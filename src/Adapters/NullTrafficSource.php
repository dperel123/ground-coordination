<?php

namespace Nyartcc\GroundCoordination\Adapters;

use Illuminate\Support\Collection;
use Nyartcc\GroundCoordination\Contracts\TrafficSource;

/** No traffic anywhere. */
final class NullTrafficSource implements TrafficSource
{
    public function pilotsNear(string $icao): Collection
    {
        return new Collection;
    }
}
