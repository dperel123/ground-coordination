<?php

namespace Nyartcc\GroundCoordination\Tests;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Nyartcc\GroundCoordination\Contracts\TrafficSource;
use Nyartcc\GroundCoordination\Dto\GroundAircraftDto;
use Nyartcc\GroundCoordination\GroundCoordinationServiceProvider;
use Nyartcc\GroundCoordination\Models\Airport;
use Nyartcc\GroundCoordination\Models\GroundAircraft;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SweepCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> the ICAOs the fake source was asked about, in order */
    public array $asked = [];

    public function test_it_sweeps_every_active_airport_and_no_inactive_one(): void
    {
        $this->fakeSource();
        $open = Airport::factory()->create(['icao' => 'KAAA', 'lat' => 40.0, 'lon' => -73.0]);
        Airport::factory()->create(['icao' => 'KBBB']);
        Airport::factory()->create(['icao' => 'KZZZ', 'active' => false]);

        $this->artisan('ground-coordination:sweep')
            ->expectsTable(['airport', 'seen', 'at_stand', 'moving', 'pruned'], [['KAAA', 1, 0, 0, 0], ['KBBB', 0, 0, 0, 0]])
            ->assertSuccessful();

        $this->assertSame(['KAAA', 'KBBB'], $this->asked);
        $this->assertTrue(GroundAircraft::sole()->airport->is($open));
    }

    public function test_with_no_active_airport_it_says_so(): void
    {
        $this->fakeSource();
        Airport::factory()->create(['active' => false]);

        $this->artisan('ground-coordination:sweep')->expectsOutputToContain('No active airports.')->assertSuccessful();
        $this->assertSame([], $this->asked);
    }

    public function test_the_scheduler_registers_nothing_while_disabled(): void
    {
        config(['ground-coordination.enabled' => false]);

        $this->assertNull($this->scheduledSweep());
    }

    public function test_the_scheduler_registers_the_sweep_when_enabled(): void
    {
        config(['ground-coordination.enabled' => true, 'ground-coordination.poll_seconds' => 15, 'ground-coordination.sweep_lock_minutes' => 2]);

        $event = $this->scheduledSweep();

        $this->assertNotNull($event);
        $this->assertSame(15, $event->repeatSeconds);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(2, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
    }

    /** @return array<string, array{int, string}> */
    public static function pollIntervals(): array
    {
        return [
            '15 exact' => [15, 'everyFifteenSeconds'],
            '1' => [1, 'everySecond'],
            '0 rounds up to the fastest' => [0, 'everySecond'],
            '12 is nearer 10' => [12, 'everyTenSeconds'],
            '13 is nearer 15' => [13, 'everyFifteenSeconds'],
            '25 ties to the faster' => [25, 'everyTwentySeconds'],
            '40 is nearer 30' => [40, 'everyThirtySeconds'],
            '50 is nearer a minute' => [50, 'everyMinute'],
            '300 caps at a minute' => [300, 'everyMinute'],
        ];
    }

    #[DataProvider('pollIntervals')]
    public function test_poll_seconds_maps_to_the_closest_scheduler_frequency(int $seconds, string $method): void
    {
        $this->assertSame($method, GroundCoordinationServiceProvider::frequencyFor($seconds));
    }

    public function test_an_odd_poll_interval_still_schedules(): void
    {
        config(['ground-coordination.enabled' => true, 'ground-coordination.poll_seconds' => 12]);

        $this->assertSame(10, $this->scheduledSweep()?->repeatSeconds);
    }

    private function scheduledSweep(): ?Event
    {
        $this->app->forgetInstance(Schedule::class);

        return (new Collection(app(Schedule::class)->events()))
            ->first(fn (Event $event) => str_contains((string) $event->command, 'ground-coordination:sweep'));
    }

    private function fakeSource(): void
    {
        $test = $this;
        $this->app->instance(TrafficSource::class, new class($test) implements TrafficSource
        {
            public function __construct(private readonly SweepCommandTest $test) {}

            public function pilotsNear(string $icao): Collection
            {
                $this->test->asked[] = $icao;

                return new Collection($icao === 'KAAA'
                    ? [new GroundAircraftDto('AAL1', 1, 40.03, -73.0, 0, 0, 0, null, null, null)]
                    : []);
            }
        });
    }
}
