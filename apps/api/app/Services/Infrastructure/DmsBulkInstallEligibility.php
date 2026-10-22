<?php

namespace App\Services\Infrastructure;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;

class DmsBulkInstallEligibility
{
    /**
     * @return array{servers: Collection, domains: Collection, missing_username: bool}
     */
    public function for(User $user, Workspace $workspace): array
    {
        $username = trim((string) $user->username);
        $servers = $username === ''
            ? collect()
            : $workspace->managedServers()
                ->where('is_active', true)
                ->where('is_installed', false)
                ->where('name', 'like', '%_'.mb_strtoupper($username).'%')
                ->orderByDesc('id')
                ->get(['id', 'name', 'ip_address']);

        $domains = $workspace->sendingDomains()
            ->whereIn('status', ['free', 'new'])
            ->where('is_active', true)
            ->where('is_our', false)
            ->whereHas('domainProvider', fn ($query) => $query->where('is_active', true))
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
}
