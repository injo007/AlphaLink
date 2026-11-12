<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Campaigns\PmtaPanelUrl;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/** Replays the AlphaLink Reinstall host, DNS, component, firewall, and persistence order. */
class AlphaLinkCentos9ReinstallWorkflow
{
    public function __construct(
        private readonly AlphaLinkPmtaDeploymentGate $pmtaGate,
        private readonly AlphaLinkInstallPlatform $platform,
        private readonly AlphaLinkReinstallSelectionValidator $selectionValidator,
        private readonly AlphaLinkBulkInstallRuntimeInputs $runtimeInputs,
        private readonly AlphaLinkInstallerServerCredentialResolver $credentials,
        private readonly AlphaLinkInstallerSshSessionFactory $sessions,
        private readonly AlphaLinkCentos9HostPreparation $hostPreparation,
        private readonly AlphaLinkCentos9ServiceSetup $serviceSetup,
        private readonly AlphaLinkCentos9PhpInstallationStage $phpInstallation,
        private readonly AlphaLinkCentos9TrackingDeploymentStage $tracking,
        private readonly AlphaLinkBulkInstallDomainRecordStage $domainRecords,
        private readonly AlphaLinkCentos9PmtaDeploymentStage $pmta,
        private readonly AlphaLinkCentos9VmtaDeploymentStage $vmtas,
        private readonly AlphaLinkCentos9ProxyDeploymentStage $proxy,
        private readonly AlphaLinkCentos9ImapDeploymentStage $imap,
        private readonly AlphaLinkCentos9HttpsDeploymentStage $https,
        private readonly AlphaLinkCentos9FirewallDeploymentStage $firewall,
        private readonly AlphaLinkBulkInstallIspCatalog $ispCatalog,
        private readonly AlphaLinkReinstallCompletionWriter $completion,
        private readonly AlphaLinkBulkInstallDomainDnsRecordWriter $dnsWriter,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $submittedRows
     * @param  array{pmta: bool, tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool}  $options
     * @param  Closure(string): void  $log
     * @return array{pmta_admin_url: ?string, ip_count: int, domain_count: int}
     */
    public function run(
        Workspace $workspace,
        User $actor,
        ManagedServer $server,
        array $submittedRows,
        int $pmtaVersion,
        array $options,
        Closure $log,
        ?string $requestId = null,
    ): array {
        if (! in_array($pmtaVersion, [40, 45, 50], true)) {
            throw new InvalidArgumentException('Select a supported PMTA version.');
        }
        foreach (['pmta', 'tracking', 'proxy', 'imap', 'spf', 'dkim', 'dmarc', 'https'] as $key) {
            if (! is_bool($options[$key] ?? null)) {
                throw new InvalidArgumentException('The reinstall component selections are invalid.');
            }
        }

        $prepared = $this->selectionValidator->prepare($workspace, $server, $submittedRows);
        $hostFacts = is_array($server->host_facts) ? $server->host_facts : [];
        if ($this->platform->installerFamily((string) ($hostFacts['os_pretty_name'] ?? '')) !== 'centos9'
            || $this->platform->blockers($hostFacts) !== []) {
            throw new InvalidArgumentException('Reinstall currently supports the verified AlmaLinux 9 / CentOS 9 x86_64 workflow.');
        }

        if ($options['pmta']) {
            $readiness = $this->pmtaGate->evaluate($hostFacts, $pmtaVersion, $this->componentOptions($options));
            if (! $readiness['installation_authorized']) {
                throw new RuntimeException('PMTA deployment prerequisites are not verified.');
            }
        }

        // Resolve settings and every selected private asset before the first remote mutation.
        $inputs = $this->runtimeInputs->resolve($this->componentOptions($options), $workspace, $options['pmta'] ? $pmtaVersion : null);
        $rows = $prepared['rows'];
        $mainDomain = $prepared['main_domain'];
        $subdomains = $this->subdomains($rows);
        $credential = $this->credentials->resolve($server);
        $session = new AlphaLinkFailClosedInstallerTransport(
            $this->sessions->create($server, $credential['secret'], $credential['type']),
            'Server Reinstall',
        );
        $dnsRows = $this->domainRecords->resolvePublicAddresses(
            $rows,
            new AlphaLinkCentos9PublicIpResolver($session),
            $log,
            (string) $server->ip_address,
        );
        $groups = $this->groupRows($dnsRows, $prepared['domains']);
        $mainDomainProviderReady = $this->dnsWriter->isDomainAvailable($mainDomain);
        $deploymentDomain = $mainDomainProviderReady ? (string) $mainDomain->name : null;
        $deploymentSubdomains = $mainDomainProviderReady ? $subdomains : [];
        if (! $mainDomainProviderReady) {
            $log(sprintf(
                'The selected domain %s is not available from its configured DNS provider; skipping domain-based host naming and using public IP %s for tracking and PMTA monitor endpoints.',
                $mainDomain->name,
                $server->ip_address,
            ));
        }

        if ($options['tracking'] && $inputs['assets']['tracking_app'] !== null) {
            $this->tracking->verifyCallback($session, $inputs['settings']['tracking_callback_url']);
        }

        $log('Prepare Reinstalling Server : '.$server->getKey());

        $log('Re Setup Host');
        $configuredHostname = $this->hostPreparation->configureHost($session, (string) $server->ip_address, $deploymentDomain);
        if (! $mainDomainProviderReady) {
            $log(sprintf(
                'No DNS-managed domain is available; configured PMTA host identity %s while keeping tracking and monitor endpoints on public IP %s.',
                $configuredHostname,
                $server->ip_address,
            ));
        }
        $log('End Setting Host');

        $log('Re Setup Services');
        $this->serviceSetup->run($session);
        $log('End Setting Services');

        if ($options['tracking']) {
            $log('Re Setup PHP');
            $this->phpInstallation->run($session);
            $log('End Setting PHP');

            $log('Re Setup Tracking App');
            $this->tracking->run(
                $session,
                $session,
                (string) $server->ip_address,
                (string) $server->getKey(),
                $deploymentDomain,
                $deploymentSubdomains,
                $inputs['assets']['tracking_app'],
                $inputs['assets']['cloudflare_ips'],
                $inputs['settings']['tracking_callback_url'],
                $inputs['settings']['tracking_ingest_key'],
                $options['https'] && $mainDomainProviderReady,
                (string) config('app.name', 'NA'),
            );
            $log('End Setting Tracking App');
        }

        $resolver = new AlphaLinkCentos9PublicIpResolver($session);
        foreach ($groups as $group) {
            $dnsReady = $this->domainRecords->run(
                $group['domain'],
                $group['rows'],
                [
                    'tracking' => $options['tracking'],
                    'imap' => $options['imap'],
                    'spf' => $options['spf'],
                    'dkim' => $options['dkim'],
                    'dmarc' => $options['dmarc'],
                ],
                $inputs['settings']['dkim_selector'],
                $inputs['settings']['dkim_public_key'],
                $resolver,
                $this->dnsWriter,
                $log,
                continueOnDnsFailure: true,
            );
            if ($dnsReady !== null && (string) $group['domain']->getKey() === (string) $mainDomain->getKey()) {
                $mainDomainProviderReady = $dnsReady;
            }
        }

        $monitorDomain = $mainDomainProviderReady ? (string) $mainDomain->name : null;
        // Keep the stable PMTA 4.5/5.0 loopback backend behind Nginx for
        // IP-only monitor fallback; PMTA 4.0 retains its direct listener mode.
        $directPmtaMonitor = AlphaLinkPmtaMonitorMode::usesDirectListener(
            $pmtaVersion,
            $mainDomainProviderReady,
            $options['pmta'],
        );
        if (! $mainDomainProviderReady && $deploymentDomain !== null) {
            $log(sprintf(
                'DNS provider setup did not complete for %s; switching PMTA monitor access to public IP %s.',
                $mainDomain->name,
                $server->ip_address,
            ));
        }

        $adminUrl = null;
        $pmtaMonitorHttps = (bool) (($server->install_parameters['pmta_monitor_https'] ?? false));
        $domainCertificateReady = false;
        $trackingHttpsReady = false;
        if ($options['pmta']) {
            $log('Re Setup PMTA '.$pmtaVersion);
            $this->pmta->run(
                $session,
                $session,
                $hostFacts,
                $pmtaVersion,
                $inputs['settings']['pmta_port'],
                $inputs['settings']['pmta_admin_port'],
                $inputs['settings']['dev_ips'],
                $inputs['settings']['production_ips'],
                $inputs['settings']['allow_all_monitor'],
                $this->componentOptions($options),
                $inputs['assets']['pmta_domains_override'] ?? null,
                $inputs['settings']['pmta_relay_ips'],
                $directPmtaMonitor,
            );
            $log('End Setting PMTA');

            $log('Re Setup VMTAS');
            $vmtaRows = array_map(static fn (array $row): array => [
                'ip' => $row['ip'],
                'sub' => $row['sub'],
                'domain' => $row['domain'],
                'is_our' => $row['is_our'],
            ], $rows);
            $this->vmtas->run(
                $session,
                $session,
                (bool) $mainDomain->is_our,
                $vmtaRows,
                $this->ispCatalog->vmtaNames($workspace),
                $inputs['settings']['dkim_selector'],
                $options['dkim'],
                $inputs['settings']['dkim_key'],
            );
            $log('End Setting VMTAS');

        }

        $ips = array_column($rows, 'ip');
        if ($options['proxy']) {
            $log('Re Setup Proxy');
            $this->proxy->run(
                $session,
                $session,
                $inputs['assets']['squid_config'],
                $inputs['settings']['proxy_port'],
                $ips,
                $inputs['settings']['dev_ips'],
                $inputs['settings']['production_ips'],
                $inputs['settings']['proxy_username'],
                $inputs['settings']['proxy_password'],
            );
            $log('End Setting Proxy');
        }

        if ($options['imap']) {
            $log('Re Setup IMAP');
            $this->imap->run(
                $session,
                $session,
                $inputs['assets']['dovecot_config'],
                $inputs['settings']['imap_username'],
                $inputs['settings']['imap_password'],
            );
            $log('End Setting IMAP');
        }

        if ($options['https'] || $options['pmta']) {
            $log($options['https'] && $mainDomainProviderReady ? 'Re Setup Https' : 'Re Setup HTTPS for PMTA Web Monitor');
            if ($monitorDomain !== null && ! $this->firewall->prepareDomainCertificatePorts($session)) {
                $log('The host firewall could not confirm HTTP/HTTPS access before domain certificate issuance; the PMTA monitor may fall back to the AlphaLink HTTPS proxy.');
            }
            $domainCertificateReady = $this->https->run($session, $monitorDomain, $mainDomainProviderReady ? $subdomains : []);
            if ($monitorDomain !== null && ! $domainCertificateReady) {
                $log('The selected domain certificate was not issued; check public DNS and inbound HTTP port 80 before retrying.');
            }
            if ($options['pmta']) {
                $pmtaMonitorHttps = $this->https->configurePmtaMonitor(
                    $session,
                    $session,
                    $monitorDomain,
                    $pmtaVersion,
                    $inputs['settings']['pmta_admin_port'],
                    $inputs['settings']['dev_ips'],
                    $inputs['settings']['production_ips'],
                    $inputs['settings']['allow_all_monitor'],
                    $inputs['settings']['pmta_relay_ips'],
                    $directPmtaMonitor,
                );
                if (! $pmtaMonitorHttps) {
                    $log(sprintf(
                        'PMTA monitor HTTPS is unavailable; using the public IP HTTP endpoint http://%s:%d%s.',
                        $server->ip_address,
                        $inputs['settings']['pmta_admin_port'],
                        $directPmtaMonitor ? ' directly from PMTA' : ' through Nginx',
                    ));
                }
            }
            $log('End Setting HTTPS');
        }
        if ($options['pmta']) {
            $adminUrl = PmtaPanelUrl::displayUrlForInstallation(
                (string) $server->ip_address,
                $monitorDomain,
                $inputs['settings']['pmta_admin_port'],
                $pmtaMonitorHttps,
                (string) $server->getKey(),
            );
        }
        if ($adminUrl !== null) {
            $log(str_starts_with($adminUrl, 'https://')
                ? 'PMTA MONITOR LINK (verified HTTPS): '.$adminUrl
                : sprintf(
                    'PMTA MONITOR LINK (HTTP server monitor through AlphaLink HTTPS proxy; PMTA listener port %d): %s',
                    $inputs['settings']['pmta_admin_port'],
                    $adminUrl,
                ));
        }

        $log('Re Config Firewall');
        $this->firewall->run(
            $session,
            $inputs['settings']['apply_firewall'],
            (int) $server->ssh_port,
            $inputs['settings']['pmta_port'],
            $inputs['settings']['pmta_admin_port'],
            $inputs['settings']['proxy_port'],
            $inputs['settings']['dev_ips'],
            $inputs['settings']['production_ips'],
            $inputs['settings']['rdp_ips'],
            $inputs['settings']['allow_all_monitor'],
            $inputs['settings']['pmta_relay_ips'] ?? [],
        );
        $log('End Setting Firewall');

        if ($options['tracking'] && $options['https'] && $mainDomainProviderReady && $domainCertificateReady) {
            $trackingHttpsReady = $this->tracking->verifyHttps($session, $monitorDomain);
            if (! $trackingHttpsReady) {
                $log('Tracking HTTPS did not pass its public health and redirect checks; campaign tracking links will use the verified HTTP endpoint.');
            }
        }

        if ($options['pmta']) {
            $log('Verify PMTA relay and listener readiness');
            $readiness = $session->exec($this->pmtaReadinessCommand(
                $pmtaVersion,
                $inputs['settings'],
                $pmtaMonitorHttps,
                $monitorDomain,
                $directPmtaMonitor,
            ));
            if ($readiness !== 'ALPHALINK_PMTA_READY') {
                $diagnostics = AlphaLinkPmtaPostInstallReadiness::failureDetails($readiness);
                throw new RuntimeException('PMTA post-reinstall verification failed: '.$diagnostics.'.');
            }
            $log('PMTA relay and listener ready');
        }

        $this->completion->finalize(
            $workspace,
            $actor,
            $server,
            $rows,
            $pmtaVersion,
            [...$options, 'https' => $options['https'] && $mainDomainProviderReady && $domainCertificateReady],
            $requestId,
            $pmtaMonitorHttps,
            $monitorDomain ?? (string) $server->ip_address,
            $trackingHttpsReady,
        );

        $log('Ended Disconnecting From Server : '.$server->getKey());

        return [
            'pmta_admin_url' => $adminUrl,
            'ip_count' => count($rows),
            'domain_count' => count($prepared['domains']),
        ];
    }

    /** @param array<string, bool> $options @return array{tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool} */
    private function componentOptions(array $options): array
    {
        return [
            'tracking' => $options['tracking'],
            'proxy' => $options['proxy'],
            'imap' => $options['imap'],
            'spf' => $options['spf'],
            'dkim' => $options['dkim'],
            'dmarc' => $options['dmarc'],
            'https' => $options['https'],
        ];
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

    /** @param list<array<string, mixed>> $rows @return list<string> */
    private function subdomains(array $rows): array
    {
        $subdomains = [];
        foreach ($rows as $row) {
            if ($row['is_our'] && $row['sub'] !== '') {
                $subdomains[] = 'www.'.$row['domain'];
                $subdomains[] = $row['sub'].'.'.$row['domain'];
            }
        }

        return array_values(array_unique($subdomains));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<SendingDomain>  $domains
     * @return list<array{domain: SendingDomain, rows: list<array<string, mixed>>}>
     */
    private function groupRows(array $rows, array $domains): array
    {
        $domainsById = [];
        foreach ($domains as $domain) {
            $domainsById[(string) $domain->getKey()] = $domain;
        }

        $groups = [];
        foreach ($rows as $row) {
            $domainId = $row['domain_id'];
            $domain = $domainsById[$domainId] ?? null;
            if (! $domain instanceof SendingDomain) {
                throw new InvalidArgumentException('A reinstall IP row references an unavailable domain.');
            }
            $groups[$domainId] ??= ['domain' => $domain, 'rows' => []];
            $groups[$domainId]['rows'][] = $row;
        }

        return array_values($groups);
    }
}
