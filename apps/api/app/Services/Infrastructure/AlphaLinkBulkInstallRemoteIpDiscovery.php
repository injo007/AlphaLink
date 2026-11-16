<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use InvalidArgumentException;

/** Reproduces the read-only address discovery phase shared by reference CentOS 7 and 9 installers. */
class AlphaLinkBulkInstallRemoteIpDiscovery
{
    public function __construct(
        private readonly SshHostProbe $hostProbe,
        private readonly AlphaLinkBulkInstallIpPlanner $ipPlanner,
    ) {}

    /**
     * @return list<string>
     *
     * @throws HostProbeException
     */
    public function discover(ManagedServer $server, string $credential, string $credentialType = 'password'): array
    {
        $output = $this->hostProbe->discoverGlobalServerIps($server, $credential, $credentialType);
        $rawLines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', trim($output)) ?: []), fn (string $ip): bool => $ip !== ''));

        if ($rawLines === []) {
            throw new HostProbeException('No Ips Found');
        }

        $addresses = [];
        $interfaceMap = [];
        foreach ($rawLines as $raw) {
            $parts = explode('|', $raw, 2);
            $publicIp = trim($parts[0]);
            $interfaceIp = isset($parts[1]) ? trim($parts[1]) : $publicIp;

            $addresses[] = $publicIp;
            if ($interfaceIp !== '') {
                $interfaceMap[$publicIp] = $interfaceIp;
            }
        }

        $primaryIp = trim((string) $server->ip_address);
        if ($primaryIp !== '' && filter_var($primaryIp, FILTER_VALIDATE_IP) !== false && ! in_array($primaryIp, $addresses, true)) {
            $addresses[] = $primaryIp;
        }

        try {
            $ordered = $this->ipPlanner->orderDiscoveredIps($addresses);

            if ($server->exists) {
                $facts = is_array($server->host_facts) ? $server->host_facts : [];
                $facts['public_ips'] = $ordered;
                if ($interfaceMap !== []) {
                    $facts['ip_interface_map'] = array_merge($facts['ip_interface_map'] ?? [], $interfaceMap);
                }
                $server->forceFill(['host_facts' => $facts])->save();
            }

            return $ordered;
        } catch (InvalidArgumentException) {
            throw new HostProbeException('Server IP discovery returned an invalid address.');
        }
    }
}
