<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsCentos9ImapDeploymentStage;
use App\Services\Infrastructure\DmsInstallerRemoteCommandTransport;
use App\Services\Infrastructure\DmsInstallerRemoteFileTransport;
use PHPUnit\Framework\TestCase;

class DmsCentos9ImapDeploymentStageTest extends TestCase
{
    public function test_missing_dovecot_asset_skips_the_optional_stage(): void
    {
        $transport = $this->transport();

        (new DmsCentos9ImapDeploymentStage)->run($transport, $transport, null, 'mail-user', 'synthetic-password');

        $this->assertSame([], $transport->events);
    }

    public function test_configures_dovecot_in_dms_order_and_keeps_password_out_of_commands(): void
    {
        $transport = $this->transport();
        $transport->commandResults['id -u \'mail-user\';'] = '1001';

        (new DmsCentos9ImapDeploymentStage)->run(
            $transport,
            $transport,
            "mail_location = maildir:/home/__USER__/Maildir\n",
            'mail-user',
            'synthetic-password',
        );

        $this->assertSame([
            ['type' => 'command', 'command' => 'rpm -qa | grep -qw dovecot || dnf -y install dovecot;'],
            ['type' => 'upload', 'destination' => '/etc/dovecot/dovecot.conf', 'contents' => "mail_location = maildir:/home/__USER__/Maildir\n"],
            ['type' => 'command', 'command' => "sed -i 's/__USER__/mail-user/g' /etc/dovecot/dovecot.conf;"],
            ['type' => 'command', 'command' => "useradd -s /sbin/nologin 'mail-user';"],
            ['type' => 'command', 'command' => "id -u 'mail-user';"],
            ['type' => 'upload', 'destination' => '/etc/dovecot/imap.passwd', 'contents' => "mail-user:{plain}synthetic-password:1001:1001::/dev/null:/sbin/nologin\n"],
            ['type' => 'command', 'command' => "sed -i 's/ProtectSystem=full/ProtectSystem=false/g' /usr/lib/systemd/system/dovecot.service"],
            ['type' => 'command', 'command' => 'systemctl daemon-reload'],
            ['type' => 'command', 'command' => 'systemctl restart dovecot'],
            ['type' => 'command', 'command' => 'systemctl enable dovecot'],
        ], $transport->events);

        $commands = array_column(array_filter($transport->events, fn (array $event): bool => $event['type'] === 'command'), 'command');
        $this->assertStringNotContainsString('synthetic-password', implode("\n", $commands));
    }

    public function test_quotes_username_in_shell_commands_and_sed_replacement(): void
    {
        $transport = $this->transport();
        $transport->commandResults["id -u 'mail'\\''user';"] = '1002';

        (new DmsCentos9ImapDeploymentStage)->run($transport, $transport, '__USER__', "mail'user", 'synthetic');

        $this->assertSame('sed -i '.escapeshellarg("s/__USER__/mail'user/g").' /etc/dovecot/dovecot.conf;', $transport->events[2]['command']);
        $this->assertSame("useradd -s /sbin/nologin 'mail'\\''user';", $transport->events[3]['command']);
        $this->assertSame("id -u 'mail'\\''user';", $transport->events[4]['command']);
        $this->assertSame("mail'user:{plain}synthetic:1002:1002::/dev/null:/sbin/nologin\n", $transport->events[5]['contents']);
    }

    private function transport(): object
    {
        return new class implements DmsInstallerRemoteCommandTransport, DmsInstallerRemoteFileTransport
        {
            /** @var list<array<string, string>> */
            public array $events = [];

            /** @var array<string, bool|string> */
            public array $commandResults = [];

            public function exec(string $command): bool|string
            {
                $this->events[] = ['type' => 'command', 'command' => $command];

                return $this->commandResults[$command] ?? '';
            }

            public function upload(string $contents, string $destination): bool
            {
                $this->events[] = ['type' => 'upload', 'destination' => $destination, 'contents' => $contents];

                return true;
            }

            public function uploadFile(string $localPath, string $destination): bool
            {
                $contents = file_get_contents($localPath);
                $this->events[] = ['type' => 'upload_file', 'destination' => $destination, 'contents' => is_string($contents) ? $contents : ''];

                return is_string($contents);
            }
        };
    }
}
