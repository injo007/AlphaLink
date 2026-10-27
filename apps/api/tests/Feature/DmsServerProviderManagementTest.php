<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ManagedServer;
use App\Models\ServerProvider;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsServerProviderManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->owner = User::factory()->create();
        $this->workspace = $this->makeWorkspace($this->owner, 'server-provider-main');
        $this->actingAs($this->owner);
    }

    public function test_index_matches_dms_columns_and_scopes_workspace_rows_and_server_counts(): void
    {
        $default = $this->provider(null, 'Digital');
        $local = $this->provider($this->workspace, 'Synthetic host', 'https://provider.example', 'demo-user', 'synthetic-password');
        $foreignWorkspace = $this->makeWorkspace(User::factory()->create(), 'server-provider-other');
        $foreign = $this->provider($foreignWorkspace, 'Foreign provider', 'https://foreign.example');
        $this->server($this->workspace, $local, '192.0.2.10');
        $this->server($foreignWorkspace, $foreign, '192.0.2.11');

        $page = route('console.infrastructure.server-providers.index', $this->workspace->getKey());
        $this->get($page)->assertOk()
            ->assertSee('<title>Servers Providers</title>', false)
            ->assertSee('data-action="fullscreen_toggle"', false)
            ->assertSee('data-create-url=', false)
            ->assertSee('data-edit-url-template=', false)
            ->assertSee('/dms-reference/plugins/datatables/datatables.min.js', false);

        $response = $this->getJson($page)->assertOk()->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', $default->getKey())
            ->assertJsonPath('data.0.can_manage', false)
            ->assertJsonPath('data.0.servers_count', 0)
            ->assertJsonPath('data.1.id', $local->getKey())
            ->assertJsonPath('data.1.username', 'demo-user')
            ->assertJsonPath('data.1.password', '••••••••')
            ->assertJsonPath('data.1.servers_count', 1)
            ->assertJsonMissing(['name' => 'Foreign provider'])
            ->assertJsonMissingPath('data.1.workspace_id');
        $this->assertStringNotContainsString('synthetic-password', $response->getContent());
    }

    public function test_create_encrypts_password_and_records_only_non_secret_audit_properties(): void
    {
        $secret = 'synthetic-provider-secret';
        $response = $this->postJson(route('console.infrastructure.server-providers.store', $this->workspace->getKey()), [
            'name' => 'Example Host',
            'url' => 'https://host.example/login',
            'username' => 'synthetic-user',
            'password' => $secret,
        ])->assertOk()->assertExactJson(['data' => 'Provider created']);

        $provider = ServerProvider::query()->where('name', 'Example Host')->firstOrFail();
        $this->assertSame($this->workspace->getKey(), $provider->workspace_id);
        $this->assertSame($secret, $provider->password);
        $this->assertNotSame($secret, $provider->getRawOriginal('password'));
        $this->assertArrayNotHasKey('password', $provider->toArray());
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertDatabaseHas('audit_events', [
            'action' => 'server_provider.created',
            'subject_id' => (string) $provider->getKey(),
        ]);
        $this->assertStringNotContainsString($secret, AuditEvent::query()->get()->toJson());

        $this->getJson(route('console.infrastructure.server-providers.index', $this->workspace->getKey()))
            ->assertJsonPath('data.0.password', '••••••••');
    }

    public function test_create_validation_matches_dms_minimums_and_rejects_invalid_provider_links(): void
    {
        $this->postJson(route('console.infrastructure.server-providers.store', $this->workspace->getKey()), [
            'name' => 'A',
            'url' => 'not-a-url',
            'username' => 'usr',
            'password' => '123',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'url', 'username', 'password']);
    }

    public function test_edit_masks_saved_password_blank_preserves_it_and_new_password_rotates_it(): void
    {
        $provider = $this->provider($this->workspace, 'Example Host', 'https://host.example', 'synthetic-user', 'original-secret');
        $base = route('console.infrastructure.server-providers.index', $this->workspace->getKey());

        $this->get($base.'/'.$provider->getKey().'/edit')->assertOk()
            ->assertSee('EDIT PROVIDER', false)
            ->assertSee('Leave empty to keep saved password')
            ->assertDontSee('original-secret');

        $this->putJson($base.'/'.$provider->getKey(), [
            'name' => 'Updated Host',
            'url' => 'https://host.example/account',
            'username' => 'synthetic-user',
            'password' => '',
        ])->assertOk()->assertExactJson(['data' => 'Provider updated']);
        $this->assertSame('Updated Host', $provider->fresh()->name);
        $this->assertSame('original-secret', $provider->fresh()->password);

        $rotated = 'synthetic-rotated-secret';
        $this->putJson($base.'/'.$provider->getKey(), [
            'name' => 'Updated Host',
            'url' => 'https://host.example/account',
            'username' => 'synthetic-user',
            'password' => $rotated,
        ])->assertOk();
        $this->assertSame($rotated, $provider->fresh()->password);
        $this->assertStringNotContainsString($rotated, AuditEvent::query()->get()->toJson());
    }

    public function test_update_keeps_dms_four_character_name_minimum(): void
    {
        $provider = $this->provider($this->workspace, 'Example Host', 'https://host.example', 'synthetic-user', 'synthetic-password');
        $this->putJson(route('console.infrastructure.server-providers.update', [$this->workspace->getKey(), $provider->getKey()]), [
            'name' => 'abc',
            'url' => 'https://host.example',
            'username' => 'synthetic-user',
            'password' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->assertSame('Example Host', $provider->fresh()->name);
    }

    public function test_delete_soft_deletes_only_the_provider_and_keeps_linked_server(): void
    {
        $provider = $this->provider($this->workspace, 'Example Host', 'https://host.example', 'synthetic-user', 'synthetic-password');
        $server = $this->server($this->workspace, $provider, '192.0.2.12');

        $this->deleteJson(route('console.infrastructure.server-providers.destroy', [$this->workspace->getKey(), $provider->getKey()]))
            ->assertOk()->assertExactJson(['data' => 'Provider deleted']);

        $this->assertSoftDeleted('server_providers', ['id' => $provider->getKey()]);
        $this->assertDatabaseHas('managed_servers', ['id' => $server->getKey(), 'servers_provider_id' => $provider->getKey()]);
        $this->assertNull($server->fresh()->serverProvider);
        $this->getJson(route('console.infrastructure.server-providers.index', $this->workspace->getKey()))
            ->assertJsonMissing(['id' => $provider->getKey()]);
    }

    public function test_shared_and_foreign_providers_cannot_be_edited_or_deleted_by_workspace_users(): void
    {
        $shared = $this->provider(null, 'Digital');
        $foreignWorkspace = $this->makeWorkspace(User::factory()->create(), 'server-provider-foreign');
        $foreign = $this->provider($foreignWorkspace, 'Foreign Host', 'https://foreign.example');
        $base = route('console.infrastructure.server-providers.index', $this->workspace->getKey());

        $this->get($base.'/'.$shared->getKey().'/edit')->assertNotFound();
        $this->putJson($base.'/'.$shared->getKey(), [])->assertNotFound();
        $this->deleteJson($base.'/'.$shared->getKey())->assertNotFound();
        $this->get($base.'/'.$foreign->getKey().'/edit')->assertNotFound();
        $this->putJson($base.'/'.$foreign->getKey(), [])->assertNotFound();
        $this->deleteJson($base.'/'.$foreign->getKey())->assertNotFound();
        $this->assertFalse($foreign->fresh()->trashed());
    }

    public function test_provider_filter_applies_to_alpha_link_server_table(): void
    {
        $first = $this->provider($this->workspace, 'First Host', 'https://first.example');
        $second = $this->provider($this->workspace, 'Second Host', 'https://second.example');
        $firstServer = $this->server($this->workspace, $first, '192.0.2.20');
        $this->server($this->workspace, $second, '192.0.2.21');

        $this->getJson(route('console.infrastructure.servers.index', [
            'workspace' => $this->workspace->getKey(),
            'provider_id' => $first->getKey(),
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $firstServer->getKey());

        $this->get(route('console.infrastructure.servers.index', [
            'workspace' => $this->workspace->getKey(),
            'provider_id' => $first->getKey(),
        ]))->assertOk()->assertSee('provider_id='.$first->getKey());
    }

    public function test_workspace_provider_can_be_assigned_to_server_but_foreign_provider_is_rejected(): void
    {
        $local = $this->provider($this->workspace, 'Example Host', 'https://host.example');
        $foreignWorkspace = $this->makeWorkspace(User::factory()->create(), 'server-provider-assignment-foreign');
        $foreign = $this->provider($foreignWorkspace, 'Foreign Host', 'https://foreign.example');
        $url = route('console.infrastructure.servers.store', $this->workspace->getKey());
        $payload = [
            'servers_provider_id' => $local->getKey(),
            'name' => 'Example Server',
            'ips' => ['192.0.2.30'],
            'port' => 22,
            'username' => 'root',
            'login_type' => 'password',
            'password' => 'synthetic-ssh-password',
            'expire_at' => now()->addDays(30)->format('Y-m-d'),
        ];

        $this->postJson($url, $payload)->assertOk()->assertJsonPath('added', 1);
        $this->assertDatabaseHas('managed_servers', [
            'ip_address' => '192.0.2.30',
            'servers_provider_id' => $local->getKey(),
        ]);

        $this->postJson($url, [...$payload, 'ips' => ['192.0.2.31'], 'servers_provider_id' => $foreign->getKey()])
            ->assertUnprocessable()->assertJsonValidationErrors(['servers_provider_id']);
        $this->assertDatabaseMissing('managed_servers', ['ip_address' => '192.0.2.31']);
    }

    private function provider(?Workspace $workspace, string $name, ?string $url = null, ?string $username = null, ?string $password = null): ServerProvider
    {
        return ServerProvider::query()->create([
            'workspace_id' => $workspace?->getKey(),
            'name' => $name,
            'url' => $url,
            'username' => $username,
            'password' => $password,
        ]);
    }

    private function server(Workspace $workspace, ServerProvider $provider, string $ip): ManagedServer
    {
        return $workspace->managedServers()->create([
            'name' => 'TEST SERVER',
            'provider' => $provider->name,
            'servers_provider_id' => $provider->getKey(),
            'ip_address' => $ip,
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64),
            'created_by' => $this->owner->getKey(),
        ]);
    }

    private function makeWorkspace(User $owner, string $slug): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Workspace '.Str::headline($slug),
            'slug' => $slug,
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);

        return $workspace;
    }
}
