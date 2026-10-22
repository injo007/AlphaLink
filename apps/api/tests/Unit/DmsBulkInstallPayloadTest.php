<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsBulkInstallPayload;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DmsBulkInstallPayloadTest extends TestCase
{
    public function test_it_reduces_dms_rows_to_only_server_and_domain_ids_and_normalizes_options(): void
    {
        $result = app(DmsBulkInstallPayload::class)->validateAndNormalize([
            'data' => [[
                'id' => 'server-uuid',
                'domain' => 'domain-uuid',
                'name' => 'Relay name',
                'ip' => '192.0.2.22',
                'check' => true,
            ]],
            'key' => '1782155053000',
            'pmta_version' => '45',
            'tracking' => '1',
            'proxy' => '0',
            'imap' => false,
            'spf' => true,
            'dkim' => 0,
            'dmarc' => '1',
            'https' => '0',
        ]);

        $this->assertSame([
            'data' => [['id' => 'server-uuid', 'domain' => 'domain-uuid']],
            'key' => '1782155053000',
            'pmta_version' => 45,
            'tracking' => true,
            'proxy' => false,
            'imap' => false,
            'spf' => true,
            'dkim' => false,
            'dmarc' => true,
            'https' => false,
        ], $result);
    }

    public function test_it_rejects_an_empty_row_list_or_unsupported_pmta_version(): void
    {
        $base = [
            'data' => [['id' => 'server-uuid', 'domain' => 'domain-uuid']],
            'key' => '1782155053000',
            'pmta_version' => 40,
            'tracking' => 0,
            'proxy' => 0,
            'imap' => 0,
            'spf' => 0,
            'dkim' => 0,
            'dmarc' => 0,
            'https' => 0,
        ];

        try {
            app(DmsBulkInstallPayload::class)->validateAndNormalize([...$base, 'data' => []]);
            $this->fail('Expected empty rows to fail DMS payload validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data', $exception->errors());
        }

        try {
            app(DmsBulkInstallPayload::class)->validateAndNormalize([...$base, 'pmta_version' => 46]);
            $this->fail('Expected an unsupported PMTA release to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('pmta_version', $exception->errors());
        }
    }

    public function test_it_rejects_missing_row_identifiers_and_non_boolean_options(): void
    {
        $payload = [
            'data' => [['id' => 'server-uuid']],
            'key' => '1782155053000',
            'pmta_version' => 40,
            'tracking' => 'maybe',
            'proxy' => 0,
            'imap' => 0,
            'spf' => 0,
            'dkim' => 0,
            'dmarc' => 0,
            'https' => 0,
        ];

        try {
            app(DmsBulkInstallPayload::class)->validateAndNormalize($payload);
            $this->fail('Expected malformed rows and switch values to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.0.domain', $exception->errors());
            $this->assertArrayHasKey('tracking', $exception->errors());
        }
    }
}
