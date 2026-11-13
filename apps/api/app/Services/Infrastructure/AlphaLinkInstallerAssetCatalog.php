<?php

namespace App\Services\Infrastructure;

use InvalidArgumentException;

/** Maps AlphaLink installation identifiers to the private reviewed asset layout. */
class AlphaLinkInstallerAssetCatalog
{
    /**
     * @return array<string, string>
     */
    public function manifestPaths(): array
    {
        return [
            'install/pmta_source' => 'pmta/shared/source.conf',
            'install/pmta_config' => 'pmta/shared/base.conf',
            'install/tracker_index' => 'optional/tracking/index.php',
            'install/tracker_handler' => 'optional/tracking/TrackerRequestHandler.php',
            'install/tracker_homepage' => 'optional/tracking/homepage.html',
            'install/cf_ips.conf' => 'optional/tracking/cloudflare-ips.conf',
            'install/dovecot_centos.conf' => 'optional/imap/dovecot.conf',
            'install/squid_centos.conf' => 'optional/proxy/squid.conf',
            'install/dkim.json' => 'optional/dkim/keys.json',
            'install/nginx_repo' => 'optional/reverse-proxy/nginx-repo.conf',
            'install/nginx_config' => 'optional/reverse-proxy/nginx.conf',
            'install/gs' => 'optional/gscript/gs',
            'install/gs.service' => 'optional/gscript/gs.service',
        ];
    }

    public function pathFor(string $sourceIdentifier): string
    {
        if (preg_match('/\Ainstall\/pmta_(40|45|50)\/([a-zA-Z0-9_.-]+)\z/', $sourceIdentifier, $matches) === 1) {
            $version = match ($matches[1]) {
                '40' => '4.0',
                '45' => '4.5',
                '50' => '5.0',
            };
            $filename = match ($matches[2]) {
                'pmta.rpm' => 'package.rpm',
                'pmtahttp_patch' => 'httpd-patch',
                'pmtad_patch' => 'pmtad-patch',
                'pmta.lic' => 'license.lic',
                'pmta_domains' => 'domains.conf',
                default => throw new InvalidArgumentException('The PMTA source asset is not in the AlphaLink catalog.'),
            };

            return "pmta/{$version}/{$filename}";
        }

        return $this->manifestPaths()[$sourceIdentifier]
            ?? throw new InvalidArgumentException('The installation source asset is not in the AlphaLink catalog.');
    }
}
