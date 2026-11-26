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
        private readonly AlphaLinkMultiDomainInstallPlan $multiDomainPlan,
        private readonly AlphaLinkAssignmentDnsRecordWriter $assignmentDnsWriter,
        private readonly AlphaLinkSendingDomainDkimIdentityManager $assignmentDkimIdentities,
        private readonly AlphaLinkMultiDomainDnsVerifier $multiDomainDnsVerifier,
        private readonly AlphaLinkManualDnsVerifier $manualDnsVerifier,
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
        private readonly AlphaLinkPmtaApplicationReadiness $applicationReadiness,
        private readonly AlphaLinkPendingManualDnsPlanStore $pendingManualDnsPlans = new AlphaLinkPendingManualDnsPlanStore,
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
            $ips = $this->groupSharedIpAssignments($ips);
        }

        $multiDomain = collect($ips)->contains(static fn (array $ip): bool => ! empty($ip['domains']));
        $assignmentPlan = $multiDomain ? $this->multiDomainPlan->build($ips) : null;
        $assignmentReadinessByKey = [];
        $trackingHttpsReadyByHost = [];
        $allAssignmentAddressesReady = true;
        if ($assignmentPlan !== null) {
            $ips = $assignmentPlan['ips'];
            if (! $options['tracking'] || ! $options['https'] || ! $options['spf'] || ! $options['dkim'] || ! $options['dmarc']) {
                throw new RuntimeException('Multi-domain installation requires HTTPS tracking and SPF, DKIM, and DMARC to be enabled.');
            }

            $pmtaSigningIdentities = [];
            foreach ($assignmentPlan['assignments'] as &$plannedAssignment) {
                $selectedDomain = $domainsById[(string) $plannedAssignment['domain_id']] ?? null;
                if (! $selectedDomain instanceof SendingDomain) {
                    throw new InvalidArgumentException('A planned assignment does not belong to a selected sending domain.');
                }
                $identity = $this->assignmentDkimIdentities->ensure($workspace, $selectedDomain, $plannedAssignment['hostname']);
                $plannedAssignment['sending_domain_dkim_identity_id'] = (string) $identity->getKey();
                $plannedAssignment['dkim_selector'] = (string) $identity->selector;
                $plannedAssignment['dkim_public_record'] = (string) $identity->public_record;
                $plannedAssignment['dkim_private_key'] = (string) $identity->private_key;
                $plannedAssignment['ehlo_hostname'] = (string) collect($assignmentPlan['ips'])
                    ->firstWhere('ip', $plannedAssignment['ip'])['ehlo_hostname'];
                $pmtaSigningIdentities[$plannedAssignment['hostname']] = [
                    'selector' => (string) $identity->selector,
                    'private_key' => (string) $identity->private_key,
                ];
            }
            unset($plannedAssignment);

            // DNS groups are planned before DKIM material is generated. Carry the
            // public identity data into the provider writer input without ever
            // putting private keys into DNS records or installation parameters.
            $plannedAssignmentsByKey = [];
            foreach ($assignmentPlan['assignments'] as $plannedAssignment) {
                $plannedAssignmentsByKey[$plannedAssignment['ip'].'|'.$plannedAssignment['hostname']] = $plannedAssignment;
            }
            foreach ($assignmentPlan['dns_groups'] as &$dnsGroup) {
                foreach ($dnsGroup['assignments'] as &$dnsAssignment) {
                    $key = $dnsAssignment['ip'].'|'.$dnsAssignment['hostname'];
                    $dnsAssignment = [...$dnsAssignment, ...array_intersect_key(
                        $plannedAssignmentsByKey[$key],
                        array_flip(['sending_domain_dkim_identity_id', 'dkim_selector', 'dkim_public_record', 'ehlo_hostname']),
                    )];
                }
                unset($dnsAssignment);
            }
            unset($dnsGroup);

            $assignmentByIpAndHostname = [];
            foreach ($assignmentPlan['assignments'] as $plannedAssignment) {
                $assignmentByIpAndHostname[$plannedAssignment['ip'].'|'.$plannedAssignment['hostname']] = $plannedAssignment;
            }
            foreach ($ips as &$ipEntry) {
                $primaryHostname = strtolower(($ipEntry['sub'] !== '' ? $ipEntry['sub'].'.' : '').$ipEntry['domain']);
                $primaryIdentity = $assignmentByIpAndHostname[$ipEntry['ip'].'|'.$primaryHostname];
                $ipEntry = [...$ipEntry, ...$this->completionIdentityFields($primaryIdentity)];
                foreach ($ipEntry['domains'] as &$additionalIdentity) {
                    $hostname = strtolower(($additionalIdentity['sub'] !== '' ? $additionalIdentity['sub'].'.' : '').$additionalIdentity['domain']);
                    $planned = $assignmentByIpAndHostname[$ipEntry['ip'].'|'.$hostname];
                    $additionalIdentity = [...$additionalIdentity, ...$this->completionIdentityFields($planned)];
                }
                unset($additionalIdentity);
            }
            unset($ipEntry);
            $assignmentPlan['pmta_signing_identities'] = $pmtaSigningIdentities;
        }

        $interfaceMap = is_array($hostFacts['ip_interface_map'] ?? null) ? $hostFacts['ip_interface_map'] : [];
        foreach ($ips as &$ipEntry) {
            if (! isset($ipEntry['interface_ip']) && isset($interfaceMap[$ipEntry['ip']])) {
                $ipEntry['interface_ip'] = $interfaceMap[$ipEntry['ip']];
            }
        }
        unset($ipEntry);

        $domainProviderReady = [];
        foreach ($domainsById as $domainId => $selectedDomain) {
            $domainProviderReady[$domainId] = ! $selectedDomain->is_our
                ? false
                : ($assignmentPlan !== null
                    ? $this->assignmentDnsWriter->isAssignmentDomainAvailable($selectedDomain)
                    : $dnsWriter->isDomainAvailable($selectedDomain));
        }
        $primaryDomainId = (string) $primaryDomain->getKey();
        $primaryDomainReady = $domainProviderReady[$primaryDomainId] ?? false;
        $deploymentDomain = $assignmentPlan !== null
            ? (string) $ips[array_search(true, array_column($ips, 'main'), true)]['ehlo_hostname']
            : (string) $primaryDomain->name;

        $readySubdomains = static function (array $ipsToInclude) use ($domain, &$domainProviderReady, $primaryDomain): array {
            $list = [];
            foreach ($ipsToInclude as $ip) {
                $entryDomainId = (string) ($ip['domain_id'] ?? $domain->getKey());
                if (! ($domainProviderReady[$entryDomainId] ?? false)) {
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
        $trackingAssignment = collect($ips)->firstWhere('main', true) ?? $ips[0];
        $trackingDomain = $assignmentPlan !== null
            ? (string) $trackingAssignment['tracking_host']
            : (trim((string) $trackingAssignment['sub']) !== ''
                ? trim((string) $trackingAssignment['sub']).'.'.(string) $trackingAssignment['domain']
                : (string) $trackingAssignment['domain']);
        $trackingDomainAliases = $assignmentPlan !== null
            ? array_values(array_diff($assignmentPlan['tracking_hosts'], [$trackingDomain]))
            : $subdomains;
        $deploymentSubdomains = $assignmentPlan !== null
            ? $trackingDomainAliases
            : ($primaryDomainReady ? $subdomains : []);
        if (! $primaryDomainReady) {
            $log(sprintf(
                'DNS record management is unavailable for %s; retaining the selected hostname and checking its public HTTPS route independently. DNS updates may be skipped.',
                $primaryDomain->name,
            ));
        }

        $dnsInputIps = $ips;
        if ($assignmentPlan !== null) {
            foreach ($dnsInputIps as &$dnsInputIp) {
                $dnsInputIp['source_ip'] = $dnsInputIp['ip'];
            }
            unset($dnsInputIp);
        }
        $dnsIps = $this->domainRecords->resolvePublicAddresses(
            $dnsInputIps,
            new AlphaLinkCentos9PublicIpResolver($session),
            $log,
            (string) $server->ip_address,
        );

        if ($assignmentPlan === null) {
            $pendingManualDnsPlans = [];
            $this->pendingManualDnsPlans->replace($workspace, $server, []);
            foreach ($this->groupIpsByDomain($dnsIps, $domainsById) as $domainGroup) {
                if ($domainGroup['domain']->is_our) {
                    continue;
                }
                $records = $this->domainRecords->plannedDomainRecords(
                    $domainGroup['domain'],
                    $domainGroup['ips'],
                    $options,
                    $settings['dkim_selector'],
                    $settings['dkim_public_key'],
                );
                $this->domainRecords->logPlannedRecords($domainGroup['domain'], $records, $log);
                $pendingManualDnsPlans[] = [
                    'domain_id' => (string) $domainGroup['domain']->getKey(),
                    'domain' => (string) $domainGroup['domain']->name,
                    'records' => $records,
                ];
                $this->pendingManualDnsPlans->replace($workspace, $server, $pendingManualDnsPlans);
                $log('Pending manual DNS records are available on the external domain record page.');

                $manualRecords = array_map(static fn (array $ip): array => [
                    'hostname' => trim((string) ($ip['sub'] ?? '')) !== ''
                        ? trim((string) $ip['sub']).'.'.(string) $domainGroup['domain']->name
                        : (string) $domainGroup['domain']->name,
                    'ip' => (string) $ip['ip'],
                ], $domainGroup['ips']);
                $domainProviderReady[(string) $domainGroup['domain']->getKey()] = $this->manualDnsVerifier->verifies($manualRecords);
                $log(sprintf(
                    'External DNS for %s %s the selected IPs; no provider records will be changed.',
                    $domainGroup['domain']->name,
                    $domainProviderReady[(string) $domainGroup['domain']->getKey()] ? 'already points to' : 'does not point to',
                ));
            }
        }

        if ($assignmentPlan !== null) {
            $publicIpBySource = [];
            foreach ($dnsIps as $resolvedIp) {
                if (isset($resolvedIp['source_ip'])) {
                    $publicIpBySource[(string) $resolvedIp['source_ip']] = (string) $resolvedIp['ip'];
                }
            }
            $pendingManualDnsPlans = [];
            $this->pendingManualDnsPlans->replace($workspace, $server, []);
            foreach ($assignmentPlan['dns_groups'] as $dnsGroup) {
                $selectedDomain = $domainsById[$dnsGroup['domain_id']] ?? null;
                if (! $selectedDomain instanceof SendingDomain) {
                    throw new InvalidArgumentException('A DNS assignment group is outside the selected sending domains.');
                }
                $records = [];
                foreach ($dnsGroup['assignments'] as $assignment) {
                    $publicIp = $publicIpBySource[$assignment['ip']] ?? null;
                    if ($publicIp === null) {
                        throw new RuntimeException('A selected physical IP could not be resolved to its public DNS address.');
                    }
                    $records[] = [
                        'hostname' => $assignment['hostname'],
                        'ip' => $publicIp,
                        'tracking_host' => $assignment['tracking_host'],
                        'dkim_selector' => $assignment['dkim_selector'],
                        'dkim_public_record' => $assignment['dkim_public_record'],
                    ];
                }
                $additionalARecords = [];
                if (isset($assignmentPlan['shared_ehlo_hostname'])
                    && (string) ($assignmentPlan['ehlo_domain_id'] ?? '') === (string) $dnsGroup['domain_id']) {
                    foreach ($ips as $ipEntry) {
                        $additionalARecords[] = [
                            'hostname' => (string) $assignmentPlan['shared_ehlo_hostname'],
                            'ip' => (string) ($publicIpBySource[$ipEntry['ip']] ?? ''),
                        ];
                    }
                }
                if (! $selectedDomain->is_our || ! ($domainProviderReady[$dnsGroup['domain_id']] ?? false)) {
                    $log(sprintf(
                        '%s DNS zone for %s is managed manually; selected sending and HTTPS tracking hostnames are checked individually. No provider records will be changed, and setup continues with missing DNS pending.',
                        $selectedDomain->is_our ? 'Provider-managed' : 'External',
                        $dnsGroup['domain'],
                    ));
                    $this->domainRecords->logManualAssignmentRecords(
                        $selectedDomain,
                        $records,
                        [...$options, 'clean_dns' => false],
                        $log,
                        $additionalARecords,
                    );
                    if (! $selectedDomain->is_our) {
                        $pendingManualDnsPlans[(string) $selectedDomain->getKey()] = [
                            'domain_id' => (string) $selectedDomain->getKey(),
                            'domain' => (string) $selectedDomain->name,
                            'records' => $this->domainRecords->plannedAssignmentRecords(
                                $selectedDomain,
                                $records,
                                [...$options, 'clean_dns' => false],
                                $additionalARecords,
                            ),
                        ];
                        $this->pendingManualDnsPlans->replace($workspace, $server, array_values($pendingManualDnsPlans));
                        $log('Pending manual DNS records are available on the external domain record page.');
                    }

                    continue;
                }
                try {
                    $this->domainRecords->runAssignments(
                        $selectedDomain,
                        $records,
                        [...$options, 'clean_dns' => false],
                        $this->assignmentDnsWriter,
                        $log,
                        $additionalARecords,
                    );
                } catch (RuntimeException $exception) {
                    $log('DNS provider updates did not finish for '.$selectedDomain->name.'; continuing server installation and checking public DNS readiness: '.$exception->getMessage());
                }
            }

            $publicAssignments = [];
            foreach ($assignmentPlan['assignments'] as $assignment) {
                $publicIp = $publicIpBySource[$assignment['ip']] ?? null;
                if ($publicIp === null) {
                    throw new RuntimeException('A selected assignment has no verified public IP for DNS authentication checks.');
                }
                $ipEntry = collect($ips)->first(fn (array $ip): bool => (string) $ip['ip'] === (string) $assignment['ip']);
                if (! is_array($ipEntry)) {
                    throw new RuntimeException('A selected assignment has no physical-IP EHLO identity.');
                }
                $publicAssignments[] = [
                    ...$assignment,
                    'source_ip' => (string) $assignment['ip'],
                    'ip' => $publicIp,
                    'ehlo_hostname' => (string) $ipEntry['ehlo_hostname'],
                ];
            }

            $assignmentReadiness = $this->multiDomainDnsVerifier->inspectReadiness($publicAssignments, $options);
            $addressReadinessByDomain = [];
            $allAssignmentAddressesReady = true;
            foreach ($assignmentReadiness as $readiness) {
                $key = $readiness['source_ip'].'|'.$readiness['hostname'];
                $assignmentReadinessByKey[$key] = $readiness;
                $addressReadinessByDomain[$readiness['domain_id']][] = $readiness['dns_verified'];
                $allAssignmentAddressesReady = $allAssignmentAddressesReady && $readiness['dns_verified'];
                if (! $readiness['dns_verified']) {
                    $log('A/AAAA DNS is pending for '.implode(', ', $readiness['missing_address_hosts']).'; continuing installation without domain HTTPS for this multi-domain setup.');
                } elseif (! $readiness['authentication_verified']) {
                    $log('SPF, DKIM, or DMARC TXT records are pending for '.$readiness['hostname'].'; the domain remains selectable for sending while mail-authentication checks are pending.');
                }
            }
            foreach ($addressReadinessByDomain as $domainId => $states) {
                $domainProviderReady[$domainId] = ! in_array(false, $states, true);
            }
            if (! $allAssignmentAddressesReady) {
                $log('Domain HTTPS will be skipped until selected sending/tracking hostnames and EHLO A/AAAA records resolve to their selected IPs.');
            }
        }

        $primaryDomainReady = $domainProviderReady[$primaryDomainId] ?? false;
        $subdomains = $readySubdomains($ips);
        $deploymentSubdomains = $assignmentPlan !== null
            ? $trackingDomainAliases
            : ($primaryDomainReady ? $subdomains : []);

        if ($options['tracking'] && $assets['tracking_app'] !== null) {
            $this->tracking->verifyCallback($session, $settings['tracking_callback_url']);
        }

        $log('Setup Host');
        $configuredHostname = $this->hostPreparation->configureHost($session, (string) $server->ip_address, $deploymentDomain);
        $log('Configured PMTA host identity '.$configuredHostname);
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
            $trackingDeploymentDomain = $options['https'] ? $trackingDomain : $deploymentDomain;
            $this->tracking->run(
                $session, $session, (string) $server->ip_address, (string) $server->getKey(),
                $trackingDeploymentDomain, $deploymentSubdomains, $assets['tracking_app'], $assets['cloudflare_ips'],
                $settings['tracking_callback_url'], $settings['tracking_ingest_key'],
                $options['https'] && $trackingDeploymentDomain !== null,
                (string) config('app.name', 'NA'),
            );
            $log('End Setting Tracking App');
        }

        foreach ($assignmentPlan === null ? $this->groupIpsByDomain($dnsIps, $domainsById) : [] as $domainGroup) {
            if (! $domainGroup['domain']->is_our) {
                $log('External DNS is managed manually; no provider records will be changed for '.$domainGroup['domain']->name);

                continue;
            }
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
        $monitorDomain = (string) $primaryDomain->name;
        $subdomains = $readySubdomains($ips);
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
            $assignmentPlan['pmta_signing_identities'] ?? [],
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

        $certificateDomain = $monitorDomain;
        if ($options['https'] && $options['tracking']) {
            $certificateDomain = $trackingDomain;
        } elseif ($certificateDomain === null && $options['https']) {
            $certificateDomain = (string) $primaryDomain->name;
        }
        $certificateSubdomains = $assignmentPlan !== null
            ? $trackingDomainAliases
            : ($primaryDomainReady
                ? array_values(array_filter($subdomains, static fn (string $host): bool => $host !== $certificateDomain))
                : []);

        if ($assignmentPlan !== null && ! $allAssignmentAddressesReady) {
            $certificateDomain = null;
            $certificateSubdomains = [];
            $log('Skipping domain HTTPS because assignment A/AAAA records are not ready; PMTA monitoring will use the AlphaLink proxy or IP fallback.');
        }

        $log($assignmentPlan !== null && ! $allAssignmentAddressesReady
            ? 'Setup HTTPS for PMTA Web Monitor'
            : ($options['https'] ? 'Setup HTTPS for selected domain' : 'Setup HTTPS for PMTA Web Monitor'));
        if ($certificateDomain !== null && ! $this->firewall->prepareDomainCertificatePorts($session)) {
            $log('The host firewall could not confirm HTTP/HTTPS access before domain certificate issuance; the PMTA monitor may fall back to the AlphaLink HTTPS proxy.');
        }
        $domainCertificateReady = $this->https->run($session, $certificateDomain, $certificateSubdomains);
        if ($certificateDomain !== null && ! $domainCertificateReady) {
            $log('The selected domain certificate was not issued; check public DNS and inbound HTTP port 80 before retrying.');
        }
        $monitorDomain = $domainCertificateReady ? $certificateDomain : null;
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
        $trackingHttpsReadyByHost = [];
        if ($assignmentPlan === null && $options['tracking'] && $options['https'] && $domainCertificateReady && $trackingDomain !== '') {
            $trackingHttpsReady = $this->tracking->verifyHttps($session, $trackingDomain);
            if (! $trackingHttpsReady) {
                $log('Tracking HTTPS did not pass its health and redirect checks; tracked campaign sends require repair before use.');
            }
        }

        if ($assignmentPlan !== null) {
            foreach ($assignmentPlan['tracking_hosts'] as $assignmentTrackingHost) {
                $ready = $domainCertificateReady && $this->tracking->verifyHttps($session, $assignmentTrackingHost);
                $trackingHttpsReadyByHost[$assignmentTrackingHost] = $ready;
                if (! $ready) {
                    $log('HTTPS tracking remains pending for '.$assignmentTrackingHost.'; sending remains available, but click/open/unsubscribe tracking is omitted until HTTPS is ready.');
                }
            }
            foreach ($ips as &$ipEntry) {
                $primaryHostname = strtolower(($ipEntry['sub'] !== '' ? $ipEntry['sub'].'.' : '').$ipEntry['domain']);
                $primaryReadiness = $assignmentReadinessByKey[$ipEntry['ip'].'|'.$primaryHostname] ?? null;
                $primaryTrackingHost = (string) ($ipEntry['tracking_host'] ?? '');
                $ipEntry['dns_verified'] = (bool) ($primaryReadiness['dns_verified'] ?? false);
                $ipEntry['authentication_verified'] = (bool) ($primaryReadiness['authentication_verified'] ?? false);
                $ipEntry['tracking_https_ready'] = $ipEntry['dns_verified']
                    && (bool) ($trackingHttpsReadyByHost[$primaryTrackingHost] ?? false);
                if (! empty($ipEntry['main'])) {
                    $trackingHttpsReady = $ipEntry['tracking_https_ready'];
                }
                foreach ($ipEntry['domains'] as &$additionalIdentity) {
                    $hostname = strtolower(($additionalIdentity['sub'] !== '' ? $additionalIdentity['sub'].'.' : '').$additionalIdentity['domain']);
                    $readiness = $assignmentReadinessByKey[$ipEntry['ip'].'|'.$hostname] ?? null;
                    $trackingHost = (string) ($additionalIdentity['tracking_host'] ?? '');
                    $additionalIdentity['dns_verified'] = (bool) ($readiness['dns_verified'] ?? false);
                    $additionalIdentity['authentication_verified'] = (bool) ($readiness['authentication_verified'] ?? false);
                    $additionalIdentity['tracking_https_ready'] = $additionalIdentity['dns_verified']
                        && (bool) ($trackingHttpsReadyByHost[$trackingHost] ?? false);
                }
                unset($additionalIdentity);
            }
            unset($ipEntry);
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

        $this->applicationReadiness->verify($workspace, $server, $settings['pmta_admin_port']);
        $log('PMTA monitor and read-only command access verified through the configured relay proxy');

        $completedTrackingHost = $assignmentPlan !== null
            ? ($trackingHttpsReady ? $trackingDomain : (string) $server->ip_address)
            : ($trackingHttpsReady ? $trackingDomain : ($monitorDomain ?? (string) $server->ip_address));
        if ($ipAssignments === null) {
            $completedOptions = [...$options, 'https' => $options['https'] && $domainCertificateReady];
            $this->completion->finalize($workspace, $server, $primaryDomain, $ips, $pmtaVersion, $completedOptions, [], $pmtaMonitorHttps, $completedTrackingHost, $trackingHttpsReady);
        } else {
            $completedOptions = [...$options, 'https' => $options['https'] && $domainCertificateReady];
            $this->completion->finalize($workspace, $server, $primaryDomain, $ips, $pmtaVersion, $completedOptions, $selectedDomains, $pmtaMonitorHttps, $completedTrackingHost, $trackingHttpsReady);
        }
        $publicPort = $settings['pmta_admin_port'];
        $adminUrl = PmtaPanelUrl::displayUrlForInstallation(
            (string) $server->ip_address,
            $monitorDomain,
            $publicPort,
            $pmtaMonitorHttps,
            (string) $server->getKey(),
        );
        $monitorIsHttps = $pmtaMonitorHttps;
        $monitorLabel = $monitorIsHttps
            ? 'Open PMTA HTTPS monitor via AlphaLink'
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
     * Normalize legacy one-row-per-domain assignment requests into one physical
     * IP row with nested identities. This keeps PMTA VMTA and database IP rows
     * unique while preserving each domain/subdomain identity.
     *
     * @param  list<array<string, mixed>>  $assignments
     * @return list<array<string, mixed>>
     */
    private function groupSharedIpAssignments(array $assignments): array
    {
        $groups = [];
        foreach ($assignments as $assignment) {
            $key = (string) $assignment['ip'];
            $groups[$key] ??= [];
            $groups[$key][] = $assignment;
        }

        $rows = [];
        foreach ($groups as $group) {
            $primaryIndex = collect($group)->search(static fn (array $assignment): bool => (bool) ($assignment['main'] ?? false));
            if ($primaryIndex === false) {
                $primaryIndex = 0;
            }
            $primary = $group[$primaryIndex];
            $primary['domains'] = array_values($primary['domains'] ?? []);
            foreach ($group as $index => $assignment) {
                if ($index === $primaryIndex) {
                    continue;
                }
                $primary['domains'][] = [
                    'domain_id' => $assignment['domain_id'],
                    'domain' => $assignment['domain'],
                    'sub' => $assignment['sub'],
                    'is_our' => (bool) $assignment['is_our'],
                ];
            }
            $rows[] = $primary;
        }

        return $rows;
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

    /** @param array<string, mixed> $identity
     * @return array<string, mixed>
     */
    private function completionIdentityFields(array $identity): array
    {
        return [
            'sending_domain_dkim_identity_id' => (string) $identity['sending_domain_dkim_identity_id'],
            'dkim_selector' => (string) $identity['dkim_selector'],
            'dns_verified' => false,
            'authentication_verified' => false,
            'tracking_https_ready' => false,
        ];
    }
}
