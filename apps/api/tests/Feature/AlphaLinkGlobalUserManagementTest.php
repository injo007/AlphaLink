<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlphaLinkGlobalUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_global_account_edit_it_change_password_disable_and_impersonate(): void
    {
        $admin = User::factory()->create(['type' => User::ADMIN, 'roles' => []]);
        $otherWorkspace = $this->workspaceFor(User::factory()->create());
        $unassignedAccount = User::factory()->create(['name' => 'Unassigned Account']);

        $this->actingAs($admin)->get(route('console.global-users.index'))
            ->assertOk()
            ->assertSee('Global Users & System Operators')
            ->assertSee('ADD USER')
            ->assertSee('Unassigned Account')
            ->assertSee('USERNAME')
            ->assertDontSee('Login Email');

        $this->actingAs($admin)->getJson(route('console.global-users.index'))
            ->assertOk()
            ->assertJsonPath('data.0.username', $admin->username)
            ->assertJsonPath('data.2.full_name', $unassignedAccount->name);

        $this->actingAs($admin)->get(route('console.global-users.create'))
            ->assertOk()
            ->assertSee('Workspace Access')
            ->assertSee('value="'.$otherWorkspace->getKey().'"', false);

        $created = $this->actingAs($admin)->postJson(route('console.global-users.store'), [
            'username' => 'NewMailer',
            'full_name' => 'New Global Mailer',
            'type' => User::MAILER,
            'workspace_id' => $otherWorkspace->getKey(),
            'access_role' => User::ACCESS_ROLE_OPERATOR,
            'permission_selection_submitted' => '1',
            'roles' => ['campaigns.view', 'campaigns.manage'],
        ])->assertOk()
            ->assertJsonPath('data', 'User created');

        $user = User::query()->where('username', 'newmailer')->sole();
        $credentials = $created->json('info');
        $temporaryPassword = explode(':', $credentials, 2)[1];
        $this->assertNull($user->email);
        $this->assertSame(['campaigns.view', 'campaigns.manage'], $user->roles);
        $this->assertTrue(password_verify($temporaryPassword, $user->password));
        $this->assertTrue($otherWorkspace->users()->whereKey($user->getKey())->exists());
        $this->assertSame(User::ACCESS_ROLE_OPERATOR, $user->workspaces()->firstOrFail()->pivot->role);

        $this->actingAs($admin)->putJson(route('console.global-users.update', $user), [
            'username' => 'UpdatedMailer',
            'full_name' => 'Updated Global Mailer',
            'type' => User::MAILER,
            'access_role' => User::ACCESS_ROLE_OPERATOR,
            'permission_selection_submitted' => '1',
            'roles' => ['campaigns.view'],
        ])->assertOk()->assertJsonPath('data', 'User updated');
        $user->refresh();
        $this->assertSame('UpdatedMailer', $user->username);
        $this->assertSame('Updated Global Mailer', $user->name);
        $this->assertSame(['campaigns.view'], $user->roles);

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
            ->assertRedirect(route('console.mailer.campaigns'));
        $this->assertAuthenticatedAs($user);
        $this->get(route('console.mailer.campaigns'))->assertOk();
    }

    public function test_global_user_creation_rejects_inactive_workspaces(): void
    {
        $admin = User::factory()->create([
            'type' => User::ADMIN,
            'access_role' => User::ACCESS_ROLE_ADMIN,
            'roles' => [],
        ]);
        $inactiveWorkspace = Workspace::query()->create([
            'name' => 'Inactive Workspace',
            'slug' => 'inactive-'.Str::lower(Str::random(8)),
            'status' => 'inactive',
            'created_by' => $admin->getKey(),
        ]);

        $this->actingAs($admin)->postJson(route('console.global-users.store'), [
            'username' => 'OutsideWorkspace',
            'full_name' => 'Outside Workspace User',
            'type' => User::MAILER,
            'workspace_id' => $inactiveWorkspace->getKey(),
            'access_role' => User::ACCESS_ROLE_OPERATOR,
            'permission_selection_submitted' => '1',
            'roles' => ['campaigns.view'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['workspace_id']);

        $this->assertDatabaseMissing('users', ['username' => 'outsideworkspace']);
    }

    public function test_global_account_creation_assigns_a_workspace_for_each_account_type(): void
    {
        $owner = User::factory()->create([
            'type' => User::ADMIN,
            'access_role' => User::ACCESS_ROLE_OWNER,
            'roles' => [],
        ]);
        $workspace = $this->workspaceFor($owner);

        foreach (User::TYPES as $index => $type) {
            $username = 'NewAccount'.chr(65 + $index);
            $roles = $type === User::MAILER ? ['campaigns.view'] : ['dashboard.view'];
            $created = $this->actingAs($owner)->postJson(route('console.global-users.store'), [
                'username' => $username,
                'full_name' => 'New Account '.$type,
                'type' => $type,
                'workspace_id' => $workspace->getKey(),
                'access_role' => User::ACCESS_ROLE_OPERATOR,
                'permission_selection_submitted' => '1',
                'roles' => $roles,
            ])->assertOk()->assertJsonPath('data', 'User created');

            $user = User::query()->where('username', strtolower($username))->sole();
            $this->assertDatabaseHas('workspace_user', [
                'user_id' => $user->getKey(),
                'workspace_id' => $workspace->getKey(),
                'role' => User::ACCESS_ROLE_OPERATOR,
            ]);
        }
    }

    public function test_owner_can_soft_delete_admin_and_mailer_accounts_without_removing_workspace_history(): void
    {
        $owner = User::factory()->create([
            'type' => User::ADMIN,
            'access_role' => User::ACCESS_ROLE_OWNER,
            'roles' => [],
        ]);
        $workspace = $this->workspaceFor($owner);
        $admin = User::factory()->create([
            'username' => 'deleteadmin',
            'type' => User::ADMIN,
            'access_role' => User::ACCESS_ROLE_ADMIN,
            'roles' => [],
        ]);
        $mailer = User::factory()->create([
            'username' => 'deletemailer',
            'type' => User::MAILER,
            'access_role' => User::ACCESS_ROLE_OPERATOR,
            'roles' => ['campaigns.view'],
            'password' => 'Deleted-Mailer-Password-2026',
        ]);
        $workspace->users()->attach($admin->getKey(), ['role' => User::ACCESS_ROLE_ADMIN]);
        $workspace->users()->attach($mailer->getKey(), ['role' => User::ACCESS_ROLE_OPERATOR]);

        $this->actingAs($owner)->get(route('console.global-users.index'))
            ->assertOk()
            ->assertSee('DELETE USER')
            ->assertSee('data-user-row="'.$admin->getKey().'"', false)
            ->assertSee('data-user-row="'.$mailer->getKey().'"', false);

        foreach ([$admin, $mailer] as $target) {
            $this->deleteJson(route('console.global-users.destroy', $target))
                ->assertOk()
                ->assertJsonPath('data', 'User deleted');

            $this->assertSoftDeleted('users', ['id' => $target->getKey()]);
            $this->assertDatabaseHas('users', [
                'id' => $target->getKey(),
                'is_active' => false,
            ]);
            $this->assertDatabaseHas('workspace_user', [
                'user_id' => $target->getKey(),
                'workspace_id' => $workspace->getKey(),
            ]);
        }

        $this->actingAs($owner)->get(route('console.global-users.index'))
            ->assertOk()
            ->assertDontSee('deleteadmin')
            ->assertDontSee('deletemailer');
        $this->assertNull(User::query()->find($mailer->getKey()));

        $this->actingAs($owner)->postJson(route('console.global-users.store'), [
            'username' => 'deletemailer',
            'full_name' => 'Replacement Deleted Mailer',
            'type' => User::MAILER,
            'workspace_id' => $workspace->getKey(),
            'access_role' => User::ACCESS_ROLE_OPERATOR,
            'permission_selection_submitted' => '1',
            'roles' => ['campaigns.view'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['username']);

        $this->post(route('logout'));
        $this->post(route('login.store'), [
            'username' => 'deletemailer',
            'password' => 'Deleted-Mailer-Password-2026',
        ])->assertSessionHasErrors('username');
    }

    public function test_only_owner_can_delete_users_and_owner_cannot_delete_self_or_another_owner(): void
    {
        $owner = User::factory()->create([
            'type' => User::ADMIN,
            'access_role' => User::ACCESS_ROLE_OWNER,
            'roles' => [],
        ]);
        $anotherOwner = User::factory()->create([
            'type' => User::ADMIN,
            'access_role' => User::ACCESS_ROLE_OWNER,
            'roles' => [],
        ]);
        $admin = User::factory()->create([
            'type' => User::ADMIN,
            'access_role' => User::ACCESS_ROLE_ADMIN,
            'roles' => [],
        ]);

        $this->actingAs($admin)->get(route('console.global-users.index'))
            ->assertOk()
            ->assertDontSee('DELETE USER');
        $this->actingAs($admin)->deleteJson(route('console.global-users.destroy', $anotherOwner))
            ->assertForbidden();
        $this->actingAs($owner)->deleteJson(route('console.global-users.destroy', $owner))
            ->assertConflict();
        $this->deleteJson(route('console.global-users.destroy', $anotherOwner))
            ->assertConflict();
        $this->deleteJson(route('console.global-users.destroy', $admin))
            ->assertOk();

        $this->assertNotSoftDeleted('users', ['id' => $owner->getKey()]);
        $this->assertNotSoftDeleted('users', ['id' => $anotherOwner->getKey()]);
        $this->assertSoftDeleted('users', ['id' => $admin->getKey()]);
    }

    public function test_global_user_routes_allow_reference_role_and_deny_other_mailers(): void
    {
        $manager = User::factory()->create([
            'type' => User::MAILER,
            'access_role' => User::ACCESS_ROLE_ADMIN,
            'roles' => ['campaigns.view'],
        ]);
        $ordinary = User::factory()->create([
            'type' => User::MAILER,
            'access_role' => User::ACCESS_ROLE_OPERATOR,
            'roles' => [],
        ]);

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
        $admin = User::factory()->create(['type' => User::ADMIN, 'access_role' => User::ACCESS_ROLE_ADMIN, 'roles' => []]);

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

    public function test_usernames_preserve_edited_spelling_and_remain_case_insensitively_unique(): void
    {
        $admin = User::factory()->create(['type' => User::ADMIN, 'roles' => []]);
        $original = User::factory()->create(['username' => 'OriginalMailer', 'access_role' => User::ACCESS_ROLE_OPERATOR]);
        $other = User::factory()->create(['username' => 'OtherMailer', 'access_role' => User::ACCESS_ROLE_OPERATOR]);

        $this->actingAs($admin)->putJson(route('console.global-users.update', $original), [
            'username' => 'ORIGINALMAILER',
            'full_name' => 'Updated Original Mailer',
            'type' => User::MAILER,
            'access_role' => User::ACCESS_ROLE_OPERATOR,
            'permission_selection_submitted' => '1',
        ])->assertOk();

        $this->assertSame('ORIGINALMAILER', $original->fresh()->username);

        $this->putJson(route('console.global-users.update', $other), [
            'username' => 'originalmailer',
            'full_name' => 'Updated Other Mailer',
            'type' => User::MAILER,
        ])->assertUnprocessable()->assertJsonValidationErrors(['username']);

        $this->postJson(route('console.global-users.store'), [
            'username' => 'ORIGINALMAILER',
            'full_name' => 'Duplicate Global Mailer',
            'type' => User::MAILER,
        ])->assertUnprocessable()->assertJsonValidationErrors(['username']);
    }

    public function test_username_index_migration_refuses_to_apply_over_existing_case_collisions(): void
    {
        DB::statement('DROP INDEX users_username_case_insensitive_unique');
        User::factory()->create(['username' => 'CaseCollision']);
        User::factory()->create(['username' => 'casecollision']);

        $migration = require database_path('migrations/2026_10_28_140000_add_case_insensitive_username_index.php');

        $this->expectException(\RuntimeException::class);
        $migration->up();
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
