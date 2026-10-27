<?php

namespace Database\Factories;

use App\Models\AudienceList;
use App\Models\Campaign;
use App\Models\CampaignEngagementEvent;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRecipientSnapshotItem;
use App\Models\CampaignRevision;
use App\Models\Contact;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CampaignEngagementEvent>
 */
class CampaignEngagementEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action' => fake()->randomElement(['open', 'click', 'unsubscribe', 'bot']),
            'send_id' => (string) fake()->numberBetween(1, 1000),
            'isp_id' => null,
            'client_ip' => '203.0.113.10',
            'user_agent' => 'Synthetic campaign tracker',
            'dimension_ids' => [],
            'token_sha256' => hash('sha256', Str::random(40)),
            'created_at' => now(),
        ];
    }

    public function forRecipient(
        Workspace $workspace,
        Campaign $campaign,
        CampaignRevision $revision,
        CampaignRecipientSnapshot $snapshot,
        CampaignRecipientSnapshotItem $item,
        AudienceList $list,
        Contact $contact,
    ): static {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->getKey(),
            'campaign_id' => $campaign->getKey(),
            'revision_id' => $revision->getKey(),
            'recipient_snapshot_id' => $snapshot->getKey(),
            'snapshot_item_id' => $item->getKey(),
            'contact_id' => $contact->getKey(),
            'audience_list_id' => $list->getKey(),
            'source_email_type' => $item->source_email_type,
        ]);
    }
}
