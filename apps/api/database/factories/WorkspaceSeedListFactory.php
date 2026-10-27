<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSeedList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceSeedList>
 */
class WorkspaceSeedListFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => fn (): string => (string) Workspace::query()->firstOrFail()->getKey(),
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'is_active' => true,
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
