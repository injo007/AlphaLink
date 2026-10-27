<?php

namespace App\Services\Infrastructure;

use phpseclib3\Net\SFTP;

class PhpseclibDmsInstallerSshConnector implements DmsInstallerSshConnector
{
    public function connect(string $host, int $port, int $timeoutSeconds): DmsInstallerSshConnection
    {
        $sftp = new SFTP($host, $port, $timeoutSeconds);
        $sftp->setTimeout($timeoutSeconds);

        return new PhpseclibDmsInstallerSshConnection($sftp);
    }
}
