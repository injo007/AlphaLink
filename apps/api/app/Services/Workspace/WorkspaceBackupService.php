<?php

namespace App\Services\Workspace;

use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use App\Services\Telegram\TelegramAlertService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use phpseclib3\Net\SFTP;
use Throwable;

class WorkspaceBackupService
{
    public function __construct(
        private readonly TelegramAlertService $telegramAlerts,
    ) {}

    /**
     * Execute a workspace backup snapshot (optionally syncing to configured remote SFTP server)
     * and dispatch the configured Telegram backup notification.
     *
     * @return array{success: bool, skipped: bool, filename: ?string, size: string, destination: string, message: string}
     */
    public function run(Workspace $workspace, ?User $actor = null, bool $force = false): array
    {
        $settings = $workspace->appSettings?->settings ?? [];
        $allowBackup = filter_var($settings['allow_backup'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $force && ! $allowBackup) {
            return [
                'success' => false,
                'skipped' => true,
                'filename' => null,
                'size' => '0 B',
                'destination' => 'Skipped',
                'message' => 'Automated backups are disabled for this workspace.',
            ];
        }

        $triggeredBy = $actor ? ($actor->name ?: $actor->email) : 'Scheduled Cron';
        $timestamp = now()->format('Ymd_His');
        $workspaceDir = storage_path('app/private/backups/'.$workspace->getKey());
        File::ensureDirectoryExists($workspaceDir);

        $isPostgres = config('database.default') === 'pgsql';
        $extension = $isPostgres && $this->hasPgDump() ? 'sql.gz' : 'json.gz';
        $filename = "alphalink-backup-{$timestamp}.{$extension}";
        $fullPath = $workspaceDir.DIRECTORY_SEPARATOR.$filename;
        $destination = 'Local Private Archive';

        try {
            if ($extension === 'sql.gz') {
                $this->dumpPostgresArchive($fullPath);
            } else {
                $this->writeSnapshotArchive($workspace, $fullPath);
            }

            clearstatcache(true, $fullPath);
            $bytes = File::exists($fullPath) ? (int) File::size($fullPath) : 0;
            $sizeLabel = $this->formatBytes($bytes);

            $remoteServer = trim((string) ($settings['backup_server'] ?? ''));
            $remoteUser = trim((string) ($settings['backup_username'] ?? ''));
            $remotePass = (string) ($settings['backup_password'] ?? '');
            $remotePort = max(1, min(65535, (int) ($settings['backup_port'] ?? 22)));
            $remoteBasePath = trim((string) ($settings['backup_path'] ?? '/home')) ?: '/home';

            if ($remoteServer !== '' && $remoteUser !== '' && $remotePass !== '') {
                $remotePath = rtrim($remoteBasePath, '/').'/'.$filename;
                $sftp = new SFTP($remoteServer, $remotePort, 10);
                if (! $sftp->login($remoteUser, $remotePass)) {
                    throw new \RuntimeException("SFTP authentication failed for {$remoteServer}:{$remotePort}.");
                }
                if (! $sftp->put($remotePath, $fullPath, SFTP::SOURCE_LOCAL_FILE)) {
                    throw new \RuntimeException("Failed uploading backup to {$remoteServer}:{$remotePath}.");
                }
                $destination = "Remote SFTP ({$remoteServer}) + Local Archive";
            }

            $this->pruneOldArchives($workspaceDir, 10);

            AuditEvent::query()->create([
                'workspace_id' => $workspace->getKey(),
                'actor_user_id' => $actor?->getKey(),
                'action' => 'workspace.backup_completed',
                'subject_type' => 'workspace_backup',
                'subject_id' => $filename,
                'occurred_at' => now(),
                'properties' => [
                    'filename' => $filename,
                    'bytes' => $bytes,
                    'destination' => $destination,
                ],
            ]);

            $this->safeNotify($workspace, [
                'status' => 'Completed',
                'filename' => $filename,
                'size' => $sizeLabel,
                'destination' => $destination,
                'triggered_by' => $triggeredBy,
                'details' => 'Workspace backup verified cleanly.',
            ], $actor?->getKey());

            return [
                'success' => true,
                'skipped' => false,
                'filename' => $filename,
                'size' => $sizeLabel,
                'destination' => $destination,
                'message' => "Backup {$filename} ({$sizeLabel}) completed -> {$destination}.",
            ];
        } catch (Throwable $e) {
            try {
                AuditEvent::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'actor_user_id' => $actor?->getKey(),
                    'action' => 'workspace.backup_failed',
                    'subject_type' => 'workspace_backup',
                    'subject_id' => $filename,
                    'occurred_at' => now(),
                    'properties' => ['error' => $e->getMessage()],
                ]);
            } catch (Throwable) {
            }

            $this->safeNotify($workspace, [
                'status' => 'Failed',
                'filename' => $filename,
                'size' => '0 B',
                'destination' => $destination,
                'triggered_by' => $triggeredBy,
                'details' => $e->getMessage(),
            ], $actor?->getKey());

            return [
                'success' => false,
                'skipped' => false,
                'filename' => null,
                'size' => '0 B',
                'destination' => $destination,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function safeNotify(Workspace $workspace, array $context, ?int $actorUserId): void
    {
        try {
            $this->telegramAlerts->notifyEvent($workspace, 'backup', $context, $actorUserId);
        } catch (Throwable) {
            // Backup state must still return cleanly even if Telegram cannot be reached.
        }
    }

    private function hasPgDump(): bool
    {
        try {
            return Process::timeout(3)->run(['which', 'pg_dump'])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function dumpPostgresArchive(string $destinationGzPath): void
    {
        $conn = config('database.connections.pgsql', []);
        $host = (string) ($conn['host'] ?? '127.0.0.1');
        $port = (string) ($conn['port'] ?? '5432');
        $database = (string) ($conn['database'] ?? 'alphalink');
        $username = (string) ($conn['username'] ?? 'postgres');
        $password = (string) ($conn['password'] ?? '');

        $res = Process::timeout(120)
            ->env(['PGPASSWORD' => $password])
            ->run([
                'pg_dump',
                '--no-owner',
                '--no-privileges',
                '-h', $host,
                '-p', $port,
                '-U', $username,
                $database,
            ]);

        if (! $res->successful()) {
            throw new \RuntimeException('pg_dump failed: '.trim($res->errorOutput()));
        }

        $gz = gzopen($destinationGzPath, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('Could not open compressed backup archive for writing.');
        }
        gzwrite($gz, $res->output());
        gzclose($gz);
    }

    private function writeSnapshotArchive(Workspace $workspace, string $destinationGzPath): void
    {
        $payload = [
            'generated_at' => now()->toIso8601String(),
            'workspace' => [
                'id' => $workspace->getKey(),
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'counts' => [
                'campaigns' => Campaign::query()->where('workspace_id', $workspace->getKey())->count(),
                'sponsors' => WorkspaceSponsor::query()->where('workspace_id', $workspace->getKey())->count(),
                'offers' => WorkspaceOffer::query()->where('workspace_id', $workspace->getKey())->count(),
                'servers' => ManagedServer::query()->where('workspace_id', $workspace->getKey())->count(),
                'domains' => SendingDomain::query()->where('workspace_id', $workspace->getKey())->count(),
            ],
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
        $gz = gzopen($destinationGzPath, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('Could not open backup snapshot file for writing.');
        }
        gzwrite($gz, $json);
        gzclose($gz);
    }

    private function pruneOldArchives(string $dir, int $keep): void
    {
        $files = glob($dir.DIRECTORY_SEPARATOR.'alphalink-backup-*.*');
        if (! is_array($files) || count($files) <= $keep) {
            return;
        }
        rsort($files);
        foreach (array_slice($files, $keep) as $oldFile) {
            @unlink($oldFile);
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / 1048576, 2).' MB';
    }
}
