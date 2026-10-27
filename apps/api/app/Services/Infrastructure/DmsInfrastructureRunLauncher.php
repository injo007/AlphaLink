<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Starts a detached local Artisan worker so the web process can serve live log polls. */
class DmsInfrastructureRunLauncher
{
    public function launch(string $workspaceId, string $runId): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            throw new RuntimeException('Background installation runs require a Unix-compatible AlphaLink host.');
        }

        foreach ([$workspaceId, $runId] as $identifier) {
            if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $identifier)) {
                throw new RuntimeException('The installation run identifier is invalid.');
            }
        }

        $artisan = base_path('artisan');
        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            $artisan,
            'infrastructure:execute-run',
            $workspaceId,
            $runId,
        ]));
        $script = 'nohup '.$command.' > /dev/null 2>&1 < /dev/null & echo $!';

        $result = Process::timeout(5)->run(['/bin/sh', '-c', $script]);
        $pid = trim($result->output());
        if ($result->failed() || ! preg_match('/^[1-9][0-9]*$/', $pid)) {
            throw new RuntimeException('The background installation worker could not be started.');
        }

        return (int) $pid;
    }
}
