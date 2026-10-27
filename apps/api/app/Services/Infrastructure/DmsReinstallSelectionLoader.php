<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\Workspace;
use RuntimeException;

/** Loads the saved DMS reinstall form and its read-only server IP refresh. */
class DmsReinstallSelectionLoader
{
    public function __construct(
        private readonly DmsInstallerServerCredentialResolver $credentials,
        private readonly DmsBulkInstallRemoteIpDiscovery $ipDiscovery,
        private readonly DmsBulkInstallSubdomainGenerator $subdomains,
    ) {}

    /**
     * @return array{spf: bool, dkim: bool, dmarc: bool, pmta_version: int, tracking: bool, proxy: bool, imap: bool, https: bool, data: list<array<string, mixed>>}
     */
    public function loadConfiguration(Workspace $workspace, ManagedServer $server): array
    {
        $this->assertWorkspaceServer($workspace, $server);
        $settings = is_array($server->install_parameters) ? $server->install_parameters : [];
        $data = is_array($settings['data'] ?? null) ? array_values($settings['data']) : [];

        return [
            'spf' => (bool) ($settings['spf'] ?? false),
            'dkim' => (bool) ($settings['dkim'] ?? false),
            'dmarc' => (bool) ($settings['dmarc'] ?? false),
            'pmta_version' => (int) ($settings['pmta_version'] ?? 40),
            'tracking' => (bool) ($settings['tracking'] ?? false),
            'proxy' => (bool) ($settings['proxy'] ?? false),
            'imap' => (bool) ($settings['imap'] ?? false),
            'https' => (bool) ($settings['https'] ?? false),
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
        $settings = is_array($server->install_parameters) ? $server->install_parameters : [];
        $savedRows = is_array($settings['data'] ?? null) ? array_values($settings['data']) : [];
        $domainIds = collect($savedRows)
            ->pluck('domain_id')
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
