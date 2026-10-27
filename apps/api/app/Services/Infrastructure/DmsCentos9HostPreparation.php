<?php

namespace App\Services\Infrastructure;

/**
 * Reconstructs the DMS CentOS 9/AlmaLinux 9 host setup and old-package cleanup.
 * This stage is deliberately not bound to a production installer runner.
 */
class DmsCentos9HostPreparation
{
    public function run(
        DmsInstallerRemoteCommandTransport $transport,
        string $serverIp,
        ?string $domain,
    ): void {
        $this->configureHost($transport, $serverIp, $domain);
        $this->removeOldPackages($transport);
    }

    public function configureHost(
        DmsInstallerRemoteCommandTransport $transport,
        string $serverIp,
        ?string $domain,
    ): void {
        if (! empty($domain)) {
            $domainArgument = escapeshellarg($domain);
            $hostEntryArgument = escapeshellarg($serverIp.' '.$domain);
            $sedExpression = escapeshellarg('s/HOSTNAME=.*/HOSTNAME='.$domain.'/g');

            $transport->exec("hostnamectl set-hostname {$domainArgument}");
            $transport->exec("sed -i {$sedExpression} /etc/sysconfig/network");
            $transport->exec("grep -qxF {$hostEntryArgument} /etc/hosts || echo -e {$hostEntryArgument} >> /etc/hosts");
        }
    }

    public function removeOldPackages(DmsInstallerRemoteCommandTransport $transport): void
    {
        $transport->exec('dnf remove -y httpd libopendkim* opendkim postfix php* sendmail;');
        $transport->exec('rm -rf /etc/httpd;');
    }
}
