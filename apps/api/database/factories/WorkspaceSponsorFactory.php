<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceSponsor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceSponsor>
 */
class WorkspaceSponsorFactory extends Factory
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
            'name' => fake()->company(),
            'affiliate_id' => (string) fake()->unique()->numberBetween(1000, 999999),
            'url' => fake()->url(),
            'username' => fake()->userName(),
            'password' => fake()->password(8),
            'api_key' => fake()->sha256(),
            'type' => fake()->randomElement(WorkspaceSponsor::TYPES),
            'allowed_users' => [],
            'is_active' => true,
        ];
    }
}
