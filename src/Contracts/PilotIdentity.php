<?php

namespace Nyartcc\GroundCoordination\Contracts;

/**
 * Who is using the page, as the host knows them.
 */
interface PilotIdentity
{
    /** The signed-in member's VATSIM CID, or null for a guest. */
    public function currentCid(): ?int;

    /** Whether the signed-in member may use the pilot side. */
    public function isPilot(): bool;

    /** Whether the signed-in member may use the controller side. */
    public function isController(): bool;
}
