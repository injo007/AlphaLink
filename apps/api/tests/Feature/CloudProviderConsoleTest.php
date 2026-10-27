<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCloudProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CloudProviderConsoleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->owner = User::factory()->create();
        $this->workspace = $this->makeWorkspace($this->owner, 'cloud-provider-main');
        $this->actingAs($this->owner);
    }

    public function test_index_and_edit_form_match_dms_cloud_provider_fields(): void
    {
        $firstOperator = User::factory()->create(['name' => 'Zeta Mailer']);
        $secondOperator = User::factory()->create(['name' => 'Alpha Mailer']);
        $admin = User::factory()->create(['name' => 'Cloud Admin Not Allowed']);
        $this->workspace->users()->attach($firstOperator->getKey(), ['role' => 'operator']);
        $this->workspace->users()->attach($secondOperator->getKey(), ['role' => 'operator']);
        $this->workspace->users()->attach($admin->getKey(), ['role' => 'admin']);
        $provider = $this->provider('digitalocean', 'Primary cloud', 'synthetic-cloud-token', [$firstOperator->getKey()]);

        $index = route('clean.console.infrastructure.cloud-providers.index');
        $this->get($index)
            ->assertOk()
            ->assertSee('Cloud Providers')
            ->assertSee(asset('dms-reference/plugins/datatables/datatables.min.css'), false)
            ->assertSee(asset('dms-reference/plugins/datatables/datatables.min.js'), false)
            ->assertSee(asset('dms-reference/assets/js/tables_datatables.js'), false);
        $this->getJson($index)
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.name', 'Primary cloud')
            ->assertJsonPath('data.0.type', 'digitalocean')
            ->assertDontSee('synthetic-cloud-token');
        $edit = $this->get(route('clean.console.infrastructure.cloud-providers.edit', $provider->getKey()))
            ->assertOk()
            ->assertSee('Allowed Mailers :')
            ->assertSee('TEST CONNECTION')
            ->assertSee($firstOperator->name)
            ->assertSee($secondOperator->name)
            ->assertDontSee('id="allowed-mailer-'.$this->owner->getKey().'"', false)
            ->assertDontSee('id="allowed-mailer-'.$admin->getKey().'"', false)
            ->assertSee('type="password"', false)
            ->assertSee('Leave blank to keep current token')
            ->assertSee('dop_v1_')
            ->assertSee('doo_v1_')
            ->assertSee('droplet:read')
            ->assertDontSee('synthetic-cloud-token');
        $edit->assertSeeInOrder([$firstOperator->name, $secondOperator->name]);
    }

    public function test_datatable_search_and_pagination_are_workspace_scoped(): void
    {
        $this->provider('linode', 'First synthetic cloud', 'first-token');
        $this->provider('azure', 'Second synthetic cloud', 'second-token');
        $index = route('clean.console.infrastructure.cloud-providers.index');
        $query = http_build_query([
            'draw' => 4,
            'start' => 0,
            'length' => 1,
            'search' => ['value' => 'Second'],
            'order' => [['column' => 2, 'dir' => 'asc']],
            'columns' => [
                ['data' => 'id'], ['data' => 'type'], ['data' => 'name'], ['data' => 'created_at'], ['data' => null],
            ],
        ]);

        $this->getJson($index.'?'.$query)
            ->assertOk()
            ->assertJsonPath('draw', 4)
            ->assertJsonPath('recordsTotal', 2)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.name', 'Second synthetic cloud')
            ->assertJsonMissingPath('data.1')
            ->assertDontSee('second-token');
    }

    public function test_create_encrypts_api_token_and_scopes_allowed_users_to_workspace(): void
    {
        $member = User::factory()->create();
        $this->workspace->users()->attach($member->getKey(), ['role' => 'operator']);
        $token = 'synthetic-cloud-api-token';

        $this->post(route('console.infrastructure.cloud-providers.store', $this->workspace->getKey()), [
            'type' => 'linode',
            'name' => 'Primary Linode',
            'api_token' => $token,
            'allowed_users' => [$member->getKey()],
        ])->assertRedirect(route('clean.console.infrastructure.cloud-providers.index'));

        $provider = WorkspaceCloudProvider::query()->where('name', 'Primary Linode')->sole();
        $this->assertSame(['api_token' => $token], $provider->tokens);
        $this->assertNotSame(json_encode(['api_token' => $token]), $provider->getRawOriginal('tokens'));
        $this->assertSame([(int) $member->getKey()], $provider->allowed_users);
        $this->assertStringNotContainsString($token, AuditEvent::query()->get()->toJson());
        $this->post(route('console.infrastructure.cloud-providers.store', $this->workspace->getKey()), [
            'type' => 'linode', 'name' => 'Foreign member', 'api_token' => 'another-synthetic-token',
            'allowed_users' => [User::factory()->create()->getKey()],
        ])->assertSessionHasErrors(['allowed_users.0']);
        $this->post(route('console.infrastructure.cloud-providers.store', $this->workspace->getKey()), [
            'type' => 'linode', 'name' => 'Owner is not a mailer', 'api_token' => 'third-synthetic-token',
            'allowed_users' => [$this->owner->getKey()],
        ])->assertSessionHasErrors(['allowed_users.0']);
    }

    public function test_connection_checks_read_only_instance_access_for_linode_and_digitalocean(): void
    {
        $linode = $this->provider('linode', 'Primary Linode', 'saved-linode-token');
        Http::fake([
            'https://api.linode.com/v4/linode/instances*' => Http::response(['data' => [], 'pages' => 0]),
            'https://api.digitalocean.com/v2/droplets*' => Http::response(['droplets' => []]),
        ]);

        $this->postJson(route('console.infrastructure.cloud-providers.test-existing-connection', [
            $this->workspace->getKey(), $linode->getKey(),
        ]), ['type' => 'linode', 'api_token' => ''])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Connected to Linode. Instance-list access is available; cloud creation permissions were not tested.');

        $this->postJson(route('console.infrastructure.cloud-providers.test-new-connection', $this->workspace->getKey()), [
            'type' => 'digitalocean', 'api_token' => 'unsaved-digitalocean-token',
        ])->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Connected to DigitalOcean. Instance-list access is available; cloud creation permissions were not tested.');

        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/v4/linode/instances?')
            && str_contains($request->url(), 'page=1')
            && str_contains($request->url(), 'page_size=25')
            && $request->hasHeader('Authorization', 'Bearer saved-linode-token'));
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/v2/droplets?')
            && str_contains($request->url(), 'per_page=1')
            && $request->hasHeader('Authorization', 'Bearer unsaved-digitalocean-token'));
        $this->assertSame(1, WorkspaceCloudProvider::query()->count());
        $this->assertSame(['api_token' => 'saved-linode-token'], $linode->fresh()->tokens);
    }

    public function test_connection_check_requires_a_token_before_making_a_provider_request(): void
    {
        Http::fake();

        $this->postJson(route('clean.console.infrastructure.cloud-providers.test-new-connection'), [
            'type' => 'linode',
            'api_token' => '',
        ])->assertOk()->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Enter the cloud API token before testing this account.');

        Http::assertNothingSent();
    }

    public function test_cloud_connection_failures_do_not_disclose_provider_response_or_allow_operators(): void
    {
        Http::fake([
            'https://api.linode.com/v4/linode/instances*' => Http::response(['errors' => [['reason' => 'synthetic-secret-body']]], 401),
        ]);
        $url = route('console.infrastructure.cloud-providers.test-new-connection', $this->workspace->getKey());

        $this->postJson($url, ['type' => 'linode', 'api_token' => 'synthetic-token'])
            ->assertOk()->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Cloud provider request failed (HTTP 401). Check the saved provider credentials and account permissions.')
            ->assertJsonMissing(['message' => 'synthetic-secret-body']);

        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $this->actingAs($operator)->postJson($url, ['type' => 'digitalocean', 'api_token' => 'synthetic-token'])->assertForbidden();
        $this->assertSame(0, WorkspaceCloudProvider::query()->count());
    }

    public function test_digitalocean_connection_failures_explain_invalid_token_and_read_scope(): void
    {
        Http::fakeSequence('https://api.digitalocean.com/v2/droplets*')
            ->push(['message' => 'synthetic-private-provider-error'], 401)
            ->push(['message' => 'synthetic-private-provider-error'], 403);
        $url = route('clean.console.infrastructure.cloud-providers.test-new-connection');

        $this->postJson($url, ['type' => 'digitalocean', 'api_token' => 'synthetic-do-token'])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'DigitalOcean rejected the API access token (HTTP 401). It may be invalid or expired. Use an active DigitalOcean API access token, not a Spaces key or OAuth refresh token, then enter it again. Testing does not save token changes.')
            ->assertJsonMissing(['message' => 'synthetic-private-provider-error'])
            ->assertDontSee('synthetic-do-token');

        $this->postJson($url, ['type' => 'digitalocean', 'api_token' => 'synthetic-do-token'])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'DigitalOcean denied droplet-list access (HTTP 403). Confirm the token has droplet:read and any dependent read scopes DigitalOcean requires for custom tokens. This check only reads droplets.')
            ->assertJsonMissing(['message' => 'synthetic-private-provider-error'])
            ->assertDontSee('synthetic-do-token');

        Http::assertSentCount(2);
        $this->assertSame(0, WorkspaceCloudProvider::query()->count());
    }

    public function test_edit_hides_and_preserves_token_when_blank_then_requires_replacement_for_provider_type_change(): void
    {
        $provider = $this->provider('digitalocean', 'Primary DigitalOcean', 'original-cloud-secret');
        $updateUrl = route('console.infrastructure.cloud-providers.update', [$this->workspace->getKey(), $provider->getKey()]);
        $this->putJson($updateUrl, [
            'type' => 'digitalocean', 'name' => 'Renamed Cloud', 'api_token' => '',
        ])->assertOk()->assertJsonPath('data', 'Updated successfully');
        $this->assertSame(['api_token' => 'original-cloud-secret'], $provider->fresh()->tokens);
        $this->assertSame('Renamed Cloud', $provider->fresh()->name);

        $this->putJson($updateUrl, [
            'type' => 'linode', 'name' => 'Updated Cloud', 'api_token' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors(['api_token']);

        $this->putJson($updateUrl, [
            'type' => 'linode', 'name' => 'Updated Cloud', 'api_token' => 'replacement-cloud-secret',
        ])->assertOk()->assertJsonPath('data', 'Updated successfully');
        $this->assertSame(['api_token' => 'replacement-cloud-secret'], $provider->fresh()->tokens);
        $this->assertSame('linode', $provider->fresh()->type);

        $this->deleteJson(route('console.infrastructure.cloud-providers.destroy', [$this->workspace->getKey(), $provider->getKey()]))
            ->assertOk()->assertJsonPath('data', 'Deleted Successfully');
        $this->assertDatabaseMissing('workspace_cloud_providers', ['id' => $provider->getKey()]);
        $this->patch('/app/workspaces/'.$this->workspace->getKey().'/infrastructure/cloud-providers/'.$provider->getKey().'/restore')
            ->assertNotFound();
    }

    public function test_cloud_provider_pages_are_workspace_and_role_scoped(): void
    {
        $other = $this->makeWorkspace(User::factory()->create(), 'cloud-provider-other');
        $provider = $this->provider('vultr', 'Private cloud', 'synthetic-secret');
        $other->cloudProviders()->create([
            'type' => 'vultr', 'name' => 'Other provider', 'tokens' => ['api_token' => 'other-secret'],
        ]);
        $this->get(route('clean.console.infrastructure.cloud-providers.edit', $other->cloudProviders()->create([
            'type' => 'vultr', 'name' => 'Other private provider', 'tokens' => ['api_token' => 'hidden-token'],
        ])->getKey()))->assertNotFound();

        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $this->actingAs($operator)->get(route('clean.console.infrastructure.cloud-providers.index'))
            ->assertForbidden();
        $this->actingAs($this->owner)->get(route('clean.console.infrastructure.cloud-providers.index'))
            ->assertOk()->assertDontSee('other-secret');
        $this->getJson(route('console.infrastructure.cloud-providers.index', $this->workspace->getKey()))
            ->assertOk()->assertJsonPath('data.0.name', 'Private cloud')->assertDontSee('other-secret');
        $this->assertNotNull($provider->fresh());
    }

    public function test_available_provider_selector_matches_dms_type_and_allowed_mailer_filter_without_tokens(): void
    {
        $operator = User::factory()->create();
        $otherOperator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $this->workspace->users()->attach($otherOperator->getKey(), ['role' => 'operator']);
        $allowed = $this->provider('digitalocean', 'Allowed account', 'allowed-secret', [$operator->getKey()]);
        $blocked = $this->provider('digitalocean', 'Other account', 'blocked-secret', [$otherOperator->getKey()]);
        $differentType = $this->provider('azure', 'Different type', 'different-type-secret', [$operator->getKey()]);
        $otherWorkspace = $this->makeWorkspace(User::factory()->create(), 'cloud-selector-other');
        $otherWorkspace->cloudProviders()->create([
            'type' => 'digitalocean',
            'name' => 'Other workspace account',
            'tokens' => ['api_token' => 'foreign-secret'],
            'allowed_users' => [$operator->getKey()],
        ]);
        $url = route('console.infrastructure.cloud-providers.available', [$this->workspace->getKey(), 'digitalocean']);

        $this->actingAs($operator)->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $allowed->getKey())
            ->assertJsonPath('data.0.name', 'Allowed account')
            ->assertDontSee('allowed-secret')
            ->assertDontSee('blocked-secret')
            ->assertDontSee('foreign-secret');

        $this->actingAs($this->owner)->getJson($url)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['id' => $differentType->getKey()]);

        $this->actingAs($operator)->getJson(route('console.infrastructure.cloud-providers.available', [$this->workspace->getKey(), 'unknown']))
            ->assertNotFound();
    }

    private function provider(string $type, string $name, string $token, array $allowedUsers = []): WorkspaceCloudProvider
    {
        return $this->workspace->cloudProviders()->create([
            'type' => $type,
            'name' => $name,
            'tokens' => ['api_token' => $token],
            'allowed_users' => array_values(array_map('intval', $allowedUsers)),
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
