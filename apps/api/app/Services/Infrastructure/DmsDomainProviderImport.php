<?php

namespace App\Services\Infrastructure;

use App\Models\AuditEvent;
use App\Models\DomainProvider;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;

/**
 * Imports the registrar domain catalog using the provider APIs used by DMS.
 * Network calls are isolated here; tests use Laravel's fake HTTP transport.
 */
class DmsDomainProviderImport
{
    public function import(
        Workspace $workspace,
        User $actor,
        DomainProvider $provider,
        string $clientIp,
        ?string $requestId = null,
    ): int {
        if (! $workspace->users()->whereKey($actor->getKey())->wherePivotIn('role', ['owner', 'admin'])->exists()) {
            abort(403);
        }

        if ($provider->workspace_id !== null && $provider->workspace_id !== $workspace->getKey()) {
            abort(404);
        }

        try {
            $rows = match (strtolower((string) $provider->type)) {
                'namecheap' => $this->namecheapDomains($provider, $clientIp),
                'godaddy' => $this->godaddyDomains($provider),
                'cloudflare' => $this->cloudflareZones($provider),
                default => throw new DmsDomainProviderImportException('This registrar type is not supported.'),
            };
        } catch (DecryptException) {
            throw new DmsDomainProviderImportException('The saved registrar credentials could not be read.');
        } catch (DmsDomainProviderImportException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DmsDomainProviderImportException('The registrar import failed. Check the provider credentials and try again.');
        }

        $added = DB::transaction(function () use ($workspace, $actor, $provider, $rows, $requestId): int {
            $added = 0;
            $seenProviderIds = [];
            $seenNames = [];

            foreach ($rows as $row) {
                $providerDomainId = $row['provider_domain_id'];
                $name = mb_strtolower(rtrim(trim($row['name']), '.'));

                if (isset($seenProviderIds[$providerDomainId]) || isset($seenNames[$name])) {
                    continue;
                }

                $seenProviderIds[$providerDomainId] = true;
                $seenNames[$name] = true;

                // DMS ignores provider IDs already present anywhere in its catalog. AlphaLink
                // applies that rule within the workspace boundary used by its domain table.
                $existing = SendingDomain::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->where(fn ($query) => $query
                        ->where('provider_domain_id', $providerDomainId)
                        ->orWhere('name', $name))
                    ->exists();

                if ($existing) {
                    continue;
                }

                $inserted = DB::table('sending_domains')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->getKey(),
                    'name' => $name,
                    'state' => 'pending',
                    'verification_token' => bin2hex(random_bytes(24)),
                    'created_by' => $actor->getKey(),
                    'domains_provider_id' => $provider->getKey(),
                    'provider_domain_id' => $providerDomainId,
                    'status' => 'new',
                    // The DMS domains migration defaults imported registrar records to is_our=true.
                    'is_our' => true,
                    'is_active' => true,
                    'have_who_is' => $row['have_who_is'],
                    'have_renew' => $row['have_renew'],
                    'expire_at' => $row['expire_at'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $added += $inserted;
            }

            if ($added > 0) {
                AuditEvent::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'actor_user_id' => $actor->getKey(),
                    'action' => 'domain.dms_auto_imported',
                    'subject_type' => 'domain_provider',
                    'subject_id' => (string) $provider->getKey(),
                    'request_id' => $requestId,
                    'properties' => ['count' => $added, 'type' => $provider->type],
                    'occurred_at' => now(),
                ]);
            }

            return $added;
        });

        return $added;
    }

    /** @return list<array{provider_domain_id: string, name: string, have_who_is: bool, have_renew: bool, expire_at: ?string}> */
    private function namecheapDomains(DomainProvider $provider, string $clientIp): array
    {
        if (mb_strlen((string) $provider->username) < 4 || mb_strlen((string) $provider->api_key) < 4) {
            throw new DmsDomainProviderImportException('The saved Namecheap username or API key is incomplete.');
        }

        $base = [
            'ApiUser' => $provider->username,
            'ApiKey' => $provider->api_key,
            'UserName' => $provider->username,
            'ClientIP' => $clientIp,
            'Command' => 'namecheap.domains.getList',
            'ListType' => 'all',
            'SearchTerm' => '',
            'PageSize' => 100,
            'SortBy' => 'CREATEDATE_DESC',
        ];
        $result = [];
        $page = 1;

        while (true) {
            try {
                $response = Http::asForm()->timeout(60)->retry(2)->post('https://api.namecheap.com/xml.response', [
                    ...$base,
                    'Page' => $page,
                ]);
            } catch (Throwable) {
                throw new DmsDomainProviderImportException('Namecheap could not be reached. Check the provider credentials and try again.');
            }

            if (! $response->successful()) {
                throw new DmsDomainProviderImportException('Namecheap rejected the domain import request.');
            }

            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($response->body(), SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if (! $xml instanceof SimpleXMLElement || strtolower((string) $xml['Status']) !== 'ok') {
                throw new DmsDomainProviderImportException('Namecheap returned an invalid or unsuccessful import response.');
            }

            $pageDomains = $xml->CommandResponse->DomainGetListResult->Domain;
            if (empty($pageDomains)) {
                break;
            }

            foreach ($pageDomains as $domain) {
                $attributes = [];
                foreach ($domain->attributes() as $key => $value) {
                    $attributes[(string) $key] = (string) $value;
                }

                // This is the same DMS rule: locked and expired Namecheap domains are skipped.
                if (($attributes['IsLocked'] ?? null) !== 'false' || ($attributes['IsExpired'] ?? null) !== 'false') {
                    continue;
                }

                $result[] = [
                    'provider_domain_id' => (string) ($attributes['ID'] ?? ''),
                    'name' => (string) ($attributes['Name'] ?? ''),
                    'have_who_is' => strtolower((string) ($attributes['WhoisGuard'] ?? '')) === 'enabled',
                    'have_renew' => filter_var($attributes['AutoRenew'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'expire_at' => $this->expiryDate((string) ($attributes['Expires'] ?? '')),
                ];
            }

            $totalItems = (int) ($xml->CommandResponse->Paging->TotalItems ?? 0);
            if ($totalItems < 100) {
                break;
            }

            $page++;
        }

        return $this->validateRows($result);
    }

    /** @return list<array{provider_domain_id: string, name: string, have_who_is: bool, have_renew: bool, expire_at: string}> */
    private function godaddyDomains(DomainProvider $provider): array
    {
        if (mb_strlen((string) $provider->api_key) < 4 || mb_strlen((string) $provider->api_secret) < 4) {
            throw new DmsDomainProviderImportException('The saved GoDaddy API key or API secret is incomplete.');
        }

        try {
            $response = Http::baseUrl('https://api.godaddy.com/')
                ->timeout(60)
                ->retry(2)
                ->withHeaders(['Authorization' => "sso-key {$provider->api_key}:{$provider->api_secret}"])
                ->get('v1/domains', ['statuses' => 'ACTIVE']);
        } catch (Throwable) {
            throw new DmsDomainProviderImportException('GoDaddy could not be reached. Check the provider credentials and try again.');
        }

        if (! $response->successful()) {
            throw new DmsDomainProviderImportException('GoDaddy rejected the domain import request.');
        }

        $domains = $response->json();
        if ($domains === null) {
            return [];
        }
        if (! is_array($domains)) {
            throw new DmsDomainProviderImportException('GoDaddy returned an invalid domain list.');
        }

        $result = [];
        foreach ($domains as $domain) {
            if (! is_array($domain)) {
                throw new DmsDomainProviderImportException('GoDaddy returned an invalid domain record.');
            }

            $result[] = [
                'provider_domain_id' => (string) ($domain['domainId'] ?? ''),
                'name' => (string) ($domain['domain'] ?? ''),
                'have_who_is' => ($domain['exposeWhois'] ?? true) === false,
                'have_renew' => (bool) ($domain['renewAuto'] ?? false),
                'expire_at' => $this->expiryDate((string) ($domain['expires'] ?? '')),
            ];
        }

        return $this->validateRows($result);
    }

    /** @return list<array{provider_domain_id: string, name: string, have_who_is: bool, have_renew: bool, expire_at: ?string}> */
    private function cloudflareZones(DomainProvider $provider): array
    {
        if (blank($provider->api_key)) {
            throw new DmsDomainProviderImportException('The saved Cloudflare API token is missing.');
        }

        $zones = app(CloudflareDnsApi::class)->listZones($provider);
        $rows = [];
        foreach ($zones as $zone) {
            if (($zone['status'] ?? null) !== 'active') {
                continue;
            }

            $zoneId = (string) ($zone['id'] ?? '');
            $name = rtrim(trim((string) ($zone['name'] ?? '')), '.');
            if (preg_match('/\A[a-f0-9]{32}\z/i', $zoneId) !== 1
                || $name === ''
                || strlen($name) > 253
                || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/iD', $name)) {
                throw new DmsDomainProviderImportException('Cloudflare returned a zone AlphaLink cannot store.');
            }

            $rows[] = [
                'provider_domain_id' => $zoneId,
                'name' => mb_strtolower($name),
                'have_who_is' => false,
                'have_renew' => false,
                'expire_at' => null,
            ];
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<array{provider_domain_id: string, name: string, have_who_is: bool, have_renew: bool, expire_at: ?string}>
     */
    private function validateRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['name'] = rtrim(trim($row['name']), '.');
            if ($row['provider_domain_id'] === '' || strlen($row['provider_domain_id']) > 255
                || $row['name'] === '' || strlen($row['name']) > 253
                || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/iD', $row['name'])) {
                throw new DmsDomainProviderImportException('The registrar returned a domain record that AlphaLink cannot store.');
            }
        }

        return $rows;
    }

    private function expiryDate(string $date): string
    {
        try {
            return CarbonImmutable::parse($date)->toDateString();
        } catch (Throwable) {
            throw new DmsDomainProviderImportException('The registrar returned an invalid domain expiration date.');
        }
    }
}
