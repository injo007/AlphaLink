<?php

namespace App\Services\Infrastructure;

use InvalidArgumentException;

class AlphaLinkBulkInstallIpPlanner
{
    public function __construct(private readonly AlphaLinkBulkInstallSubdomainGenerator $subdomainGenerator = new AlphaLinkBulkInstallSubdomainGenerator) {}

    /**
     * Orders discovered IPs and assigns generated subdomains for installation.
     * Tests can inject subdomain values to make DNS and VMTA output deterministic.
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
            throw new InvalidArgumentException('A random subdomain value is generated for each discovered IP.');
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
     * AlphaLink removes duplicate command output, sorts IPv4 before IPv6, then sorts
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
