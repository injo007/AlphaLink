<?php

namespace App\Jobs;

use App\Models\WorkspaceSpfScan;
use App\Services\Spf\AlphaLinkSpfScanProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class RunWorkspaceSpfScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public int $scanId, public int $workers, public bool $resume = false)
    {
        $this->onConnection('spf_scans');
        $this->onQueue('spf-scans');
    }

    public function tags(): array
    {
        return ['spf-scan', 'spf-scan:'.$this->scanId];
    }

    public function handle(AlphaLinkSpfScanProcessor $processor): void
    {
        $scan = WorkspaceSpfScan::query()->find($this->scanId);
        if (! $scan) {
            return;
        }
        if ($scan->status === WorkspaceSpfScan::STATUS_KILLED) {
            $processor->finish($this->scanId, false);

            return;
        }
        if ($this->resume) {
            if ($scan->status !== WorkspaceSpfScan::STATUS_SCANNING || $scan->partition_offsets === null) {
                return;
            }
        } elseif ($scan->status !== WorkspaceSpfScan::STATUS_PENDING) {
            return;
        }

        if (! $this->resume) {
            $scan->update(['stage' => 'preparing', 'heartbeat_at' => now()]);
        }
        $exitCode = Artisan::call('spf:scan-workspace', [
            'scan' => (string) $this->scanId,
            '--workers' => $this->workers,
            '--resume' => $this->resume,
        ]);
        $scan->refresh();

        if ($scan->status === WorkspaceSpfScan::STATUS_KILLED || $exitCode === 0) {
            return;
        }

        throw new \RuntimeException('The SPF scan worker exited before completing the scan.');
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
        WorkspaceSpfScan::query()
            ->whereKey($this->scanId)
            ->whereIn('status', [WorkspaceSpfScan::STATUS_PENDING, WorkspaceSpfScan::STATUS_SCANNING, WorkspaceSpfScan::STATUS_PAUSED])
            ->update([
                'status' => WorkspaceSpfScan::STATUS_FAILED,
                'stage' => 'failed',
                'failure' => 'The SPF scan worker stopped unexpectedly. Check queue health and application logs.',
                'ended_at' => now(),
            ]);
    }
}
