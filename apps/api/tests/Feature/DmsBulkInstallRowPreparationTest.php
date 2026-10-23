<?php

namespace Tests\Feature;

use App\Models\DomainProvider;
use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\ServerCredential;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsBulkInstallRemoteIpDiscovery;
use App\Services\Infrastructure\DmsBulkInstallRowPreparation;
use App\Services\Infrastructure\HostProbeException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DmsBulkInstallRowPreparationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private ManagedServer $server;

    private SendingDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['username' => 'mailer']);
        $this->workspace = Workspace::query()->create([
            'name' => 'Install test',
            'slug' => 'install-test',
            'created_by' => $this->user->getKey(),
        ]);
        $this->workspace->users()->attach($this->user->getKey(), ['role' => 'owner']);

        $provider = DomainProvider::query()->create(['name' => 'DMS provider', 'is_active' => true]);
        $this->server = ManagedServer::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'ALPHA_MAILER_RELAY',
            'provider' => 'Synthetic provider',
            'ip_address' => '198.51.100.12',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'expected_host_fingerprint' => 'SHA256:internal-test-pin',
            'observed_host_fingerprint' => 'SHA256:internal-test-pin',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => false,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64),
            'created_by' => $this->user->getKey(),
        ]);
        $this->domain = SendingDomain::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'example.net',
            'state' => 'verified',
            'verification_token' => 'synthetic-verification-token',
            'created_by' => $this->user->getKey(),
            'domains_provider_id' => $provider->getKey(),
            'status' => 'free',
            'is_our' => false,
            'is_active' => true,
        ]);
    }

    public function test_it_resolves_dms_eligible_rows_and_uses_the_saved_credential_for_read_only_ip_discovery(): void
    {
        ServerCredential::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'credential_type' => 'password',
            'encrypted_secret' => Crypt::encryptString('synthetic-password'),
            'created_by' => $this->user->getKey(),
            'rotated_at' => now(),
        ]);

        $discovery = Mockery::mock(DmsBulkInstallRemoteIpDiscovery::class);
        $discovery->shouldReceive('discover')
            ->once()
            ->with(Mockery::on(fn (ManagedServer $server): bool => $server->is($this->server)), 'synthetic-password', 'password')
            ->andReturn(['198.51.100.12', '2001:db8::12']);
        $this->app->instance(DmsBulkInstallRemoteIpDiscovery::class, $discovery);

        $prepared = app(DmsBulkInstallRowPreparation::class)->prepare(
            $this->user,
            $this->workspace,
            (string) $this->server->getKey(),
            (string) $this->domain->getKey(),
        );

        $this->assertSame($this->server->getKey(), $prepared['server']->getKey());
        $this->assertSame($this->domain->getKey(), $prepared['domain']->getKey());
        $this->assertSame(['198.51.100.12', '2001:db8::12'], $prepared['ips']);
        $this->assertSame('SHA256:internal-test-pin', $prepared['server']->expected_host_fingerprint);
    }

    public function test_it_rejects_a_server_without_completed_read_only_preflight_before_discovery(): void
    {
        $this->server->update(['state' => 'pending_preflight', 'expected_host_fingerprint' => null]);
        $this->app->instance(DmsBulkInstallRemoteIpDiscovery::class, Mockery::mock(DmsBulkInstallRemoteIpDiscovery::class)->shouldNotReceive('discover')->getMock());

        $this->expectException(HostProbeException::class);
        $this->expectExceptionMessage('Verify the server host before installation.');
        app(DmsBulkInstallRowPreparation::class)->prepare(
            $this->user,
            $this->workspace,
            (string) $this->server->getKey(),
            (string) $this->domain->getKey(),
        );
    }

    public function test_it_does_not_resolve_servers_for_a_user_without_the_dms_username_mapping(): void
    {
        $this->user->forceFill(['username' => null])->save();
        $this->app->instance(DmsBulkInstallRemoteIpDiscovery::class, Mockery::mock(DmsBulkInstallRemoteIpDiscovery::class)->shouldNotReceive('discover')->getMock());

        $this->expectException(ModelNotFoundException::class);
        app(DmsBulkInstallRowPreparation::class)->prepare(
            $this->user,
            $this->workspace,
            (string) $this->server->getKey(),
            (string) $this->domain->getKey(),
        );
    }

    public function test_it_rejects_non_owner_workspace_members_before_discovery(): void
    {
        $otherUser = User::factory()->create(['username' => 'othermailer']);
        $this->app->instance(DmsBulkInstallRemoteIpDiscovery::class, Mockery::mock(DmsBulkInstallRemoteIpDiscovery::class)->shouldNotReceive('discover')->getMock());

        $this->expectException(ModelNotFoundException::class);
        app(DmsBulkInstallRowPreparation::class)->prepare(
            $otherUser,
            $this->workspace,
            (string) $this->server->getKey(),
            (string) $this->domain->getKey(),
        );
    }

    public function test_it_requires_a_workspace_scoped_saved_credential_before_discovery(): void
    {
        $this->app->instance(DmsBulkInstallRemoteIpDiscovery::class, Mockery::mock(DmsBulkInstallRemoteIpDiscovery::class)->shouldNotReceive('discover')->getMock());

        $this->expectException(HostProbeException::class);
        $this->expectExceptionMessage('The stored SSH credential is missing.');
        app(DmsBulkInstallRowPreparation::class)->prepare(
            $this->user,
            $this->workspace,
            (string) $this->server->getKey(),
            (string) $this->domain->getKey(),
        );
    }
}
