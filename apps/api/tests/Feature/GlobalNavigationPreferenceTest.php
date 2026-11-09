<?php

namespace Tests\Feature;

use App\Models\AppGlobalPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalNavigationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_persist_parent_assignments_for_the_global_navigation(): void
    {
        $admin = User::factory()->create(['type' => User::ADMIN, 'roles' => []]);
        $order = [
            'groups' => ['nav-audience', 'nav-campaigns'],
            'items' => [
                'nav-audience' => ['nav-item-create-0123456789ab'],
                'nav-campaigns' => ['nav-item-lists-abcdef012345'],
            ],
        ];

        $this->actingAs($admin)
            ->postJson(route('console.global-navigation.order.update'), $order)
            ->assertOk()
            ->assertJsonPath('message', 'Navigation saved for all users.');

        self::assertSame($order, AppGlobalPreference::query()->findOrFail('navigation_order')->value);
    }

    public function test_non_admin_cannot_change_the_global_navigation(): void
    {
        $member = User::factory()->create(['roles' => []]);

        $this->actingAs($member)
            ->postJson(route('console.global-navigation.order.update'), [
                'groups' => ['nav-audience'],
                'items' => ['nav-audience' => ['nav-item-create-0123456789ab']],
            ])
            ->assertForbidden();

        self::assertNull(AppGlobalPreference::query()->find('navigation_order'));
    }
}
