<?php

namespace Nyartcc\GroundCoordination\Services;

use Illuminate\Support\Carbon;
use Nyartcc\GroundCoordination\Contracts\TrafficSource;
use Nyartcc\GroundCoordination\Dto\GroundAircraftDto;
use Nyartcc\GroundCoordination\Dto\SweepCounts;
use Nyartcc\GroundCoordination\Enums\GroundPhase;
use Nyartcc\GroundCoordination\Models\Airport;
use Nyartcc\GroundCoordination\Models\GroundAircraft;
use Nyartcc\GroundCoordination\Models\Stand;
use Nyartcc\GroundCoordination\Support\Haversine;

/**
 * Refreshes one airport's ground picture from the traffic source: upserts
 * each reported aircraft by (airport, callsign), matches it to a stand,
 * sets its phase, then drops rows not reported for stale_after_seconds.
 * System-driven (no human actor), so it writes through Eloquent directly.
 */
class GroundSweep
{
    public function __construct(
        private readonly TrafficSource $traffic,
        private readonly StandMatcher $matcher,
    ) {}

    public function run(Airport $airport): SweepCounts
    {
        $now = now();
        $phases = $this->traffic->pilotsNear($airport->icao)
            ->map(fn (GroundAircraftDto $aircraft) => $this->record($airport, $aircraft, $now));

        $staleBefore = $now->copy()->subSeconds((int) config('ground-coordination.stale_after_seconds'));
        $pruned = $airport->groundAircraft()->where('last_seen_at', '<', $staleBefore)->delete();

        return new SweepCounts(
            seen: $phases->count(),
            atStand: $phases->filter(fn (GroundPhase $phase) => $phase === GroundPhase::AtStand)->count(),
            moving: $phases->filter(fn (GroundPhase $phase) => $phase === GroundPhase::Moving)->count(),
            pruned: $pruned,
        );
    }

    private function record(Airport $airport, GroundAircraftDto $aircraft, Carbon $now): GroundPhase
    {
        $row = GroundAircraft::firstOrNew(['airport_id' => $airport->id, 'callsign' => $aircraft->callsign]);
        $stand = $this->matcher->match($aircraft->lat, $aircraft->lon, $airport);

        $this->trackStand($row, $stand, $now);
        $row->fill([
            'cid' => $aircraft->cid,
            'lat' => $aircraft->lat,
            'lon' => $aircraft->lon,
            'groundspeed' => max(0, $aircraft->groundspeed),
            'heading' => max(0, $aircraft->heading),
            'altitude' => $aircraft->altitude,
            'aircraft_type' => $aircraft->aircraftType,
            'departure' => $aircraft->departure,
            'arrival' => $aircraft->arrival,
            'stand_id' => $stand?->id,
            'stand_distance_m' => $stand === null ? null : Haversine::meters($aircraft->lat, $aircraft->lon, $stand->lat, $stand->lon),
            'phase' => $this->phase($stand, $aircraft->groundspeed),
            'first_seen_at' => $row->first_seen_at ?? $now,
            'last_seen_at' => $now,
        ])->save();

        return $row->phase;
    }

    /**
     * Entering a stand (from none, or from a different one) stamps
     * stand_entered_at and clears stand_left_at; leaving every stand stamps
     * stand_left_at and keeps stand_entered_at as the time it last arrived.
     */
    private function trackStand(GroundAircraft $row, ?Stand $stand, Carbon $now): void
    {
        $previous = $row->exists ? $row->stand_id : null;

        if ($stand !== null && $stand->id !== $previous) {
            $row->stand_entered_at = $now;
            $row->stand_left_at = null;
        } elseif ($stand === null && $previous !== null) {
            $row->stand_left_at = $now;
        }
    }

    private function phase(?Stand $stand, int $groundspeed): GroundPhase
    {
        if ($groundspeed >= (int) config('ground-coordination.ground.stationary_kt')) {
            return GroundPhase::Moving;
        }

        return $stand === null ? GroundPhase::OffStand : GroundPhase::AtStand;
    }
}
