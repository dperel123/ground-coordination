<?php

namespace Nyartcc\GroundCoordination\Services;

use Illuminate\Support\Collection;
use Nyartcc\GroundCoordination\Dto\StandProximity;
use Nyartcc\GroundCoordination\Models\Airport;
use Nyartcc\GroundCoordination\Models\Stand;
use Nyartcc\GroundCoordination\Support\Haversine;

class StandMatcher
{
    /**
     * The nearest active stand whose capture radius (its own radius_m, else
     * the airport's match_radius_m) contains the position, or null.
     */
    public function match(float $lat, float $lon, Airport $airport): ?Stand
    {
        $reach = max($airport->match_radius_m, (int) $airport->stands()->active()->max('radius_m'));

        return $this->measure($this->candidatesWithin($airport, $lat, $lon, $reach), $lat, $lon, $airport)
            ->filter(fn (StandProximity $proximity) => $proximity->withinRadius)
            ->first()?->stand;
    }

    /** The nearest active stand at the airport, however far, and its distance in meters. */
    public function nearestWithDistance(float $lat, float $lon, Airport $airport): ?StandProximity
    {
        return $this->measure($airport->stands()->active()->get(), $lat, $lon, $airport)->first();
    }

    /** @return Collection<int, Stand> active stands inside a lat/lon window of $meters around the position */
    private function candidatesWithin(Airport $airport, float $lat, float $lon, int $meters): Collection
    {
        $dLat = Haversine::latitudeDegrees($meters);
        $dLon = Haversine::longitudeDegrees($meters, $lat);

        return $airport->stands()->active()
            ->whereBetween('lat', [$lat - $dLat, $lat + $dLat])
            ->whereBetween('lon', [$lon - $dLon, $lon + $dLon])
            ->get();
    }

    /**
     * @param  Collection<int, Stand>  $stands
     * @return Collection<int, StandProximity> nearest first
     */
    private function measure(Collection $stands, float $lat, float $lon, Airport $airport): Collection
    {
        return $stands
            ->map(function (Stand $stand) use ($lat, $lon, $airport) {
                $meters = Haversine::meters($lat, $lon, $stand->lat, $stand->lon);

                return new StandProximity($stand, $meters, $meters <= $stand->effectiveRadius($airport));
            })
            ->sortBy(fn (StandProximity $proximity) => $proximity->meters)
            ->values();
    }
}
