<?php

$pmtaAdminPort = env('ALPHALINK_INSTALL_PMTA_ADMIN_PORT');
$proxyPort = env('ALPHALINK_INSTALL_PROXY_PORT');
$applyFirewall = env('ALPHALINK_INSTALL_APPLY_FIREWALL');
$allowAllMonitor = env('ALPHALINK_INSTALL_ALLOW_ALL_MONITOR');

return [
    // AlphaLink keeps installation settings under its own environment
    // namespace; these defaults mirror the inspected source behavior.
    'pmta_port' => (int) (env('ALPHALINK_INSTALL_PMTA_PORT') ?: env('INFRASTRUCTURE_PMTA_PORT', 1212)),
    'probe_timeout_seconds' => (int) env('INFRASTRUCTURE_PROBE_TIMEOUT_SECONDS', 10),
    // Installer commands include package-manager and large-package work; the
    // short probe timeout would abort DNF and leave phpseclib's exec channel open.
    'install_ssh_timeout_seconds' => (int) env('ALPHALINK_INSTALL_SSH_TIMEOUT_SECONDS', 300),
    'ip_api_key' => env('INFRASTRUCTURE_IP_API_KEY'),
    // DMS-compatible history checks publish each enabled IP/domain in DNS queries.
    'ip_history_enabled' => filter_var(env('INFRASTRUCTURE_IP_HISTORY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'domain_history_enabled' => filter_var(env('INFRASTRUCTURE_DOMAIN_HISTORY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'maxmind_city_database' => env('INFRASTRUCTURE_MAXMIND_CITY_DATABASE', storage_path('app/maxmind/city.mmdb')),
    // Ubuntu deployments use private shared storage by default. The installer
    // writes the same path explicitly; this fallback also repairs older .env files.
    'install_assets_directory' => env('ALPHALINK_INSTALL_ASSETS_DIRECTORY', storage_path('app/private/install-assets')),
    'pmta_feedback_staging_path' => env('ALPHALINK_PMTA_FEEDBACK_STAGING_PATH', storage_path('app/private/pmta-feedback-staging')),
    'tracking_callback_url' => env('ALPHALINK_TRACKING_CALLBACK_URL'),
    'bulk_install_settings' => [
        // Defaults follow the source-backed installation behavior.
        'pmta_admin_port' => $pmtaAdminPort === null || $pmtaAdminPort === '' ? 1213 : $pmtaAdminPort,
        'proxy_port' => $proxyPort === null || $proxyPort === '' ? 1214 : $proxyPort,
        'apply_firewall' => $applyFirewall === null || $applyFirewall === '' ? false : $applyFirewall,
        'allow_all_monitor' => $allowAllMonitor === null || $allowAllMonitor === '' ? false : $allowAllMonitor,
        'dev_ips' => env('ALPHALINK_INSTALL_DEV_IPS', ''),
        'pmta_relay_ips' => env('ALPHALINK_INSTALL_PMTA_RELAY_IPS', ''),
        'production_ips' => env('ALPHALINK_INSTALL_PRODUCTION_IPS', ''),
        'rdp_ips' => env('ALPHALINK_INSTALL_RDP_IPS', ''),
        'dkim_key' => env('ALPHALINK_INSTALL_DKIM_KEY', ''),
        'webmail_username' => env('ALPHALINK_INSTALL_WEBMAIL_USERNAME', ''),
        'webmail_password' => env('ALPHALINK_INSTALL_WEBMAIL_PASSWORD', ''),
        'proxy_username' => env('ALPHALINK_INSTALL_PROXY_USERNAME', ''),
        'proxy_password' => env('ALPHALINK_INSTALL_PROXY_PASSWORD', ''),
        'mini_app_key' => env('ALPHALINK_INSTALL_MINI_APP_KEY', ''),
    ],
    'single_install_execution_enabled' => filter_var(env('ALPHALINK_SINGLE_INSTALL_EXECUTION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'bulk_install_execution_enabled' => filter_var(env('ALPHALINK_INSTALL_EXECUTION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'reinstall_execution_enabled' => filter_var(env('ALPHALINK_REINSTALL_EXECUTION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'reinstall_ip_config_execution_enabled' => filter_var(env('ALPHALINK_REINSTALL_IP_CONFIG_EXECUTION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];
