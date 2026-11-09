<?php

namespace Nyartcc\GroundCoordination\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nyartcc\GroundCoordination\Models\Airport;
use Nyartcc\GroundCoordination\Models\Stand;
use Nyartcc\GroundCoordination\Services\StandMatcher;
use Nyartcc\GroundCoordination\Support\Haversine;
use Tests\TestCase;

class StandMatcherTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 40.0;

    private const LON = -73.0;

    private Airport $airport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->airport = Airport::factory()->create(['match_radius_m' => 40, 'lat' => self::LAT, 'lon' => self::LON]);
    }

    public function test_a_position_inside_the_airport_radius_matches(): void
    {
        $stand = $this->standAt(0, 'B25');

        $this->assertTrue($this->matcher()->match(...$this->northOf(39.0))->is($stand));
    }

    public function test_a_position_just_outside_the_radius_matches_nothing(): void
    {
        $this->standAt(0, 'B25');

        $this->assertNull($this->matcher()->match(...$this->northOf(41.0)));
    }

    public function test_the_nearer_of_two_stands_in_range_wins(): void
    {
        $this->standAt(0, 'FAR');
        $near = $this->standAt(30, 'NEAR');

        // 20 m north: 20 m from FAR, 10 m from NEAR. Both rows exist so the far one would win on id order.
        $this->assertTrue($this->matcher()->match(...$this->northOf(20.0))->is($near));
    }

    public function test_inactive_stands_are_ignored(): void
    {
        $this->standAt(0, 'CLOSED', ['active' => false]);
        $open = $this->standAt(30, 'OPEN');

        $this->assertTrue($this->matcher()->match(...$this->northOf(5.0))->is($open));
        $this->assertTrue($this->matcher()->nearestWithDistance(...$this->northOf(5.0))->stand->is($open));
    }

    public function test_a_stand_radius_overrides_the_airport_radius_both_ways(): void
    {
        $tight = $this->standAt(0, 'TIGHT', ['radius_m' => 10]);
        $wide = $this->standAt(500, 'WIDE', ['radius_m' => 80]);

        $this->assertNull($this->matcher()->match(...$this->northOf(15.0)), 'TIGHT: 15 m is inside 40 but outside its own 10');
        $this->assertTrue($this->matcher()->match(...$this->northOf(9.0))->is($tight));
        $this->assertTrue($this->matcher()->match(...$this->northOf(570.0))->is($wide), 'WIDE: 70 m is outside 40 but inside its own 80');
    }

    public function test_a_stand_at_another_airport_never_matches(): void
    {
        Stand::factory()->create(['lat' => self::LAT, 'lon' => self::LON]);

        $this->assertNull($this->matcher()->match(self::LAT, self::LON, $this->airport));
    }

    public function test_nearest_with_distance_reports_meters_beyond_the_radius(): void
    {
        $stand = $this->standAt(0, 'B25');

        $proximity = $this->matcher()->nearestWithDistance(...$this->northOf(100.0));

        $this->assertTrue($proximity->stand->is($stand));
        $this->assertEqualsWithDelta(100.0, $proximity->meters, 0.01);
        $this->assertFalse($proximity->withinRadius);
        $this->assertTrue($this->matcher()->nearestWithDistance(...$this->northOf(18.0))->withinRadius);
    }

    public function test_nearest_with_distance_is_null_with_no_active_stand(): void
    {
        $this->standAt(0, 'CLOSED', ['active' => false]);

        $this->assertNull($this->matcher()->nearestWithDistance(self::LAT, self::LON, $this->airport));
    }

    private function matcher(): StandMatcher
    {
        return app(StandMatcher::class);
    }

    /** @param array<string, mixed> $attributes */
    private function standAt(float $metersNorth, string $name, array $attributes = []): Stand
    {
        return Stand::factory()->for($this->airport)->create([
            'name' => $name,
            'lat' => self::LAT + Haversine::latitudeDegrees($metersNorth),
            'lon' => self::LON,
        ] + $attributes);
    }

    /** @return array{float, float, Airport} */
    private function northOf(float $meters): array
    {
        return [self::LAT + Haversine::latitudeDegrees($meters), self::LON, $this->airport];
    }
}
