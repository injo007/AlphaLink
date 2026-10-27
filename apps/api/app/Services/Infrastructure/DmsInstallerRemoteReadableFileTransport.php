<?php

namespace App\Services\Infrastructure;

interface DmsInstallerRemoteReadableFileTransport
{
    public function download(string $path): string|false;
}
