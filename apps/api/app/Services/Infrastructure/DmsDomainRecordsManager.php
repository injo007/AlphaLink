<?php

namespace App\Services\Infrastructure;

use App\Models\DomainProvider;
use App\Models\SendingDomain;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

/** Reads and updates domain DNS records through the workspace's saved registrar provider. */
class DmsDomainRecordsManager
{
    private const GODADDY_DEFAULT_NAMESERVERS = [
        'ns01.domaincontrol.com',
        'ns02.domaincontrol.com',
    ];

    public function __construct(private readonly DmsDomainRecordsHostWriter $hostWriter) {}

    /**
     * @return array{
     *   hosts: array{status: bool, data: array<int, array<string, mixed>>|string},
     *   nameservers: array{status: bool, data: array<int, array{nameserver: string}>|string}
     * }
     */
    public function load(SendingDomain $domain): array
    {
        $provider = $this->providerFor($domain);

        return [
            'hosts' => $this->loadHosts($provider, $domain->name),
            'nameservers' => $this->loadNameservers($provider, $domain->name),
        ];
    }

    /** @param array<int, mixed> $data */
    public function update(SendingDomain $domain, string $type, array $data): bool
    {
        $provider = $this->providerFor($domain);

        return match ($type) {
            'hosts' => $this->updateHosts($domain, $data),
            'nameservers' => $this->updateNameservers($provider, $domain->name, $data),
            'reset' => $this->resetNameservers($provider, $domain->name),
            default => false,
        };
    }

    private function providerFor(SendingDomain $domain): DomainProvider
    {
        $provider = DomainProvider::query()
            ->whereKey($domain->domains_provider_id)
            ->where(fn ($query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $domain->workspace_id))
            ->first();

        if (! $provider instanceof DomainProvider) {
            throw new RuntimeException('Domain provider is unavailable.');
        }

        return $provider;
    }

    /** @return array{status: bool, data: array<int, array<string, mixed>>|string} */
    private function loadHosts(DomainProvider $provider, string $domain): array
    {
        if (strtolower($provider->type) === 'godaddy') {
            if (blank($provider->api_key) || blank($provider->api_secret)) {
                return ['status' => false, 'data' => 'Could not get domain hosts'];
            }

            $response = $this->goDaddyRequest($provider)->get('v1/domains/'.$domain.'/records');
            $records = $response->json();
            if (! $response->successful() || ! is_array($records)) {
                return ['status' => false, 'data' => 'Could not get domain hosts'];
            }

            $hosts = [];
            foreach ($records as $record) {
                if (is_array($record)) {
                    $hosts[] = [
                        'host' => $record['name'] ?? '',
                        'type' => $record['type'] ?? '',
                        'value' => $record['data'] ?? '',
                        'ttl' => $record['ttl'] ?? 60,
                    ];
                }
            }

            return ['status' => true, 'data' => $hosts];
        }

        $xml = $this->namecheapRequest($provider, $domain, 'namecheap.domains.dns.getHosts');
        if (! $xml instanceof SimpleXMLElement) {
            return ['status' => false, 'data' => 'Could not get domain hosts'];
        }

        $hosts = [];
        foreach ($xml->CommandResponse->DomainDNSGetHostsResult->host ?? [] as $record) {
            $attributes = $record->attributes();
            $hosts[] = [
                'host' => (string) ($attributes['Name'] ?? ''),
                'type' => (string) ($attributes['Type'] ?? ''),
                'value' => (string) ($attributes['Address'] ?? ''),
                'ttl' => (string) ($attributes['TTL'] ?? 60),
            ];
        }

        return ['status' => true, 'data' => $hosts];
    }

    /** @return array{status: bool, data: array<int, array{nameserver: string}>|string} */
    private function loadNameservers(DomainProvider $provider, string $domain): array
    {
        if (strtolower($provider->type) === 'godaddy') {
            if (blank($provider->api_key) || blank($provider->api_secret)) {
                return ['status' => false, 'data' => 'Could not get domain nameservers'];
            }

            $response = $this->goDaddyRequest($provider)->get('v1/domains/'.$domain);
            $nameservers = $response->json('nameServers');
            if (! $response->successful() || ! is_array($nameservers)) {
                return ['status' => false, 'data' => 'Could not get domain nameservers'];
            }

            return ['status' => true, 'data' => array_map(
                static fn (string $nameserver): array => ['nameserver' => $nameserver],
                array_values(array_filter($nameservers, 'is_string')),
            )];
        }

        $xml = $this->namecheapRequest($provider, $domain, 'namecheap.domains.dns.getList');
        if (! $xml instanceof SimpleXMLElement) {
            return ['status' => false, 'data' => 'Could not get domain nameservers'];
        }

        $nameservers = [];
        foreach ($xml->CommandResponse->DomainDNSGetListResult->Nameserver ?? [] as $nameserver) {
            $value = trim((string) $nameserver);
            if ($value !== '') {
                $nameservers[] = ['nameserver' => $value];
            }
        }

        return ['status' => true, 'data' => $nameservers];
    }

    /** @param array<int, mixed> $data */
    private function updateHosts(SendingDomain $domain, array $data): bool
    {
        $records = [];
        foreach ($data as $record) {
            if (! is_array($record) || empty($record['value'])) {
                continue;
            }

            $records[] = [
                'host' => (string) ($record['host'] ?? ''),
                'address' => (string) $record['value'],
                'type' => strtoupper((string) ($record['type'] ?? '')),
                'ttl' => (string) ($record['ttl'] ?? 60),
            ];
        }

        return $this->hostWriter->setDomainRecordsHosts($domain, $records);
    }

    /** @param array<int, mixed> $data */
    private function updateNameservers(DomainProvider $provider, string $domain, array $data): bool
    {
        $nameservers = [];
        foreach ($data as $row) {
            if (is_array($row) && is_string($row['nameserver'] ?? null)) {
                $nameservers[] = $row['nameserver'];
            }
        }

        if (strtolower($provider->type) === 'godaddy') {
            if (blank($provider->api_key) || blank($provider->api_secret)) {
                return false;
            }

            return $this->goDaddyRequest($provider)
                ->patch('v1/domains/'.$domain, ['nameServers' => $nameservers])
                ->successful();
        }

        $xml = $this->namecheapRequest($provider, $domain, 'namecheap.domains.dns.setCustom', [
            'Nameservers' => implode(',', $nameservers),
        ]);

        return $xml instanceof SimpleXMLElement
            && strtolower((string) ($xml->CommandResponse->DomainDNSSetCustomResult['Updated'] ?? 'false')) === 'true';
    }

    private function resetNameservers(DomainProvider $provider, string $domain): bool
    {
        if (strtolower($provider->type) === 'godaddy') {
            if (blank($provider->api_key) || blank($provider->api_secret)) {
                return false;
            }

            return $this->goDaddyRequest($provider)
                ->patch('v1/domains/'.$domain, ['nameServers' => self::GODADDY_DEFAULT_NAMESERVERS])
                ->successful();
        }

        $xml = $this->namecheapRequest($provider, $domain, 'namecheap.domains.dns.setDefault');

        return $xml instanceof SimpleXMLElement
            && strtolower((string) ($xml->CommandResponse->DomainDNSSetDefaultResult['Updated'] ?? 'false')) === 'true';
    }

    /** @param array<string, string> $parameters */
    private function namecheapRequest(DomainProvider $provider, string $domain, string $command, array $parameters = []): SimpleXMLElement|false
    {
        if (blank($provider->username) || blank($provider->api_key)) {
            return false;
        }

        $labels = explode('.', $domain);
        if (count($labels) < 2) {
            return false;
        }

        $payload = [
            'ApiUser' => $provider->username,
            'ApiKey' => $provider->api_key,
            'UserName' => $provider->username,
            'ClientIP' => request()->ip() ?: '127.0.0.1',
            'Command' => $command,
            'SLD' => array_shift($labels),
            'TLD' => implode('.', $labels),
        ] + $parameters;

        $response = Http::asForm()->timeout(60)->retry(2)->post('https://api.namecheap.com/xml.response', $payload);
        if (! $response->successful()) {
            return false;
        }

        return $this->parseNamecheapResponse($response);
    }

    private function parseNamecheapResponse(Response $response): SimpleXMLElement|false
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body(), SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement || strtolower((string) $xml['Status']) !== 'ok') {
            return false;
        }

        return $xml;
    }

    private function goDaddyRequest(DomainProvider $provider): PendingRequest
    {
        return Http::baseUrl('https://api.godaddy.com/')
            ->timeout(60)
            ->retry(2)
            ->withHeaders(['Authorization' => "sso-key {$provider->api_key}:{$provider->api_secret}"]);
    }
}
