# nyartcc/ground-coordination

Ground coordination tools for a VATSIM ARTCC website, packaged so any Laravel
host can install them. The package owns its config, migrations, views, routes
and Livewire components; everything it needs to know about the host (who is
signed in, where the traffic comes from, airport data, how to push updates)
comes through four contracts the host implements.

Slice 1 is the skeleton (provider, config, contracts, Null adapters). Slice 2
adds the airport and stand reference data, its importer and the stand matcher.
It still registers no routes.

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
   provider. Until you do, the Null adapters are used and the package does
   nothing visible. The provider uses `bindIf()`, so a host binding always wins.

5. Turn it on with `GROUND_COORDINATION_ENABLED=true` and review the other
   keys in `config/ground-coordination.php`.

## Adding an airport

1. Write `resources/data/airports/{ICAO}.json` by hand: `icao`, `name`, `lat`,
   `lon`, `bbox` (`min_lat`, `min_lon`, `max_lat`, `max_lon`) and `timezone`.
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
| `poll_seconds` | `15` | Page refresh interval |
| `stale_after_seconds` | `300` | When an unreported aircraft is dropped |
| `data_path` | package `resources/data` | Where `import` reads and `fetch-osm` writes |
| `osm.*` | Overpass endpoint, User-Agent, 120 s timeout, source, attribution | `fetch-osm` |

## The four contracts (`Nyartcc\GroundCoordination\Contracts`)

| Contract | Methods | Null adapter |
|----------|---------|--------------|
| `PilotIdentity` | `currentCid(): ?int`, `isPilot(): bool`, `isController(): bool` | guest, neither |
| `TrafficSource` | `pilotsNear(string $icao): Collection<GroundAircraftDto>` | empty collection |
| `AirportData` | `atisFor(string $icao): ?array`, `frequenciesFor(string $icao): array` | `null`, `[]` |
| `BoardNotifier` | `announce(string $channel, array $payload): void` | does nothing |

`GroundAircraftDto` carries `callsign`, `cid`, `lat`, `lon`, `groundspeed`,
`heading`, `altitude`, `aircraftType`, `departure`, `arrival`.

## Licence

MIT. See `LICENSE`.
