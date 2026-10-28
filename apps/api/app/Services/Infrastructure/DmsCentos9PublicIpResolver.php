<?php

namespace App\Services\Infrastructure;

use RuntimeException;

/** Resolves the selected interface's egress address through a bounded read-only host lookup. */
class DmsCentos9PublicIpResolver implements DmsBulkInstallPublicIpResolver
{
    /** @var list<string> */
    private const ENDPOINTS = [
        'https://ifconfig.me/ip',
        'https://api.ipify.org',
        'https://icanhazip.com',
        'https://ident.me',
    ];

    public function __construct(private readonly DmsInstallerRemoteCommandTransport $transport) {}

    public function resolve(string $interfaceIp): string
    {
        $family = filter_var($interfaceIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ? FILTER_FLAG_IPV4
            : FILTER_FLAG_IPV6;

        foreach (self::ENDPOINTS as $endpoint) {
            $result = $this->transport->exec(
                'curl -fsS --connect-timeout 3 --max-time 6 --interface '.escapeshellarg($interfaceIp).' '.$endpoint.' 2>/dev/null',
            );
            if ($result === false) {
                throw new RuntimeException('The remote server connection was lost while checking a selected interface public address.');
            }

            $publicIp = is_string($result) ? trim($result) : '';

            if (filter_var($publicIp, FILTER_VALIDATE_IP, $family | FILTER_FLAG_NO_PRIV_RANGE) !== false) {
                return $publicIp;
            }
        }

        return $interfaceIp;
    }
}
