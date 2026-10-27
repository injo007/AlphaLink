<?php

namespace Tests\Feature;

use App\Models\AudienceList;
use App\Models\Campaign;
use App\Models\CampaignEngagementEvent;
use App\Models\CampaignLeadConversion;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRecipientSnapshotItem;
use App\Models\CampaignRevision;
use App\Models\Contact;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceCampaignVisitorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_owner_can_review_engagement_and_lead_records_without_contact_addresses(): void
    {
        $fixture = $this->fixture();
        foreach (['open', 'click', 'unsubscribe', 'bot'] as $action) {
            $this->event($fixture, $action);
        }
        CampaignLeadConversion::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'campaign_id' => $fixture['campaign']->getKey(),
            'revision_id' => $fixture['revision']->getKey(),
            'recipient_snapshot_id' => $fixture['snapshot']->getKey(),
            'snapshot_item_id' => $fixture['item']->getKey(),
            'contact_id' => $fixture['contact']->getKey(),
            'audience_list_id' => $fixture['list']->getKey(),
            'sponsor_id' => $fixture['sponsor']->getKey(),
            'offer_id' => $fixture['offer']->getKey(),
            'provider_type' => 'hitpath',
            'provider_conversion_id' => 'visitor-log-conversion-1',
            'revenue' => 3.5,
            'conversion_ip' => '192.0.2.56',
            'dimension_ids' => [],
            'converted_at' => now(),
        ]);

        $url = route('console.campaigns.visitors', [$fixture['workspace']->getKey(), $fixture['campaign']->getKey()]);
        $this->actingAs($fixture['owner'])->get($url)
            ->assertOk()
            ->assertSee('CAMPAIGN')
            ->assertSee('VISITORS')
            ->assertSee('$3.50')
            ->assertSee('Native per-send configuration records are not available')
            ->assertSee('dms-reference/plugins/datatables/datatables.min.css')
            ->assertSee('dms-reference/plugins/datatables/datatables.min.js');

        $response = $this->actingAs($fixture['owner'])->getJson($url.'?draw=3&start=0&length=20');
        $response->assertOk()->assertJsonPath('draw', 3)->assertJsonPath('recordsTotal', 5)->assertJsonPath('recordsFiltered', 5);
        $this->assertCount(5, $response->json('data'));
        $this->assertEqualsCanonicalizing(['open', 'click', 'unsubscribe', 'bot', 'lead'], array_column($response->json('data'), 'type'));
        $this->assertStringNotContainsString('private-campaign-contact@example.test', $response->getContent());

        $lead = collect($response->json('data'))->firstWhere('type', 'lead');
        $this->assertSame('3.5', (string) $lead['revenue']);
        $this->assertNull($lead['country']);
        $this->assertNull($lead['device']);
    }

    public function test_campaign_visitors_search_is_server_filtered_and_keeps_the_dms_table_protocol(): void
    {
        $fixture = $this->fixture();
        $this->event($fixture, 'open');
        $this->event($fixture, 'click');
        $url = route('console.campaigns.visitors', [$fixture['workspace']->getKey(), $fixture['campaign']->getKey()]);

        $this->actingAs($fixture['owner'])->getJson($url.'?draw=4&start=0&length=20&search%5Bvalue%5D=click')
            ->assertOk()
            ->assertJsonPath('draw', 4)
            ->assertJsonPath('recordsTotal', 2)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.type', 'click');
    }

    public function test_campaign_visitors_are_scoped_to_the_campaign_owner_and_workspace(): void
    {
        $fixture = $this->fixture();
        $member = User::factory()->create();
        $fixture['workspace']->users()->attach($member->getKey(), ['role' => 'operator']);
        $url = route('console.campaigns.visitors', [$fixture['workspace']->getKey(), $fixture['campaign']->getKey()]);

        $this->actingAs($member)->get($url)->assertNotFound();
        $this->actingAs($fixture['owner'])->get(route('console.campaigns.visitors', [Str::uuid(), $fixture['campaign']->getKey()]))->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Synthetic campaign visitors workspace',
            'slug' => 'campaign-visitors-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);
        $sponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'Synthetic visitor sponsor',
            'affiliate_id' => 'visitors-'.Str::random(8), 'url' => 'https://affiliate.example.test',
            'username' => 'fixture-user', 'password' => 'fixture-password', 'api_key' => 'fixture-api-key',
            'type' => 'hitpath', 'allowed_users' => [], 'is_active' => true,
        ]);
        $offer = WorkspaceOffer::query()->create([
            'workspace_id' => $workspace->getKey(), 'sponsor_id' => $sponsor->getKey(),
            'offer_id' => 'visitors-'.Str::random(8), 'name' => 'Synthetic visitor offer',
            'type' => 'cpa', 'vertical' => 'testing', 'geo_target' => ['US'], 'payout' => 1,
            'redirect' => 'https://offer.example.test', 'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live', 'allowed_users' => [], 'is_active' => true,
        ]);
        $list = AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
            'name' => 'Synthetic visitor list', 'slug' => 'visitors-'.Str::lower(Str::random(8)),
            'status' => 'active', 'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'visitor list '.Str::random(8)),
        ]);
        $contact = Contact::query()->create([
            'workspace_id' => $workspace->getKey(), 'email' => 'private-campaign-contact@example.test',
            'email_lookup_hash' => hash('sha256', 'private-campaign-contact@example.test'),
            'lookup_key_version' => 1, 'status' => 'active', 'idempotency_key' => (string) Str::uuid(),
        ]);
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
            'name' => 'Synthetic campaign visitors', 'kind' => 'marketing', 'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(), 'revision_number' => 1, 'payload' => [],
            'content_sha256' => hash('sha256', 'visitor revision '.Str::random(8)),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'visitor revision request '.Str::random(8)),
            'created_by' => $owner->getKey(), 'created_at' => now(),
        ]);
        $snapshot = CampaignRecipientSnapshot::query()->create([
            'workspace_id' => $workspace->getKey(), 'campaign_id' => $campaign->getKey(),
            'revision_id' => $revision->getKey(), 'offer_id' => $offer->getKey(),
            'suppression_sha256' => hash('sha256', 'synthetic suppression'),
            'selection' => ['audience_segments' => [$list->getKey().'_fresh']], 'recipient_count' => 1,
            'recipients_sha256' => hash('sha256', $contact->getKey()), 'created_at' => now(),
        ]);
        $item = CampaignRecipientSnapshotItem::query()->create([
            'workspace_id' => $workspace->getKey(), 'snapshot_id' => $snapshot->getKey(),
            'contact_id' => $contact->getKey(), 'position' => 1,
            'email_lookup_hash' => $contact->email_lookup_hash, 'source_list_id' => $list->getKey(),
            'source_email_type' => 'fresh', 'created_at' => now(),
        ]);

        return compact('owner', 'workspace', 'sponsor', 'offer', 'list', 'contact', 'campaign', 'revision', 'snapshot', 'item');
    }

    /** @param array<string, mixed> $fixture */
    private function event(array $fixture, string $action): void
    {
        CampaignEngagementEvent::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'campaign_id' => $fixture['campaign']->getKey(),
            'revision_id' => $fixture['revision']->getKey(),
            'recipient_snapshot_id' => $fixture['snapshot']->getKey(),
            'snapshot_item_id' => $fixture['item']->getKey(),
            'contact_id' => $fixture['contact']->getKey(),
            'audience_list_id' => $fixture['list']->getKey(),
            'source_email_type' => 'fresh', 'action' => $action,
            'send_id' => 'send-'.Str::random(8), 'client_ip' => '192.0.2.55',
            'user_agent' => 'Synthetic visitor test agent', 'token_sha256' => hash('sha256', Str::random(20)),
            'created_at' => now(),
        ]);
    }
}
