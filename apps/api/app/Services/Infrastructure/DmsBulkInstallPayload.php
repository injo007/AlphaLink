<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reproduces the safe request contract at DMS's bulk-install boundary.
 * This only validates and reduces the request; it never starts an installer.
 */
class DmsBulkInstallPayload
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
     *   https: bool
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
            'key' => ['required', 'numeric'],
            'pmta_version' => ['required', 'numeric', Rule::in([40, 45, 50])],
            'tracking' => ['required', 'boolean'],
            'proxy' => ['required', 'boolean'],
            'imap' => ['required', 'boolean'],
            'spf' => ['required', 'boolean'],
            'dkim' => ['required', 'boolean'],
            'dmarc' => ['required', 'boolean'],
            'https' => ['required', 'boolean'],
        ])->validate();

        $rows = array_map(
            static function (array $row): array {
                $normalized = ['id' => $row['id'], 'domain' => $row['domain']];
                if (isset($row['ip_assignments'])) {
                    $normalized['ip_assignments'] = array_map(
                        static fn (array $assignment): array => [
                            'ip' => $assignment['ip'],
                            'type' => $assignment['type'],
                            'main' => (bool) $assignment['main'],
                            'sub' => $assignment['sub'] ?? '',
                            'domain_id' => $assignment['domain_id'],
                        ],
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
        ];
    }
}
