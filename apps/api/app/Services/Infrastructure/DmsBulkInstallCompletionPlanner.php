<?php

namespace App\Services\Infrastructure;

use InvalidArgumentException;

/**
 * Describes the database writes DMS performs after a successful server install.
 * The returned operations are data only; this class has no persistence adapter.
 */
class DmsBulkInstallCompletionPlanner
{
    /**
     * @param  list<array{main: bool, ip: string, type: string, domain_id?: int|string, sub: string, domain: string, is_our?: bool}>  $dataIps
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
    ): array {
        $this->validateRows($dataIps);
        $this->validateOptions($options);

        $operations = [
            ['action' => 'delete_server_mtas', 'server_id' => $serverId],
            ['action' => 'delete_server_ips', 'server_id' => $serverId],
        ];

        $ownedDomainIds = [];
        foreach ($dataIps as $ip) {
            $rowDomainId = $ip['domain_id'] ?? $domainId;
            $rowIsOwnedDomain = (bool) ($ip['is_our'] ?? $isOwnedDomain);
            $dns = $rowIsOwnedDomain
                ? ($ip['sub'] === '' ? $ip['domain'] : $ip['sub'].'.'.$ip['domain'])
                : $ip['domain'];

            $operations[] = [
                'action' => 'insert_server_ip',
                'ip' => $ip['ip'],
                'type' => $ip['type'],
                'dns' => $dns,
                'domain_id' => $rowDomainId,
                'server_id' => $serverId,
                'is_active' => true,
            ];

            if ($rowIsOwnedDomain) {
                $ownedDomainIds[(string) $rowDomainId] = $rowDomainId;
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
        // Completion is only persisted after the remote PMTA TLS readiness
        // probe succeeds, so this is a verified endpoint capability.
        $installParameters['pmta_monitor_https'] = $options['https'];

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
            ) {
                throw new InvalidArgumentException('DMS install completion requires formatted IP rows.');
            }
        }
    }

    /** @param array<string, mixed> $options */
    private function validateOptions(array $options): void
    {
        $expected = ['tracking', 'proxy', 'imap', 'spf', 'dkim', 'dmarc', 'https'];
        $keys = array_keys($options);
        sort($keys);
        $sortedExpected = $expected;
        sort($sortedExpected);

        if ($keys !== $sortedExpected) {
            throw new InvalidArgumentException('DMS install completion requires all seven install options.');
        }

        foreach ($options as $option) {
            if (! is_bool($option)) {
                throw new InvalidArgumentException('DMS install completion options must be booleans.');
            }
        }
    }
}
