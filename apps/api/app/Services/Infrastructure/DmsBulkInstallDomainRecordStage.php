<?php

namespace App\Services\Infrastructure;

use App\Models\SendingDomain;
use Closure;
use RuntimeException;

/** Reconstructs ServerInstallBulk::setup_records() behind fakeable host/provider boundaries. */
class DmsBulkInstallDomainRecordStage
{
    public function __construct(private readonly DmsBulkInstallDnsRecordPlanner $planner) {}

    /**
     * @param  list<array{main: bool, ip: string, type: string, sub: string, domain: string}>  $ips
     * @param  array{tracking: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool}  $options
     * @param  Closure(string): void  $log
     */
    public function run(
        SendingDomain $domain,
        array $ips,
        array $options,
        string $dkimSelector,
        ?string $dkimPublicKey,
        DmsBulkInstallPublicIpResolver $publicIpResolver,
        DmsBulkInstallDomainDnsRecordWriter $dnsWriter,
        Closure $log,
    ): void {
        $resolvedIps = $this->resolvePublicAddresses($ips, $publicIpResolver, $log);

        $records = $this->planner->records(
            (bool) $domain->is_our,
            $resolvedIps,
            $options,
            $dkimSelector,
            $dkimPublicKey,
        );
        if ($records === []) {
            $log('Skipping DNS records for '.$domain->name.' (no DNS options selected)');

            return;
        }

        $recordSummary = $this->recordSummary($records);
        $log('Config Hosts A-AAAA-MX... For '.$domain->name.' ('.$recordSummary.')');
        $result = $dnsWriter->setHosts($domain, $records);

        if (! $result) {
            throw new RuntimeException('The selected domain DNS records could not be applied through its provider.');
        }

        $log('Done Setting Records For '.$domain->name.' ('.$recordSummary.')');
    }

    /** @param list<array{host: string, address: string, type: string, ttl: string}> $records */
    private function recordSummary(array $records): string
    {
        $counts = [];
        foreach ($records as $record) {
            $type = strtoupper($record['type']);
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        $summary = [];
        foreach (['A', 'AAAA', 'MX', 'TXT'] as $type) {
            if (isset($counts[$type])) {
                $summary[] = $type.'='.$counts[$type];
            }
        }

        return implode(', ', $summary);
    }

    /**
     * @param  list<array{main: bool, ip: string, type: string, sub: string, domain: string}>  $ips
     * @param  callable(string): void|null  $log
     * @return list<array{main: bool, ip: string, type: string, sub: string, domain: string}>
     */
    public function resolvePublicAddresses(
        array $ips,
        DmsBulkInstallPublicIpResolver $publicIpResolver,
        ?callable $log = null,
    ): array {
        $resolved = [];
        foreach ($ips as $entry) {
            $family = $entry['type'] === 'ipv4' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
            $originalIp = $entry['ip'];
            $originalIsPublic = filter_var($originalIp, FILTER_VALIDATE_IP, $family | FILTER_FLAG_NO_PRIV_RANGE) !== false;

            if (! filter_var($originalIp, FILTER_VALIDATE_IP)) {
                throw new RuntimeException('A selected interface has no verified public IP for DNS records.');
            }

            if (! $originalIsPublic) {
                $entry['ip'] = $publicIpResolver->resolve($originalIp);
            }

            if (! filter_var($entry['ip'], FILTER_VALIDATE_IP, $family | FILTER_FLAG_NO_PRIV_RANGE)) {
                if ($this->hasPublicPeer($entry, $ips)) {
                    if ($log !== null) {
                        $log(sprintf(
                            'Skipping unverified private interface %s for %s.%s; a verified public %s address is already selected.',
                            $originalIp,
                            $entry['sub'] === '' ? '@' : $entry['sub'],
                            $entry['domain'],
                            $entry['type'],
                        ));
                    }

                    continue;
                }

                throw new RuntimeException('A selected interface has no verified public IP for DNS records.');
            }

            $resolved[] = $entry;
        }

        return $resolved;
    }

    /**
     * @param  array{main: bool, ip: string, type: string, sub: string, domain: string}  $entry
     * @param  list<array{main: bool, ip: string, type: string, sub: string, domain: string}>  $ips
     */
    private function hasPublicPeer(array $entry, array $ips): bool
    {
        $family = $entry['type'] === 'ipv4' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;

        foreach ($ips as $candidate) {
            if ($candidate['type'] !== $entry['type']
                || $candidate['sub'] !== $entry['sub']
                || $candidate['domain'] !== $entry['domain']) {
                continue;
            }

            if (filter_var($candidate['ip'], FILTER_VALIDATE_IP, $family | FILTER_FLAG_NO_PRIV_RANGE) !== false) {
                return true;
            }
        }

        return false;
    }
}
