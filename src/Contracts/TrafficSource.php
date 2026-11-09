<?php

namespace Nyartcc\GroundCoordination\Contracts;

use Illuminate\Support\Collection;
use Nyartcc\GroundCoordination\Dto\GroundAircraftDto;

/**
 * Live aircraft positions, from whatever feed the host reads.
 */
interface TrafficSource
{
    /** @return Collection<int, GroundAircraftDto> the aircraft on or near the airport */
    public function pilotsNear(string $icao): Collection;
}
