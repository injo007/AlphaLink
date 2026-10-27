<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsBulkInstallRunProcessPoolCoordinator;
use App\Services\Infrastructure\DmsBulkInstallTranscriptStore;
use App\Services\Infrastructure\DmsCentos9ReinstallWorkflow;
use Illuminate\Console\Command;
use Throwable;

class ExecuteInfrastructureRun extends Command
{
    protected $signature = 'infrastructure:execute-run {workspace} {run}';

    protected $description = 'Execute a previously validated background infrastructure run.';

    public function handle(
        DmsBulkInstallTranscriptStore $transcripts,
        DmsBulkInstallRunProcessPoolCoordinator $installCoordinator,
        DmsCentos9ReinstallWorkflow $reinstallWorkflow,
    ): int {
        $workspaceId = (string) $this->argument('workspace');
        $runId = (string) $this->argument('run');
        $payload = $transcripts->readRunPayload($workspaceId, $runId);

        if ($payload === null || ! is_string($payload['kind'] ?? null)) {
            $this->error('The infrastructure run request is unavailable.');

            return self::FAILURE;
        }

        $transcripts->setRunStatus($workspaceId, $runId, 'running');

        try {
            $workspace = Workspace::query()->findOrFail($workspaceId);
            $actor = User::query()->findOrFail((string) ($payload['actor_id'] ?? ''));
            $membership = $workspace->users()->whereKey($actor->getKey())->first();
            if (! $membership) {
                throw new \RuntimeException('The operator no longer has access to this workspace.');
            }

            $results = match ($payload['kind']) {
                'bulk_install', 'single_install' => $this->executeInstall(
                    $payload,
                    $workspace,
                    $actor,
                    $runId,
                    $transcripts,
                    $installCoordinator,
                ),
                'reinstall' => $this->executeReinstall(
                    $payload,
                    $workspace,
                    $actor,
                    $runId,
                    $transcripts,
                    $reinstallWorkflow,
                ),
                default => throw new \RuntimeException('The infrastructure run type is invalid.'),
            };

            $failed = collect($results)->contains(fn (array $result): bool => ($result['status'] ?? null) !== 'succeeded');
            $status = $failed ? 'failed' : 'succeeded';
            $transcripts->append(
                $workspaceId,
                $runId,
                now()->format('[d/m/Y-H:i:s]').($failed ? ' Installation finished with failed rows.' : ' Installation complete.'),
            );
            $transcripts->setRunStatus($workspaceId, $runId, $status, $results);

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $message = $exception->getMessage() !== '' ? $exception->getMessage() : 'The infrastructure run failed.';
            $transcripts->append($workspaceId, $runId, now()->format('[d/m/Y-H:i:s]').' Error: '.$message);
            $transcripts->setRunStatus($workspaceId, $runId, 'failed', [], $message);
            $this->error('Infrastructure run failed.');

            return self::FAILURE;
        }
    }

    /** @return list<array<string, mixed>> */
    private function executeInstall(
        array $run,
        Workspace $workspace,
        User $actor,
        string $runId,
        DmsBulkInstallTranscriptStore $transcripts,
        DmsBulkInstallRunProcessPoolCoordinator $coordinator,
    ): array {
        $enabled = $run['kind'] === 'single_install'
            ? config('infrastructure.single_install_execution_enabled', false)
            : config('infrastructure.bulk_install_execution_enabled', false);
        if (! $enabled) {
            throw new \RuntimeException('Installation execution was disabled before the worker started.');
        }

        $payload = $run['payload'] ?? null;
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
            throw new \RuntimeException('The installation payload is invalid.');
        }

        $result = $coordinator->execute($payload, $actor, $workspace, $runId);

        return $result['results'];
    }

    /** @return list<array<string, mixed>> */
    private function executeReinstall(
        array $run,
        Workspace $workspace,
        User $actor,
        string $runId,
        DmsBulkInstallTranscriptStore $transcripts,
        DmsCentos9ReinstallWorkflow $workflow,
    ): array {
        if (! config('infrastructure.reinstall_execution_enabled', false)) {
            throw new \RuntimeException('Server reinstall execution was disabled before the worker started.');
        }

        $serverId = $run['server_id'] ?? null;
        $payload = $run['payload'] ?? null;
        if (! is_string($serverId) || ! is_array($payload)) {
            throw new \RuntimeException('The reinstall request is invalid.');
        }

        $server = $workspace->managedServers()
            ->where('is_active', true)
            ->where('is_installed', true)
            ->whereKey($serverId)
            ->firstOrFail();
        $log = static function (string $message) use ($transcripts, $workspace, $runId): void {
            $transcripts->append(
                (string) $workspace->getKey(),
                $runId,
                now()->format('Y-m-d H:i:s').': '.$message,
            );
        };

        $result = $workflow->run(
            $workspace,
            $actor,
            $server,
            $payload['data'],
            (int) $payload['pmta_version'],
            $payload['options'],
            $log,
            (string) $run['request_id'],
        );

        return [[
            'server_id' => $serverId,
            'status' => 'succeeded',
            'result' => $result,
        ]];
    }
}
