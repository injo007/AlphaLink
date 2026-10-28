<?php

namespace Tests\Unit;

use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsBulkInstallCompletionWriter;
use App\Services\Infrastructure\DmsBulkInstallDnsRecordPlanner;
use App\Services\Infrastructure\DmsBulkInstallDomainDnsRecordWriter;
use App\Services\Infrastructure\DmsBulkInstallDomainRecordStage;
use App\Services\Infrastructure\DmsBulkInstallIpPlanner;
use App\Services\Infrastructure\DmsBulkInstallVmtaPlanner;
use App\Services\Infrastructure\DmsCentos9FirewallDeploymentStage;
use App\Services\Infrastructure\DmsCentos9FirewallPlanner;
use App\Services\Infrastructure\DmsCentos9HostPreparation;
use App\Services\Infrastructure\DmsCentos9HttpsDeploymentStage;
use App\Services\Infrastructure\DmsCentos9ImapDeploymentStage;
use App\Services\Infrastructure\DmsCentos9InstallWorkflow;
use App\Services\Infrastructure\DmsCentos9PhpInstallationStage;
use App\Services\Infrastructure\DmsCentos9PmtaDeploymentStage;
use App\Services\Infrastructure\DmsCentos9ProxyDeploymentStage;
use App\Services\Infrastructure\DmsCentos9ServiceSetup;
use App\Services\Infrastructure\DmsCentos9TrackingDeploymentStage;
use App\Services\Infrastructure\DmsCentos9VmtaDeploymentStage;
use App\Services\Infrastructure\DmsInstallerRemoteCommandTransport;
use App\Services\Infrastructure\DmsInstallerRemoteFileTransport;
use App\Services\Infrastructure\DmsInstallerRemoteReadableFileTransport;
use App\Services\Infrastructure\DmsInstallPlatform;
use App\Services\Infrastructure\DmsPmtaDeploymentGate;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Tests\TestCase;

class DmsCentos9InstallWorkflowTest extends TestCase
{
    public function test_it_blocks_before_any_host_operation_when_pmta_evidence_is_not_ready(): void
    {
        [$workflow, $gate, $pmta, $completion] = $this->workflow();
        $gate->method('evaluate')->willReturn(['installation_authorized' => false]);
        $pmta->expects($this->never())->method('run');
        $completion->expects($this->never())->method('finalize');
        $session = new RecordingWorkflowSession;
        $logs = [];

        try {
            $this->runWorkflow($workflow, $session, $logs, [], [], [], ['8.8.8.8'], false);
            $this->fail('The PMTA gate must reject before host commands.');
        } catch (RuntimeException $exception) {
            $this->assertSame('PMTA deployment prerequisites are not verified.', $exception->getMessage());
        }

        $this->assertSame([], $session->commands);
        $this->assertSame([], $session->uploads);
        $this->assertSame([], $logs);
    }

    public function test_it_dispatches_the_non_owned_almalinux_9_row_in_dms_order(): void
    {
        [$workflow, $gate, $pmta, $completion] = $this->workflow();
        $gate->method('evaluate')->willReturn(['installation_authorized' => true]);
        $pmta->expects($this->once())->method('run');
        $completion->expects($this->once())->method('finalize')->willReturn([]);
        $session = new RecordingWorkflowSession;
        $logs = [];

        $this->runWorkflow($workflow, $session, $logs, [], [
            'dev_ips' => [],
            'allow_all_monitor' => true,
        ]);

        $this->assertSame([
            'Setup Host', 'End Setting Host',
            'Removing Old Packages', 'End Removing Old Packages',
            'Setup Services', 'End Setting Services',
            'Config Hosts A-AAAA-MX... For example.test (A=1)',
            'Done Setting Records For example.test (A=1)',
            'Setup PMTA 40', 'End Setting PMTA',
            'Setup VMTAS', 'End Setting VMTAS',
            'Config Firewall', 'End Setting Firewall',
            'PMTA WEB MONITOR LINK (public PMTA HTTP port 8080): >>> <a target="_blank" href="http://8.8.8.8:8080">8.8.8.8:8080</a>',
            'Ended Disconnecting',
        ], $logs);
        $this->assertSame("hostnamectl set-hostname 'example.test'", $session->commands[0]);
        $this->assertContains('dnf remove -y httpd libopendkim* opendkim postfix php* sendmail;', $session->commands);
        $this->assertNotContains('dnf module enable php:remi-8.3 -y;', $session->commands);
        $this->assertArrayHasKey('/etc/pmta/conf/vmta', $session->uploads);
        $healthCommand = end($session->commands);
        $this->assertIsString($healthCommand);
        $this->assertStringContainsString("grep -Fqx 'http-access 0/0 monitor' /etc/pmta/settings/access", $healthCommand);
        $this->assertStringContainsString("grep -Fq '<source 198.51.100.44>' /etc/pmta/settings/access", $healthCommand);
        $this->assertStringContainsString("grep -Fqx 'http-access 198.51.100.44 admin' /etc/pmta/settings/access", $healthCommand);
        $this->assertStringNotContainsString('http-access 0/0 admin', $healthCommand);
    }

    public function test_it_does_not_mark_the_server_installed_when_pmta_health_fails(): void
    {
        [$workflow, $gate, $pmta, $completion] = $this->workflow();
        $gate->method('evaluate')->willReturn(['installation_authorized' => true]);
        $pmta->expects($this->once())->method('run');
        $completion->expects($this->never())->method('finalize');
        $session = new RecordingWorkflowSession;
        $session->pmtaReady = false;
        $logs = [];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PMTA package, HTTP listener, access policy, or monitor proxy failed post-install verification.');

        $this->runWorkflow($workflow, $session, $logs);
    }

    public function test_pmta_45_verifies_the_backend_is_loopback_only_and_logs_one_browser_port(): void
    {
        [$workflow, $gate, $pmta, $completion] = $this->workflow();
        $gate->method('evaluate')->willReturn(['installation_authorized' => true]);
        $pmta->expects($this->once())->method('run');
        $completion->expects($this->once())->method('finalize')->willReturn([]);
        $session = new RecordingWorkflowSession;
        $logs = [];

        $this->runWorkflow(
            $workflow,
            $session,
            $logs,
            settingsOverrides: ['dev_ips' => [], 'allow_all_monitor' => true, 'pmta_admin_port' => 1213],
            pmtaVersion: 45,
        );

        $healthCommand = end($session->commands);
        $this->assertIsString($healthCommand);
        $this->assertStringContainsString("grep -Fqx 'http-access 127.0.0.1 admin' /etc/pmta/settings/access", $healthCommand);
        $this->assertStringContainsString("grep -Fq 'location = /command {' /etc/nginx/conf.d/default.conf", $healthCommand);
        $this->assertStringContainsString("grep -Fq 'allow 198.51.100.44;' /etc/nginx/conf.d/default.conf", $healthCommand);
        $this->assertStringContainsString("grep -Fq '# ALPHALINK_PMTA_MONITOR_ACCESS_PUBLIC' /etc/nginx/conf.d/default.conf", $healthCommand);
        $this->assertStringContainsString("! grep -Fqx 'http-access 0/0 monitor' /etc/pmta/settings/access", $healthCommand);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:2426;', $healthCommand);
        $this->assertSame(
            'PMTA WEB MONITOR LINK (public monitor port 1213; backend-only PMTA listener 2426): >>> <a target="_blank" href="http://8.8.8.8:1213">8.8.8.8:1213</a>',
            $logs[count($logs) - 2],
        );
    }

    public function test_private_interface_without_public_dns_mapping_stops_before_host_changes(): void
    {
        [$workflow, $gate, $pmta, $completion] = $this->workflow();
        $gate->method('evaluate')->willReturn(['installation_authorized' => true]);
        $pmta->expects($this->never())->method('run');
        $completion->expects($this->never())->method('finalize');
        $session = new RecordingWorkflowSession;
        $logs = [];

        try {
            $this->runWorkflow($workflow, $session, $logs, ['spf' => true], [], [], ['10.0.0.8'], false);
            $this->fail('An unresolved private interface must stop before host changes.');
        } catch (RuntimeException $exception) {
            $this->assertSame('A selected interface has no verified public IP for DNS records.', $exception->getMessage());
        }

        $this->assertSame([
            "curl -fsS --connect-timeout 3 --max-time 6 --interface '10.0.0.8' https://ifconfig.me/ip 2>/dev/null",
            "curl -fsS --connect-timeout 3 --max-time 6 --interface '10.0.0.8' https://api.ipify.org 2>/dev/null",
            "curl -fsS --connect-timeout 3 --max-time 6 --interface '10.0.0.8' https://icanhazip.com 2>/dev/null",
            "curl -fsS --connect-timeout 3 --max-time 6 --interface '10.0.0.8' https://ident.me 2>/dev/null",
        ], $session->commands);
        $this->assertSame([], $session->uploads);
        $this->assertSame([], $logs);
    }

    public function test_it_dispatches_php_and_apache_only_when_tracking_is_selected(): void
    {
        [$workflow, $gate, $pmta, $completion] = $this->workflow();
        $gate->method('evaluate')->willReturn(['installation_authorized' => true]);
        $pmta->expects($this->once())->method('run');
        $completion->expects($this->once())->method('finalize')->willReturn([]);
        $session = new RecordingWorkflowSession;
        $logs = [];

        $this->runWorkflow($workflow, $session, $logs, ['tracking' => true]);

        $this->assertContains('dnf module enable php:remi-8.3 -y;', $session->commands);
        $this->assertContains('dnf install -y httpd', $session->commands);
        $this->assertLessThan(
            array_search('dnf install -y httpd', $session->commands, true),
            array_search('dnf module enable php:remi-8.3 -y;', $session->commands, true),
        );
        $this->assertContains('Setup PHP', $logs);
        $this->assertContains('Setup Tracking App', $logs);
    }

    public function test_it_rejects_invalid_runtime_settings_before_gate_or_host_commands(): void
    {
        [$workflow, $gate, $pmta, $completion] = $this->workflow();
        $gate->expects($this->never())->method('evaluate');
        $pmta->expects($this->never())->method('run');
        $completion->expects($this->never())->method('finalize');
        $session = new RecordingWorkflowSession;
        $logs = [];

        try {
            $this->runWorkflow($workflow, $session, $logs, [], ['pmta_port' => 0], [], ['8.8.8.8'], false);
            $this->fail('Invalid settings must reject before host commands.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('A required DMS installation port is invalid.', $exception->getMessage());
        }

        $this->assertSame([], $session->commands);
        $this->assertSame([], $logs);
    }

    public function test_it_rejects_centos_7_before_using_almalinux_9_commands(): void
    {
        [$workflow, $gate, $pmta, $completion] = $this->workflow();
        $gate->expects($this->never())->method('evaluate');
        $pmta->expects($this->never())->method('run');
        $completion->expects($this->never())->method('finalize');
        $session = new RecordingWorkflowSession;
        $logs = [];

        try {
            $this->runWorkflow($workflow, $session, $logs, [], [], ['os_pretty_name' => 'CentOS Linux 7'], ['8.8.8.8'], false);
            $this->fail('The CentOS 9 workflow must not run on CentOS 7.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('The AlmaLinux 9 workflow requires a DMS CentOS 9 family host.', $exception->getMessage());
        }

        $this->assertSame([], $session->commands);
    }

    public function test_explicit_single_install_assignments_keep_each_domains_dns_vmta_and_primary_host_name(): void
    {
        [$workflow, $gate, $pmta, $completion] = $this->workflow();
        $gate->method('evaluate')->willReturn(['installation_authorized' => true]);
        $pmta->expects($this->once())->method('run');

        $workspace = (new Workspace)->forceFill(['id' => 'workspace-one']);
        $server = (new ManagedServer)->forceFill([
            'id' => 'server-one',
            'workspace_id' => 'workspace-one',
            'ip_address' => '198.51.100.30',
            'ssh_port' => 22,
            'host_facts' => ['os_pretty_name' => 'AlmaLinux 9'],
            'is_installed' => false,
        ]);
        $externalDomain = (new SendingDomain)->forceFill([
            'id' => 'domain-external', 'workspace_id' => 'workspace-one', 'name' => 'external.example.test',
            'status' => 'free', 'is_our' => false, 'is_active' => true,
        ]);
        $ownedDomain = (new SendingDomain)->forceFill([
            'id' => 'domain-owned', 'workspace_id' => 'workspace-one', 'name' => 'owned.example.test',
            'status' => 'new', 'is_our' => true, 'is_active' => true,
        ]);
        $assignments = [
            [
                'main' => true, 'ip' => '198.51.100.30', 'type' => 'ipv4', 'domain_id' => 'domain-external',
                'sub' => '', 'domain' => 'external.example.test', 'is_our' => false,
            ],
            [
                'main' => false, 'ip' => '2001:db8::30', 'type' => 'ipv6', 'domain_id' => 'domain-owned',
                'sub' => 'relay', 'domain' => 'owned.example.test', 'is_our' => true,
            ],
        ];
        $options = [
            'tracking' => false, 'proxy' => false, 'imap' => false, 'spf' => false,
            'dkim' => false, 'dmarc' => false, 'https' => false,
        ];
        $completion->expects($this->once())
            ->method('finalize')
            ->with($workspace, $server, $externalDomain, $assignments, 40, $options, [$externalDomain, $ownedDomain])
            ->willReturn([]);

        $dnsWriter = $this->createMock(DmsBulkInstallDomainDnsRecordWriter::class);
        $dnsWrites = [];
        $dnsWriter->expects($this->exactly(2))
            ->method('setHosts')
            ->willReturnCallback(static function (SendingDomain $domain, array $records) use (&$dnsWrites): bool {
                $dnsWrites[] = ['domain' => $domain->name, 'records' => $records];

                return true;
            });
        $session = new RecordingWorkflowSession;
        $settings = [
            'pmta_port' => 1212, 'pmta_admin_port' => 8080, 'proxy_port' => 3128,
            'dev_ips' => ['192.0.2.10'], 'pmta_relay_ips' => ['198.51.100.44'], 'production_ips' => [], 'rdp_ips' => [],
            'allow_all_monitor' => false, 'apply_firewall' => false,
            'dkim_selector' => 'selector', 'dkim_key' => '', 'dkim_public_key' => null,
            'imap_username' => '', 'imap_password' => '', 'proxy_username' => '', 'proxy_password' => '',
            'mini_app_url' => '', 'mini_app_key' => '',
        ];
        $assets = ['mini_app' => null, 'cloudflare_ips' => null, 'dovecot_config' => null, 'squid_config' => null];
        $logs = [];

        $workflow->run(
            $workspace,
            $server,
            $externalDomain,
            ['198.51.100.30', '2001:db8::30'],
            [],
            40,
            $options,
            $settings,
            $assets,
            $session,
            $dnsWriter,
            static function (string $line) use (&$logs): void {
                $logs[] = $line;
            },
            $assignments,
            [$externalDomain, $ownedDomain],
        );

        $this->assertSame("hostnamectl set-hostname 'external.example.test'", $session->commands[0]);
        $this->assertStringContainsString('host-name external.example.test', $session->uploads['/etc/pmta/conf/vmta']);
        $this->assertStringContainsString('host-name relay.owned.example.test', $session->uploads['/etc/pmta/conf/vmta']);
        $this->assertStringContainsString('relay-domain relay.owned.example.test', $session->uploads['/etc/pmta/settings/inbound']);
        $this->assertContains('Config Hosts A-AAAA-MX... For external.example.test (A=1)', $logs);
        $this->assertContains('Config Hosts A-AAAA-MX... For owned.example.test (AAAA=1)', $logs);
        $this->assertSame([
            ['domain' => 'external.example.test', 'records' => [
                ['host' => '@', 'address' => '198.51.100.30', 'type' => 'A', 'ttl' => '60'],
            ]],
            ['domain' => 'owned.example.test', 'records' => [
                ['host' => 'relay', 'address' => '2001:db8::30', 'type' => 'AAAA', 'ttl' => '60'],
            ]],
        ], $dnsWrites);
    }

    /** @return array{DmsCentos9InstallWorkflow, DmsPmtaDeploymentGate&MockObject, DmsCentos9PmtaDeploymentStage&MockObject, DmsBulkInstallCompletionWriter&MockObject} */
    private function workflow(): array
    {
        $gate = $this->createMock(DmsPmtaDeploymentGate::class);
        $pmta = $this->createMock(DmsCentos9PmtaDeploymentStage::class);
        $completion = $this->createMock(DmsBulkInstallCompletionWriter::class);

        return [new DmsCentos9InstallWorkflow(
            $gate,
            new DmsInstallPlatform,
            new DmsBulkInstallIpPlanner,
            new DmsCentos9HostPreparation,
            new DmsCentos9ServiceSetup,
            new DmsCentos9PhpInstallationStage,
            new DmsCentos9TrackingDeploymentStage,
            new DmsBulkInstallDomainRecordStage(new DmsBulkInstallDnsRecordPlanner),
            $pmta,
            new DmsCentos9VmtaDeploymentStage(new DmsBulkInstallVmtaPlanner),
            new DmsCentos9ImapDeploymentStage,
            new DmsCentos9ProxyDeploymentStage,
            new DmsCentos9HttpsDeploymentStage,
            new DmsCentos9FirewallDeploymentStage(new DmsCentos9FirewallPlanner),
            $completion,
        ), $gate, $pmta, $completion];
    }

    /**
     * @param  list<string>  $logs
     * @param  array<string, bool>  $selectedOptions
     * @param  array<string, mixed>  $settingsOverrides
     * @param  array<string, mixed>  $hostFactsOverrides
     */
    private function runWorkflow(DmsCentos9InstallWorkflow $workflow, RecordingWorkflowSession $session, array &$logs, array $selectedOptions = [], array $settingsOverrides = [], array $hostFactsOverrides = [], array $discoveredIps = ['8.8.8.8'], bool $expectDnsWrite = true, int $pmtaVersion = 40): void
    {
        $workspace = (new Workspace)->forceFill(['id' => 'workspace-one']);
        $server = (new ManagedServer)->forceFill([
            'id' => 'server-one',
            'workspace_id' => 'workspace-one',
            'ip_address' => $discoveredIps[0] ?? '8.8.8.8',
            'ssh_port' => 22,
            'host_facts' => array_replace(['os_pretty_name' => 'AlmaLinux 9'], $hostFactsOverrides),
            'is_installed' => false,
        ]);
        $domain = (new SendingDomain)->forceFill([
            'id' => 'domain-one',
            'workspace_id' => 'workspace-one',
            'name' => 'example.test',
            'status' => 'free',
            'is_our' => false,
        ]);
        $options = [
            'tracking' => false, 'proxy' => false, 'imap' => false, 'spf' => false,
            'dkim' => false, 'dmarc' => false, 'https' => false,
        ];
        $options = array_replace($options, $selectedOptions);
        $settings = [
            'pmta_port' => 1212, 'pmta_admin_port' => 8080, 'proxy_port' => 3128,
            'dev_ips' => ['192.0.2.10'], 'pmta_relay_ips' => ['198.51.100.44'], 'production_ips' => [], 'rdp_ips' => [],
            'allow_all_monitor' => false, 'apply_firewall' => false,
            'dkim_selector' => 'selector', 'dkim_key' => '', 'dkim_public_key' => null,
            'imap_username' => '', 'imap_password' => '',
            'proxy_username' => '', 'proxy_password' => '',
            'mini_app_url' => '', 'mini_app_key' => '',
        ];
        $settings = array_replace($settings, $settingsOverrides);
        $assets = ['mini_app' => null, 'cloudflare_ips' => null, 'dovecot_config' => null, 'squid_config' => null];
        $dnsWriter = $this->createMock(DmsBulkInstallDomainDnsRecordWriter::class);
        if ($expectDnsWrite) {
            $dnsWriter->expects($this->once())->method('setHosts')->willReturn(true);
        } else {
            $dnsWriter->expects($this->never())->method('setHosts');
        }

        $workflow->run(
            $workspace, $server, $domain, $discoveredIps, [], $pmtaVersion, $options, $settings,
            $assets, $session, $dnsWriter,
            static function (string $line) use (&$logs): void {
                $logs[] = $line;
            },
        );
    }
}

class RecordingWorkflowSession implements DmsInstallerRemoteCommandTransport, DmsInstallerRemoteFileTransport, DmsInstallerRemoteReadableFileTransport
{
    public bool $pmtaReady = true;

    /** @var list<string> */
    public array $commands = [];

    /** @var array<string, string> */
    public array $uploads = [];

    public function exec(string $command): bool|string
    {
        $this->commands[] = $command;

        if (str_contains($command, 'printf ALPHALINK_PMTA_READY')) {
            return $this->pmtaReady ? 'ALPHALINK_PMTA_READY' : '';
        }

        return $command === 'free -m | grep Swap | awk \'{print $2}\'' ? '2048' : '';
    }

    public function upload(string $contents, string $destination): bool
    {
        $this->uploads[$destination] = $contents;

        return true;
    }

    public function uploadFile(string $localPath, string $destination): bool
    {
        $contents = file_get_contents($localPath);
        if (! is_string($contents)) {
            return false;
        }

        $this->uploads[$destination] = $contents;

        return true;
    }

    public function download(string $path): string|false
    {
        return false;
    }
}
