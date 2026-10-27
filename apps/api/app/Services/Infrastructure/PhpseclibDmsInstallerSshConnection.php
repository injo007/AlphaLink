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
}
