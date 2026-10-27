<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DomainProvider;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DmsDomainProviderRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = $this->workspaceFor($this->owner, 'provider-one');
    }

    public function test_owner_can_create_dms_domain_provider_with_encrypted_credentials(): void
    {
        $payload = [
            'name' => 'Example Registrar',
            'type' => 'godaddy',
            'username' => 'synthetic-user',
            'password' => 'synthetic-password',
            'api_key' => 'synthetic-api-key',
            'api_secret' => 'synthetic-api-secret',
        ];

        $this->actingAs($this->owner)->postJson($this->providerRoute(), $payload)
            ->assertOk()
            ->assertExactJson(['data' => 'Provider created']);

        $provider = DomainProvider::query()->firstOrFail();
        $this->assertSame($this->workspace->getKey(), $provider->workspace_id);
        $this->assertSame('godaddy', $provider->type);
        $this->assertSame('synthetic-api-key', $provider->api_key);
        $this->assertSame('synthetic-api-secret', $provider->api_secret);
        $this->assertSame('synthetic-password', $provider->password);
        $this->assertNotSame('synthetic-password', $provider->getRawOriginal('password'));
        $this->assertNotSame('synthetic-api-key', $provider->getRawOriginal('api_key'));
        $this->assertNotSame('synthetic-api-secret', $provider->getRawOriginal('api_secret'));
        $this->assertArrayNotHasKey('api_key', $provider->toArray());
        $this->assertArrayNotHasKey('api_secret', $provider->toArray());
        $this->assertArrayNotHasKey('password', $provider->toArray());

        $audit = AuditEvent::query()->where('action', 'domain_provider.created')->firstOrFail();
        $this->assertSame(['type' => 'godaddy'], $audit->properties);
    }

    public function test_owner_can_save_a_cloudflare_token_without_registrar_credentials(): void
    {
        $this->actingAs($this->owner)->postJson($this->providerRoute(), [
            'name' => 'Cloudflare account',
            'type' => 'cloudflare',
            'api_key' => 'synthetic-cloudflare-token',
        ])->assertOk()->assertExactJson(['data' => 'Provider created']);

        $provider = DomainProvider::query()->sole();
        $this->assertSame('cloudflare', $provider->type);
        $this->assertSame('synthetic-cloudflare-token', $provider->api_key);
        $this->assertNull($provider->username);
        $this->assertNull($provider->api_secret);
        $this->assertNotSame('synthetic-cloudflare-token', $provider->getRawOriginal('api_key'));
        $this->assertArrayNotHasKey('api_key', $provider->toArray());

        $this->get('/infrastructure/domain-providers/'.$provider->getKey().'/edit')->assertOk()
            ->assertSee('cloudflare')
            ->assertSee('Zone Read, DNS Read, and DNS Write permissions')
            ->assertDontSee('synthetic-cloudflare-token');

        $update = route('console.infrastructure.domain-providers.update', [$this->workspace->getKey(), $provider->getKey()]);
        $this->putJson($update, [
            'name' => 'Cloudflare account', 'type' => 'cloudflare', 'username' => '',
            'api_key' => '', 'password' => '', 'api_secret' => '',
        ])->assertOk()->assertExactJson(['data' => 'Provider updated']);
        $this->assertSame('synthetic-cloudflare-token', $provider->fresh()->api_key);
    }

    public function test_namecheap_connection_button_checks_domain_list_access_without_saving_the_credentials(): void
    {
        Http::fake([
            'https://api.namecheap.com/xml.response' => Http::response(
                '<ApiResponse Status="OK"><CommandResponse><DomainGetListResult/></CommandResponse></ApiResponse>',
            ),
        ]);

        $this->actingAs($this->owner)->postJson(
            route('console.infrastructure.domain-providers.test-new-connection', $this->workspace->getKey()),
            ['type' => 'namecheap', 'username' => 'synthetic-user', 'api_key' => 'synthetic-namecheap-key'],
        )->assertOk()->assertExactJson([
            'success' => true,
            'message' => 'Connected to Namecheap. Domain-list access is available.',
        ]);

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.namecheap.com/xml.response'
            && $request->data()['Command'] === 'namecheap.domains.getList'
            && (string) $request->data()['PageSize'] === '10'
            && isset($request->data()['ClientIP']));
        $this->assertSame(0, DomainProvider::query()->count());
        $this->assertSame(0, SendingDomain::query()->count());
    }

    public function test_godaddy_connection_uses_saved_credentials_and_limits_the_read_to_one_domain(): void
    {
        $provider = DomainProvider::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'GoDaddy Account',
            'type' => 'godaddy',
            'username' => 'synthetic-user',
            'api_key' => 'synthetic-godaddy-key',
            'api_secret' => 'synthetic-godaddy-secret',
            'is_active' => true,
        ]);
        Http::fake(['https://api.godaddy.com/v1/domains*' => Http::response([])]);

        $this->actingAs($this->owner)->postJson(
            route('console.infrastructure.domain-providers.test-existing-connection', [$this->workspace->getKey(), $provider->getKey()]),
            ['type' => 'godaddy', 'username' => 'synthetic-user', 'api_key' => '', 'api_secret' => ''],
        )->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Connected to GoDaddy. Domain-list access is available.');

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.godaddy.com/v1/domains?')
            && str_contains($request->url(), 'limit=1')
            && $request->hasHeader('Authorization', 'sso-key synthetic-godaddy-key:synthetic-godaddy-secret'));
        $this->assertSame(1, DomainProvider::query()->count());
        $this->assertSame('synthetic-godaddy-key', $provider->fresh()->api_key);
    }

    public function test_godaddy_http_success_with_an_error_payload_is_not_reported_as_connected(): void
    {
        Http::fake([
            'https://api.godaddy.com/v1/domains*' => Http::response(['message' => 'synthetic invalid key detail']),
        ]);

        $this->actingAs($this->owner)->postJson(
            route('console.infrastructure.domain-providers.test-new-connection', $this->workspace->getKey()),
            ['type' => 'godaddy', 'api_key' => 'synthetic-godaddy-key', 'api_secret' => 'synthetic-godaddy-secret'],
        )->assertOk()->assertJsonPath('success', false)
            ->assertJsonPath('message', 'GoDaddy rejected the API request. Check the API key, secret, and account access.')
            ->assertJsonMissing(['message' => 'synthetic invalid key detail']);

        Http::assertSentCount(1);
        $this->assertSame(0, DomainProvider::query()->count());
    }

    public function test_cloudflare_connection_verifies_the_token_and_reads_a_single_zone_without_dns_writes(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response([
                'success' => true,
                'result' => ['status' => 'active'],
            ]),
            'https://api.cloudflare.com/client/v4/zones*' => Http::response([
                'success' => true,
                'result' => [],
                'result_info' => ['total_pages' => 0],
            ]),
        ]);

        $this->actingAs($this->owner)->postJson(
            route('console.infrastructure.domain-providers.test-new-connection', $this->workspace->getKey()),
            ['type' => 'cloudflare', 'api_key' => 'synthetic-cloudflare-token'],
        )->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Connected to Cloudflare. The token is active and can read zones. DNS write permission was not tested.');

        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/user/tokens/verify')
            && $request->hasHeader('Authorization', 'Bearer synthetic-cloudflare-token'));
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/zones?')
            && str_contains($request->url(), 'per_page=1'));
        $this->assertSame(0, DomainProvider::query()->count());
        $this->assertSame(0, SendingDomain::query()->count());
    }

    public function test_domain_provider_connection_failures_hide_provider_bodies_and_respect_workspace_roles(): void
    {
        Http::fake([
            'https://api.godaddy.com/v1/domains*' => Http::response(['message' => 'synthetic-secret-provider-body'], 403),
        ]);
        $url = route('console.infrastructure.domain-providers.test-new-connection', $this->workspace->getKey());

        $this->actingAs($this->owner)->postJson($url, [
            'type' => 'godaddy', 'username' => 'synthetic-user', 'api_key' => 'synthetic-key', 'api_secret' => 'synthetic-secret',
        ])->assertOk()->assertJsonPath('success', false)->assertJsonMissing(['message' => 'synthetic-secret-provider-body'])
            ->assertJsonPath('message', 'GoDaddy rejected the API request. Check the API key, secret, and account access.');

        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $this->actingAs($operator)->postJson($url, ['type' => 'cloudflare', 'api_key' => 'synthetic-key'])->assertForbidden();
        $otherWorkspace = $this->workspaceFor(User::factory()->create(), 'provider-other-test');
        $this->actingAs($this->owner)->postJson(
            route('console.infrastructure.domain-providers.test-existing-connection', [$otherWorkspace->getKey(), 999]),
            ['type' => 'namecheap'],
        )->assertNotFound();
        $this->assertSame(0, DomainProvider::query()->count());
    }

    public function test_switching_provider_types_requires_a_new_cloudflare_token(): void
    {
        $provider = DomainProvider::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'name' => 'Namecheap account',
            'type' => 'namecheap', 'username' => 'synthetic-user', 'api_key' => 'synthetic-namecheap-key',
            'is_active' => true,
        ]);

        $this->actingAs($this->owner)->putJson(
            route('console.infrastructure.domain-providers.update', [$this->workspace->getKey(), $provider->getKey()]),
            ['name' => 'Cloudflare account', 'type' => 'cloudflare', 'username' => '', 'api_key' => ''],
        )->assertUnprocessable()->assertJsonValidationErrors(['api_key']);

        $this->assertSame('namecheap', $provider->fresh()->type);
    }

    public function test_provider_list_links_the_dms_add_form_and_returns_only_safe_workspace_rows(): void
    {
        $local = DomainProvider::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'Namecheap account',
            'type' => 'namecheap',
            'username' => 'synthetic-user',
            'api_key' => 'synthetic-api-key',
            'is_active' => true,
        ]);
        DomainProvider::query()->create([
            'workspace_id' => $this->workspaceFor(User::factory()->create(), 'provider-foreign')->getKey(),
            'name' => 'Foreign account',
            'type' => 'namecheap',
            'is_active' => true,
        ]);

        $url = '/infrastructure/domain-providers';
        $this->actingAs($this->owner)->get($url)->assertOk()
            ->assertSee('Domains Providers')
            ->assertSee('ADD NEW PROVIDER')
            ->assertSee('dms-reference/plugins/datatables/datatables.min.js')
            ->assertDontSee('/app/workspaces/'.$this->workspace->getKey().'/');
        $this->getJson($url)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $local->getKey())
            ->assertJsonPath('data.0.type', 'namecheap')
            ->assertJsonMissingPath('data.0.api_key')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissing(['name' => 'Foreign account']);
    }

    public function test_provider_edit_and_status_are_scoped_and_do_not_disclose_saved_secrets(): void
    {
        $provider = DomainProvider::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'Namecheap account',
            'type' => 'namecheap',
            'username' => 'synthetic-user',
            'api_key' => 'synthetic-api-key',
            'password' => 'synthetic-password',
            'is_active' => true,
        ]);
        $base = route('console.infrastructure.domain-providers.index', $this->workspace->getKey()).'/'.$provider->getKey();

        $this->actingAs($this->owner)->get('/infrastructure/domain-providers/'.$provider->getKey().'/edit')->assertOk()
            ->assertSee('EDIT PROVIDER')
            ->assertSee('TEST CONNECTION')
            ->assertDontSee('synthetic-api-key')
            ->assertDontSee('synthetic-password');
        $this->putJson($base, [
            'name' => 'Updated Namecheap', 'type' => 'namecheap',
            'username' => 'synthetic-user', 'api_key' => '',
            'password' => '', 'api_secret' => '',
        ])->assertOk()->assertExactJson(['data' => 'Provider updated']);
        $this->assertSame('Updated Namecheap', $provider->fresh()->name);
        $this->assertSame('synthetic-api-key', $provider->fresh()->api_key);
        $this->assertSame('synthetic-password', $provider->fresh()->password);

        $this->patchJson($base.'/status', ['status' => 0])
            ->assertOk()->assertExactJson(['data' => 'Changed successfully']);
        $this->assertFalse($provider->fresh()->is_active);
        $this->assertDatabaseHas('audit_events', ['action' => 'domain_provider.status_changed', 'subject_id' => (string) $provider->getKey()]);

        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->workspaceFor($otherOwner, 'provider-other-edit');
        $foreignBase = '/infrastructure/domain-providers/'.$provider->getKey();
        $this->actingAs($otherOwner)->get($foreignBase.'/edit')->assertNotFound();
        $this->putJson($foreignBase, ['name' => 'Foreign change'])->assertNotFound();
        $this->patchJson($foreignBase.'/status', ['status' => 1])->assertNotFound();
    }

    public function test_other_workspace_cannot_use_a_scoped_provider_to_import_domains(): void
    {
        $this->actingAs($this->owner)->postJson($this->providerRoute(), [
            'name' => 'Example Registrar',
            'type' => 'namecheap',
            'username' => 'synthetic-user',
            'password' => null,
            'api_key' => 'synthetic-api-key',
            'api_secret' => null,
        ])->assertOk();

        $provider = DomainProvider::query()->firstOrFail();
        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->workspaceFor($otherOwner, 'provider-two');

        $this->actingAs($otherOwner)->postJson(
            route('console.infrastructure.domains.manual', $otherWorkspace->getKey()),
            ['provider' => $provider->getKey(), 'domains' => ['example.net']],
        )->assertNotFound();

        $this->assertSame(0, SendingDomain::query()->count());
    }

    public function test_provider_creation_matches_dms_field_rules_and_workspace_roles(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $payload = [
            'name' => 'Registrar',
            'type' => 'unsupported',
            'username' => 'usr',
            'password' => 'x',
            'api_key' => 'key',
            'api_secret' => null,
        ];

        $this->actingAs($operator)->postJson($this->providerRoute(), $payload)->assertForbidden();
        $this->actingAs($this->owner)->postJson($this->providerRoute(), $payload)->assertUnprocessable();
        $this->assertSame(0, DomainProvider::query()->count());
    }

    public function test_setup_pages_render_dms_fields_and_only_list_active_providers_from_this_workspace(): void
    {
        $localProvider = DomainProvider::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'Workspace registrar',
            'type' => 'namecheap',
            'is_active' => true,
        ]);
        $inactiveProvider = DomainProvider::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'Inactive registrar',
            'is_active' => false,
        ]);
        DomainProvider::query()->create([
            'workspace_id' => null,
            'name' => 'Legacy global registrar',
            'is_active' => true,
        ]);
        $foreignProvider = DomainProvider::query()->create([
            'workspace_id' => Workspace::query()->create([
                'name' => 'Other workspace',
                'slug' => 'provider-other',
                'created_by' => $this->owner->getKey(),
            ])->getKey(),
            'name' => 'Foreign registrar',
            'is_active' => true,
        ]);

        $this->actingAs($this->owner)
            ->get('/infrastructure/domain-providers/create')
            ->assertOk()
            ->assertSee('ADD PROVIDER')
            ->assertSee('Provider Type:')
            ->assertSee('namecheap')
            ->assertSee('godaddy')
            ->assertSee('Api Key:')
            ->assertSee('Api Secret:');

        $domainPage = $this->actingAs($this->owner)
            ->get('/infrastructure/domains/create')
            ->assertOk()
            ->assertSee('ADD DOMAINS')
            ->assertSee('Workspace registrar')
            ->assertSee('Legacy global registrar')
            ->assertSee('value="'.$localProvider->getKey().'"', false)
            ->assertDontSee('Inactive registrar')
            ->assertDontSee('Foreign registrar')
            ->assertDontSee('value="'.$foreignProvider->getKey().'"', false);
    }

    public function test_owner_can_set_the_dms_username_used_for_server_selection(): void
    {
        $this->actingAs($this->owner)
            ->get('/infrastructure/install-username')
            ->assertOk()
            ->assertSee('EDIT USER')
            ->assertSee('DMS username that appears in this server');

        $this->actingAs($this->owner)
            ->putJson(route('console.infrastructure.install-username.update', $this->workspace->getKey()), [
                'username' => 'relayuser',
            ])
            ->assertOk()
            ->assertExactJson(['data' => 'Username updated']);

        $this->assertSame('relayuser', $this->owner->fresh()->username);
    }

    public function test_username_update_matches_dms_length_uniqueness_and_member_access_rules(): void
    {
        User::factory()->create(['username' => 'takenname']);
        $route = route('console.infrastructure.install-username.update', $this->workspace->getKey());
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);

        $this->actingAs($operator)->putJson($route, ['username' => 'relayuser'])->assertOk();
        $this->actingAs($this->owner)->putJson($route, ['username' => 'abc'])->assertUnprocessable();
        $this->actingAs($this->owner)->putJson($route, ['username' => 'takenname'])->assertUnprocessable();
        $this->assertNull($this->owner->fresh()->username);
    }

    private function providerRoute(): string
    {
        return route('console.infrastructure.domain-providers.store', $this->workspace->getKey());
    }

    private function workspaceFor(User $user, string $slug): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Provider workspace',
            'slug' => $slug,
            'created_by' => $user->getKey(),
        ]);
        $workspace->users()->attach($user->getKey(), ['role' => 'owner']);

        return $workspace;
    }
}
