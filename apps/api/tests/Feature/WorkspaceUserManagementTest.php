<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_admin_can_create_edit_disable_and_change_a_member_password(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $index = route('console.users.index', $workspace->getKey());

        $this->actingAs($owner)->get($index)
            ->assertOk()
            ->assertSee('USERS MANAGEMENT')
            ->assertSee('ADD USER')
            ->assertSee('WORKSPACE ROLE');

        $created = $this->actingAs($owner)->postJson(route('console.users.store', $workspace->getKey()), [
            'email' => 'new.member@example.test',
            'username' => 'newmember',
            'name' => 'New Workspace Member',
            'type' => 'mailer',
            'roles' => ['manage_users', 'manage_servers'],
            'workspace_role' => 'operator',
        ])->assertOk()
            ->assertJsonPath('data', 'User created')
            ->assertJsonPath('login_email', 'new.member@example.test');

        $member = User::query()->where('email', 'new.member@example.test')->sole();
        $this->assertSame('newmember', $member->username);
        $this->assertSame('mailer', $member->type);
        $this->assertSame(['manage_users', 'manage_servers'], $member->roles);
        $this->assertTrue(password_verify(explode(':', $created->json('info'), 2)[1], $member->password));
        $this->assertSame('operator', $workspace->users()->whereKey($member->getKey())->firstOrFail()->pivot->role);

        $this->actingAs($owner)->putJson(route('console.users.update', [$workspace->getKey(), $member->getKey()]), [
            'username' => 'memberedited',
            'name' => 'Edited Workspace Member',
            'type' => 'admin',
            'roles' => [],
            'workspace_role' => 'viewer',
        ])->assertOk()->assertJsonPath('data', 'User updated');

        $member->refresh();
        $this->assertSame('memberedited', $member->username);
        $this->assertSame('admin', $member->type);
        $this->assertSame([], $member->roles);
        $this->assertSame('viewer', $workspace->users()->whereKey($member->getKey())->firstOrFail()->pivot->role);

        $this->actingAs($owner)->putJson(route('console.users.password.update', [$workspace->getKey(), $member->getKey()]), [
            'password' => 'Updated-password-2026',
        ])->assertOk()->assertJsonPath('data', 'Changed successfully');
        $this->assertTrue(password_verify('Updated-password-2026', $member->fresh()->password));

        $this->actingAs($owner)->patchJson(route('console.users.status', [$workspace->getKey(), $member->getKey()]), [
            'status' => false,
        ])->assertOk()->assertJsonPath('data', 'Changed successfully');
        $this->assertFalse($member->fresh()->is_active);
        $member->refresh();
        $this->actingAs($member)->get($index)->assertRedirect(route('login'));
    }

    public function test_only_workspace_owners_and_admins_can_manage_workspace_users_and_members_cannot_disable_themselves(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $operator = User::factory()->create();
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);

        $this->actingAs($operator)->get(route('console.users.index', $workspace->getKey()))->assertForbidden();
        $this->actingAs($operator)->postJson(route('console.users.store', $workspace->getKey()), [
            'email' => 'blocked@example.test',
            'username' => 'blockeduser',
            'name' => 'Blocked New User',
            'type' => 'mailer',
            'workspace_role' => 'operator',
        ])->assertForbidden();

        $this->actingAs($owner)->patchJson(route('console.users.status', [$workspace->getKey(), $owner->getKey()]), [
            'status' => false,
        ])->assertUnauthorized();

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('console.users.index', $workspace->getKey()))->assertNotFound();
    }

    public function test_dms_user_creation_validation_and_active_account_login_guard(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $url = route('console.users.store', $workspace->getKey());

        $this->actingAs($owner)->postJson($url, [
            'email' => 'bad@example.test',
            'username' => 'no spaces',
            'name' => 'Too Short',
            'type' => 'unknown',
            'roles' => ['not-a-role'],
            'workspace_role' => 'superuser',
        ])->assertUnprocessable()->assertJsonValidationErrors(['username', 'type', 'roles.0', 'workspace_role']);

        $disabled = User::factory()->create(['is_active' => false]);
        Auth::logout();
        $this->post(route('login.store'), ['email' => $disabled->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
    }

    public function test_workspace_admin_can_attach_a_username_only_global_account(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $account = User::factory()->create([
            'name' => 'Global Mailer Account',
            'email' => null,
            'username' => 'globalmailer',
            'type' => User::MAILER,
        ]);

        $this->actingAs($owner)->get(route('console.users.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('Add an existing account')
            ->assertSee('globalmailer');

        $this->actingAs($owner)->postJson(route('console.users.attach', $workspace->getKey()), [
            'user_id' => $account->getKey(),
            'workspace_role' => 'operator',
        ])->assertOk()->assertJsonPath('data', 'Account added to workspace');

        $this->assertSame('operator', $workspace->users()->whereKey($account->getKey())->firstOrFail()->pivot->role);
        $this->assertNull($account->fresh()->email);

        $this->actingAs($owner)->postJson(route('console.users.attach', $workspace->getKey()), [
            'user_id' => $account->getKey(),
            'workspace_role' => 'viewer',
        ])->assertConflict();
    }

    private function workspaceFor(User $owner): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'User management workspace',
            'slug' => 'users-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);

        return $workspace;
    }
}
