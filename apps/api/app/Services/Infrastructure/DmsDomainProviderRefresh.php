<?php

namespace App\Services\Infrastructure;

use App\Models\DomainProvider;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

class DmsDomainProviderRefresh
{
    public function refresh(
        Workspace $workspace,
        User $actor,
        SendingDomain $domain,
        string $clientIp,
    ): bool {
        if ($domain->workspace_id !== $workspace->getKey()) {
            abort(404);
        }

        if (! $workspace->users()->whereKey($actor->getKey())->wherePivotIn('role', ['owner', 'admin'])->exists()) {
            abort(403);
        }

        if (! $domain->is_our) {
            return $domain->fill(['expire_at' => now()->addYear()])->save();
        }

        $provider = DomainProvider::query()
            ->whereKey($domain->domains_provider_id)
            ->where(fn ($query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $workspace->getKey()))
            ->first();

        if (! $provider instanceof DomainProvider) {
            return false;
        }

        try {
            $details = match (strtolower((string) $provider->type)) {
                'namecheap' => $this->namecheapDomain($provider, $domain->name, $clientIp),
                'godaddy' => $this->godaddyDomain($provider, $domain->name),
                default => [],
            };
        } catch (Throwable) {
            return false;
        }

        return $details !== [] && $domain->fill($details)->save();
    }

    /** @return array{have_who_is: bool, have_renew: bool, expire_at: string} */
    private function namecheapDomain(DomainProvider $provider, string $domain, string $clientIp): array
    {
        if (mb_strlen((string) $provider->username) < 4 || mb_strlen((string) $provider->api_key) < 4) {
            return [];
        }

        $page = 1;
        while (true) {
            $response = Http::asForm()->timeout(60)->retry(2)->post('https://api.namecheap.com/xml.response', [
                'ApiUser' => $provider->username,
                'ApiKey' => $provider->api_key,
                'UserName' => $provider->username,
                'ClientIP' => $clientIp,
                'Command' => 'namecheap.domains.getList',
                'ListType' => 'all',
                'SearchTerm' => $domain,
                'PageSize' => 100,
                'SortBy' => 'CREATEDATE_DESC',
                'Page' => $page,
            ]);

            if (! $response->successful()) {
                return [];
            }

            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($response->body(), SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if (! $xml instanceof SimpleXMLElement || strtolower((string) $xml['Status']) !== 'ok') {
                return [];
            }

            foreach ($xml->CommandResponse->DomainGetListResult->Domain as $record) {
                $attributes = [];
                foreach ($record->attributes() as $key => $value) {
                    $attributes[(string) $key] = (string) $value;
                }

                if (strcasecmp((string) ($attributes['Name'] ?? ''), $domain) !== 0
                    || ($attributes['IsLocked'] ?? null) !== 'false'
                    || ($attributes['IsExpired'] ?? null) !== 'false') {
                    continue;
                }

                return [
                    'have_who_is' => strtolower((string) ($attributes['WhoisGuard'] ?? '')) === 'enabled',
                    'have_renew' => filter_var($attributes['AutoRenew'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'expire_at' => CarbonImmutable::parse((string) ($attributes['Expires'] ?? ''))->toDateString(),
                ];
            }

            if ((int) ($xml->CommandResponse->Paging->TotalItems ?? 0) < 100) {
                return [];
            }

            $page++;
        }
    }

    /** @return array{have_who_is: bool, have_renew: bool, expire_at: string} */
    private function godaddyDomain(DomainProvider $provider, string $domain): array
    {
        if (mb_strlen((string) $provider->api_key) < 4 || mb_strlen((string) $provider->api_secret) < 4) {
            return [];
        }

        $response = Http::baseUrl('https://api.godaddy.com/')
            ->timeout(60)
            ->retry(2)
            ->withHeaders(['Authorization' => "sso-key {$provider->api_key}:{$provider->api_secret}"])
            ->get('v1/domains/'.$domain);

        if (! $response->successful() || ! is_array($record = $response->json())) {
            return [];
        }

        return [
            'have_who_is' => ($record['exposeWhois'] ?? true) === false,
            'have_renew' => (bool) ($record['renewAuto'] ?? false),
            'expire_at' => CarbonImmutable::parse((string) ($record['expires'] ?? ''))->toDateString(),
        ];
    }
}
