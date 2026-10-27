<?php

namespace Tests\Feature;

use App\Models\ManagedServer;
use App\Models\ManagedServerHistory;
use App\Models\ManagedServerIp;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsMyServersTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_servers_is_a_separate_dms_page_in_the_pmta_navigation_group(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $member = User::factory()->create();
        $workspace->users()->attach($member->getKey(), ['role' => 'viewer']);

        $myServersUrl = route('console.my-servers.index', $workspace->getKey());
        $infrastructureServersUrl = route('console.infrastructure.servers.index', $workspace->getKey());
        $response = $this->actingAs($member)->get($myServersUrl)->assertOk();
        $html = (string) $response->getContent();

        self::assertNotSame($myServersUrl, $infrastructureServersUrl);
        self::assertStringContainsString('My Servers', $html);
        self::assertStringContainsString('id="nav-submenu-servers-12"', $html);
        self::assertStringContainsString('href="'.$myServersUrl.'"', $html);
        self::assertStringContainsString('data-source-url="'.$myServersUrl.'"', $html);
        self::assertStringContainsString('data-pmta-url="'.route('console.pmta-admin.index', $workspace->getKey()).'"', $html);
        self::assertStringContainsString('dms-reference/my-servers.js', $html);
    }

    public function test_my_servers_returns_only_active_installed_workspace_servers_and_dms_health_fields(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspace($owner);
        $workspace->users()->attach($member->getKey(), ['role' => 'viewer']);
        $visible = $this->server($workspace, $owner, '203.0.113.20');
        $this->server($workspace, $owner, '203.0.113.21', active: false);
        $this->server($workspace, $owner, '203.0.113.22', installed: false);

        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->workspace($otherOwner);
        $this->server($otherWorkspace, $otherOwner, '203.0.113.23');

        $domain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'fixture.example.test',
            'state' => 'verified',
            'verification_token' => (string) Str::uuid(),
            'created_by' => $owner->getKey(),
            'status' => 'new',
            'is_our' => false,
            'is_active' => true,
        ]);
        ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $visible->getKey(),
            'sending_domain_id' => $domain->getKey(), 'ip' => '203.0.113.24', 'type' => 'ipv4',
            'dns' => 'fixture.example.test', 'is_active' => true,
        ]);
        ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $visible->getKey(),
            'sending_domain_id' => $domain->getKey(), 'ip' => '203.0.113.25', 'type' => 'ipv4',
            'dns' => 'fixture.example.test', 'is_active' => false,
        ]);
        ManagedServerHistory::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $visible->getKey(),
            'is_ssh_down' => false, 'is_apache_down' => true, 'is_pmta_down' => false,
        ]);

        $response = $this->actingAs($member)
            ->getJson(route('console.my-servers.index', $workspace->getKey()))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->getKey())
            ->assertJsonPath('data.0.name', $visible->name)
            ->assertJsonPath('data.0.ips_count', 1)
            ->assertJsonPath('data.0.history.is_ssh_down', false)
            ->assertJsonPath('data.0.history.is_apache_down', true)
            ->assertJsonPath('data.0.history.is_pmta_down', false);

        self::assertArrayNotHasKey('ssh_username', $response->json('data.0'));
        self::assertArrayNotHasKey('expected_host_fingerprint', $response->json('data.0'));
    }

    public function test_my_servers_requires_membership_in_the_requested_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('console.my-servers.index', $workspace->getKey()))
            ->assertNotFound();
    }

    private function workspace(User $owner): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'My Servers fixture',
            'slug' => 'my-servers-'.Str::lower(Str::random(10)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);

        return $workspace;
    }

    private function server(
        Workspace $workspace,
        User $actor,
        string $ip,
        bool $active = true,
        bool $installed = true,
    ): ManagedServer {
        return $workspace->managedServers()->create([
            'name' => 'MY-SERVER-', 'provider' => 'Synthetic provider', 'ip_address' => $ip, 'ssh_port' => 22,
            'ssh_username' => 'fixture-user', 'state' => 'verified', 'expected_host_fingerprint' => 'SHA256:fixture',
            'is_active' => $active, 'is_installed' => $installed, 'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', Str::random(16)), 'created_by' => $actor->getKey(),
        ]);
    }
}
