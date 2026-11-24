<?php

namespace Tests\Unit;

use App\Services\Infrastructure\AlphaLinkBulkInstallPayload;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AlphaLinkBulkInstallPayloadTest extends TestCase
{
    public function test_it_reduces_reference_rows_to_only_server_and_domain_ids_and_normalizes_options(): void
    {
        $result = app(AlphaLinkBulkInstallPayload::class)->validateAndNormalize([
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
            'clean_dns' => false,
            'multi_domain_per_ip' => false,
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
            app(AlphaLinkBulkInstallPayload::class)->validateAndNormalize([...$base, 'data' => []]);
            $this->fail('Expected empty rows to fail bulk-install payload validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data', $exception->errors());
        }

        try {
            app(AlphaLinkBulkInstallPayload::class)->validateAndNormalize([...$base, 'pmta_version' => 46]);
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
            app(AlphaLinkBulkInstallPayload::class)->validateAndNormalize($payload);
            $this->fail('Expected malformed rows and switch values to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.0.domain', $exception->errors());
            $this->assertArrayHasKey('tracking', $exception->errors());
        }
    }

    public function test_it_preserves_clean_dns_trigger_when_provided(): void
    {
        $result = app(AlphaLinkBulkInstallPayload::class)->validateAndNormalize([
            'data' => [['id' => 'server-uuid', 'domain' => 'domain-uuid']],
            'key' => '1782155053000',
            'pmta_version' => 45,
            'tracking' => true,
            'proxy' => false,
            'imap' => false,
            'spf' => false,
            'dkim' => false,
            'dmarc' => false,
            'https' => false,
            'clean_dns' => true,
        ]);

        $this->assertTrue($result['clean_dns']);
    }

    public function test_multi_domain_payload_preserves_additional_assignments_without_duplicating_ips(): void
    {
        $primaryId = '11111111-1111-4111-8111-111111111111';
        $secondaryId = '22222222-2222-4222-8222-222222222222';
        $result = app(AlphaLinkBulkInstallPayload::class)->validateAndNormalize([
            'data' => [[
                'id' => 'server-uuid',
                'domain' => $primaryId,
                'ip_assignments' => [[
                    'ip' => '198.51.100.10',
                    'type' => 'ipv4',
                    'main' => true,
                    'sub' => 'mail',
                    'domain_id' => $primaryId,
                    'domains' => [['domain_id' => $secondaryId, 'sub' => 'news']],
                ]],
            ]],
            'key' => '1782155053000',
            'pmta_version' => 45,
            'tracking' => true,
            'proxy' => false,
            'imap' => false,
            'spf' => true,
            'dkim' => true,
            'dmarc' => true,
            'https' => true,
            'multi_domain_per_ip' => true,
            'domains_per_ip' => 2,
        ]);

        $this->assertTrue($result['multi_domain_per_ip']);
        $this->assertCount(1, $result['data'][0]['ip_assignments']);
        $this->assertSame([['domain_id' => $secondaryId, 'sub' => 'news']], $result['data'][0]['ip_assignments'][0]['domains']);
    }

    public function test_additional_domains_require_the_multi_domain_switch_and_preserve_dns_records(): void
    {
        $primaryId = '11111111-1111-4111-8111-111111111111';
        $secondaryId = '22222222-2222-4222-8222-222222222222';
        $payload = [
            'data' => [[
                'id' => 'server-uuid',
                'domain' => $primaryId,
                'ip_assignments' => [[
                    'ip' => '198.51.100.10',
                    'type' => 'ipv4',
                    'main' => true,
                    'sub' => '',
                    'domain_id' => $primaryId,
                    'domains' => [['domain_id' => $secondaryId, 'sub' => 'news']],
                ]],
            ]],
            'key' => '1782155053000',
            'pmta_version' => 45,
            'tracking' => true,
            'proxy' => false,
            'imap' => false,
            'spf' => true,
            'dkim' => true,
            'dmarc' => true,
            'https' => true,
        ];

        try {
            app(AlphaLinkBulkInstallPayload::class)->validateAndNormalize($payload);
            $this->fail('An additional domain without the switch must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.0.ip_assignments.0.domains', $exception->errors());
        }

        try {
            app(AlphaLinkBulkInstallPayload::class)->validateAndNormalize([
                ...$payload,
                'multi_domain_per_ip' => true,
                'domains_per_ip' => 2,
                'clean_dns' => true,
            ]);
            $this->fail('Multi-domain provisioning must preserve existing DNS records.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('clean_dns', $exception->errors());
        }
    }

    public function test_multi_domain_payload_rejects_more_than_100_tracking_hosts_per_server(): void
    {
        $domainId = '11111111-1111-4111-8111-111111111111';
        $assignments = [];
        for ($index = 1; $index <= 51; $index++) {
            $assignments[] = [
                'ip' => '198.51.100.'.$index,
                'type' => 'ipv4',
                'main' => $index === 1,
                'sub' => 'mail'.$index,
                'domain_id' => $domainId,
                'domains' => [['domain_id' => $domainId, 'sub' => 'offers'.$index]],
            ];
        }

        try {
            app(AlphaLinkBulkInstallPayload::class)->validateAndNormalize([
                'data' => [['id' => 'server-uuid', 'domain' => $domainId, 'ip_assignments' => $assignments]],
                'key' => '1782155053000',
                'pmta_version' => 45,
                'tracking' => true,
                'proxy' => false,
                'imap' => false,
                'spf' => true,
                'dkim' => true,
                'dmarc' => true,
                'https' => true,
                'multi_domain_per_ip' => true,
                'domains_per_ip' => 2,
            ]);
            $this->fail('A server with more tracking hosts than the certificate limit must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.0.ip_assignments', $exception->errors());
        }
    }
}
