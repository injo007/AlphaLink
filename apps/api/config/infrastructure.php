<?php

return [
    // DMS's settings form defaults PMTA's listener to port 1212 and allows it
    // to be changed. Deployments can set the equivalent here without storing
    // an operational value in source control.
    'pmta_port' => (int) env('INFRASTRUCTURE_PMTA_PORT', 1212),
    'probe_timeout_seconds' => (int) env('INFRASTRUCTURE_PROBE_TIMEOUT_SECONDS', 10),
    'ip_api_key' => env('INFRASTRUCTURE_IP_API_KEY'),
    'maxmind_city_database' => env('INFRASTRUCTURE_MAXMIND_CITY_DATABASE', storage_path('app/maxmind/city.mmdb')),
];
