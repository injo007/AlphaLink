<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsCentos9HostPreparation;
use App\Services\Infrastructure\DmsInstallerRemoteCommandTransport;
use PHPUnit\Framework\TestCase;

class DmsCentos9HostPreparationTest extends TestCase
{
    public function test_it_configures_host_then_removes_old_packages_in_dms_order(): void
    {
        $transport = new RecordingInstallerTransport;

        (new DmsCentos9HostPreparation)->run($transport, '203.0.113.18', 'mail.example.test');

        $this->assertSame([
            "hostnamectl set-hostname 'mail.example.test'",
            "sed -i 's/HOSTNAME=.*/HOSTNAME=mail.example.test/g' /etc/sysconfig/network",
            "grep -qxF '203.0.113.18 mail.example.test' /etc/hosts || echo -e '203.0.113.18 mail.example.test' >> /etc/hosts",
            'dnf remove -y httpd libopendkim* opendkim postfix php* sendmail;',
            'rm -rf /etc/httpd;',
        ], $transport->commands);
    }

    public function test_it_skips_hostname_configuration_when_dms_domain_is_empty(): void
    {
        $transport = new RecordingInstallerTransport;

        (new DmsCentos9HostPreparation)->run($transport, '203.0.113.18', '');

        $this->assertSame([
            'dnf remove -y httpd libopendkim* opendkim postfix php* sendmail;',
            'rm -rf /etc/httpd;',
        ], $transport->commands);
    }

    public function test_it_attempts_cleanup_commands_even_when_the_first_command_returns_false(): void
    {
        $transport = new RecordingInstallerTransport([false, false, false, false, false]);

        (new DmsCentos9HostPreparation)->run($transport, '203.0.113.18', 'mail.example.test');

        $this->assertCount(5, $transport->commands);
        $this->assertSame('rm -rf /etc/httpd;', $transport->commands[4]);
    }

    public function test_host_configuration_and_cleanup_can_be_dispatched_as_separate_dms_phases(): void
    {
        $transport = new RecordingInstallerTransport;
        $stage = new DmsCentos9HostPreparation;

        $stage->configureHost($transport, '203.0.113.18', 'mail.example.test');
        $this->assertCount(3, $transport->commands);
        $this->assertNotContains('dnf remove -y httpd libopendkim* opendkim postfix php* sendmail;', $transport->commands);

        $stage->removeOldPackages($transport);
        $this->assertSame('dnf remove -y httpd libopendkim* opendkim postfix php* sendmail;', $transport->commands[3]);
        $this->assertSame('rm -rf /etc/httpd;', $transport->commands[4]);
    }
}

class RecordingInstallerTransport implements DmsInstallerRemoteCommandTransport
{
    /** @var list<string> */
    public array $commands = [];

    /** @param list<bool|string> $results */
    public function __construct(private readonly array $results = []) {}

    public function exec(string $command): bool|string
    {
        $this->commands[] = $command;

        return $this->results[count($this->commands) - 1] ?? '';
    }
}
