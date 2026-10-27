<?php

namespace App\Services\Spf;

use App\Models\WorkspaceSpfRoot;
use Closure;
use Throwable;

class DmsSpfDnsCheckMatcher
{
    /** @param (Closure(string, int): array<int, array<string, mixed>>)|null $dnsLookup */
    public function __construct(private readonly ?Closure $dnsLookup = null) {}

    /**
     * Resolve the stored SPF DNS-check mechanisms at IP-search time.
     *
     * @param  iterable<WorkspaceSpfRoot>  $roots
     * @param  array<int, string>  $ips
     * @return array<int, array{root_id: int|string, matched_ip: string, root: string, include: string, network: string, record: ?string}>
     */
    public function findMatches(iterable $roots, array $ips): array
    {
        if (! config('spf.search_dns_checks', true) || $ips === []) {
            return [];
        }

        $maximumChecks = max(1, (int) config('spf.max_search_dns_checks', 10_000));
        $templates = [];
        $hostEdges = [];

        foreach ($roots as $root) {
            foreach ($root->hosts ?? [] as $host) {
                $domain = SpfParser::normalizeDomain((string) ($host['domain'] ?? ''));
                $record = SpfParser::normalizeRecord($host['record'] ?? null);
                if ($domain === '' || $record === null) {
                    continue;
                }

                foreach (SpfParser::extractStoredDnsChecks($domain, $record) as $template) {
                    $signature = SpfParser::dnsCheckKey($template);
                    if (! isset($templates[$signature]) && count($templates) >= $maximumChecks) {
                        continue;
                    }
                    $templates[$signature] ??= $template;
                    $hostEdges[$signature][] = [
                        'root_id' => $root->getKey(),
                        'root' => $root->domain,
                        'include' => $domain,
                        'record' => $record,
                    ];
                }
            }
        }

        if ($templates === []) {
            return [];
        }

        $dns = $this->resolveLookups(array_values($templates), $ips);
        $rows = [];
        $seen = [];

        foreach ($templates as $signature => $template) {
            foreach ($ips as $ip) {
                $expanded = SpfParser::expandForIp($template['domain'], $ip);
                if ($expanded === null || ! SpfParser::isHostname($expanded)) {
                    continue;
                }

                $matches = match ($template['type']) {
                    'exists' => (bool) ($dns['exists'][$expanded] ?? false),
                    'ptr' => $this->ptrMatches($expanded, $dns['ptr'][$ip] ?? []),
                    'a', 'mx' => $this->addressMatches(
                        $ip,
                        $dns['addresses'][$template['type'].':'.$expanded] ?? [],
                        $template
                    ),
                    default => false,
                };

                if (! $matches) {
                    continue;
                }

                foreach ($hostEdges[$signature] ?? [] as $edge) {
                    $key = $ip.'|'.$edge['root'].'|'.$edge['include'];
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $rows[] = [
                        'root_id' => $edge['root_id'],
                        'matched_ip' => $ip,
                        'root' => $edge['root'],
                        'include' => $edge['include'],
                        'network' => SpfParser::formatDnsCheck($template),
                        'record' => $edge['record'],
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, array{type: string, domain: string, prefix4: ?int, prefix6: ?int}>  $templates
     * @param  array<int, string>  $ips
     * @return array{exists: array<string, bool>, addresses: array<string, array<int, string>>, ptr: array<string, array<int, string>>}
     */
    private function resolveLookups(array $templates, array $ips): array
    {
        $existsNames = [];
        $addressKeys = [];
        $ptrIps = [];

        foreach ($templates as $template) {
            foreach ($ips as $ip) {
                $expanded = SpfParser::expandForIp($template['domain'], $ip);
                if ($expanded === null || ! SpfParser::isHostname($expanded)) {
                    continue;
                }

                match ($template['type']) {
                    'exists' => $existsNames[$expanded] = true,
                    'ptr' => $ptrIps[$ip] = true,
                    'a', 'mx' => $addressKeys[$template['type'].':'.$expanded] = $template['type'],
                    default => null,
                };
            }
        }

        $exists = [];
        foreach (array_keys($existsNames) as $name) {
            $exists[$name] = $this->records($name, DNS_A) !== [];
        }

        $addresses = [];
        foreach ($addressKeys as $key => $type) {
            $name = substr($key, strlen($type) + 1);
            $addresses[$key] = $type === 'mx' ? $this->mxAddresses($name) : $this->addresses($name);
        }

        $ptr = [];
        foreach (array_keys($ptrIps) as $ip) {
            $ptr[$ip] = $this->validatedPtr($ip);
        }

        return compact('exists', 'addresses', 'ptr');
    }

    /** @return array<int, string> */
    private function addresses(string $name): array
    {
        $addresses = [];
        foreach ([DNS_A, DNS_AAAA] as $type) {
            foreach ($this->records($name, $type) as $record) {
                foreach (['ip', 'ipv6'] as $key) {
                    $ip = (string) ($record[$key] ?? '');
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        $addresses[$ip] = true;
                    }
                }
            }
        }

        return array_keys($addresses);
    }

    /** @return array<int, string> */
    private function mxAddresses(string $name): array
    {
        $addresses = [];
        foreach ($this->records($name, DNS_MX) as $record) {
            $host = SpfParser::mxTarget((string) ($record['target'] ?? ''));
            if ($host === null) {
                continue;
            }
            foreach ($this->addresses($host) as $ip) {
                $addresses[$ip] = true;
            }
        }

        return array_keys($addresses);
    }

    /** @return array<int, string> */
    private function validatedPtr(string $ip): array
    {
        $names = [];
        foreach ($this->records($ip, DNS_PTR) as $record) {
            $name = SpfParser::normalizeDomain(rtrim((string) ($record['target'] ?? ''), '.'));
            if (SpfParser::isHostname($name)) {
                $names[$name] = true;
            }
        }

        $validated = [];
        foreach (array_keys($names) as $name) {
            if (in_array($ip, $this->addresses($name), true)) {
                $validated[] = $name;
            }
        }

        return $validated;
    }

    /** @return array<int, array<string, mixed>> */
    private function records(string $name, int $type): array
    {
        try {
            $records = $this->dnsLookup !== null
                ? ($this->dnsLookup)($name, $type)
                : (@dns_get_record($name, $type) ?: []);
        } catch (Throwable) {
            return [];
        }

        return is_array($records) ? $records : [];
    }

    /** @param array<int, string> $names */
    private function ptrMatches(string $target, array $names): bool
    {
        foreach ($names as $name) {
            if (SpfParser::ptrNameMatches($name, $target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $resolved
     * @param  array{prefix4: ?int, prefix6: ?int}  $template
     */
    private function addressMatches(string $ip, array $resolved, array $template): bool
    {
        foreach ($resolved as $resolvedIp) {
            $cidr = SpfParser::ipToCidr($resolvedIp, $template['prefix4'], $template['prefix6']);
            if ($cidr !== null && SpfParser::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }
}
