<?php

namespace App\Services\Infrastructure;

use RuntimeException;

/** Detects the installed PMTA 4.0 monitor topology for legacy server records. */
final class AlphaLinkPmtaMonitorModeResolver
{
    public function resolve(
        AlphaLinkInstallerRemoteCommandTransport $commands,
        int $version,
        int $publicPort,
    ): bool {
        if ($version !== 40) {
            return false;
        }
        if ($publicPort < 1 || $publicPort > 32767) {
            throw new RuntimeException('The PMTA public monitor port is invalid.');
        }

        $result = $commands->exec($this->probeCommand($publicPort));

        return match ($result) {
            'ALPHALINK_PMTA_PROXY_MODE' => false,
            'ALPHALINK_PMTA_DIRECT_MODE' => true,
            default => throw new RuntimeException(
                'The installed PMTA 4.0 monitor mode could not be verified. Change Domain stopped before changing the server; verify the PMTA monitor listener and Nginx configuration, then retry.',
            ),
        };
    }

    private function probeCommand(int $publicPort): string
    {
        $url = escapeshellarg('http://127.0.0.1:'.$publicPort.'/');

        return 'if test -s /etc/nginx/conf.d/default.conf; then printf ALPHALINK_PMTA_PROXY_MODE; '
            .'elif test ! -e /etc/nginx; then status=$(curl --silent --show-error --output /dev/null --write-out \'%{http_code}\' --connect-timeout 2 --max-time 2 '.$url.' 2>/dev/null || true); '
            .'case "$status" in 2??|3??) printf ALPHALINK_PMTA_DIRECT_MODE ;; *) printf ALPHALINK_PMTA_MODE_UNKNOWN ;; esac; '
            .'else printf ALPHALINK_PMTA_MODE_UNKNOWN; fi';
    }
}
