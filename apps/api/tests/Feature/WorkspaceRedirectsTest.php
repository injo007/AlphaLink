<?php

namespace Tests\Feature;

use App\Models\ManagedServer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('2 installed')
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
            'tracking_host' => 'visible.example.test',
        ]);

        $otherOwner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $otherWorkspace = $this->workspaceFor($otherOwner, 'Private redirects workspace');
        $this->server($otherWorkspace, $otherOwner, 'Private server', [
            'tracking' => true,
            'https' => true,
            'tracking_host' => 'private.example.test',
        ]);

        $this->actingAs($owner)
            ->get(route('console.redirects.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('visible.example.test')
            ->assertDontSee('private.example.test')
            ->assertDontSee('Private server');
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
}
