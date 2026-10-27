<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerMta;
use App\Models\SendingDomain;
use App\Models\Workspace;
use InvalidArgumentException;

/** Applies the source-traced DMS finalization writes in their original order. */
class DmsBulkInstallCompletionWriter
{
    public function __construct(private readonly DmsBulkInstallCompletionPlanner $planner) {}

    /**
     * @param  list<array{main: bool, ip: string, type: string, domain_id?: int|string, sub: string, domain: string, is_our?: bool}>  $dataIps
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
        );

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
                'insert_server_ip' => ManagedServerIp::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'managed_server_id' => $server->getKey(),
                    'sending_domain_id' => $operation['domain_id'],
                    'ip' => $operation['ip'],
                    'type' => $operation['type'],
                    'dns' => $operation['dns'],
                    'is_active' => $operation['is_active'],
                ]),
                'mark_domain_used' => $this->markDomainUsed($workspace, $operation['domain_id'], $operation['status']),
                'save_installation_config' => $server->forceFill(['install_parameters' => $operation['install_parameters']])->save(),
                'mark_server_installed' => $server->forceFill(['is_installed' => $operation['is_installed']])->save(),
                default => throw new InvalidArgumentException('Unsupported DMS bulk-install completion operation.'),
            };
        }

        return $operations;
    }

    private function markDomainUsed(Workspace $workspace, string|int $domainId, string $status): bool
    {
        $domain = $workspace->sendingDomains()->whereKey($domainId)->firstOrFail();

        return $domain->forceFill(['status' => $status])->save();
    }
}
