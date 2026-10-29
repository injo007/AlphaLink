<?php

namespace App\Services\Infrastructure;

use InvalidArgumentException;

/**
 * Reconstructs the DMS CentOS 9 PMTA deployment operations as data.
 * Nothing in this planner connects to a host or uploads/executes an asset.
 */
class DmsCentos9PmtaDeploymentPlanner
{
    /**
     * @param  list<string>  $developmentIps
     * @param  list<string>  $productionIps
     * @return array{version: int, version_setup_skipped: bool, operations: list<array<string, string>>}
     */
    public function plan(
        int $version,
        bool $rpmExists,
        bool $patchExists,
        int $pmtaPort,
        int $adminPort,
        array $developmentIps,
        array $productionIps,
        bool $allowAllMonitor,
        string $pmtaSource,
        array $pmtaRelayIps = [],
        bool $directMonitor = false,
    ): array {
        if (! in_array($version, [40, 45, 50], true)) {
            throw new InvalidArgumentException('DMS CentOS 9 PMTA planner supports versions 40, 45, and 50.');
        }

        if ($pmtaPort < 1 || $pmtaPort > 65535 || $adminPort < 1 || $adminPort > 32767) {
            throw new InvalidArgumentException('PMTA planner ports must be valid TCP ports, and the admin port must leave room for its doubled backend port.');
        }
        foreach ($pmtaRelayIps as $ip) {
            if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new InvalidArgumentException('PMTA relay source IPs must be valid IPv4 addresses.');
            }
        }

        $versionSkipped = ! $rpmExists && ! $patchExists;
        $operations = [
            $this->command('rpm -e PowerMTA'),
            $this->command('rm -rf /etc/pmta; rm -rf /var/lib/pmta; rm -rf /var/log/pmta'),
            $this->command('dnf install -y initscripts'),
            $this->command('kill -9 $(lsof -t -i:25)'),
        ];

        if (! $versionSkipped) {
            array_push($operations, ...$this->versionOperations(
                $version,
                $pmtaPort,
                $adminPort,
                $developmentIps,
                $productionIps,
                $allowAllMonitor,
                $pmtaSource,
                $pmtaRelayIps,
                $directMonitor,
            ));
        }

        array_push($operations,
            $this->command('systemctl enable pmta;'),
            $this->command('systemctl enable pmtahttp;'),
            $this->command('systemctl restart pmta;'),
            $this->command('systemctl restart pmtahttp;'),
        );

        return [
            'version' => $version,
            'version_setup_skipped' => $versionSkipped,
            'operations' => $operations,
        ];
    }

    /** @return list<array<string, string>> */
    private function versionOperations(
        int $version,
        int $pmtaPort,
        int $adminPort,
        array $developmentIps,
        array $productionIps,
        bool $allowAllMonitor,
        string $pmtaSource,
        array $pmtaRelayIps,
        bool $directMonitor,
    ): array {
        $versionDirectory = "install/pmta_{$version}";
        $patchName = $version === 40 ? 'pmtahttp_patch' : 'pmtad_patch';
        $patchTarget = $version === 40 ? '/usr/sbin/pmtahttpd' : '/usr/sbin/pmtad';
        // A verified provider domain uses an Nginx TLS proxy on the configured
        // port. Without one, PMTA listens directly on that port over HTTP.
        $httpPort = $directMonitor ? $adminPort : $adminPort * 2;
        $operations = [];

        if ($version === 40) {
            $operations[] = $this->command('dnf remove -y nginx;');
            $operations[] = $this->command('rm -rf /etc/nginx;');
        }

        $operations[] = $this->asset("{$versionDirectory}/pmta.rpm", '/root/pmta.rpm');
        $operations[] = $this->command('dnf install -y /root/pmta.rpm;');
        $operations[] = $this->command('rm -rf /root/pmta.rpm; rm -rf /etc/pmta/license-notice; rm -rf /etc/pmta/config-defaults;');

        foreach ([
            'mkdir -p /etc/pmta/conf; mkdir -p /etc/pmta/settings; mkdir -p /etc/pmta/domainKeys;',
            'mkdir -p /var/spool/app/pickup; mkdir -p /var/spool/app/bad;',
            'mkdir -p /etc/pmta/delivered; mkdir -p /etc/pmta/delivered/treated;',
            'mkdir -p /etc/pmta/bounced; mkdir -p /etc/pmta/bounced/treated;',
            'mkdir -p /etc/pmta/inbound;',
            'mkdir -p /var/spool/pmta; mkdir -p /var/log/pmta;',
        ] as $command) {
            $operations[] = $this->command($command);
        }

        $access = $this->accessContent(
            $version,
            $developmentIps,
            $productionIps,
            $allowAllMonitor,
            $pmtaSource,
            $pmtaRelayIps,
            $directMonitor,
        );
        $operations[] = $this->command("echo -e '{$access['allowed']}' > /etc/pmta/settings/access;");
        $operations[] = $this->command("echo -e '{$access['source']}' >> /etc/pmta/settings/access;");
        $operations[] = $this->asset("{$versionDirectory}/pmta_domains", '/etc/pmta/conf/domains');
        $operations[] = $this->asset('install/pmta_config', '/etc/pmta/settings/config');
        $operations[] = $this->command("sed -i 's/__PMTA_LISTENER_PORT__/{$pmtaPort}/g' /etc/pmta/settings/config;");
        $operations[] = $this->command("sed -i 's/__PMTA_ADMIN_PORT__/{$httpPort}/g' /etc/pmta/settings/config;");

        if ($version === 50) {
            $operations[] = $this->command("echo 'http-redirect-to-https false' >> /etc/pmta/settings/config");
            $operations[] = $this->command("echo 'http-csrf-protection false' >> /etc/pmta/settings/config");
        }

        foreach ([
            'chown -R pmta:pmta /var/log/pmta; chown -R pmta:pmta /var/spool/app; chown -R pmta:pmta /etc/pmta;',
            'chmod 640 /etc/pmta/config; chmod 755 /var/spool/app;',
            'echo -e "include /etc/pmta/conf/*" > /etc/pmta/config',
            'echo -e "include /etc/pmta/settings/*" >> /etc/pmta/config',
            "rm -rf {$patchTarget}",
        ] as $command) {
            $operations[] = $this->command($command);
        }

        $operations[] = $this->asset("{$versionDirectory}/{$patchName}", $patchTarget);
        $operations[] = $this->command("chown pmta:pmta {$patchTarget};");
        $operations[] = $this->command("chmod 755 {$patchTarget}");
        $operations[] = $this->command('rm -rf /etc/pmta/license');
        $operations[] = $this->asset("{$versionDirectory}/pmta.lic", '/etc/pmta/license');

        if ($directMonitor) {
            $operations[] = $this->command('systemctl disable --now nginx 2>/dev/null || true;');
            $operations[] = $this->command('rm -rf /etc/nginx/conf.d/*; rm -rf /etc/nginx/default.d/*;');
        } else {
            array_push($operations, ...$this->reverseProxyOperations(
                $version,
                $httpPort,
                $adminPort,
                $developmentIps,
                $productionIps,
                $allowAllMonitor,
                $pmtaRelayIps,
            ));
        }

        return $operations;
    }

    /**
     * @param  list<string>  $developmentIps
     * @param  list<string>  $productionIps
     * @return array{allowed: string, source: string}
     */
    private function accessContent(
        int $version,
        array $developmentIps,
        array $productionIps,
        bool $allowAllMonitor,
        string $pmtaSource,
        array $pmtaRelayIps,
        bool $directMonitor,
    ): array {
        $allowed = '';
        $source = '';

        // With an Nginx proxy, PMTA trusts only its local proxy. IP fallback
        // applies the same allowlists directly in PMTA's access policy.
        $allowed .= "http-access 127.0.0.1 admin\n";

        foreach (array_values(array_unique([...$developmentIps, ...$pmtaRelayIps])) as $ip) {
            $source .= str_replace('__IP__', $ip, $pmtaSource);
        }

        // Public monitor access is controlled by Nginx. The backend listener
        // is firewalled from the Internet and only trusts the local proxy.
        if ($directMonitor) {
            foreach (array_values(array_unique($developmentIps)) as $ip) {
                $allowed .= "http-access {$ip} admin\n";
            }
            foreach (array_values(array_unique($pmtaRelayIps)) as $ip) {
                $allowed .= "http-access {$ip} admin\n";
            }
            foreach (array_values(array_unique($productionIps)) as $ip) {
                $allowed .= "http-access {$ip} monitor\n";
            }
            if ($allowAllMonitor) {
                $allowed .= "http-access 0/0 monitor\n";
            }
        }

        return ['allowed' => $allowed, 'source' => $source];
    }

    /**
     * @param  list<string>  $developmentIps
     * @param  list<string>  $productionIps
     * @param  list<string>  $pmtaRelayIps
     * @return list<array<string, string>>
     */
    private function reverseProxyOperations(
        int $version,
        int $httpPort,
        int $adminPort,
        array $developmentIps,
        array $productionIps,
        bool $allowAllMonitor,
        array $pmtaRelayIps,
    ): array {
        $operations = [$this->command('dnf install -y nginx')];
        $settings = $this->monitorNginxConfiguration(
            $version,
            $httpPort,
            $adminPort,
            $developmentIps,
            $productionIps,
            $allowAllMonitor,
            $pmtaRelayIps,
        );

        array_push($operations,
            $this->command('rm -rf /etc/nginx/conf.d/*'),
            $this->command('rm -rf /etc/nginx/default.d/*'),
            $this->remoteContent('/etc/nginx/conf.d/default.conf', $settings),
            $this->command('rm -rf /etc/nginx/nginx.conf'),
            $this->asset('install/nginx_config', '/etc/nginx/nginx.conf'),
            $this->command('systemctl enable nginx'),
            $this->command('systemctl restart nginx'),
        );

        return $operations;
    }

    /**
     * Render the sole public PMTA monitor endpoint. Supplying a domain enables
     * TLS after Certbot has provisioned that domain's certificate.
     *
     * @param  list<string>  $developmentIps
     * @param  list<string>  $productionIps
     * @param  list<string>  $pmtaRelayIps
     */
    public function monitorNginxConfiguration(
        int $version,
        int $httpPort,
        int $adminPort,
        array $developmentIps,
        array $productionIps,
        bool $allowAllMonitor,
        array $pmtaRelayIps,
        ?string $tlsDomain = null,
    ): string {
        if (! in_array($version, [40, 45, 50], true) || $httpPort < 1 || $httpPort > 65535 || $adminPort < 1 || $adminPort > 65535) {
            throw new InvalidArgumentException('PMTA monitor proxy inputs are invalid.');
        }
        if ($tlsDomain !== null && (filter_var($tlsDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || filter_var($tlsDomain, FILTER_VALIDATE_IP) !== false)) {
            throw new InvalidArgumentException('The PMTA HTTPS domain must be a valid hostname.');
        }

        $allowed = $allowAllMonitor
            ? "# ALPHALINK_PMTA_MONITOR_ACCESS_PUBLIC\n"
            : "# ALPHALINK_PMTA_MONITOR_ACCESS_RESTRICTED\n";
        if (! $allowAllMonitor) {
            // AlphaLink's egress also needs the monitor endpoint for its
            // authenticated, read-only PMTA panel proxy.
            // The installer also verifies the local HTTP proxy before it
            // persists the server, so loopback must pass this outer ACL.
            $allowed .= "allow 127.0.0.1;\n";
            foreach (array_values(array_unique([...$developmentIps, ...$productionIps, ...$pmtaRelayIps])) as $ip) {
                $allowed .= "allow {$ip};\n";
            }
            $allowed .= "deny all;\n";
        }

        $adminAllowed = '';
        foreach (array_values(array_unique($pmtaRelayIps)) as $ip) {
            $adminAllowed .= "    allow {$ip};\n";
        }
        $adminAllowed .= "    deny all;\n";

        $location = $version !== 50
            ? "location / {\n    proxy_pass http://127.0.0.1:{$httpPort};\n    proxy_hide_header 'X-Frame-Options';\n    proxy_hide_header 'X-Content-Type-Options';\n}\nlocation = /command {\n{$adminAllowed}    proxy_pass http://127.0.0.1:{$httpPort};\n    proxy_hide_header 'X-Frame-Options';\n    proxy_hide_header 'X-Content-Type-Options';\n}\nlocation ~* ^/(logs|getFile|listqueue|command|editConfig|enterLak|serviceControl) {\n    return 302 /;\n}"
            : "location / {\n    proxy_pass http://127.0.0.1:{$httpPort};\n    proxy_hide_header 'X-Frame-Options';\n    proxy_hide_header 'X-Content-Type-Options';\n    proxy_hide_header 'Content-Security-Policy';\n    proxy_hide_header 'X-Content-Security-Policy';\n    proxy_hide_header 'X-Content-Type-Option';\n    proxy_hide_header 'X-WebKit-CSP';\n    proxy_hide_header 'X-XSS-Protection';\n}\nlocation = /command {\n{$adminAllowed}    proxy_pass http://127.0.0.1:{$httpPort};\n    proxy_hide_header 'X-Frame-Options';\n    proxy_hide_header 'X-Content-Type-Options';\n    proxy_hide_header 'Content-Security-Policy';\n    proxy_hide_header 'X-Content-Security-Policy';\n    proxy_hide_header 'X-Content-Type-Option';\n    proxy_hide_header 'X-WebKit-CSP';\n    proxy_hide_header 'X-XSS-Protection';\n}\nlocation ~* ^/(logs|getFile|listqueue|command|configFile|editConfig|enterLak|serviceControl) {\n    return 200 \"{}\";\n}";

        $listen = $tlsDomain === null ? "listen {$adminPort};" : "listen {$adminPort} ssl;";
        $serverName = $tlsDomain === null ? '_' : $tlsDomain;
        $tls = $tlsDomain === null ? '' : "\n    ssl_certificate /etc/letsencrypt/live/{$tlsDomain}/fullchain.pem;\n    ssl_certificate_key /etc/letsencrypt/live/{$tlsDomain}/privkey.pem;\n    ssl_protocols TLSv1.2 TLSv1.3;";

        return "server {\n    {$listen}\n    server_name {$serverName};{$tls}\n    {$allowed}\n    {$location}\n    error_page 403 @error403;\n    location @error403 {\n        default_type text/html;\n        return 403 \"403\";\n    }\n    error_page 502 @error502;\n    location @error502 {\n        default_type text/html;\n        return 502 \"GONE :(\";\n    }\n}";
    }

    /** @return array{type: string, command: string} */
    private function command(string $command): array
    {
        return ['type' => 'command', 'command' => $command];
    }

    /** @return array{type: string, source: string, destination: string} */
    private function asset(string $source, string $destination): array
    {
        return ['type' => 'asset_upload', 'source' => $source, 'destination' => $destination];
    }

    /** @return array{type: string, destination: string, content: string} */
    private function remoteContent(string $destination, string $content): array
    {
        return ['type' => 'remote_content', 'destination' => $destination, 'content' => $content];
    }
}
