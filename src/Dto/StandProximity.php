<?php

namespace Nyartcc\GroundCoordination\Dto;

use Nyartcc\GroundCoordination\Models\Stand;

/** The nearest stand to a position and how far its center is. */
final readonly class StandProximity
{
    public function __construct(
        public Stand $stand,
        public float $meters,
        public bool $withinRadius,
    ) {}
}
