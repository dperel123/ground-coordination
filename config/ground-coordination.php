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
    | How often, in seconds, an open page refreshes its traffic picture, and
    | how often the scheduled sweep runs (rounded to the nearest frequency the
    | scheduler offers: 1, 2, 5, 10, 15, 20, 30 or 60 seconds).
    */
    'poll_seconds' => 15,

    /*
    | An aircraft not reported by the traffic source for this many seconds is
    | treated as gone.
    */
    'stale_after_seconds' => 300,

    /*
    | The traffic feed counts as live only while its last successful fetch is
    | at most this many seconds old (Services\FeedStatus::isLive()).
    */
    'live_window_seconds' => 300,

    /*
    | How long the scheduled sweep's overlap lock may be held, in minutes,
    | before a crashed run's lock is ignored.
    */
    'sweep_lock_minutes' => 2,

    /*
    | The default traffic source (Adapters\VatsimDatafeedTrafficSource).
    | status_url is VATSIM's status file, which names the v3 data URL; the
    | data URL it names is kept for status_cache_seconds. The parsed pilots
    | list is cached for cache_seconds, so every airport swept in one cycle
    | shares one download. Keep cache_seconds below poll_seconds.
    */
    'datafeed' => [
        'status_url' => 'https://status.vatsim.net/status.json',
        'user_agent' => 'nyartcc-ground-coordination/0.1 (ground sweep)',
        'timeout' => 10,
        'cache_seconds' => 10,
        'status_cache_seconds' => 3600,
    ],

    /*
    | Which reported aircraft count as on the ground, and when one counts as
    | stationary. An aircraft is on the ground when it is inside the airport
    | bbox, slower than max_groundspeed_kt and lower than the airport's
    | elevation_ft + max_agl_ft.
    */
    'ground' => [
        'max_groundspeed_kt' => 50,
        'max_agl_ft' => 300,
        'stationary_kt' => 3,
    ],

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
