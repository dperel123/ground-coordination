<?php

namespace Nyartcc\GroundCoordination\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nyartcc\GroundCoordination\Enums\GroundPhase;
use Nyartcc\GroundCoordination\Models\Airport;
use Nyartcc\GroundCoordination\Models\GroundAircraft;

/** @extends Factory<GroundAircraft> */
class GroundAircraftFactory extends Factory
{
    protected $model = GroundAircraft::class;

    public function definition(): array
    {
        return [
            'airport_id' => Airport::factory(),
            'callsign' => strtoupper($this->faker->unique()->bothify('???###')),
            'cid' => $this->faker->numberBetween(800000, 1999999),
            'lat' => $this->faker->latitude(25, 48),
            'lon' => $this->faker->longitude(-120, -70),
            'groundspeed' => 0,
            'heading' => $this->faker->numberBetween(0, 359),
            'altitude' => 0,
            'aircraft_type' => 'B738',
            'departure' => 'KJFK',
            'arrival' => 'KBOS',
            'stand_id' => null,
            'stand_distance_m' => null,
            'phase' => GroundPhase::OffStand,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'stand_entered_at' => null,
            'stand_left_at' => null,
        ];
    }
}
