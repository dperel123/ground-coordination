# nyartcc/ground-coordination

Ground coordination tools for a VATSIM ARTCC website, packaged so any Laravel
host can install them. The package owns its config, migrations, views, routes
and Livewire components; everything it needs to know about the host (who is
signed in, where the traffic comes from, airport data, how to push updates)
comes through four contracts the host implements.

Slice 1 is the skeleton: the service provider, the config, the contracts, the
Null adapters and the tests. It registers no routes and no tables yet.

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

## Configuration (`config/ground-coordination.php`)

| Key | Default | Meaning |
|-----|---------|---------|
| `enabled` | `false` | Master switch |
| `layout` | `components.layouts.app` | Layout for pilot pages |
| `controller_layout` | `components.layouts.tools` | Layout for controller pages |
| `airports` | `['KJFK']` | ICAO codes offered |
| `poll_seconds` | `15` | Page refresh interval |
| `stale_after_seconds` | `300` | When an unreported aircraft is dropped |

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
