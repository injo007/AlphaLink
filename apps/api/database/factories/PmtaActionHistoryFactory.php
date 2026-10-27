<?php

namespace Database\Factories;

use App\Models\ManagedServer;
use App\Models\ManagedServer;
use App\Models\PmtaActionHistory;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PmtaActionHistory>
 */
class PmtaActionHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => fake()->uuid(),
            'managed_server_id' => fake()->uuid(),
            'actor_user_id' => null,
            'action' => fake()->randomElement(['pause', 'resume', 'delete', 'reset', 'schedule', 'restart']),
            'result' => fake()->sentence(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function forContext(Workspace $workspace, ManagedServer $server, ?User $actor): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'actor_user_id' => $actor?->getKey(),
        ]);
    }
}
