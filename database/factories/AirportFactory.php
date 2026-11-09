<?php

namespace Nyartcc\GroundCoordination\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nyartcc\GroundCoordination\Models\Airport;

/** @extends Factory<Airport> */
class AirportFactory extends Factory
{
    protected $model = Airport::class;

    public function definition(): array
    {
        $lat = $this->faker->latitude(25, 48);
        $lon = $this->faker->longitude(-120, -70);

        return [
            'icao' => 'K'.strtoupper($this->faker->unique()->lexify('???')),
            'name' => $this->faker->city().' Airport',
            'lat' => $lat,
            'lon' => $lon,
            'bbox' => ['min_lat' => $lat - 0.02, 'min_lon' => $lon - 0.03, 'max_lat' => $lat + 0.02, 'max_lon' => $lon + 0.03],
            'timezone' => 'America/New_York',
            'match_radius_m' => 40,
            'active' => true,
        ];
    }
}
