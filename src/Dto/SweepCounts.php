<?php

namespace Nyartcc\GroundCoordination\Dto;

/** What one GroundSweep::run() did at one airport. */
final readonly class SweepCounts
{
    public function __construct(
        public int $seen,
        public int $atStand,
        public int $moving,
        public int $pruned,
    ) {}
}
