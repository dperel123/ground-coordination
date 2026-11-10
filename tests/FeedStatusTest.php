<?php

namespace Nyartcc\GroundCoordination\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nyartcc\GroundCoordination\Models\FeedState;
use Nyartcc\GroundCoordination\Services\FeedStatus;
use Tests\TestCase;

class FeedStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ground-coordination.live_window_seconds' => 300]);
        $this->freezeSecond();
    }

    public function test_no_record_is_not_live(): void
    {
        $this->assertFalse($this->feedStatus()->isLive());
    }

    public function test_a_success_inside_the_window_is_live_and_one_past_it_is_not(): void
    {
        $this->feedStatus()->recordSuccess();

        $this->travel(300)->seconds();
        $this->assertTrue($this->feedStatus()->isLive(), 'exactly at the window edge');

        $this->travel(1)->seconds();
        $this->assertFalse($this->feedStatus()->isLive());
    }

    public function test_a_failure_after_a_recent_success_stays_live_until_the_success_ages_out(): void
    {
        $this->feedStatus()->recordSuccess();
        $this->travel(60)->seconds();
        $this->feedStatus()->recordFailure('boom');

        $state = FeedState::sole();
        $this->assertSame(FeedState::FAILED, $state->last_outcome);
        $this->assertTrue($this->feedStatus()->isLive());

        $this->travel(241)->seconds();
        $this->assertFalse($this->feedStatus()->isLive());
    }

    public function test_the_window_is_read_from_config(): void
    {
        $this->feedStatus()->recordSuccess();
        $this->travel(61)->seconds();

        config(['ground-coordination.live_window_seconds' => 60]);
        $this->assertFalse($this->feedStatus()->isLive());
    }

    public function test_feeds_are_tracked_separately(): void
    {
        $this->feedStatus()->recordSuccess('host-feed');

        $this->assertTrue($this->feedStatus()->isLive('host-feed'));
        $this->assertFalse($this->feedStatus()->isLive());
    }

    private function feedStatus(): FeedStatus
    {
        return app(FeedStatus::class);
    }
}
