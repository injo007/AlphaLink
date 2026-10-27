<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsGlobalUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_global_account_edit_it_change_password_disable_and_impersonate(): void
    {
        $admin = User::factory()->create(['type' => User::ADMIN, 'roles' => []]);
        $otherWorkspace = $this->workspaceFor(User::factory()->create());
        $unassignedAccount = User::factory()->create(['name' => 'Unassigned Account']);

        $this->actingAs($admin)->get(route('console.global-users.index'))
            ->assertOk()
            ->assertSee('GLOBAL ACCOUNT ADMINISTRATION')
            ->assertSee('ADD USER')
            ->assertSee('Unassigned Account')
            ->assertSee('USERNAME')
            ->assertDontSee('Login Email');

        $this->actingAs($admin)->getJson(route('console.global-users.index'))
            ->assertOk()
            ->assertJsonPath('data.0.username', $admin->username)
            ->assertJsonPath('data.2.full_name', $unassignedAccount->name);

        $created = $this->actingAs($admin)->postJson(route('console.global-users.store'), [
            'username' => 'NewMailer',
            'full_name' => 'New Global Mailer',
            'type' => User::MAILER,
            'roles' => ['manage_users', 'share_headers'],
        ])->assertOk()
            ->assertJsonPath('data', 'User created');

        $user = User::query()->where('username', 'newmailer')->sole();
        $credentials = $created->json('info');
        $temporaryPassword = explode(':', $credentials, 2)[1];
        $this->assertNull($user->email);
        $this->assertSame(['manage_users', 'share_headers'], $user->roles);
        $this->assertTrue(password_verify($temporaryPassword, $user->password));
        $this->assertFalse($otherWorkspace->users()->whereKey($user->getKey())->exists());

        $this->actingAs($admin)->putJson(route('console.global-users.update', $user), [
            'username' => 'UpdatedMailer',
            'full_name' => 'Updated Global Mailer',
            'type' => User::MAILER,
            'roles' => ['manage_users'],
        ])->assertOk()->assertJsonPath('data', 'User updated');
        $user->refresh();
        $this->assertSame('updatedmailer', $user->username);
        $this->assertSame('Updated Global Mailer', $user->name);
        $this->assertSame(['manage_users'], $user->roles);

        $this->actingAs($admin)->putJson(route('console.global-users.password.update', $user), [
            'password' => 'Updated-password-2026',
        ])->assertOk()->assertJsonPath('data', 'Changed successfully');
        $this->assertTrue(password_verify('Updated-password-2026', $user->fresh()->password));

        $this->actingAs($admin)->patchJson(route('console.global-users.status', $user), ['status' => false])
            ->assertOk()->assertJsonPath('data', 'Changed successfully');
        $this->assertFalse($user->fresh()->is_active);
        $this->actingAs($admin)->patchJson(route('console.global-users.status', $user), ['status' => true])
            ->assertOk();

        $this->actingAs($admin)->post(route('console.global-users.impersonation.start', $user))
            ->assertRedirect(route('console.index'));
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(session()->has('alphalink.impersonator_id'));

        $this->post(route('console.global-users.impersonation.leave'))
            ->assertRedirect(route('console.global-users.index'));
        $this->assertAuthenticatedAs($admin);
        $this->assertFalse(session()->has('alphalink.impersonator_id'));

        $this->post(route('logout'));
        $this->post(route('login.store'), ['username' => 'UPDATEDMAILER', 'password' => 'Updated-password-2026'])
            ->assertRedirect(route('console.dashboard.main'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_global_user_routes_allow_dms_role_and_deny_other_mailers(): void
    {
        $manager = User::factory()->create(['type' => User::MAILER, 'roles' => ['manage_users']]);
        $ordinary = User::factory()->create(['type' => User::MAILER, 'roles' => []]);

        $this->actingAs($manager)->get(route('console.global-users.index'))->assertOk();
        $this->actingAs($ordinary)->get(route('console.global-users.index'))->assertForbidden();
        $this->actingAs($ordinary)->postJson(route('console.global-users.store'), [
            'username' => 'blockeduser',
            'full_name' => 'Blocked Global User',
            'type' => User::MAILER,
        ])->assertForbidden();
    }

    public function test_global_user_creation_rejects_invalid_username_type_and_roles_and_admin_can_open_directory_without_workspace(): void
    {
        $admin = User::factory()->create(['type' => User::ADMIN, 'roles' => []]);

        $this->actingAs($admin)->postJson(route('console.global-users.store'), [
            'username' => 'invalid name',
            'full_name' => 'Bad',
            'type' => 'unknown',
            'roles' => ['not-a-role'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['username', 'full_name', 'type', 'roles.0']);

        $this->actingAs($admin)->get(route('console.global-users.index'))
            ->assertOk()
            ->assertSee('ACCOUNT DIRECTORY')
            ->assertSee('GLOBAL ACCOUNT ADMINISTRATION');

        $this->actingAs($admin)->patchJson(route('console.global-users.status', $admin), ['status' => false])
            ->assertUnauthorized();
    }

    public function test_only_admin_type_can_impersonate_active_mailer_accounts(): void
    {
        $manager = User::factory()->create(['type' => User::MAILER, 'roles' => ['manage_users']]);
        $target = User::factory()->create(['type' => User::MAILER]);

        $this->actingAs($manager)->post(route('console.global-users.impersonation.start', $target))->assertForbidden();
        $this->assertAuthenticatedAs($manager);

        $admin = User::factory()->create(['type' => User::ADMIN, 'roles' => []]);
        $disabled = User::factory()->create(['type' => User::MAILER, 'is_active' => false]);
        $this->actingAs($admin)->post(route('console.global-users.impersonation.start', $disabled))->assertForbidden();
        $this->assertAuthenticatedAs($admin);
    }

    private function workspaceFor(User $owner): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Global users workspace',
            'slug' => 'global-users-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);

        return $workspace;
    }
}
