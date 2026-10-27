<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDrop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceDrop>
 */
class WorkspaceDropFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => fn (): string => (string) Workspace::query()->firstOrFail()->getKey(),
            'user_id' => User::factory(),
            'send_type' => fake()->randomElement(['send', 'test']),
            'servers' => [],
            'lists' => [],
            'filters' => [],
            'countries' => [],
            'send_status' => 'start',
            'send_progress' => 0,
            'send_count' => null,
            'is_processed' => true,
            'is_scheduled' => false,
            'scheduled_at' => null,
            'is_restricted' => false,
        ];
    }

    public function forWorkspace(Workspace $workspace, User $user): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
        ]);
    }
}
