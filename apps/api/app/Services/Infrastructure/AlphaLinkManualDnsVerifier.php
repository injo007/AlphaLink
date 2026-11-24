<?php

namespace App\Services\Infrastructure;

/** Checks manually managed host A/AAAA records without contacting a provider API. */
class AlphaLinkManualDnsVerifier
{
    public function __construct(private readonly AlphaLinkPublicDnsRecordResolver $dns) {}

    /** @param list<array{hostname: string, ip: string}> $records */
    public function verifies(array $records): bool
    {
        if ($records === []) {
            return false;
        }

        foreach ($records as $record) {
            $hostname = strtolower(rtrim(trim((string) ($record['hostname'] ?? '')), '.'));
            $ip = trim((string) ($record['ip'] ?? ''));
            if (filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
                || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }

            $type = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? DNS_A : DNS_AAAA;
            $key = $type === DNS_A ? 'ip' : 'ipv6';
            $addresses = array_map(
                static fn (array $answer): string => (string) ($answer[$key] ?? ''),
                $this->dns->records($hostname, $type),
            );
            if (! in_array($ip, $addresses, true)) {
                return false;
            }
        }

        return true;
    }
}
