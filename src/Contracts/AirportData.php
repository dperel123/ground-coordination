<?php

namespace Nyartcc\GroundCoordination\Contracts;

/**
 * Reference data about an airport, from the host's own records.
 */
interface AirportData
{
    /** @return array<string, mixed>|null the current ATIS, or null when none is broadcasting */
    public function atisFor(string $icao): ?array;

    /** @return array<string, string> position name => frequency */
    public function frequenciesFor(string $icao): array;
}
