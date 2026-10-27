<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class DmsBulkInstallEligibility
{
    /**
     * @return array{servers: Collection, domains: Collection}
     */
    public function for(Workspace $workspace): array
    {
        $servers = $this->eligibleInstallServers($workspace)
            ->orderByDesc('id')
            ->get(['id', 'name', 'ip_address']);

        $domains = $this->eligibleDomains($workspace, true)
            ->orderByDesc('is_our')
            ->with('history')
            ->get(['id', 'name', 'status', 'is_our']);

        return [
            'servers' => $servers,
            'domains' => $domains->map(fn ($domain) => [
                'id' => (string) $domain->getKey(),
                'label' => sprintf(
                    '%s | STATUS: %s | IS OUR: %s | LISTED: SPAMHAUS = %s - HOSTKARMA = %s',
                    $domain->name,
                    $domain->status,
                    $domain->is_our ? 'YES' : 'NO',
                    $domain->history?->is_listed_spamhaus ? 'YES' : 'NO',
                    $domain->history?->is_listed_hostkarma ? 'YES' : 'NO',
                ),
            ]),
        ];
    }

    /**
     * The DMS administration Install page lists active, uninstalled servers
     * and includes owned and externally managed domains. AlphaLink scopes the
     * same candidate set to active workspace members under the user's access
     * rule. The separate Mailer Cloud bulk-install page has different filters.
     *
     * @return array{servers: Collection, domains: Collection}
     */
    public function forSingleInstall(Workspace $workspace): array
    {
        $servers = $this->eligibleInstallServers($workspace)
            ->orderByDesc('id')
            ->get(['id', 'name', 'ip_address']);

        $domains = $this->eligibleDomains($workspace, true)
            ->orderByDesc('is_our')
            ->with('history')
            ->get(['id', 'name', 'status', 'is_our']);

        return [
            'servers' => $servers,
            'domains' => $domains->map(fn ($domain) => [
                'id' => (string) $domain->getKey(),
                'name' => (string) $domain->name,
                'status' => (string) $domain->status,
                'is_our' => (bool) $domain->is_our,
                'is_listed_spamhaus' => (bool) ($domain->history?->is_listed_spamhaus ?? false),
                'is_listed_hostkarma' => (bool) ($domain->history?->is_listed_hostkarma ?? false),
                'label' => sprintf(
                    '%s | STATUS: %s | IS OUR: %s | LISTED : SPAMHAUS = %s - HOSTKARMA = %s',
                    $domain->name,
                    $domain->status,
                    $domain->is_our ? 'YES' : 'NO',
                    $domain->history?->is_listed_spamhaus ? 'YES' : 'NO',
                    $domain->history?->is_listed_hostkarma ? 'YES' : 'NO',
                ),
            ])->values(),
        ];
    }

    public function serverForSelection(User $user, Workspace $workspace, string $serverId): ?ManagedServer
    {
        if (! $workspace->users()->whereKey($user->getKey())->exists()) {
            return null;
        }

        return $this->eligibleInstallServers($workspace)->whereKey($serverId)->first();
    }

    public function singleInstallServerForSelection(Workspace $workspace, string $serverId): ?ManagedServer
    {
        return $this->eligibleInstallServers($workspace)->whereKey($serverId)->first();
    }

    public function domainForSelection(Workspace $workspace, string $domainId): ?SendingDomain
    {
        return $this->eligibleDomains($workspace, true)->whereKey($domainId)->first();
    }

    public function singleInstallDomainForSelection(Workspace $workspace, string $domainId): ?SendingDomain
    {
        return $this->eligibleDomains($workspace, true)->whereKey($domainId)->first();
    }

    private function eligibleInstallServers(Workspace $workspace): HasMany
    {
        return $workspace->managedServers()
            ->where('is_active', true)
            ->where('is_installed', false);
    }

    private function eligibleDomains(Workspace $workspace, bool $includeOwned = false): HasMany
    {
        return $workspace->sendingDomains()
            ->whereIn('status', ['free', 'new'])
            ->where('is_active', true)
            ->when(! $includeOwned, fn ($query) => $query->where('is_our', false))
            ->whereHas('domainProvider', fn ($query) => $query
                ->where('is_active', true)
                ->where(fn ($providerQuery) => $providerQuery
                    ->whereNull('workspace_id')
                    ->orWhere('workspace_id', $workspace->getKey())));
    }
}
