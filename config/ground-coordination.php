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

    /*
    | Where `ground-coordination:import {icao}` reads its reference data:
    | airports/{ICAO}.json and stands/{ICAO}.geojson. Any other file in
    | stands/ (e.g. {ICAO}.ambiguous.geojson, the hand-correction backlog) is
    | never read.
    */
    'data_path' => dirname(__DIR__).'/resources/data',

    /*
    | `ground-coordination:fetch-osm {icao}`: where stand positions come from
    | (OpenStreetMap aeroway=parking_position via the Overpass API), how the
    | request identifies itself (Overpass refuses anonymous clients with a
    | 406), and the provenance written onto every stand it produces.
    */
    'osm' => [
        'endpoint' => 'https://overpass-api.de/api/interpreter',
        'user_agent' => 'nyartcc-ground-coordination/0.1 (stand data import)',
        'timeout_seconds' => 120,
        'source' => 'openstreetmap',
        'attribution' => '© OpenStreetMap contributors, ODbL',
    ],

];
