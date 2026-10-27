<?php

namespace App\Services\Infrastructure;

use InvalidArgumentException;

class DmsBulkInstallIpPlanner
{
    public function __construct(private readonly DmsBulkInstallSubdomainGenerator $subdomainGenerator = new DmsBulkInstallSubdomainGenerator) {}

    /**
     * Reconstructs DMS get_ips ordering and ServerInstallBulk::format_ips output.
     * The default path uses DMS's random alias picker; tests can inject sampled
     * values to make downstream DNS and VMTA output deterministic.
     *
     * @param  list<string>  $discoveredIps
     * @param  list<string>  $generatedSubdomains
     * @return list<array{main: bool, ip: string, type: string, domain_id: int|string, sub: string, domain: string}>
     */
    public function format(
        string $serverIp,
        int|string $domainId,
        string $domain,
        array $discoveredIps,
        ?array $generatedSubdomains = null,
    ): array {
        $ips = $this->orderDiscoveredIps($discoveredIps);
        $generatedSubdomains ??= $this->subdomainGenerator->generate(count($ips));

        if (count($generatedSubdomains) !== count($ips)) {
            throw new InvalidArgumentException('DMS generates one random subdomain value per discovered IP.');
        }

        $formattedIps = [];

        foreach ($ips as $index => $ip) {
            $isMainIp = $serverIp === $ip;
            $subdomain = $isMainIp || $index === 0 ? '' : $generatedSubdomains[$index];

            if (! is_string($subdomain)) {
                throw new InvalidArgumentException('Generated subdomain values must be strings.');
            }

            $formattedIps[] = [
                'main' => $isMainIp,
                'ip' => $ip,
                'type' => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 'ipv4' : 'ipv6',
                'domain_id' => $domainId,
                'sub' => $subdomain,
                'domain' => $domain,
            ];
        }

        return $formattedIps;
    }

    /**
     * DMS removes duplicate command output, sorts IPv4 before IPv6, then sorts
     * addresses within each family by their packed binary representation.
     *
     * @param  list<string>  $discoveredIps
     * @return list<string>
     */
    public function orderDiscoveredIps(array $discoveredIps): array
    {
        foreach ($discoveredIps as $ip) {
            if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException('Discovered server addresses must be valid IPv4 or IPv6 values.');
            }
        }

        $ips = array_values(array_unique($discoveredIps));

        usort($ips, static function (string $left, string $right): int {
            $leftIsIpv4 = filter_var($left, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $rightIsIpv4 = filter_var($right, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;

            if ($leftIsIpv4 !== $rightIsIpv4) {
                return $leftIsIpv4 ? -1 : 1;
            }

            return strcmp(inet_pton($left), inet_pton($right));
        });

        return $ips;
    }
}
