<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class AlphaLinkBulkInstallRowProcessPayload
{
    public const RESULT_SUCCEEDED = 'ALPHALINK_BULK_INSTALL_ROW_SUCCEEDED';

    public const RESULT_FAILED = 'ALPHALINK_BULK_INSTALL_ROW_FAILED';

    /**
     * @param  array{pmta_version: int, tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool}  $options
     */
    public function encode(
        string $workspaceId,
        string $userId,
        string $serverId,
        string $domainId,
        string $runId,
        array $options,
        ?array $ipAssignments = null,
        bool $multiDomainPerIp = false,
    ): string {
        $workerPayload = [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'server_id' => $serverId,
            'domain_id' => $domainId,
            'run_id' => $runId,
            'multi_domain_per_ip' => $multiDomainPerIp,
            ...$options,
        ];
        if ($ipAssignments !== null) {
            $workerPayload['ip_assignments'] = $ipAssignments;
        }

        $payload = json_encode($workerPayload, JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * @return array{workspace_id: string, user_id: string, server_id: string, domain_id: string, run_id: string, pmta_version: int, tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool, multi_domain_per_ip: bool, ip_assignments?: list<array{ip: string, type: string, main: bool, sub: string, domain_id: string, domains?: list<array{domain_id: string, sub: string}>}>}
     */
    public function decode(string $encoded): array
    {
        if ($encoded === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $encoded)) {
            throw new InvalidArgumentException('The bulk-install worker payload is invalid.');
        }

        $padding = str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode(strtr($encoded, '-_', '+/').$padding, true);
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;

        if (! is_array($payload)) {
            throw new InvalidArgumentException('The bulk-install worker payload is invalid.');
        }

        $validated = Validator::make($payload, [
            'workspace_id' => ['required', 'uuid'],
            'user_id' => ['required', 'string', 'max:255'],
            'server_id' => ['required', 'string', 'max:255'],
            'domain_id' => ['required', 'string', 'max:255'],
            'run_id' => ['required', 'uuid'],
            'pmta_version' => ['required', 'integer', Rule::in([40, 45, 50])],
            'tracking' => ['required', 'boolean'],
            'proxy' => ['required', 'boolean'],
            'imap' => ['required', 'boolean'],
            'spf' => ['required', 'boolean'],
            'dkim' => ['required', 'boolean'],
            'dmarc' => ['required', 'boolean'],
            'https' => ['required', 'boolean'],
            'multi_domain_per_ip' => ['sometimes', 'boolean'],
            'ip_assignments' => ['sometimes', 'array', 'min:1', 'max:256'],
            'ip_assignments.*.ip' => ['required', 'ip'],
            'ip_assignments.*.type' => ['required', 'string', Rule::in(['ipv4', 'ipv6'])],
            'ip_assignments.*.main' => ['required', 'boolean'],
            'ip_assignments.*.sub' => ['present', 'nullable', 'string', 'max:63'],
            'ip_assignments.*.domain_id' => ['required', 'uuid'],
            'ip_assignments.*.domains' => ['sometimes', 'array', 'max:32'],
            'ip_assignments.*.domains.*.domain_id' => ['required', 'uuid'],
            'ip_assignments.*.domains.*.sub' => ['present', 'nullable', 'string', 'max:63'],
        ])->validate();

        $multiDomainPerIp = (bool) ($validated['multi_domain_per_ip'] ?? false);
        $additionalDomainCount = 0;
        $additionalCountsByIp = [];
        $mainIpCount = 0;
        foreach ($validated['ip_assignments'] ?? [] as $assignment) {
            $mainIpCount += (((bool) $assignment['main']) ? 1 : 0);
            $additionalCountsByIp[] = count($assignment['domains'] ?? []);
            if (! $multiDomainPerIp && ! empty($assignment['domains'])) {
                throw new InvalidArgumentException('Multi-Domain per IP is required for additional domain assignments.');
            }
            $additionalDomainCount += count($assignment['domains'] ?? []);
        }
        if ($multiDomainPerIp && $additionalDomainCount === 0) {
            throw new InvalidArgumentException('Multi-Domain per IP requires at least one additional domain assignment.');
        }
        if ($multiDomainPerIp && ($mainIpCount !== 1 || count(array_unique($additionalCountsByIp)) !== 1 || ($additionalCountsByIp[0] ?? 0) < 1)) {
            throw new InvalidArgumentException('Multi-Domain per IP requires one main IP and the same additional domain count on every IP.');
        }
        if ($multiDomainPerIp && count($validated['ip_assignments'] ?? []) * (1 + ($additionalCountsByIp[0] ?? 0)) > 100) {
            throw new InvalidArgumentException('A server can have at most 100 HTTPS tracking hostnames on its current certificate.');
        }

        $normalized = [
            'workspace_id' => $validated['workspace_id'],
            'user_id' => $validated['user_id'],
            'server_id' => $validated['server_id'],
            'domain_id' => $validated['domain_id'],
            'run_id' => $validated['run_id'],
            'pmta_version' => (int) $validated['pmta_version'],
            'tracking' => (bool) $validated['tracking'],
            'proxy' => (bool) $validated['proxy'],
            'imap' => (bool) $validated['imap'],
            'spf' => (bool) $validated['spf'],
            'dkim' => (bool) $validated['dkim'],
            'dmarc' => (bool) $validated['dmarc'],
            'https' => (bool) $validated['https'],
            'multi_domain_per_ip' => $multiDomainPerIp,
        ];
        if (isset($validated['ip_assignments'])) {
            $normalized['ip_assignments'] = array_map(
                static function (array $assignment): array {
                    $normalizedAssignment = [
                        'ip' => $assignment['ip'],
                        'type' => $assignment['type'],
                        'main' => (bool) $assignment['main'],
                        'sub' => $assignment['sub'] ?? '',
                        'domain_id' => $assignment['domain_id'],
                    ];
                    if (isset($assignment['domains'])) {
                        $normalizedAssignment['domains'] = array_map(
                            static fn (array $domain): array => [
                                'domain_id' => $domain['domain_id'],
                                'sub' => $domain['sub'] ?? '',
                            ],
                            $assignment['domains'],
                        );
                    }

                    return $normalizedAssignment;
                },
                $validated['ip_assignments'],
            );
        }

        return $normalized;
    }
}
