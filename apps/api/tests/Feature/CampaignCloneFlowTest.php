<?php

namespace Tests\Feature;

use App\Models\AudienceList;
use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignCloneFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_owner_can_clone_a_saved_campaign_as_a_fresh_draft_and_replay_the_request_safely(): void
    {
        $fixture = $this->campaignFixture();
        $route = route('console.campaigns.clone', [$fixture['workspace']->getKey(), $fixture['source']->getKey()]);
        $this->actingAs($fixture['owner'])
            ->get($route)
            ->assertOk()
            ->assertSee('DMS Clone requires a live, active offer')
            ->assertSee('send_setup[audience_segments][]', false)
            ->assertSee('send_setup[order_by]', false)
            ->assertSee('send_setup[start_from]', false)
            ->assertSee('The result is a draft');

        $idempotencyKey = (string) Str::uuid();
        $payload = $this->clonePayload($fixture, $idempotencyKey);
        $response = $this->actingAs($fixture['owner'])->post($route, $payload);
        $clone = Campaign::query()->where('workspace_id', $fixture['workspace']->getKey())->where('idempotency_key', $idempotencyKey)->firstOrFail();
        $cloneUrl = route('console.campaigns.show', [$fixture['workspace']->getKey(), $clone->getKey()]);

        $response->assertRedirect($cloneUrl)->assertSessionHas('status', 'Campaign cloned as a new draft with a fresh suppression snapshot. Native campaign sending remains unavailable.');
        $this->assertSame('draft', $clone->status);
        $this->assertTrue($clone->is_active);
        $this->assertSame('marketing', $clone->kind);
        $this->assertSame('Welcome test', $clone->latestRevision->payload['subject']);
        $this->assertSame('sender@example.test', $clone->latestRevision->payload['from_email']);
        $this->assertSame($fixture['sourceSetup']['isp_id'], $clone->latestRevision->payload['send_setup']['isp_id']);
        $this->assertSame('default', $fixture['source']->fresh()->latestRevision->payload['send_setup']['order_by']);
        $this->assertSame('asc', $clone->latestRevision->payload['send_setup']['order_by']);
        $this->assertSame(25, $clone->latestRevision->payload['send_setup']['start_from']);
        $snapshot = CampaignRecipientSnapshot::query()->where('revision_id', $clone->latestRevision->getKey())->firstOrFail();
        $this->assertSame(1, $snapshot->recipient_count);
        $this->assertSame(1, $snapshot->start_from);
        $this->assertSame(1, $snapshot->items()->count());
        $this->assertSame($fixture['source']->latestRevision->getKey(), $fixture['source']->fresh()->latestRevision->getKey());
        $this->assertDatabaseHas('audit_events', [
            'workspace_id' => $fixture['workspace']->getKey(),
            'subject_id' => $clone->getKey(),
            'action' => 'campaign.cloned',
        ]);
        $this->assertDatabaseCount('campaigns', 2);
        $this->assertDatabaseCount('campaign_recipient_snapshots', 1);

        $this->actingAs($fixture['owner'])
            ->post($route, $payload)
            ->assertRedirect($cloneUrl)
            ->assertSessionHas('status', 'Clone already saved; no duplicate draft was created.');
        $this->assertDatabaseCount('campaigns', 2);
        $this->assertDatabaseCount('campaign_recipient_snapshots', 1);
    }

    public function test_send_register_exposes_clone_for_a_saved_marketing_campaign(): void
    {
        $fixture = $this->campaignFixture();
        $this->actingAs($fixture['owner'])
            ->get(route('console.campaigns.index', $fixture['workspace']->getKey()))
            ->assertOk()
            ->assertSee(route('console.campaigns.clone', [$fixture['workspace']->getKey(), $fixture['source']->getKey()]), false)
            ->assertSee('Clone campaign into a new draft');
    }

    public function test_clone_requires_the_source_campaign_owner_and_a_live_active_offer(): void
    {
        $fixture = $this->campaignFixture();
        $otherUser = User::factory()->create();
        $fixture['workspace']->users()->attach($otherUser->getKey(), ['role' => 'admin']);

        $this->actingAs($otherUser)
            ->get(route('console.campaigns.clone', [$fixture['workspace']->getKey(), $fixture['source']->getKey()]))
            ->assertNotFound();

        $fixture['offer']->forceFill(['is_active' => false])->save();
        $this->actingAs($fixture['owner'])
            ->get(route('console.campaigns.clone', [$fixture['workspace']->getKey(), $fixture['source']->getKey()]))
            ->assertStatus(409);

        $this->assertDatabaseCount('campaigns', 1);
        $this->assertDatabaseCount('campaign_recipient_snapshots', 0);
    }

    public function test_clone_rejects_unavailable_segments_and_stale_source_revisions_without_creating_a_draft(): void
    {
        $fixture = $this->campaignFixture();
        $route = route('console.campaigns.clone', [$fixture['workspace']->getKey(), $fixture['source']->getKey()]);
        $payload = $this->clonePayload($fixture, (string) Str::uuid());
        $payload['send_setup']['audience_segments'] = [(string) Str::uuid().'_fresh'];

        $this->actingAs($fixture['owner'])
            ->post($route, $payload)
            ->assertSessionHasErrors('send_setup.audience_segments');

        $payload = $this->clonePayload($fixture, (string) Str::uuid());
        $payload['source_revision_id'] = (string) Str::uuid();
        $this->actingAs($fixture['owner'])->post($route, $payload)->assertStatus(409);

        $this->assertDatabaseCount('campaigns', 1);
        $this->assertDatabaseCount('campaign_revisions', 1);
        $this->assertDatabaseCount('campaign_recipient_snapshots', 0);
    }

    public function test_clone_fails_closed_when_the_saved_suppression_file_changes(): void
    {
        $fixture = $this->campaignFixture();
        Storage::disk('local')->put($fixture['offer']->suppression_path, "changed@example.test\n");

        $this->actingAs($fixture['owner'])
            ->post(
                route('console.campaigns.clone', [$fixture['workspace']->getKey(), $fixture['source']->getKey()]),
                $this->clonePayload($fixture, (string) Str::uuid()),
            )
            ->assertSessionHasErrors('send_setup.offer_id');

        $this->assertDatabaseCount('campaigns', 1);
        $this->assertDatabaseCount('campaign_revisions', 1);
        $this->assertDatabaseCount('campaign_recipient_snapshots', 0);
    }

    /** @return array<string, mixed> */
    private function campaignFixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Campaign clone workspace',
            'slug' => 'campaign-clone-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);
        $sponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Clone fixture sponsor',
            'affiliate_id' => (string) Str::uuid(),
            'url' => 'https://affiliate.example.test',
            'username' => 'fixture-user',
            'password' => 'fixture-password',
            'api_key' => 'fixture-api-key',
            'type' => 'everflow',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $suppressionPath = 'clone-suppression.txt';
        $suppression = "blocked@example.test\n";
        Storage::disk('local')->put($suppressionPath, $suppression);
        $offer = WorkspaceOffer::query()->create([
            'workspace_id' => $workspace->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => (string) Str::uuid(),
            'name' => 'Clone fixture offer',
            'type' => 'CPA',
            'vertical' => 'insurance',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://example.test/offer',
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live',
            'allowed_users' => [],
            'is_active' => true,
            'suppression_path' => $suppressionPath,
            'suppression_sha256' => hash('sha256', $suppression),
            'suppression_format' => 'email',
            'suppression_updated_at' => now()->toDateString(),
        ]);
        $isp = $workspace->isps()->create([
            'name' => 'Clone fixture ISP',
            'is_active' => true,
            'tracked_countries' => ['US'],
            'allowed_users' => [],
            'allowed_sponsors' => [(int) $sponsor->getKey()],
        ]);
        $list = AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Clone fixture list',
            'slug' => 'clone-fixture-list',
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'clone-fixture-list'),
            'isp_id' => $isp->getKey(),
            'country' => 'US',
            'allowed_users' => [],
            'allowed_sponsors' => [(int) $sponsor->getKey()],
        ]);
        $email = 'clone-recipient@example.test';
        $contact = Contact::query()->create([
            'workspace_id' => $workspace->getKey(),
            'email' => $email,
            'email_lookup_hash' => hash('sha256', $email),
            'lookup_key_version' => 1,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        ContactListMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(),
            'email_type' => 'fresh',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $domain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'send.clone.example.test',
            'state' => 'verified',
            'verification_token' => 'clone-fixture-domain-token',
            'created_by' => $owner->getKey(),
            'status' => 'free',
            'is_our' => false,
            'is_active' => true,
        ]);
        $server = ManagedServer::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'CLONE FIXTURE SERVER',
            'provider' => 'Fixture',
            'ip_address' => '203.0.113.50',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'clone-fixture-server'),
        ]);
        $ip = ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => '203.0.113.51',
            'type' => 'ipv4',
            'dns' => 'mta.clone.example.test',
            'is_active' => true,
        ]);
        $sourceSetup = [
            'sponsor_id' => (int) $sponsor->getKey(),
            'offer_id' => (int) $offer->getKey(),
            'negative_id' => null,
            'from_asset_ids' => [],
            'subject_asset_ids' => [],
            'creative_asset_id' => null,
            'html_asset_id' => null,
            'offer_country' => 'ALL',
            'isp_id' => (int) $isp->getKey(),
            'country' => 'US',
            'audience_list_ids' => [(string) $list->getKey()],
            'audience_segments' => [$list->getKey().'_fresh'],
            'targeting_basis' => 'dms_email_type_segments',
            'order_by' => 'default',
            'start_from' => 0,
            'route_option' => 'ip',
            'ip_type' => 'ipv4',
            'server_ids' => [(string) $server->getKey()],
            'ip_ids' => [(string) $ip->getKey()],
            'send_type' => 'delay',
            'domain' => null,
            'parameters' => [
                'total_send' => 1,
                'fraction_ip' => 1,
                'sleep_email' => 0,
                'sleep_ip' => 0,
                'seed_every' => 0,
                'bounce_limit' => 1000,
                'duplicate_data' => 1,
            ],
            'placeholder_combination' => false,
            'placeholder_all_combination' => false,
            'placeholders' => [],
        ];
        $source = $workspace->campaigns()->create([
            'created_by' => $owner->getKey(),
            'name' => 'Source campaign',
            'kind' => 'marketing',
            'status' => 'draft',
            'is_active' => true,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $content = [
            'subject' => 'Welcome test',
            'from_name' => 'Fixture sender',
            'from_email' => 'sender@example.test',
            'reply_to' => null,
            'return_path' => '[rnd]@[domain]',
            'email_tests' => [],
            'header' => 'MIME-version: 1.0',
            'body' => 'DMS body',
            'text_body' => 'Plain body',
            'html_body' => '<p>HTML body</p>',
            'send_setup' => $sourceSetup,
        ];
        $contentHash = hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $source->revisions()->create([
            'revision_number' => 1,
            'payload' => $content,
            'content_sha256' => $contentHash,
            'idempotency_key' => 'source-revision-one',
            'request_fingerprint' => $contentHash,
            'created_by' => $owner->getKey(),
            'created_at' => now(),
        ]);

        return compact('owner', 'workspace', 'sponsor', 'offer', 'isp', 'list', 'contact', 'server', 'ip', 'source', 'sourceSetup');
    }

    /** @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private function clonePayload(array $fixture, string $idempotencyKey): array
    {
        return [
            'name' => 'Cloned campaign',
            'idempotency_key' => $idempotencyKey,
            'source_revision_id' => $fixture['source']->latestRevision->getKey(),
            'send_setup' => [
                'isp_id' => $fixture['isp']->getKey(),
                'country' => 'US',
                'audience_segments' => [$fixture['list']->getKey().'_fresh'],
                'order_by' => 'asc',
                'start_from' => 25,
            ],
        ];
    }
}
