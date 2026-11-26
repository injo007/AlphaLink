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
        private readonly AlphaLinkMultiDomainInstallPlan $multiDomainPlan,
        private readonly AlphaLinkAssignmentDnsRecordWriter $assignmentDnsWriter,
        private readonly AlphaLinkSendingDomainDkimIdentityManager $assignmentDkimIdentities,
        private readonly AlphaLinkMultiDomainDnsVerifier $multiDomainDnsVerifier,
        private readonly AlphaLinkManualDnsVerifier $manualDnsVerifier,
        private readonly AlphaLinkCentos9PmtaDeploymentStage $pmta,
        private readonly AlphaLinkCentos9VmtaDeploymentStage $vmtas,
        private readonly AlphaLinkCentos9ProxyDeploymentStage $proxy,
        private readonly AlphaLinkCentos9ImapDeploymentStage $imap,
        private readonly AlphaLinkCentos9HttpsDeploymentStage $https,
        private readonly AlphaLinkCentos9FirewallDeploymentStage $firewall,
        private readonly AlphaLinkBulkInstallIspCatalog $ispCatalog,
        private readonly AlphaLinkReinstallCompletionWriter $completion,
        private readonly AlphaLinkBulkInstallDomainDnsRecordWriter $dnsWriter,
        private readonly AlphaLinkPmtaApplicationReadiness $applicationReadiness,
        private readonly AlphaLinkPendingManualDnsPlanStore $pendingManualDnsPlans = new AlphaLinkPendingManualDnsPlanStore,
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
        $domainsById = collect($prepared['domains'])->keyBy(static fn (SendingDomain $domain): string => (string) $domain->getKey())->all();
        $hasMultipleDomainsPerIp = collect($rows)->contains(static fn (array $row): bool => ! empty($row['domains']));
        $assignmentPlan = null;
        $pmtaSigningIdentities = [];
        $assignmentReadinessByKey = [];
        $trackingHttpsReadyByHost = [];
        $allAssignmentAddressesReady = true;
        if ($hasMultipleDomainsPerIp) {
            if (! $options['pmta'] || ! $options['tracking'] || ! $options['https'] || ! $options['spf'] || ! $options['dkim'] || ! $options['dmarc']) {
                throw new RuntimeException('Multi-domain reinstall requires HTTPS tracking and SPF, DKIM, and DMARC to be enabled.');
            }
            $assignmentPlan = $this->multiDomainPlan->build($rows);
            $rows = $assignmentPlan['ips'];
            foreach ($assignmentPlan['assignments'] as &$plannedAssignment) {
                $selectedDomain = $domainsById[(string) $plannedAssignment['domain_id']] ?? null;
                if (! $selectedDomain instanceof SendingDomain) {
                    throw new InvalidArgumentException('A reinstall assignment references a domain outside the saved server map.');
                }
                $identity = $this->assignmentDkimIdentities->ensure($workspace, $selectedDomain, $plannedAssignment['hostname']);
                $plannedAssignment['sending_domain_dkim_identity_id'] = (string) $identity->getKey();
                $plannedAssignment['dkim_selector'] = (string) $identity->selector;
                $plannedAssignment['dkim_public_record'] = (string) $identity->public_record;
                $plannedAssignment['dkim_private_key'] = (string) $identity->private_key;
                $plannedAssignment['ehlo_hostname'] = (string) collect($rows)
                    ->firstWhere('ip', $plannedAssignment['ip'])['ehlo_hostname'];
                $pmtaSigningIdentities[$plannedAssignment['hostname']] = [
                    'selector' => (string) $identity->selector,
                    'private_key' => (string) $identity->private_key,
                ];
            }
            unset($plannedAssignment);
            $plannedByIpAndHostname = [];
            foreach ($assignmentPlan['assignments'] as $plannedAssignment) {
                $plannedByIpAndHostname[$plannedAssignment['ip'].'|'.$plannedAssignment['hostname']] = $plannedAssignment;
            }
            foreach ($assignmentPlan['dns_groups'] as &$dnsGroup) {
                foreach ($dnsGroup['assignments'] as &$dnsAssignment) {
                    $key = $dnsAssignment['ip'].'|'.$dnsAssignment['hostname'];
                    $dnsAssignment = [...$dnsAssignment, ...array_intersect_key(
                        $plannedByIpAndHostname[$key],
                        array_flip(['sending_domain_dkim_identity_id', 'dkim_selector', 'dkim_public_record', 'ehlo_hostname']),
                    )];
                }
                unset($dnsAssignment);
            }
            unset($dnsGroup);
            foreach ($rows as &$row) {
                $primaryHostname = strtolower(($row['sub'] !== '' ? $row['sub'].'.' : '').$row['domain']);
                $primaryIdentity = $plannedByIpAndHostname[$row['ip'].'|'.$primaryHostname];
                $row = [...$row, ...$this->completionIdentityFields($primaryIdentity)];
                foreach ($row['domains'] as &$additional) {
                    $hostname = strtolower(($additional['sub'] !== '' ? $additional['sub'].'.' : '').$additional['domain']);
                    $additional = [...$additional, ...$this->completionIdentityFields($plannedByIpAndHostname[$row['ip'].'|'.$hostname])];
                }
                unset($additional);
            }
            unset($row);
            $assignmentPlan['pmta_signing_identities'] = $pmtaSigningIdentities;
        }
        $mainAssignment = collect($rows)->firstWhere('main', true) ?? $rows[0];
        $trackingDomain = $assignmentPlan !== null
            ? (string) $mainAssignment['tracking_host']
            : (string) $mainDomain->name;
        $subdomains = $assignmentPlan !== null
            ? array_values(array_diff($assignmentPlan['tracking_hosts'], [$trackingDomain]))
            : $this->subdomains($rows);
        $credential = $this->credentials->resolve($server);
        $session = new AlphaLinkFailClosedInstallerTransport(
            $this->sessions->create($server, $credential['secret'], $credential['type']),
            'Server Reinstall',
        );
        $dnsInputRows = $rows;
        foreach ($dnsInputRows as &$dnsInputRow) {
            $dnsInputRow['source_ip'] = (string) $dnsInputRow['ip'];
        }
        unset($dnsInputRow);
        $dnsRows = $this->domainRecords->resolvePublicAddresses(
            $dnsInputRows,
            new AlphaLinkCentos9PublicIpResolver($session),
            $log,
            (string) $server->ip_address,
        );
        $groups = $this->groupRows($dnsRows, $prepared['domains']);
        $publicIpBySource = [];
        foreach ($dnsRows as $resolvedIp) {
            $publicIpBySource[(string) ($resolvedIp['source_ip'] ?? $resolvedIp['ip'])] = (string) $resolvedIp['ip'];
        }
        $mainHostname = strtolower(($mainAssignment['sub'] !== '' ? $mainAssignment['sub'].'.' : '').$mainAssignment['domain']);
        $mainPublicIp = $publicIpBySource[(string) $mainAssignment['ip']] ?? (string) $mainAssignment['ip'];
        $mainDomainProviderReady = $assignmentPlan !== null
            ? true
            : ($mainDomain->is_our
                ? $this->dnsWriter->isDomainAvailable($mainDomain)
                : $this->manualDnsVerifier->verifies([[
                    'hostname' => $mainHostname,
                    'ip' => $mainPublicIp,
                ]]));
        $deploymentDomain = $assignmentPlan !== null
            ? (string) $mainAssignment['ehlo_hostname']
            : (string) $mainDomain->name;
        $deploymentSubdomains = $assignmentPlan !== null
            ? $subdomains
            : ($mainDomainProviderReady ? $subdomains : []);
        if ($assignmentPlan !== null) {
            $publicAssignments = [];
            foreach ($assignmentPlan['assignments'] as $assignment) {
                $publicIp = $publicIpBySource[(string) $assignment['ip']] ?? null;
                if ($publicIp === null) {
                    throw new RuntimeException('A selected assignment has no verified public IP for DNS checks.');
                }
                $physicalIp = collect($rows)->firstWhere('ip', $assignment['ip']);
                $publicAssignments[] = [
                    ...$assignment,
                    'source_ip' => (string) $assignment['ip'],
                    'ip' => $publicIp,
                    'ehlo_hostname' => (string) $physicalIp['ehlo_hostname'],
                ];
            }
            $pendingManualDnsPlans = [];
            $this->pendingManualDnsPlans->replace($workspace, $server, []);
            foreach ($assignmentPlan['dns_groups'] as $dnsGroup) {
                $selectedDomain = $domainsById[(string) $dnsGroup['domain_id']] ?? null;
                if (! $selectedDomain instanceof SendingDomain) {
                    throw new InvalidArgumentException('A DNS assignment group is outside the saved server map.');
                }
                $records = [];
                foreach ($dnsGroup['assignments'] as $assignment) {
                    $records[] = [
                        'hostname' => $assignment['hostname'],
                        'ip' => $publicIpBySource[$assignment['ip']],
                        'tracking_host' => $assignment['tracking_host'],
                        'dkim_selector' => $assignment['dkim_selector'],
                        'dkim_public_record' => $assignment['dkim_public_record'],
                    ];
                }
                $additionalARecords = [];
                $providerAvailable = $selectedDomain->is_our && $this->assignmentDnsWriter->isAssignmentDomainAvailable($selectedDomain);
                if (! $providerAvailable) {
                    $log(sprintf('DNS zone %s is managed manually; reinstall will not call its DNS provider. Selected sending and HTTPS tracking hostnames are checked individually.', $selectedDomain->name));
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
                    $log('DNS provider updates did not finish for '.$selectedDomain->name.'; continuing server reinstall and checking public DNS readiness: '.$exception->getMessage());
                }
            }
            $assignmentReadiness = $this->multiDomainDnsVerifier->inspectReadiness($publicAssignments, $options);
            $allAssignmentAddressesReady = true;
            foreach ($assignmentReadiness as $readiness) {
                $key = $readiness['source_ip'].'|'.$readiness['hostname'];
                $assignmentReadinessByKey[$key] = $readiness;
                $allAssignmentAddressesReady = $allAssignmentAddressesReady && $readiness['dns_verified'];
                if (! $readiness['dns_verified']) {
                    $log('A/AAAA DNS is pending for '.implode(', ', $readiness['missing_address_hosts']).'; continuing reinstall without domain HTTPS for this multi-domain setup.');
                } elseif (! $readiness['authentication_verified']) {
                    $log('SPF, DKIM, or DMARC TXT records are pending for '.$readiness['hostname'].'; the domain remains selectable for sending while mail-authentication checks are pending.');
                }
            }
            $mainDomainProviderReady = $allAssignmentAddressesReady;
            if (! $allAssignmentAddressesReady) {
                $log('Domain HTTPS will be skipped until selected sending/tracking hostnames and EHLO A/AAAA records resolve to their selected IPs.');
            }
        } elseif (! $mainDomain->is_our) {
            $log('External DNS is managed manually; reinstall will not call its DNS provider.');
        }
        if (! $mainDomainProviderReady) {
            $log(sprintf(
                'DNS record management is unavailable for %s; retaining the selected hostname and checking its public HTTPS route independently. DNS updates may be skipped.',
                $mainDomain->name,
            ));
        }

        if ($options['tracking'] && $inputs['assets']['tracking_app'] !== null) {
            $this->tracking->verifyCallback($session, $inputs['settings']['tracking_callback_url']);
        }

        $log('Prepare Reinstalling Server : '.$server->getKey());

        $log('Re Setup Host');
        $configuredHostname = $this->hostPreparation->configureHost($session, (string) $server->ip_address, $deploymentDomain);
        $log('Configured PMTA host identity '.$configuredHostname);
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
                $assignmentPlan !== null && $options['https'] ? $trackingDomain : $deploymentDomain,
                $deploymentSubdomains,
                $inputs['assets']['tracking_app'],
                $inputs['assets']['cloudflare_ips'],
                $inputs['settings']['tracking_callback_url'],
                $inputs['settings']['tracking_ingest_key'],
                $options['https'],
                (string) config('app.name', 'NA'),
            );
            $log('End Setting Tracking App');
        }

        if ($assignmentPlan === null) {
            $resolver = new AlphaLinkCentos9PublicIpResolver($session);
            $pendingManualDnsPlans = [];
            $this->pendingManualDnsPlans->replace($workspace, $server, []);
            foreach ($groups as $group) {
                if (! $group['domain']->is_our) {
                    $records = $this->domainRecords->plannedDomainRecords(
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
                    );
                    $this->domainRecords->logPlannedRecords($group['domain'], $records, $log);
                    $pendingManualDnsPlans[] = [
                        'domain_id' => (string) $group['domain']->getKey(),
                        'domain' => (string) $group['domain']->name,
                        'records' => $records,
                    ];
                    $this->pendingManualDnsPlans->replace($workspace, $server, $pendingManualDnsPlans);
                    $log('Pending manual DNS records are available on the external domain record page.');

                    $manualRecords = array_map(static fn (array $row): array => [
                        'hostname' => trim((string) ($row['sub'] ?? '')) !== ''
                            ? trim((string) $row['sub']).'.'.(string) $group['domain']->name
                            : (string) $group['domain']->name,
                        'ip' => (string) $row['ip'],
                    ], $group['rows']);
                    $dnsReady = $this->manualDnsVerifier->verifies($manualRecords);
                    $log(sprintf(
                        'External DNS for %s %s the selected IPs; no provider records were changed.',
                        $group['domain']->name,
                        $dnsReady ? 'already points to' : 'does not point to',
                    ));
                } else {
                    $dnsReady = $this->domainRecords->run(
                        $group['domain'],
                        $group['rows'],
                        [
                            'tracking' => $options['tracking'],
                            'imap' => $options['imap'],
                            'spf' => $options['spf'],
                            'dkim' => $options['dkim'],
                            'dmarc' => $options['dmarc'],
                            'clean_dns' => ! empty($options['clean_dns']),
                        ],
                        $inputs['settings']['dkim_selector'],
                        $inputs['settings']['dkim_public_key'],
                        $resolver,
                        $this->dnsWriter,
                        $log,
                        continueOnDnsFailure: true,
                    );
                }
                if ($dnsReady !== null && (string) $group['domain']->getKey() === (string) $mainDomain->getKey()) {
                    $mainDomainProviderReady = $dnsReady;
                }
            }
        }

        $monitorDomain = $assignmentPlan !== null ? $trackingDomain : (string) $mainDomain->name;
        // Keep the stable PMTA 4.5/5.0 loopback backend behind Nginx for
        // IP-only monitor fallback; PMTA 4.0 retains its direct listener mode.
        $directPmtaMonitor = AlphaLinkPmtaMonitorMode::usesDirectListener(
            $pmtaVersion,
            $mainDomainProviderReady,
            $options['pmta'],
        );

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
            $interfaceMap = is_array($hostFacts['ip_interface_map'] ?? null) ? $hostFacts['ip_interface_map'] : [];
            $vmtaRows = array_map(static fn (array $row): array => [
                'ip' => $row['ip'],
                'interface_ip' => $row['interface_ip'] ?? ($interfaceMap[$row['ip']] ?? null),
                'sub' => $row['sub'],
                'domain' => $row['domain'],
                'is_our' => $row['is_our'],
                ...(isset($row['domains']) ? ['domains' => array_map(static fn (array $assignment): array => [
                    'sub' => $assignment['sub'],
                    'domain' => $assignment['domain'],
                ], $row['domains'])] : []),
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
                $assignmentPlan['pmta_signing_identities'] ?? [],
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
            $certificateDomain = $assignmentPlan !== null && ! $allAssignmentAddressesReady ? null : $monitorDomain;
            if ($assignmentPlan !== null && ! $allAssignmentAddressesReady) {
                $log('Skipping domain HTTPS because assignment A/AAAA records are not ready; PMTA monitoring will use the AlphaLink proxy or IP fallback.');
            }
            if ($certificateDomain !== null && ! $this->firewall->prepareDomainCertificatePorts($session)) {
                $log('The host firewall could not confirm HTTP/HTTPS access before domain certificate issuance; the PMTA monitor may fall back to the AlphaLink HTTPS proxy.');
            }
            $domainCertificateReady = $this->https->run($session, $certificateDomain, $mainDomainProviderReady ? $subdomains : []);
            if ($certificateDomain !== null && ! $domainCertificateReady) {
                $log('The selected domain certificate was not issued; check public DNS and inbound HTTP port 80 before retrying.');
            }
            if ($options['pmta']) {
                $pmtaMonitorHttps = $this->https->configurePmtaMonitor(
                    $session,
                    $session,
                    $domainCertificateReady ? $monitorDomain : null,
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
            $log($pmtaMonitorHttps
                ? sprintf(
                    'PMTA MONITOR LINK (verified HTTPS server monitor through AlphaLink proxy; PMTA listener port %d): %s',
                    $inputs['settings']['pmta_admin_port'],
                    $adminUrl,
                )
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

        if ($assignmentPlan !== null) {
            foreach ($assignmentPlan['tracking_hosts'] as $assignmentTrackingHost) {
                $ready = $domainCertificateReady
                    && $this->tracking->verifyHttps($session, $assignmentTrackingHost);
                $trackingHttpsReadyByHost[$assignmentTrackingHost] = $ready;
                if (! $ready) {
                    $log('HTTPS tracking remains pending for '.$assignmentTrackingHost.'; sending remains available, but click/open/unsubscribe tracking is omitted until HTTPS is ready.');
                }
            }
            foreach ($rows as &$row) {
                $primaryHostname = strtolower(($row['sub'] !== '' ? $row['sub'].'.' : '').$row['domain']);
                $primaryReadiness = $assignmentReadinessByKey[$row['ip'].'|'.$primaryHostname] ?? null;
                $primaryTrackingHost = (string) ($row['tracking_host'] ?? '');
                $row['dns_verified'] = (bool) ($primaryReadiness['dns_verified'] ?? false);
                $row['authentication_verified'] = (bool) ($primaryReadiness['authentication_verified'] ?? false);
                $row['tracking_https_ready'] = $row['dns_verified']
                    && (bool) ($trackingHttpsReadyByHost[$primaryTrackingHost] ?? false);
                if (! empty($row['main'])) {
                    $trackingHttpsReady = $row['tracking_https_ready'];
                }
                foreach ($row['domains'] as &$additional) {
                    $hostname = strtolower(($additional['sub'] !== '' ? $additional['sub'].'.' : '').$additional['domain']);
                    $readiness = $assignmentReadinessByKey[$row['ip'].'|'.$hostname] ?? null;
                    $trackingHost = (string) ($additional['tracking_host'] ?? '');
                    $additional['dns_verified'] = (bool) ($readiness['dns_verified'] ?? false);
                    $additional['authentication_verified'] = (bool) ($readiness['authentication_verified'] ?? false);
                    $additional['tracking_https_ready'] = $additional['dns_verified']
                        && (bool) ($trackingHttpsReadyByHost[$trackingHost] ?? false);
                }
                unset($additional);
            }
            unset($row);
        } elseif ($options['tracking'] && $options['https'] && $domainCertificateReady) {
            $trackingHttpsReady = $this->tracking->verifyHttps($session, $monitorDomain);
            if (! $trackingHttpsReady) {
                $log('Tracking HTTPS did not pass its public health and redirect checks; tracked campaign sends require repair before use.');
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
            $this->applicationReadiness->verify($workspace, $server, $inputs['settings']['pmta_admin_port']);
            $log('PMTA relay and listener ready');
        }

        $this->completion->finalize(
            $workspace,
            $actor,
            $server,
            $rows,
            $pmtaVersion,
            [...$options, 'https' => $options['https'] && $domainCertificateReady],
            $requestId,
            $pmtaMonitorHttps,
            $trackingHttpsReady ? $monitorDomain : (string) $server->ip_address,
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
            $sub = trim((string) ($row['sub'] ?? ''));
            if ($sub !== '') {
                $subdomains[] = 'www.'.$row['domain'];
                $subdomains[] = $sub.'.'.$row['domain'];
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
