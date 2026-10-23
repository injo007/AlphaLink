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

    public function createRun(string $workspaceId): string
    {
        $this->validateUuid($workspaceId);
        $runId = (string) Str::uuid();
        $directory = $this->directory($workspaceId);

        $this->createDirectory($directory);
        $created = file_put_contents($this->path($workspaceId, $runId), '', LOCK_EX);

        if ($created === false) {
            throw new RuntimeException('Could not create the bulk-install transcript.');
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

    private function validateUuid(string $value): void
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            throw new InvalidArgumentException('Bulk-install transcript identifiers must be UUIDs.');
        }
    }
}
