<?php

namespace Tests\Feature;

use App\Models\AudienceList;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\CampaignEngagementEvent;
use App\Models\CampaignLeadConversion;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRecipientSnapshotItem;
use App\Models\CampaignRevision;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\ContactListMembershipEvent;
use App\Models\Isp;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDrop;
use App\Models\WorkspaceDropVisitor;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsWorkspaceEmailManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        config(['alphalink.contact_lookup_key' => 'synthetic-contact-lookup-key-0123456789']);
        $this->owner = User::factory()->create();
        $this->workspace = $this->makeWorkspace('Email management workspace', $this->owner, 'owner');
        $this->actingAs($this->owner);
    }

    public function test_email_management_page_is_available_from_the_dms_admin_route(): void
    {
        $this->followingRedirects()->get(route('console.infrastructure.emails.index', $this->workspace->getKey()))
            ->assertOk()
            ->assertSee('Search Emails')
            ->assertSee('Search Type')
            ->assertSee('MOVE TO BLACKLIST')
            ->assertSee('SEARCH EMAIL');
    }

    public function test_search_supports_local_id_email_hash_and_saved_tracking_token_modes(): void
    {
        $fixture = $this->recipientFixture();
        $searchRoute = route('console.infrastructure.emails.search', $this->workspace->getKey());

        foreach ([
            ['type' => 'id', 'data' => [$fixture['contact']->getKey()]],
            ['type' => 'email', 'data' => ['  '.strtoupper($fixture['contact']->email).'  ']],
            ['type' => 'hash', 'data' => [$fixture['contact']->email_lookup_hash]],
            ['type' => 'token', 'data' => [$fixture['token']]],
        ] as $search) {
            $response = $this->postJson($searchRoute, $search)->assertOk();
            $response->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $fixture['contact']->getKey())
                ->assertJsonPath('data.0.isp', 'Management ISP')
                ->assertJsonPath('data.0.list', 'Synthetic audience')
                ->assertJsonPath('data.0.email', $fixture['contact']->email)
                ->assertJsonPath('data.0.type', 'fresh')
                ->assertJsonPath('data.0.first_name', 'Sample')
                ->assertJsonPath('data.0.logs_count', 1);
        }
    }

    public function test_search_is_workspace_scoped_and_operator_cannot_access_personal_data(): void
    {
        $fixture = $this->recipientFixture();
        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->makeWorkspace('Other workspace', $otherOwner, 'owner');
        $sameEmail = $this->makeContact($otherWorkspace, $fixture['contact']->email);

        $response = $this->postJson(route('console.infrastructure.emails.search', $this->workspace->getKey()), [
            'type' => 'email',
            'data' => [$fixture['contact']->email],
        ])->assertOk();
        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $fixture['contact']->getKey());
        $this->assertNotSame($fixture['contact']->getKey(), $sameEmail->getKey());

        $outsideIsp = Isp::query()->create([
            'workspace_id' => $otherWorkspace->getKey(), 'name' => 'Other ISP', 'is_active' => true,
            'allowed_users' => [], 'allowed_sponsors' => [], 'tracked_countries' => [], 'quality_emails' => [],
        ]);
        $outsideList = AudienceList::query()->create([
            'workspace_id' => $otherWorkspace->getKey(), 'created_by' => $otherOwner->getKey(),
            'name' => 'Other list', 'slug' => 'other-list', 'status' => 'active',
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'other-list'),
            'isp_id' => $outsideIsp->getKey(),
        ]);
        $outsideMembership = ContactListMembership::query()->create([
            'workspace_id' => $otherWorkspace->getKey(), 'audience_list_id' => $outsideList->getKey(),
            'contact_id' => $sameEmail->getKey(), 'email_type' => 'fresh', 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->patchJson(route('console.infrastructure.emails.action', $this->workspace->getKey()), [
            'ids' => [$fixture['contact']->getKey(), $sameEmail->getKey()], 'action' => 'blacklist',
        ])->assertOk()->assertJsonPath('updated_count', 1);
        $this->assertSame('fresh', $outsideMembership->fresh()->email_type);

        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $this->actingAs($operator);
        $this->followingRedirects()->get(route('console.infrastructure.emails.index', $this->workspace->getKey()))->assertForbidden();
        $this->postJson(route('console.infrastructure.emails.search', $this->workspace->getKey()), [
            'type' => 'id', 'data' => [$fixture['contact']->getKey()],
        ])->assertForbidden();
    }

    public function test_manual_type_changes_are_logged_and_restore_the_latest_prior_automatic_type(): void
    {
        $fixture = $this->recipientFixture();
        $this->recordAutomaticTransition($fixture, 'fresh', 'open', now()->subMinutes(2));
        $this->recordAutomaticTransition($fixture, 'open', 'click', now()->subMinute());
        $fixture['membership']->forceFill(['email_type' => 'click'])->save();
        $url = route('console.infrastructure.emails.action', $this->workspace->getKey());

        $this->patchJson($url, ['ids' => [$fixture['contact']->getKey()], 'action' => 'blacklist'])
            ->assertOk()
            ->assertJsonPath('updated_count', 1);
        $this->assertSame('blacklist', $fixture['membership']->fresh()->email_type);

        $this->patchJson($url, ['ids' => [$fixture['contact']->getKey()], 'action' => 'restore'])
            ->assertOk()
            ->assertJsonPath('updated_count', 1);
        $this->assertSame('open', $fixture['membership']->fresh()->email_type);
        $this->assertSame(2, ContactListMembershipEvent::query()->where('source', 'admin_email_action')->count());
        $this->assertSame(2, AuditEvent::query()->where('action', 'contact_membership.type_changed')->count());
        $this->assertDatabaseHas('contact_list_membership_events', [
            'workspace_id' => $this->workspace->getKey(),
            'contact_id' => $fixture['contact']->getKey(),
            'source' => 'admin_email_action',
        ]);
        $lastAction = ContactListMembershipEvent::query()->where('source', 'admin_email_action')->latest('created_at')->firstOrFail();
        $this->assertSame('open', $lastAction->metadata['restore_type']);
    }

    public function test_email_log_table_merges_campaign_drop_and_lead_records_with_paging(): void
    {
        $fixture = $this->recipientFixture();
        $drop = WorkspaceDrop::factory()->forWorkspace($this->workspace, $this->owner)->create([
            'offer_id' => $fixture['offer']->getKey(),
            'lists' => [(string) $fixture['list']->getKey()],
        ]);
        $dropToken = 'synthetic-drop-log-token';
        WorkspaceDropVisitor::factory()->forDrop($drop)->create([
            'type' => 'open',
            'email_id' => (string) $fixture['contact']->getKey(),
            'ip' => '203.0.113.13',
            'token_sha256' => hash('sha256', $dropToken),
            'created_at' => now()->subSeconds(30),
        ]);
        WorkspaceDropVisitor::factory()->forDrop($drop)->create([
            'type' => 'lead',
            'email_id' => (string) $fixture['contact']->getKey(),
            'ip' => '203.0.113.14',
            'revenue' => 6.5,
            'created_at' => now()->subSeconds(15),
        ]);
        $conversion = CampaignLeadConversion::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'campaign_id' => $fixture['campaign']->getKey(),
            'revision_id' => $fixture['revision']->getKey(),
            'recipient_snapshot_id' => $fixture['snapshot']->getKey(),
            'snapshot_item_id' => $fixture['item']->getKey(),
            'contact_id' => $fixture['contact']->getKey(),
            'audience_list_id' => $fixture['list']->getKey(),
            'sponsor_id' => $fixture['sponsor']->getKey(),
            'offer_id' => $fixture['offer']->getKey(),
            'provider_type' => 'everflow',
            'provider_conversion_id' => 'synthetic-conversion-1',
            'revenue' => 4.25,
            'conversion_ip' => '203.0.113.12',
            'isp_id' => $fixture['isp']->getKey(),
            'dimension_ids' => [],
            'converted_at' => now()->subMinute(),
        ]);
        $this->postJson(route('console.infrastructure.emails.search', $this->workspace->getKey()), [
            'type' => 'id', 'data' => [$fixture['contact']->getKey()],
        ])->assertOk()->assertJsonPath('data.0.logs_count', 4);
        $this->postJson(route('console.infrastructure.emails.search', $this->workspace->getKey()), [
            'type' => 'token', 'data' => [$dropToken],
        ])->assertOk()->assertJsonPath('data.0.id', $fixture['contact']->getKey());

        $url = route('console.infrastructure.emails.logs', [$this->workspace->getKey(), $fixture['contact']->getKey()]);
        $this->followingRedirects()->get($url)
            ->assertOk()
            ->assertSee($fixture['contact']->email)
            ->assertSee('Campaign and Drop activity')
            ->assertSee('REFRESH');
        $this->getJson($url.'?json=1&draw=3&start=0&length=1&order[0][column]=12&order[0][dir]=desc')
            ->assertOk()
            ->assertJsonPath('draw', 3)
            ->assertJsonPath('recordsTotal', 4)
            ->assertJsonPath('recordsFiltered', 4)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'click');

        $this->getJson($url.'?json=1&draw=4&start=1&length=1&order[0][column]=12&order[0][dir]=desc')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 4)
            ->assertJsonPath('data.0.action', 'lead')
            ->assertJsonPath('data.0.offer_id', $drop->offer_id)
            ->assertJsonPath('data.0.ip', '203.0.113.14');

        $this->getJson($url.'?json=1&draw=6&start=2&length=1&order[0][column]=12&order[0][dir]=desc')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 4)
            ->assertJsonPath('data.0.action', 'open')
            ->assertJsonPath('data.0.offer_id', $conversion->offer_id)
            ->assertJsonPath('data.0.ip', '203.0.113.13');

        $this->getJson($url.'?json=1&draw=8&start=3&length=1&order[0][column]=12&order[0][dir]=desc')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 4)
            ->assertJsonPath('data.0.action', 'lead')
            ->assertJsonPath('data.0.offer_id', $conversion->offer_id)
            ->assertJsonPath('data.0.ip', '203.0.113.12');

        $this->getJson($url.'?json=1&draw=5&start=0&length=50&search%5Bvalue%5D=203.0.113.11')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 4)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.action', 'click');

        $this->getJson($url.'?json=1&draw=7&start=0&length=50&search%5Bvalue%5D=203.0.113.13')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 4)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.action', 'open');
    }

    public function test_search_validates_modes_and_logs_route_cannot_cross_workspace(): void
    {
        $fixture = $this->recipientFixture();
        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->makeWorkspace('Another email workspace', $otherOwner, 'owner');

        $this->postJson(route('console.infrastructure.emails.search', $this->workspace->getKey()), [
            'type' => 'unknown', 'data' => ['anything'],
        ])->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->get(route('console.infrastructure.emails.logs', [$otherWorkspace->getKey(), $fixture['contact']->getKey()]))
            ->assertNotFound();
    }

    /** @return array{workspace: Workspace, isp: Isp, list: AudienceList, contact: Contact, membership: ContactListMembership, campaign: Campaign, revision: CampaignRevision, snapshot: CampaignRecipientSnapshot, item: CampaignRecipientSnapshotItem, sponsor: WorkspaceSponsor, offer: WorkspaceOffer, token: string} */
    private function recipientFixture(): array
    {
        $isp = Isp::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'name' => 'Management ISP', 'is_active' => true,
            'allowed_users' => [], 'allowed_sponsors' => [], 'tracked_countries' => [], 'quality_emails' => [],
        ]);
        $list = AudienceList::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'created_by' => $this->owner->getKey(),
            'name' => 'Synthetic audience', 'slug' => 'synthetic-audience-'.Str::lower(Str::random(5)),
            'status' => 'active', 'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'management-list-'.Str::uuid()), 'isp_id' => $isp->getKey(),
        ]);
        $contact = $this->makeContact($this->workspace, 'recipient-'.Str::lower(Str::random(6)).'@example.test');
        $membership = ContactListMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'audience_list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(), 'email_type' => 'fresh', 'status' => 'active', 'joined_at' => now(),
        ]);
        $sponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'name' => 'Synthetic sponsor',
            'affiliate_id' => (string) Str::uuid(), 'url' => 'https://affiliate.example.test',
            'username' => 'fixture-user', 'password' => 'fixture-password', 'api_key' => 'fixture-key',
            'type' => 'everflow', 'allowed_users' => [], 'is_active' => true,
        ]);
        $offer = WorkspaceOffer::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'sponsor_id' => $sponsor->getKey(),
            'offer_id' => 'management-offer', 'name' => 'Synthetic offer', 'type' => 'cpa',
            'vertical' => 'testing', 'geo_target' => ['US'], 'payout' => 1,
            'redirect' => 'https://offer.example.test', 'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live', 'allowed_users' => [], 'is_active' => true,
        ]);
        $campaign = Campaign::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'created_by' => $this->owner->getKey(),
            'name' => 'Synthetic campaign', 'kind' => 'marketing', 'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(), 'revision_number' => 1,
            'payload' => ['send_setup' => ['offer_id' => $offer->getKey()]],
            'content_sha256' => hash('sha256', 'management-revision'),
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'management-revision-request'),
            'created_by' => $this->owner->getKey(), 'created_at' => now(),
        ]);
        $snapshot = CampaignRecipientSnapshot::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'campaign_id' => $campaign->getKey(),
            'revision_id' => $revision->getKey(), 'offer_id' => $offer->getKey(),
            'suppression_sha256' => hash('sha256', 'empty management suppression'),
            'selection' => ['audience_segments' => [$list->getKey().'_fresh']], 'recipient_count' => 1,
            'recipients_sha256' => hash('sha256', (string) $contact->getKey()), 'created_at' => now(),
        ]);
        $item = CampaignRecipientSnapshotItem::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'snapshot_id' => $snapshot->getKey(),
            'contact_id' => $contact->getKey(), 'position' => 1, 'email_lookup_hash' => $contact->email_lookup_hash,
            'source_list_id' => $list->getKey(), 'source_email_type' => 'fresh', 'created_at' => now(),
        ]);
        $token = 'synthetic-management-token';
        CampaignEngagementEvent::factory()->forRecipient(
            $this->workspace, $campaign, $revision, $snapshot, $item, $list, $contact,
        )->create([
            'action' => 'click', 'client_ip' => '203.0.113.11',
            'token_sha256' => hash('sha256', $token), 'created_at' => now(),
        ]);

        return compact('isp', 'list', 'contact', 'membership', 'campaign', 'revision', 'snapshot', 'item', 'sponsor', 'offer', 'token')
            + ['workspace' => $this->workspace];
    }

    private function makeWorkspace(string $name, User $owner, string $role): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(7)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => $role]);

        return $workspace;
    }

    private function makeContact(Workspace $workspace, string $email): Contact
    {
        $normalized = mb_strtolower($email);

        return Contact::query()->create([
            'workspace_id' => $workspace->getKey(),
            'email' => $normalized,
            'email_lookup_hash' => hash_hmac('sha256', $normalized, (string) config('alphalink.contact_lookup_key')),
            'lookup_key_version' => 1,
            'profile' => ['first_name' => 'Sample', 'last_name' => 'Person', 'country' => 'US', 'age' => 32, 'sex' => 'X', 'vertical' => 'testing'],
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    /** @param array<string, mixed> $fixture */
    private function recordAutomaticTransition(array $fixture, string $from, string $to, mixed $occurredAt): void
    {
        ContactListMembershipEvent::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'audience_list_id' => $fixture['list']->getKey(),
            'contact_id' => $fixture['contact']->getKey(),
            'action' => $to,
            'source' => 'campaign_tracking',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', Str::uuid()->toString()),
            'actor_user_id' => null,
            'occurred_at' => $occurredAt,
            'metadata' => ['from_type' => $from, 'to_type' => $to],
            'created_at' => $occurredAt,
        ]);
    }
}
