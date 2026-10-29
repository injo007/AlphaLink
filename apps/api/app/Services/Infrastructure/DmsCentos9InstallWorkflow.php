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
class DmsCentos9InstallWorkflow
{
    public function __construct(
        private readonly DmsPmtaDeploymentGate $gate,
        private readonly DmsInstallPlatform $platform,
        private readonly DmsBulkInstallIpPlanner $ipPlanner,
        private readonly DmsCentos9HostPreparation $hostPreparation,
        private readonly DmsCentos9ServiceSetup $serviceSetup,
        private readonly DmsCentos9PhpInstallationStage $phpInstallation,
        private readonly DmsCentos9TrackingDeploymentStage $tracking,
        private readonly DmsBulkInstallDomainRecordStage $domainRecords,
        private readonly DmsCentos9PmtaDeploymentStage $pmta,
        private readonly DmsCentos9VmtaDeploymentStage $vmtas,
        private readonly DmsCentos9ImapDeploymentStage $imap,
        private readonly DmsCentos9ProxyDeploymentStage $proxy,
        private readonly DmsCentos9HttpsDeploymentStage $https,
        private readonly DmsCentos9FirewallDeploymentStage $firewall,
        private readonly DmsBulkInstallCompletionWriter $completion,
    ) {}

    /**
     * @param  list<string>  $discoveredIps
     * @param  list<string>  $isps
     * @param  array{tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool}  $options
     * @param  array{pmta_port: int, pmta_admin_port: int, proxy_port: int, dev_ips: list<string>, pmta_relay_ips: list<string>, production_ips: list<string>, rdp_ips: list<string>, allow_all_monitor: bool, apply_firewall: bool, dkim_selector: string, dkim_key: string, dkim_public_key: ?string, imap_username: string, imap_password: string, proxy_username: string, proxy_password: string, mini_app_url: string, mini_app_key: string}  $settings
     * @param  array{mini_app: ?string, cloudflare_ips: ?string, dovecot_config: ?string, squid_config: ?string, pmta_domains_override?: ?string}  $assets
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
        DmsInstallerRemoteCommandTransport&DmsInstallerRemoteFileTransport&DmsInstallerRemoteReadableFileTransport $session,
        DmsBulkInstallDomainDnsRecordWriter $dnsWriter,
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
            throw new InvalidArgumentException('The AlmaLinux 9 workflow requires a DMS CentOS 9 family host.');
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

        $subdomains = [];
        foreach ($ips as $ip) {
            if (($ip['is_our'] ?? (bool) $domain->is_our) && $ip['sub'] !== '') {
                $subdomains[] = 'www.'.$ip['domain'];
                $subdomains[] = $ip['sub'].'.'.$ip['domain'];
            }
        }
        $subdomains = array_values(array_unique($subdomains));

        $domainProviderReady = [];
        foreach ($domainsById as $domainId => $selectedDomain) {
            $domainProviderReady[$domainId] = $dnsWriter->isDomainAvailable($selectedDomain);
        }
        $primaryDomainId = (string) $primaryDomain->getKey();
        $primaryDomainReady = $domainProviderReady[$primaryDomainId] ?? false;
        $deploymentDomain = $primaryDomainReady ? (string) $primaryDomain->name : null;
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
            new DmsCentos9PublicIpResolver($session),
            $log,
            (string) $server->ip_address,
        );

        if ($options['tracking'] && $assets['mini_app'] !== null) {
            $this->tracking->verifyCallback($session, $settings['mini_app_url']);
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
                $deploymentDomain, $deploymentSubdomains, $assets['mini_app'], $assets['cloudflare_ips'],
                $settings['mini_app_url'], $settings['mini_app_key'],
                $options['https'] && $primaryDomainReady,
            );
            $log('End Setting Tracking App');
        }

        foreach ($this->groupIpsByDomain($dnsIps, $domainsById) as $domainGroup) {
            $dnsReady = $this->domainRecords->run(
                $domainGroup['domain'], $domainGroup['ips'], $options,
                $settings['dkim_selector'], $settings['dkim_public_key'],
                new DmsCentos9PublicIpResolver($session), $dnsWriter, $log,
                continueOnDnsFailure: true,
            );
            if ($dnsReady === false) {
                $domainProviderReady[(string) $domainGroup['domain']->getKey()] = false;
            }
        }

        $primaryDomainReady = $domainProviderReady[$primaryDomainId] ?? false;
        $monitorDomain = $primaryDomainReady ? (string) $primaryDomain->name : null;
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
        $directPmtaMonitor = DmsPmtaMonitorMode::usesDirectListener($pmtaVersion, $primaryDomainReady);

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
        $this->https->run($session, $monitorDomain, $primaryDomainReady ? $subdomains : []);
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

        // DMS starts both services but does not inspect their final state. Do not
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
            $diagnostics = DmsPmtaPostInstallReadiness::failureDetails($health);
            throw new RuntimeException('PMTA post-install verification failed: '.$diagnostics.'.');
        }

        if ($ipAssignments === null) {
            $completedOptions = [...$options, 'https' => $options['https'] && $primaryDomainReady];
            $this->completion->finalize($workspace, $server, $primaryDomain, $ips, $pmtaVersion, $completedOptions, [], $pmtaMonitorHttps);
        } else {
            $completedOptions = [...$options, 'https' => $options['https'] && $primaryDomainReady];
            $this->completion->finalize($workspace, $server, $primaryDomain, $ips, $pmtaVersion, $completedOptions, $selectedDomains, $pmtaMonitorHttps);
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
                throw new InvalidArgumentException('The DMS installation options are incomplete.');
            }
        }

        foreach (['pmta_port', 'pmta_admin_port', 'proxy_port'] as $port) {
            if (! isset($settings[$port]) || ! is_int($settings[$port]) || $settings[$port] < 1 || $settings[$port] > 65535) {
                throw new InvalidArgumentException('A required DMS installation port is invalid.');
            }
        }

        foreach (['dev_ips', 'pmta_relay_ips', 'production_ips', 'rdp_ips'] as $ipList) {
            if (! isset($settings[$ipList]) || ! is_array($settings[$ipList])) {
                throw new InvalidArgumentException('A required DMS installation IP allowlist is invalid.');
            }

            foreach ($settings[$ipList] as $ip) {
                if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    throw new InvalidArgumentException('A required DMS installation IP allowlist is invalid.');
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
                throw new InvalidArgumentException('A required DMS installation switch is invalid.');
            }
        }

        foreach (['dkim_selector', 'dkim_key', 'imap_username', 'imap_password', 'proxy_username', 'proxy_password', 'mini_app_url', 'mini_app_key'] as $value) {
            if (! isset($settings[$value]) || ! is_string($settings[$value])) {
                throw new InvalidArgumentException('A required DMS installation setting is missing.');
            }
        }

        if (! array_key_exists('dkim_public_key', $settings)
            || (! is_null($settings['dkim_public_key']) && ! is_string($settings['dkim_public_key']))) {
            throw new InvalidArgumentException('The DMS DKIM public key setting is invalid.');
        }

        foreach (['mini_app', 'cloudflare_ips', 'dovecot_config', 'squid_config'] as $asset) {
            if (! array_key_exists($asset, $assets)
                || (! is_null($assets[$asset]) && ! is_string($assets[$asset]))) {
                throw new InvalidArgumentException('A required DMS installation asset input is missing.');
            }
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
        return (new DmsPmtaPostInstallReadiness)->command($version, $settings, $https, $domain, $directMonitor);
    }
}
