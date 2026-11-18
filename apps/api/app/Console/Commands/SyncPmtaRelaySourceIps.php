<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Models\WorkspaceInfrastructureSettings;
use App\Services\Campaigns\PmtaProxySettings;
use App\Services\Infrastructure\PmtaProxyEgressResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncPmtaRelaySourceIps extends Command
{
    protected $signature = 'alphalink:sync-pmta-relay-source-ips';

    protected $description = 'Detect configured PMTA proxy IPv4 egress and refresh auto-managed PMTA relay sources';

    public function handle(PmtaProxyEgressResolver $egress, PmtaProxySettings $proxySettings): int
    {
        $workspaces = Workspace::query()->with('infrastructureSettings')->orderBy('id')->get(['id']);
        if ($workspaces->isEmpty()) {
            $this->info('No workspaces need PMTA relay source setup.');

            return self::SUCCESS;
        }

        $managedWorkspaces = $workspaces->filter(function (Workspace $workspace): bool {
            $settings = $workspace->infrastructureSettings;

            return $settings === null
                || ! is_array($settings->pmta_relay_ips)
                || $settings->pmta_relay_ips === []
                || $settings->pmta_relay_ips_auto_managed;
        })->values();
        if ($managedWorkspaces->isEmpty()) {
            $this->info('All workspaces have explicitly configured PMTA relay sources; no settings were changed.');

            return self::SUCCESS;
        }

        $updated = 0;
        $preserved = 0;
        foreach ($managedWorkspaces as $workspace) {
            $workspaceId = $workspace->getKey();
            $proxy = $proxySettings->forWorkspace($workspace);
            if ($proxy === null) {
                $this->error("Workspace {$workspaceId} has no valid PMTA relay proxy configuration; refusing to authorize AlphaLink's direct egress instead.");

                return self::FAILURE;
            }

            $sourceIps = $egress->resolve($proxy);
            if ($sourceIps === []) {
                $this->error("Could not verify the PMTA relay proxy egress for workspace {$workspaceId}. Check its endpoint and credentials, then retry deployment.");

                return self::FAILURE;
            }

            $changed = DB::transaction(function () use ($workspaceId, $sourceIps): bool {
                Workspace::query()->whereKey($workspaceId)->lockForUpdate()->firstOrFail();
                $settings = WorkspaceInfrastructureSettings::query()->firstOrCreate(['workspace_id' => $workspaceId]);
                $current = $settings->pmta_relay_ips;

                if (is_array($current) && $current !== [] && ! $settings->pmta_relay_ips_auto_managed) {
                    return false;
                }

                if ($current !== $sourceIps || ! $settings->pmta_relay_ips_auto_managed) {
                    $settings->pmta_relay_ips = $sourceIps;
                    $settings->pmta_relay_ips_auto_managed = true;
                    $settings->save();
                }

                return true;
            });

            $changed ? $updated++ : $preserved++;
        }

        $preserved += $workspaces->count() - $managedWorkspaces->count();
        $this->info("Verified PMTA relay proxy egress and synchronized {$updated} automatically managed workspace(s); preserved {$preserved} explicitly configured workspace(s).");

        return self::SUCCESS;
    }
}
