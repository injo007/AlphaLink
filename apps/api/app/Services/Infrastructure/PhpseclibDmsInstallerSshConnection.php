<?php

namespace App\Services\Infrastructure;

use phpseclib3\Net\SFTP;

class PhpseclibDmsInstallerSshConnection implements DmsInstallerSshConnection
{
    public function __construct(private readonly SFTP $sftp) {}

    public function getServerPublicHostKey(): string|false
    {
        return $this->sftp->getServerPublicHostKey();
    }

    public function login(string $username, mixed $credential): bool
    {
        return $this->sftp->login($username, $credential);
    }

    public function isConnected(): bool
    {
        return $this->sftp->isConnected();
    }

    public function isAuthenticated(): bool
    {
        return $this->sftp->isAuthenticated();
    }

    public function exec(string $command): string|false
    {
        $result = $this->sftp->exec($command);
        if ($result === false) {
            // phpseclib can leave CHANNEL_EXEC open after a timeout. Disconnect so
            // a later command cannot fail with "Please close the channel ...".
            $this->sftp->disconnect();
        }

        return $result;
    }

    public function uploadString(string $contents, string $destination): bool
    {
        return $this->sftp->put($destination, $contents, SFTP::SOURCE_STRING);
    }

    public function uploadFile(string $localPath, string $destination): bool
    {
        return $this->sftp->put($destination, $localPath, SFTP::SOURCE_LOCAL_FILE);
    }

    public function downloadString(string $path): string|false
    {
        return $this->sftp->get($path);
    }

    public function listDirectory(string $path): array|false
    {
        $entries = $this->sftp->nlist($path);

        if ($entries === false) {
            return false;
        }

        return array_values(array_filter($entries, 'is_string'));
    }

    public function isDirectory(string $path): bool
    {
        return $this->sftp->is_dir($path);
    }

    public function createDirectory(string $path): bool
    {
        return $this->sftp->is_dir($path) || $this->sftp->mkdir($path, -1, true);
    }

    public function fileSize(string $path): int|false
    {
        return $this->sftp->filesize($path);
    }

    public function fileModifiedAt(string $path): int|false
    {
        return $this->sftp->filemtime($path);
    }

    public function downloadFile(string $remotePath, string $localPath): bool
    {
        return $this->sftp->get($remotePath, $localPath) === true;
    }

    public function moveFile(string $remotePath, string $destination): bool
    {
        return $this->sftp->rename($remotePath, $destination);
    }
}
