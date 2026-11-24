<?php

namespace App\Services\Infrastructure;

use RuntimeException;

/** Converts a lost SSH/SFTP channel into a failed operation instead of silent success. */
class AlphaLinkFailClosedInstallerTransport implements AlphaLinkInstallerRemoteCommandTransport, AlphaLinkInstallerRemoteFileTransport, AlphaLinkInstallerRemoteReadableFileTransport
{
    public function __construct(
        private readonly AlphaLinkInstallerRemoteCommandTransport&AlphaLinkInstallerRemoteFileTransport $session,
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
            throw $this->uploadFailure($destination);
        }

        return true;
    }

    public function uploadFile(string $localPath, string $destination): bool
    {
        if (! $this->session->uploadFile($localPath, $destination)) {
            throw $this->uploadFailure($destination);
        }

        return true;
    }

    public function download(string $path): string|false
    {
        if (! $this->session instanceof AlphaLinkInstallerRemoteReadableFileTransport) {
            throw new RuntimeException('Remote file reading is unavailable during '.$this->operation.'.');
        }

        $result = $this->session->download($path);
        if ($result === false) {
            throw new RuntimeException('A remote configuration file could not be read during '.$this->operation.'.');
        }

        return $result;
    }

    private function uploadFailure(string $destination): RuntimeException
    {
        $reason = $this->session instanceof AlphaLinkInstallerSshFailureDiagnostics
            ? trim($this->session->lastFailureReason())
            : '';
        $message = 'A remote configuration file could not be uploaded to '.$destination.' during '.$this->operation;
        if ($reason !== '') {
            $message .= ': '.$reason;
        }

        return new RuntimeException($message.'.');
    }
}
