<?php

namespace Tests\Unit;

use App\Models\DomainProvider;
use App\Services\Infrastructure\CloudflareDnsApi;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareDnsApiTest extends TestCase
{
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
