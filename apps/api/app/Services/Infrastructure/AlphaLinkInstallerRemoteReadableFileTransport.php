<?php

namespace App\Services\Infrastructure;

interface AlphaLinkInstallerRemoteReadableFileTransport
{
    public function download(string $path): string|false;
}
