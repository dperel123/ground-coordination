<?php

return [

    /*
    | Master switch. While false the package registers nothing a member can
    | reach; the host turns it on per environment.
    */
    'enabled' => env('GROUND_COORDINATION_ENABLED', false),

    /*
    | The Blade layout component the pilot-facing pages render inside.
    */
    'layout' => 'components.layouts.app',

    /*
    | The Blade layout component the controller-facing pages render inside.
    */
    'controller_layout' => 'components.layouts.tools',

    /*
    | ICAO codes of the airports ground coordination is offered at.
    */
    'airports' => ['KJFK'],

    /*
    | How often, in seconds, an open page refreshes its traffic picture.
    */
    'poll_seconds' => 15,

    /*
    | An aircraft not reported by the traffic source for this many seconds is
    | treated as gone.
    */
    'stale_after_seconds' => 300,

];
