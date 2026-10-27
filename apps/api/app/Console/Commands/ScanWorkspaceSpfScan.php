<?php

namespace App\Console\Commands;

use App\Models\WorkspaceSpfScan;
use App\Services\Spf\DmsSpfScanProcessor;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ScanWorkspaceSpfScan extends Command
{
    protected $signature = 'spf:scan-workspace {scan : Workspace SPF scan ID} {--worker= : Worker index} {--workers=1 : Worker count}';

    protected $description = 'Scan domains from a workspace SPF upload.';

    public function handle(DmsSpfScanProcessor $processor): int
    {
        $scanId = (int) $this->argument('scan');
        $workers = max(1, min(8, (int) $this->option('workers')));
        $worker = $this->option('worker');
        if ($worker !== null) {
            return $processor->runShard($scanId, $workers, max(0, min($workers - 1, (int) $worker)));
        }

        if (! $processor->prepare($scanId, $workers)) {
            return self::FAILURE;
        }
        $scan = WorkspaceSpfScan::query()->find($scanId);
        if (! $scan) {
            return self::FAILURE;
        }

        if ($scan->total_lines === 0) {
            $processor->finish($scanId, true);

            return self::SUCCESS;
        }

        $processes = [];
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
        $scan->update([
            'pid' => getmypid(),
            'worker_pids' => array_values(array_filter(array_map(fn (Process $process) => $process->getPid(), $processes))),
        ]);

        $successful = true;
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
            }
        } while ($running);

        $processor->finish($scanId, $successful);

        return $successful ? self::SUCCESS : self::FAILURE;
    }
}
