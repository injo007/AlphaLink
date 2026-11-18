<?php

namespace Tests\Unit;

use App\Models\ManagedServer;
use App\Models\Workspace;
use App\Models\WorkspaceInfrastructureSettings;
use App\Services\Campaigns\PmtaMonitorProxy;
use App\Services\Campaigns\PmtaProxySettings;
use App\Services\Campaigns\PmtaQueueAdmin;
use App\Services\Infrastructure\AlphaLinkPmtaApplicationReadiness;
use Illuminate\Http\Response;
use RuntimeException;
use Tests\TestCase;

class AlphaLinkPmtaApplicationReadinessTest extends TestCase
{
    public function test_it_checks_the_ip_monitor_and_read_only_command_using_the_configured_proxy(): void
    {
        config([
            'infrastructure.bulk_install_settings.proxy_host' => '198.51.100.77',
            'infrastructure.bulk_install_settings.proxy_port' => 8977,
            'infrastructure.bulk_install_settings.proxy_username' => 'relay-user',
            'infrastructure.bulk_install_settings.proxy_password' => 'relay-password',
        ]);
        $workspace = $this->workspace();
        $server = (new ManagedServer)->forceFill(['id' => 'server-one', 'ip_address' => '192.0.2.40']);
        $monitor = $this->createMock(PmtaMonitorProxy::class);
        $monitor->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (ManagedServer $actualServer, int $actualPort, string $path, string $workspaceId, string $proxyHost, int $proxyPort, string $username, string $password, string $target) use ($server): Response {
                $this->assertSame($server, $actualServer);
                $this->assertSame(1213, $actualPort);
                $this->assertSame('workspace-one', $workspaceId);
                $this->assertSame('198.51.100.77', $proxyHost);
                $this->assertSame(8977, $proxyPort);
                $this->assertSame('relay-user', $username);
                $this->assertSame('relay-password', $password);
                $this->assertSame('ip', $target);

                return $path === ''
                    ? response('<html><head><link rel="stylesheet" href="/console/pmta-admin/monitor?asset=style.css&amp;target=ip"></head><body>PMTA</body></html>', 200)
                    : response('body { color: black; }', 200, ['Content-Type' => 'text/css; charset=UTF-8']);
            });
        $commands = $this->createMock(PmtaQueueAdmin::class);
        $commands->expects($this->once())
            ->method('verifyReadOnlyStatusAccess')
            ->with($server, 1213, 'http://relay-user:relay-password@198.51.100.77:8977');

        $readiness = new AlphaLinkPmtaApplicationReadiness(new PmtaProxySettings, $monitor, $commands);
        $readiness->verify($workspace, $server, 1213);
    }

    public function test_it_does_not_probe_commands_when_the_monitor_proxy_is_not_ready(): void
    {
        config([
            'infrastructure.bulk_install_settings.proxy_host' => '198.51.100.77',
            'infrastructure.bulk_install_settings.proxy_port' => 8977,
        ]);
        $workspace = $this->workspace();
        $server = (new ManagedServer)->forceFill(['id' => 'server-one', 'ip_address' => '192.0.2.40']);
        $monitor = $this->createStub(PmtaMonitorProxy::class);
        $monitor->method('get')->willReturn(response('HTTP error', 502));
        $commands = $this->createMock(PmtaQueueAdmin::class);
        $commands->expects($this->never())->method('verifyReadOnlyStatusAccess');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PMTA monitor readiness through the configured relay failed with HTTP 502.');

        (new AlphaLinkPmtaApplicationReadiness(new PmtaProxySettings, $monitor, $commands))
            ->verify($workspace, $server, 1213);
    }

    public function test_it_does_not_probe_commands_when_the_monitor_stylesheet_is_unavailable(): void
    {
        config([
            'infrastructure.bulk_install_settings.proxy_host' => '198.51.100.77',
            'infrastructure.bulk_install_settings.proxy_port' => 8977,
        ]);
        $workspace = $this->workspace();
        $server = (new ManagedServer)->forceFill(['id' => 'server-one', 'ip_address' => '192.0.2.40']);
        $monitor = $this->createMock(PmtaMonitorProxy::class);
        $monitor->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                response('<html><head><link rel="stylesheet" href="/console/pmta-admin/monitor?asset=style.css&amp;target=ip"></head><body>PMTA</body></html>', 200),
                response('not a stylesheet', 200, ['Content-Type' => 'text/plain']),
            );
        $commands = $this->createMock(PmtaQueueAdmin::class);
        $commands->expects($this->never())->method('verifyReadOnlyStatusAccess');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PMTA monitor stylesheet readiness through the configured relay failed.');

        (new AlphaLinkPmtaApplicationReadiness(new PmtaProxySettings, $monitor, $commands))
            ->verify($workspace, $server, 1213);
    }

    private function workspace(): Workspace
    {
        $workspace = (new Workspace)->forceFill(['id' => 'workspace-one']);
        $workspace->setRelation('infrastructureSettings', (new WorkspaceInfrastructureSettings)->forceFill([
            'pmta_relay_ips' => ['203.0.113.14'],
        ]));

        return $workspace;
    }
}
