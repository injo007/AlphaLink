<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reproduces the safe request contract at AlphaLink's bulk-install boundary.
 * This only validates and reduces the request; it never starts an installer.
 */
class AlphaLinkBulkInstallPayload
{
    /**
     * @return array{
     *   data: list<array{id: string, domain: string, ip_assignments?: list<array{ip: string, type: string, main: bool, sub: string, domain_id: string}>>>,
     *   key: string,
     *   pmta_version: int,
     *   tracking: bool,
     *   proxy: bool,
     *   imap: bool,
     *   spf: bool,
     *   dkim: bool,
     *   dmarc: bool,
     *   https: bool,
     *   multi_domain_per_ip: bool
     * }
     *
     * @throws ValidationException
     */
    public function validateAndNormalize(array $payload): array
    {
        $validated = Validator::make($payload, [
            'data' => ['required', 'array', 'min:1'],
            'data.*' => ['required', 'array'],
            'data.*.id' => ['required', 'string'],
            'data.*.domain' => ['required', 'string'],
            'data.*.ip_assignments' => ['sometimes', 'array', 'min:1', 'max:256'],
            'data.*.ip_assignments.*.ip' => ['required', 'ip'],
            'data.*.ip_assignments.*.type' => ['required', 'string', Rule::in(['ipv4', 'ipv6'])],
            'data.*.ip_assignments.*.main' => ['required', 'boolean'],
            'data.*.ip_assignments.*.sub' => ['present', 'nullable', 'string', 'max:63'],
            'data.*.ip_assignments.*.domain_id' => ['required', 'uuid'],
            'data.*.ip_assignments.*.domains' => ['sometimes', 'array', 'max:32'],
            'data.*.ip_assignments.*.domains.*.domain_id' => ['required', 'uuid'],
            'data.*.ip_assignments.*.domains.*.sub' => ['present', 'nullable', 'string', 'max:63'],
            'key' => ['required', 'numeric'],
            'pmta_version' => ['required', 'numeric', Rule::in([40, 45, 50])],
            'tracking' => ['required', 'boolean'],
            'proxy' => ['required', 'boolean'],
            'imap' => ['required', 'boolean'],
            'spf' => ['required', 'boolean'],
            'dkim' => ['required', 'boolean'],
            'dmarc' => ['required', 'boolean'],
            'https' => ['required', 'boolean'],
            'clean_dns' => ['sometimes', 'boolean'],
            'multi_domain_per_ip' => ['sometimes', 'boolean'],
            'domains_per_ip' => ['sometimes', 'integer', 'min:2', 'max:33'],
        ])->validate();

        $multiDomainPerIp = (bool) ($validated['multi_domain_per_ip'] ?? false);
        $domainsPerIp = (int) ($validated['domains_per_ip'] ?? 0);
        if ($multiDomainPerIp && ($domainsPerIp < 2 || $domainsPerIp > 33)) {
            throw ValidationException::withMessages([
                'domains_per_ip' => 'Choose between 2 and 33 total domain assignments per IP.',
            ]);
        }
        $additionalDomainCount = 0;
        foreach ($validated['data'] as $rowIndex => $row) {
            if ($multiDomainPerIp && ! isset($row['ip_assignments'])) {
                throw ValidationException::withMessages([
                    "data.{$rowIndex}.ip_assignments" => 'Multi-Domain per IP requires an explicit IP assignment map for each server.',
                ]);
            }
            foreach ($row['ip_assignments'] ?? [] as $ipIndex => $ipAssignment) {
                $domains = $ipAssignment['domains'] ?? [];
                if (! $multiDomainPerIp && $domains !== []) {
                    throw ValidationException::withMessages([
                        "data.{$rowIndex}.ip_assignments.{$ipIndex}.domains" => 'Enable Multi-Domain per IP before adding another sending domain to an IP.',
                    ]);
                }
                if ($multiDomainPerIp && count($domains) !== $domainsPerIp - 1) {
                    throw ValidationException::withMessages([
                        "data.{$rowIndex}.ip_assignments.{$ipIndex}.domains" => 'Every IP must have the selected number of additional domain assignments.',
                    ]);
                }
                $additionalDomainCount += count($domains);
            }
            if ($multiDomainPerIp && count($row['ip_assignments'] ?? []) * $domainsPerIp > 100) {
                throw ValidationException::withMessages([
                    "data.{$rowIndex}.ip_assignments" => 'A server can have at most 100 HTTPS tracking hostnames on its current certificate.',
                ]);
            }
        }
        if ($multiDomainPerIp && $additionalDomainCount === 0) {
            throw ValidationException::withMessages([
                'multi_domain_per_ip' => 'Assign at least one additional domain or subdomain to an IP.',
            ]);
        }
        if ($multiDomainPerIp && (bool) ($validated['clean_dns'] ?? false)) {
            throw ValidationException::withMessages([
                'clean_dns' => 'Multi-Domain per IP cannot delete existing DNS records.',
            ]);
        }
        if ($multiDomainPerIp && (! (bool) $validated['tracking']
            || ! (bool) $validated['https']
            || ! (bool) $validated['spf']
            || ! (bool) $validated['dkim']
            || ! (bool) $validated['dmarc'])) {
            throw ValidationException::withMessages([
                'multi_domain_per_ip' => 'Multi-Domain per IP requires HTTPS tracking, SPF, DKIM, and DMARC to be enabled.',
            ]);
        }

        $rows = array_map(
            static function (array $row): array {
                $normalized = ['id' => $row['id'], 'domain' => $row['domain']];
                if (isset($row['ip_assignments'])) {
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
                        $row['ip_assignments'],
                    );
                }

                return $normalized;
            },
            $validated['data'],
        );

        return [
            'data' => array_values($rows),
            'key' => (string) $validated['key'],
            'pmta_version' => (int) $validated['pmta_version'],
            'tracking' => (bool) $validated['tracking'],
            'proxy' => (bool) $validated['proxy'],
            'imap' => (bool) $validated['imap'],
            'spf' => (bool) $validated['spf'],
            'dkim' => (bool) $validated['dkim'],
            'dmarc' => (bool) $validated['dmarc'],
            'https' => (bool) $validated['https'],
            'clean_dns' => (bool) ($validated['clean_dns'] ?? false),
            'multi_domain_per_ip' => $multiDomainPerIp,
        ];
    }
}
