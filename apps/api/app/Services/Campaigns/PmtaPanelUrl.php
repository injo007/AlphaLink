<?php

namespace App\Services\Campaigns;

use App\Models\ManagedServer;

/** Resolves the PMTA public endpoint recorded by a successful server install. */
final class PmtaPanelUrl
{
    public static function forServer(ManagedServer $server, int $port): string
    {
        $parameters = is_array($server->install_parameters) ? $server->install_parameters : [];
        $rows = is_array($parameters['data'] ?? null) ? $parameters['data'] : [];
        $main = null;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($main === null) {
                $main = $row;
            }
            if (($row['main'] ?? false) === true) {
                $main = $row;
                break;
            }
        }

        return self::forInstallation(
            (string) $server->ip_address,
            is_string($main['domain'] ?? null) ? $main['domain'] : null,
            $port,
            ($parameters['https'] ?? false) === true,
        );
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
