<?php

namespace App\Services\Infrastructure;

use InvalidArgumentException;

class AlphaLinkMultiDomainInstallPlan
{
    /**
     * @param  list<array<string, mixed>>  $ipRows
     * @return array{ips: list<array<string, mixed>>, assignments: list<array<string, mixed>>, dns_groups: list<array{domain_id: string, domain: string, assignments: list<array<string, mixed>>}>, tracking_hosts: list<string>}
     */
    public function build(array $ipRows): array
    {
        if ($ipRows === []) {
            throw new InvalidArgumentException('Multi-domain installation requires at least one physical IP.');
        }

        $ips = [];
        $assignments = [];
        $dnsGroups = [];
        $trackingHosts = [];
        $seenIps = [];
        $mainCount = 0;
        $additionalCount = 0;
        foreach ($ipRows as $row) {
            if (! is_array($row) || ! is_string($row['ip'] ?? null)
                || filter_var($row['ip'], FILTER_VALIDATE_IP) === false
                || ! is_bool($row['main'] ?? null)
                || ! is_array($row['domains'] ?? null)) {
                throw new InvalidArgumentException('A multi-domain IP row is invalid.');
            }
            $ip = $row['ip'];
            if (isset($seenIps[$ip])) {
                throw new InvalidArgumentException('A physical IP may appear only once in multi-domain provisioning.');
            }
            $seenIps[$ip] = true;
            $mainCount += $row['main'] ? 1 : 0;
            $additionalCount += count($row['domains']);
            $identities = [[...$row, 'is_primary' => true]];
            foreach ($row['domains'] as $additional) {
                if (! is_array($additional)) {
                    throw new InvalidArgumentException('A multi-domain assignment is invalid.');
                }
                $identities[] = [...$additional, 'is_primary' => false];
            }

            $seenHostnames = [];
            $ehloHostname = null;
            $rowTrackingHost = null;
            $additionalRows = [];
            foreach ($identities as $index => $identity) {
                $hostname = $this->hostname($identity);
                if (isset($seenHostnames[$hostname])) {
                    throw new InvalidArgumentException('A sending hostname may appear only once on each physical IP.');
                }
                $seenHostnames[$hostname] = true;
                $ehloHostname ??= $hostname;
                $trackingHost = 'track-'.substr(hash('sha256', $ip), 0, 10).'.'.$hostname;
                if (strlen($trackingHost) > 253 || filter_var($trackingHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                    throw new InvalidArgumentException('An assignment tracking hostname is too long or invalid.');
                }
                $domainId = (string) $identity['domain_id'];
                $assignment = [
                    'ip' => $ip,
                    'hostname' => $hostname,
                    'domain_id' => $domainId,
                    'domain' => strtolower(rtrim($identity['domain'], '.')),
                    'sub' => trim($identity['sub']),
                    'is_primary' => $identity['is_primary'],
                    'tracking_host' => $trackingHost,
                ];
                $assignments[] = $assignment;
                if ($index === 0) {
                    $rowTrackingHost = $trackingHost;
                } else {
                    $additionalRows[] = [...$row['domains'][$index - 1], 'tracking_host' => $trackingHost];
                }
                $trackingHosts[$trackingHost] = $trackingHost;
                if (isset($dnsGroups[$domainId]) && $dnsGroups[$domainId]['domain'] !== $assignment['domain']) {
                    throw new InvalidArgumentException('A selected domain ID has conflicting names.');
                }
                $dnsGroups[$domainId] ??= ['domain_id' => $domainId, 'domain' => $assignment['domain'], 'assignments' => []];
                $dnsGroups[$domainId]['assignments'][] = $assignment;
            }
            if (isset($row['ehlo_hostname']) && strcasecmp((string) $row['ehlo_hostname'], $ehloHostname) !== 0) {
                throw new InvalidArgumentException('The physical IP EHLO hostname conflicts with its primary identity.');
            }
            $ips[] = [...$row, 'ehlo_hostname' => $ehloHostname, 'tracking_host' => $rowTrackingHost, 'domains' => $additionalRows];
        }
        if ($mainCount !== 1 || $additionalCount < 1) {
            throw new InvalidArgumentException('Multi-domain installation requires one main IP and at least one additional domain assignment.');
        }
        if (count($assignments) > 100) {
            throw new InvalidArgumentException('This server can use at most 100 HTTPS tracking hostnames on its current certificate. Reduce the IP or domain count and retry.');
        }

        return [
            'ips' => $ips,
            'assignments' => $assignments,
            'dns_groups' => array_values($dnsGroups),
            'tracking_hosts' => array_values($trackingHosts),
        ];
    }

    /** @param array<string, mixed> $identity */
    private function hostname(array $identity): string
    {
        if (! is_string($identity['domain_id'] ?? null) && ! is_int($identity['domain_id'] ?? null)) {
            throw new InvalidArgumentException('A selected sending domain ID is missing.');
        }
        $domain = strtolower(rtrim(trim((string) ($identity['domain'] ?? '')), '.'));
        $sub = strtolower(trim((string) ($identity['sub'] ?? '')));
        if (filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || ($sub !== '' && (strlen($sub) > 63 || preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\z/D', $sub) !== 1))) {
            throw new InvalidArgumentException('A selected sending hostname is invalid.');
        }
        $hostname = $sub === '' ? $domain : $sub.'.'.$domain;
        if (strlen($hostname) > 253 || filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException('A selected sending hostname is too long.');
        }

        return $hostname;
    }
}
