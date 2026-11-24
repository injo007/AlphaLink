<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerIpDomain;
use App\Models\ManagedServerMta;
use App\Models\SendingDomain;
use App\Models\SendingDomainDkimIdentity;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Applies the documented AlphaLink finalization writes in their original order. */
class AlphaLinkBulkInstallCompletionWriter
{
    public function __construct(private readonly AlphaLinkBulkInstallCompletionPlanner $planner) {}

    /**
     * @param  list<array{main: bool, ip: string, type: string, domain_id?: int|string, sub: string, domain: string, is_our?: bool, domains?: list<array{domain_id: int|string, sub: string, domain: string, is_our?: bool}>}>  $dataIps
     * @param  array{tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool}  $options
     * @param  list<SendingDomain>  $selectedDomains
     * @return list<array<string, mixed>>
     */
    public function finalize(
        Workspace $workspace,
        ManagedServer $server,
        SendingDomain $domain,
        array $dataIps,
        int $pmtaVersion,
        array $options,
        array $selectedDomains = [],
        bool $pmtaMonitorHttps = true,
        ?string $trackingHost = null,
        bool $trackingHttpsReady = false,
    ): array {
        if ((string) $server->workspace_id !== (string) $workspace->getKey()
            || (string) $domain->workspace_id !== (string) $workspace->getKey()) {
            throw new InvalidArgumentException('Bulk-install finalization records must belong to the same workspace.');
        }
        foreach ($selectedDomains as $selectedDomain) {
            if ((string) $selectedDomain->workspace_id !== (string) $workspace->getKey()) {
                throw new InvalidArgumentException('Bulk-install finalization records must belong to the same workspace.');
            }
        }

        $operations = $this->planner->plan(
            $server->getKey(),
            $domain->getKey(),
            (bool) $domain->is_our,
            $dataIps,
            $pmtaVersion,
            $options,
            $pmtaMonitorHttps,
            $trackingHost,
            $trackingHttpsReady,
        );

        $requiredDomainIds = collect($operations)
            ->filter(static fn (array $operation): bool => isset($operation['domain_id']))
            ->pluck('domain_id')
            ->map(static fn (int|string $id): string => (string) $id)
            ->unique()
            ->values();
        $availableDomains = $workspace->sendingDomains()
            ->whereIn('id', $requiredDomainIds)
            ->get(['id', 'name'])
            ->keyBy(static fn (SendingDomain $selectedDomain): string => (string) $selectedDomain->getKey());
        if ($requiredDomainIds->diff($availableDomains->keys())->isNotEmpty()) {
            throw new InvalidArgumentException('Every installation sending domain must belong to the same workspace.');
        }
        foreach ($dataIps as $entry) {
            $entryDomain = $availableDomains->get((string) ($entry['domain_id'] ?? $domain->getKey()));
            if (! $entryDomain instanceof SendingDomain || strcasecmp($entry['domain'], $entryDomain->name) !== 0) {
                throw new InvalidArgumentException('An installation hostname conflicts with its selected sending domain.');
            }
            foreach ($entry['domains'] ?? [] as $assignment) {
                $assignmentDomain = $availableDomains->get((string) $assignment['domain_id']);
                if (! $assignmentDomain instanceof SendingDomain
                    || strcasecmp($assignment['domain'], $assignmentDomain->name) !== 0) {
                    throw new InvalidArgumentException('An installation hostname conflicts with its selected sending domain.');
                }
            }
        }

        $identityOperations = collect($operations)
            ->filter(static fn (array $operation): bool => isset($operation['sending_domain_dkim_identity_id']))
            ->values();
        $identityIds = $identityOperations->pluck('sending_domain_dkim_identity_id')->map(static fn ($id): string => (string) $id)->unique();
        if ($identityIds->isNotEmpty()) {
            $identities = SendingDomainDkimIdentity::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereIn('id', $identityIds)
                ->get(['id', 'sending_domain_id', 'hostname', 'selector'])
                ->keyBy(static fn (SendingDomainDkimIdentity $identity): string => (string) $identity->getKey());
            if ($identityIds->diff($identities->keys())->isNotEmpty()) {
                throw new InvalidArgumentException('Every assignment signing identity must belong to the installation workspace.');
            }
            foreach ($identityOperations as $operation) {
                $identity = $identities->get((string) $operation['sending_domain_dkim_identity_id']);
                if (! $identity instanceof SendingDomainDkimIdentity
                    || (string) $identity->sending_domain_id !== (string) $operation['domain_id']
                    || strcasecmp($identity->hostname, (string) ($operation['hostname'] ?? $operation['dns'])) !== 0
                    || ! hash_equals($identity->selector, (string) $operation['dkim_selector'])) {
                    throw new InvalidArgumentException('An assignment signing identity does not match its server, hostname, and sending domain.');
                }
            }
        }

        DB::transaction(function () use ($operations, $workspace, $server): void {
            foreach ($operations as $operation) {
                match ($operation['action']) {
                    'delete_server_mtas' => ManagedServerMta::query()
                        ->where('workspace_id', $workspace->getKey())
                        ->where('managed_server_id', $server->getKey())
                        ->delete(),
                    'delete_server_ips' => ManagedServerIp::query()
                        ->where('workspace_id', $workspace->getKey())
                        ->where('managed_server_id', $server->getKey())
                        ->delete(),
                    'insert_server_ip' => $this->insertServerIp($workspace, $server, $operation),
                    'insert_server_ip_domain' => $this->insertServerIpDomain($workspace, $server, $operation),
                    'mark_domain_used' => $this->markDomainUsed($workspace, $operation['domain_id'], $operation['status']),
                    'save_installation_config' => $server->forceFill(['install_parameters' => $operation['install_parameters']])->save(),
                    'mark_server_installed' => $server->forceFill(['is_installed' => $operation['is_installed']])->save(),
                    default => throw new InvalidArgumentException('Unsupported installation completion operation.'),
                };
            }
        });

        return $operations;
    }

    /** @param array<string, mixed> $operation */
    private function insertServerIp(Workspace $workspace, ManagedServer $server, array $operation): ManagedServerIp
    {
        $hostname = strtolower(rtrim(trim((string) $operation['dns']), '.'));
        $ip = ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $operation['domain_id'],
            'ip' => $operation['ip'],
            'type' => $operation['type'],
            'dns' => $hostname,
            'ehlo_hostname' => strtolower(rtrim(trim((string) ($operation['ehlo_hostname'] ?? $hostname)), '.')),
            'is_active' => $operation['is_active'],
        ]);

        ManagedServerIpDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $ip->getKey(),
            'sending_domain_id' => $operation['domain_id'],
            'hostname' => $hostname,
            'tracking_host' => $operation['tracking_host'] ?? null,
            'sending_domain_dkim_identity_id' => $operation['sending_domain_dkim_identity_id'] ?? null,
            'dkim_selector' => $operation['dkim_selector'] ?? null,
            'is_primary' => true,
            'is_active' => $operation['is_active'],
            'tracking_https_ready' => (bool) ($operation['tracking_https_ready'] ?? false),
            'dns_verified_at' => ! empty($operation['dns_verified']) ? now() : null,
            'authentication_verified_at' => ! empty($operation['authentication_verified']) ? now() : null,
        ]);

        return $ip;
    }

    /** @param array<string, mixed> $operation */
    private function insertServerIpDomain(Workspace $workspace, ManagedServer $server, array $operation): ManagedServerIpDomain
    {
        $ip = ManagedServerIp::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('managed_server_id', $server->getKey())
            ->where('ip', $operation['ip'])
            ->sole();

        return ManagedServerIpDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $ip->getKey(),
            'sending_domain_id' => $operation['domain_id'],
            'hostname' => strtolower(rtrim(trim((string) $operation['hostname']), '.')),
            'tracking_host' => $operation['tracking_host'] ?? null,
            'sending_domain_dkim_identity_id' => $operation['sending_domain_dkim_identity_id'] ?? null,
            'dkim_selector' => $operation['dkim_selector'] ?? null,
            'is_primary' => false,
            'is_active' => $operation['is_active'],
            'tracking_https_ready' => (bool) ($operation['tracking_https_ready'] ?? false),
            'dns_verified_at' => ! empty($operation['dns_verified']) ? now() : null,
            'authentication_verified_at' => ! empty($operation['authentication_verified']) ? now() : null,
        ]);
    }

    private function markDomainUsed(Workspace $workspace, string|int $domainId, string $status): bool
    {
        $domain = $workspace->sendingDomains()->whereKey($domainId)->firstOrFail();

        return $domain->forceFill(['status' => $status])->save();
    }
}
