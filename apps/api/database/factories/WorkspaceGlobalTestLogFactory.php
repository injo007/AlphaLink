<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGlobalTestLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceGlobalTestLog>
 */
class WorkspaceGlobalTestLogFactory extends Factory
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
            'actor_user_id' => User::factory(),
            'data' => [
                'return_path' => '[rnd]@[domain]',
                'email_tests' => ['test@example.test'],
                'mta' => 'ip',
                'type' => 'ipv4',
                'servers' => [],
                'ips' => [],
            ],
            'output' => 'Test sender output',
        ];
    }

    public function forWorkspace(Workspace $workspace, User $actor): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $actor->getKey(),
        ]);
    }
}
