<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceOfferAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceOfferAsset>
 */
class WorkspaceOfferAssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'offer_id' => WorkspaceOffer::factory(),
            'workspace_id' => Workspace::factory(),
            'type' => 'from',
            'value' => fake()->safeEmail(),
            'is_active' => true,
            'created_by' => null,
        ];
    }
}
