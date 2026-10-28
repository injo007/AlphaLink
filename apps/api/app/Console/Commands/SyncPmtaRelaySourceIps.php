<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Models\WorkspaceInfrastructureSettings;
use App\Services\Infrastructure\DmsPublicIpv4EgressResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncPmtaRelaySourceIps extends Command
{
    protected $signature = 'alphalink:sync-pmta-relay-source-ips';

    protected $description = 'Detect AlphaLink public IPv4 egress and initialize or refresh auto-managed PMTA relay sources';

    public function handle(DmsPublicIpv4EgressResolver $egress): int
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

        $sourceIps = $egress->resolve();
        if ($sourceIps === []) {
            $this->error('Could not discover AlphaLink public IPv4 egress from cloud metadata or independent HTTPS checks. Check host metadata access and outbound HTTPS, then retry deployment.');

            return self::FAILURE;
        }

        $updated = 0;
        $preserved = 0;
        foreach ($managedWorkspaces as $workspace) {
            $workspaceId = $workspace->getKey();
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
        $this->info("Verified AlphaLink egress and synchronized {$updated} automatically managed workspace(s); preserved {$preserved} explicitly configured workspace(s).");

        return self::SUCCESS;
    }
}
