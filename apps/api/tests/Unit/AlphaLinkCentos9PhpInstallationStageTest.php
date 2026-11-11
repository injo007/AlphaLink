<?php

namespace Tests\Unit;

use App\Services\Infrastructure\AlphaLinkCentos9PhpInstallationStage;
use App\Services\Infrastructure\AlphaLinkInstallerRemoteCommandTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AlphaLinkCentos9PhpInstallationStageTest extends TestCase
{
    public function test_it_installs_and_validates_apache_php_fpm_for_tracking(): void
    {
        $transport = new class implements AlphaLinkInstallerRemoteCommandTransport
        {
            public array $commands = [];

            public function exec(string $command): bool|string
            {
                $this->commands[] = $command;

                if (str_contains($command, 'ALPHALINK_PHP_MODULE_READY')) {
                    return 'ALPHALINK_PHP_MODULE_READY';
                }

                if (str_contains($command, 'ALPHALINK_PHP_FPM_READY')) {
                    return 'ALPHALINK_PHP_FPM_READY';
                }

                return '';
            }
        };

        (new AlphaLinkCentos9PhpInstallationStage)->run($transport);

        $this->assertCount(4, $transport->commands);
        $this->assertStringContainsString('dnf module enable php:remi-8.3 -y', $transport->commands[0]);
        $this->assertStringContainsString('dnf install -y httpd php php-fpm', $transport->commands[1]);
        $this->assertStringContainsString('systemctl enable --now php-fpm', $transport->commands[1]);
        $this->assertStringContainsString('systemctl is-active --quiet php-fpm', $transport->commands[1]);
        $this->assertStringContainsString('/etc/httpd/conf.d/php.conf', $transport->commands[1]);
        $this->assertStringContainsString('proxy_fcgi_module', $transport->commands[1]);
        $this->assertStringContainsString('httpd -t', $transport->commands[1]);
        $this->assertSame("sed -i 's/expose_php = On/expose_php = Off/g' /etc/php.ini;", $transport->commands[2]);
        $this->assertSame("sed -i 's/safe_mode = On/safe_mode = Off/g' /etc/php.ini;", $transport->commands[3]);
    }

    public function test_it_stops_when_php_fpm_is_not_ready(): void
    {
        $transport = new class implements AlphaLinkInstallerRemoteCommandTransport
        {
            public function exec(string $command): bool|string
            {
                return str_contains($command, 'ALPHALINK_PHP_MODULE_READY')
                    ? 'ALPHALINK_PHP_MODULE_READY'
                    : '';
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Apache PHP-FPM could not be installed, started, and validated for tracking.');

        (new AlphaLinkCentos9PhpInstallationStage)->run($transport);
    }
}
