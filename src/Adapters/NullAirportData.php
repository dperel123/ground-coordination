<?php

namespace Nyartcc\GroundCoordination\Adapters;

use Nyartcc\GroundCoordination\Contracts\AirportData;

/** No ATIS and no frequencies for any airport. */
final class NullAirportData implements AirportData
{
    public function atisFor(string $icao): ?array
    {
        return null;
    }

    public function frequenciesFor(string $icao): array
    {
        return [];
    }
}
