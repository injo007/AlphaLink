<?php

namespace App\Services\Infrastructure;

use RuntimeException;

class AlphaLinkBulkInstallDnsRecordPlanner
{
    /**
     * Build only records owned by the selected sending assignments in one DNS zone.
     * Unlike legacy installs, this never adds apex or wildcard records implicitly.
     *
     * @param  list<array{hostname: string, ip: string, dkim_selector: string, dkim_public_record: string, tracking_host?: string|null}>  $assignments
     * @param  list<array{hostname: string, ip: string}>  $additionalARecords
     * @param  array{tracking: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool}  $options
     * @return list<array{host: string, address: string, type: string, ttl: string}>
     */
    public function recordsForAssignments(string $zoneName, array $assignments, array $options, array $additionalARecords = []): array
    {
        $zoneName = strtolower(rtrim(trim($zoneName), '.'));
        if (filter_var($zoneName, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new RuntimeException('The selected DNS zone name is invalid.');
        }

        $records = [];
        $spfByHost = [];
        $dkimByHost = [];
        $seen = [];
        foreach ($assignments as $assignment) {
            $hostname = strtolower(rtrim(trim($assignment['hostname']), '.'));
            $host = $this->relativeHost($hostname, $zoneName);
            $ip = $assignment['ip'];
            $family = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE)
                ? 'A'
                : (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE) ? 'AAAA' : null);
            if ($family === null) {
                throw new RuntimeException('A selected sending assignment has no verified public IP for DNS records.');
            }

            $this->appendUnique($records, $seen, $host, $ip, $family);
            if ($options['tracking'] && ! empty($assignment['tracking_host'])) {
                $trackingHost = $this->relativeHost(strtolower(rtrim(trim($assignment['tracking_host']), '.')), $zoneName);
                $this->appendUnique($records, $seen, $trackingHost, $ip, $family);
            }
            if ($options['imap']) {
                $this->appendUnique($records, $seen, $host, $hostname, 'MX');
            }
            if ($options['spf']) {
                $spfByHost[$host][$family === 'A' ? 'ip4:'.$ip : 'ip6:'.$ip] = true;
            }
            if ($options['dkim']) {
                $selector = $assignment['dkim_selector'];
                $publicRecord = trim($assignment['dkim_public_record']);
                if (! preg_match('/\A[A-Za-z0-9_-]{1,63}\z/', $selector)
                    || ! str_starts_with(strtolower($publicRecord), 'v=dkim1;')) {
                    throw new RuntimeException('A selected sending assignment has no valid DKIM DNS identity.');
                }
                $dkimHost = $selector.'._domainkey'.($host === '@' ? '' : '.'.$host);
                if (isset($dkimByHost[$dkimHost]) && $dkimByHost[$dkimHost] !== $publicRecord) {
                    throw new RuntimeException('A selected DKIM selector has conflicting public keys.');
                }
                $dkimByHost[$dkimHost] = $publicRecord;
                $this->appendUnique($records, $seen, $dkimHost, $publicRecord, 'TXT');
            }
            if ($options['dmarc']) {
                $dmarcHost = '_dmarc'.($host === '@' ? '' : '.'.$host);
                $policy = 'v=DMARC1; p=none; rua=mailto:dmarc@'.$hostname;
                $this->appendUnique($records, $seen, $dmarcHost, $policy, 'TXT');
            }
        }

        foreach ($spfByHost as $host => $mechanisms) {
            $this->appendUnique($records, $seen, $host, 'v=spf1 '.implode(' ', array_keys($mechanisms)).' -all', 'TXT');
        }

        foreach ($additionalARecords as $additional) {
            $hostname = strtolower(rtrim(trim($additional['hostname']), '.'));
            $host = $this->relativeHost($hostname, $zoneName);
            $ip = (string) $additional['ip'];
            $family = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE)
                ? 'A'
                : (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE) ? 'AAAA' : null);
            if ($family === null) {
                throw new RuntimeException('An EHLO hostname has no verified public IP for DNS records.');
            }
            $this->appendUnique($records, $seen, $host, $ip, $family);
        }

        return $records;
    }

    private function relativeHost(string $hostname, string $zoneName): string
    {
        if (filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || ($hostname !== $zoneName && ! str_ends_with($hostname, '.'.$zoneName))) {
            throw new RuntimeException('A selected sending or tracking hostname is outside its DNS zone.');
        }

        return $hostname === $zoneName ? '@' : substr($hostname, 0, -strlen('.'.$zoneName));
    }

    /**
     * @param  list<array{host: string, address: string, type: string, ttl: string}>  $records
     * @param  array<string, bool>  $seen
     */
    private function appendUnique(array &$records, array &$seen, string $host, string $address, string $type): void
    {
        $key = strtolower($host).'|'.$type.'|'.$address;
        if (! isset($seen[$key])) {
            $records[] = $this->record($host, $address, $type);
            $seen[$key] = true;
        }
    }

    /**
     * Reconstructs the record set passed to AlphaLink's domain provider without
     * contacting a provider or resolving private addresses over the network.
     *
     * @param  list<array{main: bool, ip: string, type: string, sub: string, domain: string}>  $ips
     * @param  array{tracking: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool}  $options
     * @param  array<string, string>  $publicIpMappings
     * @return list<array{host: string, address: string, type: string, ttl: string}>
     */
    public function records(
        bool $isOwnedDomain,
        array $ips,
        array $options,
        string $dkimSelector = '',
        ?string $dkimPublicKey = null,
        array $publicIpMappings = [],
    ): array {
        $records = [];
        $spfAddresses = [];
        $externalSpfAddresses = [];
        $domainsWithMain = [];
        foreach ($ips as $entry) {
            if ($entry['main']) {
                $domainsWithMain[$entry['domain']] = true;
            }
        }
        $trackingWildcardsAdded = [];

        foreach ($ips as $entry) {
            $ip = $this->publicAddress($entry['ip'], $publicIpMappings);
            $isIpv4 = $entry['type'] === 'ipv4';
            if (! filter_var($ip, FILTER_VALIDATE_IP, ($isIpv4 ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6) | FILTER_FLAG_NO_PRIV_RANGE)) {
                throw new RuntimeException('A selected interface has no verified public IP for DNS records.');
            }
            $hostname = $entry['sub'] === '' ? '@' : $entry['sub'];
            $mx = $entry['sub'] === '' ? $entry['domain'] : $entry['sub'].'.'.$entry['domain'];

            // The selected domain must resolve to its selected public server IPs
            // independently of whether the optional tracking application is installed.
            $records[] = $this->record($hostname, $ip, $isIpv4 ? 'A' : 'AAAA');

            $shouldAddTrackingWildcards = $options['tracking'] && (
                $entry['main']
                || (! isset($domainsWithMain[$entry['domain']]) && ! isset($trackingWildcardsAdded[$entry['domain']]))
            );
            if ($shouldAddTrackingWildcards) {
                $trackingWildcardsAdded[$entry['domain']] = true;
                $records[] = $this->record('www', $ip, $isIpv4 ? 'A' : 'AAAA');
                $records[] = $this->record('*', $ip, $isIpv4 ? 'A' : 'AAAA');
            }

            if ($options['imap']) {
                $records[] = $this->record($hostname, $mx, 'MX');
            }

            if ($options['spf']) {
                if ($isOwnedDomain) {
                    $spfAddresses[] = ($isIpv4 ? 'ip4:' : 'ip6:').$ip;
                } else {
                    $externalSpfAddresses[$hostname][] = ($isIpv4 ? 'ip4:' : 'ip6:').$ip;
                }
            }

            if ($options['dkim'] && ! empty($dkimPublicKey)) {
                $recordHost = $dkimSelector.'._domainkey'.($entry['sub'] === '' ? '' : '.'.$entry['sub']);
                $records[] = $this->record($recordHost, trim($dkimPublicKey), 'TXT');
            }

            if ($options['dmarc']) {
                $recordHost = '_dmarc'.($entry['sub'] === '' ? '' : '.'.$entry['sub']);
                $policy = sprintf(
                    'v=DMARC1; p=none; fo=1; rua=mailto:dmarc@%1$s; ruf=mailto:dmarc@%1$s',
                    $entry['domain'],
                );
                $records[] = $this->record($recordHost, $policy, 'TXT');
            }
        }

        if ($spfAddresses !== []) {
            $policy = 'v=spf1 '.implode(' ', array_values(array_unique($spfAddresses))).' -all';
            $records[] = $this->record('@', $policy, 'TXT');
            $records[] = $this->record('*', $policy, 'TXT');
        }

        foreach ($externalSpfAddresses as $hostname => $addresses) {
            $records[] = $this->record($hostname, 'v=spf1 '.implode(' ', array_values(array_unique($addresses))).' -all', 'TXT');
        }

        return $records;
    }

    /** @param array<string, string> $publicIpMappings */
    private function publicAddress(string $ip, array $publicIpMappings): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
            return $ip;
        }

        $mappedAddress = $publicIpMappings[$ip] ?? '';

        return filter_var($mappedAddress, FILTER_VALIDATE_IP) ? $mappedAddress : $ip;
    }

    /** @return array{host: string, address: string, type: string, ttl: string} */
    private function record(string $host, string $address, string $type): array
    {
        return [
            'host' => $host,
            'address' => $address,
            'type' => $type,
            'ttl' => '60',
        ];
    }
}
