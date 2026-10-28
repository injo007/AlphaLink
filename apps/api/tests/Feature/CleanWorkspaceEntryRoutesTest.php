<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CleanWorkspaceEntryRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_redirects_to_the_dms_dashboard_path(): void
    {
        $user = User::factory()->create(['email' => 'clean-routes@example.test', 'password' => 'test-password']);
        $workspace = Workspace::query()->create([
            'name' => 'Clean Routes Workspace',
            'slug' => 'clean-routes-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'created_by' => $user->getKey(),
        ]);
        $workspace->users()->attach($user->getKey(), ['role' => 'owner', 'joined_at' => now()]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'test-password'])
            ->assertRedirect('/dashboard/main');
    }

    public function test_clean_dashboard_and_server_routes_do_not_expose_workspace_ids(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Clean Routes Workspace',
            'slug' => 'clean-routes-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'created_by' => $user->getKey(),
        ]);
        $workspace->users()->attach($user->getKey(), ['role' => 'owner', 'joined_at' => now()]);

        $dashboard = $this->actingAs($user)->get('/dashboard/main')
            ->assertOk()
            ->assertSee('Main Dashboard')
            ->assertDontSee('WORKSPACE OVERVIEW')
            ->assertViewHas('eventTotals');

        $dashboard->assertDontSee('/app/workspaces/'.$workspace->getKey().'/');
        $dashboard->assertSee('/drops/monitoring');
        $dashboard->assertSee('/infrastructure/list-history');

        $this->get('/infrastructure/servers')
            ->assertOk()
            ->assertSee('SERVERS');

        $this->assertSame($workspace->getKey(), session('active_workspace_id'));
    }

    public function test_null_dashboard_legacy_url_recovers_to_the_active_workspace_dashboard(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Clean Routes Workspace',
            'slug' => 'clean-routes-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'created_by' => $user->getKey(),
        ]);
        $workspace->users()->attach($user->getKey(), ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($user)->get('/null/dashboard')
            ->assertRedirect('/dashboard/main');

        $this->get('/dashboard/main')
            ->assertOk()
            ->assertSee('Main Dashboard');

        $this->assertSame($workspace->getKey(), session('active_workspace_id'));
    }

    public function test_legacy_browser_page_urls_redirect_to_clean_paths_but_json_routes_remain_usable(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Clean Routes Workspace',
            'slug' => 'clean-routes-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'created_by' => $user->getKey(),
        ]);
        $workspace->users()->attach($user->getKey(), ['role' => 'owner', 'joined_at' => now()]);
        $legacyServersUrl = '/app/workspaces/'.$workspace->getKey().'/infrastructure/servers';

        $this->actingAs($user)->getLegacyWorkspacePage($legacyServersUrl)
            ->assertRedirect('/infrastructure/servers');

        $this->getJson($legacyServersUrl)->assertOk()->assertJsonStructure(['data']);
    }
}
