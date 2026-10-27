<?php

namespace App\Console\Commands;

use App\Models\PmtaScheduledAction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Campaigns\PmtaQueueAdmin;
use App\Services\PmtaAuditLogger;
use Illuminate\Console\Command;

class RunPmtaScheduledActions extends Command
{
    protected $signature = 'pmta:run-scheduled-action {actionId}';

    protected $description = 'Run one enabled workspace-scoped PMTA queue task.';

    public function handle(PmtaQueueAdmin $pmta, PmtaAuditLogger $audit): int
    {
        $action = PmtaScheduledAction::query()->where('is_active', true)->find($this->argument('actionId'));
        if (! $action) {
            return self::SUCCESS;
        }
        $workspace = Workspace::query()->find($action->workspace_id);
        if (! $workspace) {
            return self::SUCCESS;
        }
        $port = (int) ($workspace->infrastructureSettings?->pmta_admin_port ?? config('infrastructure.pmta_admin_port', 1213));
        $actor = User::query()->find($action->created_by);
        if (! $actor) {
            return self::SUCCESS;
        }

        $servers = $workspace->managedServers()
            ->whereIn('id', $action->server_ids)
            ->where('is_active', true)
            ->where('is_installed', true)
            ->get();
        $portsByServer = [];
        foreach ($servers as $server) {
            $portsByServer[(string) $server->getKey()] = $port;
        }
        $outcomes = $pmta->runConcurrently(
            $servers->all(),
            $portsByServer,
            $action->action,
            $action->queue,
            $action->tags,
        );

        foreach ($servers as $server) {
            $outcome = $outcomes[(string) $server->getKey()] ?? null;
            if ($outcome === null) {
                continue;
            }
            if ($outcome['error'] !== null) {
                $audit->log($workspace, $actor, $server, 'scheduled.'.$action->action, $outcome['error']);
                $this->error($server->name.': '.$outcome['error']);

                continue;
            }

            $result = implode("\n", $outcome['results']);
            $audit->logSuccessfulAction(
                $workspace,
                $actor,
                $server,
                $action->action,
                $result,
                auditAction: 'scheduled.'.$action->action,
            );
            $this->line($server->name.': '.implode(' | ', $outcome['results']));
        }
        $action->forceFill(['last_run_at' => now()])->save();

        return self::SUCCESS;
    }
}
