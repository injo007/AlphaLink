<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\ServerCredential;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DomainTxtResolver;
use App\Services\Infrastructure\SshHostProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Mockery;
use Tests\TestCase;

class InfrastructurePlanningTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Demo', 'slug' => 'demo', 'created_by' => $this->owner->id,
        ]);
        $this->workspace->users()->attach($this->owner->id, ['role' => 'owner']);
        $this->actingAs($this->owner);
    }

    public function test_server_registration_encrypts_password_and_never_returns_it(): void
    {
        $url = $this->url('/servers');
        $data = [
            'name' => 'Primary relay', 'provider' => 'Example provider',
            'ip_address' => '159.223.97.39', 'ssh_port' => 22,
            'root_password' => 'private-example-password',
        ];
        $key = (string) Str::uuid();

        $created = $this->withHeader('Idempotency-Key', $key)->postJson($url, $data)
            ->assertCreated()->assertJsonPath('data.state', 'pending_host_verification');
        $this->assertStringNotContainsString($data['root_password'], $created->getContent());
        $credential = ServerCredential::query()->firstOrFail();
        $this->assertNotSame($data['root_password'], $credential->encrypted_secret);
        $this->assertSame($data['root_password'], Crypt::decryptString($credential->encrypted_secret));
        $this->assertSame('password', $credential->credential_type);

        $this->withHeader('Idempotency-Key', $key)->postJson($url, $data)->assertOk();
        $this->assertSame(1, ManagedServer::query()->count());
        $this->assertSame(1, ServerCredential::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'server.registered')->count());

        $this->withHeader('Idempotency-Key', $key)
            ->postJson($url, [...$data, 'root_password' => 'changed-password'])
            ->assertStatus(409);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($url, [...$data, 'ip_address' => '127.0.0.1'])
            ->assertUnprocessable();
    }

    public function test_multi_ip_registration_creates_one_server_per_ip_and_replays_as_a_batch(): void
    {
        $url = $this->url('/servers');
        $key = (string) Str::uuid();
        $data = [
            'name' => 'Relay group', 'provider' => 'Example provider',
            'ip_addresses' => ['159.223.97.39', '159.223.97.40'],
            'ssh_username' => 'mailer',
            'root_password' => 'private-example-password',
        ];

        $this->withHeader('Idempotency-Key', $key)->postJson($url, $data)
            ->assertCreated()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.ip_address', '159.223.97.39')
            ->assertJsonPath('data.1.ip_address', '159.223.97.40');
        $this->assertSame(2, ManagedServer::query()->count());
        $this->assertSame(2, ServerCredential::query()->count());
        $this->assertSame(2, ManagedServer::query()->where('ssh_username', 'mailer')->count());

        $this->withHeader('Idempotency-Key', $key)->postJson($url, $data)
            ->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(2, ManagedServer::query()->count());

        $this->withHeader('Idempotency-Key', $key)->postJson($url, [
            ...$data, 'ip_addresses' => ['159.223.97.39', '159.223.97.41'],
        ])->assertStatus(409);
        $this->assertSame(2, ManagedServer::query()->count());
    }

    public function test_multi_ip_registration_rolls_back_when_one_ip_is_already_registered(): void
    {
        $url = $this->url('/servers');
        $base = ['name' => 'Relay', 'provider' => 'Example provider', 'root_password' => 'private-example-password'];
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($url, [...$base, 'ip_address' => '159.223.97.40'])->assertCreated();

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($url, [...$base, 'ip_addresses' => ['159.223.97.39', '159.223.97.40']])
            ->assertUnprocessable();
        $this->assertSame(1, ManagedServer::query()->count());
        $this->assertSame(1, ServerCredential::query()->count());
    }

    public function test_browser_form_saves_multiple_server_ips(): void
    {
        $page = '/app/workspaces/'.$this->workspace->id.'/infrastructure';
        $this->post($page.'/servers', [
            'request_key' => (string) Str::uuid(),
            'name' => 'Relay group', 'provider' => 'Example provider',
            'ip_addresses_text' => "159.223.97.39\n159.223.97.40",
            'ssh_username' => 'mailer',
            'root_password' => 'private-example-password',
        ])->assertRedirect($page)->assertSessionHas('status', '2 servers saved. Host identity and OS still need verification.');
        $this->assertSame(2, ManagedServer::query()->count());
        $this->assertSame(2, ManagedServer::query()->where('ssh_username', 'mailer')->count());
        $this->get($page)->assertOk()->assertSee('159.223.97.39')->assertSee('159.223.97.40');
    }

    public function test_server_registration_encrypts_an_ssh_private_key_as_a_credential(): void
    {
        $privateKey = "-----BEGIN OPENSSH PRIVATE KEY-----\nsynthetic-test-key\n-----END OPENSSH PRIVATE KEY-----";
        $created = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($this->url('/servers'), [
            'name' => 'Key-auth relay', 'provider' => 'Example provider',
            'ip_address' => '159.223.97.39', 'ssh_username' => 'mailer',
            'ssh_private_key' => $privateKey,
        ])->assertCreated();

        $credential = ServerCredential::query()->firstOrFail();
        $this->assertSame('private_key', $credential->credential_type);
        $this->assertSame($privateKey, Crypt::decryptString($credential->encrypted_secret));
        $this->assertStringNotContainsString($privateKey, $created->getContent());
    }

    public function test_domain_must_be_verified_before_plan_and_plan_never_enables_install(): void
    {
        $domain = $this->postJson($this->url('/domains'), ['name' => 'Mail.Example.Org'])
            ->assertCreated()->json('data');
        $this->assertSame('mail.example.org', $domain['name']);
        $server = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url('/servers'), [
                'name' => 'Relay', 'provider' => 'Example provider',
                'ip_address' => '159.223.97.39', 'root_password' => 'private-example-password',
            ])->assertCreated()->json('data');

        $planData = [
            'server_id' => $server['id'], 'domain_id' => $domain['id'], 'pmta_version' => '4.0',
            'options' => ['tracking' => true, 'dkim' => true, 'spf' => true],
        ];
        $this->postJson($this->url('/installation-plans'), $planData)->assertUnprocessable();

        $resolver = Mockery::mock(DomainTxtResolver::class);
        $resolver->shouldReceive('records')->once()
            ->with('_alphalink-verify.mail.example.org')
            ->andReturn([$domain['verification_value']]);
        $this->app->instance(DomainTxtResolver::class, $resolver);
        $this->postJson($this->url('/domains/'.$domain['id'].'/verify'))->assertOk()
            ->assertJsonPath('verified', true);
        $this->assertSame('verified', SendingDomain::query()->findOrFail($domain['id'])->state);

        $plan = $this->postJson($this->url('/installation-plans'), $planData)
            ->assertCreated()->assertJsonPath('data.preview.pmta.mandatory', true)
            ->assertJsonPath('data.preview.execution_enabled', false)
            ->assertJsonPath('data.options.tracking', true)
            ->assertJsonPath('data.options.proxy', false)
            ->json('data');
        $this->assertNotEmpty($plan['preview']['blockers']);
        $this->assertSame('blocked', $plan['state']);
        $this->postJson($this->url('/installation-plans/'.$plan['id'].'/execute'))->assertNotFound();
    }

    public function test_owner_scope_and_role_are_enforced(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)->getJson($this->url('/infrastructure'))->assertNotFound();
        $this->workspace->users()->attach($other->id, ['role' => 'operator']);
        $this->actingAs($other)->getJson($this->url('/infrastructure'))->assertForbidden();
        $this->actingAs($other)->postJson($this->url('/domains'), ['name' => 'example.org'])->assertForbidden();
    }

    public function test_host_key_must_match_before_root_authentication_and_preflight_records_only_facts(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $server = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url('/servers'), [
                'name' => 'Relay', 'provider' => 'Example provider',
                'ip_address' => '159.223.97.39', 'root_password' => 'private-example-password',
            ])->assertCreated()->json('data');
        $fingerprint = 'SHA256:'.str_repeat('A', 43);
        $wrong = 'SHA256:'.str_repeat('B', 43);
        $probe = Mockery::mock(SshHostProbe::class);
        $probe->shouldReceive('fingerprint')->once()->andReturn($fingerprint);
        $probe->shouldReceive('inspect')->once()
            ->withArgs(fn ($host, $password, $expected) => $host->id === $server['id']
                && $password === 'private-example-password' && $expected === $fingerprint)
            ->andReturn(['fingerprint' => $fingerprint, 'facts' => [
                'os_id' => 'almalinux', 'os_version' => '9.6', 'architecture' => 'x86_64',
                'package_manager' => 'dnf', 'disk_available_kb' => 10485760,
                'pmta_binary_present' => false,
            ]]);
        $this->app->instance(SshHostProbe::class, $probe);

        $this->postJson($this->url('/servers/'.$server['id'].'/host-key'))->assertOk()
            ->assertJsonPath('observed_fingerprint', $fingerprint);
        $this->postJson($this->url('/servers/'.$server['id'].'/preflight'), [
            'expected_host_fingerprint' => $wrong,
        ])->assertUnprocessable();
        $this->assertSame('pending_host_verification', ManagedServer::findOrFail($server['id'])->state);

        $result = $this->postJson($this->url('/servers/'.$server['id'].'/preflight'), [
            'expected_host_fingerprint' => $fingerprint,
        ])->assertOk()->assertJsonPath('data.state', 'verified')
            ->assertJsonPath('data.host_facts.os_id', 'almalinux');
        $this->assertStringNotContainsString('private-example-password', $result->getContent());
        $this->assertSame(1, AuditEvent::query()->where('action', 'server.host_key_observed')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'server.preflight_verified')->count());
    }

    public function test_browser_flow_renders_and_does_not_flash_root_password(): void
    {
        $page = '/app/workspaces/'.$this->workspace->id.'/infrastructure';
        $this->get($page)->assertOk()->assertSee('Server installation')->assertSee('PowerMTA 4.0r8');

        $this->post($page.'/servers', [
            'request_key' => (string) Str::uuid(),
            'name' => 'Relay', 'ip_address' => '159.223.97.39',
            'root_password' => 'private-example-password', 'ssh_private_key' => 'synthetic-private-key',
        ])->assertRedirect()->assertSessionHasErrors('provider');
        $this->assertArrayNotHasKey('root_password', session()->get('_old_input', []));
        $this->assertArrayNotHasKey('ssh_private_key', session()->get('_old_input', []));

        $this->post($page.'/servers', [
            'request_key' => (string) Str::uuid(),
            'name' => 'Relay', 'provider' => 'Example provider',
            'ip_address' => '159.223.97.39', 'ssh_port' => 22,
            'root_password' => 'private-example-password',
        ])->assertRedirect($page);

        $this->post($page.'/domains', ['name' => 'example.org'])->assertRedirect($page);
        $domain = SendingDomain::query()->firstOrFail();
        $this->get($page)->assertOk()
            ->assertSee('159.223.97.39')
            ->assertSee($domain->verification_token)
            ->assertSee('Check DNS record');

        $resolver = Mockery::mock(DomainTxtResolver::class);
        $resolver->shouldReceive('records')->once()->andReturn([$domain->verification_token]);
        $this->app->instance(DomainTxtResolver::class, $resolver);
        $this->post($page.'/domains/'.$domain->id.'/verify')->assertRedirect($page);

        $response = $this->post($page.'/plans', [
            'server_id' => ManagedServer::query()->firstOrFail()->id,
            'domain_id' => $domain->id,
            'pmta_version' => '5.0',
            'tracking' => '1', 'dkim' => '1',
        ]);
        $response->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()
            ->assertSee('Installation blocked')
            ->assertSee('Tracking + HTTPS')
            ->assertSee('Install server');
    }

    private function url(string $suffix): string
    {
        return '/api/v1/workspaces/'.$this->workspace->id.$suffix;
    }
}
