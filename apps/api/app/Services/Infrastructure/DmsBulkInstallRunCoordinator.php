<?php

namespace App\Services\Infrastructure;

use Closure;
use Exception;

/**
 * Models DMS's per-server try/catch and log semantics independently of remote effects.
 * A runner must be explicitly supplied; the app has no production remote runner.
 */
class DmsBulkInstallRunCoordinator
{
    public function __construct(private readonly DmsBulkInstallRowRunner $runner) {}

    /**
     * @param  array{data: list<array{id: string, domain: string}>, key: string, pmta_version: int, tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool}  $payload
     * @param  Closure(string): string  $serverNameForId
     * @param  Closure(string): void|null  $transcriptWriter
     * @return array{exit_code: int, results: list<array{server_id: string, status: string}>, logs: list<string>}
     */
    public function execute(array $payload, Closure $serverNameForId, ?Closure $transcriptWriter = null): array
    {
        $options = [
            'pmta_version' => $payload['pmta_version'],
            'tracking' => $payload['tracking'],
            'proxy' => $payload['proxy'],
            'imap' => $payload['imap'],
            'spf' => $payload['spf'],
            'dkim' => $payload['dkim'],
            'dmarc' => $payload['dmarc'],
            'https' => $payload['https'],
        ];
        $results = [];
        $initialLog = $this->timestampedLog('Installation Started');
        $logs = [$initialLog];
        $transcriptWriter?->__invoke($initialLog);

        foreach ($payload['data'] as $row) {
            $serverId = $row['id'];
            $serverName = $serverId;

            try {
                $serverName = $serverNameForId($serverId);
                $appendLog = function (string $message) use (&$logs, $serverName, $serverId, $transcriptWriter): void {
                    $line = $this->timestampedLog(sprintf('- [%s:%s] - %s', $serverName, $serverId, $message));
                    $logs[] = $line;
                    $transcriptWriter?->__invoke($line);
                };
                $this->runner->install($row, $options, $appendLog);
                $results[] = ['server_id' => $serverId, 'status' => 'succeeded'];
            } catch (Exception $exception) {
                // DMS catches per-server exceptions; they are logged without failing the parent command.
                $line = $this->timestampedLog(sprintf('- [%s:%s] - Error : %s', $serverName, $serverId, $exception->getMessage()));
                $logs[] = $line;
                $transcriptWriter?->__invoke($line);
                $results[] = ['server_id' => $serverId, 'status' => 'failed'];
            }
        }

        return ['exit_code' => 0, 'results' => $results, 'logs' => $logs];
    }

    private function timestampedLog(string $message): string
    {
        return now()->format('[d/m/Y-H:i:s]').' '.$message;
    }
}
