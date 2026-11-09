<?php

namespace Nyartcc\GroundCoordination\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nyartcc\GroundCoordination\Models\Airport;
use Nyartcc\GroundCoordination\Models\Stand;

/** @extends Factory<Stand> */
class StandFactory extends Factory
{
    protected $model = Stand::class;

    public function definition(): array
    {
        return [
            'airport_id' => Airport::factory(),
            'name' => strtoupper($this->faker->unique()->bothify('?##')),
            'area' => null,
            'lat' => $this->faker->latitude(25, 48),
            'lon' => $this->faker->longitude(-120, -70),
            'heading' => null,
            'radius_m' => null,
            'source' => 'factory',
            'attribution' => 'test data',
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
