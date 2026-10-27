<?php

namespace Database\Factories;

use App\Models\WorkspaceSeedEmail;
use App\Models\WorkspaceSeedList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceSeedEmail>
 */
class WorkspaceSeedEmailFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_seed_list_id' => WorkspaceSeedList::factory(),
            'email' => fake()->unique()->safeEmail(),
        ];
    }

    public function forSeedList(WorkspaceSeedList $seedList): static
    {
        return $this->state(fn (): array => ['workspace_seed_list_id' => $seedList->getKey()]);
    }
}
