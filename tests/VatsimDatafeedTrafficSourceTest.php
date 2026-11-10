<?php

namespace Nyartcc\GroundCoordination\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nyartcc\GroundCoordination\Adapters\VatsimDatafeedTrafficSource;
use Nyartcc\GroundCoordination\Contracts\TrafficSource;
use Nyartcc\GroundCoordination\Dto\GroundAircraftDto;
use Nyartcc\GroundCoordination\Models\Airport;
use Nyartcc\GroundCoordination\Models\FeedState;
use Nyartcc\GroundCoordination\Services\FeedStatus;
use Tests\TestCase;

class VatsimDatafeedTrafficSourceTest extends TestCase
{
    use RefreshDatabase;

    private const STATUS_URL = 'https://status.example.test/status.json';

    private const DATA_URL = 'https://data.example.test/v3/vatsim-data.json';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'ground-coordination.datafeed.status_url' => self::STATUS_URL,
            'ground-coordination.ground.max_groundspeed_kt' => 50,
            'ground-coordination.ground.max_agl_ft' => 300,
        ]);
        Http::preventStrayRequests();

        Airport::factory()->create([
            'icao' => 'KTST', 'lat' => 40.0, 'lon' => -73.0, 'elevation_ft' => 20,
            'bbox' => ['min_lat' => 39.99, 'min_lon' => -73.01, 'max_lat' => 40.01, 'max_lon' => -72.99],
        ]);
        Airport::factory()->create([
            'icao' => 'KTS2', 'lat' => 41.0, 'lon' => -74.0, 'elevation_ft' => 0,
            'bbox' => ['min_lat' => 40.99, 'min_lon' => -74.01, 'max_lat' => 41.01, 'max_lon' => -73.99],
        ]);
    }

    public function test_it_is_the_default_traffic_source(): void
    {
        $this->assertInstanceOf(VatsimDatafeedTrafficSource::class, app(TrafficSource::class));
    }

    public function test_only_slow_low_pilots_inside_the_bbox_are_returned(): void
    {
        $this->fakeFeed();

        $aircraft = app(TrafficSource::class)->pilotsNear('ktst');

        // FAST1 (50 kt), HIGH1 (320 ft = 20 + 300), OUTSIDE1 (north of the bbox), TS2A (the other airport)
        // and NOPOS1 (no position) are excluded; TST2 sits just under both limits (49 kt, 319 ft).
        $this->assertSame(['TST1', 'TST2', 'TST3'], $aircraft->map(fn (GroundAircraftDto $dto) => $dto->callsign)->all());
    }

    public function test_the_dto_carries_the_feed_values_and_null_for_a_missing_flight_plan(): void
    {
        $this->fakeFeed();

        $aircraft = app(TrafficSource::class)->pilotsNear('KTST')->keyBy('callsign');

        $this->assertEquals(
            new GroundAircraftDto('TST1', 1000001, 40.0, -73.0, 0, 180, 25, 'B738', 'KTST', 'KBOS'),
            $aircraft['TST1'],
        );
        $this->assertEquals(
            new GroundAircraftDto('TST3', 1000007, 40.001, -73.001, 0, 90, 20, null, null, null),
            $aircraft['TST3'],
        );
    }

    public function test_the_ceiling_follows_the_airport_elevation(): void
    {
        $this->fakeFeed();
        Airport::where('icao', 'KTST')->update(['elevation_ft' => 0]);

        // TST2 at 319 ft is now above 0 + 300.
        $this->assertSame(['TST1', 'TST3'], app(TrafficSource::class)->pilotsNear('KTST')->pluck('callsign')->all());
    }

    public function test_two_airports_in_one_cycle_share_one_fetch(): void
    {
        $this->fakeFeed();

        $source = app(TrafficSource::class);
        $first = $source->pilotsNear('KTST');
        $second = app(TrafficSource::class)->pilotsNear('KTS2');

        $this->assertCount(3, $first);
        $this->assertSame(['TS2A'], $second->pluck('callsign')->all());
        Http::assertSentCount(2); // status.json once, the data file once
        $this->assertSame(1, Http::recorded(fn (Request $request) => $request->url() === self::DATA_URL)->count());
        $this->assertTrue(Http::recorded()->every(fn (array $pair) => $pair[0]->hasHeader('User-Agent', config('ground-coordination.datafeed.user_agent'))));
    }

    public function test_the_cache_expires_after_cache_seconds(): void
    {
        $this->fakeFeed();
        config(['ground-coordination.datafeed.cache_seconds' => 10]);

        app(TrafficSource::class)->pilotsNear('KTST');
        $this->travel(11)->seconds();
        app(TrafficSource::class)->pilotsNear('KTST');

        // The data file twice; the status file only once (its URL is kept longer).
        Http::assertSentCount(3);
    }

    public function test_a_successful_fetch_records_ok(): void
    {
        $this->fakeFeed();

        app(TrafficSource::class)->pilotsNear('KTST');

        $state = FeedState::where('feed', FeedStatus::DEFAULT_FEED)->sole();
        $this->assertSame(FeedState::OK, $state->last_outcome);
        $this->assertNotNull($state->last_success_at);
        $this->assertTrue(app(FeedStatus::class)->isLive());
    }

    public function test_a_failed_fetch_records_failed_and_returns_empty(): void
    {
        Http::fake([
            self::STATUS_URL => Http::response($this->fixture('status.json')),
            self::DATA_URL => Http::response('upstream down', 503),
        ]);

        $this->assertCount(0, app(TrafficSource::class)->pilotsNear('KTST'));

        $state = FeedState::where('feed', FeedStatus::DEFAULT_FEED)->sole();
        $this->assertSame(FeedState::FAILED, $state->last_outcome);
        $this->assertNull($state->last_success_at);
        $this->assertNotNull($state->last_failure_at);
        $this->assertStringContainsString('503', (string) $state->last_error);
        $this->assertFalse(app(FeedStatus::class)->isLive());
    }

    public function test_a_connection_failure_is_not_cached_and_the_next_cycle_retries(): void
    {
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            if ($request->url() === self::STATUS_URL) {
                return Http::response($this->fixture('status.json'));
            }
            if (++$calls === 1) {
                throw new ConnectionException('timed out');
            }

            return Http::response($this->fixture('vatsim-data.json'));
        });

        $this->assertCount(0, app(TrafficSource::class)->pilotsNear('KTST'));
        $this->assertSame('timed out', FeedState::sole()->last_error);
        $this->assertCount(3, app(TrafficSource::class)->pilotsNear('KTST'));
        $this->assertSame(FeedState::OK, FeedState::sole()->last_outcome);
    }

    public function test_a_status_file_without_a_v3_url_is_a_failure(): void
    {
        Http::fake([self::STATUS_URL => Http::response(['data' => ['v3' => []]])]);

        $this->assertCount(0, app(TrafficSource::class)->pilotsNear('KTST'));
        $this->assertSame(FeedState::FAILED, FeedState::sole()->last_outcome);
    }

    public function test_an_unknown_airport_returns_empty_without_fetching(): void
    {
        $this->fakeFeed();

        $this->assertCount(0, app(TrafficSource::class)->pilotsNear('KXXX'));
        Http::assertNothingSent();
    }

    private function fakeFeed(): void
    {
        Http::fake([
            self::STATUS_URL => Http::response($this->fixture('status.json')),
            self::DATA_URL => Http::response($this->fixture('vatsim-data.json')),
        ]);
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__."/fixtures/datafeed/{$name}"), true, 512, JSON_THROW_ON_ERROR);
    }
}
