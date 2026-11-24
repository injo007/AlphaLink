<?php

namespace Tests\Unit;

use App\Models\DomainProvider;
use App\Services\Infrastructure\CloudflareDnsApi;
use App\Services\Infrastructure\CloudflareDnsApiException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareDnsApiTest extends TestCase
{
    public function test_managed_subdomain_uses_the_longest_matching_parent_zone(): void
    {
        $provider = new DomainProvider(['type' => 'cloudflare', 'api_key' => 'test-api-token-12345678901234567890']);
        Http::fake(['https://api.cloudflare.com/client/v4/zones?*' => Http::response([
            'success' => true,
            'result' => [
                ['id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'name' => 'example.com'],
                ['id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'name' => 'sub.example.com'],
            ],
            'result_info' => ['total_pages' => 1],
        ])]);

        $zone = (new CloudflareDnsApi)->zoneForManagedHostname($provider, 'mail.sub.example.com');
        $this->assertSame(['id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'name' => 'sub.example.com'], $zone);
    }

    public function test_managed_subdomain_rejects_a_saved_zone_mismatch(): void
    {
        $provider = new DomainProvider(['type' => 'cloudflare', 'api_key' => 'test-api-token-12345678901234567890']);
        Http::fake(['https://api.cloudflare.com/client/v4/zones?*' => Http::response([
            'success' => true,
            'result' => [['id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'name' => 'example.com']],
            'result_info' => ['total_pages' => 1],
        ])]);

        $this->expectException(CloudflareDnsApiException::class);
        (new CloudflareDnsApi)->zoneForManagedHostname($provider, 'mail.example.com', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
    }

    public function test_assignment_record_rejects_an_invalid_dns_label_before_provider_writes(): void
    {
        $provider = new DomainProvider(['type' => 'cloudflare', 'api_key' => 'test-api-token-12345678901234567890']);
        Http::fake();

        $this->expectException(CloudflareDnsApiException::class);
        $this->expectExceptionMessage('hostname is invalid');
        (new CloudflareDnsApi)->ensureAssignmentRecords($provider, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'example.com', [
            ['host' => 'bad_.example.com', 'address' => '8.8.8.8', 'type' => 'A', 'ttl' => '60'],
        ]);
    }

    public function test_assignment_records_are_added_without_deleting_unrelated_records_and_retry_is_idempotent(): void
    {
        $provider = new DomainProvider(['type' => 'cloudflare', 'api_key' => 'test-api-token-12345678901234567890']);
        $zoneId = '0123456789abcdef0123456789abcdef';
        $current = [['type' => 'TXT', 'name' => 'mail.example.com', 'content' => 'site-verification=preserve']];
        $writes = [];
        Http::fake(function ($request) use (&$current, &$writes) {
            if ($request->method() === 'GET') {
                return Http::response(['success' => true, 'result' => $current, 'result_info' => ['total_pages' => 1]]);
            }
            $writes[] = $request->data();
            foreach ($request->data()['posts'] ?? [] as $record) {
                $current[] = ['id' => str_repeat('c', 32), ...$record];
            }

            return Http::response(['success' => true, 'result' => []]);
        });

        $api = new CloudflareDnsApi;
        $desired = [
            ['host' => 'mail', 'address' => '8.8.8.8', 'type' => 'A', 'ttl' => '60'],
            ['host' => 'mail', 'address' => 'v=spf1 ip4:8.8.8.8 -all', 'type' => 'TXT', 'ttl' => '60'],
        ];
        $this->assertTrue($api->ensureAssignmentRecords($provider, $zoneId, 'example.com', $desired));
        $this->assertTrue($api->ensureAssignmentRecords($provider, $zoneId, 'example.com', $desired));
        $this->assertCount(1, $writes);
        $this->assertCount(3, $current);
        $this->assertSame('site-verification=preserve', $current[0]['content']);
        $this->assertArrayNotHasKey('deletes', $writes[0]);
    }

    public function test_assignment_dns_conflict_fails_before_any_write(): void
    {
        $provider = new DomainProvider(['type' => 'cloudflare', 'api_key' => 'test-api-token-12345678901234567890']);
        $zoneId = '0123456789abcdef0123456789abcdef';
        $writes = [];
        Http::fake(function ($request) use (&$writes) {
            if ($request->method() === 'GET') {
                return Http::response(['success' => true, 'result' => [
                    ['type' => 'A', 'name' => 'mail.example.com', 'content' => '1.1.1.1', 'proxied' => false],
                ], 'result_info' => ['total_pages' => 1]]);
            }
            $writes[] = $request->data();

            return Http::response(['success' => true, 'result' => []]);
        });

        try {
            (new CloudflareDnsApi)->ensureAssignmentRecords($provider, $zoneId, 'example.com', [
                ['host' => 'mail', 'address' => '8.8.8.8', 'type' => 'A', 'ttl' => '60'],
            ]);
            $this->fail('The existing address must be preserved and reported as a conflict.');
        } catch (CloudflareDnsApiException $exception) {
            $this->assertStringContainsString('conflicts with an existing value', $exception->getMessage());
        }
        $this->assertSame([], $writes);
    }

    public function test_assignment_dns_extends_existing_spf_and_preserves_existing_dmarc_policy(): void
    {
        $provider = new DomainProvider(['type' => 'cloudflare', 'api_key' => 'test-api-token-12345678901234567890']);
        $zoneId = '0123456789abcdef0123456789abcdef';
        $spfId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $current = [
            ['id' => $spfId, 'type' => 'TXT', 'name' => 'example.com', 'content' => 'v=spf1 include:_spf.example.net -all', 'ttl' => 300],
            ['id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'type' => 'TXT', 'name' => '_dmarc.example.com', 'content' => 'v=DMARC1; p=reject; rua=mailto:reports@example.com', 'ttl' => 300],
        ];
        $writes = [];
        Http::fake(function ($request) use (&$current, &$writes, $spfId) {
            if ($request->method() === 'GET') {
                return Http::response(['success' => true, 'result' => $current, 'result_info' => ['total_pages' => 1]]);
            }
            $writes[] = ['method' => $request->method(), 'data' => $request->data()];
            if ($request->method() === 'PATCH' && str_ends_with($request->url(), '/'.$spfId)) {
                foreach ($current as &$record) {
                    if (($record['id'] ?? '') === $spfId) {
                        $record = [...$record, ...$request->data()];
                    }
                }
                unset($record);

                return Http::response(['success' => true, 'result' => ['id' => $spfId]]);
            }

            return Http::response(['success' => true, 'result' => []]);
        });

        $this->assertTrue((new CloudflareDnsApi)->ensureAssignmentRecords($provider, $zoneId, 'example.com', [
            ['host' => '@', 'address' => 'v=spf1 ip4:8.8.8.8 -all', 'type' => 'TXT', 'ttl' => '60'],
            ['host' => '_dmarc', 'address' => 'v=DMARC1; p=none', 'type' => 'TXT', 'ttl' => '60'],
        ]));

        $this->assertCount(1, $writes);
        $this->assertSame('PATCH', $writes[0]['method']);
        $this->assertSame('v=spf1 include:_spf.example.net ip4:8.8.8.8 -all', str_replace('"', '', $current[0]['content']));
        $this->assertSame('v=DMARC1; p=reject; rua=mailto:reports@example.com', $current[1]['content']);
        $this->assertArrayNotHasKey('deletes', $writes[0]['data']);
    }

    public function test_purge_zone_dns_records_deletes_all_records_except_apex_soa_and_ns(): void
    {
        $provider = new DomainProvider([
            'type' => 'cloudflare',
            'api_key' => 'test-api-token-12345678901234567890',
        ]);
        $zoneId = '0123456789abcdef0123456789abcdef';

        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/'.$zoneId.'/dns_records?*' => Http::response([
                'success' => true,
                'result' => [
                    ['id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'type' => 'SOA', 'name' => 'example.com'],
                    ['id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'type' => 'NS', 'name' => 'example.com'],
                    ['id' => 'cccccccccccccccccccccccccccccccc', 'type' => 'A', 'name' => 'example.com', 'content' => '1.2.3.4'],
                    ['id' => 'dddddddddddddddddddddddddddddddd', 'type' => 'CNAME', 'name' => 'www.example.com', 'content' => 'example.com'],
                    ['id' => 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', 'type' => 'TXT', 'name' => 'example.com', 'content' => 'v=spf1 -all'],
                ],
                'result_info' => ['total_pages' => 1],
            ], 200),
            'https://api.cloudflare.com/client/v4/zones/'.$zoneId.'/dns_records/batch' => Http::response([
                'success' => true,
                'result' => [],
            ], 200),
        ]);

        $api = new CloudflareDnsApi;
        $deleted = $api->purgeZoneDnsRecords($provider, $zoneId, 'example.com');

        $this->assertSame(3, $deleted);

        Http::assertSent(function ($request) use ($zoneId) {
            if ($request->url() === 'https://api.cloudflare.com/client/v4/zones/'.$zoneId.'/dns_records/batch') {
                $deletes = $request->data()['deletes'] ?? [];
                $ids = array_column($deletes, 'id');

                return $ids === [
                    'cccccccccccccccccccccccccccccccc',
                    'dddddddddddddddddddddddddddddddd',
                    'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
                ];
            }

            return true;
        });
    }

    public function test_purge_zone_dns_records_returns_zero_when_only_apex_ns_and_soa_exist(): void
    {
        $provider = new DomainProvider([
            'type' => 'cloudflare',
            'api_key' => 'test-api-token-12345678901234567890',
        ]);
        $zoneId = '0123456789abcdef0123456789abcdef';

        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/'.$zoneId.'/dns_records?*' => Http::response([
                'success' => true,
                'result' => [
                    ['id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'type' => 'SOA', 'name' => 'example.com'],
                    ['id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'type' => 'NS', 'name' => 'example.com'],
                ],
                'result_info' => ['total_pages' => 1],
            ], 200),
        ]);

        $api = new CloudflareDnsApi;
        $deleted = $api->purgeZoneDnsRecords($provider, $zoneId, 'example.com');

        $this->assertSame(0, $deleted);
    }
}
