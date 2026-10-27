<?php

return [
    'sender_path' => env('ALPHALINK_GLOBAL_TEST_SENDER_PATH'),
    'pmta_port' => (int) env('ALPHALINK_GLOBAL_TEST_PMTA_PORT', 25),
    'dig_path' => env('ALPHALINK_GLOBAL_TEST_DIG_PATH', 'dig'),
    'timeout_seconds' => 1800,
];
