<?php

namespace App\Services\Infrastructure;

use RuntimeException;

class AlphaLinkMultiDomainDnsVerifier
{
    public function __construct(private readonly AlphaLinkPublicDnsRecordResolver $dns) {}

    /** @param list<array<string, mixed>> $assignments */
    public function verify(array $assignments, array $options): void
    {
        $this->verifyForwardAddresses($assignments);
        $this->verifyMailAuthentication($assignments, $options);
    }

    /** @param list<array<string, mixed>> $assignments */
    public function verifyForwardAddresses(array $assignments): void
    {
        if ($assignments === []) {
            throw new RuntimeException('Multi-domain DNS verification requires at least one assignment.');
        }

        foreach ($assignments as $assignment) {
            [$hostname, $trackingHost, $ip, $ehlo] = $this->validatedIdentity($assignment);
            $this->assertAddress($hostname, $ip);
            $this->assertAddress($trackingHost, $ip);
            $this->assertAddress($ehlo, $ip);
        }
    }

    /** @param list<array<string, mixed>> $assignments */
    public function verifyMailAuthentication(array $assignments, array $options): void
    {
        if ($assignments === [] || ! ($options['spf'] ?? false) || ! ($options['dkim'] ?? false) || ! ($options['dmarc'] ?? false)) {
            throw new RuntimeException('Multi-domain authentication requires at least one assignment with SPF, DKIM, and DMARC enabled.');
        }

        foreach ($assignments as $assignment) {
            [$hostname, , $ip] = $this->validatedIdentity($assignment);

            $spfRecords = $this->txt($hostname);
            $spf = array_values(array_filter($spfRecords, static fn (string $record): bool => preg_match('/\Av=spf1(?:\s|$)/i', trim($record)) === 1));
            if (count($spf) !== 1 || ! $this->spfAuthorizes($spf[0], $ip)) {
                throw new RuntimeException('SPF did not resolve with exactly one record authorizing '.$ip.' for '.$hostname.'.');
            }

            $selector = (string) ($assignment['dkim_selector'] ?? '');
            $publicRecord = (string) ($assignment['dkim_public_record'] ?? '');
            if (! preg_match('/\A[A-Za-z0-9_-]{1,63}\z/', $selector)
                || ! str_starts_with(strtolower($publicRecord), 'v=dkim1;')) {
                throw new RuntimeException('A selected domain is missing its DKIM identity.');
            }
            $dkimName = $selector.'._domainkey.'.$hostname;
            if (! in_array($this->normalizeTxt($publicRecord), array_map($this->normalizeTxt(...), $this->txt($dkimName)), true)) {
                throw new RuntimeException('DKIM public key did not resolve for '.$hostname.'.');
            }

            $dmarc = array_values(array_filter($this->txt('_dmarc.'.$hostname), static fn (string $record): bool => preg_match('/\Av=dmarc1(?:\s*;|$)/i', trim($record)) === 1));
            if (count($dmarc) !== 1) {
                throw new RuntimeException('DMARC did not resolve with exactly one policy for '.$hostname.'.');
            }

            if (! empty($options['imap'])) {
                $mx = $this->dns->records($hostname, DNS_MX);
                if (! collect($mx)->contains(fn (array $record): bool => strcasecmp(rtrim((string) ($record['target'] ?? ''), '.'), $hostname) === 0)) {
                    throw new RuntimeException('The configured MX record did not resolve for '.$hostname.'.');
                }
            }
        }
    }

    /** @param array<string, mixed> $assignment
     * @return array{string, string, string, string}
     */
    private function validatedIdentity(array $assignment): array
    {
        $hostname = strtolower(rtrim((string) ($assignment['hostname'] ?? ''), '.'));
        $trackingHost = strtolower(rtrim((string) ($assignment['tracking_host'] ?? ''), '.'));
        $ip = (string) ($assignment['ip'] ?? '');
        $ehlo = strtolower(rtrim((string) ($assignment['ehlo_hostname'] ?? ''), '.'));
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)
            || filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || filter_var($trackingHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || filter_var($ehlo, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new RuntimeException('A multi-domain DNS verification input is invalid.');
        }

        return [$hostname, $trackingHost, $ip, $ehlo];
    }

    private function assertAddress(string $hostname, string $expectedIp): void
    {
        $type = filter_var($expectedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? DNS_A : DNS_AAAA;
        $key = $type === DNS_A ? 'ip' : 'ipv6';
        $addresses = array_map(static fn (array $record): string => (string) ($record[$key] ?? ''), $this->dns->records($hostname, $type));
        if (! in_array($expectedIp, $addresses, true)) {
            throw new RuntimeException('A/AAAA did not resolve to the selected physical IP for '.$hostname.'.');
        }
    }

    /** @return list<string> */
    private function txt(string $hostname): array
    {
        return array_values(array_filter(array_map(
            static fn (array $record): string => (string) ($record['txt'] ?? implode('', $record['entries'] ?? [])),
            $this->dns->records($hostname, DNS_TXT),
        )));
    }

    private function spfAuthorizes(string $spf, string $ip): bool
    {
        $mechanism = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'ip4:' : 'ip6:';
        $escaped = preg_quote($mechanism.$ip, '/');

        return preg_match('/(?:^|\s)[+?~-]?'.$escaped.'(?=\s|$)/i', $spf) === 1;
    }

    private function normalizeTxt(string $record): string
    {
        return strtolower(preg_replace('/\s+/', '', trim($record)) ?? '');
    }
}
