<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\Workspace\WorkspaceBackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('workspace:backup {--workspace= : Run backup for one workspace UUID only} {--force : Execute backup even when allow_backup is disabled}')]
#[Description('Create compressed workspace database/snapshot backups and notify Telegram')]
class RunWorkspaceBackup extends Command
{
    public function handle(WorkspaceBackupService $backupService): int
    {
        $workspaceId = $this->option('workspace');
        $force = (bool) $this->option('force');
        $failed = false;
        $processed = 0;

        Workspace::query()
            ->with('appSettings')
            ->when(is_string($workspaceId) && $workspaceId !== '', fn ($q) => $q->whereKey($workspaceId))
            ->orderBy('id')
            ->chunkById(50, function ($workspaces) use ($backupService, $force, &$failed, &$processed): void {
                foreach ($workspaces as $workspace) {
                    $result = $backupService->run($workspace, null, $force);
                    if ($result['skipped']) {
                        continue;
                    }
                    $processed++;
                    if ($result['success']) {
                        $this->info("[{$workspace->name}] {$result['message']}");
                    } else {
                        $failed = true;
                        $this->error("[{$workspace->name}] Backup failed: {$result['message']}");
                    }
                }
            });

        if ($processed === 0 && ! $failed) {
            $this->line('No eligible workspaces with allow_backup enabled.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
