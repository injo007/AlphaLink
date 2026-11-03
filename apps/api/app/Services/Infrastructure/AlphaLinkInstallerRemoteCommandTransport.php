<?php

namespace App\Services\Infrastructure;

/** Executes one remote shell command and returns trimmed output or false, like DMS Ssh::exec(). */
interface AlphaLinkInstallerRemoteCommandTransport
{
    public function exec(string $command): bool|string;
}
