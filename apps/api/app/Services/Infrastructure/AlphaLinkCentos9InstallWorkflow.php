<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\Workspace;
use App\Services\Campaigns\PmtaPanelUrl;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/** Composes the source-ordered AlmaLinux 9 phases used by the registered, gated row runner. */
class AlphaLinkCentos9InstallWorkflow
{
    public function __construct(
        private readonly AlphaLinkPmtaDeploymentGate $gate,
        private readonly AlphaLinkInstallPlatform $platform,
        private readonly AlphaLinkBulkInstallIpPlanner $ipPlanner,
        private readonly AlphaLinkCentos9HostPreparation $hostPreparation,
        private readonly AlphaLinkCentos9ServiceSetup $serviceSetup,
        private readonly AlphaLinkCentos9PhpInstallationStage $phpInstallation,
        private readonly AlphaLinkCentos9TrackingDeploymentStage $tracking,
        private readonly AlphaLinkBulkInstallDomainRecordStage $domainRecords,
        private readonly AlphaLinkCentos9PmtaDeploymentStage $pmta,
        private readonly AlphaLinkCentos9VmtaDeploymentStage $vmtas,
        private readonly AlphaLinkCentos9ImapDeploymentStage $imap,
        private readonly AlphaLinkCentos9ProxyDeploymentStage $proxy,
        private readonly AlphaLinkCentos9HttpsDeploymentStage $https,
        private readonly AlphaLinkCentos9FirewallDeploymentStage $firewall,
        private readonly AlphaLinkBulkInstallCompletionWriter $completion,
    ) {}

    /**
     * @param  list<string>  $discoveredIps
     * @param  list<string>  $isps
     * @param  array{tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool}  $options
     * @param  array{pmta_port: int, pmta_admin_port: int, proxy_port: int, dev_ips: list<string>, pmta_relay_ips: list<string>, production_ips: list<string>, rdp_ips: list<string>, allow_all_monitor: bool, apply_firewall: bool, dkim_selector: string, dkim_key: string, dkim_public_key: ?string, imap_username: string, imap_password: string, proxy_username: string, proxy_password: string, tracking_callback_url: string, tracking_ingest_key: string}  $settings
     * @param  array{tracking_app: ?array{index: string, handler: string, homepage: string}, cloudflare_ips: ?string, dovecot_config: ?string, squid_config: ?string, pmta_domains_override?: ?string}  $assets
     * @param  Closure(string): void  $log
     * @param  list<array{ip: string, type: string, main: bool, sub: string, domain_id: string, domain: string, is_our: bool}>|null  $ipAssignments
     * @param  list<SendingDomain>  $selectedDomains
     */
    public function run(
        Workspace $workspace,
        ManagedServer $server,
        SendingDomain $domain,
        array $discoveredIps,
        array $isps,
        int $pmtaVersion,
        array $options,
        array $settings,
        array $assets,
        AlphaLinkInstallerRemoteCommandTransport&AlphaLinkInstallerRemoteFileTransport&AlphaLinkInstallerRemoteReadableFileTransport $session,
        AlphaLinkBulkInstallDomainDnsRecordWriter $dnsWriter,
        Closure $log,
        ?array $ipAssignments = null,
        array $selectedDomains = [],
    ): void {
        $this->validateInputs($options, $settings, $assets);

        $selectedDomains = $selectedDomains !== [] ? $selectedDomains : [$domain];
        if ((string) $server->workspace_id !== (string) $workspace->getKey()) {
            throw new InvalidArgumentException('The server and domain must belong to the installation workspace.');
        }
        $domainsById = [];
        foreach ($selectedDomains as $selectedDomain) {
            if ((string) $selectedDomain->workspace_id !== (string) $workspace->getKey()) {
                throw new InvalidArgumentException('The server and domain must belong to the installation workspace.');
            }
            if ($ipAssignments !== null
                && (! in_array($selectedDomain->status, ['free', 'new'], true) || ! $selectedDomain->is_active)) {
                throw new RuntimeException('One of the selected domains is no longer eligible for installation.');
            }
            $domainsById[(string) $selectedDomain->getKey()] = $selectedDomain;
        }
        if ($ipAssignments === null && $domain->status === 'used') {
            throw new RuntimeException("Sorry this Domain: {$domain->name} is already used");
        }
        if ($server->is_installed) {
            throw new RuntimeException('Server is already installed');
        }
        if ($discoveredIps === []) {
            throw new RuntimeException('Found 0 Ips Please Check Server Configuration Aborting');
        }

        $hostFacts = is_array($server->host_facts) ? $server->host_facts : [];
        if ($this->platform->installerFamily((string) ($hostFacts['os_pretty_name'] ?? '')) !== 'centos9') {
            throw new InvalidArgumentException('The AlmaLinux 9 workflow requires a supported CentOS 9-family host.');
        }
        $readiness = $this->gate->evaluate($hostFacts, $pmtaVersion, $options);
        if (! $readiness['installation_authorized']) {
            throw new RuntimeException('PMTA deployment prerequisites are not verified.');
        }

        if ($ipAssignments === null) {
            $ips = $this->ipPlanner->format(
                (string) $server->ip_address,
                (string) $domain->getKey(),
                (string) $domain->name,
                $discoveredIps,
            );
            $primaryDomain = $domain;
        } else {
            if ($ipAssignments === []) {
                throw new InvalidArgumentException('At least one IP assignment is required for single-server installation.');
            }
            $ips = [];
            foreach ($ipAssignments as $assignment) {
                $assignmentDomain = $domainsById[$assignment['domain_id']] ?? null;
                if (! $assignmentDomain instanceof SendingDomain) {
                    throw new InvalidArgumentException('An IP assignment references a domain outside the prepared installation.');
                }
                $ips[] = [
                    ...$assignment,
                    'domain' => (string) $assignmentDomain->name,
                    'is_our' => (bool) $assignmentDomain->is_our,
                ];
            }
            $primaryAssignment = collect($ips)->firstWhere('main', true) ?? $ips[0];
            $primaryDomain = $domainsById[$primaryAssignment['domain_id']];
        }

        $domainProviderReady = [];
        foreach ($domainsById as $domainId => $selectedDomain) {
            $domainProviderReady[$domainId] = $dnsWriter->isDomainAvailable($selectedDomain);
        }
        $primaryDomainId = (string) $primaryDomain->getKey();
        $primaryDomainReady = $domainProviderReady[$primaryDomainId] ?? false;
        $deploymentDomain = $primaryDomainReady ? (string) $primaryDomain->name : null;

        $readySubdomains = static function (array $ipsToInclude) use ($domain, $domainProviderReady, $primaryDomain): array {
            $list = [];
            foreach ($ipsToInclude as $ip) {
                $entryDomainId = (string) ($ip['domain_id'] ?? $domain->getKey());
                if (! ($domainProviderReady[$entryDomainId] ?? false)) {
                    continue;
                }
                if (! ($ip['is_our'] ?? (bool) $domain->is_our)) {
                    continue;
                }
                if ($ip['domain'] !== (string) $primaryDomain->name) {
                    $list[] = $ip['domain'];
                }
                if ($ip['sub'] !== '' || $ip['domain'] !== (string) $primaryDomain->name) {
                    $list[] = 'www.'.$ip['domain'];
                }
                if ($ip['sub'] !== '') {
                    $list[] = $ip['sub'].'.'.$ip['domain'];
                }
            }

            return array_values(array_unique($list));
        };

        $subdomains = $readySubdomains($ips);
        $deploymentSubdomains = $primaryDomainReady ? $subdomains : [];
        if (! $primaryDomainReady) {
            $log(sprintf(
                'The selected domain %s is not available from its configured DNS provider; skipping domain-based host naming and using public IP %s for tracking and PMTA monitor endpoints. DNS record updates will be skipped if the provider remains unavailable.',
                $primaryDomain->name,
                $server->ip_address,
            ));
        }

        $dnsIps = $this->domainRecords->resolvePublicAddresses(
            $ips,
            new AlphaLinkCentos9PublicIpResolver($session),
            $log,
            (string) $server->ip_address,
        );

        if ($options['tracking'] && $assets['tracking_app'] !== null) {
            $this->tracking->verifyCallback($session, $settings['tracking_callback_url']);
        }

        $log('Setup Host');
        $configuredHostname = $this->hostPreparation->configureHost($session, (string) $server->ip_address, $deploymentDomain);
        if (! $primaryDomainReady) {
            $log(sprintf(
                'No DNS-managed domain is available; configured PMTA host identity %s while keeping tracking and monitor endpoints on public IP %s.',
                $configuredHostname,
                $server->ip_address,
            ));
        }
        $log('End Setting Host');

        $log('Removing Old Packages');
        $this->hostPreparation->removeOldPackages($session);
        $log('End Removing Old Packages');

        $log('Setup Services');
        $this->serviceSetup->run($session);
        $log('End Setting Services');

        if ($options['tracking']) {
            $log('Setup PHP');
            $this->phpInstallation->run($session);
            $log('End Setting PHP');

            $log('Setup Tracking App');
            $this->tracking->run(
                $session, $session, (string) $server->ip_address, (string) $server->getKey(),
                $deploymentDomain, $deploymentSubdomains, $assets['tracking_app'], $assets['cloudflare_ips'],
                $settings['tracking_callback_url'], $settings['tracking_ingest_key'],
                $options['https'] && $primaryDomainReady,
                (string) config('app.name', 'NA'),
            );
            $log('End Setting Tracking App');
        }

        foreach ($this->groupIpsByDomain($dnsIps, $domainsById) as $domainGroup) {
            $dnsReady = $this->domainRecords->run(
                $domainGroup['domain'], $domainGroup['ips'], $options,
                $settings['dkim_selector'], $settings['dkim_public_key'],
                new AlphaLinkCentos9PublicIpResolver($session), $dnsWriter, $log,
                continueOnDnsFailure: true,
            );
            if ($dnsReady !== null) {
                // The write is authoritative: a transient provider preflight
                // failure must not force a successful installation onto IP-only
                // PMTA monitor URLs.
                $domainProviderReady[(string) $domainGroup['domain']->getKey()] = $dnsReady;
            }
        }

        $primaryDomainReady = $domainProviderReady[$primaryDomainId] ?? false;
        $monitorDomain = $primaryDomainReady ? (string) $primaryDomain->name : null;
        $subdomains = $readySubdomains($ips);
        if (! $primaryDomainReady && $deploymentDomain !== null) {
            $log(sprintf(
                'DNS provider setup did not complete for %s; switching PMTA monitor access to public IP %s.',
                $primaryDomain->name,
                $server->ip_address,
            ));
        }
        // PMTA 4.5/5.0 use the normal loopback backend behind Nginx even when
        // no provider-owned domain exists. Only PMTA 4.0 uses its direct HTTP
        // listener as the public-IP fallback.
        $directPmtaMonitor = AlphaLinkPmtaMonitorMode::usesDirectListener($pmtaVersion, $primaryDomainReady);

        $log('Setup PMTA '.$pmtaVersion);
        $this->pmta->run(
            $session, $session, $hostFacts, $pmtaVersion,
            $settings['pmta_port'], $settings['pmta_admin_port'],
            $settings['dev_ips'], $settings['production_ips'],
            $settings['allow_all_monitor'], $options,
            $assets['pmta_domains_override'] ?? null,
            $settings['pmta_relay_ips'],
            $directPmtaMonitor,
        );
        $log('End Setting PMTA');

        $log('Setup VMTAS');
        $this->vmtas->run(
            $session, $session, (bool) $primaryDomain->is_our, $ips, $isps,
            $settings['dkim_selector'], $options['dkim'], $settings['dkim_key'],
        );
        $log('End Setting VMTAS');

        if ($options['imap']) {
            $log('Setup IMAP');
            $this->imap->run($session, $session, $assets['dovecot_config'], $settings['imap_username'], $settings['imap_password']);
            $log('End Setting IMAP');
        }

        if ($options['proxy']) {
            $log('Setup Proxy');
            $this->proxy->run(
                $session, $session, $assets['squid_config'], $settings['proxy_port'],
                $discoveredIps, $settings['dev_ips'], $settings['production_ips'],
                $settings['proxy_username'], $settings['proxy_password'],
            );
            $log('End Setting Proxy');
        }

        $log($options['https'] && $primaryDomainReady ? 'Setup Https' : 'Setup HTTPS for PMTA Web Monitor');
        if ($monitorDomain !== null && ! $this->firewall->prepareDomainCertificatePorts($session)) {
            $log('The host firewall could not confirm HTTP/HTTPS access before domain certificate issuance; the PMTA monitor may fall back to the AlphaLink HTTPS proxy.');
        }
        $domainCertificateReady = $this->https->run($session, $monitorDomain, $primaryDomainReady ? $subdomains : []);
        if ($monitorDomain !== null && ! $domainCertificateReady) {
            $log('The selected domain certificate was not issued; check public DNS and inbound HTTP port 80 before retrying.');
        }
        $pmtaMonitorHttps = $this->https->configurePmtaMonitor(
            $session,
            $session,
            $monitorDomain,
            $pmtaVersion,
            $settings['pmta_admin_port'],
            $settings['dev_ips'],
            $settings['production_ips'],
            $settings['allow_all_monitor'],
            $settings['pmta_relay_ips'],
            $directPmtaMonitor,
        );
        if (! $pmtaMonitorHttps) {
            $log(sprintf(
                'PMTA monitor HTTPS is unavailable; using the public IP HTTP endpoint http://%s:%d%s.',
                $server->ip_address,
                $settings['pmta_admin_port'],
                $directPmtaMonitor ? ' directly from PMTA' : ' through Nginx',
            ));
        }
        $log('End Setting HTTPS for PMTA Web Monitor');

        $log('Config Firewall');
        $this->firewall->run(
            $session, $settings['apply_firewall'], (int) $server->ssh_port,
            $settings['pmta_port'], $settings['pmta_admin_port'], $settings['proxy_port'],
            $settings['dev_ips'], $settings['production_ips'], $settings['rdp_ips'], $settings['allow_all_monitor'],
            $settings['pmta_relay_ips'],
        );
        $log('End Setting Firewall');

        $trackingHttpsReady = false;
        if ($options['tracking'] && $options['https'] && $primaryDomainReady && $domainCertificateReady) {
            $trackingHttpsReady = $this->tracking->verifyHttps($session, $monitorDomain);
            if (! $trackingHttpsReady) {
                $log('Tracking HTTPS did not pass its public health and redirect checks; campaign tracking links will use the verified HTTP endpoint.');
            }
        }

        // AlphaLink starts both services but does not inspect their final state. Do not
        // persist an installed server when the selected PMTA package or either
        // service failed after the VMTA configuration restart.
        $health = $session->exec($this->pmtaReadinessCommand(
            $pmtaVersion,
            $settings,
            $pmtaMonitorHttps,
            $monitorDomain,
            $directPmtaMonitor,
        ));
        if ($health !== 'ALPHALINK_PMTA_READY') {
            $diagnostics = AlphaLinkPmtaPostInstallReadiness::failureDetails($health);
            throw new RuntimeException('PMTA post-install verification failed: '.$diagnostics.'.');
        }

        if ($ipAssignments === null) {
            $completedOptions = [...$options, 'https' => $options['https'] && $primaryDomainReady && $domainCertificateReady];
            $this->completion->finalize($workspace, $server, $primaryDomain, $ips, $pmtaVersion, $completedOptions, [], $pmtaMonitorHttps, $monitorDomain ?? (string) $server->ip_address, $trackingHttpsReady);
        } else {
            $completedOptions = [...$options, 'https' => $options['https'] && $primaryDomainReady && $domainCertificateReady];
            $this->completion->finalize($workspace, $server, $primaryDomain, $ips, $pmtaVersion, $completedOptions, $selectedDomains, $pmtaMonitorHttps, $monitorDomain ?? (string) $server->ip_address, $trackingHttpsReady);
        }
        $publicPort = $settings['pmta_admin_port'];
        $adminUrl = PmtaPanelUrl::displayUrlForInstallation(
            (string) $server->ip_address,
            $monitorDomain,
            $publicPort,
            $pmtaMonitorHttps,
            (string) $server->getKey(),
        );
        $monitorIsHttps = str_starts_with($adminUrl, 'https://');
        $monitorLabel = $monitorIsHttps
            ? (string) parse_url($adminUrl, PHP_URL_HOST).':'.$publicPort
            : 'Open PMTA monitor via AlphaLink HTTPS';
        $monitorDescription = $monitorIsHttps
            ? sprintf('HTTPS; public port %d', $publicPort)
            : sprintf('HTTP monitor through AlphaLink HTTPS proxy; PMTA listener port %d', $publicPort);
        $log(sprintf(
            'PMTA WEB MONITOR LINK (%s): >>> <a target="_blank" rel="noopener noreferrer" href="%s">%s</a>',
            $monitorDescription,
            $adminUrl,
            $monitorLabel,
        ));
        $log('Ended Disconnecting');
    }

    /**
     * @param  list<array<string, mixed>>  $ips
     * @param  array<string, SendingDomain>  $domainsById
     * @return list<array{domain: SendingDomain, ips: list<array<string, mixed>>}>
     */
    private function groupIpsByDomain(array $ips, array $domainsById): array
    {
        $groups = [];
        foreach ($ips as $ip) {
            $domainId = (string) ($ip['domain_id'] ?? '');
            $domain = $domainsById[$domainId] ?? null;
            if (! $domain instanceof SendingDomain) {
                throw new InvalidArgumentException('An IP assignment references a domain outside the prepared installation.');
            }

            $groups[$domainId] ??= ['domain' => $domain, 'ips' => []];
            $groups[$domainId]['ips'][] = $ip;
        }

        return array_values($groups);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $assets
     */
    private function validateInputs(array $options, array $settings, array $assets): void
    {
        foreach (['tracking', 'proxy', 'imap', 'spf', 'dkim', 'dmarc', 'https'] as $option) {
            if (! array_key_exists($option, $options) || ! is_bool($options[$option])) {
                throw new InvalidArgumentException('The installation options are incomplete.');
            }
        }

        foreach (['pmta_port', 'pmta_admin_port', 'proxy_port'] as $port) {
            if (! isset($settings[$port]) || ! is_int($settings[$port]) || $settings[$port] < 1 || $settings[$port] > 65535) {
                throw new InvalidArgumentException('A required installation port is invalid.');
            }
        }

        foreach (['dev_ips', 'pmta_relay_ips', 'production_ips', 'rdp_ips'] as $ipList) {
            if (! isset($settings[$ipList]) || ! is_array($settings[$ipList])) {
                throw new InvalidArgumentException('A required installation IP allowlist is invalid.');
            }

            foreach ($settings[$ipList] as $ip) {
                if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    throw new InvalidArgumentException('A required installation IP allowlist is invalid.');
                }
            }
        }
        if ($settings['dev_ips'] === [] && $settings['production_ips'] === [] && ! $settings['allow_all_monitor']) {
            throw new InvalidArgumentException('Configure an operator or production IPv4 address, or enable the public read-only PMTA monitor.');
        }
        if ($settings['pmta_relay_ips'] === []) {
            throw new InvalidArgumentException('Configure PMTA RELAY SOURCE IPS with AlphaLink\'s stable public IPv4 egress address before installing PMTA.');
        }

        foreach (['allow_all_monitor', 'apply_firewall'] as $flag) {
            if (! isset($settings[$flag]) || ! is_bool($settings[$flag])) {
                throw new InvalidArgumentException('A required installation switch is invalid.');
            }
        }

        foreach (['dkim_selector', 'dkim_key', 'imap_username', 'imap_password', 'proxy_username', 'proxy_password', 'tracking_callback_url', 'tracking_ingest_key'] as $value) {
            if (! isset($settings[$value]) || ! is_string($settings[$value])) {
                throw new InvalidArgumentException('A required installation setting is missing.');
            }
        }

        if (! array_key_exists('dkim_public_key', $settings)
            || (! is_null($settings['dkim_public_key']) && ! is_string($settings['dkim_public_key']))) {
            throw new InvalidArgumentException('The DKIM public key setting is invalid.');
        }

        foreach (['tracking_app', 'cloudflare_ips', 'dovecot_config', 'squid_config'] as $asset) {
            if (! array_key_exists($asset, $assets)
                || (! is_null($assets[$asset]) && $asset !== 'tracking_app' && ! is_string($assets[$asset]))) {
                throw new InvalidArgumentException('A required installation asset input is missing.');
            }
        }
        if ($assets['tracking_app'] !== null
            && (! is_array($assets['tracking_app'])
                || ! is_string($assets['tracking_app']['index'] ?? null)
                || ! is_string($assets['tracking_app']['handler'] ?? null)
                || ! is_string($assets['tracking_app']['homepage'] ?? null))) {
            throw new InvalidArgumentException('The AlphaLink tracking application files are invalid.');
        }
    }

    /** @param array<string, mixed> $settings */
    private function pmtaReadinessCommand(
        int $version,
        array $settings,
        bool $https = false,
        ?string $domain = null,
        bool $directMonitor = false,
    ): string {
        return (new AlphaLinkPmtaPostInstallReadiness)->command($version, $settings, $https, $domain, $directMonitor);
    }
}
