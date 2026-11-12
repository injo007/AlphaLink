<?php

namespace App\Services\Infrastructure;

use InvalidArgumentException;

/** Provisions the domain certificate and PMTA HTTPS monitor through a command transport. */
class AlphaLinkCentos9HttpsDeploymentStage
{
    public function __construct(
        private readonly AlphaLinkCentos9PmtaDeploymentPlanner $pmtaPlanner = new AlphaLinkCentos9PmtaDeploymentPlanner,
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
        AlphaLinkInstallerRemoteCommandTransport $commands,
        ?string $domain,
        array $subdomains = [],
    ): bool {
        if (! $domain) {
            return false;
        }

        if (filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException('The certificate domain must be a valid DNS hostname.');
        }
        foreach ($subdomains as $subdomain) {
            if (filter_var($subdomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                throw new InvalidArgumentException('Certificate aliases must be valid DNS hostnames.');
            }
        }

        // PMTA TLS is required independently of the optional tracking app.
        // Install mod_ssl as well so the Apache challenge site can serve the
        // certificate on port 443, independently of Nginx's PMTA monitor port.
        $apacheTlsReady = $commands->exec(
            '(rpm -q httpd >/dev/null 2>&1 || dnf -y install httpd >/dev/null 2>&1) && '
                .'(rpm -q mod_ssl >/dev/null 2>&1 || dnf -y install mod_ssl >/dev/null 2>&1) && '
                .'systemctl enable --now httpd && httpd -t >/dev/null 2>&1 && '
                .'printf ALPHALINK_APACHE_HTTPS_READY',
        );
        if (trim((string) $apacheTlsReady) !== 'ALPHALINK_APACHE_HTTPS_READY') {
            return false;
        }
        $certbotReady = $commands->exec(
            '(rpm -q certbot >/dev/null 2>&1 || dnf -y install certbot >/dev/null 2>&1) && '
                .'(rpm -q python3-certbot-apache >/dev/null 2>&1 || dnf -y install python3-certbot-apache >/dev/null 2>&1) && '
                .'printf ALPHALINK_CERTBOT_READY',
        );
        if (trim((string) $certbotReady) !== 'ALPHALINK_CERTBOT_READY') {
            return false;
        }

        $certificateDomains = implode(',', [$domain, ...$subdomains]);
        $email = 'cert@'.$domain;
        $certificateRequest = $commands->exec(
            'certbot --apache --no-redirect -d '.escapeshellarg($certificateDomains).
            ' -m '.escapeshellarg($email).' -n --agree-tos >/dev/null 2>&1 && printf ALPHALINK_CERTIFICATE_REQUESTED',
        );
        if (trim((string) $certificateRequest) !== 'ALPHALINK_CERTIFICATE_REQUESTED') {
            return false;
        }
        $certificateReady = $commands->exec(
            'test -s '.escapeshellarg('/etc/letsencrypt/live/'.$domain.'/fullchain.pem')
                .' && test -s '.escapeshellarg('/etc/letsencrypt/live/'.$domain.'/privkey.pem')
                .' && printf ALPHALINK_DOMAIN_CERTIFICATE_READY',
        );
        $commands->exec("(crontab -l | grep 'certbot') || { crontab -l; echo '0 0 */15 * * certbot renew'; } | crontab -");

        // Certbot names the Apache SSL vhost after the certificate's first
        // hostname (including its dots), e.g. example.com-le-ssl.conf.
        $trackingVhost = '/etc/httpd/conf.d/'.$domain.'-le-ssl.conf';
        $trackingRewriteScript = '/<VirtualHost '.chr(92).'*:443>/a'.chr(92)."\n"
            .'    # ALPHALINK_TRACKING_ROUTES'.chr(92)."\n"
            .'    RewriteEngine On'.chr(92)."\n"
            .'    RewriteRule ^/healthz$ /index.php [END,QSA]'.chr(92)."\n"
            .'    RewriteRule ^/([A-Za-z0-9]{10}[roubROUB][A-Za-z0-9_-]{1,4096}(?:/[A-Za-z0-9_-]+)*)$ /index.php?__alphalink_long_uri=$1 [END,QSA,B]';
        $trackingRewrite = 'if test -f /var/mini/public/index.php && grep -q ALPHALINK_TRACKING_READY /var/mini/public/index.php && test -f '.escapeshellarg($trackingVhost).'; then '
            ."if ! grep -Fq 'ALPHALINK_TRACKING_ROUTES' ".escapeshellarg($trackingVhost).'; then '
            .'sed -i '.escapeshellarg($trackingRewriteScript).' '.escapeshellarg($trackingVhost).'; fi; '
            .'httpd -t && systemctl reload httpd; fi';
        $commands->exec($trackingRewrite);

        return trim((string) $certificateReady) === 'ALPHALINK_DOMAIN_CERTIFICATE_READY';
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
        AlphaLinkInstallerRemoteCommandTransport $commands,
        AlphaLinkInstallerRemoteFileTransport $files,
        ?string $tlsDomain,
        int $version,
        int $publicPort,
        array $developmentIps,
        array $productionIps,
        bool $allowAllMonitor,
        array $pmtaRelayIps,
        bool $directMonitor = false,
    ): bool {
        if ($directMonitor) {
            return false;
        }

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
        AlphaLinkInstallerRemoteCommandTransport $commands,
        AlphaLinkInstallerRemoteFileTransport $files,
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
