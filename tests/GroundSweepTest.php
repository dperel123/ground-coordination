<?php

namespace Nyartcc\GroundCoordination\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Nyartcc\GroundCoordination\Contracts\TrafficSource;
use Nyartcc\GroundCoordination\Dto\GroundAircraftDto;
use Nyartcc\GroundCoordination\Dto\SweepCounts;
use Nyartcc\GroundCoordination\Enums\GroundPhase;
use Nyartcc\GroundCoordination\Models\Airport;
use Nyartcc\GroundCoordination\Models\GroundAircraft;
use Nyartcc\GroundCoordination\Models\Stand;
use Nyartcc\GroundCoordination\Services\GroundSweep;
use Nyartcc\GroundCoordination\Support\Haversine;
use Tests\TestCase;

class GroundSweepTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 40.0;

    private const LON = -73.0;

    private Airport $airport;

    private Stand $stand;

    /** @var array<string, list<GroundAircraftDto>> what the fake source reports, by ICAO */
    private array $reported = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ground-coordination.ground.stationary_kt' => 3,
            'ground-coordination.stale_after_seconds' => 300,
        ]);
        $this->freezeSecond();

        $test = $this;
        $this->app->instance(TrafficSource::class, new class($test) implements TrafficSource
        {
            public function __construct(private readonly GroundSweepTest $test) {}

            public function pilotsNear(string $icao): Collection
            {
                return new Collection($this->test->reportedAt($icao));
            }
        });

        $this->airport = Airport::factory()->create(['icao' => 'KTST', 'lat' => self::LAT, 'lon' => self::LON, 'match_radius_m' => 40]);
        $this->stand = Stand::factory()->for($this->airport)->create(['name' => 'B25', 'lat' => self::LAT, 'lon' => self::LON]);
    }

    /** @return list<GroundAircraftDto> */
    public function reportedAt(string $icao): array
    {
        return $this->reported[$icao] ?? [];
    }

    public function test_a_new_aircraft_is_inserted_with_every_feed_value(): void
    {
        $this->report($this->aircraft('AAL100', metersNorth: 500, groundspeed: 0));

        $this->sweep();

        $row = GroundAircraft::sole();
        $this->assertSame(
            [$this->airport->id, 'AAL100', 1234567, 0, 270, 13, 'B738', 'KTST', 'KBOS'],
            [$row->airport_id, $row->callsign, $row->cid, $row->groundspeed, $row->heading, $row->altitude, $row->aircraft_type, $row->departure, $row->arrival],
        );
        $this->assertEqualsWithDelta(self::LAT + Haversine::latitudeDegrees(500), $row->lat, 1e-9);
        $this->assertTrue($row->first_seen_at->equalTo(now()));
        $this->assertTrue($row->last_seen_at->equalTo(now()));
        $this->assertSame(GroundPhase::OffStand, $row->phase);
        $this->assertNull($row->stand_id);
        $this->assertNull($row->stand_entered_at);
    }

    public function test_the_same_callsign_is_updated_not_duplicated(): void
    {
        $this->report($this->aircraft('AAL100', metersNorth: 500, groundspeed: 0));
        $this->sweep();
        $firstSeen = GroundAircraft::sole()->first_seen_at;

        $this->travel(15)->seconds();
        $this->report($this->aircraft('AAL100', metersNorth: 600, groundspeed: 12));
        $this->sweep();

        $row = GroundAircraft::sole();
        $this->assertSame(12, $row->groundspeed);
        $this->assertTrue($row->first_seen_at->equalTo($firstSeen));
        $this->assertTrue($row->last_seen_at->equalTo(now()));
    }

    public function test_the_same_callsign_at_another_airport_is_a_separate_row(): void
    {
        Airport::factory()->create(['icao' => 'KTS2']);
        $this->reported = ['KTST' => [$this->aircraft('AAL100')], 'KTS2' => [$this->aircraft('AAL100')]];

        $this->sweep();
        app(GroundSweep::class)->run(Airport::where('icao', 'KTS2')->sole());

        $this->assertSame(2, GroundAircraft::where('callsign', 'AAL100')->count());
    }

    public function test_a_stationary_aircraft_inside_a_stand_is_at_stand(): void
    {
        $this->report($this->aircraft('AAL100', metersNorth: 12, groundspeed: 2));

        $counts = $this->sweep();

        $row = GroundAircraft::sole();
        $this->assertSame(GroundPhase::AtStand, $row->phase);
        $this->assertTrue($row->stand()->sole()->is($this->stand));
        $this->assertEqualsWithDelta(12.0, $row->stand_distance_m, 0.01);
        $this->assertTrue($row->stand_entered_at->equalTo(now()));
        $this->assertNull($row->stand_left_at);
        $this->assertSame(1, $counts->atStand);
    }

    public function test_an_aircraft_at_or_above_the_stationary_threshold_is_moving_even_on_a_stand(): void
    {
        $this->report(
            $this->aircraft('PUSH1', metersNorth: 5, groundspeed: 3),
            $this->aircraft('TAXI1', metersNorth: 800, groundspeed: 18),
        );

        $counts = $this->sweep();

        $rows = GroundAircraft::all()->keyBy('callsign');
        $this->assertSame(GroundPhase::Moving, $rows['PUSH1']->phase);
        $this->assertSame($this->stand->id, $rows['PUSH1']->stand_id, 'still inside the stand radius');
        $this->assertSame(GroundPhase::Moving, $rows['TAXI1']->phase);
        $this->assertNull($rows['TAXI1']->stand_id);
        $this->assertSame([2, 0, 2], [$counts->seen, $counts->atStand, $counts->moving]);
    }

    public function test_leaving_the_stand_sets_stand_left_at_and_keeps_when_it_arrived(): void
    {
        $this->report($this->aircraft('AAL100', metersNorth: 0, groundspeed: 0));
        $this->sweep();
        $entered = now()->toImmutable();

        $this->travel(30)->seconds();
        $this->report($this->aircraft('AAL100', metersNorth: 10, groundspeed: 4));
        $this->sweep();
        $this->assertNull(GroundAircraft::sole()->stand_left_at, 'pushing back inside the radius has not left yet');

        $this->travel(15)->seconds();
        $this->report($this->aircraft('AAL100', metersNorth: 200, groundspeed: 8));
        $this->sweep();

        $row = GroundAircraft::sole();
        $this->assertNull($row->stand_id);
        $this->assertNull($row->stand_distance_m);
        $this->assertTrue($row->stand_left_at->equalTo(now()));
        $this->assertTrue($row->stand_entered_at->equalTo($entered));
        $this->assertSame(GroundPhase::Moving, $row->phase);
    }

    public function test_stand_left_at_is_stamped_once_not_on_every_later_sweep(): void
    {
        $this->report($this->aircraft('AAL100', metersNorth: 0, groundspeed: 0));
        $this->sweep();
        $this->travel(15)->seconds();
        $this->report($this->aircraft('AAL100', metersNorth: 300, groundspeed: 10));
        $this->sweep();
        $left = now()->toImmutable();

        $this->travel(15)->seconds();
        $this->report($this->aircraft('AAL100', metersNorth: 400, groundspeed: 10));
        $this->sweep();

        $this->assertTrue(GroundAircraft::sole()->stand_left_at->equalTo($left));
    }

    public function test_arriving_at_a_stand_later_stamps_entry_and_clears_the_old_departure(): void
    {
        $this->report($this->aircraft('AAL100', metersNorth: 0, groundspeed: 0));
        $this->sweep();
        $this->travel(15)->seconds();
        $this->report($this->aircraft('AAL100', metersNorth: 300, groundspeed: 10));
        $this->sweep();

        $this->travel(60)->seconds();
        $this->report($this->aircraft('AAL100', metersNorth: 3, groundspeed: 0));
        $this->sweep();

        $row = GroundAircraft::sole();
        $this->assertTrue($row->stand_entered_at->equalTo(now()));
        $this->assertNull($row->stand_left_at);
        $this->assertSame(GroundPhase::AtStand, $row->phase);
    }

    public function test_a_stationary_aircraft_staying_on_its_stand_keeps_its_entry_time(): void
    {
        $this->report($this->aircraft('AAL100', metersNorth: 0, groundspeed: 0));
        $this->sweep();
        $entered = now()->toImmutable();

        $this->travel(15)->seconds();
        $this->sweep();

        $this->assertTrue(GroundAircraft::sole()->stand_entered_at->equalTo($entered));
    }

    public function test_rows_not_reported_for_stale_after_seconds_are_pruned_and_only_at_this_airport(): void
    {
        $other = Airport::factory()->create(['icao' => 'KTS2']);
        GroundAircraft::factory()->for($this->airport)->create(['callsign' => 'OLD1', 'last_seen_at' => now()->subSeconds(301)]);
        GroundAircraft::factory()->for($this->airport)->create(['callsign' => 'EDGE1', 'last_seen_at' => now()->subSeconds(300)]);
        GroundAircraft::factory()->for($other)->create(['callsign' => 'OLD2', 'last_seen_at' => now()->subSeconds(900)]);
        $this->report($this->aircraft('AAL100'));

        $counts = $this->sweep();

        $this->assertSame(1, $counts->pruned);
        $this->assertEqualsCanonicalizing(['AAL100', 'EDGE1', 'OLD2'], GroundAircraft::pluck('callsign')->all());
    }

    public function test_an_aircraft_reported_again_is_never_pruned(): void
    {
        GroundAircraft::factory()->for($this->airport)->create(['callsign' => 'AAL100', 'last_seen_at' => now()->subHour()]);
        $this->report($this->aircraft('AAL100'));

        $this->assertSame(0, $this->sweep()->pruned);
        $this->assertSame(1, GroundAircraft::count());
    }

    public function test_the_counts_add_up(): void
    {
        GroundAircraft::factory()->for($this->airport)->create(['callsign' => 'GONE1', 'last_seen_at' => now()->subMinutes(10)]);
        $this->report(
            $this->aircraft('GATE1', metersNorth: 1, groundspeed: 0),
            $this->aircraft('TAXI1', metersNorth: 800, groundspeed: 15),
            $this->aircraft('TAXI2', metersNorth: 900, groundspeed: 25),
            $this->aircraft('HOLD1', metersNorth: 1500, groundspeed: 0),
        );

        $counts = $this->sweep();

        $this->assertSame([4, 1, 2, 1], [$counts->seen, $counts->atStand, $counts->moving, $counts->pruned]);
    }

    public function test_the_stationary_threshold_is_read_from_config(): void
    {
        config(['ground-coordination.ground.stationary_kt' => 6]);
        $this->report($this->aircraft('AAL100', metersNorth: 2, groundspeed: 5));

        $this->sweep();

        $this->assertSame(GroundPhase::AtStand, GroundAircraft::sole()->phase);
    }

    private function sweep(): SweepCounts
    {
        return app(GroundSweep::class)->run($this->airport);
    }

    private function report(GroundAircraftDto ...$aircraft): void
    {
        $this->reported['KTST'] = $aircraft;
    }

    private function aircraft(string $callsign, float $metersNorth = 1000, int $groundspeed = 0): GroundAircraftDto
    {
        return new GroundAircraftDto(
            $callsign, 1234567, self::LAT + Haversine::latitudeDegrees($metersNorth), self::LON,
            $groundspeed, 270, 13, 'B738', 'KTST', 'KBOS',
        );
    }
}
