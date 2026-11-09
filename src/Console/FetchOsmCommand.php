<?php

namespace Nyartcc\GroundCoordination\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Nyartcc\GroundCoordination\Dto\OsmConversion;
use Nyartcc\GroundCoordination\Services\OsmStandConverter;
use Nyartcc\GroundCoordination\Support\GeoJson;

class FetchOsmCommand extends Command
{
    protected $signature = 'ground-coordination:fetch-osm
        {icao : The airport, e.g. KJFK}
        {--bbox= : min_lat,min_lon,max_lat,max_lon (default: the bbox in airports/{ICAO}.json)}
        {--twin-threshold-m=60 : Same-ref features at most this far apart are one stand mapped twice}';

    protected $description = 'Fetch OpenStreetMap parking positions and write stands/{ICAO}.geojson and {ICAO}.ambiguous.geojson';

    public function handle(OsmStandConverter $converter): int
    {
        $icao = strtoupper((string) $this->argument('icao'));

        try {
            $bbox = $this->bbox($icao);
            $threshold = $this->twinThreshold();
            $overpass = $this->fetch($bbox);
        } catch (InvalidArgumentException|ConnectionException|RequestException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $conversion = $converter->convert($overpass, $threshold);
        $this->write($icao, $conversion);
        $this->report($icao, $conversion);

        return self::SUCCESS;
    }

    /** @return array{float, float, float, float} min_lat, min_lon, max_lat, max_lon */
    private function bbox(string $icao): array
    {
        $option = $this->option('bbox');
        if ($option === null || $option === '') {
            return $this->bboxFromAirportFile($icao);
        }

        $parts = array_map('trim', explode(',', (string) $option));
        if (count($parts) !== 4 || array_filter($parts, fn (string $part) => ! is_numeric($part)) !== []) {
            throw new InvalidArgumentException('--bbox must be four numbers: min_lat,min_lon,max_lat,max_lon.');
        }

        return $this->checkedBbox(array_map('floatval', $parts));
    }

    /** @return array{float, float, float, float} */
    private function bboxFromAirportFile(string $icao): array
    {
        $path = rtrim((string) config('ground-coordination.data_path'), '/')."/airports/{$icao}.json";
        $airport = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $bbox = is_array($airport) ? ($airport['bbox'] ?? null) : null;

        if (! is_array($bbox) || array_diff(['min_lat', 'min_lon', 'max_lat', 'max_lon'], array_keys($bbox)) !== []) {
            throw new InvalidArgumentException("No bbox: pass --bbox, or add one to {$path}.");
        }

        return $this->checkedBbox([(float) $bbox['min_lat'], (float) $bbox['min_lon'], (float) $bbox['max_lat'], (float) $bbox['max_lon']]);
    }

    /**
     * @param  list<float>  $bbox
     * @return array{float, float, float, float}
     */
    private function checkedBbox(array $bbox): array
    {
        [$minLat, $minLon, $maxLat, $maxLon] = $bbox;
        if ($minLat >= $maxLat || $minLon >= $maxLon || $minLat < -90 || $maxLat > 90 || $minLon < -180 || $maxLon > 180) {
            throw new InvalidArgumentException('The bbox must run south-west to north-east, inside -90..90 and -180..180.');
        }

        return [$minLat, $minLon, $maxLat, $maxLon];
    }

    private function twinThreshold(): float
    {
        $value = $this->option('twin-threshold-m');
        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidArgumentException('--twin-threshold-m must be a number of meters, 0 or more.');
        }

        return (float) $value;
    }

    /**
     * @param  array{float, float, float, float}  $bbox
     * @return array<string, mixed>
     *
     * @throws ConnectionException|RequestException
     */
    private function fetch(array $bbox): array
    {
        $area = implode(',', $bbox);
        $query = "[out:json][timeout:90];(node[\"aeroway\"=\"parking_position\"]({$area});way[\"aeroway\"=\"parking_position\"]({$area}););out geom;";

        return Http::withUserAgent((string) config('ground-coordination.osm.user_agent'))
            ->timeout((int) config('ground-coordination.osm.timeout_seconds'))
            ->asForm()
            ->post((string) config('ground-coordination.osm.endpoint'), ['data' => $query])
            ->throw()
            ->json() ?? [];
    }

    private function write(string $icao, OsmConversion $conversion): void
    {
        $directory = rtrim((string) config('ground-coordination.data_path'), '/').'/stands';
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents("{$directory}/{$icao}.geojson", GeoJson::featureCollection($conversion->stands));
        file_put_contents("{$directory}/{$icao}.ambiguous.geojson", GeoJson::featureCollection($conversion->ambiguous));
    }

    private function report(string $icao, OsmConversion $conversion): void
    {
        $this->info("{$icao}: wrote ".count($conversion->stands).' stands and '.count($conversion->ambiguous).' ambiguous features.');
        $this->table(['features', 'with ref', 'names', 'twins collapsed', 'ambiguous skipped'], [[
            $conversion->features,
            $conversion->withRef,
            $conversion->names,
            count($conversion->twins),
            count($conversion->ambiguous).' ('.$conversion->ambiguousNames().' names)',
        ]]);
    }
}
