<?php

namespace Nyartcc\GroundCoordination\Dto;

/**
 * One aircraft as the traffic source reports it.
 */
final readonly class GroundAircraftDto
{
    public function __construct(
        public string $callsign,
        public int $cid,
        public float $lat,
        public float $lon,
        public int $groundspeed,
        public int $heading,
        public int $altitude,
        public ?string $aircraftType,
        public ?string $departure,
        public ?string $arrival,
    ) {}
}
