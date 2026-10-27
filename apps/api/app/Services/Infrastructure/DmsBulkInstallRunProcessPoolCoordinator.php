<?php

namespace App\Services\Infrastructure;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Throwable;

class DmsBulkInstallRunProcessPoolCoordinator
{
    public function __construct(
        private readonly DmsBulkInstallRowProcessPayload $rowPayload,
        private readonly DmsBulkInstallTranscriptStore $transcripts,
    ) {}

    /**
     * @param  array{data: list<array{id: string, domain: string, ip_assignments?: list<array{ip: string, type: string, main: bool, sub: string, domain_id: string}>}>, key: string, pmta_version: int, tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool}  $payload
     * @return array{exit_code: int, results: list<array{server_id: string, status: string}>, logs: list<string>}
     */
    public function execute(array $payload, User $user, Workspace $workspace, string $runId): array
    {
        $workspaceId = (string) $workspace->getKey();
        $this->transcripts->append($workspaceId, $runId, $this->timestampedLog('Installation Started'));

        try {
            $processes = Process::pool(function (Pool $pool) use ($payload, $user, $workspaceId, $runId): void {
                foreach ($payload['data'] as $row) {
                    $encoded = $this->rowPayload->encode(
                        $workspaceId,
                        (string) $user->getKey(),
                        $row['id'],
                        $row['domain'],
                        $runId,
                        [
                            'pmta_version' => $payload['pmta_version'],
                            'tracking' => $payload['tracking'],
                            'proxy' => $payload['proxy'],
                            'imap' => $payload['imap'],
                            'spf' => $payload['spf'],
                            'dkim' => $payload['dkim'],
                            'dmarc' => $payload['dmarc'],
                            'https' => $payload['https'],
                        ],
                        $row['ip_assignments'] ?? null,
                    );

                    $pool->as($row['id'])
                        ->command([
                            PHP_BINARY,
                            base_path('artisan'),
                            'infrastructure:install-bulk-row',
                            $encoded,
                        ])
                        ->path(base_path())
                        ->forever();
                }
            })->start()->wait();
        } catch (Throwable) {
            $line = $this->timestampedLog('- [unknown:unknown] - Installer process pool could not complete.');
            $this->transcripts->append($workspaceId, $runId, $line);

            return [
                'exit_code' => 1,
                'results' => array_map(
                    static fn (array $row): array => ['server_id' => $row['id'], 'status' => 'failed'],
                    $payload['data'],
                ),
                'logs' => $this->readLogs($workspaceId, $runId),
            ];
        }

        $results = [];
        foreach ($payload['data'] as $row) {
            $serverId = $row['id'];
            $process = $processes->collect()->get($serverId);
            $output = $process?->output() ?? '';

            if ($process !== null && str_contains($output, DmsBulkInstallRowProcessPayload::RESULT_SUCCEEDED)) {
                $status = 'succeeded';
            } else {
                $status = 'failed';
                if ($process === null || $process->failed()) {
                    $line = $this->timestampedLog(sprintf(
                        '- [unknown:%s] - Installer process ended before it reported a row result.',
                        $serverId,
                    ));
                    $this->transcripts->append($workspaceId, $runId, $line);
                }
            }

            $results[] = ['server_id' => $serverId, 'status' => $status];
        }

        return [
            'exit_code' => 0,
            'results' => $results,
            'logs' => $this->readLogs($workspaceId, $runId),
        ];
    }

    /** @return list<string> */
    private function readLogs(string $workspaceId, string $runId): array
    {
        $transcript = $this->transcripts->read($workspaceId, $runId) ?? '';
        $lines = preg_split('/\r?\n/', rtrim($transcript, "\r\n"));

        return is_array($lines) && $lines !== [''] ? $lines : [];
    }

    private function timestampedLog(string $message): string
    {
        return now()->format('[d/m/Y-H:i:s]').' '.$message;
    }
}
