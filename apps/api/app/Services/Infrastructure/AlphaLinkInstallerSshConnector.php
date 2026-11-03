<?php

namespace App\Services\Infrastructure;

interface AlphaLinkInstallerSshConnector
{
    public function connect(string $host, int $port, int $timeoutSeconds): AlphaLinkInstallerSshConnection;
}
