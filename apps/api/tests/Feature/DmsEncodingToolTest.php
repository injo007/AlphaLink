<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsEncodingToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_encoding_tool_renders_from_dms_tools_navigation_and_shows_both_modes(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $url = route('console.tools.encoding.index', $workspace->getKey());

        $this->actingAs($owner)->get($url)
            ->assertOk()
            ->assertSee('ENCODING')
            ->assertSee('aria-controls="nav-submenu-tools-', false)
            ->assertSee('href="'.$url.'"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('Quoted-Printable')
            ->assertSee('Hex-Quoted-Printable')
            ->assertSee('UTF-7')
            ->assertSee('encoding-ip-run')
            ->assertSee('Hexadecimal IP');
    }

    public function test_string_encoding_matches_dms_schemas_and_unknown_schema_fallback(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $url = route('console.tools.encoding.encode', $workspace->getKey());

        $this->actingAs($owner)->postJson($url, ['string' => 'Café', 'schema' => 'B', 'charset' => 'UTF-8'])
            ->assertOk()->assertExactJson(['=?UTF-8?B?Q2Fmw6k=?=']);
        $this->actingAs($owner)->postJson($url, ['string' => 'A', 'schema' => 'HQ', 'charset' => 'UTF-8'])
            ->assertOk()->assertExactJson(['=?UTF-8?Q?=41?=']);
        $quotedPrintable = $this->actingAs($owner)->postJson($url, ['string' => 'Café', 'schema' => 'Q', 'charset' => 'UTF-8'])
            ->assertOk()->json();
        $this->assertIsString($quotedPrintable);
        $this->assertStringContainsString('=?UTF-8?Q?Caf=C3=A9?=', $quotedPrintable);
        $unboundedInput = str_repeat('x', 10001);
        $this->actingAs($owner)->postJson($url, [
            'string' => $unboundedInput,
            'schema' => 'unrecognized-schema',
            'charset' => 'unrecognized-charset',
        ])->assertOk()->assertExactJson([$unboundedInput]);
    }

    public function test_encoding_tool_is_available_to_workspace_members_only(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $member = User::factory()->create();
        $workspace->users()->attach($member->getKey(), ['role' => 'viewer']);

        $this->actingAs($member)->get(route('console.tools.encoding.popup', $workspace->getKey()))->assertOk();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('console.tools.encoding.index', $workspace->getKey()))->assertNotFound();
    }

    private function workspaceFor(User $owner): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Encoding workspace',
            'slug' => 'encoding-'.Str::lower(Str::random(10)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);

        return $workspace;
    }
}
