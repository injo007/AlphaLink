<?php

namespace App\Services\Infrastructure;

interface AlphaLinkInstallerRemoteFileTransport
{
    public function upload(string $contents, string $destination): bool;

    public function uploadFile(string $localPath, string $destination): bool;
}
