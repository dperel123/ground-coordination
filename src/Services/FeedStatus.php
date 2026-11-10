<?php

namespace Nyartcc\GroundCoordination\Services;

use Nyartcc\GroundCoordination\Models\FeedState;

/**
 * Whether the traffic feed is answering. The default traffic source records
 * every real fetch here; a host's own TrafficSource may call record*() too.
 */
class FeedStatus
{
    public const DEFAULT_FEED = 'vatsim-datafeed';

    public function recordSuccess(string $feed = self::DEFAULT_FEED): void
    {
        FeedState::updateOrCreate(['feed' => $feed], [
            'last_outcome' => FeedState::OK,
            'last_attempt_at' => now(),
            'last_success_at' => now(),
        ]);
    }

    public function recordFailure(string $error, string $feed = self::DEFAULT_FEED): void
    {
        FeedState::updateOrCreate(['feed' => $feed], [
            'last_outcome' => FeedState::FAILED,
            'last_attempt_at' => now(),
            'last_failure_at' => now(),
            'last_error' => mb_substr($error, 0, 1000),
        ]);
    }

    /** True only while the last successful fetch is within live_window_seconds. */
    public function isLive(string $feed = self::DEFAULT_FEED): bool
    {
        $lastSuccess = FeedState::where('feed', $feed)->value('last_success_at');
        if ($lastSuccess === null) {
            return false;
        }

        $window = (int) config('ground-coordination.live_window_seconds');

        return now()->subSeconds($window)->lte($lastSuccess);
    }
}
