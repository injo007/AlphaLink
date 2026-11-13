<?php

namespace App\Jobs;

use App\Models\WorkspaceSpfSearchRun;
use App\Services\Spf\AlphaLinkSpfSearchRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunWorkspaceSpfComparison implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public int $searchRunId)
    {
        $this->onConnection('spf_comparisons');
        $this->onQueue('spf-compares');
    }

    public function tags(): array
    {
        return ['spf-compare', 'spf-compare:'.$this->searchRunId];
    }

    public function handle(AlphaLinkSpfSearchRunner $runner): void
    {
        $runner->run($this->searchRunId);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        WorkspaceSpfSearchRun::query()
            ->whereKey($this->searchRunId)
            ->whereIn('status', [WorkspaceSpfSearchRun::STATUS_PENDING, WorkspaceSpfSearchRun::STATUS_PROCESSING])
            ->update([
                'status' => WorkspaceSpfSearchRun::STATUS_FAILED,
                'stage' => 'failed',
                'failure' => 'The SPF comparison worker stopped unexpectedly. Check queue health and application logs.',
                'heartbeat_at' => now(),
                'ended_at' => now(),
            ]);
    }
}
