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
        $savedRelayIps = $saved?->pmta_relay_ips;
        $configuredRelayIps = config('infrastructure.bulk_install_settings.pmta_relay_ips', []);
        $savedRelayIps = is_array($savedRelayIps)
            ? $savedRelayIps
            : preg_split('/[\s,;]+/', (string) $savedRelayIps, -1, PREG_SPLIT_NO_EMPTY);
        $configuredRelayIps = is_array($configuredRelayIps)
            ? $configuredRelayIps
            : preg_split('/[\s,;]+/', (string) $configuredRelayIps, -1, PREG_SPLIT_NO_EMPTY);

        $configuredHost = trim((string) config('infrastructure.bulk_install_settings.proxy_host', ''));
        if ($configuredHost !== '') {
            $host = filter_var($configuredHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                ? $configuredHost
                : null;
        } else {
            // Older workspaces stored the relay endpoint itself in this field.
            // Keep that fallback for existing installations, but never let an
            // auto-detected PMTA source/allowlist IP override an explicit proxy.
            $host = collect(array_merge($savedRelayIps ?: [], $configuredRelayIps ?: []))
                ->map(fn (mixed $ip): string => trim((string) $ip))
                ->first(fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false);
        }

        $port = filter_var($saved?->proxy_port, FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535) {
            $port = filter_var(config('infrastructure.bulk_install_settings.proxy_port'), FILTER_VALIDATE_INT);
        }
        if (! is_string($host) || $port === false || $port < 1 || $port > 65535) {
            return null;
        }

        $username = trim((string) ($saved?->proxy_username ?? ''));
        $password = (string) ($saved?->proxy_password ?? '');
        if ($username === '') {
            $username = (string) config('infrastructure.bulk_install_settings.proxy_username', '');
        }
        if ($password === '') {
            $password = (string) config('infrastructure.bulk_install_settings.proxy_password', '');
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
