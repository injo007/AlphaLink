<?php

namespace Database\Factories;

use App\Models\WorkspaceDrop;
use App\Models\WorkspaceDropVisitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceDropVisitor>
 */
class WorkspaceDropVisitorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_drop_id' => WorkspaceDrop::factory(),
            'workspace_id' => fn (array $attributes): string => (string) WorkspaceDrop::query()->findOrFail($attributes['workspace_drop_id'])->workspace_id,
            'type' => fake()->randomElement(['open', 'click', 'lead', 'unsubscribe', 'bot']),
            'revenue' => 0,
            'ip' => '203.0.113.10',
            'country' => 'United States',
            'state' => null,
            'city' => null,
            'device' => null,
            'platform' => null,
            'browser' => null,
            'is_mobile' => false,
            'email_id' => fake()->unique()->numberBetween(1, 100000),
        ];
    }

    public function forDrop(WorkspaceDrop $drop): static
    {
        return $this->state(fn (): array => [
            'workspace_drop_id' => $drop->getKey(),
            'workspace_id' => $drop->workspace_id,
        ]);
    }
}
