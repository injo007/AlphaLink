<?php

namespace Tests\Feature;

use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerIpDomain;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceRedirectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirects_page_is_active_and_reports_workspace_tracking_status(): void
    {
        $owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $workspace = $this->workspaceFor($owner, 'Redirect status workspace');
        $this->server($workspace, $owner, 'Ready tracking server', [
            'tracking' => true,
            'https' => true,
            'tracking_https_ready' => true,
            'tracking_host' => 'https://track.example.test',
        ]);
        $this->server($workspace, $owner, 'Fallback server', [
            'tracking' => true,
            'https' => false,
            'tracking_host' => '192.0.2.44',
        ]);

        $response = $this->actingAs($owner)
            ->get(route('console.redirects.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('Redirects &amp; Rotators', false)
            ->assertSee(url('/redirects'), false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('track.example.test')
            ->assertSee('2 endpoints')
            ->assertSee('Tracking endpoints ready')
            ->assertSee('Destination rotation is not configured.')
            ->assertSee('HTTPS required')
            ->assertSee('Recent redirect activity');

        $response->assertSee('Ready')->assertSee('HTTPS required');
    }

    public function test_redirects_page_does_not_expose_another_workspaces_tracking_hosts(): void
    {
        $owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $workspace = $this->workspaceFor($owner, 'Visible redirects workspace');
        $this->server($workspace, $owner, 'Visible server', [
            'tracking' => true,
            'https' => true,
            'tracking_https_ready' => true,
            'tracking_host' => 'visible.example.test',
        ]);

        $otherOwner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $otherWorkspace = $this->workspaceFor($otherOwner, 'Private redirects workspace');
        $this->server($otherWorkspace, $otherOwner, 'Private server', [
            'tracking' => true,
            'https' => true,
            'tracking_https_ready' => true,
            'tracking_host' => 'private.example.test',
        ]);

        $this->actingAs($owner)
            ->get(route('console.redirects.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('visible.example.test')
            ->assertDontSee('private.example.test')
            ->assertDontSee('Private server');
    }

    public function test_redirects_page_lists_each_installed_domain_on_a_shared_ip_and_keeps_removed_endpoints_for_30_days(): void
    {
        $owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $workspace = $this->workspaceFor($owner, 'Multi-domain redirect workspace');
        $server = $this->server($workspace, $owner, 'Shared-IP server', ['tracking' => true, 'tracking_https_ready' => true]);
        $primaryDomain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'primary.example.test',
            'state' => 'verified',
            'verification_token' => 'token-primary-'.Str::random(8),
            'created_by' => $owner->getKey(),
            'status' => 'used',
            'is_our' => false,
            'is_active' => true,
        ]);
        $ip = ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $primaryDomain->getKey(),
            'ip' => $server->ip_address,
            'type' => 'ipv4',
            'dns' => 'primary.example.test',
            'is_active' => true,
        ]);

        foreach (['primary.example.test', 'ask.example.test', 'pro.example.test'] as $index => $hostname) {
            $domain = $index === 0 ? $primaryDomain : SendingDomain::query()->create([
                'workspace_id' => $workspace->getKey(),
                'name' => $hostname,
                'state' => 'verified',
                'verification_token' => 'token-'.$index.'-'.Str::random(8),
                'created_by' => $owner->getKey(),
                'status' => 'used',
                'is_our' => false,
                'is_active' => true,
            ]);
            ManagedServerIpDomain::query()->create([
                'workspace_id' => $workspace->getKey(),
                'managed_server_id' => $server->getKey(),
                'managed_server_ip_id' => $ip->getKey(),
                'sending_domain_id' => $domain->getKey(),
                'hostname' => $hostname,
                'tracking_host' => $hostname,
                'dkim_selector' => 'alpha',
                'is_primary' => $index === 0,
                'is_active' => $index !== 2,
                'tracking_https_ready' => true,
            ]);
        }

        $server->delete();
        $server->forceFill(['deleted_at' => now()->subDays(29)])->saveQuietly();

        $this->actingAs($owner)
            ->get(route('console.redirects.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('3 endpoints')
            ->assertSee('primary.example.test')
            ->assertSee('ask.example.test')
            ->assertSee('pro.example.test')
            ->assertSee('Retained after server removal')
            ->assertSee('30 days');

        $server->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();

        $this->get(route('console.redirects.index', $workspace->getKey()))
            ->assertOk()
            ->assertDontSee('primary.example.test')
            ->assertDontSee('ask.example.test')
            ->assertDontSee('pro.example.test');
    }

    public function test_prune_command_removes_only_returned_server_endpoint_data_after_30_days(): void
    {
        $owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $workspace = $this->workspaceFor($owner, 'Redirect retention workspace');
        $expired = $this->server($workspace, $owner, 'Expired server', [
            'tracking' => true, 'tracking_https_ready' => true, 'tracking_host' => 'expired.example.test',
        ]);
        $recent = $this->server($workspace, $owner, 'Recent server', [
            'tracking' => true, 'tracking_https_ready' => true, 'tracking_host' => 'recent.example.test',
        ]);
        $expiredIp = $this->serverIp($workspace, $expired, $owner, 'expired.example.test');
        $recentIp = $this->serverIp($workspace, $recent, $owner, 'recent.example.test');
        $this->assignment($workspace, $expired, $expiredIp, 'expired.example.test');
        $recentAssignment = $this->assignment($workspace, $recent, $recentIp, 'recent.example.test');
        $expired->delete();
        $recent->delete();
        $expired->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();
        $recent->forceFill(['deleted_at' => now()->subDays(29)])->saveQuietly();

        Artisan::call('servers:prune-returned-tracking-endpoints');

        $this->assertDatabaseMissing('managed_server_ip_domains', ['managed_server_id' => $expired->getKey()]);
        $this->assertDatabaseHas('managed_server_ip_domains', ['id' => $recentAssignment->getKey()]);
        $expiredAfterPrune = ManagedServer::withTrashed()->findOrFail($expired->getKey());
        $this->assertFalse((bool) $expiredAfterPrune->install_parameters['tracking']);
        $this->assertNull($expiredAfterPrune->install_parameters['tracking_host'] ?? null);
    }

    private function workspaceFor(User $user, string $name): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'created_by' => $user->getKey(),
        ]);
        $workspace->users()->attach($user->getKey(), ['role' => 'owner']);

        return $workspace;
    }

    /** @param array<string, mixed> $installParameters */
    private function server(Workspace $workspace, User $owner, string $name, array $installParameters): ManagedServer
    {
        return ManagedServer::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => $name,
            'provider' => 'Synthetic provider',
            'ip_address' => '192.0.2.'.random_int(10, 240),
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => true,
            'install_parameters' => $installParameters,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', $name),
            'created_by' => $owner->getKey(),
        ]);
    }

    private function serverIp(Workspace $workspace, ManagedServer $server, User $owner, string $hostname): ManagedServerIp
    {
        $domain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => $hostname,
            'state' => 'verified',
            'verification_token' => (string) Str::uuid(),
            'created_by' => $owner->getKey(),
            'status' => 'used',
            'is_our' => false,
            'is_active' => true,
        ]);

        return ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => $server->ip_address,
            'type' => 'ipv4',
            'dns' => $hostname,
            'is_active' => true,
        ]);
    }

    private function assignment(Workspace $workspace, ManagedServer $server, ManagedServerIp $ip, string $hostname): ManagedServerIpDomain
    {
        $domain = $workspace->sendingDomains()->where('name', $hostname)->firstOrFail();

        return ManagedServerIpDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $ip->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'hostname' => $hostname,
            'tracking_host' => $hostname,
            'dkim_selector' => 'alpha',
            'is_primary' => true,
            'is_active' => true,
            'tracking_https_ready' => true,
        ]);
    }
}
