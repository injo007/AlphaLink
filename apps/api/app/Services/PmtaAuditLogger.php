<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ManagedServer;
use App\Models\PmtaActionHistory;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class PmtaAuditLogger
{
    public function log(Workspace $workspace, User $actor, ManagedServer $server, string $action, string $result, ?string $requestId = null): void
    {
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $actor->getKey(),
            'action' => 'pmta.action',
            'subject_type' => 'managed_server',
            'subject_id' => $server->getKey(),
            'request_id' => $requestId,
            'properties' => ['pmta_action' => $action, 'result' => $result],
            'occurred_at' => now(),
        ]);
    }

    public function logSuccessfulAction(
        Workspace $workspace,
        User $actor,
        ManagedServer $server,
        string $action,
        string $result,
        ?string $requestId = null,
        ?string $auditAction = null,
    ): void {
        DB::transaction(function () use ($workspace, $actor, $server, $action, $result, $requestId, $auditAction): void {
            $this->log($workspace, $actor, $server, $auditAction ?? $action, $result, $requestId);

            PmtaActionHistory::query()->create([
                'workspace_id' => $workspace->getKey(),
                'managed_server_id' => $server->getKey(),
                'actor_user_id' => $actor->getKey(),
                'action' => $action,
                'result' => $result,
            ]);
        });
    }
}
