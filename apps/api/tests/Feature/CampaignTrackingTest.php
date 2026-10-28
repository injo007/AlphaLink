<?php

namespace Tests\Feature;

use App\Http\Middleware\RewriteLegacyWorkspaceUrls;
use App\Jobs\ProcessCampaignTrackingEventJob;
use App\Models\AudienceList;
use App\Models\Campaign;
use App\Models\CampaignEngagementEvent;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRecipientSnapshotItem;
use App\Models\CampaignRevision;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\Isp;
use App\Models\ManagedServer;
use App\Models\SuppressionEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAppSettings;
use App\Models\WorkspaceInfrastructureSettings;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use App\Services\Analytics\ImportDmsPmtaFeedback;
use App\Services\Audiences\AudienceListHistoryCalculator;
use App\Services\Campaigns\CampaignIpGeoResolver;
use App\Services\Campaigns\CampaignRecipientSourceIdentity;
use App\Services\Campaigns\ProcessCampaignTrackingEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class CampaignTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pmta_campaign_feedback_matches_immutable_recipient_and_updates_daily_counters(): void
    {
        $fixture = $this->recipientFixture();
        $server = ManagedServer::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(), 'name' => 'Campaign PMTA',
            'provider' => 'Synthetic provider', 'ip_address' => '198.51.100.35',
            'ssh_port' => 22, 'ssh_username' => 'root', 'state' => 'verified',
            'is_active' => true, 'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('c', 64),
            'created_by' => $fixture['owner']->getKey(),
        ]);
        $compact = fn (mixed $value): string => str_replace('-', '', (string) $value);
        $token = implode('-', [
            $compact($fixture['campaign']->getKey()), $compact($fixture['snapshot']->getKey()),
            $compact($fixture['contact']->getKey()), 'f', $compact($fixture['list']->getKey()),
            (string) $fixture['isp']->getKey(), $compact($server->getKey()), '1', '1', '1', '1', '1', 'app', 'c',
        ]);
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, [today()->toDateString(), 'ok', 'recipient@example.test', $token], escape: '');
        rewind($stream);

        $counts = app(ImportDmsPmtaFeedback::class)->import($server, $stream, 'delivery', 'campaign-delivery.csv', today()->toDateString());

        $this->assertSame(['imported' => 1, 'duplicate' => 0, 'unmatched' => 0], $counts);
        $this->assertSame('delivery', $fixture['membership']->fresh()->email_type);
        $this->assertSame(1, (int) DB::table('workspace_daily_analytics')
            ->where('workspace_id', $fixture['workspace']->getKey())
            ->where('campaign_id', $fixture['campaign']->getKey())->value('deliveries'));
        fclose($stream);
    }

    public function test_campaign_redirect_resolves_the_offer_from_the_immutable_recipient_snapshot(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key', 'infrastructure.ip_api_key' => null]);
        $fixture = $this->recipientFixture();
        $fixture['offer']->forceFill(['geo_target' => []])->save();

        $this->postJson('/api/v1/campaign', $this->redirectPayload($fixture))
            ->assertOk()
            ->assertExactJson([
                'type' => 'everflow',
                'link' => 'https://offer.example.test',
                'user' => $fixture['owner']->getKey(),
            ]);
    }

    public function test_campaign_unsubscribe_redirect_uses_the_offer_default_when_no_url_is_saved(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key', 'infrastructure.ip_api_key' => null]);
        $fixture = $this->recipientFixture();
        $fixture['offer']->forceFill(['geo_target' => [], 'unsubscribe' => null])->save();
        $payload = $this->redirectPayload($fixture, 'unsub');
        $payload['email_id'] = '0';
        $payload['send_id'] = '0';
        $payload['list_id'] = '0';

        $this->postJson('/api/v1/campaign', $payload)
            ->assertOk()
            ->assertExactJson(['link' => 'unsubscribe?u=1']);
    }

    public function test_campaign_redirect_rejects_a_recipient_already_marked_as_blocked(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key', 'infrastructure.ip_api_key' => null]);
        $fixture = $this->recipientFixture();
        $fixture['membership']->forceFill(['email_type' => 'blacklist'])->save();

        $this->postJson('/api/v1/campaign', $this->redirectPayload($fixture))
            ->assertNotFound()
            ->assertContent('"EBE"');
        $this->assertSame(0, CampaignEngagementEvent::query()->count());
    }

    public function test_campaign_redirect_applies_offer_geo_and_records_proxy_bans_once(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key', 'infrastructure.ip_api_key' => null]);
        $fixture = $this->recipientFixture();
        WorkspaceAppSettings::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'settings' => [
                'ip_api_key' => 'synthetic-ip-api-key',
                'filter_geo' => true,
                'block_proxies' => true,
                'allowed_proxies' => [],
            ],
        ]);
        Http::fake([
            'https://pro.ip-api.com/json/*' => Http::response([
                'status' => 'success', 'countryCode' => 'US', 'country' => 'United States',
                'regionName' => 'Test Region', 'city' => 'Test City', 'proxy' => true,
                'hosting' => false, 'org' => 'Synthetic Proxy Network',
            ]),
        ]);
        $payload = $this->redirectPayload($fixture);

        $this->postJson('/api/v1/campaign', $payload)->assertNotFound()->assertContent('"TBE"');
        $this->postJson('/api/v1/campaign', $payload)->assertNotFound()->assertContent('"EBE"');

        $this->assertSame('proxy', $fixture['membership']->fresh()->email_type);
        $this->assertSame(1, DB::table('contact_list_membership_events')->where('contact_id', $fixture['contact']->getKey())->count());
        $this->assertSame(1, CampaignEngagementEvent::query()->where('action', 'ban')->count());
    }

    public function test_campaign_redirect_returns_the_dms_not_found_code_for_an_unknown_campaign(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key', 'infrastructure.ip_api_key' => null]);
        $fixture = $this->recipientFixture();
        $payload = $this->redirectPayload($fixture);
        $payload['campaign_id'] = (string) Str::uuid();

        $this->postJson('/api/v1/campaign', $payload)
            ->assertNotFound()
            ->assertContent('"NFE"');
    }

    public function test_campaign_redirect_rejects_an_ip_outside_the_offer_geo_target(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key', 'infrastructure.ip_api_key' => null]);
        $fixture = $this->recipientFixture();
        WorkspaceAppSettings::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'settings' => ['ip_api_key' => 'synthetic-ip-api-key', 'filter_geo' => true],
        ]);
        Http::fake([
            'https://pro.ip-api.com/json/*' => Http::response([
                'status' => 'success', 'countryCode' => 'CA', 'country' => 'Canada',
                'regionName' => 'Test Region', 'city' => 'Test City', 'proxy' => false,
                'hosting' => false, 'org' => 'Synthetic Network',
            ]),
        ]);

        $this->postJson('/api/v1/campaign', $this->redirectPayload($fixture))
            ->assertNotFound()
            ->assertContent('"GTE"');
        $this->assertSame('fresh', $fixture['membership']->fresh()->email_type);
        $this->assertSame(0, CampaignEngagementEvent::query()->count());
    }

    public function test_campaign_engagement_events_persist_advance_membership_and_update_dashboard(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key']);
        $fixture = $this->recipientFixture();
        $url = '/api/v1/tracking/campaign-events';

        $this->postJson($url, $this->trackingPayload($fixture, 'open'))->assertNoContent();
        $this->assertSame('open', $fixture['membership']->fresh()->email_type);
        $this->postJson($url, $this->trackingPayload($fixture, 'click'))->assertNoContent();
        $this->assertSame('click', $fixture['membership']->fresh()->email_type);
        $this->postJson($url, $this->trackingPayload($fixture, 'unsubscribe'))->assertNoContent();
        $this->assertSame('unsubscribe', $fixture['membership']->fresh()->email_type);

        $this->assertSame(3, CampaignEngagementEvent::query()->count());
        $this->assertSame(3, DB::table('contact_list_membership_events')->where('contact_id', $fixture['contact']->getKey())->count());
        $this->assertDatabaseHas('campaign_engagement_events', [
            'campaign_id' => $fixture['campaign']->getKey(),
            'contact_id' => $fixture['contact']->getKey(),
            'audience_list_id' => $fixture['list']->getKey(),
            'action' => 'open',
            'token_sha256' => hash('sha256', 'synthetic-tracking-token'),
        ]);
        $this->assertDatabaseHas('suppression_events', [
            'workspace_id' => $fixture['workspace']->getKey(),
            'contact_id' => $fixture['contact']->getKey(),
            'reason' => 'unsubscribe',
            'scope_type' => 'offer',
            'scope_key' => (string) $fixture['offer']->getKey(),
            'source' => 'campaign_unsubscribe',
        ]);

        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);

        $this->withoutMiddleware(RewriteLegacyWorkspaceUrls::class);

        $this->actingAs($fixture['owner'])
            ->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 1
                && $totals['clicks'] === 1
                && $totals['unsubscribes'] === 1
                && $totals['leads'] === 0
            );
        $this->get(route('clean.console.analytics.all-stats', [
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ]))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows): bool => $rows->count() === 3
                && $rows->firstWhere('type', 'click')['source'] === 'Campaign Synthetic tracking campaign'
            );
        $this->get(route('clean.console.analytics.isp-stats', [
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
            'isp_id' => $fixture['isp']->getKey(),
            'groupby' => 'day',
        ]))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows): bool => $rows->count() === 1
                && $rows->first()['opens'] === 1
                && $rows->first()['clicks'] === 1
                && $rows->first()['unsubscribes'] === 1
            );
        $this->get(route('clean.console.analytics.mailer-revenue', [
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ]))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows): bool => $rows->count() === 1
                && $rows->first()['opens'] === 1
                && $rows->first()['clicks'] === 1
                && $rows->first()['unsubscribes'] === 1
            );

        $this->postJson($url, $this->trackingPayload($fixture, 'open'))->assertNoContent();
        $this->assertSame('unsubscribe', $fixture['membership']->fresh()->email_type);
        $this->assertSame(4, CampaignEngagementEvent::query()->count());
        $this->assertSame(3, DB::table('contact_list_membership_events')->where('contact_id', $fixture['contact']->getKey())->count());
        $this->assertSame(1, SuppressionEvent::query()->count());
    }

    public function test_campaign_event_updates_every_selected_source_membership_and_preserves_unselected_memberships(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key']);
        $fixture = $this->recipientFixture();
        $selectedList = $this->additionalList($fixture, 'Second selected campaign list');
        $unselectedList = $this->additionalList($fixture, 'Unselected campaign list');
        $selectedMembership = ContactListMembership::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'audience_list_id' => $selectedList->getKey(),
            'contact_id' => $fixture['contact']->getKey(),
            'email_type' => 'delivery',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $unselectedMembership = ContactListMembership::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'audience_list_id' => $unselectedList->getKey(),
            'contact_id' => $fixture['contact']->getKey(),
            'email_type' => 'fresh',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $snapshotSources = [
            [
                'source_membership_id' => $fixture['membership']->getKey(),
                'source_list_id' => $fixture['list']->getKey(),
                'source_email_type' => 'fresh',
            ],
            [
                'source_membership_id' => $selectedMembership->getKey(),
                'source_list_id' => $selectedList->getKey(),
                'source_email_type' => 'delivery',
            ],
        ];
        foreach ($snapshotSources as $source) {
            DB::table('campaign_recipient_snapshot_item_sources')->insert([
                'id' => (string) Str::uuid(),
                'workspace_id' => $fixture['workspace']->getKey(),
                'snapshot_item_id' => $fixture['item']->getKey(),
                'source_membership_id' => $source['source_membership_id'],
                'source_list_id' => $source['source_list_id'],
                'source_email_type' => $source['source_email_type'],
                'source_key' => CampaignRecipientSourceIdentity::key(
                    (string) $source['source_membership_id'],
                    (string) $source['source_list_id'],
                    $source['source_email_type'],
                ),
                'created_at' => now(),
            ]);
        }

        $payload = $this->trackingPayload($fixture, 'open');
        $payload['list_id'] = (string) $selectedList->getKey();
        $payload['type'] = 'd';
        foreach (['open', 'click', 'unsubscribe'] as $action) {
            $payload['action'] = $action;
            $this->postJson('/api/v1/tracking/campaign-events', $payload)->assertNoContent();
        }

        $this->assertSame('unsubscribe', $fixture['membership']->fresh()->email_type);
        $this->assertSame('unsubscribe', $selectedMembership->fresh()->email_type);
        $this->assertSame('fresh', $unselectedMembership->fresh()->email_type);
        $this->assertSame(3, CampaignEngagementEvent::query()->count());
        $this->assertSame(6, DB::table('campaign_engagement_event_sources')->count());
        $this->assertSame(6, DB::table('contact_list_membership_events')
            ->where('contact_id', $fixture['contact']->getKey())->count());
        $this->assertEqualsCanonicalizing(
            array_merge(array_fill(0, 3, $fixture['list']->getKey()), array_fill(0, 3, $selectedList->getKey())),
            DB::table('contact_list_membership_events')
                ->where('contact_id', $fixture['contact']->getKey())
                ->pluck('audience_list_id')
                ->all(),
        );
        $this->assertDatabaseHas('campaign_engagement_events', [
            'snapshot_item_id' => $fixture['item']->getKey(),
            'audience_list_id' => $selectedList->getKey(),
            'source_email_type' => 'delivery',
            'action' => 'unsubscribe',
        ]);
        $this->assertSame(1, SuppressionEvent::query()
            ->where('workspace_id', $fixture['workspace']->getKey())
            ->where('contact_id', $fixture['contact']->getKey())
            ->where('scope_type', 'offer')
            ->where('scope_key', (string) $fixture['offer']->getKey())
            ->count());

        $forgedSource = $payload;
        $forgedSource['list_id'] = (string) $unselectedList->getKey();
        $forgedSource['type'] = 'f';
        $this->postJson('/api/v1/tracking/campaign-events', $forgedSource)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('snapshot_item_id');
        $this->assertSame(3, CampaignEngagementEvent::query()->count());
    }

    public function test_multi_list_country_move_keeps_both_memberships_when_the_destination_list_collides(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key', 'infrastructure.ip_api_key' => null]);
        $fixture = $this->recipientFixture();
        $secondList = $this->additionalList($fixture, 'Country move second source');
        $secondMembership = ContactListMembership::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'audience_list_id' => $secondList->getKey(),
            'contact_id' => $fixture['contact']->getKey(),
            'email_type' => 'delivery',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $fixture['isp']->forceFill(['tracked_countries' => ['US']])->save();
        $fixture['list']->forceFill(['is_tracked' => true])->save();
        $secondList->forceFill(['is_tracked' => true])->save();
        foreach ([
            [$fixture['membership'], $fixture['list'], 'fresh'],
            [$secondMembership, $secondList, 'delivery'],
        ] as [$membership, $list, $type]) {
            DB::table('campaign_recipient_snapshot_item_sources')->insert([
                'id' => (string) Str::uuid(),
                'workspace_id' => $fixture['workspace']->getKey(),
                'snapshot_item_id' => $fixture['item']->getKey(),
                'source_membership_id' => $membership->getKey(),
                'source_list_id' => $list->getKey(),
                'source_email_type' => $type,
                'source_key' => CampaignRecipientSourceIdentity::key(
                    (string) $membership->getKey(),
                    (string) $list->getKey(),
                    $type,
                ),
                'created_at' => now(),
            ]);
        }
        WorkspaceAppSettings::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'settings' => ['ip_api_key' => 'synthetic-country-api-key'],
        ]);
        Http::fake([
            'https://pro.ip-api.com/json/*' => Http::response([
                'status' => 'success', 'countryCode' => 'US', 'country' => 'United States',
                'regionName' => 'Test Region', 'city' => 'Test City', 'proxy' => false,
                'hosting' => false, 'org' => 'Synthetic Network',
            ]),
        ]);

        $payload = $this->trackingPayload($fixture, 'open');
        $this->postJson('/api/v1/tracking/campaign-events', $payload)->assertNoContent();

        $this->assertSame('open', $fixture['membership']->fresh()->email_type);
        $this->assertSame('open', $secondMembership->fresh()->email_type);
        $this->assertSame(2, DB::table('contact_list_membership_events')
            ->where('contact_id', $fixture['contact']->getKey())->count());
        $this->assertSame(1, AudienceList::query()
            ->where('workspace_id', $fixture['workspace']->getKey())
            ->where('isp_id', $fixture['isp']->getKey())
            ->where('name', 'US')->count());
        $this->assertSame(2, ContactListMembership::query()
            ->where('workspace_id', $fixture['workspace']->getKey())
            ->where('contact_id', $fixture['contact']->getKey())
            ->where('email_type', 'open')->count());
    }

    public function test_tracked_country_moves_campaign_membership_and_old_snapshot_events_follow_it(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key', 'infrastructure.ip_api_key' => null]);
        $fixture = $this->recipientFixture();
        $fixture['isp']->forceFill(['tracked_countries' => ['US']])->save();
        $fixture['list']->forceFill([
            'is_tracked' => true,
            'allowed_users' => ['operator-1'],
            'allowed_sponsors' => ['sponsor-1'],
            'is_open_locked' => true,
            'is_click_locked' => true,
        ])->save();
        WorkspaceAppSettings::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'settings' => ['ip_api_key' => 'synthetic-country-api-key'],
        ]);
        Http::fake([
            'https://pro.ip-api.com/json/*' => Http::response([
                'status' => 'success', 'countryCode' => 'US', 'country' => 'United States',
                'regionName' => 'Test Region', 'city' => 'Test City', 'proxy' => false,
                'hosting' => false, 'org' => 'Synthetic Network',
            ]),
        ]);

        $url = '/api/v1/tracking/campaign-events';
        $this->postJson($url, $this->trackingPayload($fixture, 'open'))->assertNoContent();

        $countryList = AudienceList::query()->where('workspace_id', $fixture['workspace']->getKey())
            ->where('isp_id', $fixture['isp']->getKey())->where('name', 'US')->firstOrFail();
        $membership = $fixture['membership']->fresh();
        $this->assertSame((string) $countryList->getKey(), (string) $membership->audience_list_id);
        $this->assertSame('open', $membership->email_type);
        $this->assertSame(['operator-1'], $countryList->allowed_users);
        $this->assertSame(['sponsor-1'], $countryList->allowed_sponsors);
        $this->assertTrue($countryList->is_open_locked);
        $this->assertTrue($countryList->is_click_locked);
        $this->assertDatabaseHas('campaign_engagement_events', [
            'campaign_id' => $fixture['campaign']->getKey(),
            'audience_list_id' => $fixture['list']->getKey(),
            'country' => 'United States',
            'action' => 'open',
        ]);
        $this->assertDatabaseHas('contact_list_membership_events', [
            'audience_list_id' => $countryList->getKey(),
            'contact_id' => $fixture['contact']->getKey(),
            'action' => 'open',
        ]);

        $this->postJson($url, $this->trackingPayload($fixture, 'click'))->assertNoContent();
        $membership->refresh();
        $this->assertSame((string) $countryList->getKey(), (string) $membership->audience_list_id);
        $this->assertSame('click', $membership->email_type);
        $this->assertSame(1, AudienceList::query()->where('workspace_id', $fixture['workspace']->getKey())
            ->where('name', 'US')->count());
    }

    public function test_configured_blocked_organization_discards_opens_but_keeps_clicks(): void
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

        $this->postJson('/api/v1/tracking/campaign-events', $this->trackingPayload($fixture, 'open'))
            ->assertNoContent();
        $this->assertSame(0, CampaignEngagementEvent::query()->count());
        $this->assertSame('fresh', $fixture['membership']->fresh()->email_type);
        $this->assertSame(0, DB::table('contact_list_membership_events')->count());

        $this->postJson('/api/v1/tracking/campaign-events', $this->trackingPayload($fixture, 'click'))
            ->assertNoContent();
        $this->assertSame(1, CampaignEngagementEvent::query()->where('action', 'click')->count());
        $this->assertSame('click', $fixture['membership']->fresh()->email_type);
    }

    public function test_test_recipient_events_are_no_ops_and_do_not_change_list_state(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key']);
        $fixture = $this->recipientFixture();
        $payload = $this->trackingPayload($fixture, 'open');
        $payload['email_id'] = '0';
        $payload['list_id'] = '0';
        $payload['snapshot_item_id'] = null;

        $this->postJson('/api/v1/tracking/campaign-events', $payload)->assertNoContent();
        $this->assertSame(0, CampaignEngagementEvent::query()->count());
        $this->assertSame('fresh', $fixture['membership']->fresh()->email_type);
    }

    public function test_engagement_location_and_agent_metadata_feed_the_visitors_table_and_country_chart(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key', 'infrastructure.ip_api_key' => null]);
        $fixture = $this->recipientFixture();
        WorkspaceAppSettings::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'settings' => ['ip_api_key' => 'synthetic-ip-api-key'],
        ]);
        Http::fake([
            'https://pro.ip-api.com/json/*' => Http::response([
                'status' => 'success', 'countryCode' => 'US', 'country' => 'United States',
                'regionName' => 'Test Region', 'city' => 'Test City', 'proxy' => false,
                'hosting' => false, 'org' => 'Synthetic Network',
            ]),
        ]);
        $payload = $this->trackingPayload($fixture, 'open');
        $payload['user_agent'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

        $this->postJson('/api/v1/tracking/campaign-events', $payload)->assertNoContent();
        $event = CampaignEngagementEvent::query()->firstOrFail();
        $this->assertSame('United States', $event->country);
        $this->assertSame('Test Region', $event->state);
        $this->assertSame('Test City', $event->city);
        $this->assertSame('iPhone', $event->device);
        $this->assertSame('iOS', $event->platform);
        $this->assertSame('Safari', $event->browser);
        $this->assertTrue($event->is_mobile);

        $visitorsUrl = route('console.campaigns.visitors', [
            $fixture['workspace']->getKey(), $fixture['campaign']->getKey(),
        ]);
        $this->actingAs($fixture['owner'])->getJson($visitorsUrl.'?draw=1&length=20')
            ->assertOk()
            ->assertJsonPath('data.0.country', 'United States')
            ->assertJsonPath('data.0.device', 'iPhone')
            ->assertJsonPath('data.0.is_mobile', true);
        $this->getJson($visitorsUrl.'?chart=1')
            ->assertOk()
            ->assertJsonPath('0.country', 'United States')
            ->assertJsonPath('0.open', 1)
            ->assertJsonPath('0.click', 0);
    }

    public function test_campaign_stats_accepts_the_callback_then_queues_five_retry_processing(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key']);
        $fixture = $this->recipientFixture();
        Queue::fake();

        $this->postJson('/api/v1/tracking/campaign-events', $this->trackingPayload($fixture, 'open'))
            ->assertNoContent();

        Queue::assertPushed(ProcessCampaignTrackingEventJob::class, fn (ProcessCampaignTrackingEventJob $job): bool => $job->tries === 5
            && $job->data['action'] === 'open'
            && $job->campaign->is($fixture['campaign'])
            && $job->workspace->is($fixture['workspace']));
        $this->assertSame(0, CampaignEngagementEvent::query()->count());
    }

    public function test_tracking_queue_retry_is_idempotent_but_a_separate_open_request_is_counted(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key']);
        $fixture = $this->recipientFixture();
        Queue::fake();

        $this->postJson('/api/v1/tracking/campaign-events', $this->trackingPayload($fixture, 'open'))
            ->assertNoContent();

        $firstJob = Queue::pushed(ProcessCampaignTrackingEventJob::class)->first();
        $this->assertNotNull($firstJob);
        $this->assertTrue(Str::isUuid($firstJob->data['tracking_event_id']));
        $firstJob->handle(app(ProcessCampaignTrackingEvent::class));
        $firstJob->handle(app(ProcessCampaignTrackingEvent::class));

        $this->assertSame(1, CampaignEngagementEvent::query()->count());
        $this->assertSame(1, DB::table('contact_list_membership_events')
            ->where('contact_id', $fixture['contact']->getKey())->count());

        $this->postJson('/api/v1/tracking/campaign-events', $this->trackingPayload($fixture, 'open'))
            ->assertNoContent();
        $jobs = Queue::pushed(ProcessCampaignTrackingEventJob::class);
        $secondJob = $jobs->last();
        $this->assertNotNull($secondJob);
        $this->assertNotSame($firstJob->data['tracking_event_id'], $secondJob->data['tracking_event_id']);
        $secondJob->handle(app(ProcessCampaignTrackingEvent::class));

        $this->assertSame(2, CampaignEngagementEvent::query()->count());
        $this->assertSame(1, DB::table('contact_list_membership_events')
            ->where('contact_id', $fixture['contact']->getKey())->count());
    }

    public function test_tracking_request_id_cannot_be_reused_for_a_different_event_payload(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key']);
        $fixture = $this->recipientFixture();
        Queue::fake();

        $this->postJson('/api/v1/tracking/campaign-events', $this->trackingPayload($fixture, 'open'))
            ->assertNoContent();

        $job = Queue::pushed(ProcessCampaignTrackingEventJob::class)->first();
        $this->assertNotNull($job);
        $job->handle(app(ProcessCampaignTrackingEvent::class));
        $job->data['action'] = 'click';

        $this->expectException(LogicException::class);
        $job->handle(app(ProcessCampaignTrackingEvent::class));
    }

    public function test_dms_stats_callback_reaches_list_history_through_the_database_queue_worker(): void
    {
        config([
            'tracking.ingest_key' => 'synthetic-tracking-key',
            'infrastructure.ip_api_key' => null,
            'queue.default' => 'database',
            'queue.connections.database.connection' => null,
        ]);
        $fixture = $this->recipientFixture();

        $this->postJson('/api/v1/stats', $this->trackingPayload($fixture, 'open'))->assertNoContent();

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, CampaignEngagementEvent::query()->count());
        $this->assertSame('fresh', $fixture['membership']->fresh()->email_type);

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--quiet' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, CampaignEngagementEvent::query()->where('action', 'open')->count());
        $this->assertSame(1, DB::table('contact_list_membership_events')->where('contact_id', $fixture['contact']->getKey())->count());
        $this->assertSame('open', $fixture['membership']->fresh()->email_type);

        $this->artisan('audience-lists:calculate-history', ['listId' => $fixture['list']->getKey()])
            ->assertExitCode(0);
        $this->actingAs($fixture['owner'])
            ->postJson(route('console.infrastructure.list-history.data', $fixture['workspace']->getKey()), [
                'isp' => $fixture['isp']->getKey(),
                'list' => $fixture['list']->getKey(),
                'from' => now()->toDateString(),
                'to' => now()->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('histories.0.open', 1)
            ->assertJsonPath('histories.0.fresh', 0);
    }

    public function test_ip_geo_cache_is_scoped_to_the_workspace_settings(): void
    {
        config(['infrastructure.ip_api_key' => null]);
        $first = $this->recipientFixture();
        $second = $this->recipientFixture();
        WorkspaceAppSettings::query()->create([
            'workspace_id' => $first['workspace']->getKey(),
            'settings' => ['ip_api_key' => 'first-workspace-key'],
        ]);
        WorkspaceAppSettings::query()->create([
            'workspace_id' => $second['workspace']->getKey(),
            'settings' => ['ip_api_key' => 'second-workspace-key'],
        ]);
        $requestCount = 0;
        Http::fake(function (HttpRequest $request) use (&$requestCount) {
            $requestCount++;
            $country = $requestCount === 1 ? 'United States' : 'Canada';

            return Http::response([
                'status' => 'success',
                'countryCode' => $requestCount === 1 ? 'US' : 'CA',
                'country' => $country,
                'regionName' => null,
                'city' => null,
                'proxy' => false,
                'hosting' => false,
                'org' => 'Synthetic network',
            ]);
        });
        $resolver = app(CampaignIpGeoResolver::class);

        $firstLocation = $resolver->resolve($first['workspace'], '203.0.113.88');
        $secondLocation = $resolver->resolve($second['workspace'], '203.0.113.88');

        $this->assertSame('United States', $firstLocation['country']);
        $this->assertSame('Canada', $secondLocation['country']);
        $this->assertSame(2, $requestCount);
    }

    public function test_bot_event_updates_the_daily_audience_list_count(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key']);
        $fixture = $this->recipientFixture();

        $this->postJson('/api/v1/tracking/campaign-events', $this->trackingPayload($fixture, 'bot'))
            ->assertNoContent();

        $this->assertSame('bot', $fixture['membership']->fresh()->email_type);
        $history = app(AudienceListHistoryCalculator::class)->calculate($fixture['list']);

        $this->assertSame(1, $history->bot);
        $this->assertSame(0, $history->fresh);
    }

    public function test_tracking_key_is_required_and_compared_before_persisting(): void
    {
        config(['tracking.ingest_key' => null, 'infrastructure.bulk_install_settings.mini_app_key' => '']);
        $fixture = $this->recipientFixture();
        $payload = $this->trackingPayload($fixture, 'open');

        $this->postJson('/api/v1/tracking/campaign-events', $payload)->assertStatus(503);
        config(['tracking.ingest_key' => 'configured-tracking-key']);
        $this->postJson('/api/v1/tracking/campaign-events', $payload)->assertForbidden();
        $this->assertSame(0, CampaignEngagementEvent::query()->count());
    }

    public function test_dms_stats_route_accepts_the_saved_sender_install_key(): void
    {
        config(['tracking.ingest_key' => null, 'infrastructure.bulk_install_settings.mini_app_key' => '']);
        $fixture = $this->recipientFixture();
        WorkspaceInfrastructureSettings::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'mini_app_key' => 'synthetic-installed-mini-app-key',
        ]);
        $payload = $this->trackingPayload($fixture, 'open');
        $payload['key'] = 'synthetic-installed-mini-app-key';

        $this->postJson('/api/v1/stats', $payload)->assertNoContent();
        $this->assertSame(1, CampaignEngagementEvent::query()->count());
        $this->assertSame('open', $fixture['membership']->fresh()->email_type);
    }

    public function test_workspace_tracking_key_rejects_the_global_fallback_key(): void
    {
        config(['tracking.ingest_key' => 'synthetic-global-key']);
        $fixture = $this->recipientFixture();
        WorkspaceInfrastructureSettings::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'mini_app_key' => 'synthetic-workspace-key',
        ]);
        $payload = $this->trackingPayload($fixture, 'open');
        $payload['key'] = 'synthetic-global-key';

        $this->postJson('/api/v1/stats', $payload)->assertForbidden();
        $this->assertSame(0, CampaignEngagementEvent::query()->count());
    }

    public function test_installed_tracker_compact_ids_resolve_the_saved_snapshot_item(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key']);
        $fixture = $this->recipientFixture();
        $payload = $this->trackingPayload($fixture, 'open');
        $payload['campaign_id'] = str_replace('-', '', (string) $fixture['campaign']->getKey());
        $payload['send_id'] = str_replace('-', '', (string) $fixture['snapshot']->getKey());
        $payload['email_id'] = str_replace('-', '', (string) $fixture['contact']->getKey());
        $payload['list_id'] = str_replace('-', '', (string) $fixture['list']->getKey());
        unset($payload['snapshot_item_id']);

        $this->postJson('/api/v1/stats', $payload)->assertNoContent();
        $this->assertDatabaseHas('campaign_engagement_events', [
            'snapshot_item_id' => $fixture['item']->getKey(),
            'contact_id' => $fixture['contact']->getKey(),
            'action' => 'open',
        ]);
    }

    public function test_dms_stats_route_accepts_the_install_fallback_when_workspace_settings_are_absent(): void
    {
        config(['tracking.ingest_key' => null, 'infrastructure.bulk_install_settings.mini_app_key' => 'synthetic-fallback-install-key']);
        $fixture = $this->recipientFixture();
        $payload = $this->trackingPayload($fixture, 'open');
        $payload['key'] = 'synthetic-fallback-install-key';
        $payload['email_id'] = '0';
        $payload['list_id'] = '0';
        $payload['snapshot_item_id'] = null;

        $this->postJson('/api/v1/stats', $payload)->assertNoContent();
        $this->assertSame(0, CampaignEngagementEvent::query()->count());
    }

    public function test_saved_empty_install_key_does_not_fall_back_to_the_global_install_key(): void
    {
        config(['tracking.ingest_key' => null, 'infrastructure.bulk_install_settings.mini_app_key' => 'synthetic-fallback-install-key']);
        $fixture = $this->recipientFixture();
        WorkspaceInfrastructureSettings::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'mini_app_key' => '',
        ]);
        $payload = $this->trackingPayload($fixture, 'open');
        $payload['key'] = 'synthetic-fallback-install-key';
        $payload['email_id'] = '0';
        $payload['list_id'] = '0';
        $payload['snapshot_item_id'] = null;

        $this->postJson('/api/v1/stats', $payload)->assertStatus(503);
        $this->assertSame(0, CampaignEngagementEvent::query()->count());
    }

    public function test_event_must_match_the_saved_campaign_recipient_snapshot(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key']);
        $fixture = $this->recipientFixture();
        $payload = $this->trackingPayload($fixture, 'open');
        $payload['email_id'] = (string) Str::uuid();

        $this->postJson('/api/v1/tracking/campaign-events', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('snapshot_item_id');
        $this->assertSame(0, CampaignEngagementEvent::query()->count());
        $this->assertSame('fresh', $fixture['membership']->fresh()->email_type);
    }

    public function test_dms_stats_actions_exclude_lead_events(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key']);
        $fixture = $this->recipientFixture();
        $payload = $this->trackingPayload($fixture, 'lead');

        $this->postJson('/api/v1/tracking/campaign-events', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');
        $this->assertSame(0, CampaignEngagementEvent::query()->count());
    }

    /** @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private function trackingPayload(array $fixture, string $action): array
    {
        return [
            'key' => 'synthetic-tracking-key',
            'action' => $action,
            'ip_address' => '203.0.113.25',
            'user_agent' => 'AlphaLink synthetic tracker',
            'campaign_id' => (string) $fixture['campaign']->getKey(),
            'send_id' => 'send-1',
            'email_id' => (string) $fixture['contact']->getKey(),
            'snapshot_item_id' => (string) $fixture['item']->getKey(),
            'type' => 'f',
            'list_id' => (string) $fixture['list']->getKey(),
            'isp_id' => $fixture['isp']->getKey(),
            'server_id' => '0',
            'ip_id' => '0',
            'domain_id' => '0',
            'from_id' => '0',
            'subject_id' => '0',
            'creative_id' => '0',
            'token' => 'synthetic-tracking-token',
        ];
    }

    /** @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private function redirectPayload(array $fixture, string $type = 'red'): array
    {
        return [
            'key' => 'synthetic-tracking-key',
            'campaign_id' => (string) $fixture['campaign']->getKey(),
            'email_id' => (string) $fixture['contact']->getKey(),
            'isp_id' => $fixture['isp']->getKey(),
            'token' => 'synthetic-campaign-redirect-token',
            'ip_address' => '203.0.113.25',
            'user_agent' => 'AlphaLink synthetic tracking server',
            'type' => $type,
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function additionalList(array $fixture, string $name): AudienceList
    {
        return AudienceList::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'created_by' => $fixture['owner']->getKey(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', $name),
            'isp_id' => $fixture['isp']->getKey(),
            'country' => 'US',
            'allowed_users' => [],
            'allowed_sponsors' => [],
        ]);
    }

    /** @return array<string, mixed> */
    private function recipientFixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Campaign tracking workspace',
            'slug' => 'campaign-tracking-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);
        $sponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'Synthetic tracking sponsor',
            'affiliate_id' => (string) Str::uuid(), 'url' => 'https://affiliate.example.test',
            'username' => 'fixture-user', 'password' => 'fixture-password', 'api_key' => 'fixture-api-key',
            'type' => 'everflow', 'allowed_users' => [], 'is_active' => true,
        ]);
        $offer = WorkspaceOffer::query()->create([
            'workspace_id' => $workspace->getKey(), 'sponsor_id' => $sponsor->getKey(),
            'offer_id' => 'tracking-fixture-offer', 'name' => 'Synthetic tracking offer',
            'type' => 'cpa', 'vertical' => 'testing', 'geo_target' => ['US'], 'payout' => 1,
            'redirect' => 'https://offer.example.test', 'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live', 'allowed_users' => [], 'is_active' => true,
        ]);
        $isp = Isp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Tracking fixture ISP',
            'is_active' => true,
            'allowed_users' => [],
            'allowed_sponsors' => [],
            'tracked_countries' => [],
            'quality_emails' => [],
        ]);
        $list = AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
            'name' => 'Tracking fixture audience', 'slug' => 'tracking-fixture-audience', 'status' => 'active',
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'tracking-fixture-list'),
            'isp_id' => $isp->getKey(),
        ]);
        $contact = Contact::query()->create([
            'workspace_id' => $workspace->getKey(), 'email' => 'tracking-fixture@example.test',
            'email_lookup_hash' => hash('sha256', 'tracking-fixture@example.test'), 'lookup_key_version' => 1,
            'status' => 'active', 'idempotency_key' => (string) Str::uuid(),
        ]);
        $membership = ContactListMembership::query()->create([
            'workspace_id' => $workspace->getKey(), 'audience_list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(), 'email_type' => 'fresh', 'status' => 'active', 'joined_at' => now(),
        ]);
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
            'name' => 'Synthetic tracking campaign', 'kind' => 'marketing', 'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(), 'revision_number' => 1,
            'payload' => ['send_setup' => ['offer_id' => $offer->getKey()]],
            'content_sha256' => hash('sha256', 'tracking-fixture-revision'),
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'tracking-fixture-revision-request'),
            'created_by' => $owner->getKey(), 'created_at' => now(),
        ]);
        $snapshot = CampaignRecipientSnapshot::query()->create([
            'workspace_id' => $workspace->getKey(), 'campaign_id' => $campaign->getKey(),
            'revision_id' => $revision->getKey(), 'offer_id' => $offer->getKey(),
            'suppression_sha256' => hash('sha256', 'empty suppression'),
            'selection' => ['audience_segments' => [$list->getKey().'_fresh']],
            'recipient_count' => 1, 'recipients_sha256' => hash('sha256', (string) $contact->getKey()),
            'created_at' => now(),
        ]);
        $item = CampaignRecipientSnapshotItem::query()->create([
            'workspace_id' => $workspace->getKey(), 'snapshot_id' => $snapshot->getKey(),
            'contact_id' => $contact->getKey(), 'position' => 1,
            'email_lookup_hash' => $contact->email_lookup_hash, 'source_list_id' => $list->getKey(),
            'source_email_type' => 'fresh', 'created_at' => now(),
        ]);

        return compact('owner', 'workspace', 'offer', 'isp', 'list', 'contact', 'membership', 'campaign', 'revision', 'snapshot', 'item');
    }
}
