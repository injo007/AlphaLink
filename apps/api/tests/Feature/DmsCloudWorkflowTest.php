<?php

namespace Tests\Feature;

use App\Models\DomainProvider;
use App\Models\ManagedServer;
use App\Models\ServerCredential;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCloudProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsCloudWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->owner = User::factory()->create();
        $this->workspace = $this->makeWorkspace($this->owner, 'cloud-workflow-main');
        $this->actingAs($this->owner);
    }

    public function test_linode_and_digitalocean_workflows_render_and_are_the_only_cloud_destinations(): void
    {
        foreach (WorkspaceCloudProvider::WORKFLOW_TYPES as $type) {
            $this->provider($type, Str::headline($type));
            $response = $this->get(route('clean.console.clouds.index', $type))
                ->assertOk()
                ->assertSee('GET DATA')
                ->assertSee('GET INSTANCES')
                ->assertSee('CREATE INSTANCES')
                ->assertSee('SELECT NONE')
                ->assertSee('SELECT ALL')
                ->assertSee('BLACKLISTED')
                ->assertSee('BULK INSTALL SERVERS')
                ->assertSee('IP blacklist and Sender Score checks start automatically when GET INSTANCES loads rows, matching DMS.')
                ->assertSee('void checkReputation(instance, row.cells[blacklistColumn], row.cells[blacklistColumn + 1], providerId)')
                ->assertDontSee("textContent = 'CHECK'")
                ->assertDontSee('window.prompt')
                ->assertSee(route('clean.console.infrastructure.cloud-providers.index'), false);

            foreach (WorkspaceCloudProvider::WORKFLOW_TYPES as $navigationType) {
                $response->assertSee(route('clean.console.clouds.index', $navigationType), false);
            }
        }

        foreach (array_diff(WorkspaceCloudProvider::TYPES, WorkspaceCloudProvider::WORKFLOW_TYPES) as $type) {
            $this->get(route('clean.console.clouds.index', $type))->assertNotFound();
            $this->getJson(route('console.clouds.instances', [$this->workspace->getKey(), $type, 123]))->assertNotFound();
        }
    }

    public function test_cloud_consumer_explains_account_setup_when_no_eligible_provider_exists(): void
    {
        $this->get(route('clean.console.clouds.index', 'linode'))
            ->assertOk()
            ->assertSee('No eligible Linode account is configured for this workspace.')
            ->assertSee('Add a cloud provider account')
            ->assertSee('disabled', false);
    }

    public function test_linode_catalog_and_instance_reads_use_the_saved_token_and_dms_field_mapping(): void
    {
        $provider = $this->provider('linode', 'Synthetic Linode', 'synthetic-linode-token');
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();
            if (str_contains($url, '/images')) {
                return Http::response(['data' => [['id' => 'linode/ubuntu22.04', 'label' => 'Ubuntu 22.04']]]);
            }
            if (str_contains($url, '/regions')) {
                return Http::response(['data' => [['id' => 'us-east', 'country' => 'US']]]);
            }
            if (str_contains($url, '/linode/types')) {
                return Http::response(['data' => [['id' => 'g6-standard-1', 'label' => 'Nanode']]]);
            }
            if (str_contains($url, '/linode/instances')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return (int) ($query['page'] ?? 1) === 1
                    ? Http::response(['data' => [[
                        'id' => 101, 'label' => 'synthetic-node', 'status' => 'running',
                        'region' => 'us-east', 'type' => 'g6-standard-1', 'ipv4' => ['192.0.2.10'],
                        'created' => '2026-10-25T00:00:00Z',
                    ]]])
                    : Http::response(['data' => []]);
            }

            return Http::response([], 404);
        });

        $this->getJson(route('console.clouds.info', [$this->workspace->getKey(), 'linode', $provider->getKey()]))
            ->assertOk()
            ->assertJsonPath('images.0.label', 'Ubuntu 22.04')
            ->assertJsonPath('regions.0.id', 'us-east')
            ->assertJsonPath('types.0.id', 'g6-standard-1');
        $this->getJson(route('console.clouds.instances', [$this->workspace->getKey(), 'linode', $provider->getKey()]))
            ->assertOk()
            ->assertJsonPath('data.0.id', '101')
            ->assertJsonPath('data.0.ip', '192.0.2.10')
            ->assertJsonPath('data.0.status', 'running');

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'api.linode.com')
            && $request->hasHeader('Authorization', 'Bearer synthetic-linode-token'));
        $requests = Http::recorded()->map(fn (array $record): ClientRequest => $record[0])->all();
        $this->assertSame([
            ['GET', '/v4/linode/types', []],
            ['GET', '/v4/images', []],
            ['GET', '/v4/regions', []],
            ['GET', '/v4/linode/instances', ['page' => '1', 'per_page' => '100']],
            ['GET', '/v4/linode/instances', ['page' => '2', 'per_page' => '100']],
        ], array_map(static function (ClientRequest $request): array {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return [$request->method(), parse_url($request->url(), PHP_URL_PATH), $query];
        }, $requests));
    }

    public function test_operator_cloud_routes_only_return_and_operate_assigned_account(): void
    {
        $operator = User::factory()->create();
        $otherOperator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $this->workspace->users()->attach($otherOperator->getKey(), ['role' => 'operator']);
        $allowed = $this->provider('linode', 'Allowed Linode', 'allowed-token', [$operator->getKey()]);
        $blocked = $this->provider('linode', 'Blocked Linode', 'blocked-token', [$otherOperator->getKey()]);

        $this->actingAs($operator)
            ->get(route('clean.console.clouds.index', 'linode'))
            ->assertOk()
            ->assertSee('Allowed Linode')
            ->assertDontSee('Blocked Linode')
            ->assertDontSee('allowed-token')
            ->assertDontSee('blocked-token');

        $this->getJson(route('console.clouds.info', [$this->workspace->getKey(), 'linode', $blocked->getKey()]))
            ->assertForbidden();

        Http::fake([
            'https://api.linode.com/*' => Http::response(['data' => []]),
        ]);
        $this->getJson(route('console.clouds.info', [$this->workspace->getKey(), 'linode', $allowed->getKey()]))
            ->assertOk();
        Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('Authorization', 'Bearer allowed-token'));
    }

    public function test_digitalocean_catalog_and_instance_projection_match_dms_visible_fields(): void
    {
        $provider = $this->provider('digitalocean', 'Synthetic DigitalOcean', 'synthetic-do-token');
        Http::fake(function (ClientRequest $request) {
            if (str_contains($request->url(), '/v2/images')) {
                return Http::response(['images' => [['id' => 1234, 'slug' => 'centos-7-x64', 'description' => 'CentOS 7', 'regions' => ['nyc1']]]]);
            }
            if (str_contains($request->url(), '/v2/regions')) {
                return Http::response(['regions' => [['slug' => 'nyc1', 'name' => 'New York 1', 'available' => true]]]);
            }
            if (str_contains($request->url(), '/v2/sizes')) {
                return Http::response(['sizes' => [['slug' => 's-1vcpu-1gb', 'memory' => 1024, 'disk' => 25, 'price_monthly' => 6, 'regions' => ['nyc1']]]]);
            }
            if (str_contains($request->url(), '/v2/droplets')) {
                return Http::response(['droplets' => [[
                    'id' => 812, 'name' => 'workspace-node', 'status' => 'active',
                    'region' => ['slug' => 'nyc1', 'name' => 'New York 1'], 'size_slug' => 's-1vcpu-1gb',
                    'networks' => ['v4' => [
                        ['type' => 'private', 'ip_address' => '10.0.0.80'],
                        ['type' => 'public', 'ip_address' => '192.0.2.80'],
                    ]],
                    'created_at' => '2026-10-25T00:00:00Z',
                ]]]);
            }

            return Http::response([], 404);
        });

        $this->getJson(route('console.clouds.info', [$this->workspace->getKey(), 'digitalocean', $provider->getKey()]))
            ->assertOk()
            ->assertJsonPath('images.0.slug', 'centos-7-x64')
            ->assertJsonPath('regions.0.name', 'New York 1')
            ->assertJsonPath('types.0.slug', 's-1vcpu-1gb');
        $this->getJson(route('console.clouds.instances', [$this->workspace->getKey(), 'digitalocean', $provider->getKey()]))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'workspace-node')
            ->assertJsonPath('data.0.region', 'New York 1')
            ->assertJsonPath('data.0.type', 's-1vcpu-1gb')
            ->assertJsonPath('data.0.ip', '192.0.2.80')
            ->assertJsonMissingPath('data.0.raw');

        $requests = Http::recorded()->map(fn (array $record): ClientRequest => $record[0])->all();
        $this->assertSame([
            ['GET', '/v2/sizes', ['per_page' => '200']],
            ['GET', '/v2/images', ['type' => 'distribution']],
            ['GET', '/v2/regions', ['per_page' => '200']],
            ['GET', '/v2/droplets', ['per_page' => '200']],
        ], array_map(static function (ClientRequest $request): array {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return [$request->method(), parse_url($request->url(), PHP_URL_PATH), $query];
        }, $requests));
    }

    public function test_digitalocean_create_registers_the_encrypted_workspace_key_then_posts_dms_creation_fields(): void
    {
        $provider = $this->provider('digitalocean', 'Synthetic DigitalOcean', 'synthetic-do-token');
        Http::fake(function (ClientRequest $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/v2/account/keys')) {
                return Http::response(['ssh_keys' => []]);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/v2/account/keys')) {
                return Http::response(['ssh_key' => ['id' => 9021]]);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/v2/droplets')) {
                return Http::response(['droplet' => ['id' => 13579]], 202);
            }

            return Http::response([], 404);
        });

        $this->postJson(route('console.clouds.create', [$this->workspace->getKey(), 'digitalocean', $provider->getKey()]), [
            'image' => 'ubuntu-24-04-x64', 'region' => 'nyc1', 'type' => 's-1vcpu-1gb', 'name' => 'workspace-node', 'count' => 1,
        ])->assertOk()->assertJsonPath('data', 'Created 1 Instances');

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.digitalocean.com/v2/droplets'
            && $request->hasHeader('Authorization', 'Bearer synthetic-do-token')
            && $request->data() === [
                'name' => 'workspace-node',
                'image' => 'ubuntu-24-04-x64',
                'region' => 'nyc1',
                'size' => 's-1vcpu-1gb',
                'ssh_keys' => ['9021'],
            ]
            && $request['image'] === 'ubuntu-24-04-x64'
            && $request['region'] === 'nyc1'
            && $request['size'] === 's-1vcpu-1gb'
            && $request['ssh_keys'] === ['9021']);

        $settings = $this->workspace->fresh()->infrastructureSettings;
        $this->assertIsArray($settings->cloud_ssh_key_pair);
        $this->assertStringContainsString('PRIVATE KEY', $settings->cloud_ssh_key_pair['private_key']);
        $this->assertStringStartsWith('ssh-rsa ', $settings->cloud_ssh_key_pair['public_key']);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $settings->getRawOriginal('cloud_ssh_key_pair'));
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.digitalocean.com/v2/account/keys?per_page=200'
            && $request->hasHeader('Authorization', 'Bearer synthetic-do-token'));
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.digitalocean.com/v2/account/keys'
            && $request->hasHeader('Authorization', 'Bearer synthetic-do-token')
            && $request['name'] === 'alphalink-'.substr(str_replace('-', '', (string) $this->workspace->getKey()), 0, 12)
            && $request['public_key'] === $settings->cloud_ssh_key_pair['public_key']);
    }

    public function test_add_to_servers_requires_current_provider_selection_and_saves_the_matching_private_key(): void
    {
        $provider = $this->provider('digitalocean', 'Synthetic DigitalOcean', 'synthetic-do-token');
        $this->createInstallableDomain();
        Http::fake(['https://api.digitalocean.com/*' => Http::response(['droplets' => [[
            'id' => 812, 'name' => 'workspace-node', 'status' => 'active', 'region' => ['slug' => 'nyc1'],
            'size_slug' => 's-1vcpu-1gb', 'networks' => ['v4' => [
                ['type' => 'private', 'ip_address' => '10.0.0.80'],
                ['type' => 'public', 'ip_address' => '192.0.2.80'],
            ]],
            'created_at' => '2026-10-25T00:00:00Z',
        ]]])]);

        $this->postJson(route('console.clouds.servers', [$this->workspace->getKey(), 'digitalocean', $provider->getKey()]), [
            'instances' => [['id' => '812', 'ip' => '192.0.2.80']],
        ])->assertOk()->assertJsonPath('data', 'Added 1, Restored 0');

        $server = ManagedServer::query()->where('workspace_id', $this->workspace->getKey())->where('ip_address', '192.0.2.80')->firstOrFail();
        $credential = ServerCredential::query()->where('managed_server_id', $server->getKey())->firstOrFail();
        $keyPair = $this->workspace->fresh()->infrastructureSettings->cloud_ssh_key_pair;
        $this->assertSame('ssh_key', $server->login_type);
        $this->assertSame('digital', $server->provider);
        $this->assertSame('root', $server->ssh_username);
        $this->assertSame(22, $server->ssh_port);
        $this->assertSame(now()->addDays(30)->toDateString(), $server->expire_at?->format('Y-m-d'));
        $this->assertTrue($server->is_active);
        $this->assertFalse($server->is_installed);
        $this->assertSame($keyPair['private_key'], Crypt::decryptString($credential->encrypted_secret));
        $this->assertTrue($server->allowedUsers()->whereKey($this->owner->getKey())->exists());

        $installPage = $this->get(route('clean.console.infrastructure.install.index'))->assertOk();
        $installPage->assertSee($server->getKey(), false)->assertSee('192.0.2.80')->assertSee('install.example.test');
        preg_match('/<select[^>]*id="single-server"[^>]*>/', $installPage->getContent(), $serverSelect);
        $this->assertNotEmpty($serverSelect);
        $this->assertStringNotContainsString('disabled', $serverSelect[0]);

        $this->postJson(route('console.clouds.servers', [$this->workspace->getKey(), 'digitalocean', $provider->getKey()]), [
            'instances' => [['id' => '999', 'ip' => '192.0.2.81']],
        ])->assertStatus(422);
        $this->assertSame(1, ManagedServer::query()->where('workspace_id', $this->workspace->getKey())->count());
    }

    public function test_linode_rdns_is_bound_to_a_current_instance_ip(): void
    {
        $provider = $this->provider('linode', 'Synthetic Linode', 'synthetic-linode-token');
        Http::fake(function (ClientRequest $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/linode/instances')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return (int) ($query['page'] ?? 1) === 1
                    ? Http::response(['data' => [['id' => 101, 'ipv4' => ['192.0.2.10']]]])
                    : Http::response(['data' => []]);
            }
            if ($request->method() === 'PUT' && str_contains($request->url(), '/networking/ips/192.0.2.10')) {
                return Http::response(['success' => true]);
            }

            return Http::response([], 404);
        });

        $this->postJson(route('console.clouds.rdns', [$this->workspace->getKey(), 'linode', $provider->getKey()]), [
            'id' => '101', 'ip' => '192.0.2.10', 'rdns' => 'mail.example.test',
        ])->assertOk()->assertJsonPath('data', 'rDNS updated.');

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/networking/ips/192.0.2.10')
            && $request->hasHeader('Authorization', 'Bearer synthetic-linode-token')
            && $request['rdns'] === 'mail.example.test');
    }

    public function test_digitalocean_delete_confirms_current_id_ip_pair_and_removes_only_unlocked_server_record(): void
    {
        $provider = $this->provider('digitalocean', 'Synthetic DigitalOcean', 'synthetic-do-token');
        $server = ManagedServer::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'name' => 'cloud-node', 'provider' => 'digital',
            'ip_address' => '192.0.2.80', 'created_by' => $this->owner->getKey(),
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('a', 64),
        ]);
        $lockedServer = ManagedServer::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'name' => 'locked-node', 'provider' => 'digital',
            'ip_address' => '192.0.2.81', 'is_locked' => true, 'created_by' => $this->owner->getKey(),
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('b', 64),
        ]);
        Http::fake(function (ClientRequest $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/v2/droplets')) {
                return Http::response(['droplets' => [[
                    'id' => 812,
                    'networks' => ['v4' => [['type' => 'public', 'ip_address' => '192.0.2.80']]],
                ]]]);
            }
            if ($request->method() === 'DELETE' && str_ends_with($request->url(), '/v2/droplets/812')) {
                return Http::response([], 204);
            }

            return Http::response([], 404);
        });

        $this->deleteJson(route('console.clouds.destroy', [$this->workspace->getKey(), 'digitalocean', $provider->getKey()]), [
            'instances' => [['id' => '812', 'ip' => '192.0.2.80']],
        ])->assertOk()->assertJsonPath('data.0', '812 Deleted Successfully');

        $this->assertSoftDeleted('managed_servers', ['id' => $server->getKey()]);
        $this->assertDatabaseHas('managed_servers', ['id' => $lockedServer->getKey(), 'deleted_at' => null, 'is_locked' => true]);

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.digitalocean.com/v2/droplets/812'
            && $request->hasHeader('Authorization', 'Bearer synthetic-do-token'));
    }

    public function test_cloud_reputation_uses_dms_dnsbl_and_sender_score_queries_only_after_explicit_request(): void
    {
        $provider = $this->provider('digitalocean', 'Synthetic DigitalOcean', 'synthetic-do-token');
        Http::fake(['https://api.digitalocean.com/*' => Http::response(['droplets' => [[
            'id' => 812,
            'networks' => ['v4' => [['type' => 'public', 'ip_address' => '192.0.2.80']]],
        ]]])]);
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $command = $process->command;
            $commands[] = $command;
            $query = (string) end($command);
            $output = match (true) {
                str_contains($query, '.zen.spamhaus.org') => '127.0.0.2',
                str_contains($query, '.b.barracudacentral.org') => '',
                str_contains($query, '.bl.spamcop.net') => '127.0.0.2',
                str_contains($query, '.score.senderscore.com') => '127.0.0.47',
                default => '',
            };

            return Process::result($output);
        });

        $this->postJson(route('console.clouds.reputation', [$this->workspace->getKey(), 'digitalocean', $provider->getKey()]), [
            'id' => '812', 'ip' => '192.0.2.80',
        ])->assertOk()
            ->assertJsonPath('spamhaus', true)
            ->assertJsonPath('barracuda', false)
            ->assertJsonPath('spamcop', true)
            ->assertJsonPath('score', '47');

        $this->assertCount(4, $commands);
        $this->assertContains('80.2.0.192.zen.spamhaus.org', array_map(static fn (array $command): string => (string) end($command), $commands));
    }

    public function test_add_to_servers_returns_provider_read_failures_as_actionable_responses(): void
    {
        $provider = $this->provider('digitalocean', 'Synthetic DigitalOcean', 'synthetic-do-token');
        Http::fake(['https://api.digitalocean.com/*' => Http::response(['message' => 'unauthorized'], 401)]);

        $this->postJson(route('console.clouds.servers', [$this->workspace->getKey(), 'digitalocean', $provider->getKey()]), [
            'instances' => [['id' => '812', 'ip' => '192.0.2.80']],
        ])->assertStatus(422)->assertJsonPath('message', 'DigitalOcean rejected the API access token (HTTP 401). It may be invalid or expired. Use an active DigitalOcean API access token, not a Spaces key or OAuth refresh token, then enter it again. Testing does not save token changes.');
    }

    public function test_cloud_provider_form_only_offers_the_two_requested_consumers(): void
    {
        $this->get(route('clean.console.infrastructure.cloud-providers.create'))
            ->assertOk()
            ->assertSee('value="linode"', false)
            ->assertSee('value="digitalocean"', false)
            ->assertDontSee('Amazon EC2')
            ->assertDontSee('Vultr');
    }

    public function test_linode_creation_uses_the_dms_password_payload_and_stops_after_provider_failure(): void
    {
        $provider = $this->provider('linode', 'Synthetic Linode', 'synthetic-linode-token');
        $this->workspace->infrastructureSettings()->create(['cloud_servers_password' => 'synthetic-root-password']);
        Http::fake(['https://api.linode.com/*' => Http::sequence()
            ->push(['id' => 123])
            ->push(['errors' => [['reason' => 'synthetic provider rejection']]], 422)]);

        $this->postJson(route('console.clouds.create', [$this->workspace->getKey(), 'linode', $provider->getKey()]), [
            'image' => 'linode/ubuntu22.04', 'region' => 'us-east', 'type' => 'g6-standard-1', 'name' => 'WorkspaceNode', 'count' => 3,
        ])->assertOk()->assertJsonPath('data', 'Created 1 Instances');

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.linode.com/v4/linode/instances'
            && $request->hasHeader('Authorization', 'Bearer synthetic-linode-token')
            && str_starts_with((string) $request['label'], 'WorkspaceNode_')
            && $request['image'] === 'linode/ubuntu22.04'
            && $request['region'] === 'us-east'
            && $request['type'] === 'g6-standard-1'
            && $request['root_pass'] === 'synthetic-root-password'
            && $request['private_ip'] === false);
        Http::assertSentCount(2);
    }

    public function test_linode_delete_uses_the_dms_instance_endpoint_before_soft_deleting_its_unlocked_server_row(): void
    {
        $provider = $this->provider('linode', 'Synthetic Linode', 'synthetic-linode-token');
        $server = ManagedServer::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'name' => 'cloud-node', 'provider' => 'linode',
            'ip_address' => '192.0.2.10', 'created_by' => $this->owner->getKey(),
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('c', 64),
        ]);
        Http::fake(function (ClientRequest $request) {
            if ($request->method() === 'GET' && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/linode/instances')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return (int) ($query['page'] ?? 1) === 1
                    ? Http::response(['data' => [['id' => 101, 'ipv4' => ['192.0.2.10']]]])
                    : Http::response(['data' => []]);
            }
            if ($request->method() === 'DELETE' && $request->url() === 'https://api.linode.com/v4/linode/instances/101') {
                return Http::response([], 200);
            }

            return Http::response([], 404);
        });

        $this->deleteJson(route('console.clouds.destroy', [$this->workspace->getKey(), 'linode', $provider->getKey()]), [
            'instances' => [['id' => '101', 'ip' => '192.0.2.10']],
        ])->assertOk()->assertJsonPath('data.0', '101 Deleted Successfully');

        $this->assertSoftDeleted('managed_servers', ['id' => $server->getKey()]);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.linode.com/v4/linode/instances/101'
            && $request->hasHeader('Authorization', 'Bearer synthetic-linode-token'));
    }

    public function test_linode_add_to_servers_saves_the_root_password_and_makes_the_server_available_for_install(): void
    {
        $provider = $this->provider('linode', 'Synthetic Linode', 'synthetic-linode-token');
        $this->createInstallableDomain();
        $this->workspace->infrastructureSettings()->create(['cloud_servers_password' => 'synthetic-root-password']);
        Http::fake(function (ClientRequest $request) {
            if ($request->method() !== 'GET' || ! str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/linode/instances')) {
                return Http::response([], 404);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return (int) ($query['page'] ?? 1) === 1
                ? Http::response(['data' => [[
                    'id' => 101, 'label' => 'workspace-node', 'status' => 'running', 'ipv4' => ['192.0.2.10'],
                ]]])
                : Http::response(['data' => []]);
        });

        $this->postJson(route('console.clouds.servers', [$this->workspace->getKey(), 'linode', $provider->getKey()]), [
            'instances' => [['id' => '101', 'ip' => '192.0.2.10']],
        ])->assertOk()->assertJsonPath('data', 'Added 1, Restored 0');

        $server = ManagedServer::query()->where('workspace_id', $this->workspace->getKey())->where('ip_address', '192.0.2.10')->firstOrFail();
        $credential = ServerCredential::query()->where('managed_server_id', $server->getKey())->firstOrFail();
        $this->assertSame('password', $server->login_type);
        $this->assertSame('linode', $server->provider);
        $this->assertSame('root', $server->ssh_username);
        $this->assertSame(22, $server->ssh_port);
        $this->assertSame(now()->addDays(30)->toDateString(), $server->expire_at?->format('Y-m-d'));
        $this->assertTrue($server->is_active);
        $this->assertFalse($server->is_installed);
        $this->assertSame('synthetic-root-password', Crypt::decryptString($credential->encrypted_secret));

        $installPage = $this->get(route('clean.console.infrastructure.install.index'))->assertOk();
        $installPage->assertSee($server->getKey(), false)->assertSee('192.0.2.10')->assertSee('install.example.test');
        preg_match('/<select[^>]*id="single-server"[^>]*>/', $installPage->getContent(), $serverSelect);
        $this->assertNotEmpty($serverSelect);
        $this->assertStringNotContainsString('disabled', $serverSelect[0]);
    }

    public function test_digitalocean_requires_dms_hostname_validation_before_contacting_provider(): void
    {
        $provider = $this->provider('digitalocean', 'Synthetic DigitalOcean', 'synthetic-do-token');
        Http::fake();

        $this->postJson(route('console.clouds.create', [$this->workspace->getKey(), 'digitalocean', $provider->getKey()]), [
            'image' => 'ubuntu-24-04-x64', 'region' => 'nyc1', 'type' => 's-1vcpu-1gb', 'name' => 'not/a hostname', 'count' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('name');

        Http::assertNothingSent();
    }

    private function provider(string $type, string $name, string $token = 'synthetic-cloud-token', array $allowedUsers = []): WorkspaceCloudProvider
    {
        return $this->workspace->cloudProviders()->create([
            'type' => $type,
            'name' => $name,
            'tokens' => ['api_token' => $token],
            'allowed_users' => array_values(array_map('intval', $allowedUsers)),
        ]);
    }

    private function createInstallableDomain(): void
    {
        $domainProvider = DomainProvider::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'Synthetic registrar',
            'is_active' => true,
        ]);
        $this->workspace->sendingDomains()->create([
            'name' => 'install.example.test',
            'verification_token' => Str::random(40),
            'created_by' => $this->owner->getKey(),
            'domains_provider_id' => $domainProvider->getKey(),
            'status' => 'free',
            'is_our' => false,
            'is_active' => true,
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
