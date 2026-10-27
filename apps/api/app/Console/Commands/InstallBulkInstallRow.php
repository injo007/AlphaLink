<?php

namespace App\Console\Commands;

use App\Models\ManagedServer;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsBulkInstallPreparedRowRunner;
use App\Services\Infrastructure\DmsBulkInstallRowPreparation;
use App\Services\Infrastructure\DmsBulkInstallRowProcessPayload;
use App\Services\Infrastructure\DmsBulkInstallRowRunner;
use App\Services\Infrastructure\DmsBulkInstallTranscriptStore;
use Exception;
use Illuminate\Console\Command;

class InstallBulkInstallRow extends Command
{
    protected $signature = 'infrastructure:install-bulk-row {payload}';

    protected $description = 'Run one isolated DMS-compatible bulk-install row.';

    public function handle(
        DmsBulkInstallRowProcessPayload $rowPayload,
        DmsBulkInstallTranscriptStore $transcripts,
        DmsBulkInstallRowPreparation $preparation,
    ): int {
        $payload = $rowPayload->decode((string) $this->argument('payload'));
        $workspaceId = $payload['workspace_id'];
        $runId = $payload['run_id'];
        if ($transcripts->read($workspaceId, $runId) === null) {
            $this->line(DmsBulkInstallRowProcessPayload::RESULT_FAILED);

            return self::FAILURE;
        }

        if (! config('infrastructure.bulk_install_execution_enabled', false)) {
            $transcripts->append(
                $workspaceId,
                $runId,
                now()->format('[d/m/Y-H:i:s]').' - [unknown:unknown] - Error : Bulk-install execution is disabled.',
            );
            $this->line(DmsBulkInstallRowProcessPayload::RESULT_FAILED);

            return self::SUCCESS;
        }

        $workspace = Workspace::query()->findOrFail($workspaceId);
        $user = User::query()->findOrFail($payload['user_id']);
        $server = $workspace->managedServers()->whereKey($payload['server_id'])->first();
        $serverName = $server instanceof ManagedServer ? $server->name : $payload['server_id'];
        $append = function (string $message) use ($transcripts, $workspaceId, $runId, $serverName, $payload): void {
            $line = sprintf('- [%s:%s] - %s', $serverName, $payload['server_id'], $message);
            $transcripts->append($workspaceId, $runId, now()->format('[d/m/Y-H:i:s]').' '.$line);
        };

        if (! app()->bound(DmsBulkInstallRowRunner::class)) {
            $append('Error : Bulk-install runner is unavailable.');
            $this->line(DmsBulkInstallRowProcessPayload::RESULT_FAILED);

            return self::SUCCESS;
        }

        $runner = new DmsBulkInstallPreparedRowRunner(
            app(DmsBulkInstallRowRunner::class),
            $preparation,
            $user,
            $workspace,
        );
        $append('Prepare Installation');

        try {
            $runner->install(
                [
                    'id' => $payload['server_id'],
                    'domain' => $payload['domain_id'],
                    ...(isset($payload['ip_assignments']) ? ['ip_assignments' => $payload['ip_assignments']] : []),
                ],
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
                $append,
            );
        } catch (Exception $exception) {
            $append('Error : '.$exception->getMessage());
            $this->line(DmsBulkInstallRowProcessPayload::RESULT_FAILED);

            return self::SUCCESS;
        }

        $this->line(DmsBulkInstallRowProcessPayload::RESULT_SUCCEEDED);

        return self::SUCCESS;
    }
}
