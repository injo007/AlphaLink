<?php

namespace App\Services\Infrastructure;

/** Provisions the domain certificate and PMTA HTTPS monitor through a command transport. */
class DmsCentos9HttpsDeploymentStage
{
    public function __construct(
        private readonly DmsCentos9PmtaDeploymentPlanner $pmtaPlanner = new DmsCentos9PmtaDeploymentPlanner,
    ) {}

    /**
     * A domain is required to issue the certificate used by the PMTA monitor.
     * With a domain, ensure Apache can answer the HTTP-01 challenge, install
     * Certbot and its plugin if missing, request a no-redirect certificate for
     * the domain and aliases, then ensure its renewal cron.
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

        // PMTA TLS is required independently of the optional tracking app.
        // Ensure the Apache challenge handler exists even when tracking is off.
        $commands->exec('rpm -qa | grep -qw httpd || dnf -y install httpd;');
        $commands->exec('systemctl enable --now httpd');
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

    /**
     * Configure PMTA's public reverse proxy for HTTP or HTTPS. The staged
     * file is only activated after Nginx validates it; a failed check restores
     * the prior working config.
     *
     * @param  list<string>  $developmentIps
     * @param  list<string>  $productionIps
     * @param  list<string>  $pmtaRelayIps
     */
    public function configurePmtaMonitor(
        DmsInstallerRemoteCommandTransport $commands,
        DmsInstallerRemoteFileTransport $files,
        ?string $tlsDomain,
        int $version,
        int $publicPort,
        array $developmentIps,
        array $productionIps,
        bool $allowAllMonitor,
        array $pmtaRelayIps,
    ): bool {
        if ($tlsDomain === null) {
            $this->activatePmtaMonitorConfiguration(
                $commands,
                $files,
                null,
                $version,
                $publicPort,
                $developmentIps,
                $productionIps,
                $allowAllMonitor,
                $pmtaRelayIps,
            );

            return false;
        }

        try {
            $this->activatePmtaMonitorConfiguration(
                $commands,
                $files,
                $tlsDomain,
                $version,
                $publicPort,
                $developmentIps,
                $productionIps,
                $allowAllMonitor,
                $pmtaRelayIps,
            );

            return true;
        } catch (\RuntimeException $exception) {
            // Keep the public monitor usable by IP when TLS cannot be validated.
            // If the HTTP replacement also fails, propagate that failure so the
            // PMTA readiness check remains fail-closed.
            $this->activatePmtaMonitorConfiguration(
                $commands,
                $files,
                null,
                $version,
                $publicPort,
                $developmentIps,
                $productionIps,
                $allowAllMonitor,
                $pmtaRelayIps,
            );

            return false;
        }
    }

    /** @param list<string> $developmentIps @param list<string> $productionIps @param list<string> $pmtaRelayIps */
    private function activatePmtaMonitorConfiguration(
        DmsInstallerRemoteCommandTransport $commands,
        DmsInstallerRemoteFileTransport $files,
        ?string $tlsDomain,
        int $version,
        int $publicPort,
        array $developmentIps,
        array $productionIps,
        bool $allowAllMonitor,
        array $pmtaRelayIps,
    ): void {
        $backendPort = $publicPort * 2;
        $configuration = $this->pmtaPlanner->monitorNginxConfiguration(
            $version,
            $backendPort,
            $publicPort,
            $developmentIps,
            $productionIps,
            $allowAllMonitor,
            $pmtaRelayIps,
            $tlsDomain,
        );
        $stagedPath = '/etc/nginx/conf.d/default.conf.alphalink-tls-stage';
        $activePath = '/etc/nginx/conf.d/default.conf';
        $backupPath = '/etc/nginx/conf.d/default.conf.alphalink-http-backup';

        if (! $files->upload($configuration, $stagedPath)) {
            throw new \RuntimeException('The PMTA monitor proxy configuration could not be staged on the server.');
        }
        $certificateCheck = '';
        if ($tlsDomain !== null) {
            $certificate = '/etc/letsencrypt/live/'.$tlsDomain.'/fullchain.pem';
            $privateKey = '/etc/letsencrypt/live/'.$tlsDomain.'/privkey.pem';
            $certificateCheck = 'test -s '.escapeshellarg($certificate).' && test -s '.escapeshellarg($privateKey).' && ';
            $commands->exec(
                "install -d -m 0755 /etc/letsencrypt/renewal-hooks/deploy && printf '%s\\n' '#!/bin/sh' 'systemctl reload nginx' > /etc/letsencrypt/renewal-hooks/deploy/alphalink-reload-nginx && chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/alphalink-reload-nginx",
            );
        }
        $result = $commands->exec(
            $certificateCheck.
            'cp -p '.escapeshellarg($activePath).' '.escapeshellarg($backupPath).
            ' && mv '.escapeshellarg($stagedPath).' '.escapeshellarg($activePath).
            ' && if nginx -t >/dev/null 2>&1 && systemctl reload nginx; then rm -f '.escapeshellarg($backupPath).
            '; else mv '.escapeshellarg($backupPath).' '.escapeshellarg($activePath).
            '; nginx -t >/dev/null 2>&1 && systemctl reload nginx; exit 1; fi && printf ALPHALINK_PMTA_PROXY_READY',
        );
        if ($result !== 'ALPHALINK_PMTA_PROXY_READY') {
            throw new \RuntimeException('The PMTA monitor proxy configuration could not be validated and activated.');
        }
    }
}
