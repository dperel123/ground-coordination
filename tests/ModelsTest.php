<?php

namespace Nyartcc\GroundCoordination\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nyartcc\GroundCoordination\Models\Airport;
use Nyartcc\GroundCoordination\Models\Stand;
use Tests\TestCase;

class ModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_package_migrations_create_both_tables(): void
    {
        $this->assertTrue(Schema::hasTable('gc_airports'));
        $this->assertTrue(Schema::hasTable('gc_stands'));
    }

    public function test_an_airport_has_many_stands_and_a_stand_belongs_to_its_airport(): void
    {
        $airport = Airport::factory()->create();
        Stand::factory()->count(2)->for($airport)->create();
        $other = Stand::factory()->create();

        $this->assertSame(2, $airport->stands()->count());
        $this->assertTrue($airport->stands()->first()->airport->is($airport));
        $this->assertFalse($other->airport->is($airport));
    }

    public function test_an_airport_defaults_to_a_40_meter_match_radius(): void
    {
        $fresh = Airport::create([
            'icao' => 'KDEF', 'name' => 'Default', 'lat' => 1.0, 'lon' => 2.0,
            'bbox' => ['min_lat' => 0, 'min_lon' => 0, 'max_lat' => 2, 'max_lon' => 3], 'timezone' => 'UTC',
        ])->refresh();

        $this->assertSame(40, $fresh->match_radius_m);
        $this->assertTrue($fresh->active);
        // jsonb stores object keys in its own order, so compare by key, not position.
        $this->assertEquals(['min_lat' => 0, 'min_lon' => 0, 'max_lat' => 2, 'max_lon' => 3], $fresh->bbox);
    }

    public function test_a_stand_name_is_unique_per_airport_only(): void
    {
        $first = Stand::factory()->create(['name' => 'B25']);
        Stand::factory()->create(['name' => 'B25']);

        $this->expectException(QueryException::class);
        Stand::factory()->for($first->airport)->create(['name' => 'B25']);
    }
}
