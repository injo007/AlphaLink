<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ManagedServer;
use App\Models\ServerCredential;
use App\Models\ServerProvider;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsBulkInstallEligibility;
use App\Services\Infrastructure\SshHostProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DmsServerEditTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private ServerProvider $provider;

    private ManagedServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->owner = User::factory()->create(['username' => 'mailer01']);
        $this->workspace = Workspace::query()->create([
            'name' => 'Installer test workspace',
            'slug' => 'installer-test-workspace',
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->provider = ServerProvider::query()->create(['name' => 'Example provider']);
        $this->server = $this->workspace->managedServers()->create([
            'name' => 'ALMA9-PRIMARY',
            'provider' => $this->provider->name,
            'servers_provider_id' => $this->provider->getKey(),
            'ip_address' => '159.223.97.39',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'login_type' => 'password',
            'expire_at' => now()->addDays(30)->format('Y-m-d'),
            'is_active' => true,
            'is_installed' => false,
            'state' => 'verified',
            'expected_host_fingerprint' => 'SHA256:previous-pin',
            'observed_host_fingerprint' => 'SHA256:previous-pin',
            'last_probe_at' => now(),
            'host_facts' => ['os_id' => 'almalinux', 'os_version' => '9.8', 'architecture' => 'x86_64'],
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64),
            'created_by' => $this->owner->getKey(),
        ]);
        ServerCredential::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'credential_type' => 'password',
            'encrypted_secret' => Crypt::encryptString('test-only-server-secret'),
            'created_by' => $this->owner->getKey(),
            'rotated_at' => now(),
        ]);
        $this->actingAs($this->owner);
    }

    public function test_edit_form_suggests_the_dms_username_match_without_rendering_the_saved_secret_or_fingerprint(): void
    {
        $this->get($this->editUrl())
            ->assertOk()
            ->assertSee('EDIT SERVER')
            ->assertSee('DMS Bulk Install includes active, uninstalled servers')
            ->assertSee('ALMA9-PRIMARY_MAILER01')
            ->assertSee('USE SUGGESTED NAME')
            ->assertDontSee('test-only-server-secret')
            ->assertDontSee('SHA256:previous-pin')
            ->assertDontSee('fingerprint');
    }

    public function test_update_matches_dms_server_name_eligibility_and_preserves_the_encrypted_credential(): void
    {
        $secretBefore = $this->server->credential()->firstOrFail()->encrypted_secret;
        $payload = $this->updatePayload(['name' => 'ALMA9-PRIMARY_MAILER01']);

        $response = $this->putJson($this->serverUrl(), $payload)
            ->assertOk()
            ->assertJsonPath('data', 'Server updated');

        $this->assertStringNotContainsString('test-only-server-secret', $response->getContent());
        $this->server->refresh();
        $this->assertSame('ALMA9-PRIMARY_MAILER01', $this->server->name);
        $this->assertSame('verified', $this->server->state);
        $this->assertSame($secretBefore, $this->server->credential()->firstOrFail()->encrypted_secret);

        $eligible = app(DmsBulkInstallEligibility::class)->for($this->workspace);
        $this->assertSame([$this->server->getKey()], $eligible['servers']->modelKeys());
        $event = AuditEvent::query()->where('action', 'server.updated')->firstOrFail();
        $this->assertSame(['name'], $event->properties['changed_fields']);
        $this->assertFalse($event->properties['credential_rotated']);
        $this->assertFalse($event->properties['preflight_invalidated']);
    }

    public function test_connection_changes_rotate_credential_and_invalidate_old_preflight_evidence(): void
    {
        $response = $this->putJson($this->serverUrl(), $this->updatePayload([
            'ip' => '159.223.97.40',
            'password' => 'replacement-server-secret',
        ]))->assertOk();

        $this->assertStringNotContainsString('replacement-server-secret', $response->getContent());
        $this->server->refresh();
        $this->assertSame('159.223.97.40', $this->server->ip_address);
        $this->assertSame('pending_preflight', $this->server->state);
        $this->assertNull($this->server->expected_host_fingerprint);
        $this->assertNull($this->server->observed_host_fingerprint);
        $this->assertNull($this->server->last_probe_at);
        $this->assertNull($this->server->host_facts);
        $this->assertSame('replacement-server-secret', Crypt::decryptString($this->server->credential()->firstOrFail()->encrypted_secret));
    }

    public function test_connection_test_uses_saved_credential_without_exposing_or_requesting_a_fingerprint(): void
    {
        $probe = Mockery::mock(SshHostProbe::class);
        $probe->shouldReceive('testConnection')
            ->once()
            ->with('159.223.97.39', 22, 'root', 'password', 'test-only-server-secret')
            ->andReturn([
                'ip' => '159.223.97.39',
                'status' => 'connected',
                'message' => 'CONNECTED ON IP: 159.223.97.39 PORT : 22',
            ]);
        $this->app->instance(SshHostProbe::class, $probe);

        $this->postJson($this->serverUrl().'/test-connection', [
            'ip' => '159.223.97.39',
            'port' => 22,
            'username' => 'root',
            'login_type' => 'password',
            'password' => null,
        ])->assertOk()
            ->assertJsonPath('results.0.status', 'connected')
            ->assertJsonMissingPath('fingerprint')
            ->assertDontSee('test-only-server-secret')
            ->assertDontSee('fingerprint');
    }

    public function test_blank_credential_cannot_change_the_saved_credential_type(): void
    {
        $this->putJson($this->serverUrl(), $this->updatePayload(['login_type' => 'ssh_key', 'password' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->assertSame('password', $this->server->fresh()->login_type);
        $this->assertSame(0, AuditEvent::query()->where('action', 'server.updated')->count());
    }

    public function test_every_workspace_member_can_edit_a_server(): void
    {
        $member = User::factory()->create(['username' => 'privacy-officer']);
        $this->workspace->users()->attach($member->getKey(), ['role' => 'privacy_officer']);

        $this->actingAs($member)->get($this->editUrl())->assertOk();
        $this->actingAs($member)->putJson($this->serverUrl(), $this->updatePayload(['name' => 'MEMBER UPDATED SERVER']))
            ->assertOk()
            ->assertJsonPath('data', 'Server updated');
        $this->assertSame('MEMBER UPDATED SERVER', $this->server->fresh()->name);
    }

    private function editUrl(): string
    {
        return '/app/workspaces/'.$this->workspace->getKey().'/infrastructure/servers/'.$this->server->getKey().'/edit';
    }

    private function serverUrl(): string
    {
        return '/app/workspaces/'.$this->workspace->getKey().'/infrastructure/servers/'.$this->server->getKey();
    }

    /** @return array<string, mixed> */
    private function updatePayload(array $overrides = []): array
    {
        return array_replace([
            'servers_provider_id' => $this->provider->getKey(),
            'name' => $this->server->name,
            'ip' => $this->server->ip_address,
            'port' => $this->server->ssh_port,
            'username' => $this->server->ssh_username,
            'login_type' => $this->server->login_type,
            'password' => null,
            'expire_at' => $this->server->expire_at->format('Y-m-d'),
        ], $overrides);
    }
}
