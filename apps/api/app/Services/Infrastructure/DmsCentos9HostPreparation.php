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
    ): string {
        $hostname = trim((string) $domain);
        if ($hostname === '') {
            $packedIp = @inet_pton($serverIp);
            $identifier = $packedIp === false ? substr(hash('sha256', $serverIp), 0, 16) : bin2hex($packedIp);
            $hostname = 'pmta-'.$identifier.'.invalid';
        }

        $hostnameArgument = escapeshellarg($hostname);
        $hostEntryArgument = escapeshellarg($serverIp.' '.$hostname);
        $sedExpression = escapeshellarg('s/HOSTNAME=.*/HOSTNAME='.$hostname.'/g');

        $transport->exec("hostnamectl set-hostname {$hostnameArgument}");
        $transport->exec("sed -i {$sedExpression} /etc/sysconfig/network");
        $transport->exec("grep -qxF {$hostEntryArgument} /etc/hosts || echo -e {$hostEntryArgument} >> /etc/hosts");

        return $hostname;
    }

    public function removeOldPackages(DmsInstallerRemoteCommandTransport $transport): void
    {
        $transport->exec('dnf remove -y httpd libopendkim* opendkim postfix php* sendmail;');
        $transport->exec('rm -rf /etc/httpd;');
    }
}
