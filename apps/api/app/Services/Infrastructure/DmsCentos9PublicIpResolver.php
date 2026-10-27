<?php

namespace App\Services\Infrastructure;

/** Resolves the selected interface's egress address through a bounded read-only host lookup. */
class DmsCentos9PublicIpResolver implements DmsBulkInstallPublicIpResolver
{
    public function __construct(private readonly DmsInstallerRemoteCommandTransport $transport) {}

    public function resolve(string $interfaceIp): string
    {
        $result = $this->transport->exec('curl -fsS --connect-timeout 5 --max-time 12 --interface '.escapeshellarg($interfaceIp).' https://ifconfig.me/ip 2>/dev/null');
        $publicIp = is_string($result) ? trim($result) : '';

        return filter_var($publicIp, FILTER_VALIDATE_IP) ? $publicIp : $interfaceIp;
    }
}
