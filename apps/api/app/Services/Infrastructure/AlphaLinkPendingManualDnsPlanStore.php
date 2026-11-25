<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Persists public DNS instructions for external assignments that are not installed yet. */
class AlphaLinkPendingManualDnsPlanStore
{
    /**
     * @param  list<array{domain_id: string, domain: string, records: list<array{host: string, address: string, type: string, ttl: string}>}>  $plans
     */
    public function replace(Workspace $workspace, ManagedServer $server, array $plans): void
    {
        if ((string) $workspace->getKey() !== (string) $server->workspace_id) {
            throw new InvalidArgumentException('A manual DNS plan must belong to the server workspace.');
        }

        if (! $server->exists) {
            return;
        }

        $domains = [];
        foreach ($plans as $plan) {
            $domainId = trim((string) ($plan['domain_id'] ?? ''));
            $domainName = strtolower(rtrim(trim((string) ($plan['domain'] ?? '')), '.'));
            $records = $plan['records'] ?? null;
            if ($domainId === '' || filter_var($domainName, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
                || ! is_array($records)) {
                throw new InvalidArgumentException('A pending manual DNS plan is invalid.');
            }

            $domains[$domainId] = [
                'domain' => $domainName,
                'records' => array_values($records),
            ];
        }

        DB::transaction(function () use ($workspace, $server, $domains): void {
            $lockedServer = ManagedServer::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereKey($server->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $parameters = is_array($lockedServer->install_parameters) ? $lockedServer->install_parameters : [];

            if ($domains === []) {
                unset($parameters['manual_dns_plans']);
            } else {
                $parameters['manual_dns_plans'] = [
                    'updated_at' => now()->toIso8601String(),
                    'domains' => $domains,
                ];
            }

            $lockedServer->forceFill(['install_parameters' => $parameters])->saveOrFail();
        });
    }
}
