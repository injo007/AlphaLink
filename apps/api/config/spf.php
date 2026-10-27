<?php

return [
    'max_includes' => 50,
    'max_workers' => (int) env('SPF_MAX_WORKERS', 8),
    'resolve_a_mx' => filter_var(env('SPF_RESOLVE_A_MX', true), FILTER_VALIDATE_BOOLEAN),
    'max_mechanism_lookups' => 10,
    'search_dns_checks' => filter_var(env('SPF_SEARCH_DNS_CHECKS', true), FILTER_VALIDATE_BOOLEAN),
    'max_search_dns_checks' => (int) env('SPF_MAX_SEARCH_DNS_CHECKS', 10_000),
];
