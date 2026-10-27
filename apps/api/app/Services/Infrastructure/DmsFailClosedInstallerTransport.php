<?php

namespace App\Services\Infrastructure;

use RuntimeException;

/** Converts a lost SSH/SFTP channel into a failed operation instead of silent success. */
class DmsFailClosedInstallerTransport implements DmsInstallerRemoteCommandTransport, DmsInstallerRemoteFileTransport, DmsInstallerRemoteReadableFileTransport
{
    public function __construct(
        private readonly DmsInstallerRemoteCommandTransport&DmsInstallerRemoteFileTransport $session,
        private readonly string $operation = 'Change Domain',
    ) {}

    public function exec(string $command): bool|string
    {
        $result = $this->session->exec($command);
        if ($result === false) {
            throw new RuntimeException('The remote server connection was lost during '.$this->operation.'.');
        }

        return $result;
    }

    public function upload(string $contents, string $destination): bool
    {
        if (! $this->session->upload($contents, $destination)) {
            throw new RuntimeException('A remote configuration file could not be uploaded during '.$this->operation.'.');
        }

        return true;
    }

    public function uploadFile(string $localPath, string $destination): bool
    {
        if (! $this->session->uploadFile($localPath, $destination)) {
            throw new RuntimeException('A remote configuration file could not be uploaded during '.$this->operation.'.');
        }

        return true;
    }

    public function download(string $path): string|false
    {
        if (! $this->session instanceof DmsInstallerRemoteReadableFileTransport) {
            throw new RuntimeException('Remote file reading is unavailable during '.$this->operation.'.');
        }

        $result = $this->session->download($path);
        if ($result === false) {
            throw new RuntimeException('A remote configuration file could not be read during '.$this->operation.'.');
        }

        return $result;
    }
}
