<?php

namespace App\Services\AffiliateNetworks;

use App\Models\WorkspaceSponsor;
use Illuminate\Http\Client\Response;
use Throwable;

class DmsSponsorConnectionTester
{
    public function __construct(private readonly DmsAffiliateProviderHttp $http) {}

    public function test(WorkspaceSponsor $sponsor): string
    {
        if (blank($sponsor->api_key)) {
            throw new DmsAffiliateProviderException('Enter the sponsor API key before testing.');
        }

        try {
            return match ($sponsor->type) {
                'hitpath' => $this->testHitPath($sponsor),
                'newhitpath' => $this->testNewHitPath($sponsor),
                'cake' => $this->testCake($sponsor),
                'hasoffers' => $this->testHasOffers($sponsor),
                'everflow' => $this->testEverFlow($sponsor),
                default => throw new DmsAffiliateProviderException('This sponsor API is not supported.'),
            };
        } catch (DmsAffiliateProviderException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DmsAffiliateProviderException('The sponsor API could not be reached. Check the provider URL and credentials.');
        }
    }

    private function testHitPath(WorkspaceSponsor $sponsor): string
    {
        $host = preg_replace('/^affiliate\\./i', '', $this->sponsorHost($sponsor)) ?? $this->sponsorHost($sponsor);
        $response = $this->http->postForm('https://api.'.$host.'/pubapi.php', [
            'apikey' => (string) $sponsor->api_key,
            'apifunc' => 'getcampaigns',
        ]);
        $this->assertAccepted($response, 'HitPath');

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($response->body(), \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $xml instanceof \SimpleXMLElement || strtolower((string) $xml['Status']) !== 'ok') {
            throw new DmsAffiliateProviderException('HitPath did not accept the API credentials. Check the API key and provider URL.');
        }

        return 'Connected to HitPath. Campaign-list API access is available.';
    }

    private function testNewHitPath(WorkspaceSponsor $sponsor): string
    {
        $today = now()->toDateString();
        $url = 'https://'.$this->sponsorHost($sponsor).'/api/reports/campaigns?'.http_build_query([
            'start' => $today,
            'end' => $today,
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $this->http->get($url, [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.(string) $sponsor->api_key,
        ]);
        $this->assertAccepted($response, 'NewHitPath');
        if (! is_array($response->json('data'))) {
            throw new DmsAffiliateProviderException('NewHitPath returned an unexpected API response. Check the API key and provider URL.');
        }

        return 'Connected to NewHitPath. The read-only campaign report API is available.';
    }

    private function testCake(WorkspaceSponsor $sponsor): string
    {
        if (blank($sponsor->affiliate_id)) {
            throw new DmsAffiliateProviderException('Enter the Cake affiliate ID before testing.');
        }

        $url = 'https://'.$this->sponsorHost($sponsor).'/affiliates/api/Reports/Conversions?'.http_build_query([
            'api_key' => (string) $sponsor->api_key,
            'affiliate_id' => (string) $sponsor->affiliate_id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $this->http->get($url);
        $this->assertAccepted($response, 'Cake');
        if (! is_array($response->json('data'))) {
            throw new DmsAffiliateProviderException('Cake returned an unexpected API response. Check the API key, affiliate ID, and provider URL.');
        }

        return 'Connected to Cake. The read-only conversion report API is available.';
    }

    private function testHasOffers(WorkspaceSponsor $sponsor): string
    {
        $networkId = strtolower(trim((string) $sponsor->affiliate_id));
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $networkId)) {
            throw new DmsAffiliateProviderException('Enter a valid HasOffers network ID before testing.');
        }

        $today = now()->toDateString();
        $url = 'https://'.$networkId.'.api.hasoffers.com/Apiv3/json?'.http_build_query([
            'api_key' => (string) $sponsor->api_key,
            'Target' => 'Affiliate_Report',
            'Method' => 'getConversions',
            'filters' => ['Stat.date' => ['conditional' => 'BETWEEN', 'values' => [$today, $today]]],
            'fields' => ['ad_id'],
            'limit' => 1,
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $this->http->get($url);
        $this->assertAccepted($response, 'HasOffers');
        if ((int) $response->json('response.status', 0) !== 1) {
            throw new DmsAffiliateProviderException('HasOffers did not accept the API credentials. Check the API key and network ID.');
        }

        return 'Connected to HasOffers. The read-only report API is available.';
    }

    private function testEverFlow(WorkspaceSponsor $sponsor): string
    {
        $today = now()->toDateString();
        $response = $this->http->postJson(
            'https://api.eflow.team/v1/affiliates/reporting/conversions?page=1',
            [
                'show_conversions' => true,
                'show_events' => true,
                'from' => $today,
                'to' => $today,
                'timezone_id' => 65,
            ],
            ['x-eflow-api-key' => (string) $sponsor->api_key],
        );
        $this->assertAccepted($response, 'Everflow');
        if (! is_array($response->json('conversions'))) {
            throw new DmsAffiliateProviderException('Everflow returned an unexpected API response. Check the API key.');
        }

        return 'Connected to Everflow. The read-only report API is available.';
    }

    private function assertAccepted(Response $response, string $provider): void
    {
        if ($response->successful()) {
            return;
        }

        if ($response->status() === 429) {
            throw new DmsAffiliateProviderException($provider.' rate-limited the connection check. Wait briefly and retry.');
        }

        throw new DmsAffiliateProviderException($provider.' rejected the API request (HTTP '.$response->status().'). Check the credentials and account access.');
    }

    private function sponsorHost(WorkspaceSponsor $sponsor): string
    {
        $host = parse_url((string) $sponsor->url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new DmsAffiliateProviderException('Enter a sponsor URL with a public provider hostname.');
        }

        return strtolower(rtrim($host, '.'));
    }
}
