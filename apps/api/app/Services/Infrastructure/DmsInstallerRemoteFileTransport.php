<?php

namespace App\Services\Infrastructure;

interface DmsInstallerRemoteFileTransport
{
    public function upload(string $contents, string $destination): bool;

    public function uploadFile(string $localPath, string $destination): bool;
}
