<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use InvalidArgumentException;

/** Reproduces the read-only address discovery phase shared by DMS CentOS 7 and 9 installers. */
class DmsBulkInstallRemoteIpDiscovery
{
    public function __construct(
        private readonly SshHostProbe $hostProbe,
        private readonly DmsBulkInstallIpPlanner $ipPlanner,
    ) {}

    /**
     * @return list<string>
     *
     * @throws HostProbeException
     */
    public function discover(ManagedServer $server, string $credential, string $credentialType = 'password'): array
    {
        $output = $this->hostProbe->discoverGlobalServerIps($server, $credential, $credentialType);
        $addresses = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', trim($output)) ?: []), fn (string $ip): bool => $ip !== ''));

        if ($addresses === []) {
            throw new HostProbeException('No Ips Found');
        }

        try {
            return $this->ipPlanner->orderDiscoveredIps($addresses);
        } catch (InvalidArgumentException) {
            throw new HostProbeException('Server IP discovery returned an invalid address.');
        }
    }
}
