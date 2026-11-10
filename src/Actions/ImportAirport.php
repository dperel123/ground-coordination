<?php

namespace Nyartcc\GroundCoordination\Actions;

use Nyartcc\GroundCoordination\Models\Airport;

/** Creates or updates an airport from its reference-data record, keyed by ICAO. */
class ImportAirport
{
    /** @param array{icao: string, name: string, lat: float, lon: float, bbox: array<string, float>, timezone: string, elevation_ft?: int|null} $record */
    public function __invoke(array $record): Airport
    {
        return Airport::updateOrCreate(
            ['icao' => strtoupper($record['icao'])],
            [
                'name' => $record['name'],
                'lat' => $record['lat'],
                'lon' => $record['lon'],
                'bbox' => $record['bbox'],
                'timezone' => $record['timezone'],
                'elevation_ft' => isset($record['elevation_ft']) ? (int) $record['elevation_ft'] : null,
            ],
        );
    }
}
