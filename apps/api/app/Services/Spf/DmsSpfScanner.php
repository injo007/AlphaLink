<?php

namespace App\Services\Spf;

use Closure;

/**
 * SPF record resolver for the local DMS-compatible scanner.
 *
 * The DMS parser is copied source-side; native DNS is used here because the
 * DMS Amp resolver dependency is not part of this application.
 */
class DmsSpfScanner
{
    /** @param (Closure(string): array{records: list<string>, error: bool})|null $txtLookup */
    public function __construct(private readonly ?Closure $txtLookup = null) {}

    /**
     * @return array{domain: string, status: string, record: ?string, cidrs: list<string>, hosts: list<array{domain: string, record: ?string, status: string, cidrs: list<string>}>}
     */
    public function scanDomain(string $domain): array
    {
        $domain = SpfParser::normalizeDomain(rtrim(trim($domain), '.'));
        if (! SpfParser::isHostname($domain)) {
            return ['domain' => $domain, 'status' => 'error', 'record' => null, 'cidrs' => [], 'hosts' => []];
        }

        $visited = [];
        $hosts = [];
        $this->scanNode($domain, $visited, $hosts, max(1, (int) config('spf.max_includes', 50)));
        $root = $hosts[$domain] ?? ['domain' => $domain, 'record' => null, 'status' => 'error', 'cidrs' => []];
        $cidrs = [];
        foreach ($hosts as $host) {
            foreach ($host['cidrs'] as $cidr) {
                $cidrs[$cidr] = true;
            }
        }

        return [
            'domain' => $domain,
            'status' => $root['status'],
            'record' => $root['record'],
            'cidrs' => array_keys($cidrs),
            'hosts' => array_values($hosts),
        ];
    }

    /** @param array<string, true> $visited
     * @param  array<string, array{domain: string, record: ?string, status: string, cidrs: list<string>}>  $hosts
     */
    private function scanNode(string $domain, array &$visited, array &$hosts, int $maximumHosts): void
    {
        $domain = SpfParser::normalizeDomain($domain);
        if (isset($visited[$domain]) || count($visited) >= $maximumHosts) {
            return;
        }
        $visited[$domain] = true;
        [$record, $dnsError] = $this->lookupSpf($domain);
        $status = $record !== null ? 'ok' : ($dnsError ? 'error' : 'no_spf');
        $cidrs = $record === null ? [] : SpfParser::extractCidrs($record);
        $hosts[$domain] = ['domain' => $domain, 'record' => $record, 'status' => $status, 'cidrs' => $cidrs];

        if ($record === null) {
            return;
        }

        if (config('spf.resolve_a_mx', true)) {
            foreach (array_slice(SpfParser::extractAAndMxTargets($domain, $record), 0, max(1, (int) config('spf.max_mechanism_lookups', 10))) as $target) {
                foreach ($this->targetAddresses($target['type'], $target['domain']) as $ip) {
                    $cidr = SpfParser::ipToCidr($ip, $target['prefix4'], $target['prefix6']);
                    if ($cidr !== null) {
                        $hosts[$domain]['cidrs'][] = $cidr;
                    }
                }
            }
        }
        $hosts[$domain]['cidrs'] = array_values(array_unique($hosts[$domain]['cidrs']));

        foreach (SpfParser::childIncludes($record, $domain) as $include) {
            if (count($visited) >= $maximumHosts) {
                break;
            }
            $this->scanNode($include, $visited, $hosts, $maximumHosts);
        }
    }

    /** @return array{0: ?string, 1: bool} */
    private function lookupSpf(string $domain): array
    {
        if ($this->txtLookup !== null) {
            $result = ($this->txtLookup)($domain);
            foreach ($result['records'] as $candidate) {
                $record = SpfParser::normalizeRecord($candidate);
                if (SpfParser::isSpfRecord($record)) {
                    return [$record, false];
                }
            }

            return [null, $result['error']];
        }

        try {
            $records = @dns_get_record($domain, DNS_TXT);
            if (! is_array($records)) {
                return [null, true];
            }
            foreach ($records as $answer) {
                $chunks = $answer['entries'] ?? null;
                $candidate = is_array($chunks) ? implode('', $chunks) : (string) ($answer['txt'] ?? '');
                $record = SpfParser::normalizeRecord($candidate);
                if (SpfParser::isSpfRecord($record)) {
                    return [$record, false];
                }
            }

            return [null, false];
        } catch (\Throwable) {
            return [null, true];
        }
    }

    /** @return list<string> */
    private function targetAddresses(string $type, string $domain): array
    {
        $records = $type === 'mx'
            ? (@dns_get_record($domain, DNS_MX) ?: [])
            : (@dns_get_record($domain, DNS_A | DNS_AAAA) ?: []);
        $addresses = [];
        foreach ($records as $record) {
            $host = $type === 'mx' ? rtrim((string) ($record['target'] ?? ''), '.') : $domain;
            if ($host === '') {
                continue;
            }
            if ($type === 'mx') {
                $addressRecords = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
                foreach ($addressRecords as $addressRecord) {
                    foreach (['ip', 'ipv6'] as $key) {
                        if (isset($addressRecord[$key]) && filter_var($addressRecord[$key], FILTER_VALIDATE_IP)) {
                            $addresses[$addressRecord[$key]] = true;
                        }
                    }
                }

                continue;
            }
            foreach (['ip', 'ipv6'] as $key) {
                if (isset($record[$key]) && filter_var($record[$key], FILTER_VALIDATE_IP)) {
                    $addresses[$record[$key]] = true;
                }
            }
        }

        return array_keys($addresses);
    }
}
