<?php

namespace Database\Factories;

use App\Models\PmtaScheduledAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PmtaScheduledAction>
 */
class PmtaScheduledActionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_ids' => [],
            'queue' => 'domain',
            'tags' => ['*/*'],
            'action' => 'schedule',
            'frequency' => 'everyMinute',
            'is_active' => true,
        ];
    }
}
