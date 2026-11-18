<?php

namespace App\Services\Campaigns;

use App\Models\Workspace;

final class PmtaProxySettings
{
    /**
     * Resolve the HTTP relay proxy used by both the monitor and PMTA command requests.
     *
     * @return array{host: string, port: int, username: string, password: string, url: string}|null
     */
    public function forWorkspace(Workspace $workspace): ?array
    {
        $saved = $workspace->infrastructureSettings;
        $configuredHost = trim((string) (config('infrastructure.pmta_relay_proxy_host')
            ?: config('infrastructure.bulk_install_settings.proxy_host', '')));
        $host = filter_var($configuredHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ? $configuredHost
            : null;

        $configuredPort = config('infrastructure.pmta_relay_proxy_port')
            ?: config('infrastructure.bulk_install_settings.pmta_relay_proxy_port');
        $port = $configuredPort !== null && $configuredPort !== ''
            ? filter_var($configuredPort, FILTER_VALIDATE_INT)
            : filter_var($saved?->proxy_port, FILTER_VALIDATE_INT);
        if (($port === false || $port < 1 || $port > 65535) && ($configuredPort === null || $configuredPort === '')) {
            $port = filter_var(config('infrastructure.bulk_install_settings.proxy_port'), FILTER_VALIDATE_INT);
        }
        if (! is_string($host) || $port === false || $port < 1 || $port > 65535) {
            return null;
        }

        $username = trim((string) ($saved?->proxy_username ?? ''));
        $password = (string) ($saved?->proxy_password ?? '');
        if ($username === '') {
            $username = (string) (config('infrastructure.pmta_relay_proxy_username')
                ?: config('infrastructure.bulk_install_settings.proxy_username', ''));
        }
        if ($password === '') {
            $password = (string) (config('infrastructure.pmta_relay_proxy_password')
                ?: config('infrastructure.bulk_install_settings.proxy_password', ''));
        }
        if (($username === '') !== ($password === '')) {
            return null;
        }

        $authentication = $username === ''
            ? ''
            : rawurlencode($username).':'.rawurlencode($password).'@';

        return [
            'host' => $host,
            'port' => (int) $port,
            'username' => $username,
            'password' => $password,
            'url' => 'http://'.$authentication.$host.':'.$port,
        ];
    }
}
