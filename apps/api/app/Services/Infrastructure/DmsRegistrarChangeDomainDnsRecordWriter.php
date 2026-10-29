<?php

namespace App\Services\Infrastructure;

use App\Models\DomainProvider;
use App\Models\SendingDomain;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/** Sends the same replacement record sets as DMS Namecheap and GoDaddy adapters. */
class DmsRegistrarChangeDomainDnsRecordWriter implements DmsBulkInstallDomainDnsRecordWriter, DmsChangeDomainDnsRecordWriter, DmsDomainRecordsHostWriter
{
    private const ALLOWED_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'SOA', 'SRV', 'TXT'];

    public function __construct(private readonly CloudflareDnsApi $cloudflareDnsApi) {}

    public function isDomainAvailable(SendingDomain $domain): bool
    {
        $provider = $this->providerFor($domain);
        if (! $provider instanceof DomainProvider || ! $provider->is_active) {
            return false;
        }

        return match (strtolower((string) $provider->type)) {
            'cloudflare' => $this->hasCloudflareZone($provider, $domain),
            'namecheap' => $this->hasNamecheapDomain($provider, (string) $domain->name),
            'godaddy' => $this->hasGoDaddyDomain($provider, (string) $domain->name),
            default => false,
        };
    }

    public function setHosts(SendingDomain $domain, array $records): bool
    {
        $provider = $this->providerFor($domain);
        if (! $provider instanceof DomainProvider || ! $provider->is_active) {
            return false;
        }

        if (strtolower((string) $provider->type) === 'cloudflare') {
            $zoneId = $this->cloudflareDnsApi->zoneIdForExactDomain($provider, (string) $domain->name, $domain->provider_domain_id);

            return $this->setHostsWithProvider($provider, $domain->name, $records, $zoneId);
        }

        return $this->setHostsWithProvider($provider, $domain->name, $records, (string) $domain->provider_domain_id);
    }

    /** @param list<array{host: string, address: string, type: string, ttl: string}> $records */
    public function setDomainRecordsHosts(SendingDomain $domain, array $records): bool
    {
        if (! $domain->is_our) {
            return false;
        }

        $provider = $this->providerFor($domain);
        if (! $provider instanceof DomainProvider) {
            return false;
        }

        return $this->setHostsWithProvider($provider, $domain->name, $records, (string) $domain->provider_domain_id);
    }

    private function providerFor(SendingDomain $domain): ?DomainProvider
    {
        return DomainProvider::query()
            ->whereKey($domain->domains_provider_id)
            ->where(fn ($query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $domain->workspace_id))
            ->first();
    }

    private function hasCloudflareZone(DomainProvider $provider, SendingDomain $domain): bool
    {
        try {
            $this->cloudflareDnsApi->zoneIdForExactDomain(
                $provider,
                (string) $domain->name,
                $domain->provider_domain_id,
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function hasNamecheapDomain(DomainProvider $provider, string $domain): bool
    {
        if (mb_strlen((string) $provider->username) < 4 || mb_strlen((string) $provider->api_key) < 4) {
            return false;
        }

        $labels = explode('.', $domain);
        if (count($labels) < 2) {
            return false;
        }

        $sld = array_shift($labels);
        try {
            $response = Http::asForm()->timeout(10)->retry(1)->post('https://api.namecheap.com/xml.response', [
                'ApiUser' => $provider->username,
                'ApiKey' => $provider->api_key,
                'UserName' => $provider->username,
                'ClientIP' => request()->ip() ?: '127.0.0.1',
                'Command' => 'namecheap.domains.getInfo',
                'SLD' => $sld,
                'TLD' => implode('.', $labels),
            ]);
            if (! $response->successful()) {
                return false;
            }

            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($response->body(), SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return $xml instanceof SimpleXMLElement
                && strtolower((string) $xml['Status']) === 'ok'
                && isset($xml->CommandResponse->DomainGetInfoResult);
        } catch (Throwable) {
            return false;
        }
    }

    private function hasGoDaddyDomain(DomainProvider $provider, string $domain): bool
    {
        if (mb_strlen((string) $provider->api_key) < 4 || mb_strlen((string) $provider->api_secret) < 4) {
            return false;
        }

        try {
            return Http::baseUrl('https://api.godaddy.com/')
                ->timeout(10)
                ->retry(1)
                ->withHeaders(['Authorization' => "sso-key {$provider->api_key}:{$provider->api_secret}"])
                ->get('v1/domains/'.$domain)
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<array{host: string, address: string, type: string, ttl: string}> $records */
    private function setHostsWithProvider(DomainProvider $provider, string $domain, array $records, string $zoneId): bool
    {
        try {
            return match (strtolower((string) $provider->type)) {
                'namecheap' => $this->setNamecheapHosts($provider, $domain, $records),
                'godaddy' => $this->setGoDaddyHosts($provider, $domain, $records),
                'cloudflare' => $this->cloudflareDnsApi->synchronizeInstallerRecords($provider, $zoneId, $domain, $records),
                default => false,
            };
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<array{host: string, address: string, type: string, ttl: string}> $records */
    private function setNamecheapHosts(DomainProvider $provider, string $domain, array $records): bool
    {
        if (mb_strlen((string) $provider->username) < 4 || mb_strlen((string) $provider->api_key) < 4) {
            return false;
        }

        $labels = explode('.', $domain);
        if (count($labels) < 2) {
            return false;
        }

        $parameters = [
            'ApiUser' => $provider->username,
            'ApiKey' => $provider->api_key,
            'UserName' => $provider->username,
            'ClientIP' => request()->ip() ?: '127.0.0.1',
            'Command' => 'namecheap.domains.dns.setHosts',
            'EmailType' => 'MX',
            'SLD' => array_shift($labels),
            'TLD' => implode('.', $labels),
        ];

        foreach ($this->filterRecords($records) as $index => $record) {
            $parameters["HostName{$index}"] = $record['host'];
            $parameters["Address{$index}"] = $record['address'];
            $parameters["RecordType{$index}"] = $record['type'];
            $parameters["TTL{$index}"] = $record['ttl'];
            if ($record['type'] === 'MX') {
                $parameters["MXPre{$index}"] = '10';
            }
        }

        $response = Http::asForm()->timeout(60)->retry(2)->post('https://api.namecheap.com/xml.response', $parameters);
        if (! $response->successful()) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body(), SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml instanceof SimpleXMLElement
            && strtolower((string) $xml['Status']) === 'ok'
            && strtolower((string) ($xml->CommandResponse->DomainDNSSetHostsResult['IsSuccess'] ?? 'false')) === 'true';
    }

    /** @param list<array{host: string, address: string, type: string, ttl: string}> $records */
    private function setGoDaddyHosts(DomainProvider $provider, string $domain, array $records): bool
    {
        if (mb_strlen((string) $provider->api_key) < 4 || mb_strlen((string) $provider->api_secret) < 4) {
            return false;
        }

        $request = Http::baseUrl('https://api.godaddy.com/')
            ->timeout(60)
            ->retry(2)
            ->withHeaders(['Authorization' => "sso-key {$provider->api_key}:{$provider->api_secret}"]);

        $groups = [];
        foreach ($this->filterRecords($records) as $record) {
            $type = strtoupper($record['type']);
            $name = trim($record['host']) !== '' ? trim($record['host']) : '@';
            $groups[$type.'|'.$name][] = [
                'data' => $record['address'],
                'ttl' => max((int) $record['ttl'], 600),
                ...($type === 'MX' ? ['priority' => 10] : []),
            ];
        }

        foreach ($groups as $key => $desired) {
            [$type, $name] = explode('|', $key, 2);
            $endpoint = 'v1/domains/'.rawurlencode($domain).'/records/'.rawurlencode($type).'/'.rawurlencode($name);
            $replacement = $desired;

            if ($type === 'TXT') {
                $currentResponse = $request->get($endpoint);
                if ($currentResponse->successful()) {
                    $current = $currentResponse->json();
                    if (! is_array($current)) {
                        return false;
                    }
                    foreach ($current as $record) {
                        if (! is_array($record) || $this->isManagedEmailAuthTxt((string) ($record['data'] ?? ''))) {
                            continue;
                        }
                        $preserved = ['data' => (string) ($record['data'] ?? '')];
                        if (isset($record['ttl']) && is_numeric($record['ttl'])) {
                            $preserved['ttl'] = (int) $record['ttl'];
                        }
                        $replacement[] = $preserved;
                    }
                } elseif ($currentResponse->status() !== 404) {
                    return false;
                }
            }

            // GoDaddy's scoped PUT replaces only the selected type/name RRset;
            // the former whole-zone PUT deleted unrelated website and mail DNS.
            if (! $request->put($endpoint, $replacement)->successful()) {
                return false;
            }
        }

        return true;
    }

    private function isManagedEmailAuthTxt(string $content): bool
    {
        $content = trim(str_replace('"', '', $content));

        return preg_match('/\Av=(?:spf1|dkim1|dmarc1)(?:\s|;)/i', $content) === 1;
    }

    /** @param list<array{host: string, address: string, type: string, ttl: string}> $records
     * @return list<array{host: string, address: string, type: string, ttl: string}>
     */
    private function filterRecords(array $records): array
    {
        return array_values(array_filter($records, static fn (array $record): bool => in_array($record['type'], self::ALLOWED_TYPES, true)));
    }
}
