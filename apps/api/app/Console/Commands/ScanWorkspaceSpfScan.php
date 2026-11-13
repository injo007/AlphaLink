<?php

namespace App\Console\Commands;

use App\Models\WorkspaceSpfScan;
use App\Services\Spf\AlphaLinkSpfScanProcessor;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ScanWorkspaceSpfScan extends Command
{
    protected $signature = 'spf:scan-workspace {scan : Workspace SPF scan ID} {--worker= : Worker index} {--workers=1 : Worker count}';

    protected $description = 'Scan domains from a workspace SPF upload.';

    public function handle(AlphaLinkSpfScanProcessor $processor): int
    {
        $scanId = (int) $this->argument('scan');
        $maximumWorkers = min(16, max(1, (int) config('spf.max_workers', 8)));
        $workers = max(1, min($maximumWorkers, (int) $this->option('workers')));
        $worker = $this->option('worker');
        if ($worker !== null) {
            return $processor->runShard($scanId, $workers, max(0, min($workers - 1, (int) $worker)));
        }

        try {
            if (! $processor->prepare($scanId, $workers)) {
                return self::FAILURE;
            }
        } catch (\Throwable $exception) {
            report($exception);
            WorkspaceSpfScan::query()->whereKey($scanId)->where('status', WorkspaceSpfScan::STATUS_PENDING)->update([
                'status' => WorkspaceSpfScan::STATUS_FAILED,
                'stage' => 'failed',
                'failure' => 'The SPF scan could not be prepared. Check application logs.',
                'ended_at' => now(),
            ]);
            $processor->finish($scanId, false);

            return self::FAILURE;
        }
        $scan = WorkspaceSpfScan::query()->find($scanId);
        if (! $scan) {
            return self::FAILURE;
        }
        $workers = max(1, min($maximumWorkers, (int) $scan->workers));

        if ($scan->total_lines === 0) {
            $processor->finish($scanId, true);

            return self::SUCCESS;
        }

        $processes = [];
        try {
            for ($index = 0; $index < $workers; $index++) {
                $process = new Process([
                    PHP_BINARY,
                    base_path('artisan'),
                    'spf:scan-workspace',
                    (string) $scanId,
                    '--worker='.$index,
                    '--workers='.$workers,
                ], base_path());
                $process->start();
                $processes[] = $process;
            }
        } catch (\Throwable $exception) {
            report($exception);
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0.2);
                }
            }
            $scan->update(['status' => WorkspaceSpfScan::STATUS_FAILED, 'stage' => 'failed', 'failure' => 'A scan worker could not be started. Check application logs.']);
            $processor->finish($scanId, false);

            return self::FAILURE;
        }
        $scan->update([
            'pid' => getmypid(),
            'worker_pids' => array_values(array_filter(array_map(fn (Process $process) => $process->getPid(), $processes))),
        ]);

        $successful = true;
        $lastHeartbeatAt = microtime(true);
        do {
            $scan->refresh();
            if ($scan->status === WorkspaceSpfScan::STATUS_KILLED) {
                foreach ($processes as $process) {
                    if ($process->isRunning()) {
                        $process->stop(0.2);
                    }
                }
                $successful = false;
                break;
            }
            if ($scan->status === WorkspaceSpfScan::STATUS_FAILED) {
                foreach ($processes as $process) {
                    if ($process->isRunning()) {
                        $process->stop(0.2);
                    }
                }
                $successful = false;
                break;
            }

            $running = false;
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $running = true;
                } elseif (! $process->isSuccessful()) {
                    $successful = false;
                }
            }
            if ($running) {
                usleep(250_000);
                if (microtime(true) - $lastHeartbeatAt >= 10) {
                    $scan->forceFill(['heartbeat_at' => now()])->save();
                    $lastHeartbeatAt = microtime(true);
                }
            }
        } while ($running);

        $processor->finish($scanId, $successful);

        return $successful ? self::SUCCESS : self::FAILURE;
    }
}
