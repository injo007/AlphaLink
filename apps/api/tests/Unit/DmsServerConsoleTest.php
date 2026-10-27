<?php

namespace Tests\Unit;

use App\Models\ManagedServer;
use App\Services\Campaigns\DmsPmtaAdminRestart;
use App\Services\Infrastructure\DmsInstallerServerCredentialResolver;
use App\Services\Infrastructure\DmsInstallerSshConnection;
use App\Services\Infrastructure\DmsInstallerSshSession;
use App\Services\Infrastructure\DmsInstallerSshSessionFactory;
use App\Services\Infrastructure\DmsServerConsole;
use App\Services\Infrastructure\HostProbeException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mockery;
use Tests\TestCase;

class DmsServerConsoleTest extends TestCase
{
    public function test_console_checks_dms_architecture_and_os_before_returning_trimmed_command_output(): void
    {
        $server = new ManagedServer;
        $credentials = Mockery::mock(DmsInstallerServerCredentialResolver::class);
        $credentials->shouldReceive('resolve')->once()->with($server)->andReturn([
            'secret' => 'stored-credential',
            'type' => 'password',
        ]);

        $connection = Mockery::mock(DmsInstallerSshConnection::class);
        $connection->shouldReceive('isConnected')->times(4)->andReturn(true);
        $connection->shouldReceive('isAuthenticated')->times(4)->andReturn(true);
        $connection->shouldReceive('exec')->once()->with('ls')->ordered()->andReturn('bin home');
        $connection->shouldReceive('exec')->once()->with('uname -m')->ordered()->andReturn('x86_64');
        $connection->shouldReceive('exec')->once()
            ->with("awk -F= '/^PRETTY_NAME=/{print $2}' /etc/os-release")
            ->ordered()->andReturn('"AlmaLinux 9.8"');
        $connection->shouldReceive('exec')->once()->with('printf synthetic')->ordered()
            ->andReturn("  <output>safe</output>\n");

        $sessions = Mockery::mock(DmsInstallerSshSessionFactory::class);
        $sessions->shouldReceive('create')->once()->with($server, 'stored-credential', 'password')
            ->andReturn(new DmsInstallerSshSession($connection));

        $console = new DmsServerConsole($credentials, $sessions);

        $this->assertSame('<output>safe</output>', $console->execute($server, 'printf synthetic'));
    }

    public function test_console_stops_before_user_command_when_architecture_is_not_x86_64(): void
    {
        $server = new ManagedServer;
        $credentials = Mockery::mock(DmsInstallerServerCredentialResolver::class);
        $credentials->shouldReceive('resolve')->once()->andReturn(['secret' => 'stored-credential', 'type' => 'password']);

        $connection = Mockery::mock(DmsInstallerSshConnection::class);
        $connection->shouldReceive('isConnected')->twice()->andReturn(true);
        $connection->shouldReceive('isAuthenticated')->twice()->andReturn(true);
        $connection->shouldReceive('exec')->once()->with('ls')->ordered()->andReturn('bin home');
        $connection->shouldReceive('exec')->once()->with('uname -m')->ordered()->andReturn('aarch64');
        $connection->shouldNotReceive('exec')->with("awk -F= '/^PRETTY_NAME=/{print $2}' /etc/os-release");
        $connection->shouldNotReceive('exec')->with('printf must-not-run');

        $sessions = Mockery::mock(DmsInstallerSshSessionFactory::class);
        $sessions->shouldReceive('create')->once()->andReturn(new DmsInstallerSshSession($connection));

        try {
            (new DmsServerConsole($credentials, $sessions))->execute($server, 'printf must-not-run');
            $this->fail('DMS architecture gate must prevent the requested command.');
        } catch (HostProbeException $exception) {
            $this->assertSame('Unsupported Architecture : aarch64', $exception->getMessage());
        }
    }

    public function test_console_runs_a_sequence_as_separate_commands_on_one_checked_session(): void
    {
        $server = new ManagedServer;
        $credentials = Mockery::mock(DmsInstallerServerCredentialResolver::class);
        $credentials->shouldReceive('resolve')->once()->with($server)->andReturn([
            'secret' => 'stored-credential',
            'type' => 'password',
        ]);

        $connection = Mockery::mock(DmsInstallerSshConnection::class);
        $connection->shouldReceive('isConnected')->times(6)->andReturn(true);
        $connection->shouldReceive('isAuthenticated')->times(6)->andReturn(true);
        $connection->shouldReceive('exec')->once()->with('ls')->ordered()->andReturn('bin home');
        $connection->shouldReceive('exec')->once()->with('uname -m')->ordered()->andReturn('x86_64');
        $connection->shouldReceive('exec')->once()
            ->with("awk -F= '/^PRETTY_NAME=/{print $2}' /etc/os-release")
            ->ordered()->andReturn('"AlmaLinux 9.8"');
        $connection->shouldReceive('exec')->once()->with('first command')->ordered()->andReturn(" first output \n");
        $connection->shouldReceive('exec')->once()->with('second command')->ordered()->andReturn('');
        $connection->shouldReceive('exec')->once()->with('third command')->ordered()->andReturn(" third output\n");

        $sessions = Mockery::mock(DmsInstallerSshSessionFactory::class);
        $sessions->shouldReceive('create')->once()->with($server, 'stored-credential', 'password')
            ->andReturn(new DmsInstallerSshSession($connection));

        $outputs = (new DmsServerConsole($credentials, $sessions))->executeSequence($server, [
            'first command',
            'second command',
            'third command',
        ]);

        $this->assertSame(['first output', '', 'third output'], $outputs);
    }

    public function test_pmta_restart_matches_dms_command_boundaries_and_returns_only_service_restart_output(): void
    {
        $server = Mockery::mock(ManagedServer::class)->makePartial();
        $server->setAttribute('ip_address', '203.0.113.11');
        $ips = Mockery::mock(HasMany::class);
        $ips->shouldReceive('orderBy')->once()->with('id')->andReturnSelf();
        $ips->shouldReceive('value')->once()->with('dns')->andReturn('mail.example.test');
        $server->shouldReceive('ips')->once()->andReturn($ips);

        $console = Mockery::mock(DmsServerConsole::class);
        $console->shouldReceive('executeSequence')->once()->with($server, [
            "hostnamectl set-hostname 'mail.example.test'",
            "sed -i 's/HOSTNAME=.*/HOSTNAME=mail.example.test/g' /etc/sysconfig/network",
            "grep -qxF '203.0.113.11 mail.example.test' /etc/hosts || echo -e '203.0.113.11 mail.example.test' >> /etc/hosts",
            'kill -9 $(lsof -t -i:25)',
            'pmta clear dns \\*',
            'service pmta restart;service pmtahttp restart;',
        ])->andReturn([
            '',
            '',
            '',
            'Error: Error executing command: PowerMTA not running',
            'Error: Error executing command: PMTA command unavailable',
            'Restarting pmta (via systemctl): [  OK  ]\nRestarting pmtahttp (via systemctl): [  OK  ]',
        ]);

        $this->assertSame(
            'Restarting pmta (via systemctl): [  OK  ]\nRestarting pmtahttp (via systemctl): [  OK  ]',
            (new DmsPmtaAdminRestart($console))->run($server),
        );
    }
}
