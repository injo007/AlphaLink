<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DmsProfilePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_open_profile_password_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('auth.profile'))
            ->assertOk()
            ->assertSee('Change your account password.')
            ->assertSee('name="current_password"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false);
    }

    public function test_guest_is_redirected_from_profile(): void
    {
        $this->get(route('auth.profile'))->assertRedirect(route('login'));
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = User::factory()->create(['password' => 'Current-password-2026']);

        $this->actingAs($user)->post(route('auth.change_password'), [
            'current_password' => 'incorrect-password',
            'password' => 'New-password-2026',
            'password_confirmation' => 'New-password-2026',
        ])->assertRedirect()
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('Current-password-2026', $user->fresh()->password));
    }

    public function test_password_change_rejects_the_current_password_and_invalid_replacements(): void
    {
        $user = User::factory()->create(['password' => 'Current-password-2026']);

        $this->actingAs($user)->post(route('auth.change_password'), [
            'current_password' => 'Current-password-2026',
            'password' => 'Current-password-2026',
            'password_confirmation' => 'Current-password-2026',
        ])->assertRedirect()
            ->assertSessionHasErrors('password');

        $this->post(route('auth.change_password'), [
            'current_password' => 'Current-password-2026',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->post(route('auth.change_password'), [
            'current_password' => 'Current-password-2026',
            'password' => 'Another-password-2026',
            'password_confirmation' => 'Not-the-same-2026',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('Current-password-2026', $user->fresh()->password));
    }

    public function test_successful_password_change_saves_hash_and_logs_user_out(): void
    {
        $user = User::factory()->create(['password' => 'Current-password-2026']);

        $this->actingAs($user)->post(route('auth.change_password'), [
            'current_password' => 'Current-password-2026',
            'password' => 'New-password-2026',
            'password_confirmation' => 'New-password-2026',
        ])->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertGuest();
        $this->assertTrue(Hash::check('New-password-2026', $user->fresh()->password));

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'New-password-2026',
        ])->assertRedirect(route('console.dashboard.main'));
    }

    public function test_sign_in_invalidates_other_password_sessions_and_keeps_the_current_session(): void
    {
        $user = User::factory()->create(['password' => 'Current-password-2026']);
        $oldPasswordHash = $user->password;

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'Current-password-2026',
        ])->assertRedirect(route('console.dashboard.main'));

        $this->assertNotSame($oldPasswordHash, $user->fresh()->password);
        $this->get(route('auth.profile'))->assertOk();

        $stalePasswordHash = Auth::guard()->hashPasswordForCookie($oldPasswordHash);
        $this->withSession(['password_hash_web' => $stalePasswordHash])
            ->actingAs($user->fresh())
            ->get(route('auth.profile'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
