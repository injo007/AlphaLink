<?php

namespace Tests\Unit;

use App\Services\Infrastructure\AlphaLinkCentos9PhpInstallationStage;
use App\Services\Infrastructure\AlphaLinkInstallerRemoteCommandTransport;
use PHPUnit\Framework\TestCase;

class AlphaLinkCentos9PhpInstallationStageTest extends TestCase
{
    public function test_it_replays_the_reference_centos_9_php_commands_in_order(): void
    {
        $transport = new class implements AlphaLinkInstallerRemoteCommandTransport
        {
            public array $commands = [];

            public function exec(string $command): bool|string
            {
                $this->commands[] = $command;

                return '';
            }
        };

        (new AlphaLinkCentos9PhpInstallationStage)->run($transport);

        $this->assertSame([
            'dnf module enable php:remi-8.3 -y;',
            'dnf install -y php php-common php-mbstring php-mcrypt php-curl;',
            "sed -i 's/expose_php = On/expose_php = Off/g' /etc/php.ini;",
            "sed -i 's/safe_mode = On/safe_mode = Off/g' /etc/php.ini;",
        ], $transport->commands);
    }
}
