<?php

namespace Nyartcc\GroundCoordination\Adapters;

use Nyartcc\GroundCoordination\Contracts\PilotIdentity;

/** Nobody is signed in and nobody may use either side. */
final class NullPilotIdentity implements PilotIdentity
{
    public function currentCid(): ?int
    {
        return null;
    }

    public function isPilot(): bool
    {
        return false;
    }

    public function isController(): bool
    {
        return false;
    }
}
