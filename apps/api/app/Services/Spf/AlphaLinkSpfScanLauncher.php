<?php

namespace App\Services\Spf;

use App\Jobs\RunWorkspaceSpfScan;
use App\Models\WorkspaceSpfScan;
use Closure;
use Throwable;

class AlphaLinkSpfScanLauncher
{
    /** @param (Closure(WorkspaceSpfScan): ?int)|null $launcher */
    public function __construct(private readonly ?Closure $launcher = null) {}

    public function start(WorkspaceSpfScan $scan): bool
    {
        try {
            if ($this->launcher !== null) {
                $pid = ($this->launcher)($scan);
                if (! is_int($pid) || $pid < 1) {
                    throw new \RuntimeException('The SPF scan worker could not start.');
                }

                $scan->update([
                    'status' => WorkspaceSpfScan::STATUS_SCANNING,
                    'pid' => $pid,
                    'started_at' => now(),
                    'stage' => 'scanning',
                    'heartbeat_at' => now(),
                ]);

                return true;
            }

            $scan->update([
                'status' => WorkspaceSpfScan::STATUS_PENDING,
                'stage' => 'queued',
                'pid' => null,
                'worker_pids' => null,
                'started_at' => null,
                'ended_at' => null,
                'failure' => null,
                'heartbeat_at' => null,
                'progress_at' => null,
                'current_domain' => null,
            ]);
            RunWorkspaceSpfScan::dispatch((int) $scan->getKey(), (int) $scan->workers);
        } catch (Throwable $exception) {
            report($exception);
            $scan->update([
                'status' => WorkspaceSpfScan::STATUS_FAILED,
                'stage' => 'failed',
                'stage' => 'failed',
                'failure' => 'The SPF scan worker could not start. Check application logs.',
                'ended_at' => now(),
            ]);

            return false;
        }

        return true;
    }
}
