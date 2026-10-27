<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsCentos9ServiceSetup;
use App\Services\Infrastructure\DmsInstallerRemoteCommandTransport;
use PHPUnit\Framework\TestCase;

class DmsCentos9ServiceSetupTest extends TestCase
{
    public function test_it_creates_swap_below_one_gib_then_runs_dms_service_commands_in_order(): void
    {
        $transport = new RecordingServiceSetupTransport(['0', '512', ...array_fill(0, 24, false)]);

        (new DmsCentos9ServiceSetup)->run($transport);

        $this->assertSame([
            'free -m | grep Swap | awk \'{print $2}\'',
            'free -m | grep Mem | awk \'{print $2}\'',
            'fallocate -l 2G /swapfile',
            'ls -lh /swapfile',
            'chmod 600 /swapfile',
            'mkswap /swapfile',
            'swapon /swapfile',
            'echo "/swapfile swap swap defaults 0 0" >> /etc/fstab',
            "echo -e \"nameserver 1.1.1.1\nnameserver 8.8.8.8\" > /etc/resolv.conf",
            'setenforce 0',
            "sed -i 's/SELINUX=enforcing/SELINUX=disabled/g' /etc/selinux/config;",
            'dnf install -y ca-certificates --disablerepo=epel || dnf update -y ca-certificates --disablerepo=epel',
            'dnf install -y curl  --disablerepo=epel || dnf update -y curl --disablerepo=epel',
            'dnf install -y http://dl.fedoraproject.org/pub/epel/epel-release-latest-9.noarch.rpm;',
            'dnf install -y gcc make nano lsof wget curl unzip iproute;',
            'dnf install -y http://rpms.remirepo.net/enterprise/remi-release-9.rpm;',
            'ulimit -H -u 80000',
            'ulimit -H -n 80000',
            'dnf install -y openssh;',
            "sed -i 's/#MaxSessions 10/MaxSessions 1200/g' /etc/ssh/sshd_config;",
            'systemctl restart sshd',
            'timedatectl set-timezone Africa/Casablanca',
            'timedatectl set-local-rtc 0',
            'timedatectl set-ntp true',
            'systemctl restart crond.service;',
            'systemctl restart systemd-timedated;',
        ], $transport->commands);
    }

    public function test_it_skips_swap_creation_when_swap_exists(): void
    {
        $transport = new RecordingServiceSetupTransport(['2048']);

        (new DmsCentos9ServiceSetup)->run($transport);

        $this->assertSame('free -m | grep Swap | awk \'{print $2}\'', $transport->commands[0]);
        $this->assertSame("echo -e \"nameserver 1.1.1.1\nnameserver 8.8.8.8\" > /etc/resolv.conf", $transport->commands[1]);
        $this->assertCount(19, $transport->commands);
        $this->assertNotContains('fallocate -l 2G /swapfile', $transport->commands);
    }

    public function test_it_skips_swap_creation_at_or_above_one_gib_ram(): void
    {
        $transport = new RecordingServiceSetupTransport(['0', '1024']);

        (new DmsCentos9ServiceSetup)->run($transport);

        $this->assertSame('free -m | grep Mem | awk \'{print $2}\'', $transport->commands[1]);
        $this->assertCount(20, $transport->commands);
        $this->assertNotContains('fallocate -l 2G /swapfile', $transport->commands);
    }
}

class RecordingServiceSetupTransport implements DmsInstallerRemoteCommandTransport
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
