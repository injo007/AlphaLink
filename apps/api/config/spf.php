<?php

return [
    'max_includes' => 50,
    'max_workers' => min(16, max(1, (int) env('SPF_MAX_WORKERS', 16))),
    'max_domains_per_scan' => 10_000_000,
    'max_upload_kilobytes' => min(1_048_576, max(1, (int) env('SPF_MAX_UPLOAD_KILOBYTES', 1_048_576))),
    'max_search_table_rows' => min(50_000, max(1_000, (int) env('SPF_MAX_SEARCH_TABLE_ROWS', 20_000))),
    'max_search_dns_cache_entries' => min(50_000, max(100, (int) env('SPF_MAX_SEARCH_DNS_CACHE_ENTRIES', 10_000))),
    'resolve_a_mx' => filter_var(env('SPF_RESOLVE_A_MX', true), FILTER_VALIDATE_BOOLEAN),
    'max_mechanism_lookups' => 10,
    'search_dns_checks' => filter_var(env('SPF_SEARCH_DNS_CHECKS', true), FILTER_VALIDATE_BOOLEAN),
    'max_search_dns_checks' => (int) env('SPF_MAX_SEARCH_DNS_CHECKS', 10_000),
    'search_retention_days' => max(1, (int) env('SPF_SEARCH_RETENTION_DAYS', 7)),
    'dns_cache_entries' => min(10_000, max(100, (int) env('SPF_DNS_CACHE_ENTRIES', 2000))),
    'dns_cache_ttl_seconds' => min(60, max(1, (int) env('SPF_DNS_CACHE_TTL_SECONDS', 60))),
    'slow_lookup_threshold_ms' => max(1000, (int) env('SPF_SLOW_LOOKUP_THRESHOLD_MS', 5000)),
    'activity_heartbeat_seconds' => max(1, (int) env('SPF_ACTIVITY_HEARTBEAT_SECONDS', 2)),
    'activity_slow_event_interval_seconds' => max(5, (int) env('SPF_ACTIVITY_SLOW_EVENT_INTERVAL_SECONDS', 15)),
    'activity_checkpoint_domains' => max(50, (int) env('SPF_ACTIVITY_CHECKPOINT_DOMAINS', 250)),
    'activity_event_retention' => min(2000, max(100, (int) env('SPF_ACTIVITY_EVENT_RETENTION', 500))),
];
