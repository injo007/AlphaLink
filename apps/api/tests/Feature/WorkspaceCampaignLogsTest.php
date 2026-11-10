<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRevision;
use App\Models\CampaignSendRun;
use App\Models\ManagedServer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceCampaignLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_logs_register_and_all_three_history_subpages_render_summaries(): void
    {
        $fixture = $this->campaignFixture();
        $owner = $fixture['owner'];
        $workspace = $fixture['workspace'];
        $campaign = $fixture['campaign'];
        $this->insertManualTestAttempt($fixture);

        $this->actingAs($owner)
            ->get(route('console.campaign-logs.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('Campaign Logs')
            ->assertSee('Synthetic campaign log')
            ->assertSee('Gmail')
            ->assertSee('Synthetic campaign offer')
            ->assertSee('T:2', false)
            ->assertSee('U:1', false)
            ->assertSee('S:1', false)
            ->assertSee('aria-label="Scrollable Campaign Logs table" tabindex="0"', false)
            ->assertSee('RESTRICT');

        $this->actingAs($owner)
            ->get(route('console.mailer.campaign-logs'))
            ->assertOk()
            ->assertSee('Synthetic campaign log');

        $this->actingAs($owner)
            ->get(route('console.campaign-logs.history', [$workspace->getKey(), $campaign->getKey(), 'tests']))
            ->assertOk()
            ->assertSee('Test history')
            ->assertSee('Manual test')
            ->assertSee('Saved campaign test')
            ->assertSee('ACCEPTED BY TRANSPORT')
            ->assertDontSee('recipient@example.test');

        $this->actingAs($owner)
            ->get(route('console.campaign-logs.history', [$workspace->getKey(), $campaign->getKey(), 'updates']))
            ->assertOk()
            ->assertSee('Update history')
            ->assertSee('Revision 2');

        $this->actingAs($owner)
            ->get(route('console.campaign-logs.history', [$workspace->getKey(), $campaign->getKey(), 'sends']))
            ->assertOk()
            ->assertSee('Send history')
            ->assertSee('DELAY')
            ->assertSee('COMPLETED')
            ->assertSee('1');
    }

    public function test_restriction_is_audited_and_blocks_campaign_send_until_unrestricted(): void
    {
        $fixture = $this->campaignFixture();
        $owner = $fixture['owner'];
        $workspace = $fixture['workspace'];
        $campaign = $fixture['campaign'];
        $restrictionUrl = route('console.campaign-logs.restriction', [$workspace->getKey(), $campaign->getKey()]);

        CampaignSendRun::query()->where('campaign_id', $campaign->getKey())->update(['state' => 'running']);

        $this->actingAs($owner)
            ->patch($restrictionUrl, ['is_restricted' => 1])
            ->assertRedirect()
            ->assertSessionHasErrors('is_restricted');

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->getKey(), 'is_restricted' => false]);
        CampaignSendRun::query()->where('campaign_id', $campaign->getKey())->update(['state' => 'completed']);

        $this->actingAs($owner)
            ->patch($restrictionUrl, ['is_restricted' => 1])
            ->assertRedirect();

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->getKey(), 'is_restricted' => true]);
        $this->assertDatabaseHas('audit_events', [
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $owner->getKey(),
            'action' => 'campaign.restriction_changed',
            'subject_id' => $campaign->getKey(),
        ]);

        $this->actingAs($owner)
            ->post(route('console.campaigns.send', [$workspace->getKey(), $campaign->getKey()]), [
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertStatus(409)
            ->assertSee('This campaign is restricted.');

        $this->actingAs($owner)
            ->patch($restrictionUrl, ['is_restricted' => 0])
            ->assertRedirect();

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->getKey(), 'is_restricted' => false]);
        $this->assertSame(2, AuditEvent::query()->where('action', 'campaign.restriction_changed')->count());
    }

    public function test_campaign_history_detail_shows_the_recorded_revision_config_without_test_recipients(): void
    {
        $fixture = $this->campaignFixture();
        $owner = $fixture['owner'];
        $workspace = $fixture['workspace'];
        $campaign = $fixture['campaign'];
        $manualTestId = $this->insertManualTestAttempt($fixture);
        $savedTestId = (string) DB::table('campaign_test_mail_attempts')->where('campaign_id', $campaign->getKey())->value('id');
        $update = CampaignRevision::query()->where('campaign_id', $campaign->getKey())->where('revision_number', 2)->firstOrFail();
        $sendRun = CampaignSendRun::query()->where('campaign_id', $campaign->getKey())->firstOrFail();

        $this->actingAs($owner)
            ->get(route('console.campaign-logs.history', [$workspace->getKey(), $campaign->getKey(), 'tests']).'?fragment=1')
            ->assertOk()
            ->assertSee('data-campaign-history-tab', false)
            ->assertSee('data-campaign-detail-url', false);

        $testUrl = route('console.campaign-logs.history-detail', [$workspace->getKey(), $campaign->getKey(), 'tests', $savedTestId]).'?source=saved&fragment=1';
        $this->actingAs($owner)
            ->get($testUrl)
            ->assertOk()
            ->assertSee('Synthetic test subject')
            ->assertSee('Synthetic campaign body')
            ->assertSee('Test delivery server')
            ->assertSee('To: [recipient hidden]')
            ->assertDontSee('recipient@example.test');

        $manualTestUrl = route('console.campaign-logs.history-detail', [$workspace->getKey(), $campaign->getKey(), 'tests', $manualTestId]).'?source=manual&fragment=1';
        $this->actingAs($owner)
            ->get($manualTestUrl)
            ->assertOk()
            ->assertSee('Manual test subject')
            ->assertSee('Test delivery server')
            ->assertSee('To: [recipient hidden]')
            ->assertDontSee('recipient@example.test');

        $this->actingAs($owner)
            ->get(route('console.campaign-logs.history-detail', [$workspace->getKey(), $campaign->getKey(), 'updates', $update->getKey()]).'?fragment=1')
            ->assertOk()
            ->assertSee('Synthetic update subject')
            ->assertSee('Updated campaign body');

        $this->actingAs($owner)
            ->get(route('console.campaign-logs.history-detail', [$workspace->getKey(), $campaign->getKey(), 'sends', $sendRun->getKey()]).'?fragment=1')
            ->assertOk()
            ->assertSee('Synthetic test subject')
            ->assertSee('Synthetic campaign body')
            ->assertSee('Test delivery server');
    }

    public function test_campaign_logs_are_workspace_scoped_and_respect_role_access(): void
    {
        $fixture = $this->campaignFixture();
        $owner = $fixture['owner'];
        $workspace = $fixture['workspace'];
        $foreignOwner = User::factory()->create();
        $foreignWorkspace = $this->workspaceFor($foreignOwner);
        $foreignCampaign = $foreignWorkspace->campaigns()->create([
            'created_by' => $foreignOwner->getKey(),
            'name' => 'Foreign synthetic campaign',
            'kind' => 'marketing',
            'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->actingAs($owner)
            ->get(route('console.campaign-logs.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('Synthetic campaign log')
            ->assertDontSee('Foreign synthetic campaign');

        $this->actingAs($owner)
            ->get(route('console.campaign-logs.history', [$workspace->getKey(), $foreignCampaign->getKey(), 'tests']))
            ->assertNotFound();

        $privacyOfficer = User::factory()->create();
        $workspace->users()->attach($privacyOfficer->getKey(), ['role' => 'privacy_officer']);
        $this->actingAs($privacyOfficer)
            ->get(route('console.campaign-logs.index', $workspace->getKey()))
            ->assertForbidden();
    }

    /** @return array{owner: User, workspace: Workspace, campaign: Campaign} */
    private function campaignFixture(): array
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $campaign = $workspace->campaigns()->create([
            'created_by' => $owner->getKey(),
            'name' => 'Synthetic campaign log',
            'kind' => 'marketing',
            'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $sponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Synthetic logs sponsor',
            'affiliate_id' => (string) Str::uuid(),
            'url' => 'https://sponsor.example.test',
            'username' => 'synthetic',
            'password' => 'synthetic-password',
            'api_key' => 'synthetic-api-key',
            'type' => 'everflow',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $offer = WorkspaceOffer::query()->create([
            'workspace_id' => $workspace->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => (string) Str::uuid(),
            'name' => 'Synthetic campaign offer',
            'type' => 'CPA',
            'vertical' => 'general',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://offer.example.test',
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $isp = DB::table('isps')->insertGetId([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Gmail',
            'is_active' => true,
            'allowed_users' => json_encode([]),
            'allowed_sponsors' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $server = ManagedServer::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Test delivery server',
            'provider' => 'Synthetic provider',
            'ip_address' => '192.0.2.15',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'synthetic managed server'),
            'created_by' => $owner->getKey(),
        ]);

        $firstRevision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(),
            'revision_number' => 1,
            'payload' => [
                'return_path' => 'bounce@example.test',
                'header' => "To: recipient@example.test\nSubject: Synthetic test subject",
                'body' => '<p>Synthetic campaign body</p>',
                'send_setup' => ['isp_id' => $isp, 'offer_id' => $offer->getKey(), 'send_type' => 'delay', 'route_option' => 'ip', 'server_ids' => [$server->getKey()], 'ip_ids' => ['test-ip-route'], 'total_send' => 50],
            ],
            'content_sha256' => hash('sha256', 'synthetic campaign revision one'),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'synthetic revision one'),
            'created_by' => $owner->getKey(),
        ]);
        CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(),
            'revision_number' => 2,
            'payload' => [
                'return_path' => 'bounce@example.test',
                'header' => "To: recipient@example.test\nSubject: Synthetic update subject",
                'body' => '<p>Updated campaign body</p>',
                'send_setup' => ['isp_id' => $isp, 'offer_id' => $offer->getKey(), 'send_type' => 'delay', 'route_option' => 'ip', 'server_ids' => [$server->getKey()], 'ip_ids' => ['test-ip-route'], 'total_send' => 75],
            ],
            'content_sha256' => hash('sha256', 'synthetic campaign revision two'),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'synthetic revision two'),
            'created_by' => $owner->getKey(),
        ]);
        DB::table('campaign_test_mail_attempts')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->getKey(),
            'campaign_id' => $campaign->getKey(),
            'revision_id' => $firstRevision->getKey(),
            'actor_user_id' => $owner->getKey(),
            'recipient_sha256' => hash('sha256', 'synthetic recipient'),
            'idempotency_key_hash' => hash('sha256', 'synthetic test attempt'),
            'mailer' => 'pmta',
            'status' => 'accepted_by_transport',
            'exception_type' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $snapshot = CampaignRecipientSnapshot::query()->create([
            'workspace_id' => $workspace->getKey(),
            'campaign_id' => $campaign->getKey(),
            'revision_id' => $firstRevision->getKey(),
            'offer_id' => $offer->getKey(),
            'suppression_sha256' => hash('sha256', 'synthetic suppression set'),
            'selection' => ['audience_segments' => ['synthetic-list_fresh']],
            'recipient_count' => 1,
            'recipients_sha256' => hash('sha256', 'synthetic recipient selection'),
            'created_at' => now(),
        ]);
        CampaignSendRun::query()->create([
            'workspace_id' => $workspace->getKey(),
            'campaign_id' => $campaign->getKey(),
            'revision_id' => $firstRevision->getKey(),
            'recipient_snapshot_id' => $snapshot->getKey(),
            'requested_by' => $owner->getKey(),
            'send_type' => 'delay',
            'state' => 'completed',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'synthetic send run'),
            'recipient_count' => 1,
            'accepted_count' => 1,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        return compact('owner', 'workspace', 'campaign', 'server');
    }

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Campaign Logs workspace',
            'slug' => 'campaign-logs-'.Str::lower(Str::random(10)),
            'created_by' => $user->getKey(),
        ]);
        $workspace->users()->attach($user->getKey(), ['role' => 'owner']);

        return $workspace;
    }

    /** @param array{owner: User, workspace: Workspace, campaign: Campaign, server: ManagedServer} $fixture */
    private function insertManualTestAttempt(array $fixture): string
    {
        $id = (string) Str::uuid();
        DB::table('campaign_manual_test_attempts')->insert([
            'id' => $id,
            'workspace_id' => $fixture['workspace']->getKey(),
            'campaign_id' => $fixture['campaign']->getKey(),
            'actor_user_id' => $fixture['owner']->getKey(),
            'managed_server_id' => $fixture['server']->getKey(),
            'recipient_sha256' => hash('sha256', 'manual synthetic recipient'),
            'idempotency_key_hash' => hash('sha256', 'manual synthetic test '.$id),
            'request_fingerprint' => hash('sha256', 'manual synthetic request '.$id),
            'status' => 'accepted_by_transport',
            'exception_type' => null,
            'test_snapshot' => Crypt::encryptString(json_encode([
                'return_path' => 'bounce@example.test',
                'header' => "To: recipient@example.test\nSubject: Manual test subject",
                'body' => '<p>Manual synthetic body</p>',
            ], JSON_THROW_ON_ERROR)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
