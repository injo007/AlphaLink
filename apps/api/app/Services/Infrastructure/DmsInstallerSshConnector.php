<?php

namespace App\Services\Infrastructure;

interface DmsInstallerSshConnector
{
    public function connect(string $host, int $port, int $timeoutSeconds): DmsInstallerSshConnection;
}
