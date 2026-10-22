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
     *   data: list<array{id: string, domain: string}>,
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
            static fn (array $row): array => ['id' => $row['id'], 'domain' => $row['domain']],
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
