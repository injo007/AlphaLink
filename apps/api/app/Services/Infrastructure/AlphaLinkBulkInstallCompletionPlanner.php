<?php

namespace App\Services\Infrastructure;

use InvalidArgumentException;

/**
 * Describes the database writes AlphaLink performs after a successful server install.
 * The returned operations are data only; this class has no persistence adapter.
 */
class AlphaLinkBulkInstallCompletionPlanner
{
    /**
     * @param  list<array{main: bool, ip: string, type: string, domain_id?: int|string, sub: string, domain: string, is_our?: bool, domains?: list<array{domain_id: int|string, sub: string, domain: string, is_our?: bool}>}>  $dataIps
     * @param  array{tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool}  $options
     * @return list<array<string, mixed>>
     */
    public function plan(
        int|string $serverId,
        int|string $domainId,
        bool $isOwnedDomain,
        array $dataIps,
        int $pmtaVersion,
        array $options,
        bool $pmtaMonitorHttps = true,
        ?string $trackingHost = null,
        bool $trackingHttpsReady = false,
    ): array {
        $this->validateRows($dataIps);
        $this->validateOptions($options);

        $operations = [
            ['action' => 'delete_server_mtas', 'server_id' => $serverId],
            ['action' => 'delete_server_ips', 'server_id' => $serverId],
        ];

        $ownedDomainIds = [];
        $seenIps = [];
        foreach ($dataIps as $ip) {
            if (isset($seenIps[$ip['ip']])) {
                throw new InvalidArgumentException('Each physical IP may appear only once in installation completion.');
            }
            $seenIps[$ip['ip']] = true;
            $rowDomainId = $ip['domain_id'] ?? $domainId;
            $rowIsOwnedDomain = (bool) ($ip['is_our'] ?? $isOwnedDomain);
            $sub = trim((string) ($ip['sub'] ?? ''));
            $dns = $sub !== '' ? $sub.'.'.$ip['domain'] : $ip['domain'];
            $seenHostnames = [strtolower($dns) => true];

            $operations[] = [
                'action' => 'insert_server_ip',
                'ip' => $ip['ip'],
                'type' => $ip['type'],
                'dns' => $dns,
                ...(isset($ip['ehlo_hostname']) ? ['ehlo_hostname' => $ip['ehlo_hostname']] : []),
                ...(isset($ip['tracking_host']) ? ['tracking_host' => $ip['tracking_host']] : []),
                ...$this->completionIdentityFields($ip),
                'domain_id' => $rowDomainId,
                'server_id' => $serverId,
                'is_active' => true,
            ];

            if ($rowIsOwnedDomain) {
                $ownedDomainIds[(string) $rowDomainId] = $rowDomainId;
            }

            foreach ($ip['domains'] ?? [] as $assignment) {
                $assignmentSub = trim($assignment['sub']);
                $hostname = $assignmentSub !== ''
                    ? $assignmentSub.'.'.$assignment['domain']
                    : $assignment['domain'];
                if (isset($seenHostnames[strtolower($hostname)])) {
                    throw new InvalidArgumentException('The hostname '.$hostname.' is assigned more than once to the same physical IP. Give each sending assignment a unique subdomain.');
                }
                $seenHostnames[strtolower($hostname)] = true;
                $operations[] = [
                    'action' => 'insert_server_ip_domain',
                    'ip' => $ip['ip'],
                    'domain_id' => $assignment['domain_id'],
                    'hostname' => $hostname,
                    ...(isset($assignment['tracking_host']) ? ['tracking_host' => $assignment['tracking_host']] : []),
                    ...$this->completionIdentityFields($assignment),
                    'server_id' => $serverId,
                    'is_active' => true,
                ];
                if ((bool) ($assignment['is_our'] ?? $isOwnedDomain)) {
                    $ownedDomainIds[(string) $assignment['domain_id']] = $assignment['domain_id'];
                }
            }
        }

        foreach ($ownedDomainIds as $ownedDomainId) {
            $operations[] = [
                'action' => 'mark_domain_used',
                'domain_id' => $ownedDomainId,
                'status' => 'used',
            ];
        }

        $installParameters = [
            'data' => $dataIps,
            'pmta_version' => $pmtaVersion,
        ];

        foreach (['tracking', 'proxy', 'imap', 'spf', 'dkim', 'dmarc', 'https'] as $option) {
            $installParameters[$option] = $options[$option];
        }
        $installParameters['tracking_https_ready'] = $options['tracking'] && $trackingHttpsReady;
        // PMTA monitor TLS is verified independently from the optional
        // tracking HTTPS component. It can be disabled when no DNS-backed
        // hostname/certificate is available and the monitor uses the server IP.
        $installParameters['pmta_monitor_https'] = $pmtaMonitorHttps;
        if ($trackingHost !== null && $trackingHost !== '') {
            $installParameters['tracking_host'] = $trackingHost;
        }

        $operations[] = [
            'action' => 'save_installation_config',
            'server_id' => $serverId,
            'install_parameters' => $installParameters,
        ];
        $operations[] = [
            'action' => 'mark_server_installed',
            'server_id' => $serverId,
            'is_installed' => true,
        ];

        return $operations;
    }

    /** @param list<array<string, mixed>> $dataIps */
    private function validateRows(array $dataIps): void
    {
        foreach ($dataIps as $ip) {
            if (
                ! is_array($ip)
                || ! isset($ip['ip'], $ip['type'], $ip['domain'])
                || ! is_string($ip['ip'])
                || ! is_string($ip['type'])
                || ! is_string($ip['domain'])
                || ! array_key_exists('sub', $ip)
                || ! is_string($ip['sub'])
                || (isset($ip['domain_id']) && ! is_string($ip['domain_id']) && ! is_int($ip['domain_id']))
                || (isset($ip['is_our']) && ! is_bool($ip['is_our']))
                || (isset($ip['domains']) && (! is_array($ip['domains']) || ! array_is_list($ip['domains'])))
                || (isset($ip['ehlo_hostname']) && (filter_var($ip['ehlo_hostname'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false))
            ) {
                throw new InvalidArgumentException('Installation completion requires formatted IP rows.');
            }
            $primaryHostname = strtolower(($ip['sub'] !== '' ? $ip['sub'].'.' : '').$ip['domain']);
            $this->assertTrackingHost($ip['tracking_host'] ?? null, $primaryHostname);
            $this->validateCompletionIdentity($ip);
            foreach ($ip['domains'] ?? [] as $assignment) {
                if (! is_array($assignment)
                    || ! isset($assignment['domain_id'], $assignment['domain'], $assignment['sub'])
                    || (! is_string($assignment['domain_id']) && ! is_int($assignment['domain_id']))
                    || ! is_string($assignment['domain'])
                    || ! is_string($assignment['sub'])
                    || (isset($assignment['is_our']) && ! is_bool($assignment['is_our']))) {
                    throw new InvalidArgumentException('Installation completion requires valid domain assignments.');
                }
                $hostname = strtolower(($assignment['sub'] !== '' ? $assignment['sub'].'.' : '').$assignment['domain']);
                $this->assertTrackingHost($assignment['tracking_host'] ?? null, $hostname);
                $this->validateCompletionIdentity($assignment);
            }
        }
    }

    /** @param array<string, mixed> $assignment */
    private function validateCompletionIdentity(array $assignment): void
    {
        $fields = ['sending_domain_dkim_identity_id', 'dkim_selector', 'dns_verified', 'authentication_verified', 'tracking_https_ready'];
        $present = array_filter($fields, static fn (string $field): bool => array_key_exists($field, $assignment));
        if ($present === []) {
            return;
        }
        if (count($present) !== count($fields)
            || ! (is_string($assignment['sending_domain_dkim_identity_id']) || is_int($assignment['sending_domain_dkim_identity_id']))
            || trim((string) $assignment['sending_domain_dkim_identity_id']) === ''
            || ! is_string($assignment['dkim_selector'])
            || preg_match('/\A[A-Za-z0-9_-]{1,63}\z/', $assignment['dkim_selector']) !== 1
            || ! is_bool($assignment['dns_verified'])
            || ! is_bool($assignment['authentication_verified'])
            || ! is_bool($assignment['tracking_https_ready'])) {
            throw new InvalidArgumentException('A domain assignment has incomplete DKIM and verification metadata.');
        }
        if (($assignment['authentication_verified'] || $assignment['tracking_https_ready']) && ! $assignment['dns_verified']) {
            throw new InvalidArgumentException('Authentication and tracking cannot be marked ready before DNS is verified.');
        }
    }

    /** @param array<string, mixed> $assignment
     * @return array<string, mixed>
     */
    private function completionIdentityFields(array $assignment): array
    {
        if (! array_key_exists('sending_domain_dkim_identity_id', $assignment)) {
            return [];
        }

        return [
            'sending_domain_dkim_identity_id' => $assignment['sending_domain_dkim_identity_id'],
            'dkim_selector' => $assignment['dkim_selector'],
            'dns_verified' => $assignment['dns_verified'],
            'authentication_verified' => $assignment['authentication_verified'],
            'tracking_https_ready' => $assignment['tracking_https_ready'],
        ];
    }

    private function assertTrackingHost(mixed $trackingHost, string $sendingHostname): void
    {
        if ($trackingHost === null) {
            return;
        }
        if (! is_string($trackingHost) || filter_var($trackingHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || ! str_ends_with(strtolower($trackingHost), '.'.$sendingHostname)) {
            throw new InvalidArgumentException('An assignment tracking host must be a separate hostname below its sending domain.');
        }
    }

    /** @param array<string, mixed> $options */
    private function validateOptions(array $options): void
    {
        unset($options['clean_dns']);
        $expected = ['tracking', 'proxy', 'imap', 'spf', 'dkim', 'dmarc', 'https'];
        $keys = array_keys($options);
        sort($keys);
        $sortedExpected = $expected;
        sort($sortedExpected);

        if ($keys !== $sortedExpected) {
            throw new InvalidArgumentException('Installation completion requires all seven options.');
        }

        foreach ($options as $option) {
            if (! is_bool($option)) {
                throw new InvalidArgumentException('Installation completion options must be booleans.');
            }
        }
    }
}
