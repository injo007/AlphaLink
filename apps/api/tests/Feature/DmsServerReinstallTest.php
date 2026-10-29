<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerMta;
use App\Models\SendingDomain;
use App\Models\ServerCredential;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsBulkInstallDomainDnsRecordWriter;
use App\Services\Infrastructure\DmsBulkInstallRemoteIpDiscovery;
use App\Services\Infrastructure\DmsBulkInstallRuntimeInputs;
use App\Services\Infrastructure\DmsBulkInstallTranscriptStore;
use App\Services\Infrastructure\DmsCentos9FirewallDeploymentStage;
use App\Services\Infrastructure\DmsCentos9HostPreparation;
use App\Services\Infrastructure\DmsCentos9HttpsDeploymentStage;
use App\Services\Infrastructure\DmsCentos9ImapDeploymentStage;
use App\Services\Infrastructure\DmsCentos9PhpInstallationStage;
use App\Services\Infrastructure\DmsCentos9PmtaDeploymentStage;
use App\Services\Infrastructure\DmsCentos9ProxyDeploymentStage;
use App\Services\Infrastructure\DmsCentos9ReinstallWorkflow;
use App\Services\Infrastructure\DmsCentos9ServiceSetup;
use App\Services\Infrastructure\DmsCentos9TrackingDeploymentStage;
use App\Services\Infrastructure\DmsCentos9VmtaDeploymentStage;
use App\Services\Infrastructure\DmsFailClosedInstallerTransport;
use App\Services\Infrastructure\DmsInfrastructureRunLauncher;
use App\Services\Infrastructure\DmsInstallerServerCredentialResolver;
use App\Services\Infrastructure\DmsInstallerSshSession;
use App\Services\Infrastructure\DmsInstallerSshSessionFactory;
use App\Services\Infrastructure\DmsPmtaDeploymentGate;
use App\Services\Infrastructure\DmsReinstallCompletionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DmsServerReinstallTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private ManagedServer $server;

    private SendingDomain $firstDomain;

    private SendingDomain $secondDomain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Server Reinstall workspace',
            'slug' => 'server-reinstall-workspace',
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->firstDomain = $this->createDomain('first.example.test');
        $this->secondDomain = $this->createDomain('second.example.test');
        $this->server = ManagedServer::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'ALPHA_REINSTALL_TEST',
            'provider' => 'Test provider',
            'ip_address' => '192.0.2.23',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'host_facts' => [
                'architecture' => 'x86_64',
                'os_pretty_name' => 'AlmaLinux 9.8 (Seafoam Ocelot)',
            ],
            'is_active' => true,
            'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('d', 64),
            'created_by' => $this->owner->getKey(),
            'install_parameters' => $this->savedSettings(),
        ]);
        ServerCredential::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'credential_type' => 'password',
            'encrypted_secret' => Crypt::encryptString('synthetic-test-secret'),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->actingAs($this->owner);
    }

    public function test_reinstall_page_exposes_source_controls_and_uses_its_own_default_off_gate(): void
    {
        config([
            'infrastructure.bulk_install_execution_enabled' => true,
            'infrastructure.reinstall_execution_enabled' => false,
            'infrastructure.reinstall_ip_config_execution_enabled' => false,
        ]);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('LOAD CONFIG')
            ->assertSee('WITH IPV6')
            ->assertSee('RELOAD IPS')
            ->assertSee('CONFIG IPS')
            ->assertSee('id="reinstall-ip-config-dialog"', false)
            ->assertSee('id="reinstall-ip-config-permanent"', false)
            ->assertSee('REINSTALL')
            ->assertSee('Server reinstall is disabled by ALPHALINK_REINSTALL_EXECUTION_ENABLED.')
            ->assertSee('CONFIG IPS is disabled by ALPHALINK_REINSTALL_IP_CONFIG_EXECUTION_ENABLED.')
            ->assertSee('const reinstallExecutionEnabled = false;', false)
            ->assertSee('const reinstallIpConfigExecutionEnabled = false;', false)
            ->assertSee('Confirm server reinstall', false)
            ->assertSee('Owned-domain DNS records may be replaced', false)
            ->assertSee('id="configure-reinstall-ips" disabled', false)
            ->assertSee('id="reinstall-submit" disabled', false)
            ->assertSee($this->server->name);
    }

    public function test_reinstall_page_uses_its_own_gate_when_enabled_and_still_requires_loaded_rows(): void
    {
        config([
            'infrastructure.bulk_install_execution_enabled' => false,
            'infrastructure.reinstall_execution_enabled' => true,
            'infrastructure.reinstall_ip_config_execution_enabled' => false,
        ]);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('CONFIG IPS is disabled by ALPHALINK_REINSTALL_IP_CONFIG_EXECUTION_ENABLED.')
            ->assertSee('const reinstallExecutionEnabled = true;', false)
            ->assertSee('Click LOAD CONFIG to review saved IP assignments before reinstalling.', false)
            ->assertSee('Configuration loaded. Click REINSTALL and confirm to begin.', false)
            ->assertSee('const reinstallIpConfigExecutionEnabled = false;', false)
            ->assertSee('id="reinstall-submit" disabled', false);
    }

    public function test_reinstall_endpoint_runs_readiness_then_honors_default_off_execution_gate(): void
    {
        config([
            'infrastructure.install_assets_directory' => null,
            'infrastructure.bulk_install_execution_enabled' => true,
            'infrastructure.reinstall_execution_enabled' => false,
            'infrastructure.bulk_install_settings' => [
                'pmta_admin_port' => 1213,
                'proxy_port' => 1214,
                'apply_firewall' => false,
                'allow_all_monitor' => true,
            ],
        ]);
        $credentials = Mockery::mock(DmsInstallerServerCredentialResolver::class);
        $credentials->shouldNotReceive('resolve');
        $this->app->instance(DmsInstallerServerCredentialResolver::class, $credentials);

        $this->postJson($this->runUrl(), $this->runPayload(false))
            ->assertStatus(503)
            ->assertJsonPath('code', 'server_reinstall_runner_unavailable');

        $this->assertSame($this->savedSettings(), $this->server->fresh()->install_parameters);
    }

    public function test_pmta_readiness_blockers_are_reported_before_any_ssh_session_or_execution(): void
    {
        config([
            'infrastructure.install_assets_directory' => null,
            'infrastructure.bulk_install_execution_enabled' => false,
            'infrastructure.reinstall_execution_enabled' => true,
        ]);
        $credentials = Mockery::mock(DmsInstallerServerCredentialResolver::class);
        $credentials->shouldNotReceive('resolve');
        $this->app->instance(DmsInstallerServerCredentialResolver::class, $credentials);

        $this->postJson($this->runUrl(), $this->runPayload(true))
            ->assertStatus(503)
            ->assertJsonPath('code', 'pmta_deployment_not_ready')
            ->assertJsonStructure(['blockers']);
    }

    public function test_reinstall_readiness_requires_loopback_admin_and_relay_egress_command_allowlist(): void
    {
        $workflow = app(DmsCentos9ReinstallWorkflow::class);
        $method = new \ReflectionMethod(DmsCentos9ReinstallWorkflow::class, 'pmtaReadinessCommand');
        $command = $method->invoke($workflow, 45, [
            'pmta_admin_port' => 1213,
            'dev_ips' => [],
            'pmta_relay_ips' => ['198.51.100.44'],
            'production_ips' => [],
            'allow_all_monitor' => true,
        ]);

        $this->assertStringContainsString("grep -Fqx 'http-access 127.0.0.1 admin' /etc/pmta/settings/access", $command);
        $this->assertStringContainsString("grep -Fq 'location = /command {' /etc/nginx/conf.d/default.conf", $command);
        $this->assertStringContainsString("grep -Fq 'allow 198.51.100.44;' /etc/nginx/conf.d/default.conf", $command);
        $this->assertStringNotContainsString('http-access 198.51.100.44 admin', $command);
    }

    public function test_reinstall_returns_a_queued_run_and_logs_are_available_before_the_worker_finishes(): void
    {
        config(['infrastructure.reinstall_execution_enabled' => true]);

        $runtimeInputs = Mockery::mock(DmsBulkInstallRuntimeInputs::class);
        $runtimeInputs->shouldReceive('resolve')->once()->andReturn(['settings' => [], 'assets' => []]);
        $this->app->instance(DmsBulkInstallRuntimeInputs::class, $runtimeInputs);

        $runId = (string) Str::uuid();
        $transcripts = Mockery::mock(DmsBulkInstallTranscriptStore::class);
        $transcripts->shouldReceive('createRun')->once()->andReturn($runId);
        $transcripts->shouldReceive('writeRunPayload')
            ->once()
            ->withArgs(fn (string $workspaceId, string $actualRunId, array $payload): bool => $workspaceId === (string) $this->workspace->getKey()
                && $actualRunId === $runId
                && $payload['kind'] === 'reinstall'
                && $payload['server_id'] === (string) $this->server->getKey()
                && count($payload['payload']['data']) === 3);
        $transcripts->shouldReceive('read')->once()->with((string) $this->workspace->getKey(), $runId)->andReturn('');
        $transcripts->shouldReceive('readRunStatus')->once()->with((string) $this->workspace->getKey(), $runId)->andReturn([
            'status' => 'queued',
            'results' => [],
            'error' => null,
        ]);
        $this->app->instance(DmsBulkInstallTranscriptStore::class, $transcripts);

        $launcher = Mockery::mock(DmsInfrastructureRunLauncher::class);
        $launcher->shouldReceive('launch')->once()->with((string) $this->workspace->getKey(), $runId)->andReturn(4242);
        $this->app->instance(DmsInfrastructureRunLauncher::class, $launcher);

        $workflow = Mockery::mock(DmsCentos9ReinstallWorkflow::class);
        $workflow->shouldNotReceive('run');
        $this->app->instance(DmsCentos9ReinstallWorkflow::class, $workflow);

        $this->postJson($this->runUrl(), $this->runPayload(false))
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('run_id', $runId);

        $this->getJson('/app/workspaces/'.$this->workspace->getKey().'/infrastructure/reinstall/runs/'.$runId.'/logs')
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('data', '');
    }

    public function test_reinstall_endpoint_rejects_changed_saved_ip_domain_pair_before_ssh(): void
    {
        config([
            'infrastructure.bulk_install_execution_enabled' => false,
            'infrastructure.reinstall_execution_enabled' => true,
        ]);
        $credentials = Mockery::mock(DmsInstallerServerCredentialResolver::class);
        $credentials->shouldNotReceive('resolve');
        $this->app->instance(DmsInstallerServerCredentialResolver::class, $credentials);
        $payload = $this->runPayload(false);
        $payload['data'][0]['domain_id'] = (string) Str::uuid();

        $this->postJson($this->runUrl(), $payload)
            ->assertStatus(503)
            ->assertJsonPath('code', 'server_reinstall_inputs_not_ready');
    }

    public function test_reinstall_completion_updates_ip_mta_and_owned_domain_only_when_pmta_is_selected(): void
    {
        $existingIp = ManagedServerIp::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'sending_domain_id' => $this->firstDomain->getKey(),
            'ip' => '192.0.2.99',
            'type' => 'ipv4',
            'dns' => $this->firstDomain->name,
            'is_active' => true,
        ]);
        ManagedServerMta::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'managed_server_ip_id' => $existingIp->getKey(),
            'ip' => $existingIp->ip,
            'dns' => $existingIp->dns,
            'ip_type' => 'ipv4',
            'mta_type' => 'vmta',
            'user_id' => $this->owner->getKey(),
        ]);
        $this->secondDomain->forceFill(['is_our' => true, 'status' => 'free'])->save();
        $writer = app(DmsReinstallCompletionWriter::class);

        $writer->finalize(
            $this->workspace,
            $this->owner,
            $this->server,
            $this->preparedSavedRows(),
            45,
            $this->completionOptions(false),
            (string) Str::uuid(),
        );

        $this->assertTrue($existingIp->fresh()->is_active);
        $this->assertSame(1, ManagedServerMta::query()->where('managed_server_id', $this->server->getKey())->count());
        $this->assertSame('free', $this->secondDomain->fresh()->status);
        $this->assertArrayNotHasKey('pmta', $this->server->fresh()->install_parameters);

        $writer->finalize(
            $this->workspace,
            $this->owner,
            $this->server,
            $this->preparedSavedRows(),
            45,
            $this->completionOptions(true),
            (string) Str::uuid(),
        );

        $this->assertFalse($existingIp->fresh()->is_active);
        $this->assertSame(0, ManagedServerMta::query()->where('managed_server_id', $this->server->getKey())->count());
        $this->assertSame(3, ManagedServerIp::query()
            ->where('managed_server_id', $this->server->getKey())
            ->where('is_active', true)
            ->count());
        $this->assertSame('used', $this->secondDomain->fresh()->status);
        $this->assertTrue($this->server->fresh()->install_parameters['pmta_monitor_https']);
        $this->assertSame(2, AuditEvent::query()->where('action', 'server.reinstalled')->count());
    }

    public function test_reinstall_workflow_keeps_dms_stage_order_and_skips_pmta_when_unselected(): void
    {
        $this->firstDomain->forceFill(['is_our' => true, 'status' => 'free'])->save();
        $gate = Mockery::mock(DmsPmtaDeploymentGate::class);
        $gate->shouldNotReceive('evaluate');
        $runtimeInputs = Mockery::mock(DmsBulkInstallRuntimeInputs::class);
        $runtimeInputs->shouldReceive('resolve')->once()->andReturn([
            'settings' => [
                'pmta_port' => 1212,
                'pmta_admin_port' => 1213,
                'proxy_port' => 1214,
                'dev_ips' => [],
                'production_ips' => [],
                'rdp_ips' => [],
                'allow_all_monitor' => false,
                'apply_firewall' => false,
                'dkim_selector' => '',
                'dkim_key' => '',
                'dkim_public_key' => null,
                'imap_username' => '',
                'imap_password' => '',
                'proxy_username' => '',
                'proxy_password' => '',
                'mini_app_url' => 'https://alphalink.example.test',
                'mini_app_key' => '',
            ],
            'assets' => [
                'mini_app' => null,
                'cloudflare_ips' => null,
                'dovecot_config' => null,
                'squid_config' => null,
            ],
        ]);
        $credentialResolver = Mockery::mock(DmsInstallerServerCredentialResolver::class);
        $credentialResolver->shouldReceive('resolve')->once()->with($this->server)->andReturn([
            'secret' => 'synthetic-test-secret',
            'type' => 'password',
        ]);
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $sessions = Mockery::mock(DmsInstallerSshSessionFactory::class);
        $sessions->shouldReceive('create')->once()->with($this->server, 'synthetic-test-secret', 'password')->andReturn($session);
        $hostPreparation = Mockery::mock(DmsCentos9HostPreparation::class);
        $hostPreparation->shouldReceive('configureHost')->once()->withArgs(
            fn (DmsFailClosedInstallerTransport $transport, string $ip, string $domain): bool => $ip === '192.0.2.23'
                && $domain === $this->firstDomain->name,
        );
        $serviceSetup = Mockery::mock(DmsCentos9ServiceSetup::class);
        $serviceSetup->shouldReceive('run')->once()->with(Mockery::type(DmsFailClosedInstallerTransport::class));
        $domainWriter = Mockery::mock(DmsBulkInstallDomainDnsRecordWriter::class);
        $domainWriter->shouldReceive('setHosts')->twice()->withArgs(function (SendingDomain $domain, array $records): bool {
            return ($domain->is($this->firstDomain) || $domain->is($this->secondDomain))
                && $records !== [];
        })->andReturn(true);
        $firewall = Mockery::mock(DmsCentos9FirewallDeploymentStage::class);
        $firewall->shouldReceive('run')->once()->withAnyArgs();
        $phpInstallation = Mockery::mock(DmsCentos9PhpInstallationStage::class);
        $phpInstallation->shouldNotReceive('run');
        $tracking = Mockery::mock(DmsCentos9TrackingDeploymentStage::class);
        $tracking->shouldNotReceive('run');
        $pmtaStage = Mockery::mock(DmsCentos9PmtaDeploymentStage::class);
        $pmtaStage->shouldNotReceive('run');
        $vmtas = Mockery::mock(DmsCentos9VmtaDeploymentStage::class);
        $vmtas->shouldNotReceive('run');
        $proxy = Mockery::mock(DmsCentos9ProxyDeploymentStage::class);
        $proxy->shouldNotReceive('run');
        $imap = Mockery::mock(DmsCentos9ImapDeploymentStage::class);
        $imap->shouldNotReceive('run');
        $https = Mockery::mock(DmsCentos9HttpsDeploymentStage::class);
        $https->shouldNotReceive('run');

        foreach ([
            DmsPmtaDeploymentGate::class => $gate,
            DmsBulkInstallRuntimeInputs::class => $runtimeInputs,
            DmsInstallerServerCredentialResolver::class => $credentialResolver,
            DmsInstallerSshSessionFactory::class => $sessions,
            DmsCentos9HostPreparation::class => $hostPreparation,
            DmsCentos9ServiceSetup::class => $serviceSetup,
            DmsCentos9PhpInstallationStage::class => $phpInstallation,
            DmsCentos9TrackingDeploymentStage::class => $tracking,
            DmsCentos9PmtaDeploymentStage::class => $pmtaStage,
            DmsCentos9VmtaDeploymentStage::class => $vmtas,
            DmsCentos9ProxyDeploymentStage::class => $proxy,
            DmsCentos9ImapDeploymentStage::class => $imap,
            DmsCentos9HttpsDeploymentStage::class => $https,
            DmsCentos9FirewallDeploymentStage::class => $firewall,
            DmsBulkInstallDomainDnsRecordWriter::class => $domainWriter,
        ] as $abstract => $mock) {
            $this->app->instance($abstract, $mock);
        }

        $logs = [];
        $result = app(DmsCentos9ReinstallWorkflow::class)->run(
            $this->workspace,
            $this->owner,
            $this->server,
            $this->savedSettings()['data'],
            45,
            [
                'pmta' => false,
                'tracking' => false,
                'proxy' => false,
                'imap' => false,
                'spf' => true,
                'dkim' => false,
                'dmarc' => false,
                'https' => false,
            ],
            static function (string $message) use (&$logs): void {
                $logs[] = $message;
            },
        );

        $this->assertNull($result['pmta_admin_url']);
        $this->assertSame(3, $result['ip_count']);
        $this->assertSame(2, $result['domain_count']);
        $this->assertSame([
            'Prepare Reinstalling Server : '.$this->server->getKey(),
            'Re Setup Host',
            'End Setting Host',
            'Re Setup Services',
            'End Setting Services',
            'Config Hosts A-AAAA-MX... For '.$this->firstDomain->name.' (A=2, TXT=2)',
            'Done Setting Records For '.$this->firstDomain->name.' (A=2, TXT=2)',
            'Config Hosts A-AAAA-MX... For '.$this->secondDomain->name.' (A=1, TXT=1)',
            'Done Setting Records For '.$this->secondDomain->name.' (A=1, TXT=1)',
            'Re Config Firewall',
            'End Setting Firewall',
            'Ended Disconnecting From Server : '.$this->server->getKey(),
        ], $logs);
        $this->assertSame('free', $this->firstDomain->fresh()->status);
        $this->assertArrayNotHasKey('pmta', $this->server->fresh()->install_parameters);
    }

    public function test_load_config_returns_dms_options_and_saved_ip_rows_without_a_mutation(): void
    {
        $response = $this->getJson($this->configUrl())
            ->assertOk()
            ->assertJsonPath('pmta_version', 45)
            ->assertJsonPath('tracking', true)
            ->assertJsonPath('https', false)
            ->assertJsonPath('spf', true)
            ->assertJsonPath('dkim', false)
            ->assertJsonPath('dmarc', true)
            ->assertJsonPath('data.0.ip', '192.0.2.23')
            ->assertJsonPath('data.0.main', true)
            ->assertJsonPath('data.2.domain_id', (string) $this->secondDomain->getKey());

        $response->assertJsonMissingPath('pmta');
        $this->assertSame($this->savedSettings(), $this->server->fresh()->install_parameters);
    }

    public function test_reload_ips_reuses_saved_assignments_orders_primary_first_and_filters_ipv6(): void
    {
        $discovery = Mockery::mock(DmsBulkInstallRemoteIpDiscovery::class);
        $discovery->shouldReceive('discover')
            ->once()
            ->withArgs(fn (ManagedServer $server, string $secret, string $type): bool => $server->is($this->server)
                && $secret === 'synthetic-test-secret'
                && $type === 'password')
            ->andReturn(['192.0.2.44', '192.0.2.23', '2001:db8::23']);
        $this->app->instance(DmsBulkInstallRemoteIpDiscovery::class, $discovery);

        $response = $this->postJson($this->ipsUrl(), ['with_ipv6' => false])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.ip', '192.0.2.23')
            ->assertJsonPath('data.0.main', true)
            ->assertJsonPath('data.0.sub', '')
            ->assertJsonPath('data.1.ip', '192.0.2.44')
            ->assertJsonPath('data.1.sub', 'kept-alias')
            ->assertJsonPath('data.1.domain_id', (string) $this->firstDomain->getKey());

        $this->assertSame($this->savedSettings(), $this->server->fresh()->install_parameters);
    }

    public function test_reload_ips_assigns_new_addresses_across_saved_domain_groups(): void
    {
        $this->server->forceFill(['install_parameters' => [
            'pmta_version' => 40,
            'data' => [[
                'main' => true,
                'ip' => '192.0.2.23',
                'type' => 'ipv4',
                'domain_id' => $this->firstDomain->getKey(),
                'sub' => '',
                'domain' => $this->firstDomain->name,
            ], [
                'main' => false,
                'ip' => '192.0.2.88',
                'type' => 'ipv4',
                'domain_id' => $this->secondDomain->getKey(),
                'sub' => 'stale-relay',
                'domain' => $this->secondDomain->name,
            ]],
        ]])->save();

        $discovery = Mockery::mock(DmsBulkInstallRemoteIpDiscovery::class);
        $discovery->shouldReceive('discover')->once()->andReturn([
            '192.0.2.23', '192.0.2.44', '192.0.2.55', '192.0.2.66',
        ]);
        $this->app->instance(DmsBulkInstallRemoteIpDiscovery::class, $discovery);

        $response = $this->postJson($this->ipsUrl(), [])
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.domain_id', (string) $this->firstDomain->getKey())
            ->assertJsonPath('data.1.domain_id', (string) $this->firstDomain->getKey())
            ->assertJsonPath('data.2.domain_id', (string) $this->secondDomain->getKey())
            ->assertJsonPath('data.2.main', false)
            ->assertJsonPath('data.2.domain', $this->secondDomain->name)
            ->assertJsonPath('data.3.domain_id', (string) $this->secondDomain->getKey())
            ->assertJsonPath('data.3.domain', $this->secondDomain->name);

        $this->assertNotSame('', $response->json('data.3.sub'));
    }

    public function test_configure_ips_expands_cidr_and_excludes_the_primary_address(): void
    {
        config(['infrastructure.reinstall_ip_config_execution_enabled' => true]);
        $this->server->forceFill(['ip_address' => '192.0.2.49'])->save();
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('exec')
            ->once()
            ->with("ip route | grep 'default' | awk -F ' ' '{print $5}'")
            ->ordered()
            ->andReturn('eth0');
        $session->shouldReceive('exec')
            ->once()
            ->with("ip address add '192.0.2.50'/32 dev 'eth0' && printf ALPHALINK_IP_CONFIGURED")
            ->ordered()
            ->andReturn('ALPHALINK_IP_CONFIGURED');
        $this->bindReinstallSshSession($session);

        $this->postJson($this->configIpsUrl(), [
            'ips' => ['192.0.2.48/30'],
            'permanent' => false,
        ])->assertOk()->assertJsonPath('data', 'Finish Settings Ips');
    }

    public function test_configure_ips_permanent_matches_dms_alias_file_and_restart_sequence(): void
    {
        config(['infrastructure.reinstall_ip_config_execution_enabled' => true]);
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('exec')
            ->once()
            ->with("ip route | grep 'default' | awk -F ' ' '{print $5}'")
            ->ordered()
            ->andReturn('eth0');
        $session->shouldReceive('exec')
            ->once()
            ->with("ip route | grep 'default' | awk -F ' ' '{print $3}'")
            ->ordered()
            ->andReturn('192.0.2.1');
        $session->shouldReceive('exec')
            ->once()
            ->with('rm -f -- /etc/sysconfig/network-scripts/ifcfg-eth0:* && printf ALPHALINK_ALIAS_FILES_CLEARED')
            ->ordered()
            ->andReturn('ALPHALINK_ALIAS_FILES_CLEARED');
        $session->shouldReceive('upload')
            ->once()
            ->withArgs(static fn (string $contents, string $path): bool => $path === '/etc/sysconfig/network-scripts/ifcfg-eth0:1'
                && str_contains($contents, "DEVICE=eth0:1\n")
                && str_contains($contents, "GATEWAY=192.0.2.1\n")
                && str_contains($contents, "IPADDR=192.0.2.24\n")
                && str_contains($contents, "NETMASK=255.255.255.0\n"))
            ->ordered()
            ->andReturn(true);
        $session->shouldReceive('exec')
            ->once()
            ->with('systemctl restart network && printf ALPHALINK_NETWORK_RESTARTED')
            ->ordered()
            ->andReturn('ALPHALINK_NETWORK_RESTARTED');
        $this->bindReinstallSshSession($session);

        $this->postJson($this->configIpsUrl(), [
            'ips' => ['192.0.2.24'],
            'permanent' => true,
        ])->assertOk()->assertJsonPath('data', 'Finish Settings Ips');
    }

    public function test_configure_ips_rejects_invalid_addresses_before_resolving_ssh(): void
    {
        config(['infrastructure.reinstall_ip_config_execution_enabled' => true]);
        $credentials = Mockery::mock(DmsInstallerServerCredentialResolver::class);
        $credentials->shouldNotReceive('resolve');
        $this->app->instance(DmsInstallerServerCredentialResolver::class, $credentials);

        $this->postJson($this->configIpsUrl(), [
            'ips' => ['not-an-ip'],
            'permanent' => false,
        ])->assertStatus(400)->assertJsonPath('data', 'CONFIG IPS accepts IPv4 addresses and IPv4 CIDR ranges.');
    }

    public function test_configure_ips_stays_blocked_when_only_reinstall_gate_is_enabled(): void
    {
        config([
            'infrastructure.bulk_install_execution_enabled' => true,
            'infrastructure.reinstall_execution_enabled' => true,
            'infrastructure.reinstall_ip_config_execution_enabled' => false,
        ]);
        $credentials = Mockery::mock(DmsInstallerServerCredentialResolver::class);
        $credentials->shouldNotReceive('resolve');
        $this->app->instance(DmsInstallerServerCredentialResolver::class, $credentials);

        $this->postJson($this->configIpsUrl(), [
            'ips' => ['192.0.2.24'],
            'permanent' => false,
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'server_reinstall_runner_unavailable')
            ->assertJsonPath('data', 'CONFIG IPS is disabled by ALPHALINK_REINSTALL_IP_CONFIG_EXECUTION_ENABLED.');
    }

    public function test_reinstall_load_routes_are_workspace_scoped_and_available_to_members(): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other reinstall workspace',
            'slug' => 'other-reinstall-workspace',
            'created_by' => $otherOwner->getKey(),
        ]);
        $otherWorkspace->users()->attach($otherOwner->getKey(), ['role' => 'owner']);
        $otherServer = ManagedServer::query()->create([
            'workspace_id' => $otherWorkspace->getKey(),
            'name' => 'OTHER_REINSTALL_SERVER',
            'provider' => 'Test provider',
            'ip_address' => '192.0.2.100',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('e', 64),
            'created_by' => $otherOwner->getKey(),
        ]);

        $this->getJson($this->configUrl($otherServer->getKey()))->assertNotFound();
        $this->postJson($this->ipsUrl($otherServer->getKey()), [])->assertNotFound();
        $this->postJson($this->configIpsUrl($otherServer->getKey()), [
            'ips' => ['192.0.2.24'],
        ])->assertNotFound();

        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        config(['infrastructure.reinstall_ip_config_execution_enabled' => true]);
        $this->actingAs($operator)
            ->getJson($this->configUrl())->assertOk();
        $this->postJson($this->configIpsUrl(), [
            'ips' => ['192.0.2.24'],
        ])->assertStatus(400);
    }

    public function test_reload_ips_rejects_missing_saved_domain_assignments_without_ssh(): void
    {
        $this->server->forceFill(['install_parameters' => ['data' => []]])->save();
        $discovery = Mockery::mock(DmsBulkInstallRemoteIpDiscovery::class);
        $discovery->shouldNotReceive('discover');
        $this->app->instance(DmsBulkInstallRemoteIpDiscovery::class, $discovery);

        $this->postJson($this->ipsUrl(), [])
            ->assertStatus(400)
            ->assertJsonPath('data', 'The saved reinstall configuration has no domain assignments.');
    }

    private function createDomain(string $name): SendingDomain
    {
        return SendingDomain::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => $name,
            'state' => 'verified',
            'verification_token' => (string) Str::random(40),
            'created_by' => $this->owner->getKey(),
            'status' => 'used',
            'is_our' => false,
            'is_active' => true,
        ]);
    }

    private function savedSettings(): array
    {
        return [
            'pmta_version' => 45,
            'tracking' => true,
            'proxy' => false,
            'imap' => true,
            'https' => false,
            'spf' => true,
            'dkim' => false,
            'dmarc' => true,
            'data' => [[
                'main' => true,
                'ip' => '192.0.2.23',
                'type' => 'ipv4',
                'domain_id' => $this->firstDomain->getKey(),
                'sub' => '',
                'domain' => $this->firstDomain->name,
            ], [
                'main' => false,
                'ip' => '192.0.2.44',
                'type' => 'ipv4',
                'domain_id' => $this->firstDomain->getKey(),
                'sub' => 'kept-alias',
                'domain' => $this->firstDomain->name,
            ], [
                'main' => false,
                'ip' => '192.0.2.88',
                'type' => 'ipv4',
                'domain_id' => $this->secondDomain->getKey(),
                'sub' => 'second-relay',
                'domain' => $this->secondDomain->name,
            ]],
        ];
    }

    private function url(): string
    {
        return '/infrastructure/reinstall';
    }

    private function configUrl(?string $serverId = null): string
    {
        return $this->url().'/'.($serverId ?? $this->server->getKey()).'/config';
    }

    private function ipsUrl(?string $serverId = null): string
    {
        return $this->url().'/'.($serverId ?? $this->server->getKey()).'/ips';
    }

    private function configIpsUrl(?string $serverId = null): string
    {
        return $this->url().'/'.($serverId ?? $this->server->getKey()).'/config-ips';
    }

    private function runUrl(?string $serverId = null): string
    {
        return $this->url().'/'.($serverId ?? $this->server->getKey()).'/runs';
    }

    /** @return array<string, mixed> */
    private function runPayload(bool $pmta): array
    {
        return [
            'pmta' => $pmta,
            'pmta_version' => 45,
            'tracking' => false,
            'https' => false,
            'proxy' => false,
            'imap' => false,
            'spf' => false,
            'dkim' => false,
            'dmarc' => false,
            'data' => $this->savedSettings()['data'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function preparedSavedRows(): array
    {
        return array_map(function (array $row): array {
            $domain = SendingDomain::withTrashed()->findOrFail($row['domain_id']);

            return [
                ...$row,
                'domain_id' => (string) $domain->getKey(),
                'is_our' => (bool) $domain->is_our,
            ];
        }, $this->savedSettings()['data']);
    }

    /** @return array{pmta: bool, tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool} */
    private function completionOptions(bool $pmta): array
    {
        return [
            'pmta' => $pmta,
            'tracking' => false,
            'proxy' => false,
            'imap' => false,
            'spf' => false,
            'dkim' => false,
            'dmarc' => false,
            'https' => false,
        ];
    }

    private function bindReinstallSshSession(DmsInstallerSshSession $session): void
    {
        $credentials = Mockery::mock(DmsInstallerServerCredentialResolver::class);
        $credentials->shouldReceive('resolve')->once()->withArgs(
            fn (ManagedServer $server): bool => $server->is($this->server),
        )->andReturn([
            'secret' => 'synthetic-test-secret',
            'type' => 'password',
        ]);
        $this->app->instance(DmsInstallerServerCredentialResolver::class, $credentials);

        $sessions = Mockery::mock(DmsInstallerSshSessionFactory::class);
        $sessions->shouldReceive('create')->once()->withArgs(
            fn (ManagedServer $server, string $secret, string $type): bool => $server->is($this->server)
                && $secret === 'synthetic-test-secret'
                && $type === 'password',
        )->andReturn($session);
        $this->app->instance(DmsInstallerSshSessionFactory::class, $sessions);
    }
}
