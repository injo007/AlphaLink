<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInfrastructureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsCloudServerPasswordSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = $this->workspaceFor($this->owner, 'app-settings-one');
        $this->actingAs($this->owner);
    }

    public function test_cloud_server_password_is_in_dms_general_settings_and_install_link_uses_the_same_page(): void
    {
        $this->workspace->infrastructureSettings()->create([
            'cloud_servers_password' => 'synthetic-cloud-root-password',
        ]);

        $this->get($this->appSettingsPage())
            ->assertOk()
            ->assertSee('GENERAL SETTINGS')
            ->assertSee('Cloud Servers Password:')
            ->assertSee('id="cloud_servers_password"', false)
            ->assertSee('name="settings[cloud_servers_password]"', false)
            ->assertSee('type="password"', false)
            ->assertSee('placeholder="Saved; leave blank to keep"', false)
            ->assertDontSee('synthetic-cloud-root-password')
            ->assertDontSee('data-secret-toggle');

        $this->get($this->installSettingsPage())
            ->assertRedirect($this->appSettingsPage().'#settings');
    }

    public function test_owner_can_save_cloud_password_encrypted_with_redacted_audit(): void
    {
        $this->post($this->appSettingsPage(), [
            'cloud_servers_password' => 'synthetic-cloud-root-password',
        ])->assertRedirect($this->appSettingsPage().'#settings')
            ->assertSessionHas('status', 'Settings saved.');

        $settings = $this->workspace->infrastructureSettings()->sole();
        $this->assertSame('synthetic-cloud-root-password', $settings->cloud_servers_password);
        $this->assertNotSame('synthetic-cloud-root-password', $settings->getRawOriginal('cloud_servers_password'));
        $this->assertArrayNotHasKey('cloud_servers_password', $settings->toArray());

        $audit = AuditEvent::query()->where('action', 'workspace.app_settings_updated')->sole();
        $this->assertSame(['cloud_servers_password'], $audit->properties['secrets_updated']);
        $this->assertStringNotContainsString('synthetic-cloud-root-password', json_encode($audit->properties, JSON_THROW_ON_ERROR));
    }

    public function test_blank_app_settings_edit_preserves_saved_cloud_password(): void
    {
        $settings = $this->workspace->infrastructureSettings()->create([
            'cloud_servers_password' => 'saved-cloud-root-password',
        ]);

        $this->post($this->appSettingsPage(), ['cloud_servers_password' => ''])
            ->assertRedirect($this->appSettingsPage().'#settings');

        $this->assertSame('saved-cloud-root-password', $settings->fresh()->cloud_servers_password);
    }

    public function test_invalid_cloud_password_is_not_flashed_back_to_session(): void
    {
        $this->from($this->appSettingsPage())->post($this->appSettingsPage(), [
            'cloud_servers_password' => str_repeat('x', 4097),
        ])->assertRedirect($this->appSettingsPage())
            ->assertSessionHasErrors('settings.cloud_servers_password')
            ->assertSessionMissing('_old_input.cloud_servers_password');
    }

    public function test_workspace_members_can_view_server_settings_but_general_settings_stay_manager_only(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->workspaceFor($otherOwner, 'app-settings-two');

        $this->actingAs($operator)->get($this->appSettingsPage())
            ->assertOk()
            ->assertSee('SERVER / PMTA SETTINGS')
            ->assertSee('CLOUD SERVERS PASSWORD')
            ->assertDontSee('OPTIZMO TOKEN');
        $this->actingAs($operator)->post($this->appSettingsPage(), [])->assertForbidden();
        $this->actingAs($operator)
            ->post(route('console.infrastructure.install-settings.update', $this->workspace->getKey()), [
                'cloud_servers_password' => 'member-cloud-root-password',
            ])
            ->assertRedirect($this->appSettingsPage().'#settings');

        $this->assertSame('member-cloud-root-password', $this->workspace->infrastructureSettings()->sole()->cloud_servers_password);
        $this->actingAs($this->owner)
            ->get(route('console.app-settings.index', $otherWorkspace->getKey()))
            ->assertNotFound();
        $this->actingAs($this->owner)
            ->post(route('console.app-settings.update', $otherWorkspace->getKey()), [])
            ->assertNotFound();

        $settings = WorkspaceInfrastructureSettings::query()->sole();
        $this->assertSame($this->workspace->getKey(), $settings->workspace_id);
    }

    private function appSettingsPage(): string
    {
        return route('console.app-settings.index', $this->workspace->getKey());
    }

    private function installSettingsPage(): string
    {
        return route('console.infrastructure.install-settings.edit', $this->workspace->getKey());
    }

    private function workspaceFor(User $user, string $slug): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'App settings workspace',
            'slug' => $slug.'-'.Str::lower(Str::random(6)),
            'created_by' => $user->getKey(),
        ]);
        $workspace->users()->attach($user->getKey(), ['role' => 'owner']);

        return $workspace;
    }
}
