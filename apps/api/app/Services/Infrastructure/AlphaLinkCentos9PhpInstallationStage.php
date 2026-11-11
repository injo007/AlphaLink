<?php

namespace App\Services\Infrastructure;

use RuntimeException;

/** reference CentOS 9 install_php() phase, invoked only when tracking is selected. */
class AlphaLinkCentos9PhpInstallationStage
{
    public function run(AlphaLinkInstallerRemoteCommandTransport $transport): void
    {
        $moduleReady = $transport->exec(
            'dnf module enable php:remi-8.3 -y >/dev/null 2>&1 && printf ALPHALINK_PHP_MODULE_READY',
        );
        if (trim((string) $moduleReady) !== 'ALPHALINK_PHP_MODULE_READY') {
            throw new RuntimeException('The PHP 8.3 module could not be enabled for tracking.');
        }

        $runtimeReady = $transport->exec(
            'dnf install -y httpd php php-fpm php-common php-mbstring php-mcrypt php-curl >/dev/null 2>&1 && '
                .'systemctl enable --now php-fpm >/dev/null 2>&1 && '
                .'systemctl is-active --quiet php-fpm && '
                .'test -s /etc/httpd/conf.d/php.conf && '
                .'httpd -M 2>/dev/null | grep -q proxy_fcgi_module && '
                .'httpd -t >/dev/null 2>&1 && printf ALPHALINK_PHP_FPM_READY',
        );
        if (trim((string) $runtimeReady) !== 'ALPHALINK_PHP_FPM_READY') {
            throw new RuntimeException('Apache PHP-FPM could not be installed, started, and validated for tracking.');
        }

        foreach ([
            "sed -i 's/expose_php = On/expose_php = Off/g' /etc/php.ini;",
            "sed -i 's/safe_mode = On/safe_mode = Off/g' /etc/php.ini;",
        ] as $command) {
            $transport->exec($command);
        }
    }
}
