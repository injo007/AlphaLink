<?php

namespace App\Services\Campaigns;

use App\Models\ManagedServer;

/** Resolves the PMTA public endpoint recorded by a successful server install. */
final class PmtaPanelUrl
{
    public static function forServer(ManagedServer $server, int $port): string
    {
        $parameters = is_array($server->install_parameters) ? $server->install_parameters : [];

        return self::forInstallation(
            (string) $server->ip_address,
            self::mainDomain($parameters),
            $port,
            // `https` covers the server's general HTTPS install option (for
            // example its tracking vhost). Older installs can have that flag
            // without a TLS-enabled PMTA listener. Only use HTTPS when the
            // install workflow explicitly persisted its verified PMTA state.
            ($parameters['pmta_monitor_https'] ?? false) === true,
        );
    }

    /**
     * Return the browser-facing URL for the PMTA monitor.
     *
     * Direct HTTP monitor URLs are unreliable from an HTTPS AlphaLink page:
     * browsers may upgrade them to HTTPS even though PMTA only speaks HTTP.
     * Route those views through AlphaLink's authenticated, read-only proxy.
     */
    public static function displayUrl(ManagedServer $server, int $port): string
    {
        $parameters = is_array($server->install_parameters) ? $server->install_parameters : [];

        return self::displayUrlForInstallation(
            (string) $server->ip_address,
            self::mainDomain($parameters),
            $port,
            ($parameters['pmta_monitor_https'] ?? false) === true,
            (string) $server->getKey(),
        );
    }

    /**
     * Browser-safe PMTA URL for install logs and existing server records.
     * HTTP-only PMTA endpoints stay server-side behind AlphaLink's authenticated
     * HTTPS proxy, avoiding browser HTTPS upgrades to an HTTP listener.
     */
    public static function displayUrlForInstallation(
        string $ip,
        ?string $domain,
        int $port,
        bool $https,
        string $serverId,
    ): string {
        $directUrl = self::forInstallation($ip, $domain, $port, $https);

        if ($directUrl === '' || str_starts_with($directUrl, 'https://')) {
            return $directUrl;
        }

        return route('clean.console.pmta-admin.monitor', $serverId, false);
    }

    /** @param array<string, mixed> $parameters */
    private static function mainDomain(array $parameters): ?string
    {
        $rows = is_array($parameters['data'] ?? null) ? $parameters['data'] : [];
        $main = null;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $main ??= $row;
            if (($row['main'] ?? false) === true) {
                $main = $row;
                break;
            }
        }

        if (is_array($main) && self::isExplicitlyUnowned($main['is_our'] ?? null)) {
            return null;
        }

        return is_string($main['domain'] ?? null) ? $main['domain'] : null;
    }

    private static function isExplicitlyUnowned(mixed $value): bool
    {
        if (is_bool($value)) {
            return ! $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value === 0 || $value === 0.0;
        }

        return is_string($value)
            && in_array(strtolower(trim($value)), ['0', 'false', 'no', 'n', 'off'], true);
    }

    public static function forInstallation(string $ip, ?string $domain, int $port, bool $https): string
    {
        $isIp = filter_var($ip, FILTER_VALIDATE_IP) !== false;
        $validDomain = is_string($domain)
            && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && filter_var($domain, FILTER_VALIDATE_IP) === false;
        $useTls = $https && $validDomain;
        $host = $useTls ? (string) $domain : $ip;
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '['.$host.']';
        }
        if (! $isIp && ! $useTls) {
            return '';
        }

        return ($useTls ? 'https://' : 'http://').$host.':'.$port.'/';
    }
}
