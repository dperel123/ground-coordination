<?php

namespace Nyartcc\GroundCoordination\Enums;

/** Where an aircraft on the ground stands, as the sweep last saw it. */
enum GroundPhase: string
{
    /** Matched to a stand and stationary. */
    case AtStand = 'at_stand';

    /** At or above the stationary threshold, matched or not. */
    case Moving = 'moving';

    /** Stationary but at no stand (holding short, in a pad, on a remote ramp). */
    case OffStand = 'off_stand';
}
