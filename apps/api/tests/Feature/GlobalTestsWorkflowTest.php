<?php

namespace Tests\Feature;

use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerMta;
use App\Models\PmtaVirtualMta;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGlobalTestLog;
use App\Services\Campaigns\DmsGlobalTestReverseDnsResolver;
use App\Services\Campaigns\DmsGlobalTestRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class GlobalTestsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = Mockery::mock(DmsGlobalTestReverseDnsResolver::class);
        $resolver->shouldReceive('resolve')->andReturnUsing(fn (string $ip, string $fallback): string => $fallback);
        $this->app->instance(DmsGlobalTestReverseDnsResolver::class, $resolver);
    }

    public function test_global_test_page_uses_dms_navigation_and_exposes_the_test_controls(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');

        $this->actingAs($owner)
            ->get(route('console.global-tests.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('GLOBAL TESTS')
            ->assertSee('Select Header')
            ->assertSee('PLACEHOLDERS')
            ->assertSee('SEND TEST')
            ->assertSee(route('console.global-tests.histories', $workspace->getKey()), false)
            ->assertSee('global-tests.js');
    }

    public function test_catalog_returns_only_active_installed_server_ip_options_from_the_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.40');
        $domain = $this->domain($workspace, $owner, 'mta.example.test');
        $ip = $this->ip($workspace, $server, $domain, '203.0.113.41', 'mta.example.test');
        $otherServer = $this->server($workspace, $owner, '203.0.113.50', ['is_installed' => false]);
        $otherDomain = $this->domain($workspace, $owner, 'offline.example.test');
        $this->ip($workspace, $otherServer, $otherDomain, '203.0.113.51', 'offline.example.test');

        $this->actingAs($owner)
            ->getJson(route('console.global-tests.catalog', [
                $workspace->getKey(),
                'servers' => [$server->getKey()],
                'option' => 'ip',
                'type' => 'ipv4',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'servers')
            ->assertJsonPath('servers.0.id', $server->getKey())
            ->assertJsonCount(1, 'ips')
            ->assertJsonPath('ips.0.id', $ip->getKey())
            ->assertJsonPath('ips.0.dns', 'mta.example.test');
    }

    public function test_sender_output_is_saved_with_the_dms_payload_and_returned_without_page_reload(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.40');
        $domain = $this->domain($workspace, $owner, 'mta.example.test');
        $ip = $this->ip($workspace, $server, $domain, '203.0.113.41', 'mta.example.test');
        $encodedHeader = base64_encode("MIME-version: 1.0\nTo: [to]");

        $runner = Mockery::mock(DmsGlobalTestRunner::class);
        $runner->shouldReceive('isConfigured')->once()->andReturn(true);
        $runner->shouldReceive('run')
            ->once()
            ->with(Mockery::on(fn (array $configuration): bool => $configuration['workspaceId'] === $workspace->getKey()
                && $configuration['repeat'] === 3
                && $configuration['header'] === $encodedHeader
                && $configuration['servers'][0]['ips'][0]['idi'] === $ip->getKey()
                && $configuration['servers'][0]['ips'][0]['vmta'] === 'mta-203.0.113.41'
            ))
            ->andReturn(['configured' => true, 'output' => 'Accepted by local sender.', 'error' => '', 'exit_code' => 0]);
        $this->app->instance(DmsGlobalTestRunner::class, $runner);

        $this->actingAs($owner)
            ->postJson(route('console.global-tests.test', $workspace->getKey()), [
                'return_path' => '[rnd]@[domain]',
                'email_test' => ['test@example.test'],
                'header' => $encodedHeader,
                'body' => base64_encode('<p>[ip]</p>'),
                'option' => 'ip',
                'type' => 'ipv4',
                'servers' => [$server->getKey()],
                'ips' => [$ip->getKey()],
                'placeholders' => [['data' => ['alpha'], 'rotate_every' => 1]],
                'repeat' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data', 'Accepted by local sender.')
            ->assertJsonStructure(['log_id']);

        $log = WorkspaceGlobalTestLog::query()->sole();
        $this->assertSame($workspace->getKey(), $log->workspace_id);
        $this->assertSame($owner->getKey(), $log->actor_user_id);
        $this->assertSame(['test@example.test'], $log->data['email_tests']);
        $this->assertSame($encodedHeader, $log->data['header']);
        $this->assertSame('ip', $log->data['mta']);
        $this->assertSame('Accepted by local sender.', $log->output);
    }

    public function test_no_sender_output_does_not_create_a_history_row(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.40');
        $domain = $this->domain($workspace, $owner, 'mta.example.test');
        $ip = $this->ip($workspace, $server, $domain, '203.0.113.41', 'mta.example.test');

        $runner = Mockery::mock(DmsGlobalTestRunner::class);
        $runner->shouldReceive('isConfigured')->once()->andReturn(true);
        $runner->shouldReceive('run')->once()->andReturn([
            'configured' => true,
            'output' => '',
            'error' => 'PMTA did not return output.',
            'exit_code' => 1,
        ]);
        $this->app->instance(DmsGlobalTestRunner::class, $runner);

        $this->actingAs($owner)
            ->postJson(route('console.global-tests.test', $workspace->getKey()), $this->validRequest($server, $ip))
            ->assertStatus(400)
            ->assertJsonPath('data', 'Could Not Send PMTA did not return output.');

        $this->assertDatabaseCount('workspace_global_test_logs', 0);
    }

    public function test_vmta_and_smtp_payloads_match_the_dms_identifier_and_name_format(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.40');
        $domain = $this->domain($workspace, $owner, 'mta.example.test');
        $ip = $this->ip($workspace, $server, $domain, '203.0.113.41', 'mta.example.test');
        $vmta = $this->mta($workspace, $server, $ip, $owner, 'vmta', 'vmta-user');
        $smtp = $this->mta($workspace, $server, $ip, $owner, 'smtp', 'smtp-user');

        $runner = Mockery::mock(DmsGlobalTestRunner::class);
        $runner->shouldReceive('isConfigured')->twice()->andReturn(true);
        $runner->shouldReceive('run')->once()->with(Mockery::on(fn (array $configuration): bool => $configuration['servers'][0]['ips'][0]['idi'] === $ip->getKey()
            && $configuration['servers'][0]['ips'][0]['idd'] === 0
            && $configuration['servers'][0]['ips'][0]['vmta'] === 'vmta-203.0.113.41-'.$vmta->getKey().'-vmta-user'
        ))->andReturn(['configured' => true, 'output' => 'VMTA output.', 'error' => '', 'exit_code' => 0]);
        $runner->shouldReceive('run')->once()->with(Mockery::on(fn (array $configuration): bool => $configuration['servers'][0]['ips'][0]['idi'] === $ip->getKey()
            && $configuration['servers'][0]['ips'][0]['idd'] === 0
            && $configuration['servers'][0]['ips'][0]['vmta'] === 'smtp-203.0.113.41-'.$smtp->getKey()
        ))->andReturn(['configured' => true, 'output' => 'SMTP output.', 'error' => '', 'exit_code' => 0]);
        $this->app->instance(DmsGlobalTestRunner::class, $runner);

        foreach ([['vmta', $vmta], ['smtp', $smtp]] as [$route, $mta]) {
            $this->actingAs($owner)
                ->postJson(route('console.global-tests.test', $workspace->getKey()), array_replace(
                    $this->validRequest($server, $ip),
                    ['option' => $route, 'ips' => [$mta->getKey()]],
                ))
                ->assertOk();
        }
    }

    public function test_empty_placeholder_array_matches_dms_minimum_validation(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.40');
        $domain = $this->domain($workspace, $owner, 'mta.example.test');
        $ip = $this->ip($workspace, $server, $domain, '203.0.113.41', 'mta.example.test');
        $runner = Mockery::mock(DmsGlobalTestRunner::class);
        $this->app->instance(DmsGlobalTestRunner::class, $runner);

        $this->actingAs($owner)
            ->postJson(route('console.global-tests.test', $workspace->getKey()), array_replace(
                $this->validRequest($server, $ip),
                ['placeholders' => []],
            ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('placeholders');

        $this->assertDatabaseCount('workspace_global_test_logs', 0);
    }

    public function test_unconfigured_alpha_link_sender_fails_closed_without_creating_a_history_row(): void
    {
        config(['global-tests.sender_path' => null]);
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.40');
        $domain = $this->domain($workspace, $owner, 'mta.example.test');
        $ip = $this->ip($workspace, $server, $domain, '203.0.113.41', 'mta.example.test');
        $resolver = Mockery::mock(DmsGlobalTestReverseDnsResolver::class);
        $resolver->shouldNotReceive('resolve');
        $this->app->instance(DmsGlobalTestReverseDnsResolver::class, $resolver);

        $this->actingAs($owner)
            ->postJson(route('console.global-tests.test', $workspace->getKey()), $this->validRequest($server, $ip))
            ->assertStatus(503)
            ->assertJsonPath('data', 'Could Not Send The AlphaLink Global Test sender is not configured or is not executable.');

        $this->assertDatabaseCount('workspace_global_test_logs', 0);
    }

    public function test_operator_cannot_view_another_members_global_test_history(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $log = $workspace->globalTestLogs()->create([
            'actor_user_id' => $owner->getKey(),
            'data' => ['email_tests' => ['private@example.test'], 'servers' => [], 'ips' => []],
            'output' => 'Private sender output.',
        ]);

        $this->actingAs($operator)
            ->get(route('console.global-tests.histories', $workspace->getKey()))
            ->assertOk()
            ->assertDontSee('private@example.test');
        $this->get(route('console.global-tests.history', [$workspace->getKey(), $log->getKey()]))
            ->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function validRequest(ManagedServer $server, ManagedServerIp $ip): array
    {
        return [
            'return_path' => '[rnd]@[domain]',
            'email_test' => ['test@example.test'],
            'header' => base64_encode("MIME-version: 1.0\nTo: [to]"),
            'body' => base64_encode(''),
            'option' => 'ip',
            'type' => 'ipv4',
            'servers' => [$server->getKey()],
            'ips' => [$ip->getKey()],
            'placeholders' => [['data' => ['alpha'], 'rotate_every' => 1]],
            'repeat' => 1,
        ];
    }

    private function workspace(User $owner, string $role): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Global Test workspace',
            'slug' => 'global-test-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => $role]);

        return $workspace;
    }

    /** @param array<string, mixed> $overrides */
    private function server(Workspace $workspace, User $owner, string $ipAddress, array $overrides = []): ManagedServer
    {
        return $workspace->managedServers()->create(array_replace([
            'name' => 'Synthetic sender server',
            'provider' => 'Fixture provider',
            'ip_address' => $ipAddress,
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', $ipAddress),
            'created_by' => $owner->getKey(),
        ], $overrides));
    }

    private function domain(Workspace $workspace, User $owner, string $name): SendingDomain
    {
        return SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => $name,
            'state' => 'verified',
            'verification_token' => Str::random(32),
            'created_by' => $owner->getKey(),
            'status' => 'free',
            'is_our' => false,
            'is_active' => true,
        ]);
    }

    private function ip(Workspace $workspace, ManagedServer $server, SendingDomain $domain, string $address, string $dns): ManagedServerIp
    {
        return ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => $address,
            'type' => 'ipv4',
            'dns' => $dns,
            'is_active' => true,
        ]);
    }

    private function mta(Workspace $workspace, ManagedServer $server, ManagedServerIp $ip, User $owner, string $type, string $username): ManagedServerMta
    {
        $virtualMta = $type === 'vmta' ? PmtaVirtualMta::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $ip->getKey(),
            'created_by' => $owner->getKey(),
            'ip' => $ip->ip,
            'domain' => $ip->dns,
            'name' => $username,
            'duplicate_index' => 1,
            'route' => false,
            'is_active' => true,
        ]) : null;

        return ManagedServerMta::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $ip->getKey(),
            'ip' => $ip->ip,
            'dns' => $ip->dns,
            'ip_type' => 'ipv4',
            'mta_type' => $type,
            'username' => $username,
            'pmta_virtual_mta_id' => $virtualMta?->getKey(),
            'is_active' => true,
            'user_id' => $owner->getKey(),
        ]);
    }
}
