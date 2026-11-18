<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRevision;
use App\Models\CampaignSendRun;
use App\Models\ManagedServer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use App\Services\Campaigns\CampaignAutoFailoverService;
use App\Jobs\ProcessCampaignSendRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignAutoFailoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([ProcessCampaignSendRun::class]);
    }

    public function test_auto_failover_service_detects_error_patterns(): void
    {
        $owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $workspace = $this->workspaceFor($owner);
        $campaign = $this->campaignFor($workspace, $owner);

        $run = $this->createSendRun($workspace, $campaign, $owner, [
            'auto_failover_enabled' => true,
            'failover_error_patterns' => '421, rate limit, too many connections, temporarily deferred',
            'failover_threshold' => 3,
            'failover_purge_spool' => false,
            'disabled_domains' => [],
            'domain_error_counts' => [],
            'failover_events' => [],
        ]);

        $service = app(CampaignAutoFailoverService::class);

        $this->assertTrue($service->isEnabled($run));
        $this->assertTrue($service->matchesErrorPattern($run, '421 4.7.0 [TSS04] Message temporarily deferred'));
        $this->assertTrue($service->matchesErrorPattern($run, 'SMTP Error: 421 Rate limit exceeded'));
        $this->assertTrue($service->matchesErrorPattern($run, 'Connection rejected: too many connections'));
        $this->assertFalse($service->matchesErrorPattern($run, '550 5.1.1 User unknown'));
    }

    public function test_auto_failover_service_increments_and_triggers_circuit_breaker(): void
    {
        $owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $workspace = $this->workspaceFor($owner);
        $campaign = $this->campaignFor($workspace, $owner, [
            'domain' => 'primary.com',
            'vmta_domains' => ['domain1.com', 'domain2.com'],
        ]);

        $run = $this->createSendRun($workspace, $campaign, $owner, [
            'auto_failover_enabled' => true,
            'failover_error_patterns' => '421, rate limit',
            'failover_threshold' => 2,
            'failover_purge_spool' => false,
            'disabled_domains' => [],
            'domain_error_counts' => [],
            'failover_events' => [],
        ]);

        $service = app(CampaignAutoFailoverService::class);

        // First error: count = 1, below threshold 2
        $res1 = $service->recordAttemptError($run, 'domain1.com', '421 4.7.0 Rate limit exceeded');
        $this->assertFalse($res1['triggered']);
        $this->assertSame('incremented', $res1['action']);

        $run->refresh();
        $this->assertSame(1, $service->getDomainErrorCounts($run)['domain1.com']);
        $this->assertSame('running', $run->state);

        // Second error: reaches threshold 2 -> triggers failover!
        $res2 = $service->recordAttemptError($run, 'domain1.com', '421 4.7.0 Rate limit exceeded');
        $this->assertTrue($res2['triggered']);
        $this->assertSame('resumed_with_remaining', $res2['action']);

        $run->refresh();
        $this->assertContains('domain1.com', $service->getDisabledDomains($run));
        $events = $service->getFailoverEvents($run);
        $this->assertCount(1, $events);
        $this->assertSame('domain1.com', $events[0]['domain']);
        $this->assertSame('domain_removed', $events[0]['action']);
    }

    public function test_all_domains_disabled_pauses_send_run_safely(): void
    {
        $owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $workspace = $this->workspaceFor($owner);
        $campaign = $this->campaignFor($workspace, $owner, [
            'domain' => 'onlydomain.com',
            'vmta_domains' => ['onlydomain.com'],
        ]);

        $run = $this->createSendRun($workspace, $campaign, $owner, [
            'auto_failover_enabled' => true,
            'failover_error_patterns' => '421',
            'failover_threshold' => 1,
            'failover_purge_spool' => false,
            'disabled_domains' => [],
            'domain_error_counts' => [],
            'failover_events' => [],
        ]);

        $service = app(CampaignAutoFailoverService::class);
        $res = $service->recordAttemptError($run, 'onlydomain.com', '421 Service not available');

        $this->assertTrue($res['triggered']);
        $this->assertSame('paused_all_disabled', $res['action']);

        $run->refresh();
        $this->assertSame('paused', $run->state);
    }

    public function test_restore_domain_re_enables_domain_in_rotation(): void
    {
        $owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $workspace = $this->workspaceFor($owner);
        $campaign = $this->campaignFor($workspace, $owner, [
            'domain' => 'domain1.com',
            'vmta_domains' => ['domain1.com'],
        ]);

        $run = $this->createSendRun($workspace, $campaign, $owner, [
            'auto_failover_enabled' => true,
            'disabled_domains' => ['domain1.com'],
            'domain_error_counts' => ['domain1.com' => 5],
            'failover_events' => [],
        ], 'paused');

        $service = app(CampaignAutoFailoverService::class);
        $result = $service->restoreDomain($run, 'domain1.com');

        $this->assertTrue($result['restored']);
        $run->refresh();
        $this->assertNotContains('domain1.com', $service->getDisabledDomains($run));
        $this->assertArrayNotHasKey('domain1.com', $service->getDomainErrorCounts($run));
        $this->assertSame('running', $run->state);
    }

    public function test_telemetry_endpoint_returns_valid_circuit_breaker_data(): void
    {
        $owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $workspace = $this->workspaceFor($owner);
        $campaign = $this->campaignFor($workspace, $owner, [
            'domain' => 'testfailover.com',
            'auto_failover_enabled' => true,
            'failover_threshold' => 10,
        ]);

        $run = $this->createSendRun($workspace, $campaign, $owner, [
            'auto_failover_enabled' => true,
            'failover_threshold' => 10,
            'failover_error_patterns' => '421, rate limit',
            'failover_purge_spool' => true,
            'disabled_domains' => ['bad-domain.com'],
            'domain_error_counts' => ['bad-domain.com' => 10],
            'failover_events' => [
                [
                    'id' => (string) Str::uuid(),
                    'timestamp' => now()->toIso8601String(),
                    'domain' => 'bad-domain.com',
                    'action' => 'domain_removed',
                    'message' => 'Deferred threshold reached',
                    'purged_spool' => true,
                ],
            ],
        ]);
        $run->update(['accepted_count' => 10, 'rejected_count' => 2]);

        $response = $this->actingAs($owner)
            ->get(route('console.campaigns.telemetry', [$workspace->getKey(), $campaign->getKey()]))
            ->assertOk();

        $response->assertJson([
            'campaign_id' => $campaign->getKey(),
            'auto_failover_enabled' => true,
            'threshold' => 10,
            'disabled_domains' => ['bad-domain.com'],
            'send_run' => [
                'state' => 'running',
                'total' => 100,
                'accepted' => 10,
            ],
        ]);
    }

    public function test_restore_domain_endpoint_operates_cleanly(): void
    {
        $owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $workspace = $this->workspaceFor($owner);
        $campaign = $this->campaignFor($workspace, $owner);

        $run = $this->createSendRun($workspace, $campaign, $owner, [
            'auto_failover_enabled' => true,
            'disabled_domains' => ['temp-deferred.com'],
            'domain_error_counts' => ['temp-deferred.com' => 5],
            'failover_events' => [],
        ], 'paused');

        $response = $this->actingAs($owner)
            ->postJson(route('console.campaigns.telemetry.restore-domain', [$workspace->getKey(), $campaign->getKey()]), [
                'domain' => 'temp-deferred.com',
            ])
            ->assertOk();

        $response->assertJson(['restored' => true]);

        $run->refresh();
        $this->assertNotContains('temp-deferred.com', (array) data_get($run->execution_options, 'disabled_domains'));
    }

    public function test_campaigns_index_renders_telemetry_toggle_button_and_drawer(): void
    {
        $owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $workspace = $this->workspaceFor($owner);
        $campaign = $this->campaignFor($workspace, $owner);

        $response = $this->actingAs($owner)
            ->get(route('console.campaigns.index', $workspace->getKey()))
            ->assertOk();

        $response->assertSee('data-campaign-telemetry-toggle', false);
        $response->assertSee('id="campaign-telemetry-'.$campaign->getKey().'"', false);
        $response->assertSee('data-campaign-telemetry-panel', false);
    }

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Auto-Failover Test Workspace',
            'slug' => 'failover-'.Str::lower(Str::random(10)),
            'created_by' => $user->getKey(),
        ]);
        $workspace->users()->attach($user->getKey(), ['role' => 'owner']);

        return $workspace;
    }

    private function campaignFor(Workspace $workspace, User $user, array $extraSetup = []): Campaign
    {
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $user->getKey(),
            'name' => 'Failover Test Campaign',
            'kind' => 'marketing',
            'status' => 'draft',
            'is_active' => true,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(),
            'revision_number' => 1,
            'payload' => [
                'send_setup' => array_merge([
                    'route_option' => 'vmta',
                    'ip_type' => 'ipv4',
                    'server_ids' => [],
                    'ip_ids' => [],
                    'domain' => 'default.com',
                    'auto_failover_enabled' => false,
                ], $extraSetup),
            ],
            'content_sha256' => hash('sha256', 'dummy'),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'dummy'),
            'created_by' => $user->getKey(),
            'created_at' => now(),
        ]);

        return $campaign;
    }

    private function createSendRun(Workspace $workspace, Campaign $campaign, User $owner, array $options, string $state = 'running'): CampaignSendRun
    {
        $revision = $campaign->latestRevision;

        $sponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Sponsor '.Str::random(5),
            'affiliate_id' => (string) Str::uuid(),
            'url' => 'https://sponsor.test',
            'username' => 'fixture-user',
            'password' => 'fixture-password',
            'api_key' => 'fixture-api-key',
            'type' => 'everflow',
            'allowed_users' => [],
            'is_active' => true,
            'status' => 'active',
        ]);

        $offer = WorkspaceOffer::query()->create([
            'workspace_id' => $workspace->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => (string) Str::uuid(),
            'name' => 'Synthetic offer',
            'type' => 'CPA',
            'vertical' => 'general',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://example.test',
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live',
            'allowed_users' => [],
            'is_active' => true,
            'suppression_path' => 'synthetic.txt',
            'suppression_sha256' => str_repeat('a', 64),
            'suppression_format' => 'email',
            'suppression_updated_at' => now()->toDateString(),
        ]);

        $snapshot = CampaignRecipientSnapshot::query()->create([
            'workspace_id' => $workspace->getKey(),
            'campaign_id' => $campaign->getKey(),
            'revision_id' => $revision->getKey(),
            'offer_id' => $offer->getKey(),
            'suppression_sha256' => str_repeat('a', 64),
            'selection' => [],
            'recipient_count' => 100,
            'recipients_sha256' => str_repeat('b', 64),
            'created_at' => now(),
        ]);

        return CampaignSendRun::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->getKey(),
            'campaign_id' => $campaign->getKey(),
            'revision_id' => $revision->getKey(),
            'recipient_snapshot_id' => $snapshot->getKey(),
            'requested_by' => $owner->getKey(),
            'send_type' => 'delay',
            'state' => $state,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', Str::random(10)),
            'execution_options' => $options,
            'recipient_count' => 100,
            'start_position' => 1,
            'next_position' => 1,
        ]);
    }
}
