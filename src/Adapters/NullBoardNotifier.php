<?php

namespace Nyartcc\GroundCoordination\Adapters;

use Nyartcc\GroundCoordination\Contracts\BoardNotifier;

/** Announces nothing. */
final class NullBoardNotifier implements BoardNotifier
{
    public function announce(string $channel, array $payload): void {}
}
