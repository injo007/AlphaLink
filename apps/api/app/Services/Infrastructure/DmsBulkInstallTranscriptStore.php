<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Stores workspace-scoped append-only bulk-install transcripts on local storage. */
class DmsBulkInstallTranscriptStore
{
    private readonly string $basePath;

    /**
     * Accepts a custom base directory so transcript behavior can be tested offline.
     */
    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? storage_path('app/private/bulk-install-runs');
    }

    public function createRun(string $workspaceId, ?string $logKey = null): string
    {
        $this->validateUuid($workspaceId);

        if ($logKey !== null && ! is_numeric($logKey)) {
            throw new InvalidArgumentException('Bulk-install log keys must be numeric.');
        }

        $runId = (string) Str::uuid();
        $directory = $this->directory($workspaceId);

        $this->createDirectory($directory);
        $created = file_put_contents($this->path($workspaceId, $runId), '', LOCK_EX);

        if ($created === false) {
            throw new RuntimeException('Could not create the bulk-install transcript.');
        }

        $this->writePrivateJson($this->statePath($workspaceId, $runId), [
            'status' => 'queued',
            'results' => [],
            'error' => null,
            'updated_at' => now()->toIso8601String(),
        ]);

        if ($logKey !== null) {
            $this->createDirectory($directory.DIRECTORY_SEPARATOR.'keys');
            $aliasPath = $this->logKeyPath($workspaceId, $logKey);
            $written = file_put_contents($aliasPath, $runId, LOCK_EX);

            if ($written === false) {
                throw new RuntimeException('Could not register the bulk-install log key.');
            }
        }

        return $runId;
    }

    /** Append one already-formatted, timestamped DMS transcript line. */
    public function append(string $workspaceId, string $runId, string $line): void
    {
        $this->validateUuid($workspaceId);
        $this->validateUuid($runId);

        $this->writeLine($workspaceId, $runId, $line);
    }

    public function read(string $workspaceId, string $runId): ?string
    {
        $this->validateUuid($workspaceId);
        $this->validateUuid($runId);

        $path = $this->path($workspaceId, $runId);

        if (! is_file($path)) {
            return null;
        }

        $file = fopen($path, 'rb');

        if ($file === false) {
            throw new RuntimeException('Could not open the bulk-install transcript.');
        }

        try {
            if (! flock($file, LOCK_SH)) {
                throw new RuntimeException('Could not lock the bulk-install transcript for reading.');
            }

            $contents = stream_get_contents($file);

            if ($contents === false) {
                throw new RuntimeException('Could not read the bulk-install transcript.');
            }

            return $contents;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    /** Read a DMS-style numeric browser polling key without exposing its file path. */
    public function readByLogKey(string $workspaceId, string $logKey): ?string
    {
        $runId = $this->runIdForLogKey($workspaceId, $logKey);

        return $runId === null ? null : $this->read($workspaceId, $runId);
    }

    /** Store only the validated, non-secret request needed by the detached worker. */
    public function writeRunPayload(string $workspaceId, string $runId, array $payload): void
    {
        $this->validateUuid($workspaceId);
        $this->validateUuid($runId);

        $this->writePrivateJson($this->payloadPath($workspaceId, $runId), $payload);
    }

    /** @return array<string, mixed>|null */
    public function readRunPayload(string $workspaceId, string $runId): ?array
    {
        $this->validateUuid($workspaceId);
        $this->validateUuid($runId);

        return $this->readPrivateJson($this->payloadPath($workspaceId, $runId));
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array{status: string, results: list<array<string, mixed>>, error: string|null, updated_at: string}
     */
    public function setRunStatus(string $workspaceId, string $runId, string $status, array $results = [], ?string $error = null): array
    {
        $this->validateUuid($workspaceId);
        $this->validateUuid($runId);

        if (! in_array($status, ['queued', 'running', 'succeeded', 'failed'], true)) {
            throw new InvalidArgumentException('The installation run status is invalid.');
        }

        $state = [
            'status' => $status,
            'results' => array_values($results),
            'error' => $error,
            'updated_at' => now()->toIso8601String(),
        ];
        $this->writePrivateJson($this->statePath($workspaceId, $runId), $state);

        return $state;
    }

    /** @return array{status: string, results: list<array<string, mixed>>, error: string|null, updated_at: string}|null */
    public function readRunStatus(string $workspaceId, string $runId): ?array
    {
        $this->validateUuid($workspaceId);
        $this->validateUuid($runId);

        $state = $this->readPrivateJson($this->statePath($workspaceId, $runId));
        if (! is_array($state)
            || ! is_string($state['status'] ?? null)
            || ! is_array($state['results'] ?? null)
            || ! is_string($state['updated_at'] ?? null)) {
            return null;
        }

        return [
            'status' => $state['status'],
            'results' => array_values(array_filter($state['results'], 'is_array')),
            'error' => is_string($state['error'] ?? null) ? $state['error'] : null,
            'updated_at' => $state['updated_at'],
        ];
    }

    /** @return array{status: string, results: list<array<string, mixed>>, error: string|null, updated_at: string}|null */
    public function readRunStatusByLogKey(string $workspaceId, string $logKey): ?array
    {
        $this->validateUuid($workspaceId);
        $runId = $this->runIdForLogKey($workspaceId, $logKey);

        return $runId === null ? null : $this->readRunStatus($workspaceId, $runId);
    }

    private function runIdForLogKey(string $workspaceId, string $logKey): ?string
    {
        $this->validateUuid($workspaceId);

        if (! is_numeric($logKey)) {
            return null;
        }

        $aliasPath = $this->logKeyPath($workspaceId, $logKey);

        if (! is_file($aliasPath)) {
            return null;
        }

        $file = fopen($aliasPath, 'rb');

        if ($file === false) {
            return null;
        }

        try {
            if (! flock($file, LOCK_SH)) {
                return null;
            }

            $runId = stream_get_contents($file);
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }

        if (! is_string($runId) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $runId)) {
            return null;
        }

        return $runId;
    }

    private function writeLine(string $workspaceId, string $runId, string $line): void
    {
        $this->createDirectory($this->directory($workspaceId));
        $written = file_put_contents($this->path($workspaceId, $runId), $line.PHP_EOL, FILE_APPEND | LOCK_EX);

        if ($written === false) {
            throw new RuntimeException('Could not write the bulk-install transcript.');
        }
    }

    private function directory(string $workspaceId): string
    {
        return $this->basePath.DIRECTORY_SEPARATOR.$workspaceId;
    }

    private function createDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the private bulk-install transcript directory.');
        }
    }

    private function path(string $workspaceId, string $runId): string
    {
        return $this->basePath.DIRECTORY_SEPARATOR.$workspaceId.DIRECTORY_SEPARATOR.$runId.'.log';
    }

    private function statePath(string $workspaceId, string $runId): string
    {
        return $this->basePath.DIRECTORY_SEPARATOR.$workspaceId.DIRECTORY_SEPARATOR.$runId.'.state.json';
    }

    private function payloadPath(string $workspaceId, string $runId): string
    {
        return $this->basePath.DIRECTORY_SEPARATOR.$workspaceId.DIRECTORY_SEPARATOR.$runId.'.request.json';
    }

    /** @param array<string, mixed> $contents */
    private function writePrivateJson(string $path, array $contents): void
    {
        $directory = dirname($path);
        $this->createDirectory($directory);
        $temporaryPath = tempnam($directory, '.run-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Could not create private installation run metadata.');
        }

        try {
            $json = json_encode($contents, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
                throw new RuntimeException('Could not write private installation run metadata.');
            }
            chmod($temporaryPath, 0600);
            if (! rename($temporaryPath, $path)) {
                throw new RuntimeException('Could not publish private installation run metadata.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function readPrivateJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function logKeyPath(string $workspaceId, string $logKey): string
    {
        return $this->directory($workspaceId).DIRECTORY_SEPARATOR.'keys'.DIRECTORY_SEPARATOR.hash('sha256', $logKey).'.run';
    }

    private function validateUuid(string $value): void
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            throw new InvalidArgumentException('Bulk-install transcript identifiers must be UUIDs.');
        }
    }
}
