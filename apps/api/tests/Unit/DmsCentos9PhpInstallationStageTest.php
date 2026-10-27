<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsCentos9PhpInstallationStage;
use App\Services\Infrastructure\DmsInstallerRemoteCommandTransport;
use PHPUnit\Framework\TestCase;

class DmsCentos9PhpInstallationStageTest extends TestCase
{
    public function test_it_replays_the_dms_centos_9_php_commands_in_order(): void
    {
        $transport = new class implements DmsInstallerRemoteCommandTransport
        {
            public array $commands = [];

            public function exec(string $command): bool|string
            {
                $this->commands[] = $command;

                return '';
            }
        };

        (new DmsCentos9PhpInstallationStage)->run($transport);

        $this->assertSame([
            'dnf module enable php:remi-8.3 -y;',
            'dnf install -y php php-common php-mbstring php-mcrypt php-curl;',
            "sed -i 's/expose_php = On/expose_php = Off/g' /etc/php.ini;",
            "sed -i 's/safe_mode = On/safe_mode = Off/g' /etc/php.ini;",
        ], $transport->commands);
    }
}
