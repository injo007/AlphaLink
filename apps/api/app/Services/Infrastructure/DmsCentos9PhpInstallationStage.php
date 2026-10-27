<?php

namespace App\Services\Infrastructure;

/** DMS CentOS 9 install_php() phase, invoked only when tracking is selected. */
class DmsCentos9PhpInstallationStage
{
    public function run(DmsInstallerRemoteCommandTransport $transport): void
    {
        foreach ([
            'dnf module enable php:remi-8.3 -y;',
            'dnf install -y php php-common php-mbstring php-mcrypt php-curl;',
            "sed -i 's/expose_php = On/expose_php = Off/g' /etc/php.ini;",
            "sed -i 's/safe_mode = On/safe_mode = Off/g' /etc/php.ini;",
        ] as $command) {
            $transport->exec($command);
        }
    }
}
