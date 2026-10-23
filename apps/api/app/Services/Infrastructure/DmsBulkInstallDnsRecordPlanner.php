<?php

namespace App\Services\Infrastructure;

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
        if (! $isOwnedDomain) {
            return [];
        }

        $records = [];
        $spfAddresses = [];

        foreach ($ips as $entry) {
            $ip = $this->publicAddress($entry['ip'], $publicIpMappings);
            $isIpv4 = $entry['type'] === 'ipv4';
            $hostname = $entry['sub'] === '' ? '@' : $entry['sub'];
            $mx = $entry['sub'] === '' ? $entry['domain'] : $entry['sub'].'.'.$entry['domain'];

            if ($options['tracking']) {
                $records[] = $this->record($hostname, $ip, $isIpv4 ? 'A' : 'AAAA');

                if ($entry['main']) {
                    $records[] = $this->record('www', $ip, $isIpv4 ? 'A' : 'AAAA');
                    $records[] = $this->record('*', $ip, $isIpv4 ? 'A' : 'AAAA');
                }
            }

            if ($options['imap']) {
                $records[] = $this->record($hostname, $mx, 'MX');
            }

            if ($options['spf']) {
                $spfAddresses[] = ($isIpv4 ? 'ip4:' : 'ip6:').$ip;
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
