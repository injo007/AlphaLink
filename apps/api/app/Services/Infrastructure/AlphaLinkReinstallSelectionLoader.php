<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\SendingDomain;
use App\Models\Workspace;
use RuntimeException;

/** Loads the saved AlphaLink reinstall form and its read-only server IP refresh. */
class AlphaLinkReinstallSelectionLoader
{
    public function __construct(
        private readonly AlphaLinkInstallerServerCredentialResolver $credentials,
        private readonly AlphaLinkBulkInstallRemoteIpDiscovery $ipDiscovery,
        private readonly AlphaLinkBulkInstallSubdomainGenerator $subdomains,
    ) {}

    /**
     * @return array{spf: bool, dkim: bool, dmarc: bool, pmta_version: int, tracking: bool, proxy: bool, imap: bool, https: bool, multi_domain_per_ip: bool, data: list<array<string, mixed>>}
     */
    public function loadConfiguration(Workspace $workspace, ManagedServer $server): array
    {
        $this->assertWorkspaceServer($workspace, $server);
        $settings = is_array($server->install_parameters) ? $server->install_parameters : [];
        $data = $this->savedRows($workspace, $server);

        return [
            'spf' => (bool) ($settings['spf'] ?? false),
            'dkim' => (bool) ($settings['dkim'] ?? false),
            'dmarc' => (bool) ($settings['dmarc'] ?? false),
            'pmta_version' => (int) ($settings['pmta_version'] ?? 40),
            'tracking' => (bool) ($settings['tracking'] ?? false),
            'proxy' => (bool) ($settings['proxy'] ?? false),
            'imap' => (bool) ($settings['imap'] ?? false),
            'https' => (bool) ($settings['https'] ?? false),
            'multi_domain_per_ip' => collect($data)->contains(static fn (array $row): bool => ! empty($row['domains'])),
            'data' => array_map(static function (array $row): array {
                $row['main'] = (bool) ($row['main'] ?? false);

                return $row;
            }, $data),
        ];
    }

    /**
     * @return list<array{main: bool, ip: string, type: string, domain_id: string, sub: string, domain: string}>
     *
     * @throws HostProbeException
     */
    public function reloadIps(Workspace $workspace, ManagedServer $server, bool $withIpv6 = true): array
    {
        $this->assertWorkspaceServer($workspace, $server);
        $savedRows = $this->savedRows($workspace, $server);
        $domainIds = collect($savedRows)
            ->flatMap(static fn (array $row): array => [
                $row['domain_id'] ?? null,
                ...array_map(static fn (array $assignment): mixed => $assignment['domain_id'] ?? null, $row['domains'] ?? []),
            ])
            ->filter(static fn (mixed $domainId): bool => is_string($domainId) || is_int($domainId))
            ->unique()
            ->values();

        if ($domainIds->isEmpty()) {
            throw new RuntimeException('The saved reinstall configuration has no domain assignments.');
        }

        $domains = SendingDomain::withTrashed()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('id', $domainIds->all())
            ->get()
            ->keyBy(static fn (SendingDomain $domain): string => (string) $domain->getKey());

        if ($domains->count() !== $domainIds->count()) {
            throw new RuntimeException('The saved reinstall configuration references a domain that is unavailable in this workspace.');
        }

        $credential = $this->credentials->resolve($server);
        $ips = $this->ipDiscovery->discover($server, $credential['secret'], $credential['type']);
        if (! $withIpv6) {
            $ips = array_values(array_filter(
                $ips,
                static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            ));
        }

        $primaryIpIndex = array_search((string) $server->ip_address, $ips, true);
        if ($primaryIpIndex !== false && $primaryIpIndex > 0) {
            array_splice($ips, $primaryIpIndex, 1);
            array_unshift($ips, (string) $server->ip_address);
        }

        if ($ips === []) {
            throw new RuntimeException('No Ips Found');
        }

        $chunkSize = min(count($ips), $domainIds->count());
        $groups = collect($ips)->split($chunkSize)->values();
        $savedByIp = collect($savedRows)->filter(static fn (mixed $row): bool => is_array($row) && is_string($row['ip'] ?? null))->keyBy('ip');
        $result = [];

        foreach ($groups as $groupIndex => $group) {
            $domainId = (string) $domainIds->get($groupIndex);
            $domain = $domains->get($domainId);
            if (! $domain instanceof SendingDomain) {
                throw new RuntimeException('The saved reinstall configuration references an unavailable domain.');
            }

            $generatedSubs = $this->subdomains->generate(count($group));
            foreach (array_values($group->all()) as $ipIndex => $ip) {
                $existing = $savedByIp->get($ip);
                if (is_array($existing)) {
                    $existing['main'] = (bool) ($existing['main'] ?? false);
                    $existing['domain_id'] = (string) ($existing['domain_id'] ?? $domainId);
                    $existing['domain'] = (string) ($existing['domain'] ?? $domain->name);
                    $result[] = $existing;

                    continue;
                }

                $isPrimaryIp = (string) $server->ip_address === $ip;
                $result[] = [
                    'main' => $isPrimaryIp,
                    'ip' => $ip,
                    'type' => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 'ipv4' : 'ipv6',
                    'domain_id' => $domainId,
                    'sub' => $isPrimaryIp || $ipIndex === 0 ? '' : (string) ($generatedSubs[$ipIndex] ?? ''),
                    'domain' => (string) $domain->name,
                ];
            }
        }

        return $result;
    }

    /**
     * Reuse the saved install snapshot when present, otherwise recover legacy rows
     * from AlphaLink's persisted server IP-to-domain map.
     *
     * @return list<array<string, mixed>>
     */
    private function savedRows(Workspace $workspace, ManagedServer $server): array
    {
        $settings = is_array($server->install_parameters) ? $server->install_parameters : [];
        $savedRows = $settings['data'] ?? null;
        if (is_array($savedRows) && $savedRows !== []) {
            $savedRows = array_values(array_filter($savedRows, static fn (mixed $row): bool => is_array($row)));
            if ($savedRows !== []) {
                return $savedRows;
            }
        }

        $savedIps = $server->ips()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)
            ->with(['domainAssignments' => fn ($query) => $query->where('is_active', true)->orderByDesc('is_primary')->orderBy('id')])
            ->orderBy('id')
            ->get(['id', 'sending_domain_id', 'ip', 'type', 'dns']);
        if ($savedIps->isEmpty()) {
            return [];
        }

        $domainIds = $savedIps
            ->flatMap(static function (ManagedServerIp $ip): array {
                return [
                    (string) ($ip->domainAssignments->firstWhere('is_primary', true)?->sending_domain_id ?? $ip->sending_domain_id),
                    ...$ip->domainAssignments->pluck('sending_domain_id')->map(static fn (mixed $id): string => (string) $id)->all(),
                ];
            })
            ->unique()->values();
        $domains = SendingDomain::withTrashed()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('id', $domainIds)
            ->get(['id', 'name', 'is_our'])
            ->keyBy(static fn (SendingDomain $domain): string => (string) $domain->getKey());

        if ($domains->count() !== $domainIds->count()) {
            throw new RuntimeException('The saved server IP map references a domain that is unavailable in this workspace.');
        }

        return $savedIps->map(function (ManagedServerIp $ip) use ($domains, $server): array {
            $primaryAssignment = $ip->domainAssignments->firstWhere('is_primary', true);
            $primaryDomainId = (string) ($primaryAssignment?->sending_domain_id ?? $ip->sending_domain_id);
            $domain = $domains->get($primaryDomainId);
            if (! $domain instanceof SendingDomain) {
                throw new RuntimeException('The saved server IP map references an unavailable domain.');
            }

            $subdomain = '';
            $domainName = rtrim((string) $domain->name, '.');
            $dnsName = rtrim((string) $ip->dns, '.');
            if (strcasecmp($dnsName, $domainName) !== 0) {
                $suffix = '.'.$domainName;
                if (! str_ends_with(strtolower($dnsName), strtolower($suffix))) {
                    throw new RuntimeException('The saved server IP map has a DNS name that cannot be safely restored.');
                }

                $subdomain = substr($dnsName, 0, -strlen($suffix));
                if (! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/iD', $subdomain)) {
                    throw new RuntimeException('The saved server IP map has a subdomain that cannot be safely restored.');
                }
            }

            $additionalDomains = [];
            foreach ($ip->domainAssignments->reject(static fn ($assignment): bool => (bool) $assignment->is_primary) as $assignment) {
                $assignmentDomain = $domains->get((string) $assignment->sending_domain_id);
                if (! $assignmentDomain instanceof SendingDomain) {
                    throw new RuntimeException('The saved server IP map references an unavailable assignment domain.');
                }
                $hostname = strtolower(rtrim((string) $assignment->hostname, '.'));
                $assignmentBase = strtolower(rtrim((string) $assignmentDomain->name, '.'));
                $assignmentSub = '';
                if ($hostname !== $assignmentBase) {
                    $suffix = '.'.$assignmentBase;
                    if (! str_ends_with($hostname, $suffix)) {
                        throw new RuntimeException('A saved server domain assignment has a hostname outside its domain.');
                    }
                    $assignmentSub = substr($hostname, 0, -strlen($suffix));
                    if (! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/iD', $assignmentSub)) {
                        throw new RuntimeException('A saved server domain assignment has an invalid subdomain.');
                    }
                }
                $additionalDomains[] = [
                    'domain_id' => (string) $assignmentDomain->getKey(),
                    'sub' => $assignmentSub,
                    'domain' => (string) $assignmentDomain->name,
                    'is_our' => (bool) $assignmentDomain->is_our,
                ];
            }

            return [
                'id' => (int) $ip->getKey(),
                'main' => (string) $ip->ip === (string) $server->ip_address,
                'ip' => (string) $ip->ip,
                'type' => (string) $ip->type,
                'domain_id' => $primaryDomainId,
                'sub' => $subdomain,
                'domain' => (string) $domain->name,
                'domains' => $additionalDomains,
            ];
        })->all();
    }

    private function assertWorkspaceServer(Workspace $workspace, ManagedServer $server): void
    {
        if ((string) $server->workspace_id !== (string) $workspace->getKey()) {
            throw new RuntimeException('The server does not belong to this workspace.');
        }

        if (! $server->is_active || ! $server->is_installed) {
            throw new RuntimeException('Only active installed servers can be reinstalled.');
        }
    }
}
