<?php

namespace App\Services\Spf;

use Closure;

/**
 * SPF record resolver for the local reference-compatible scanner.
 *
 * The AlphaLink parser is maintained locally; native DNS is used here because the
 * alternate resolver package is not part of this application.
 */
class AlphaLinkSpfScanner
{
    /** @var array<string, array{records: array, expires_at: float}> */
    private array $dnsCache = [];

    private int $dnsErrors = 0;

    /** @param (Closure(string): array{records: list<string>, error: bool})|null $txtLookup */
    public function __construct(private readonly ?Closure $txtLookup = null) {}

    /**
     * @return array{domain: string, status: string, record: ?string, cidrs: list<string>, hosts: list<array{domain: string, record: ?string, status: string, cidrs: list<string>}>, dns_errors: int}
     */
    public function scanDomain(string $domain, ?Closure $activity = null): array
    {
        $this->dnsErrors = 0;
        $domain = SpfParser::normalizeDomain(rtrim(trim($domain), '.'));
        if (! SpfParser::isHostname($domain)) {
            return ['domain' => $domain, 'status' => 'error', 'record' => null, 'cidrs' => [], 'hosts' => [], 'dns_errors' => 0];
        }

        $visited = [];
        $hosts = [];
        $this->scanNode($domain, $visited, $hosts, max(1, (int) config('spf.max_includes', 50)), $activity);
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
            'dns_errors' => $this->dnsErrors,
        ];
    }

    /** @param array<string, true> $visited
     * @param  array<string, array{domain: string, record: ?string, status: string, cidrs: list<string>}>  $hosts
     */
    private function scanNode(string $domain, array &$visited, array &$hosts, int $maximumHosts, ?Closure $activity = null): void
    {
        $domain = SpfParser::normalizeDomain($domain);
        if (isset($visited[$domain]) || count($visited) >= $maximumHosts) {
            return;
        }
        $visited[$domain] = true;
        [$record, $dnsError] = $this->lookupSpf($domain, $activity);
        $status = $record !== null ? 'ok' : ($dnsError ? 'error' : 'no_spf');
        $cidrs = $record === null ? [] : SpfParser::extractCidrs($record);
        $hosts[$domain] = ['domain' => $domain, 'record' => $record, 'status' => $status, 'cidrs' => $cidrs];

        if ($record === null) {
            return;
        }

        if (config('spf.resolve_a_mx', true)) {
            foreach (array_slice(SpfParser::extractAAndMxTargets($domain, $record), 0, max(1, (int) config('spf.max_mechanism_lookups', 10))) as $target) {
                foreach ($this->targetAddresses($target['type'], $target['domain'], $activity) as $ip) {
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
            $this->notify($activity, 'include', $include, 'include_started');
            $this->scanNode($include, $visited, $hosts, $maximumHosts, $activity);
        }
    }

    /** @return array{0: ?string, 1: bool} */
    private function lookupSpf(string $domain, ?Closure $activity = null): array
    {
        if ($this->txtLookup !== null) {
            $startedAt = microtime(true);
            $this->notify($activity, 'spf_txt', $domain, 'lookup_started');
            $result = ($this->txtLookup)($domain);
            if ($result['error']) {
                $this->dnsErrors++;
            }
            $this->notify($activity, 'spf_txt', $domain, 'lookup_finished', (int) round((microtime(true) - $startedAt) * 1000), (bool) $result['error']);
            foreach ($result['records'] as $candidate) {
                $record = SpfParser::normalizeRecord($candidate);
                if (SpfParser::isSpfRecord($record)) {
                    return [$record, false];
                }
            }

            return [null, $result['error']];
        }

        [$records, $failed] = $this->dnsRecords($domain, DNS_TXT, 'spf_txt', $activity);
        foreach ($records as $answer) {
            $chunks = $answer['entries'] ?? null;
            $candidate = is_array($chunks) ? implode('', $chunks) : (string) ($answer['txt'] ?? '');
            $record = SpfParser::normalizeRecord($candidate);
            if (SpfParser::isSpfRecord($record)) {
                return [$record, false];
            }
        }

        return [null, $failed];
    }

    /** @return list<string> */
    private function targetAddresses(string $type, string $domain, ?Closure $activity = null): array
    {
        $records = $type === 'mx'
            ? $this->dnsRecords($domain, DNS_MX, 'mx', $activity)[0]
            : $this->dnsRecords($domain, DNS_A | DNS_AAAA, 'a_mx', $activity)[0];
        $addresses = [];
        foreach ($records as $record) {
            $host = $type === 'mx' ? rtrim((string) ($record['target'] ?? ''), '.') : $domain;
            if ($host === '') {
                continue;
            }
            if ($type === 'mx') {
                $addressRecords = $this->dnsRecords($host, DNS_A | DNS_AAAA, 'a_mx', $activity)[0];
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

    /** @return array{0: array, 1: bool} */
    private function dnsRecords(string $domain, int $type, string $stage, ?Closure $activity): array
    {
        $domain = SpfParser::normalizeDomain($domain);
        $cacheKey = $domain.':'.$type;
        $startedAt = microtime(true);
        $this->notify($activity, $stage, $domain, 'lookup_started');
        $cached = $this->dnsCache[$cacheKey] ?? null;
        if ($cached !== null && $cached['expires_at'] > $startedAt) {
            $this->notify($activity, $stage, $domain, 'lookup_finished', 0, false);

            return [$cached['records'], false];
        }
        unset($this->dnsCache[$cacheKey]);

        try {
            $records = @dns_get_record($domain, $type);
            $failed = ! is_array($records);
            $records = $failed ? [] : $records;
        } catch (\Throwable) {
            $records = [];
            $failed = true;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        if ($failed) {
            $this->dnsErrors++;
        } elseif ($records !== []) {
            $this->cacheDnsRecords($cacheKey, $records, $startedAt);
        }
        $this->notify($activity, $stage, $domain, 'lookup_finished', $durationMs, $failed);

        return [$records, $failed];
    }

    private function cacheDnsRecords(string $key, array $records, float $now): void
    {
        $maximumEntries = max(100, (int) config('spf.dns_cache_entries', 2000));
        $ttlLimit = max(1, min(60, (int) config('spf.dns_cache_ttl_seconds', 60)));
        $answerTtl = null;
        foreach ($records as $record) {
            if (isset($record['ttl']) && is_numeric($record['ttl'])) {
                $answerTtl = $answerTtl === null ? (int) $record['ttl'] : min($answerTtl, (int) $record['ttl']);
            }
        }
        $ttl = min($ttlLimit, $answerTtl ?? 15);
        if ($ttl <= 0) {
            return;
        }
        if (count($this->dnsCache) >= $maximumEntries) {
            array_shift($this->dnsCache);
        }
        $this->dnsCache[$key] = ['records' => $records, 'expires_at' => $now + $ttl];
    }

    private function notify(
        ?Closure $activity,
        string $stage,
        string $domain,
        string $event,
        ?int $durationMs = null,
        bool $failed = false,
    ): void {
        if ($activity === null) {
            return;
        }
        try {
            $activity($stage, $domain, $event, $durationMs, $failed);
        } catch (\Throwable) {
            // Activity reporting is best effort and cannot stop a DNS scan.
        }
    }
}
