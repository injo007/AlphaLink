<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsTextMechanicTest extends TestCase
{
    use RefreshDatabase;

    public function test_text_mechanic_page_and_navigation_match_the_dms_surface(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $url = route('console.tools.text-mechanic', $workspace->getKey());

        $this->actingAs($owner)
            ->get($url)
            ->assertOk()
            ->assertSee('TEXT MECHANIC')
            ->assertSee('aria-controls="nav-submenu-tools-', false)
            ->assertSee('aria-expanded="true"', false)
            ->assertSee('href="'.$this->cleanWorkspaceUrl($url).'"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('id="asc"', false)
            ->assertSee('id="desc"', false)
            ->assertSee('id="length"', false)
            ->assertSee('id="random"', false)
            ->assertSee('id="rduplicate"', false)
            ->assertSee('id="ips"', false)
            ->assertSee('id="sall"', false)
            ->assertSee('id="copy"', false)
            ->assertSee('id="clear"', false)
            ->assertSee('id="text" class="form-control" wrap="off" rows="40"', false)
            ->assertSee('aria-label="Toggle full screen"', false)
            ->assertSee('alphalink/tools/text-mechanic.js', false);
    }

    public function test_text_mechanic_is_workspace_scoped_and_available_to_active_members(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $viewer = User::factory()->create();
        $workspace->users()->attach($viewer->getKey(), ['role' => 'viewer']);

        $this->actingAs($viewer)
            ->get(route('console.tools.text-mechanic', $workspace->getKey()))
            ->assertOk();

        $otherUser = User::factory()->create();
        $this->actingAs($otherUser)
            ->get(route('console.tools.text-mechanic', $workspace->getKey()))
            ->assertNotFound();

        $workspace->update(['status' => 'suspended']);
        $this->actingAs($owner)
            ->get(route('console.tools.text-mechanic', $workspace->getKey()))
            ->assertNotFound();
    }

    private function workspaceFor(User $user, string $role): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Tools workspace',
            'slug' => 'tools-'.Str::lower(Str::random(10)),
            'created_by' => $user->getKey(),
        ]);
        $workspace->users()->attach($user->getKey(), ['role' => $role]);

        return $workspace;
    }
}
