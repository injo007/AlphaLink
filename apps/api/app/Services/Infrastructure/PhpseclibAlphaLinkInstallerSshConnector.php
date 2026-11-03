<?php

namespace App\Services\Infrastructure;

use phpseclib3\Net\SFTP;

class PhpseclibAlphaLinkInstallerSshConnector implements AlphaLinkInstallerSshConnector
{
    public function connect(string $host, int $port, int $timeoutSeconds): AlphaLinkInstallerSshConnection
    {
        $sftp = new SFTP($host, $port, $timeoutSeconds);
        $sftp->setTimeout($timeoutSeconds);

        return new PhpseclibAlphaLinkInstallerSshConnection($sftp);
    }
}
