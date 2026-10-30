<?php

namespace App\Jobs;

use App\Models\WorkspaceGoogleProject;
use App\Services\Workspace\DmsWorkspaceDirectorySync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncWorkspaceGoogleProjectAccounts implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct(public int $projectId) {}

    public function uniqueId(): string
    {
        return (string) $this->projectId;
    }

    public function handle(DmsWorkspaceDirectorySync $directory): void
    {
        $project = WorkspaceGoogleProject::query()->find($this->projectId);
        if (! $project) {
            return;
        }

        try {
            $directory->sync($project);
        } catch (Throwable $exception) {
            $project->forceFill([
                'is_active' => false,
                'sync_error' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $project = WorkspaceGoogleProject::query()->find($this->projectId);
        if (! $project) {
            return;
        }

        $project->forceFill([
            'is_active' => false,
            'sync_error' => mb_substr($exception?->getMessage() ?? 'Google account synchronization failed.', 0, 1000),
        ])->save();
    }
}
