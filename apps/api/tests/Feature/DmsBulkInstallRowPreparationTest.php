<?php

namespace Tests\Feature;

use App\Models\DomainProvider;
use App\Models\Isp;
use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\ServerCredential;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsBulkInstallRemoteIpDiscovery;
use App\Services\Infrastructure\DmsBulkInstallRowPreparation;
use App\Services\Infrastructure\DmsInstallerServerCredentialResolver;
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
        Isp::query()->create(['workspace_id' => $this->workspace->getKey(), 'name' => 'Gmail', 'is_active' => false]);
        Isp::query()->create(['workspace_id' => $this->workspace->getKey(), 'name' => 'YAHOO', 'is_active' => true]);
        Isp::query()->create(['workspace_id' => $this->workspace->getKey(), 'name' => 'gmail', 'is_active' => true]);

        $credential = ServerCredential::query()->create([
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

        $this->assertSame($this->workspace->getKey(), $prepared['workspace']->getKey());
        $this->assertSame($this->server->getKey(), $prepared['server']->getKey());
        $this->assertSame($this->domain->getKey(), $prepared['domain']->getKey());
        $this->assertSame(['198.51.100.12', '2001:db8::12'], $prepared['ips']);
        $this->assertSame(['gmail', 'yahoo'], $prepared['isps']);
        $this->assertSame('SHA256:internal-test-pin', $prepared['server']->expected_host_fingerprint);
        $this->assertArrayNotHasKey('credential', $prepared);
        $this->assertArrayNotHasKey('secret', $prepared);
    }

    public function test_credential_resolver_decrypts_workspace_scoped_password_and_ssh_key(): void
    {
        $credential = ServerCredential::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'credential_type' => 'password',
            'encrypted_secret' => Crypt::encryptString('synthetic-password'),
            'created_by' => $this->user->getKey(),
            'rotated_at' => now(),
        ]);

        $resolver = app(DmsInstallerServerCredentialResolver::class);
        $password = $resolver->resolve($this->server);
        $this->assertSame(['secret' => 'synthetic-password', 'type' => 'password'], $password);

        $credential->forceFill([
            'credential_type' => 'ssh_key',
            'encrypted_secret' => Crypt::encryptString('synthetic-private-key'),
        ])->save();

        $privateKey = $resolver->resolve($this->server);
        $this->assertSame(['secret' => 'synthetic-private-key', 'type' => 'ssh_key'], $privateKey);
    }

    public function test_credential_resolver_sanitizes_corrupt_and_unsupported_credential_data(): void
    {
        $credential = ServerCredential::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'credential_type' => 'password',
            'encrypted_secret' => 'not-encrypted',
            'created_by' => $this->user->getKey(),
            'rotated_at' => now(),
        ]);

        try {
            app(DmsInstallerServerCredentialResolver::class)->resolve($this->server);
            $this->fail('Corrupt ciphertext must not be returned.');
        } catch (HostProbeException $exception) {
            $this->assertSame('The stored SSH credential could not be decrypted.', $exception->getMessage());
            $this->assertStringNotContainsString('not-encrypted', $exception->getMessage());
        }

        $credential->forceFill([
            'credential_type' => 'private_key',
            'encrypted_secret' => Crypt::encryptString('synthetic-private-key'),
        ])->save();

        try {
            app(DmsInstallerServerCredentialResolver::class)->resolve($this->server);
            $this->fail('Unknown credential types must be rejected.');
        } catch (HostProbeException $exception) {
            $this->assertSame('The stored SSH credential type is unsupported.', $exception->getMessage());
        }
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

    public function test_it_resolves_active_uninstalled_servers_without_a_username_suffix(): void
    {
        $this->user->forceFill(['username' => null])->save();
        $this->server->update(['name' => 'OTHER_RELAY']);
        $secret = 'synthetic-password';
        ServerCredential::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'credential_type' => 'password',
            'encrypted_secret' => Crypt::encryptString($secret),
            'created_by' => $this->user->getKey(),
            'rotated_at' => now(),
        ]);
        $discovery = Mockery::mock(DmsBulkInstallRemoteIpDiscovery::class);
        $discovery->shouldReceive('discover')
            ->once()
            ->with(Mockery::on(fn (ManagedServer $server): bool => $server->is($this->server)), $secret, 'password')
            ->andReturn(['198.51.100.12']);
        $this->app->instance(DmsBulkInstallRemoteIpDiscovery::class, $discovery);

        $prepared = app(DmsBulkInstallRowPreparation::class)->prepare(
            $this->user,
            $this->workspace,
            (string) $this->server->getKey(),
            (string) $this->domain->getKey(),
        );

        $this->assertSame($this->server->getKey(), $prepared['server']->getKey());
        $this->assertSame(['198.51.100.12'], $prepared['ips']);
    }

    public function test_it_allows_a_viewer_workspace_member_to_prepare_a_server_row(): void
    {
        $viewer = User::factory()->create(['username' => 'viewer']);
        $this->workspace->users()->attach($viewer->getKey(), ['role' => 'viewer']);
        $secret = 'viewer-access-synthetic-password';
        ServerCredential::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'credential_type' => 'password',
            'encrypted_secret' => Crypt::encryptString($secret),
            'created_by' => $this->user->getKey(),
            'rotated_at' => now(),
        ]);
        $discovery = Mockery::mock(DmsBulkInstallRemoteIpDiscovery::class);
        $discovery->shouldReceive('discover')
            ->once()
            ->with(Mockery::on(fn (ManagedServer $server): bool => $server->is($this->server)), $secret, 'password')
            ->andReturn(['198.51.100.18']);
        $this->app->instance(DmsBulkInstallRemoteIpDiscovery::class, $discovery);

        $prepared = app(DmsBulkInstallRowPreparation::class)->prepare(
            $viewer,
            $this->workspace,
            (string) $this->server->getKey(),
            (string) $this->domain->getKey(),
        );

        $this->assertSame($this->server->getKey(), $prepared['server']->getKey());
        $this->assertSame(['198.51.100.18'], $prepared['ips']);
    }

    public function test_it_rejects_nonmembers_before_discovery(): void
    {
        $nonmember = User::factory()->create(['username' => 'outsider']);
        $this->app->instance(DmsBulkInstallRemoteIpDiscovery::class, Mockery::mock(DmsBulkInstallRemoteIpDiscovery::class)->shouldNotReceive('discover')->getMock());

        $this->expectException(ModelNotFoundException::class);
        app(DmsBulkInstallRowPreparation::class)->prepare(
            $nonmember,
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
