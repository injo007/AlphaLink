<?php

namespace Tests\Feature;

use App\Http\Middleware\RewriteLegacyWorkspaceUrls;
use App\Jobs\ProcessDropTrackingEventJob;
use App\Models\AudienceList;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\ContactListMembershipEvent;
use App\Models\Isp;
use App\Models\SuppressionEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAppSettings;
use App\Models\WorkspaceDrop;
use App\Models\WorkspaceDropVisitor;
use App\Models\WorkspaceInfrastructureSettings;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use App\Services\Audiences\AudienceListHistoryCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DropTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_installed_tracker_drop_redirect_returns_the_selected_offer(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key']);
        $fixture = $this->recipientFixture();
        $payload = [
            'key' => 'synthetic-tracking-key',
            'drop_id' => $fixture['drop']->getKey(),
            'email_id' => (string) $fixture['contact']->getKey(),
            'isp_id' => 0,
            'token' => 'synthetic-drop-token',
            'ip_address' => '203.0.113.31',
            'user_agent' => 'AlphaLink synthetic tracker',
            'type' => 'red',
        ];

        $this->postJson('/api/v1/drop', $payload)->assertOk()->assertJsonPath('link', 'https://offer.example.test');
        $payload['key'] = 'wrong';
        $this->postJson('/api/v1/drop', $payload)->assertForbidden();
    }

    public function test_dms_stats_drop_events_record_visitors_update_list_state_and_exclude_unsubscribed_offer(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key']);
        $fixture = $this->recipientFixture();
        $url = '/api/v1/stats-drop';

        $this->postJson($url, $this->payload($fixture, 'open'))->assertNoContent();
        $this->assertSame('open', $fixture['membership']->fresh()->email_type);
        $this->postJson($url, $this->payload($fixture, 'click'))->assertNoContent();
        $this->assertSame('click', $fixture['membership']->fresh()->email_type);
        $this->postJson($url, $this->payload($fixture, 'unsubscribe'))->assertNoContent();
        $this->assertSame('unsubscribe', $fixture['membership']->fresh()->email_type);

        $this->assertSame(3, WorkspaceDropVisitor::query()->count());
        $this->assertSame(3, ContactListMembershipEvent::query()->where('source', 'drop_tracking')->count());
        $this->assertDatabaseHas('workspace_drop_visitors', [
            'workspace_id' => $fixture['workspace']->getKey(),
            'workspace_drop_id' => $fixture['drop']->getKey(),
            'type' => 'open',
            'email_id' => $fixture['contact']->getKey(),
            'list_id' => $fixture['list']->getKey(),
            'list_type' => 'fresh',
            'token_sha256' => hash('sha256', 'synthetic-drop-tracking-token'),
        ]);
        $this->assertDatabaseHas('suppression_events', [
            'workspace_id' => $fixture['workspace']->getKey(),
            'contact_id' => $fixture['contact']->getKey(),
            'reason' => 'unsubscribe',
            'scope_type' => 'offer',
            'scope_key' => (string) $fixture['offer']->getKey(),
            'source' => 'drop_unsubscribe',
        ]);

        app(AudienceListHistoryCalculator::class)->calculate($fixture['list']);
        $this->assertSame(1, (int) $fixture['list']->history()->firstOrFail()->unsubscribe);

        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);

        $this->withoutMiddleware(RewriteLegacyWorkspaceUrls::class);

        $this->actingAs($fixture['owner'])
            ->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 1
                && $totals['clicks'] === 1
                && $totals['unsubscribes'] === 1);
        $this->get(route('clean.console.analytics.all-stats', [
            'from' => today()->toDateString(), 'to' => today()->toDateString(),
        ]))->assertOk()->assertViewHas('rows', fn ($rows): bool => $rows->total() === 3);

        $this->postJson($url, $this->payload($fixture, 'open'))->assertNoContent();
        $this->assertSame(4, WorkspaceDropVisitor::query()->count());
        $this->assertSame('unsubscribe', $fixture['membership']->fresh()->email_type);
        $this->assertSame(3, ContactListMembershipEvent::query()->where('source', 'drop_tracking')->count());
        $this->assertSame(1, SuppressionEvent::query()->count());
    }

    public function test_tracked_country_moves_drop_membership_and_old_drop_events_follow_it(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key', 'infrastructure.ip_api_key' => null]);
        $fixture = $this->recipientFixture();
        $isp = Isp::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'name' => 'Tracked-country Drop ISP',
            'is_active' => true,
            'allowed_users' => [],
            'allowed_sponsors' => [],
            'tracked_countries' => ['US'],
            'quality_emails' => [],
        ]);
        $fixture['list']->forceFill([
            'isp_id' => $isp->getKey(),
            'is_tracked' => true,
            'allowed_users' => ['operator-2'],
            'allowed_sponsors' => ['sponsor-2'],
            'is_delivery_locked' => true,
            'is_lead_locked' => true,
        ])->save();
        WorkspaceAppSettings::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'settings' => ['ip_api_key' => 'synthetic-drop-country-api-key'],
        ]);
        Http::fake([
            'https://pro.ip-api.com/json/*' => Http::response([
                'status' => 'success', 'countryCode' => 'US', 'country' => 'United States',
                'regionName' => 'Test Region', 'city' => 'Test City', 'proxy' => false,
                'hosting' => false, 'org' => 'Synthetic Network',
            ]),
        ]);

        $url = '/api/v1/stats-drop';
        $this->postJson($url, $this->payload($fixture, 'open'))->assertNoContent();
        $countryList = AudienceList::query()->where('workspace_id', $fixture['workspace']->getKey())
            ->where('isp_id', $isp->getKey())->where('name', 'US')->firstOrFail();
        $this->assertSame((string) $countryList->getKey(), (string) $fixture['membership']->fresh()->audience_list_id);
        $this->assertSame('open', $fixture['membership']->fresh()->email_type);
        $this->assertSame(['operator-2'], $countryList->allowed_users);
        $this->assertSame(['sponsor-2'], $countryList->allowed_sponsors);
        $this->assertTrue($countryList->is_delivery_locked);
        $this->assertTrue($countryList->is_lead_locked);
        $this->assertDatabaseHas('workspace_drop_visitors', [
            'workspace_drop_id' => $fixture['drop']->getKey(),
            'list_id' => $fixture['list']->getKey(),
            'type' => 'open',
            'country' => 'United States',
        ]);

        $this->postJson($url, $this->payload($fixture, 'click'))->assertNoContent();
        $this->assertSame((string) $countryList->getKey(), (string) $fixture['membership']->fresh()->audience_list_id);
        $this->assertSame('click', $fixture['membership']->fresh()->email_type);
        $this->assertDatabaseHas('contact_list_membership_events', [
            'audience_list_id' => $countryList->getKey(),
            'contact_id' => $fixture['contact']->getKey(),
            'action' => 'click',
        ]);
    }

    public function test_configured_blocked_organization_discards_drop_opens_but_keeps_clicks(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key', 'infrastructure.ip_api_key' => null]);
        $fixture = $this->recipientFixture();
        WorkspaceAppSettings::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'settings' => [
                'ip_api_key' => 'synthetic-ip-api-key',
                'blocked_open_traffic' => ['synthetic proxy'],
            ],
        ]);
        Http::fake([
            'https://pro.ip-api.com/json/*' => Http::response([
                'status' => 'success', 'countryCode' => 'US', 'country' => 'United States',
                'regionName' => 'Test Region', 'city' => 'Test City', 'proxy' => true,
                'hosting' => false, 'org' => 'Synthetic Proxy Network',
            ]),
        ]);

        $this->postJson('/api/v1/stats-drop', $this->payload($fixture, 'open'))->assertNoContent();
        $this->assertSame(0, WorkspaceDropVisitor::query()->count());
        $this->assertSame('fresh', $fixture['membership']->fresh()->email_type);
        $this->assertSame(0, ContactListMembershipEvent::query()->count());

        $this->postJson('/api/v1/stats-drop', $this->payload($fixture, 'click'))->assertNoContent();
        $this->assertSame(1, WorkspaceDropVisitor::query()->where('type', 'click')->count());
        $this->assertSame('click', $fixture['membership']->fresh()->email_type);
    }

    public function test_drop_tracking_requires_configured_shared_key_and_test_recipients_are_noops(): void
    {
        config(['tracking.ingest_key' => null, 'infrastructure.bulk_install_settings.mini_app_key' => '']);
        $fixture = $this->recipientFixture();
        $payload = $this->payload($fixture, 'open');

        $this->postJson('/api/v1/stats-drop', $payload)->assertStatus(503);
        config(['tracking.ingest_key' => 'configured-tracking-key']);
        $this->postJson('/api/v1/stats-drop', $payload)->assertForbidden();

        $payload['key'] = 'configured-tracking-key';
        $payload['email_id'] = '0';
        $payload['list_id'] = '0';
        $this->postJson('/api/v1/stats-drop', $payload)->assertNoContent();
        $this->assertSame(0, WorkspaceDropVisitor::query()->count());
        $this->assertSame('fresh', $fixture['membership']->fresh()->email_type);
    }

    public function test_dms_stats_drop_route_accepts_the_saved_sender_install_key(): void
    {
        config(['tracking.ingest_key' => null, 'infrastructure.bulk_install_settings.mini_app_key' => '']);
        $fixture = $this->recipientFixture();
        WorkspaceInfrastructureSettings::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'mini_app_key' => 'synthetic-installed-mini-app-key',
        ]);
        $payload = $this->payload($fixture, 'open');
        $payload['key'] = 'synthetic-installed-mini-app-key';

        $this->postJson('/api/v1/stats-drop', $payload)->assertNoContent();
        $this->assertSame(1, WorkspaceDropVisitor::query()->count());
        $this->assertSame('open', $fixture['membership']->fresh()->email_type);
    }

    public function test_drop_visitor_logs_store_location_and_agent_metadata(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key', 'infrastructure.ip_api_key' => null]);
        $fixture = $this->recipientFixture();
        WorkspaceAppSettings::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'settings' => ['ip_api_key' => 'synthetic-ip-api-key'],
        ]);
        Http::fake([
            'https://pro.ip-api.com/json/*' => Http::response([
                'status' => 'success', 'countryCode' => 'CA', 'country' => 'Canada',
                'regionName' => 'Test Province', 'city' => 'Test City', 'proxy' => false,
                'hosting' => false, 'org' => 'Synthetic Network',
            ]),
        ]);
        $payload = $this->payload($fixture, 'click');
        $payload['user_agent'] = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';

        $this->postJson('/api/v1/stats-drop', $payload)->assertNoContent();

        $visitor = WorkspaceDropVisitor::query()->firstOrFail();
        $this->assertSame('Canada', $visitor->country);
        $this->assertSame('Test Province', $visitor->state);
        $this->assertSame('Test City', $visitor->city);
        $this->assertSame('Android', $visitor->device);
        $this->assertSame('Android', $visitor->platform);
        $this->assertSame('Chrome', $visitor->browser);
        $this->assertTrue($visitor->is_mobile);
    }

    public function test_drop_stats_accepts_the_callback_then_queues_five_retry_processing(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key']);
        $fixture = $this->recipientFixture();
        Queue::fake();

        $this->postJson('/api/v1/stats-drop', $this->payload($fixture, 'open'))
            ->assertNoContent();

        Queue::assertPushed(ProcessDropTrackingEventJob::class, fn (ProcessDropTrackingEventJob $job): bool => $job->tries === 5
            && $job->data['action'] === 'open'
            && $job->drop->is($fixture['drop'])
            && $job->workspace->is($fixture['workspace']));
        $this->assertSame(0, WorkspaceDropVisitor::query()->count());
    }

    /** @return array<string, mixed> */
    private function recipientFixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Drop tracking workspace',
            'slug' => 'drop-tracking-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);
        $sponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Synthetic Drop sponsor',
            'affiliate_id' => 'drop-tracking-affiliate',
            'url' => 'https://affiliate.example.test',
            'username' => 'fixture-user',
            'password' => 'fixture-password',
            'api_key' => 'fixture-api-key',
            'type' => 'everflow',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $offer = WorkspaceOffer::query()->create([
            'workspace_id' => $workspace->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => 'drop-tracking-offer',
            'name' => 'Synthetic Drop offer',
            'type' => 'cpa',
            'vertical' => 'testing',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://offer.example.test',
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $list = AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Drop tracking audience',
            'slug' => 'drop-tracking-audience',
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'drop-tracking-list'),
        ]);
        $contact = Contact::query()->create([
            'workspace_id' => $workspace->getKey(),
            'email' => 'drop-tracking@example.test',
            'email_lookup_hash' => hash('sha256', 'drop-tracking@example.test'),
            'lookup_key_version' => 1,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $membership = ContactListMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(),
            'email_type' => 'fresh',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $drop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create([
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'lists' => [(string) $list->getKey()],
        ]);

        return compact('owner', 'workspace', 'sponsor', 'offer', 'list', 'contact', 'membership', 'drop');
    }

    /** @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private function payload(array $fixture, string $action): array
    {
        return [
            'key' => 'synthetic-tracking-key',
            'action' => $action,
            'ip_address' => '203.0.113.31',
            'user_agent' => 'AlphaLink synthetic Drop tracker',
            'drop_id' => $fixture['drop']->getKey(),
            'email_id' => (string) $fixture['contact']->getKey(),
            'type' => 'f',
            'list_id' => (string) $fixture['list']->getKey(),
            'isp_id' => 0,
            'server_id' => '0',
            'ip_id' => '0',
            'domain_id' => '0',
            'from_id' => '0',
            'subject_id' => '0',
            'creative_id' => '0',
            'token' => 'synthetic-drop-tracking-token',
        ];
    }
}
