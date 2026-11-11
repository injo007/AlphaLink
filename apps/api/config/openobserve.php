<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenObserve Connection Configuration
    |--------------------------------------------------------------------------
    |
    | OpenObserve provides cloud-native log ingestion and SQL search APIs.
    | When enabled and reachable, AlphaLink routes error intelligence queries
    | to OpenObserve and can stream runtime errors in real time.
    |
    */

    'enabled' => (bool) env('OPENOBSERVE_ENABLED', false),

    'url' => rtrim((string) env('OPENOBSERVE_URL', 'http://127.0.0.1:5080'), '/'),

    'org' => env('OPENOBSERVE_ORG', 'default'),

    'stream' => env('OPENOBSERVE_STREAM', 'default'),

    'username' => env('OPENOBSERVE_USER', 'admin@alphalink.test'),

    'password' => env('OPENOBSERVE_PASSWORD', ''),

    'token' => env('OPENOBSERVE_TOKEN', ''),

    'timeout' => (float) env('OPENOBSERVE_TIMEOUT', 3.0),

];
