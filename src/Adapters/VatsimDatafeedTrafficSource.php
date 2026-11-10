<?php

namespace Nyartcc\GroundCoordination\Adapters;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nyartcc\GroundCoordination\Contracts\TrafficSource;
use Nyartcc\GroundCoordination\Dto\GroundAircraftDto;
use Nyartcc\GroundCoordination\Models\Airport;
use Nyartcc\GroundCoordination\Services\FeedStatus;
use UnexpectedValueException;

/**
 * The VATSIM v3 data feed. One download serves every airport asked about
 * within datafeed.cache_seconds; only a real download is recorded in
 * FeedStatus. A failed download returns no aircraft and is not cached.
 */
class VatsimDatafeedTrafficSource implements TrafficSource
{
    public const PILOTS_CACHE_KEY = 'ground-coordination:datafeed:pilots';

    public const DATA_URL_CACHE_KEY = 'ground-coordination:datafeed:data-url';

    public function __construct(private readonly FeedStatus $status) {}

    public function pilotsNear(string $icao): Collection
    {
        $airport = Airport::where('icao', strtoupper($icao))->first();
        if ($airport === null) {
            return new Collection;
        }

        $bbox = $airport->bbox;
        $ceiling = ($airport->elevation_ft ?? 0) + (int) config('ground-coordination.ground.max_agl_ft');
        $maxSpeed = (int) config('ground-coordination.ground.max_groundspeed_kt');

        return (new Collection($this->pilots()))
            ->filter(fn (array $pilot) => $pilot['lat'] >= $bbox['min_lat'] && $pilot['lat'] <= $bbox['max_lat']
                && $pilot['lon'] >= $bbox['min_lon'] && $pilot['lon'] <= $bbox['max_lon']
                && $pilot['groundspeed'] < $maxSpeed
                && $pilot['altitude'] < $ceiling)
            ->map(fn (array $pilot) => new GroundAircraftDto(...$pilot))
            ->values();
    }

    /** @return list<array<string, mixed>> the parsed pilots, from cache or one fresh download */
    private function pilots(): array
    {
        $cached = Cache::get(self::PILOTS_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $pilots = $this->download();
        } catch (ConnectionException|RequestException|UnexpectedValueException $exception) {
            $this->status->recordFailure($exception->getMessage());

            return [];
        }

        Cache::put(self::PILOTS_CACHE_KEY, $pilots, (int) config('ground-coordination.datafeed.cache_seconds'));
        $this->status->recordSuccess();

        return $pilots;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    private function download(): array
    {
        $feed = $this->request()->get($this->dataUrl())->throw()->json();
        if (! is_array($feed) || ! is_array($feed['pilots'] ?? null)) {
            throw new UnexpectedValueException('The VATSIM data feed carried no pilots list.');
        }

        return array_values(array_filter(array_map($this->parsePilot(...), $feed['pilots'])));
    }

    /** @throws ConnectionException|RequestException|UnexpectedValueException */
    private function dataUrl(): string
    {
        $cached = Cache::get(self::DATA_URL_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $status = $this->request()->get((string) config('ground-coordination.datafeed.status_url'))->throw()->json();
        $url = is_array($status) ? ($status['data']['v3'][0] ?? null) : null;
        if (! is_string($url) || $url === '') {
            throw new UnexpectedValueException('The VATSIM status file names no v3 data URL.');
        }

        Cache::put(self::DATA_URL_CACHE_KEY, $url, (int) config('ground-coordination.datafeed.status_cache_seconds'));

        return $url;
    }

    private function request(): PendingRequest
    {
        return Http::withUserAgent((string) config('ground-coordination.datafeed.user_agent'))
            ->acceptJson()
            ->timeout((int) config('ground-coordination.datafeed.timeout'));
    }

    /**
     * The DTO's constructor arguments, or null for a pilot the feed reported
     * without a usable position.
     *
     * @return array<string, mixed>|null
     */
    private function parsePilot(mixed $pilot): ?array
    {
        if (! is_array($pilot) || ! is_numeric($pilot['latitude'] ?? null) || ! is_numeric($pilot['longitude'] ?? null)
            || ! is_string($pilot['callsign'] ?? null) || ! is_numeric($pilot['cid'] ?? null)) {
            return null;
        }

        $plan = is_array($pilot['flight_plan'] ?? null) ? $pilot['flight_plan'] : [];

        return [
            'callsign' => $pilot['callsign'],
            'cid' => (int) $pilot['cid'],
            'lat' => (float) $pilot['latitude'],
            'lon' => (float) $pilot['longitude'],
            'groundspeed' => (int) ($pilot['groundspeed'] ?? 0),
            'heading' => (int) ($pilot['heading'] ?? 0),
            'altitude' => (int) ($pilot['altitude'] ?? 0),
            'aircraftType' => $this->blankToNull($plan['aircraft_short'] ?? null),
            'departure' => $this->blankToNull($plan['departure'] ?? null),
            'arrival' => $this->blankToNull($plan['arrival'] ?? null),
        ];
    }

    private function blankToNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
