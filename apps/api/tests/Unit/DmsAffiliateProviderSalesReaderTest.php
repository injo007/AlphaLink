<?php

namespace Tests\Unit;

use App\Models\WorkspaceSponsor;
use App\Services\AffiliateNetworks\DmsAffiliateProviderHttp;
use App\Services\AffiliateNetworks\DmsAffiliateProviderSalesReader;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DmsAffiliateProviderSalesReaderTest extends TestCase
{
    public function test_hitpath_reads_the_dms_sales_csv_contract(): void
    {
        $campaignToken = $this->s1('C_123');
        Http::preventStrayRequests();
        Http::fake(['https://reporting.example.test/api.php' => Http::response(
            "ignored,date,s1,s2,s3,ip,earning\nx,2026-10-25 12:34:56,{$campaignToken},recipient-token,creative,192.0.2.5,4.25\n",
        )]);

        $sales = $this->reader()->read($this->sponsor('hitpath', 'https://affiliate.example.test'), Carbon::parse('2026-10-25'), Carbon::parse('2026-10-26'));

        $this->assertCount(1, $sales);
        $this->assertSame('4.25', (string) $sales[0]['earning']);
        $this->assertSame('C_123', $sales[0]['id']);
        $this->assertSame('recipient-token', $sales[0]['s2']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://reporting.example.test/api.php'
            && $request['type'] === 'sales'
            && $request['start'] === '2026-10-25'
            && $request['end'] === '2026-10-26');
    }

    public function test_newhitpath_reads_sales_pages_for_campaigns_with_sales(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://newhit.example.test/api/reports/campaigns*' => Http::response(['data' => [
                ['id' => 12, 'sales' => 1], ['id' => 13, 'sales' => 0],
            ]]),
            'https://newhit.example.test/api/reports/sales-detail/12*' => Http::response(['data' => [[
                'saledate' => '2026-10-25T12:00:00Z', 'earnings' => '2.50', 'c1' => $this->s1('C_12'),
                'c2' => 'structured-recipient-token', 'c3' => 'third', 'ip' => '192.0.2.8',
            ]], 'links' => ['next' => null]]),
        ]);

        $sales = $this->reader()->read($this->sponsor('newhitpath', 'newhit.example.test'), Carbon::parse('2026-10-25'), Carbon::parse('2026-10-26'));

        $this->assertCount(1, $sales);
        $this->assertSame('C_12', $sales[0]['id']);
        $this->assertSame('structured-recipient-token', $sales[0]['s2']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/reports/campaigns?')
            && $request->hasHeader('Authorization', 'Bearer synthetic-provider-key'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/reports/sales-detail/12?')
            && str_contains($request->url(), 'page=1'));
    }

    public function test_cake_reads_conversions_and_uses_the_dms_end_date_adjustment(): void
    {
        $campaignToken = $this->s1('C_22');
        Http::preventStrayRequests();
        Http::fake(['https://cake.example.test/affiliates/api/Reports/Conversions*' => Http::response(['data' => [[
            'conversion_id' => 'cake-conversion-1', 'price' => '3.75', 'subid_1' => $campaignToken,
            'subid_2' => 'recipient', 'subid_3' => 'third', 'conversion_date' => '2026-10-25T15:00:00Z',
        ]]])]);

        $sales = $this->reader()->read($this->sponsor('cake', 'cake.example.test'), Carbon::parse('2026-10-25'), Carbon::parse('2026-10-26'));

        $this->assertCount(1, $sales);
        $this->assertSame('cake-conversion-1', $sales[0]['conversion_id']);
        $this->assertNull($sales[0]['ip']);
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_contains($request->url(), '/Reports/Conversions?')
                && $query['start_date'] === '2026-10-25'
                && $query['end_date'] === '2026-10-27';
        });
    }

    public function test_hasoffers_maps_stat_fields_from_the_report_response(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://network.api.hasoffers.com/Apiv3/json*' => Http::response([
            'response' => ['status' => 1, 'data' => ['data' => [[
                'Stat' => [
                    'ad_id' => 'hasoffers-1', 'approved_payout' => 1.5, 'datetime' => '2026-10-25 11:12:13',
                    'affiliate_info1' => $this->s1('C_33'), 'affiliate_info2' => 'recipient',
                    'affiliate_info3' => 'third', 'conversion_user_ip' => '192.0.2.9',
                ],
            ]]]],
        ])]);

        $sales = $this->reader()->read($this->sponsor('hasoffers', 'network.example.test', 'network'), Carbon::parse('2026-10-25'), Carbon::parse('2026-10-26'));

        $this->assertCount(1, $sales);
        $this->assertSame('hasoffers-1', $sales[0]['conversion_id']);
        $this->assertSame('C_33', $sales[0]['id']);
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && $query['Target'] === 'Affiliate_Report'
                && $query['Method'] === 'getConversions'
                && $query['limit'] === '99999'
                && $query['filters']['Stat.date']['values'] === ['2026-10-25', '2026-10-26'];
        });
    }

    public function test_everflow_reads_paginated_conversion_reports(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'page=1')) {
                return Http::response(['conversions' => [[
                    'conversion_id' => 'everflow-1', 'revenue' => '5.00', 'sub1' => $this->s1('C_44'),
                    'sub2' => 'recipient', 'sub3' => 'third', 'conversion_unix_timestamp' => 1792922400,
                    'conversion_user_ip' => '192.0.2.10',
                ]]]);
            }

            return Http::response(['conversions' => []]);
        });

        $sales = $this->reader()->read($this->sponsor('everflow', 'unused.example.test'), Carbon::parse('2026-10-25'), Carbon::parse('2026-10-26'));

        $this->assertCount(1, $sales);
        $this->assertSame('everflow-1', $sales[0]['conversion_id']);
        $this->assertSame('C_44', $sales[0]['id']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'page=1')
            && $request->hasHeader('x-eflow-api-key', 'synthetic-provider-key')
            && $request['timezone_id'] === 65
            && $request['show_conversions'] === true
            && $request['show_events'] === true);
    }

    private function reader(): DmsAffiliateProviderSalesReader
    {
        return new DmsAffiliateProviderSalesReader(new DmsAffiliateProviderHttp(static fn (string $host): array => ['93.184.216.34']));
    }

    private function sponsor(string $type, string $url, string $affiliateId = '12345'): WorkspaceSponsor
    {
        return new WorkspaceSponsor([
            'type' => $type,
            'url' => $url,
            'affiliate_id' => $affiliateId,
            'api_key' => 'synthetic-provider-key',
        ]);
    }

    private function s1(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
