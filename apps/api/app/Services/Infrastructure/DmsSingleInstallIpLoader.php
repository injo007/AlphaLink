<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/** Loads the source-shaped editable IP/domain rows for DMS's single-server Install page. */
class DmsSingleInstallIpLoader
{
    public function __construct(
        private readonly DmsBulkInstallEligibility $eligibility,
        private readonly DmsBulkInstallRemoteIpDiscovery $ipDiscovery,
        private readonly DmsBulkInstallSubdomainGenerator $subdomainGenerator,
        private readonly DmsInstallerServerCredentialResolver $credentialResolver,
    ) {}

    /**
     * @param  list<string>  $domainIds
     * @return array{data: list<array{main: bool, ip: string, type: string, domain_id: string, sub: string, domain: string}>}
     *
     * @throws HostProbeException
     * @throws ModelNotFoundException
     */
    public function load(
        User $user,
        Workspace $workspace,
        string $serverId,
        array $domainIds,
        bool $withIpv6,
    ): array {
        $this->assertMember($user, $workspace);

        $server = $workspace->managedServers()
            ->where('is_active', true)
            ->whereKey($serverId)
            ->firstOrFail();

        if ($domainIds === []) {
            throw new HostProbeException('Select at least one sending domain before loading server IPs.');
        }

        $domains = $this->loadInstallableDomains($workspace, $domainIds);
        $credential = $this->credentialResolver->resolve($server);
        $ips = $this->ipDiscovery->discover($server, $credential['secret'], $credential['type']);

        if (! $withIpv6) {
            $ips = array_values(array_filter(
                $ips,
                static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            ));
        }

        if ($ips === []) {
            throw new HostProbeException('No Ips Found');
        }

        $mainIndex = array_search((string) $server->ip_address, $ips, true);
        if ($mainIndex !== false && $mainIndex > 0) {
            array_splice($ips, $mainIndex, 1);
            array_unshift($ips, (string) $server->ip_address);
        }

        $groupCount = min(count($ips), $domains->count());
        $groups = collect($ips)->split($groupCount)->values();
        $rows = [];

        foreach ($groups as $groupIndex => $group) {
            $domain = $domains->get($groupIndex);
            $subdomains = $this->subdomainGenerator->generate($group->count());

            foreach ($group->values() as $ipIndex => $ip) {
                $isMain = (string) $server->ip_address === $ip;
                $rows[] = [
                    'main' => $isMain,
                    'ip' => $ip,
                    'type' => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 'ipv4' : 'ipv6',
                    'domain_id' => (string) $domain->getKey(),
                    'sub' => $isMain || $ipIndex === 0 ? '' : $subdomains[$ipIndex],
                    'domain' => (string) $domain->name,
                ];
            }
        }

        return ['data' => $rows];
    }

    private function assertMember(User $user, Workspace $workspace): void
    {
        if (! $workspace->users()->whereKey($user->getKey())->exists()) {
            throw (new ModelNotFoundException)->setModel(ManagedServer::class);
        }
    }

    /**
     * Keep the user's selected order because DMS assigns IP chunks to domains in that order.
     *
     * @param  list<string>  $domainIds
     * @return Collection<int, SendingDomain>
     */
    private function loadInstallableDomains(Workspace $workspace, array $domainIds): Collection
    {
        $uniqueIds = array_values(array_unique(array_map('strval', $domainIds)));
        $domainsById = $workspace->sendingDomains()
            ->whereIn('status', ['free', 'new'])
            ->where('is_active', true)
            ->whereHas('domainProvider', fn ($query) => $query
                ->where('is_active', true)
                ->where(fn ($providerQuery) => $providerQuery
                    ->whereNull('workspace_id')
                    ->orWhere('workspace_id', $workspace->getKey())))
            ->whereIn('id', $uniqueIds)
            ->get(['id', 'name', 'is_our'])
            ->keyBy(fn ($domain): string => (string) $domain->getKey());

        if ($domainsById->count() !== count($uniqueIds)) {
            throw (new ModelNotFoundException)->setModel(SendingDomain::class, $uniqueIds);
        }

        return collect($uniqueIds)->map(fn (string $id) => $domainsById->get($id))->values();
    }
}
