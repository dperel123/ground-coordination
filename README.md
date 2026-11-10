# nyartcc/ground-coordination

Ground coordination tools for a VATSIM ARTCC website, packaged so any Laravel
host can install them. The package owns its config, migrations, views, routes
and Livewire components; everything it needs to know about the host (who is
signed in, where the traffic comes from, airport data, how to push updates)
comes through four contracts the host implements.

Slice 1 is the skeleton (provider, config, contracts, Null adapters). Slice 2
adds the airport and stand reference data, its importer and the stand matcher.
Slice 3 adds the live ground picture: the VATSIM data feed as the default
traffic source and a scheduled sweep that keeps each aircraft on the ground
matched to a stand. It still registers no routes.

## Reference data

- `gc_airports` / `gc_stands` (models `Models\Airport`, `Models\Stand`), run by
  the host's `php artisan migrate`.
- `resources/data/airports/{ICAO}.json` and `resources/data/stands/{ICAO}.geojson`
  (Points with `name`, `area`, `heading`, `source`, `attribution`). KJFK's
  stands are OpenStreetMap `aeroway=parking_position` refs,
  (c) OpenStreetMap contributors, ODbL.
- `resources/data/stands/{ICAO}.ambiguous.geojson` is the hand-correction
  backlog: refs OSM uses for more than one stand. The importer never reads it.
- `php artisan ground-coordination:import KJFK` upserts the airport and its
  stands by (airport, name). It never deletes a stand, never touches a row's
  `active` or `radius_m`, and skips a name that occurs twice in the file.
  `data_path` in the config points it at another directory.
- `Services\StandMatcher::match($lat, $lon, $airport)` returns the nearest
  active stand whose radius (`radius_m`, else the airport's `match_radius_m`)
  contains the position. `nearestWithDistance()` returns the nearest active
  stand and its distance in meters, whatever the radius.

## The ground picture

- `gc_ground_aircraft` (model `Models\GroundAircraft`) holds one row per
  aircraft on the ground at an airport, unique by (airport, callsign): its
  last reported position and flight plan, the stand it is at now
  (`stand_id`, `stand_distance_m`, null when at none), its `phase`
  (`Enums\GroundPhase`: `at_stand`, `moving`, `off_stand`), `first_seen_at`,
  `last_seen_at`, and `stand_entered_at` / `stand_left_at`.
- `Services\GroundSweep::run($airport)` asks the traffic source for
  `pilotsNear()`, upserts each aircraft, matches it with `StandMatcher` and
  sets the phase: `moving` at or above `ground.stationary_kt`, else
  `at_stand` when a stand matched, else `off_stand`. Entering a stand stamps
  `stand_entered_at` and clears `stand_left_at`; leaving every stand stamps
  `stand_left_at` once and clears `stand_id`. Rows not reported for
  `stale_after_seconds` are deleted. It returns `Dto\SweepCounts` (seen,
  atStand, moving, pruned).
- `php artisan ground-coordination:sweep` runs it for every airport whose
  `active` is true and prints the counts.
- The default `TrafficSource` is `Adapters\VatsimDatafeedTrafficSource`. It
  reads the v3 data URL from `datafeed.status_url` (kept for
  `datafeed.status_cache_seconds`), downloads the feed and caches the parsed
  pilots for `datafeed.cache_seconds` in the host's default cache store, so
  every airport in one sweep shares one download. `pilotsNear()` keeps pilots
  inside the airport bbox, slower than `ground.max_groundspeed_kt` and below
  the airport's `elevation_ft` (null is read as 0) + `ground.max_agl_ft`. A
  failed download returns no aircraft and is not cached.
- Each real download is recorded in `gc_feed_status`. `Services\FeedStatus::isLive()`
  is true only while the last success is at most `live_window_seconds` old.
  A host with its own `TrafficSource` may call `recordSuccess()` /
  `recordFailure()` under its own feed name.

### Scheduling

With `enabled` true the provider adds `ground-coordination:sweep` to the
host's scheduler at the frequency nearest `poll_seconds` (1, 2, 5, 10, 15,
20, 30 or 60 seconds; a tie goes to the faster), `withoutOverlapping()` (lock
held at most `sweep_lock_minutes`) and `onOneServer()` (the cache store must
support atomic locks: redis, database, memcached, dynamodb, file or array).
With `enabled` false nothing is scheduled; the command still runs by hand.

Sub-minute events only repeat inside a long-running scheduler process. A plain
once-a-minute cron of `schedule:run` runs them only if that run stays up for
the minute, which `schedule:run` does when a sub-minute event is due
(Laravel 10.15+), so either works:

- `php artisan schedule:work` (a foreground worker, e.g. a supervisor or
  container process), or
- cron `* * * * * php /path/to/artisan schedule:run`.

Locally with Sail:

```
sail artisan migrate
sail artisan ground-coordination:import KJFK
sail artisan ground-coordination:sweep            # one sweep, by hand
sail artisan schedule:list                        # shows the sweep when enabled
sail artisan schedule:work                        # runs it every poll_seconds
```

`GROUND_COORDINATION_ENABLED=true` in `.env` (or the environment) turns the
schedule on.

## Host integration

1. Add the package. For local development beside the host checkout:

   ```json
   "repositories": [
       { "type": "path", "url": "../ground-coordination", "options": { "symlink": true } }
   ],
   "require": { "nyartcc/ground-coordination": "*" }
   ```

   If the host runs in Laravel Sail, the container mounts only the host
   folder, so `../ground-coordination` does not exist inside it. Add a
   `compose.override.yaml` beside the host's `compose.yaml` (Docker Compose
   merges it automatically) and keep it out of version control with
   `.git/info/exclude`, so it never reaches a PR:

   ```yaml
   services:
       laravel.test:
           volumes:
               - '../ground-coordination:/var/www/ground-coordination'
   ```

   Recreate the container with `sail up -d`, then run
   `sail composer update nyartcc/ground-coordination`. Laravel auto-discovers
   `Nyartcc\GroundCoordination\GroundCoordinationServiceProvider`.

2. Tailwind: add an `@source` line for the package's views to the host
   stylesheet, e.g. `@source '../../../ground-coordination/resources/views';`.

3. Tests: add a phpunit testsuite pointing at the package's `tests/` folder.
   The package tests extend the host's `Tests\TestCase`.

4. Bind the four contracts to the host's own implementations in a host service
   provider. Until you do, the VATSIM data feed supplies traffic and the Null
   adapters cover the rest. The provider uses `bindIf()`, so a host binding always wins.

5. Turn it on with `GROUND_COORDINATION_ENABLED=true` and review the other
   keys in `config/ground-coordination.php`.

## Adding an airport

1. Write `resources/data/airports/{ICAO}.json` by hand: `icao`, `name`, `lat`,
   `lon`, `elevation_ft` (field elevation, feet MSL), `bbox` (`min_lat`, `min_lon`, `max_lat`, `max_lon`) and `timezone`.
   Make the bbox cover every ramp, cargo and GA area included.

2. Fetch the stands from OpenStreetMap:

   ```
   php artisan ground-coordination:fetch-osm KXXX
   php artisan ground-coordination:fetch-osm KXXX --bbox=40.620,-73.830,40.665,-73.745
   php artisan ground-coordination:fetch-osm KXXX --twin-threshold-m=60
   ```

   It asks the Overpass API (`osm.endpoint`, identifying itself with
   `osm.user_agent`; Overpass refuses anonymous clients with a 406) for every
   `aeroway=parking_position` node and way inside the bbox: `--bbox` if given,
   else the one in the airport file. It writes two files under
   `resources/data/stands/` and overwrites both:

   - `{ICAO}.geojson`: one Point per stand, named by the OSM `ref`, sorted by
     name, with `area`/`heading` null, `source`/`attribution` from the `osm`
     config, and the OSM id it came from. A way's point is the mean of its
     vertices.
   - `{ICAO}.ambiguous.geojson`: the hand-correction backlog. These are refs OSM
     uses for more than one stand, kept with the raw ref, point, OSM id and how
     far apart the features are. The importer never reads this file.

   Features with no `ref` are dropped. Same-ref features no more than
   `--twin-threshold-m` apart (default 60) are one stand mapped twice. They
   collapse to the node's point when exactly one of them is a node, and to
   their mean otherwise. A same-ref group spread wider than that goes, whole,
   to the ambiguous file. The summary prints features, with ref, names, twins
   collapsed and ambiguous skipped. Nothing is written if the fetch fails.

   Output is stable (same layout, same rounding), so re-fetching an airport
   produces a diff of only the stands OSM changed. Review that diff before
   importing.

3. Load it: `php artisan ground-coordination:import KXXX` (see Reference data).

4. Add the ICAO to `airports` in the config.

The conversion itself is `Services\OsmStandConverter`, a pure function of the
Overpass response. Its tests run against a saved slice of the real KJFK
response (`tests/fixtures/overpass/KJFK-subset.json`, (c) OpenStreetMap
contributors, ODbL), so they never touch the network.

## Configuration (`config/ground-coordination.php`)

| Key | Default | Meaning |
|-----|---------|---------|
| `enabled` | `false` | Master switch |
| `layout` | `components.layouts.app` | Layout for pilot pages |
| `controller_layout` | `components.layouts.tools` | Layout for controller pages |
| `airports` | `['KJFK']` | ICAO codes offered |
| `poll_seconds` | `15` | Page refresh interval and sweep frequency |
| `stale_after_seconds` | `300` | When an unreported aircraft is dropped |
| `live_window_seconds` | `300` | How old the last successful fetch may be for `FeedStatus::isLive()` |
| `sweep_lock_minutes` | `2` | Overlap-lock expiry for the scheduled sweep |
| `datafeed.*` | VATSIM status URL, User-Agent, 10 s timeout, 10 s cache, 3600 s status cache | `VatsimDatafeedTrafficSource` |
| `ground.*` | `max_groundspeed_kt` 50, `max_agl_ft` 300, `stationary_kt` 3 | Ground filter and stationary threshold |
| `data_path` | package `resources/data` | Where `import` reads and `fetch-osm` writes |
| `osm.*` | Overpass endpoint, User-Agent, 120 s timeout, source, attribution | `fetch-osm` |

## The four contracts (`Nyartcc\GroundCoordination\Contracts`)

| Contract | Methods | Null adapter |
|----------|---------|--------------|
| `PilotIdentity` | `currentCid(): ?int`, `isPilot(): bool`, `isController(): bool` | guest, neither |
| `TrafficSource` | `pilotsNear(string $icao): Collection<GroundAircraftDto>` | default is the VATSIM data feed; `NullTrafficSource` (empty) is there to bind |
| `AirportData` | `atisFor(string $icao): ?array`, `frequenciesFor(string $icao): array` | `null`, `[]` |
| `BoardNotifier` | `announce(string $channel, array $payload): void` | does nothing |

`GroundAircraftDto` carries `callsign`, `cid`, `lat`, `lon`, `groundspeed`,
`heading`, `altitude`, `aircraftType`, `departure`, `arrival`.

## Licence

MIT. See `LICENSE`.
