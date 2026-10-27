<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceOffer>
 */
class WorkspaceOfferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'sponsor_id' => WorkspaceSponsor::factory(),
            'offer_id' => (string) fake()->unique()->numberBetween(1000, 999999),
            'name' => fake()->words(3, true),
            'type' => strtolower(fake()->randomElement(WorkspaceOffer::TYPES)),
            'vertical' => 'insurance',
            'geo_target' => ['US'],
            'payout' => fake()->randomFloat(2, 0, 100),
            'redirect' => fake()->url(),
            'unsubscribe' => null,
            'note' => null,
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live',
            'allowed_users' => [],
            'is_active' => false,
        ];
    }
}
