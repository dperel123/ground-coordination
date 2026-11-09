<?php

namespace Nyartcc\GroundCoordination\Contracts;

/**
 * Pushes a change to every open board, through the host's broadcaster.
 */
interface BoardNotifier
{
    /** @param array<string, mixed> $payload */
    public function announce(string $channel, array $payload): void;
}
