<?php

namespace App\Services\Spf;

use App\Models\WorkspaceSpfScan;
use Closure;
use Symfony\Component\Process\Process;

class DmsSpfScanLauncher
{
    /** @param (Closure(WorkspaceSpfScan): ?int)|null $launcher */
    public function __construct(private readonly ?Closure $launcher = null) {}

    public function start(WorkspaceSpfScan $scan): bool
    {
        $pid = $this->launcher !== null ? ($this->launcher)($scan) : $this->startProcess($scan);
        if (! is_int($pid) || $pid < 1) {
            $scan->update([
                'status' => WorkspaceSpfScan::STATUS_FAILED,
                'failure' => 'Unable to start the SPF scan worker.',
                'ended_at' => now(),
            ]);

            return false;
        }

        $scan->update(['status' => WorkspaceSpfScan::STATUS_SCANNING, 'pid' => $pid, 'started_at' => now()]);

        return true;
    }

    private function startProcess(WorkspaceSpfScan $scan): ?int
    {
        $arguments = [PHP_BINARY, base_path('artisan'), 'spf:scan-workspace', (string) $scan->getKey(), '--workers='.$scan->workers];
        $command = 'nohup '.implode(' ', array_map('escapeshellarg', $arguments)).' >/dev/null 2>&1 < /dev/null & echo $!';
        $process = Process::fromShellCommandline($command, base_path());
        $process->setTimeout(5);
        $process->run();
        $pid = trim($process->getOutput());

        return $process->isSuccessful() && ctype_digit($pid) ? (int) $pid : null;
    }
}
