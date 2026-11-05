<?php

namespace Tests\Feature;

use App\Models\AudienceList;
use App\Models\Campaign;
use App\Models\CampaignEngagementEvent;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRecipientSnapshotItem;
use App\Models\CampaignRevision;
use App\Models\Contact;
use App\Models\Isp;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSeedList;
use App\Models\WorkspaceSponsor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceMailerOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_drop_and_warmup_sidebar_items_open_workspace_pages(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');

        $this->actingAs($owner)->get(route('console.drops.drafts.index', $workspace->getKey()))
            ->assertOk()->assertSee('DROP : NEW')->assertSee('RECENT DROP')
            ->assertSee(route('clean.console.drops.drafts.create'), false)
            ->assertSee(route('clean.console.drops.monitoring'), false);
        $dropComposer = $this->actingAs($owner)->get(route('console.drops.drafts.create', $workspace->getKey()));
        $dropComposer->assertOk()->assertSee('DROP : NEW')->assertSee('Sponsors:')->assertSee('MAP BODY')
            ->assertSee('Duplicate Data:')->assertSee('Delay (MS):')->assertSee('Lists:')->assertSee('AlphaLink draft');
        $this->assertSame(1, substr_count($dropComposer->getContent(), ' id="mailer-header"'));
        $this->assertSame(1, substr_count($dropComposer->getContent(), ' id="mailer-body"'));
        $this->actingAs($owner)->get(route('console.warmups.index', $workspace->getKey()))
            ->assertOk()->assertSee('My Warmups')->assertSee('TOTAL SEND')->assertSee('CREATE WARMUP')
            ->assertSee(route('clean.console.warmups.seeds.index'), false)
            ->assertSee(route('clean.console.warmups.statistics'), false);
        $warmupComposer = $this->actingAs($owner)->get(route('console.warmups.create', $workspace->getKey()));
        $warmupComposer
            ->assertOk()->assertSee('NEW WARMUP')->assertSee('Return Path:')->assertSee('Saved Emails:')
            ->assertSee('Seeds:')->assertSee('Sleep Btw Email')->assertSee('Test After:');
        $this->assertSame(1, substr_count($warmupComposer->getContent(), ' id="mailer-header"'));
        $this->assertSame(1, substr_count($warmupComposer->getContent(), ' id="mailer-body"'));
        $this->actingAs($owner)->get(route('console.warmups.statistics', $workspace->getKey()))
            ->assertOk()->assertSee('Warmup Statistics')->assertSee('No Warmup actions in this date range');
    }

    public function test_drop_drafts_save_workspace_selections_and_create_immutable_revisions(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $isp = Isp::query()->create(['workspace_id' => $workspace->getKey(), 'name' => 'Synthetic ISP', 'is_active' => true]);
        $list = $this->audienceList($workspace, $owner, 'Synthetic US list', $isp->getKey(), 'US');
        $australiaList = $this->audienceList($workspace, $owner, 'Synthetic AU list', $isp->getKey(), 'AU');
        $idempotencyKey = (string) Str::uuid();
        $url = route('console.drops.drafts.store', $workspace->getKey());
        $payload = [
            'name' => 'Synthetic Drop', 'from' => 'Updates <mail@example.test>', 'subject' => 'First subject',
            'header' => 'Content-Type: text/html', 'html_body' => '<p>Original [domain] tag</p>',
            'isp_id' => $isp->getKey(), 'list_ids' => [$list->getKey(), $australiaList->getKey()], 'countries' => ['US', 'AU'],
            'send_setup' => [
                'route_option' => 'ip', 'ip_type' => 'all', 'send_type' => 'delayp', 'total_send' => 50,
                'duplicate_data' => 3, 'delay' => 250, 'email_types' => ['open', 'click'],
                'placeholder_combination' => true,
                'placeholder_inputs' => [['data_text' => "one\ntwo", 'rotate_every' => 2]],
            ],
            'idempotency_key' => $idempotencyKey,
        ];

        $this->actingAs($owner)->post($url, $payload)->assertRedirect();
        $draft = Campaign::query()->where('workspace_id', $workspace->getKey())->where('kind', 'drop')->firstOrFail();
        $revision = $draft->revisions()->firstOrFail();
        $this->assertSame('<p>Original [domain] tag</p>', $revision->payload['html_body']);
        $this->assertSame([(string) $list->getKey(), (string) $australiaList->getKey()], $revision->payload['list_ids']);
        $this->assertSame(['US', 'AU'], $revision->payload['countries']);
        $this->assertSame((int) $isp->getKey(), $revision->payload['send_setup']['isp_id']);
        $this->assertSame('delayp', $revision->payload['send_setup']['send_type']);
        $this->assertSame(3, $revision->payload['send_setup']['duplicate_data']);
        $this->assertSame(250, $revision->payload['send_setup']['delay']);
        $this->assertSame(['open', 'click'], $revision->payload['send_setup']['email_types']);
        $this->assertSame([['data' => ['one', 'two'], 'rotate_every' => 2]], $revision->payload['send_setup']['placeholders']);
        $this->assertTrue($revision->payload['send_setup']['placeholder_combination']);
        $this->assertSame(0, $draft->sendRuns()->count());

        $this->actingAs($owner)->put(route('console.drops.drafts.update', [$workspace->getKey(), $draft->getKey()]), [
            ...$payload,
            'name' => 'Synthetic Drop Updated',
            'subject' => 'Second subject',
            'html_body' => '<p>Edited without rewriting https://example.test/image.jpg</p>',
            'source_revision_id' => $revision->getKey(),
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $draft->refresh();
        $this->assertSame('Synthetic Drop Updated', $draft->name);
        $this->assertSame(2, $draft->revisions()->count());
        $this->assertStringContainsString('https://example.test/image.jpg', $draft->latestRevision->payload['html_body']);
    }

    public function test_drop_draft_rejects_foreign_lists_and_isp_or_country_mismatches(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $isp = Isp::query()->create(['workspace_id' => $workspace->getKey(), 'name' => 'Selected ISP', 'is_active' => true]);
        $otherIsp = Isp::query()->create(['workspace_id' => $workspace->getKey(), 'name' => 'Other ISP', 'is_active' => true]);
        $otherIspList = $this->audienceList($workspace, $owner, 'Other ISP list', $otherIsp->getKey(), 'US');
        $this->actingAs($owner)->post(route('console.drops.drafts.store', $workspace->getKey()), [
            'name' => 'ISP mismatch', 'subject' => 'Test', 'html_body' => '<p>Test</p>',
            'isp_id' => $isp->getKey(), 'list_ids' => [$otherIspList->getKey()], 'countries' => ['US'],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('list_ids');

        $sameIspOtherCountry = $this->audienceList($workspace, $owner, 'Other country list', $isp->getKey(), 'AU');
        $this->actingAs($owner)->post(route('console.drops.drafts.store', $workspace->getKey()), [
            'name' => 'Country mismatch', 'subject' => 'Test', 'html_body' => '<p>Test</p>',
            'isp_id' => $isp->getKey(), 'list_ids' => [$sameIspOtherCountry->getKey()], 'countries' => ['US'],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('countries');

        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->workspace($otherOwner, 'owner');
        $foreignList = $this->audienceList($otherWorkspace, $otherOwner, 'Foreign list');

        $this->actingAs($owner)->post(route('console.drops.drafts.store', $workspace->getKey()), [
            'name' => 'Synthetic Drop', 'subject' => 'Test', 'html_body' => '<p>Test</p>',
            'list_ids' => [$foreignList->getKey()], 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('list_ids');
        $this->assertDatabaseMissing('campaigns', ['workspace_id' => $workspace->getKey(), 'kind' => 'drop']);
    }

    public function test_warmup_drafts_validate_seed_ownership_and_save_configuration_without_sending(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $ownerList = WorkspaceSeedList::query()->create(['workspace_id' => $workspace->getKey(), 'user_id' => $owner->getKey(), 'name' => 'Owner seeds', 'is_active' => true]);

        $this->actingAs($operator)->post(route('console.warmups.store', $workspace->getKey()), [
            'name' => 'Operator warmup', 'header' => 'Content-Type: text/html', 'html_body' => '<p>Warmup</p>',
            'seed_list_ids' => [$ownerList->getKey()], 'send_type' => 'delay', 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('seed_list_ids');

        $this->actingAs($owner)->post(route('console.warmups.store', $workspace->getKey()), [
            'name' => 'Synthetic warmup', 'return_path' => 'reply@example.test',
            'email_test_addresses_text' => "qa@example.test\n   \nops@example.test\n",
            'header' => 'Content-Type: text/html', 'html_body' => '<p>Warmup body</p>',
            'seed_list_ids' => [$ownerList->getKey()], 'send_type' => 'delayp',
            'total_send' => 500, 'fraction_ip' => 25, 'sleep_email' => 2, 'sleep_ip' => 5,
            'seed_every' => 10, 'bounce_limit' => 15, 'seed_accounts_text' => "warmup@example.test\n",
            'placeholder_combination' => true,
            'placeholder_inputs' => [['data_text' => "first\nsecond", 'rotate_every' => 3]],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $draft = Campaign::query()->where('workspace_id', $workspace->getKey())->where('kind', 'warmup')->firstOrFail();
        $revision = $draft->latestRevision;
        $this->assertSame(['qa@example.test', 'ops@example.test'], $revision->payload['email_test_addresses']);
        $this->assertSame([(int) $ownerList->getKey()], $revision->payload['seed_list_ids']);
        $this->assertSame('delayp', $revision->payload['send_type']);
        $this->assertSame('warmup@example.test', $revision->payload['seed_accounts_text']);
        $this->assertSame([['data' => ['first', 'second'], 'rotate_every' => 3]], $revision->payload['send_setup']['placeholders']);
        $this->assertSame(0, $draft->sendRuns()->count());

        $this->actingAs($owner)->get(route('console.warmups.index', $workspace->getKey()))
            ->assertOk()->assertSee('Synthetic warmup')->assertSee('TOTAL SEND')->assertSee('DRAFT');
    }

    public function test_recent_drop_opens_latest_saved_drop_without_creating_another(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        foreach (['Older saved Drop', 'Latest saved Drop'] as $index => $name) {
            $campaign = Campaign::query()->create([
                'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
                'name' => $name, 'kind' => 'drop', 'status' => 'draft', 'is_active' => true,
                'idempotency_key' => (string) Str::uuid(),
            ]);
            DB::table('campaigns')->where('id', $campaign->getKey())->update([
                'created_at' => now()->addSeconds($index)->toDateTimeString(),
                'updated_at' => now()->addSeconds($index)->toDateTimeString(),
            ]);
            CampaignRevision::query()->create([
                'campaign_id' => $campaign->getKey(), 'revision_number' => 1,
                'payload' => ['draft_type' => 'drop', 'subject' => 'Stored', 'html_body' => '<p>Stored</p>'],
                'content_sha256' => hash('sha256', 'recent drop '.$index.' '.Str::random(8)),
                'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'recent '.Str::random(8)),
                'created_by' => $owner->getKey(), 'created_at' => now()->addSeconds($index),
            ]);
        }
        $this->actingAs($owner)->get(route('console.drops.drafts.index', $workspace->getKey()))
            ->assertOk()->assertSee('Latest saved Drop')->assertDontSee('Older saved Drop');
        $this->assertSame(2, Campaign::query()->where('workspace_id', $workspace->getKey())->where('kind', 'drop')->count());
    }

    public function test_warmup_statistics_count_only_saved_unique_recipient_actions(): void
    {
        $fixture = $this->warmupTrackingFixture();
        foreach (['open', 'click', 'unsubscribe', 'bot'] as $action) {
            $event = CampaignEngagementEvent::query()->create([
                'workspace_id' => $fixture['workspace']->getKey(),
                'campaign_id' => $fixture['campaign']->getKey(),
                'revision_id' => $fixture['revision']->getKey(),
                'recipient_snapshot_id' => $fixture['snapshot']->getKey(),
                'snapshot_item_id' => $fixture['item']->getKey(),
                'contact_id' => $fixture['contact']->getKey(),
                'audience_list_id' => $fixture['list']->getKey(),
                'source_email_type' => 'fresh', 'action' => $action,
                'client_ip' => '192.0.2.15', 'user_agent' => 'Synthetic warmup test',
                'token_sha256' => hash('sha256', Str::random(20)), 'created_at' => now(),
            ]);
            DB::table('campaign_engagement_unique_actions')->insertOrIgnore([
                'workspace_id' => $event->workspace_id, 'campaign_id' => $event->campaign_id,
                'contact_id' => $event->contact_id, 'action' => $event->action,
                'engagement_event_id' => $event->getKey(), 'created_at' => $event->created_at,
            ]);
        }

        $this->actingAs($fixture['owner'])->get(route('console.warmups.statistics', $fixture['workspace']->getKey()))
            ->assertOk()->assertSee('Synthetic tracked warmup')
            ->assertSee('Unique opens')->assertSee('Automated actions')
            ->assertSee('>1</div>', false);
    }

    /** @return array{owner: User, workspace: Workspace} */
    private function workspace(User $owner, string $role): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Synthetic mail operations workspace',
            'slug' => 'mailer-operations-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => $role]);

        return $workspace;
    }

    private function audienceList(Workspace $workspace, User $creator, string $name, ?int $ispId = null, ?string $country = null): AudienceList
    {
        return AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $creator->getKey(),
            'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'active', 'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', $name.' '.Str::random(12)),
            'isp_id' => $ispId, 'country' => $country, 'allowed_users' => [],
        ]);
    }

    /** @return array<string, mixed> */
    private function warmupTrackingFixture(): array
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $sponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'Synthetic warmup sponsor',
            'affiliate_id' => 'warmup-'.Str::random(8), 'url' => 'https://affiliate.example.test',
            'username' => 'fixture-user', 'password' => 'fixture-password', 'api_key' => 'fixture-key',
            'type' => 'hitpath', 'allowed_users' => [], 'is_active' => true,
        ]);
        $offer = WorkspaceOffer::query()->create([
            'workspace_id' => $workspace->getKey(), 'sponsor_id' => $sponsor->getKey(),
            'offer_id' => 'warmup-'.Str::random(8), 'name' => 'Synthetic warmup offer',
            'type' => 'cpa', 'vertical' => 'testing', 'geo_target' => ['US'], 'payout' => 1,
            'redirect' => 'https://offer.example.test', 'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live', 'allowed_users' => [], 'is_active' => true,
        ]);
        $list = $this->audienceList($workspace, $owner, 'Synthetic warmup audience');
        $contact = Contact::query()->create([
            'workspace_id' => $workspace->getKey(), 'email' => 'private-warmup@example.test',
            'email_lookup_hash' => hash('sha256', 'private-warmup@example.test'), 'lookup_key_version' => 1,
            'status' => 'active', 'idempotency_key' => (string) Str::uuid(),
        ]);
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
            'name' => 'Synthetic tracked warmup', 'kind' => 'warmup', 'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(), 'revision_number' => 1, 'payload' => ['draft_type' => 'warmup'],
            'content_sha256' => hash('sha256', 'warmup revision '.Str::random(8)),
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'warmup request '.Str::random(8)),
            'created_by' => $owner->getKey(), 'created_at' => now(),
        ]);
        $snapshot = CampaignRecipientSnapshot::query()->create([
            'workspace_id' => $workspace->getKey(), 'campaign_id' => $campaign->getKey(),
            'revision_id' => $revision->getKey(), 'offer_id' => $offer->getKey(),
            'suppression_sha256' => hash('sha256', 'synthetic warmup suppression'),
            'selection' => ['audience_segments' => [$list->getKey().'_fresh']], 'recipient_count' => 1,
            'recipients_sha256' => hash('sha256', $contact->getKey()), 'created_at' => now(),
        ]);
        $item = CampaignRecipientSnapshotItem::query()->create([
            'workspace_id' => $workspace->getKey(), 'snapshot_id' => $snapshot->getKey(),
            'contact_id' => $contact->getKey(), 'position' => 1,
            'email_lookup_hash' => $contact->email_lookup_hash, 'source_list_id' => $list->getKey(),
            'source_email_type' => 'fresh', 'created_at' => now(),
        ]);

        return compact('owner', 'workspace', 'campaign', 'revision', 'snapshot', 'item', 'contact', 'list');
    }
}
