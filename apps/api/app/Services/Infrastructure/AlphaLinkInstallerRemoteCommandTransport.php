<?php

namespace App\Services\Infrastructure;

/** Executes one remote shell command and returns trimmed output or false. */
interface AlphaLinkInstallerRemoteCommandTransport
{
    public function exec(string $command): bool|string;
}
