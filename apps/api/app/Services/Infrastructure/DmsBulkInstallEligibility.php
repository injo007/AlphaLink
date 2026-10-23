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
     * @return array{servers: Collection, domains: Collection, missing_username: bool}
     */
    public function for(User $user, Workspace $workspace): array
    {
        $username = trim((string) $user->username);
        $servers = $this->eligibleServers($user, $workspace)
            ->orderByDesc('id')
            ->get(['id', 'name', 'ip_address']);

        $domains = $this->eligibleDomains($workspace)
            ->orderByDesc('is_our')
            ->get(['id', 'name', 'status', 'is_our']);

        return [
            'servers' => $servers,
            'domains' => $domains->map(fn ($domain) => [
                'id' => (string) $domain->getKey(),
                'label' => sprintf('%s | STATUS: %s | IS OUR: %s', $domain->name, $domain->status, $domain->is_our ? 'Yes' : 'No'),
            ]),
            'missing_username' => $username === '',
        ];
    }

    public function serverForSelection(User $user, Workspace $workspace, string $serverId): ?ManagedServer
    {
        if (trim((string) $user->username) === '') {
            return null;
        }

        return $this->eligibleServers($user, $workspace)->whereKey($serverId)->first();
    }

    public function domainForSelection(Workspace $workspace, string $domainId): ?SendingDomain
    {
        return $this->eligibleDomains($workspace)->whereKey($domainId)->first();
    }

    private function eligibleServers(User $user, Workspace $workspace): HasMany
    {
        $username = trim((string) $user->username);
        $query = $workspace->managedServers()
            ->where('is_active', true)
            ->where('is_installed', false);

        if ($username === '') {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('name', 'like', '%_'.mb_strtoupper($username).'%');
    }

    private function eligibleDomains(Workspace $workspace): HasMany
    {
        return $workspace->sendingDomains()
            ->whereIn('status', ['free', 'new'])
            ->where('is_active', true)
            ->where('is_our', false)
            ->whereHas('domainProvider', fn ($query) => $query->where('is_active', true));
    }
}
