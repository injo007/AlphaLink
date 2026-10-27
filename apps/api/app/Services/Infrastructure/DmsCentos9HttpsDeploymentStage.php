<?php

namespace App\Services\Infrastructure;

/** Replays DMS CentOS 9's optional Certbot/Apache stage through a command transport. */
class DmsCentos9HttpsDeploymentStage
{
    /**
     * DMS does nothing when no domain is supplied. With a domain, it installs
     * Certbot and its Apache plugin if missing, requests a no-redirect
     * certificate for the domain and aliases, then ensures its renewal cron.
     *
     * @param  list<string>  $subdomains
     */
    public function run(
        DmsInstallerRemoteCommandTransport $commands,
        ?string $domain,
        array $subdomains = [],
    ): void {
        if (! $domain) {
            return;
        }

        $commands->exec('rpm -qa | grep -qw certbot || dnf -y install certbot;');
        $commands->exec('rpm -qa | grep -qw python3-certbot-apache || dnf -y install python3-certbot-apache;');

        $certificateDomains = implode(',', [$domain, ...$subdomains]);
        $email = 'cert@'.$domain;
        $commands->exec(
            'certbot --apache --no-redirect -d '.escapeshellarg($certificateDomains).
            ' -m '.escapeshellarg($email).' -n --agree-tos',
        );
        $commands->exec("(crontab -l | grep 'certbot') || { crontab -l; echo '0 0 */15 * * certbot renew'; } | crontab -");
    }
}
