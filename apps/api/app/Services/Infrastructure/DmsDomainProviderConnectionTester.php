<?php

namespace App\Services\Infrastructure;

use App\Models\DomainProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

class DmsDomainProviderConnectionTester
{
    public function __construct(private readonly CloudflareDnsApi $cloudflare) {}

    public function test(DomainProvider $provider, string $clientIp): string
    {
        try {
            return match ($provider->type) {
                'namecheap' => $this->testNamecheap($provider, $clientIp),
                'godaddy' => $this->testGoDaddy($provider),
                'cloudflare' => $this->testCloudflare($provider),
                default => throw new DmsDomainProviderImportException('This domain provider is not supported.'),
            };
        } catch (DmsDomainProviderImportException $exception) {
            throw $exception;
        } catch (CloudflareDnsApiException $exception) {
            throw new DmsDomainProviderImportException($exception->getMessage());
        } catch (ConnectionException) {
            throw new DmsDomainProviderImportException('The provider could not be reached. Check the network connection and retry.');
        } catch (Throwable) {
            throw new DmsDomainProviderImportException('The provider connection check failed. Check the credentials and try again.');
        }
    }

    private function testNamecheap(DomainProvider $provider, string $clientIp): string
    {
        if (blank($provider->username) || blank($provider->api_key)) {
            throw new DmsDomainProviderImportException('Enter the Namecheap username and API key before testing.');
        }

        $response = Http::asForm()
            ->accept('application/xml')
            ->timeout(20)
            ->connectTimeout(5)
            ->withoutRedirecting()
            ->post('https://api.namecheap.com/xml.response', [
                'ApiUser' => $provider->username,
                'ApiKey' => $provider->api_key,
                'UserName' => $provider->username,
                'ClientIP' => $clientIp,
                'Command' => 'namecheap.domains.getList',
                'ListType' => 'all',
                'SearchTerm' => '',
                'PageSize' => 10,
                'SortBy' => 'CREATEDATE_DESC',
                'Page' => 1,
            ]);

        if (! $response->successful()) {
            throw new DmsDomainProviderImportException('Namecheap rejected the API request. Check the username, API key, and allowed API IP address.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($response->body(), SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $xml instanceof SimpleXMLElement || strtolower((string) $xml['Status']) !== 'ok') {
            throw new DmsDomainProviderImportException('Namecheap did not accept the API credentials. Check the username, API key, and allowed API IP address.');
        }

        return 'Connected to Namecheap. Domain-list access is available.';
    }

    private function testGoDaddy(DomainProvider $provider): string
    {
        if (blank($provider->api_key) || blank($provider->api_secret)) {
            throw new DmsDomainProviderImportException('Enter the GoDaddy API key and API secret before testing.');
        }

        $response = Http::baseUrl('https://api.godaddy.com/')
            ->timeout(20)
            ->connectTimeout(5)
            ->acceptJson()
            ->withoutRedirecting()
            ->withHeaders(['Authorization' => "sso-key {$provider->api_key}:{$provider->api_secret}"])
            ->get('v1/domains', ['statuses' => 'ACTIVE', 'limit' => 1]);

        $domains = $response->json();
        if (! $response->successful() || ! is_array($domains) || ! array_is_list($domains)) {
            throw new DmsDomainProviderImportException('GoDaddy rejected the API request. Check the API key, secret, and account access.');
        }

        return 'Connected to GoDaddy. Domain-list access is available.';
    }

    private function testCloudflare(DomainProvider $provider): string
    {
        if (blank($provider->api_key)) {
            throw new DmsDomainProviderImportException('Enter a Cloudflare API token before testing.');
        }

        $this->cloudflare->testReadAccess($provider);

        return 'Connected to Cloudflare. The token is active and can read zones. DNS write permission was not tested.';
    }
}
