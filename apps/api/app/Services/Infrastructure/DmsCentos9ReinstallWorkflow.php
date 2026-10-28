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

/** Replays the DMS Reinstall host, DNS, component, firewall, and persistence order. */
class DmsCentos9ReinstallWorkflow
{
    public function __construct(
        private readonly DmsPmtaDeploymentGate $pmtaGate,
        private readonly DmsInstallPlatform $platform,
        private readonly DmsReinstallSelectionValidator $selectionValidator,
        private readonly DmsBulkInstallRuntimeInputs $runtimeInputs,
        private readonly DmsInstallerServerCredentialResolver $credentials,
        private readonly DmsInstallerSshSessionFactory $sessions,
        private readonly DmsCentos9HostPreparation $hostPreparation,
        private readonly DmsCentos9ServiceSetup $serviceSetup,
        private readonly DmsCentos9PhpInstallationStage $phpInstallation,
        private readonly DmsCentos9TrackingDeploymentStage $tracking,
        private readonly DmsBulkInstallDomainRecordStage $domainRecords,
        private readonly DmsCentos9PmtaDeploymentStage $pmta,
        private readonly DmsCentos9VmtaDeploymentStage $vmtas,
        private readonly DmsCentos9ProxyDeploymentStage $proxy,
        private readonly DmsCentos9ImapDeploymentStage $imap,
        private readonly DmsCentos9HttpsDeploymentStage $https,
        private readonly DmsCentos9FirewallDeploymentStage $firewall,
        private readonly DmsBulkInstallIspCatalog $ispCatalog,
        private readonly DmsReinstallCompletionWriter $completion,
        private readonly DmsBulkInstallDomainDnsRecordWriter $dnsWriter,
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
        $session = new DmsFailClosedInstallerTransport(
            $this->sessions->create($server, $credential['secret'], $credential['type']),
            'Server Reinstall',
        );
        $dnsRows = $this->domainRecords->resolvePublicAddresses(
            $rows,
            new DmsCentos9PublicIpResolver($session),
            $log,
            (string) $server->ip_address,
        );
        $groups = $this->groupRows($dnsRows, $prepared['domains']);

        if ($options['tracking'] && $inputs['assets']['mini_app'] !== null) {
            $this->tracking->verifyCallback($session, $inputs['settings']['mini_app_url']);
        }

        $log('Prepare Reinstalling Server : '.$server->getKey());

        $log('Re Setup Host');
        $this->hostPreparation->configureHost($session, (string) $server->ip_address, (string) $mainDomain->name);
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
                (string) $mainDomain->name,
                $subdomains,
                $inputs['assets']['mini_app'],
                $inputs['assets']['cloudflare_ips'],
                $inputs['settings']['mini_app_url'],
                $inputs['settings']['mini_app_key'],
                $options['https'],
            );
            $log('End Setting Tracking App');
        }

        $resolver = new DmsCentos9PublicIpResolver($session);
        foreach ($groups as $group) {
            $this->domainRecords->run(
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
            );
        }

        $adminUrl = null;
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

            $adminUrl = PmtaPanelUrl::forInstallation(
                (string) $server->ip_address,
                (string) $mainDomain->name,
                $inputs['settings']['pmta_admin_port'],
                $options['https'],
            );
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

        if ($options['https']) {
            $log('Re Setup Https');
            $this->https->run($session, (string) $mainDomain->name, $subdomains);
            if ($options['pmta']) {
                $this->https->configurePmtaMonitor(
                    $session,
                    $session,
                    (string) $mainDomain->name,
                    $pmtaVersion,
                    $inputs['settings']['pmta_admin_port'],
                    $inputs['settings']['dev_ips'],
                    $inputs['settings']['production_ips'],
                    $inputs['settings']['allow_all_monitor'],
                    $inputs['settings']['pmta_relay_ips'],
                );
            }
            $log('End Setting Https');
        }
        if ($adminUrl !== null) {
            $log('PMTA MONITOR LINK : '.$adminUrl);
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

        if ($options['pmta']) {
            $log('Verify PMTA relay and listener readiness');
            $readiness = $session->exec($this->pmtaReadinessCommand(
                $pmtaVersion,
                $inputs['settings'],
                $options['https'],
                (string) $mainDomain->name,
            ));
            if ($readiness !== 'ALPHALINK_PMTA_READY') {
                throw new RuntimeException('PMTA package, HTTP listener, access policy, or relay authorization failed post-reinstall verification.');
            }
            $log('PMTA relay and listener ready');
        }

        $this->completion->finalize(
            $workspace,
            $actor,
            $server,
            $rows,
            $pmtaVersion,
            $options,
            $requestId,
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
    private function pmtaReadinessCommand(int $version, array $settings, bool $https = false, ?string $domain = null): string
    {
        $publicPort = $settings['pmta_admin_port'];
        $pmtaHttpPort = $publicPort * 2;
        $checks = [
            'rpm -q PowerMTA >/dev/null',
            'systemctl is-active --quiet pmta',
            'systemctl is-active --quiet pmtahttp',
            "ss -H -ltn | awk -v pattern=':{$pmtaHttpPort}$' '\$4 ~ pattern { found = 1 } END { exit !found }'",
            "grep -Fqx 'http-access 127.0.0.1 admin' /etc/pmta/settings/access",
        ];
        foreach ($settings['pmta_relay_ips'] as $ip) {
            $checks[] = "grep -Fq '<source {$ip}>' /etc/pmta/settings/access";
            $checks[] = "grep -Fq 'always-allow-relaying yes' /etc/pmta/settings/access";
        }

        $checks[] = "grep -Fq 'location = /command {' /etc/nginx/conf.d/default.conf";
        $checks[] = "! grep -Fqx 'http-access 0/0 monitor' /etc/pmta/settings/access";
        $checks[] = 'systemctl is-active --quiet nginx';
        $checks[] = 'nginx -t >/dev/null 2>&1';
        $checks[] = "ss -H -ltn | awk -v pattern=':{$publicPort}$' '\$4 ~ pattern { found = 1 } END { exit !found }'";
        $checks[] = "grep -Fq 'proxy_pass http://127.0.0.1:{$pmtaHttpPort};' /etc/nginx/conf.d/default.conf";
        foreach ($settings['pmta_relay_ips'] as $ip) {
            $checks[] = "grep -Fq 'allow {$ip};' /etc/nginx/conf.d/default.conf";
        }
        if (! $settings['allow_all_monitor']) {
            foreach (array_values(array_unique([...$settings['dev_ips'], ...$settings['production_ips'], ...$settings['pmta_relay_ips']])) as $ip) {
                $checks[] = "grep -Fq 'allow {$ip};' /etc/nginx/conf.d/default.conf";
            }
            $checks[] = "grep -Fq '# ALPHALINK_PMTA_MONITOR_ACCESS_RESTRICTED' /etc/nginx/conf.d/default.conf";
        } else {
            $checks[] = "grep -Fq '# ALPHALINK_PMTA_MONITOR_ACCESS_PUBLIC' /etc/nginx/conf.d/default.conf";
        }
        if ($https && is_string($domain) && $domain !== '') {
            $checks[] = "grep -Fq 'listen {$publicPort} ssl;' /etc/nginx/conf.d/default.conf";
            $domainArgument = escapeshellarg($domain);
            $checks[] = 'grep -Fq '.escapeshellarg("server_name {$domain};").' /etc/nginx/conf.d/default.conf';
            $checks[] = 'test -s '.escapeshellarg('/etc/letsencrypt/live/'.$domain.'/fullchain.pem');
            $checks[] = 'test -s '.escapeshellarg('/etc/letsencrypt/live/'.$domain.'/privkey.pem');
            $checks[] = "printf '' | timeout 8 openssl s_client -connect 127.0.0.1:{$publicPort} -servername {$domainArgument} -verify_hostname {$domainArgument} -verify_return_error 2>&1 | grep -Fq 'Verify return code: 0 (ok)'";
        }

        $checks[] = 'printf ALPHALINK_PMTA_READY';

        return implode(' && ', $checks);
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
