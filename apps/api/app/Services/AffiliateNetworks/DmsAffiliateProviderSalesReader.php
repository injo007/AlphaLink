<?php

namespace App\Services\AffiliateNetworks;

use App\Models\WorkspaceSponsor;
use Illuminate\Support\Carbon;

class DmsAffiliateProviderSalesReader
{
    public function __construct(private readonly DmsAffiliateProviderHttp $http) {}

    /**
     * Read the DMS sales-report contract for one configured sponsor.
     *
     * @return list<array{conversion_id: string, earning: float, s1: string, s2: string, s3: string, ip: ?string, id: ?string, created_at: Carbon}>
     */
    public function read(WorkspaceSponsor $sponsor, Carbon $from, Carbon $to): array
    {
        return match ($sponsor->type) {
            'hitpath' => $this->readHitPath($sponsor, $from, $to),
            'newhitpath' => $this->readNewHitPath($sponsor, $from, $to),
            'cake' => $this->readCake($sponsor, $from, $to),
            'hasoffers' => $this->readHasOffers($sponsor, $from, $to),
            'everflow' => $this->readEverFlow($sponsor, $from, $to),
            default => throw new DmsAffiliateProviderException('Automatic conversion import is not available for this affiliate network.'),
        };
    }

    /** @return list<array{conversion_id: string, earning: float, s1: string, s2: string, s3: string, ip: ?string, id: ?string, created_at: Carbon}> */
    private function readHitPath(WorkspaceSponsor $sponsor, Carbon $from, Carbon $to): array
    {
        $host = preg_replace('/^affiliate\./i', '', $this->sponsorHost($sponsor)) ?? $this->sponsorHost($sponsor);
        $url = sprintf('https://reporting.%s/api.php', $host);
        $response = $this->http->postForm($url, [
            'type' => 'sales',
            'key' => (string) $sponsor->api_key,
            'start' => $from->toDateString(),
            'end' => $to->toDateString(),
        ]);
        if (! $response->successful()) {
            return [];
        }

        $body = str_replace(",,,,,,\n", '', trim($response->body()));
        if ($body === '') {
            return [];
        }

        $rows = array_map('str_getcsv', preg_split('/\r\n|\r|\n/', $body) ?: []);
        array_shift($rows);

        return array_values(array_filter(array_map(function (array $sale): ?array {
            if (empty($sale[2])) {
                return null;
            }

            $createdAt = Carbon::parse((string) ($sale[1] ?? ''));

            return $this->sale(
                (string) $createdAt->timestamp,
                (float) ($sale[6] ?? 0),
                (string) $sale[2],
                (string) ($sale[3] ?? ''),
                (string) ($sale[4] ?? ''),
                is_string($sale[5] ?? null) ? $sale[5] : null,
                $createdAt,
            );
        }, $rows)));
    }

    /** @return list<array{conversion_id: string, earning: float, s1: string, s2: string, s3: string, ip: ?string, id: ?string, created_at: Carbon}> */
    private function readNewHitPath(WorkspaceSponsor $sponsor, Carbon $from, Carbon $to): array
    {
        $apiUrl = 'https://'.$this->sponsorHost($sponsor).'/api';
        $headers = ['Authorization' => 'Bearer '.(string) $sponsor->api_key, 'Accept' => 'application/json'];
        $campaigns = $this->http->get($apiUrl.'/reports/campaigns?'.http_build_query([
            'start' => $from->toDateString(),
            'end' => $to->toDateString(),
        ], '', '&', PHP_QUERY_RFC3986), $headers);
        if (! $campaigns->successful()) {
            return [];
        }

        $result = [];
        foreach ($campaigns->json('data', []) as $campaign) {
            if (! is_array($campaign) || (int) ($campaign['sales'] ?? 0) <= 0 || ! is_numeric($campaign['id'] ?? null)) {
                continue;
            }

            $page = 1;
            do {
                $url = sprintf('%s/reports/sales-detail/%d?', $apiUrl, (int) $campaign['id']).http_build_query([
                    'start' => $from->toDateString(),
                    'end' => $to->toDateString(),
                    'page' => $page,
                ], '', '&', PHP_QUERY_RFC3986);
                $response = $this->http->get($url, $headers);
                if (! $response->successful()) {
                    break;
                }

                $data = $response->json();
                $sales = is_array($data) && is_array($data['data'] ?? null) ? $data['data'] : [];
                foreach ($sales as $sale) {
                    if (! is_array($sale) || empty($sale['c1'])) {
                        continue;
                    }

                    $createdAt = Carbon::parse((string) ($sale['saledate'] ?? ''));
                    $ip = is_string($sale['ip'] ?? null) ? $sale['ip'] : null;
                    $result[] = $this->sale(
                        (string) $createdAt->timestamp.hash('adler32', (string) $ip),
                        (float) ($sale['earnings'] ?? 0),
                        (string) $sale['c1'],
                        (string) ($sale['c2'] ?? ''),
                        (string) ($sale['c3'] ?? ''),
                        $ip,
                        $createdAt,
                    );
                }

                $page++;
            } while (is_array($data ?? null) && ! empty($data['links']['next']));
        }

        return $result;
    }

    /** @return list<array{conversion_id: string, earning: float, s1: string, s2: string, s3: string, ip: ?string, id: ?string, created_at: Carbon}> */
    private function readCake(WorkspaceSponsor $sponsor, Carbon $from, Carbon $to): array
    {
        if (blank($sponsor->affiliate_id)) {
            throw new DmsAffiliateProviderException('Save the Cake affiliate ID before importing conversions.');
        }

        $url = 'https://'.$this->sponsorHost($sponsor).'/affiliates/api/Reports/Conversions?'.http_build_query([
            'api_key' => (string) $sponsor->api_key,
            'affiliate_id' => (string) $sponsor->affiliate_id,
            'start_date' => $from->toDateString(),
            'end_date' => $to->copy()->addDay()->toDateString(),
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $this->http->get($url);
        if (! $response->successful()) {
            return [];
        }

        $sales = $response->json('data', []);
        if (! is_array($sales)) {
            return [];
        }

        return array_values(array_filter(array_map(function (array $sale): ?array {
            if (empty($sale['subid_1'])) {
                return null;
            }

            $createdAt = Carbon::parse((string) ($sale['conversion_date'] ?? ''));

            return $this->sale(
                (string) ($sale['conversion_id'] ?? ''),
                (float) ($sale['price'] ?? 0),
                (string) $sale['subid_1'],
                (string) ($sale['subid_2'] ?? ''),
                (string) ($sale['subid_3'] ?? ''),
                null,
                $createdAt,
            );
        }, array_values(array_filter($sales, 'is_array')))));
    }

    /** @return list<array{conversion_id: string, earning: float, s1: string, s2: string, s3: string, ip: ?string, id: ?string, created_at: Carbon}> */
    private function readHasOffers(WorkspaceSponsor $sponsor, Carbon $from, Carbon $to): array
    {
        $affiliateId = trim((string) $sponsor->affiliate_id);
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $affiliateId)) {
            throw new DmsAffiliateProviderException('Save a valid HasOffers network ID before importing conversions.');
        }

        $url = sprintf('https://%s.api.hasoffers.com/Apiv3/json?', strtolower($affiliateId)).http_build_query([
            'api_key' => (string) $sponsor->api_key,
            'Target' => 'Affiliate_Report',
            'Method' => 'getConversions',
            'filters' => ['Stat.date' => ['conditional' => 'BETWEEN', 'values' => [$from->toDateString(), $to->toDateString()]]],
            'fields' => ['ad_id', 'Stat.approved_payout', 'Stat.ip', 'Stat.datetime', 'Stat.affiliate_info1', 'Stat.affiliate_info2', 'Stat.affiliate_info3'],
            'limit' => 99999,
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $this->http->get($url);
        if (! $response->successful() || (int) $response->json('response.status', 0) !== 1) {
            return [];
        }

        $sales = $response->json('response.data.data', []);
        if (! is_array($sales)) {
            return [];
        }

        return array_values(array_filter(array_map(function (array $row): ?array {
            $sale = is_array($row['Stat'] ?? null) ? $row['Stat'] : [];
            if (empty($sale['affiliate_info1'])) {
                return null;
            }

            $createdAt = Carbon::parse((string) ($sale['datetime'] ?? ''));

            return $this->sale(
                (string) ($sale['ad_id'] ?? ''),
                (float) ($sale['approved_payout'] ?? 0),
                (string) $sale['affiliate_info1'],
                (string) ($sale['affiliate_info2'] ?? ''),
                (string) ($sale['affiliate_info3'] ?? ''),
                is_string($sale['conversion_user_ip'] ?? null) ? $sale['conversion_user_ip'] : null,
                $createdAt,
            );
        }, array_values(array_filter($sales, 'is_array')))));
    }

    /** @return list<array{conversion_id: string, earning: float, s1: string, s2: string, s3: string, ip: ?string, id: ?string, created_at: Carbon}> */
    private function readEverFlow(WorkspaceSponsor $sponsor, Carbon $from, Carbon $to): array
    {
        $sales = [];
        $page = 1;
        do {
            $response = $this->http->postJson(
                'https://api.eflow.team/v1/affiliates/reporting/conversions?page='.$page,
                [
                    'show_conversions' => true,
                    'show_events' => true,
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'timezone_id' => 65,
                ],
                ['x-eflow-api-key' => (string) $sponsor->api_key],
            );
            if (! $response->successful()) {
                break;
            }

            $data = $response->json();
            $pageSales = is_array($data) && is_array($data['conversions'] ?? null) ? $data['conversions'] : [];
            $sales = [...$sales, ...$pageSales];
            $page++;
        } while ($pageSales !== []);

        return array_values(array_filter(array_map(function (array $sale): ?array {
            if (empty($sale['sub1'])) {
                return null;
            }

            $createdAt = Carbon::createFromTimestamp((int) ($sale['conversion_unix_timestamp'] ?? 0));

            return $this->sale(
                (string) ($sale['conversion_id'] ?? ''),
                (float) ($sale['revenue'] ?? 0),
                (string) $sale['sub1'],
                (string) ($sale['sub2'] ?? ''),
                (string) ($sale['sub3'] ?? ''),
                is_string($sale['conversion_user_ip'] ?? null) ? $sale['conversion_user_ip'] : null,
                $createdAt,
            );
        }, array_values(array_filter($sales, 'is_array')))));
    }

    /** @return array{conversion_id: string, earning: float, s1: string, s2: string, s3: string, ip: ?string, id: ?string, created_at: Carbon} */
    private function sale(string $conversionId, float $earning, string $s1, string $s2, string $s3, ?string $ip, Carbon $createdAt): array
    {
        return [
            'conversion_id' => $conversionId,
            'earning' => $earning,
            's1' => $s1,
            's2' => $s2,
            's3' => $s3,
            'ip' => $ip,
            'id' => $this->decodeBase64Url($s1),
            'created_at' => $createdAt,
        ];
    }

    private function sponsorHost(WorkspaceSponsor $sponsor): string
    {
        $url = trim((string) $sponsor->url);
        if (! str_contains($url, '://')) {
            $url = 'https://'.$url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new DmsAffiliateProviderException('The sponsor URL must contain a provider hostname.');
        }

        return strtolower(rtrim($host, '.'));
    }

    private function decodeBase64Url(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }
}
