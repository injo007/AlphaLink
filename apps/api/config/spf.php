<?php

return [
    'max_includes' => 50,
    'max_workers' => min(16, max(1, (int) env('SPF_MAX_WORKERS', 8))),
    'max_domains_per_scan' => max(100_000, (int) env('SPF_MAX_DOMAINS_PER_SCAN', 250_000)),
    'max_search_table_rows' => min(50_000, max(1_000, (int) env('SPF_MAX_SEARCH_TABLE_ROWS', 20_000))),
    'max_search_dns_cache_entries' => min(50_000, max(100, (int) env('SPF_MAX_SEARCH_DNS_CACHE_ENTRIES', 10_000))),
    'resolve_a_mx' => filter_var(env('SPF_RESOLVE_A_MX', true), FILTER_VALIDATE_BOOLEAN),
    'max_mechanism_lookups' => 10,
    'search_dns_checks' => filter_var(env('SPF_SEARCH_DNS_CHECKS', true), FILTER_VALIDATE_BOOLEAN),
    'max_search_dns_checks' => (int) env('SPF_MAX_SEARCH_DNS_CHECKS', 10_000),
    'search_retention_days' => max(1, (int) env('SPF_SEARCH_RETENTION_DAYS', 7)),
];
