<?php

namespace App\Services\Infrastructure;

use RuntimeException;

class DmsBulkInstallDnsRecordPlanner
{
    /**
     * Reconstructs the record set passed to DMS's domain provider without
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

            if ($options['tracking'] && $entry['main']) {
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
            $policy = 'v=spf1 '.implode(' ', $spfAddresses).' -all';
            $records[] = $this->record('@', $policy, 'TXT');
            $records[] = $this->record('*', $policy, 'TXT');
        }

        foreach ($externalSpfAddresses as $hostname => $addresses) {
            $records[] = $this->record($hostname, 'v=spf1 '.implode(' ', $addresses).' -all', 'TXT');
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
