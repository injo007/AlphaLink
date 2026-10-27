<?php

namespace Tests\Feature;

use App\Models\DomainProvider;
use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\ServerCredential;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsBulkInstallEligibility;
use App\Services\Infrastructure\DmsBulkInstallPayload;
use App\Services\Infrastructure\DmsBulkInstallRemoteIpDiscovery;
use App\Services\Infrastructure\DmsBulkInstallRowProcessPayload;
use App\Services\Infrastructure\DmsBulkInstallRunProcessPoolCoordinator;
use App\Services\Infrastructure\DmsBulkInstallRuntimeInputs;
use App\Services\Infrastructure\DmsBulkInstallTranscriptStore;
use App\Services\Infrastructure\DmsInfrastructureRunLauncher;
use App\Services\Infrastructure\DmsInstallerServerCredentialResolver;
use App\Services\Infrastructure\DmsInstallerSshSession;
use App\Services\Infrastructure\DmsInstallerSshSessionFactory;
use App\Services\Infrastructure\DmsPmtaDeploymentGate;
use App\Services\Infrastructure\DmsReinstallIpConfigurator;
use App\Services\Infrastructure\InfrastructureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DmsSingleInstallTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private ManagedServer $server;

    private DomainProvider $provider;

    private SendingDomain $externalDomain;

    private SendingDomain $ownedDomain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['username' => 'mailer']);
        $this->workspace = Workspace::query()->create([
            'name' => 'Single Install workspace',
            'slug' => 'single-install-workspace',
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->provider = DomainProvider::query()->create(['name' => 'DMS provider', 'is_active' => true]);
        $this->server = ManagedServer::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'ALPHA_INSTALL_NODE',
            'provider' => 'Synthetic provider',
            'ip_address' => '198.51.100.12',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'expected_host_fingerprint' => 'SHA256:internal-test-pin',
            'observed_host_fingerprint' => 'SHA256:internal-test-pin',
            'host_facts' => [
                'os_id' => 'almalinux',
                'os_version' => '9.8',
                'os_pretty_name' => 'AlmaLinux 9.8 (Synthetic)',
                'architecture' => 'x86_64',
            ],
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => false,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64),
            'created_by' => $this->owner->getKey(),
        ]);
        ServerCredential::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'credential_type' => 'password',
            'encrypted_secret' => Crypt::encryptString('synthetic-install-credential'),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->externalDomain = $this->createDomain('external.example.test', false);
        $this->ownedDomain = $this->createDomain('owned.example.test', true);
        $this->actingAs($this->owner);
    }

    public function test_install_page_uses_dms_domain_eligibility_and_topology_controls(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSee('ALPHA_INSTALL_NODE')
            ->assertDontSee('No active, uninstalled servers are available for installation.')
            ->assertDontSee('Set your DMS installation username')
            ->assertSee('id="single-domains" multiple', false)
            ->assertSee('WITH IPV6')
            ->assertSee('LOAD IPS')
            ->assertSee('CONFIG IPS')
            ->assertSee('id="single-config-ips" type="button" disabled', false)
            ->assertSee('id="single-install-ip-config-dialog"', false)
            ->assertSee('infrastructure/install/__SERVER__/config-ips')
            ->assertSee('id="single-install-ips"', false)
            ->assertSee('<option value="45" selected>PMTA 4.5</option>', false)
            ->assertSee('id="tracking" checked', false)
            ->assertSee('id="imap" checked', false)
            ->assertSee('id="spf" checked', false)
            ->assertSee('id="dkim" checked', false)
            ->assertSee('id="dmarc" checked', false)
            ->assertSee($this->externalDomain->name)
            ->assertSee($this->ownedDomain->name)
            ->assertSee('LISTED : SPAMHAUS');
    }

    public function test_single_install_server_eligibility_does_not_apply_bulk_username_name_filter(): void
    {
        $this->assertSame(
            (string) $this->server->getKey(),
            (string) app(DmsBulkInstallEligibility::class)
                ->singleInstallServerForSelection($this->workspace, (string) $this->server->getKey())
                ?->getKey(),
        );
    }

    public function test_load_ips_moves_primary_first_filters_ipv6_and_splits_rows_across_selected_domains(): void
    {
        $discovery = Mockery::mock(DmsBulkInstallRemoteIpDiscovery::class);
        $discovery->shouldReceive('discover')
            ->once()
            ->withArgs(fn (ManagedServer $server, string $secret, string $type): bool => $server->is($this->server)
                && $secret === 'synthetic-install-credential'
                && $type === 'password')
            ->andReturn(['10.20.0.8', '198.51.100.12', '2001:db8::12']);
        $this->app->instance(DmsBulkInstallRemoteIpDiscovery::class, $discovery);

        $this->postJson($this->loadIpsUrl(), [
            'server_id' => $this->server->getKey(),
            'domain_ids' => [$this->externalDomain->getKey(), $this->ownedDomain->getKey()],
            'with_ipv6' => true,
        ])
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.ip', '198.51.100.12')
            ->assertJsonPath('data.0.main', true)
            ->assertJsonPath('data.0.domain_id', (string) $this->externalDomain->getKey())
            ->assertJsonPath('data.0.sub', '')
            ->assertJsonPath('data.1.domain_id', (string) $this->externalDomain->getKey())
            ->assertJsonPath('data.2.ip', '2001:db8::12')
            ->assertJsonPath('data.2.domain_id', (string) $this->ownedDomain->getKey())
            ->assertJsonPath('data.2.domain', $this->ownedDomain->name);

        $this->assertFalse($this->server->fresh()->is_installed);
        $this->assertDatabaseCount('managed_server_ips', 0);
    }

    public function test_load_ips_silently_preflights_a_new_server_before_discovery(): void
    {
        $this->server->forceFill([
            'state' => 'pending_preflight',
            'expected_host_fingerprint' => null,
            'observed_host_fingerprint' => null,
        ])->save();

        $manager = Mockery::mock(InfrastructureManager::class);
        $manager->shouldReceive('verifyServerHost')
            ->once()
            ->withArgs(fn (Workspace $workspace, User $actor, ManagedServer $server): bool => $workspace->is($this->workspace)
                && $actor->is($this->owner)
                && $server->is($this->server))
            ->andReturnUsing(function (Workspace $workspace, User $actor, ManagedServer $server): ManagedServer {
                $server->forceFill([
                    'state' => 'verified',
                    'expected_host_fingerprint' => 'SHA256:captured-internally',
                    'observed_host_fingerprint' => 'SHA256:captured-internally',
                ]);

                return $server;
            });
        $this->app->instance(InfrastructureManager::class, $manager);

        $discovery = Mockery::mock(DmsBulkInstallRemoteIpDiscovery::class);
        $discovery->shouldReceive('discover')
            ->once()
            ->andReturn(['198.51.100.12']);
        $this->app->instance(DmsBulkInstallRemoteIpDiscovery::class, $discovery);

        $this->postJson($this->loadIpsUrl(), [
            'server_id' => $this->server->getKey(),
            'domain_ids' => [$this->externalDomain->getKey()],
            'with_ipv6' => false,
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ip', '198.51.100.12');
    }

    public function test_load_ips_allows_workspace_members_and_scopes_servers_and_domains(): void
    {
        $discovery = Mockery::mock(DmsBulkInstallRemoteIpDiscovery::class);
        $discovery->shouldReceive('discover')->once()->andReturn(['198.51.100.12']);
        $this->app->instance(DmsBulkInstallRemoteIpDiscovery::class, $discovery);

        $this->postJson($this->loadIpsUrl(), [
            'server_id' => (string) Str::uuid(),
            'domain_ids' => [$this->externalDomain->getKey()],
        ])->assertNotFound();

        $viewer = User::factory()->create(['username' => 'viewer']);
        $this->workspace->users()->attach($viewer->getKey(), ['role' => 'viewer']);
        $this->actingAs($viewer)
            ->postJson($this->loadIpsUrl(), [
                'server_id' => $this->server->getKey(),
                'domain_ids' => [$this->externalDomain->getKey()],
            ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ip', '198.51.100.12');

        $outsideMember = User::factory()->create(['username' => 'outside-member']);
        $this->actingAs($outsideMember)
            ->postJson($this->loadIpsUrl(), [
                'server_id' => $this->server->getKey(),
                'domain_ids' => [$this->externalDomain->getKey()],
            ])->assertNotFound();
    }

    public function test_configure_ips_uses_shared_dms_configurator_only_for_active_uninstalled_servers(): void
    {
        $configurator = Mockery::mock(DmsReinstallIpConfigurator::class);
        $configurator->shouldReceive('configure')
            ->once()
            ->withArgs(fn (Workspace $workspace, ManagedServer $server, array $ips, bool $permanent, bool $requireInstalled): bool => $workspace->is($this->workspace)
                && $server->is($this->server)
                && $ips === ['198.51.100.20']
                && $permanent
                && ! $requireInstalled)
            ->andReturnNull();
        $configurator->shouldReceive('configure')
            ->once()
            ->withArgs(fn (Workspace $workspace, ManagedServer $server, array $ips, bool $permanent, bool $requireInstalled): bool => $workspace->is($this->workspace)
                && $server->is($this->server)
                && $ips === ['198.51.100.22']
                && ! $permanent
                && ! $requireInstalled)
            ->andReturnNull();
        $this->app->instance(DmsReinstallIpConfigurator::class, $configurator);

        $this->postJson($this->configIpsUrl(), [
            'ips' => ['198.51.100.20'],
            'permanent' => true,
        ])->assertOk()->assertJsonPath('data', 'Finish Settings Ips');

        $installedServer = ManagedServer::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'ALREADY_INSTALLED_NODE',
            'provider' => 'Synthetic provider',
            'ip_address' => '198.51.100.13',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('b', 64),
            'created_by' => $this->owner->getKey(),
        ]);

        $this->postJson($this->configIpsUrl((string) $installedServer->getKey()), [
            'ips' => ['198.51.100.21'],
        ])->assertNotFound();

        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $this->actingAs($operator)
            ->postJson($this->configIpsUrl(), ['ips' => ['198.51.100.22']])
            ->assertOk()->assertJsonPath('data', 'Finish Settings Ips');
    }

    public function test_single_install_configure_ips_runs_through_saved_credential_session_for_uninstalled_server(): void
    {
        $this->server->forceFill(['host_facts' => [
            'architecture' => 'x86_64',
            'os_pretty_name' => 'AlmaLinux 9.8 (Seafoam Ocelot)',
        ]])->save();

        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('exec')
            ->once()
            ->with("ip route | grep 'default' | awk -F ' ' '{print $5}'")
            ->ordered()
            ->andReturn('eth0');
        $session->shouldReceive('exec')
            ->once()
            ->with("ip address add '198.51.100.20'/32 dev 'eth0' && printf ALPHALINK_IP_CONFIGURED")
            ->ordered()
            ->andReturn('ALPHALINK_IP_CONFIGURED');

        $credentials = Mockery::mock(DmsInstallerServerCredentialResolver::class);
        $credentials->shouldReceive('resolve')->once()->withArgs(
            fn (ManagedServer $server): bool => $server->is($this->server),
        )->andReturn(['secret' => 'synthetic-install-credential', 'type' => 'password']);
        $this->app->instance(DmsInstallerServerCredentialResolver::class, $credentials);

        $sessions = Mockery::mock(DmsInstallerSshSessionFactory::class);
        $sessions->shouldReceive('create')->once()->withArgs(
            fn (ManagedServer $server, string $secret, string $type): bool => $server->is($this->server)
                && $secret === 'synthetic-install-credential'
                && $type === 'password',
        )->andReturn($session);
        $this->app->instance(DmsInstallerSshSessionFactory::class, $sessions);

        $this->postJson($this->configIpsUrl(), [
            'ips' => ['198.51.100.20'],
            'permanent' => false,
        ])->assertOk()->assertJsonPath('data', 'Finish Settings Ips');

        $this->assertFalse($this->server->fresh()->is_installed);
    }

    public function test_single_install_validates_every_domain_assignment_before_pmta_gate_or_worker(): void
    {
        $foreignOwner = User::factory()->create();
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign workspace',
            'slug' => 'foreign-single-install-workspace',
            'created_by' => $foreignOwner->getKey(),
        ]);
        $foreignWorkspace->users()->attach($foreignOwner->getKey(), ['role' => 'owner']);
        $foreignDomain = $this->createDomain('foreign.example.test', false, $foreignWorkspace);

        $payload = $this->installPayload([[
            'ip' => '198.51.100.12',
            'type' => 'ipv4',
            'main' => true,
            'sub' => '',
            'domain_id' => $foreignDomain->getKey(),
        ]], (string) $foreignDomain->getKey());

        $this->postJson($this->installUrl(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data.0.ip_assignments.0.domain_id']);

        $payload = $this->installPayload([[
            'ip' => '198.51.100.12',
            'type' => 'ipv4',
            'main' => true,
            'sub' => '',
            'domain_id' => $this->ownedDomain->getKey(),
        ]], (string) $this->ownedDomain->getKey());
        $this->postJson($this->installUrl(), $payload)
            ->assertStatus(503)
            ->assertJsonPath('code', 'pmta_deployment_not_ready');

        $this->assertFalse($this->server->fresh()->is_installed);
        $this->assertNull($this->server->fresh()->install_parameters);
        $this->assertDatabaseCount('managed_server_ips', 0);
    }

    public function test_single_install_execution_uses_an_independent_gate_from_bulk_install(): void
    {
        config([
            'infrastructure.single_install_execution_enabled' => false,
            'infrastructure.bulk_install_execution_enabled' => true,
        ]);
        $this->bindReadySingleInstallInputs();

        $coordinator = Mockery::mock(DmsBulkInstallRunProcessPoolCoordinator::class);
        $coordinator->shouldNotReceive('execute');
        $this->app->instance(DmsBulkInstallRunProcessPoolCoordinator::class, $coordinator);

        $this->postJson($this->installUrl(), $this->installPayload(
            [$this->assignment((string) $this->externalDomain->getKey())],
            (string) $this->externalDomain->getKey(),
            false,
        ))
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'single_install_runner_unavailable');

        $this->assertFalse($this->server->fresh()->is_installed);
        $this->assertDatabaseCount('managed_server_ips', 0);
    }

    public function test_single_install_can_run_when_bulk_execution_remains_disabled(): void
    {
        config([
            'infrastructure.single_install_execution_enabled' => true,
            'infrastructure.bulk_install_execution_enabled' => false,
        ]);
        $this->bindReadySingleInstallInputs();

        $runId = (string) Str::uuid();
        $transcripts = Mockery::mock(DmsBulkInstallTranscriptStore::class);
        $transcripts->shouldReceive('createRun')
            ->once()
            ->with((string) $this->workspace->getKey(), '1782155053000')
            ->andReturn($runId);
        $this->app->instance(DmsBulkInstallTranscriptStore::class, $transcripts);

        $coordinator = Mockery::mock(DmsBulkInstallRunProcessPoolCoordinator::class);
        $coordinator->shouldNotReceive('execute');
        $this->app->instance(DmsBulkInstallRunProcessPoolCoordinator::class, $coordinator);

        $transcripts->shouldReceive('writeRunPayload')
            ->once()
            ->withArgs(fn (string $workspaceId, string $actualRunId, array $runPayload): bool => $workspaceId === (string) $this->workspace->getKey()
                && $actualRunId === $runId
                && $runPayload['kind'] === 'single_install'
                && $runPayload['actor_id'] === (string) $this->owner->getKey()
                && count($runPayload['payload']['data']) === 1
                && isset($runPayload['payload']['data'][0]['ip_assignments']));

        $launcher = Mockery::mock(DmsInfrastructureRunLauncher::class);
        $launcher->shouldReceive('launch')
            ->once()
            ->with((string) $this->workspace->getKey(), $runId)
            ->andReturn(4242);
        $this->app->instance(DmsInfrastructureRunLauncher::class, $launcher);

        $this->postJson($this->installUrl(), $this->installPayload(
            [$this->assignment((string) $this->externalDomain->getKey())],
            (string) $this->externalDomain->getKey(),
            false,
        ))
            ->assertStatus(202)
            ->assertJsonPath('data', 'Installation queued.')
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('run_id', $runId);

        $this->assertFalse($this->server->fresh()->is_installed);
        $this->assertDatabaseCount('managed_server_ips', 0);
    }

    public function test_per_ip_assignments_survive_request_and_child_process_normalization(): void
    {
        $assignments = [[
            'ip' => '198.51.100.12',
            'type' => 'ipv4',
            'main' => true,
            'sub' => '',
            'domain_id' => (string) $this->externalDomain->getKey(),
        ]];
        $payload = app(DmsBulkInstallPayload::class)->validateAndNormalize(
            $this->installPayload($assignments, (string) $this->externalDomain->getKey()),
        );
        $this->assertSame($assignments, $payload['data'][0]['ip_assignments']);

        $encoded = app(DmsBulkInstallRowProcessPayload::class)->encode(
            (string) $this->workspace->getKey(),
            (string) $this->owner->getKey(),
            (string) $this->server->getKey(),
            (string) $this->externalDomain->getKey(),
            (string) Str::uuid(),
            [
                'pmta_version' => $payload['pmta_version'],
                'tracking' => $payload['tracking'],
                'proxy' => $payload['proxy'],
                'imap' => $payload['imap'],
                'spf' => $payload['spf'],
                'dkim' => $payload['dkim'],
                'dmarc' => $payload['dmarc'],
                'https' => $payload['https'],
            ],
            $payload['data'][0]['ip_assignments'],
        );

        $this->assertSame($assignments, app(DmsBulkInstallRowProcessPayload::class)->decode($encoded)['ip_assignments']);
    }

    private function createDomain(string $name, bool $isOur, ?Workspace $workspace = null): SendingDomain
    {
        $workspace ??= $this->workspace;

        return SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => $name,
            'state' => 'verified',
            'verification_token' => (string) Str::random(40),
            'created_by' => $workspace->created_by,
            'domains_provider_id' => $this->provider->getKey(),
            'status' => 'free',
            'is_our' => $isOur,
            'is_active' => true,
        ]);
    }

    /** @param list<array{ip: string, type: string, main: bool, sub: string, domain_id: string}> $assignments */
    private function installPayload(array $assignments, string $primaryDomainId, bool $tracking = true): array
    {
        return [
            'data' => [[
                'id' => $this->server->getKey(),
                'domain' => $primaryDomainId,
                'ip_assignments' => $assignments,
            ]],
            'key' => 1782155053000,
            'pmta_version' => 45,
            'tracking' => $tracking,
            'proxy' => false,
            'imap' => false,
            'spf' => false,
            'dkim' => false,
            'dmarc' => false,
            'https' => false,
        ];
    }

    /** @return array{ip: string, type: string, main: bool, sub: string, domain_id: string} */
    private function assignment(string $domainId): array
    {
        return [
            'ip' => '198.51.100.12',
            'type' => 'ipv4',
            'main' => true,
            'sub' => '',
            'domain_id' => $domainId,
        ];
    }

    private function bindReadySingleInstallInputs(): void
    {
        $pmtaGate = Mockery::mock(DmsPmtaDeploymentGate::class);
        $pmtaGate->shouldReceive('evaluate')
            ->once()
            ->andReturn([
                'installation_authorized' => true,
                'checks' => [],
                'blockers' => [],
                'asset_inventory' => [],
            ]);
        $this->app->instance(DmsPmtaDeploymentGate::class, $pmtaGate);

        $runtimeInputs = Mockery::mock(DmsBulkInstallRuntimeInputs::class);
        $runtimeInputs->shouldReceive('resolve')
            ->once()
            ->andReturn(['settings' => [], 'assets' => []]);
        $this->app->instance(DmsBulkInstallRuntimeInputs::class, $runtimeInputs);
    }

    private function url(): string
    {
        return '/infrastructure/install';
    }

    private function loadIpsUrl(): string
    {
        return $this->url().'/load-ips';
    }

    private function installUrl(): string
    {
        return '/infrastructure/install/runs';
    }

    private function configIpsUrl(?string $serverId = null): string
    {
        return $this->url().'/'.($serverId ?? $this->server->getKey()).'/config-ips';
    }
}
