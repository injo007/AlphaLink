<?php

namespace App\Services\Infrastructure;

use RuntimeException;

/** An authenticated DMS-style session exposing command, file, and string transports. */
class DmsInstallerSshSession implements DmsInstallerRemoteCommandTransport, DmsInstallerRemoteFileTransport, DmsInstallerRemoteReadableFileTransport
{
    public function __construct(private readonly DmsInstallerSshConnection $connection) {}

    public function exec(string $command): bool|string
    {
        if (! $this->connection->isConnected() || ! $this->connection->isAuthenticated()) {
            return false;
        }

        $result = $this->connection->exec($command);
        if ($result === false) {
            throw new RuntimeException('The remote installer command timed out or the SSH channel failed.');
        }

        // DMS trims the command response; keep its successful-output behavior.
        return trim($result);
    }

    public function upload(string $contents, string $destination): bool
    {
        if (! $this->connection->isConnected() || ! $this->connection->isAuthenticated()) {
            return false;
        }

        return $this->connection->uploadString($contents, $destination);
    }

    public function uploadFile(string $localPath, string $destination): bool
    {
        if (! $this->connection->isConnected() || ! $this->connection->isAuthenticated()) {
            return false;
        }

        return $this->connection->uploadFile($localPath, $destination);
    }

    public function download(string $path): string|false
    {
        if (! $this->connection->isConnected() || ! $this->connection->isAuthenticated()) {
            return false;
        }

        return $this->connection->downloadString($path);
    }
}
