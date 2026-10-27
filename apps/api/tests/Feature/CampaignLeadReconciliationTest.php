<?php

namespace Tests\Feature;

use App\Models\AudienceList;
use App\Models\AudienceListHistory;
use App\Models\Campaign;
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
use App\Models\WorkspaceAppSettings;
use App\Models\WorkspaceDrop;
use App\Models\WorkspaceDropVisitor;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use App\Services\AffiliateNetworks\DmsAffiliateProviderHttp;
use App\Services\AffiliateNetworks\DmsAffiliateProviderSalesReader;
use App\Services\AffiliateNetworks\DmsSponsorLeadImporter;
use App\Services\Audiences\AudienceListHistoryCalculator;
use App\Services\Campaigns\CampaignIpGeoResolver;
use App\Services\Campaigns\DmsCampaignAffiliateAttribution;
use App\Services\Campaigns\DmsDropAffiliateAttribution;
use App\Services\Campaigns\TrackedCountryListManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignLeadReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_events_and_reconciled_leads_feed_dashboard_reports_and_list_history(): void
    {
        config(['tracking.ingest_key' => 'synthetic-tracking-key', 'infrastructure.ip_api_key' => null]);
        $fixture = $this->recipientFixture();
        $unsubscribedContact = Contact::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'email' => 'unsubscribed-fixture@example.test',
            'email_lookup_hash' => hash('sha256', 'unsubscribed-fixture@example.test'),
            'lookup_key_version' => 1,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $unsubscribedMembership = ContactListMembership::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'audience_list_id' => $fixture['list']->getKey(),
            'contact_id' => $unsubscribedContact->getKey(),
            'email_type' => 'fresh',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $unsubscribedRevision = CampaignRevision::query()->create([
            'campaign_id' => $fixture['campaign']->getKey(),
            'revision_number' => 2,
            'payload' => ['send_setup' => ['offer_id' => $fixture['offer']->getKey()]],
            'content_sha256' => hash('sha256', 'synthetic-unsubscribe-revision'),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'synthetic-unsubscribe-revision-request'),
            'created_by' => $fixture['owner']->getKey(),
            'created_at' => now(),
        ]);
        $unsubscribedSnapshot = CampaignRecipientSnapshot::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'campaign_id' => $fixture['campaign']->getKey(),
            'revision_id' => $unsubscribedRevision->getKey(),
            'offer_id' => $fixture['offer']->getKey(),
            'suppression_sha256' => hash('sha256', 'synthetic-unsubscribe-suppression'),
            'selection' => ['audience_segments' => [$fixture['list']->getKey().'_fresh']],
            'recipient_count' => 1,
            'recipients_sha256' => hash('sha256', (string) $unsubscribedContact->getKey()),
            'created_at' => now(),
        ]);
        $unsubscribedItem = CampaignRecipientSnapshotItem::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'snapshot_id' => $unsubscribedSnapshot->getKey(),
            'contact_id' => $unsubscribedContact->getKey(),
            'position' => 1,
            'email_lookup_hash' => $unsubscribedContact->email_lookup_hash,
            'source_list_id' => $fixture['list']->getKey(),
            'source_email_type' => 'fresh',
            'created_at' => now(),
        ]);

        $this->postJson('/api/v1/stats', $this->trackingPayload($fixture, $fixture['item'], $fixture['contact'], 'open'))
            ->assertNoContent();
        $this->postJson('/api/v1/stats', $this->trackingPayload($fixture, $fixture['item'], $fixture['contact'], 'click'))
            ->assertNoContent();
        $this->postJson('/api/v1/stats', $this->trackingPayload($fixture, $unsubscribedItem, $unsubscribedContact, 'unsubscribe'))
            ->assertNoContent();

        $report = $this->hitPathReport($fixture, '6.0000');
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($report) {
            if (str_starts_with($request->url(), 'https://pro.ip-api.com/json/')) {
                return Http::response([
                    'status' => 'success', 'countryCode' => 'US', 'country' => 'United States',
                    'regionName' => null, 'city' => null, 'proxy' => false, 'hosting' => false,
                    'org' => 'Synthetic Network',
                ]);
            }

            return Http::response($report);
        });
        $importer = new DmsSponsorLeadImporter(
            new DmsAffiliateProviderSalesReader(new DmsAffiliateProviderHttp(static fn (string $host): array => ['93.184.216.34'])),
            new DmsCampaignAffiliateAttribution,
            new DmsDropAffiliateAttribution,
            new CampaignIpGeoResolver,
            new TrackedCountryListManager,
        );
        $result = $importer->import($fixture['sponsor'], now()->subDay()->startOfDay(), now()->endOfDay());

        $this->assertSame(['read' => 1, 'created' => 1, 'updated' => 0, 'skipped' => 0], $result);
        $this->assertSame('lead', $fixture['membership']->fresh()->email_type);
        $this->assertSame('unsubscribe', $unsubscribedMembership->fresh()->email_type);
        $history = app(AudienceListHistoryCalculator::class)->calculate($fixture['list'], Carbon::today());
        $this->assertSame(1, (int) $history->lead);
        $this->assertSame(1, (int) $history->unsubscribe);

        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);
        $this->actingAs($fixture['owner'])
            ->get(route('console.analytics.index', $fixture['workspace']->getKey()))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 1
                && $totals['clicks'] === 1
                && $totals['leads'] === 1
                && $totals['unsubscribes'] === 1
                && $totals['lead_revenue'] === 6.0
            );

        $allStatsRows = $this->getJson(route('console.analytics.all-stats', [
            $fixture['workspace']->getKey(),
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ]))->assertOk()->json();
        $this->assertSame(1, collect($allStatsRows)->sum('opens'));
        $this->assertSame(1, collect($allStatsRows)->sum('clicks'));
        $this->assertSame(1, collect($allStatsRows)->sum('leads'));
        $this->assertSame(1, collect($allStatsRows)->sum('unsubscribes'));

        $this->getJson(route('console.analytics.mailer-revenue', [
            $fixture['workspace']->getKey(),
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ]))
            ->assertOk()
            ->assertJsonFragment([
                'opens' => 1,
                'clicks' => 1,
                'leads' => 1,
                'unsubscribes' => 1,
                'lead_revenue' => 6.0,
            ]);

        $this->getJson(route('console.campaign-statistics.index', [
            $fixture['workspace']->getKey(),
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ]))
            ->assertOk()
            ->assertJsonFragment([
                'opens' => 1,
                'clicks' => 1,
                'leads' => 1,
                'unsubscribes' => 1,
                'lead_revenue' => 6.0,
            ]);

        $this->postJson(route('console.infrastructure.list-history.data', $fixture['workspace']->getKey()), [
            'isp' => $fixture['isp']->getKey(),
            'list' => (string) $fixture['list']->getKey(),
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ])
            ->assertOk()
            ->assertJsonPath('histories.0.lead', 1)
            ->assertJsonPath('histories.0.unsubscribe', 1);

        $this->get(route('console.audiences.index', $fixture['workspace']->getKey()))
            ->assertOk()
            ->assertSee('OPEN')
            ->assertSee('CLICK')
            ->assertSee('LEAD')
            ->assertSee('UNSUB')
            ->assertViewHas('audienceLists', fn ($lists): bool => $lists->first()->history->lead === 1
                && $lists->first()->history->unsubscribe === 1);
    }

    public function test_affiliate_lead_reconciliation_updates_revenue_dashboard_reports_and_list_state(): void
    {
        $fixture = $this->recipientFixture();
        $firstReport = $this->hitPathReport($fixture, '3.5000');
        $secondReport = $this->hitPathReport($fixture, '4.7500');
        $thirdReport = $this->hitPathReport($fixture, '6.0000');
        $requests = 0;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$requests, $firstReport, $secondReport, $thirdReport) {
            $requests++;

            return Http::response(match ($requests) {
                1 => $firstReport,
                2 => $secondReport,
                default => $thirdReport,
            });
        });
        $importer = new DmsSponsorLeadImporter(
            new DmsAffiliateProviderSalesReader(new DmsAffiliateProviderHttp(static fn (string $host): array => ['93.184.216.34'])),
            new DmsCampaignAffiliateAttribution,
            new DmsDropAffiliateAttribution,
            new CampaignIpGeoResolver,
            new TrackedCountryListManager,
        );

        $first = $importer->import($fixture['sponsor'], now()->subDay()->startOfDay(), now()->endOfDay());
        $second = $importer->import($fixture['sponsor'], now()->subDay()->startOfDay(), now()->endOfDay());
        $anotherSponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'name' => 'Synthetic duplicate conversion sponsor',
            'affiliate_id' => '67890',
            'url' => 'https://affiliate.example.test',
            'username' => 'second-fixture-user',
            'password' => 'second-fixture-password',
            'api_key' => 'second-fixture-api-key',
            'type' => 'hitpath',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $third = $importer->import($anotherSponsor, now()->subDay()->startOfDay(), now()->endOfDay());

        $this->assertSame(['read' => 1, 'created' => 1, 'updated' => 0, 'skipped' => 0], $first);
        $this->assertSame(['read' => 1, 'created' => 0, 'updated' => 1, 'skipped' => 0], $second);
        $this->assertSame(['read' => 1, 'created' => 0, 'updated' => 1, 'skipped' => 0], $third);
        $this->assertSame(1, CampaignLeadConversion::query()->count());
        $this->assertSame('6.0000', (string) CampaignLeadConversion::query()->firstOrFail()->revenue);
        $this->assertSame('lead', $fixture['membership']->fresh()->email_type);
        $this->assertSame(1, ContactListMembershipEvent::query()->where('source', 'affiliate_conversion')->count());

        $history = app(AudienceListHistoryCalculator::class)->calculate($fixture['list'], Carbon::today());
        $this->assertSame(1, (int) $history->lead);
        $this->assertSame(1, AudienceListHistory::query()->count());

        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);

        $this->actingAs($fixture['owner'])
            ->get(route('console.analytics.index', $fixture['workspace']->getKey()))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['leads'] === 1 && $totals['lead_revenue'] === 6.0);

        $this->get(route('console.analytics.all-stats', [
            $fixture['workspace']->getKey(), 'from' => today()->toDateString(), 'to' => today()->toDateString(),
        ]))->assertOk()->assertViewHas('rows', function ($rows): bool {
            $lead = collect($rows->items())->firstWhere('type', 'lead');

            return $lead !== null && $lead['event_count'] === 1 && $lead['lead_revenue'] === 6.0;
        });

        $this->get(route('console.analytics.isp-stats', [
            $fixture['workspace']->getKey(), 'from' => today()->toDateString(), 'to' => today()->toDateString(),
        ]))->assertOk()->assertViewHas('rows', fn ($rows): bool => $rows->total() === 1 && $rows->items()[0]['leads'] === 1);

        $this->get(route('console.analytics.mailer-revenue', [
            $fixture['workspace']->getKey(), 'from' => today()->toDateString(), 'to' => today()->toDateString(),
        ]))->assertOk()->assertViewHas('rows', fn ($rows): bool => $rows->total() === 1
            && $rows->items()[0]['leads'] === 1
            && $rows->items()[0]['lead_revenue'] === 6.0);
    }

    public function test_lead_import_skips_invalid_campaign_attribution(): void
    {
        $fixture = $this->recipientFixture();
        $report = "ignored,date,s1,s2,s3,ip,earning\n"
            .'x,2026-10-26 12:34:56,'.$this->s1('C_'.Str::uuid()).',malformed,third,192.0.2.9,3.00'."\n";
        Http::preventStrayRequests();
        Http::fake(['https://reporting.example.test/api.php' => Http::response($report)]);
        $importer = new DmsSponsorLeadImporter(
            new DmsAffiliateProviderSalesReader(new DmsAffiliateProviderHttp(static fn (string $host): array => ['93.184.216.34'])),
            new DmsCampaignAffiliateAttribution,
            new DmsDropAffiliateAttribution,
            new CampaignIpGeoResolver,
            new TrackedCountryListManager,
        );

        $result = $importer->import($fixture['sponsor'], now()->subDay()->startOfDay(), now()->endOfDay());

        $this->assertSame(['read' => 1, 'created' => 0, 'updated' => 0, 'skipped' => 1], $result);
        $this->assertSame(0, CampaignLeadConversion::query()->count());
        $this->assertSame('fresh', $fixture['membership']->fresh()->email_type);
    }

    public function test_campaign_lead_uses_conversion_country_for_tracked_lists(): void
    {
        config(['infrastructure.ip_api_key' => null]);
        $fixture = $this->recipientFixture();
        $fixture['isp']->forceFill(['tracked_countries' => ['US']])->save();
        $fixture['list']->forceFill([
            'is_tracked' => true,
            'allowed_users' => ['lead-operator'],
            'allowed_sponsors' => ['lead-sponsor'],
            'is_unsubscribe_locked' => true,
        ])->save();
        WorkspaceAppSettings::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'settings' => ['ip_api_key' => 'synthetic-lead-country-api-key'],
        ]);
        $report = $this->hitPathReport($fixture, '5.2500');
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($report) {
            if (str_starts_with($request->url(), 'https://pro.ip-api.com/json/')) {
                return Http::response([
                    'status' => 'success', 'countryCode' => 'US', 'country' => 'United States',
                    'regionName' => 'Test Region', 'city' => 'Test City', 'proxy' => false,
                    'hosting' => false, 'org' => 'Synthetic Network',
                ]);
            }

            return Http::response($report);
        });
        $importer = new DmsSponsorLeadImporter(
            new DmsAffiliateProviderSalesReader(new DmsAffiliateProviderHttp(static fn (string $host): array => ['93.184.216.34'])),
            new DmsCampaignAffiliateAttribution,
            new DmsDropAffiliateAttribution,
            new CampaignIpGeoResolver,
            new TrackedCountryListManager,
        );

        $result = $importer->import($fixture['sponsor'], now()->subDay()->startOfDay(), now()->endOfDay());
        $countryList = AudienceList::query()->where('workspace_id', $fixture['workspace']->getKey())
            ->where('isp_id', $fixture['isp']->getKey())->where('name', 'US')->firstOrFail();
        $this->assertSame(['read' => 1, 'created' => 1, 'updated' => 0, 'skipped' => 0], $result);
        $this->assertSame((string) $countryList->getKey(), (string) $fixture['membership']->fresh()->audience_list_id);
        $this->assertSame('lead', $fixture['membership']->fresh()->email_type);
        $this->assertSame('United States', CampaignLeadConversion::query()->firstOrFail()->country);
        $this->assertSame(['lead-operator'], $countryList->allowed_users);
        $this->assertTrue($countryList->is_unsubscribe_locked);
    }

    public function test_drop_lead_uses_conversion_country_for_tracked_lists(): void
    {
        config(['infrastructure.ip_api_key' => null]);
        $fixture = $this->recipientFixture();
        $fixture['isp']->forceFill(['tracked_countries' => ['US']])->save();
        $fixture['list']->forceFill([
            'is_tracked' => true,
            'allowed_users' => ['drop-operator'],
            'allowed_sponsors' => ['drop-sponsor'],
            'is_lead_locked' => true,
        ])->save();
        WorkspaceAppSettings::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'settings' => ['ip_api_key' => 'synthetic-drop-lead-country-api-key'],
        ]);
        $drop = WorkspaceDrop::factory()->forWorkspace($fixture['workspace'], $fixture['owner'])->create([
            'sponsor_id' => $fixture['sponsor']->getKey(),
            'offer_id' => $fixture['offer']->getKey(),
            'isp_id' => $fixture['isp']->getKey(),
            'lists' => [(string) $fixture['list']->getKey()],
        ]);
        $report = $this->dropHitPathReport($fixture, $drop, '6.7500');
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($report) {
            if (str_starts_with($request->url(), 'https://pro.ip-api.com/json/')) {
                return Http::response([
                    'status' => 'success', 'countryCode' => 'US', 'country' => 'United States',
                    'regionName' => 'Test Region', 'city' => 'Test City', 'proxy' => false,
                    'hosting' => false, 'org' => 'Synthetic Network',
                ]);
            }

            return Http::response($report);
        });
        $importer = new DmsSponsorLeadImporter(
            new DmsAffiliateProviderSalesReader(new DmsAffiliateProviderHttp(static fn (string $host): array => ['93.184.216.34'])),
            new DmsCampaignAffiliateAttribution,
            new DmsDropAffiliateAttribution,
            new CampaignIpGeoResolver,
            new TrackedCountryListManager,
        );

        $result = $importer->import($fixture['sponsor'], now()->subDay()->startOfDay(), now()->endOfDay());
        $countryList = AudienceList::query()->where('workspace_id', $fixture['workspace']->getKey())
            ->where('isp_id', $fixture['isp']->getKey())->where('name', 'US')->firstOrFail();
        $visitor = WorkspaceDropVisitor::query()->where('workspace_drop_id', $drop->getKey())->firstOrFail();
        $this->assertSame(['read' => 1, 'created' => 1, 'updated' => 0, 'skipped' => 0], $result);
        $this->assertSame((string) $countryList->getKey(), (string) $fixture['membership']->fresh()->audience_list_id);
        $this->assertSame('lead', $fixture['membership']->fresh()->email_type);
        $this->assertSame('United States', $visitor->country);
        $this->assertSame(['drop-operator'], $countryList->allowed_users);
        $this->assertTrue($countryList->is_lead_locked);
        $this->assertDatabaseHas('contact_list_membership_events', [
            'audience_list_id' => $countryList->getKey(),
            'contact_id' => $fixture['contact']->getKey(),
            'action' => 'lead',
            'source' => 'affiliate_conversion',
        ]);
    }

    public function test_drop_lead_reconciliation_is_workspace_scoped_deduplicated_and_updates_list_state(): void
    {
        $fixture = $this->recipientFixture();
        $drop = WorkspaceDrop::factory()->forWorkspace($fixture['workspace'], $fixture['owner'])->create([
            'sponsor_id' => $fixture['sponsor']->getKey(),
            'offer_id' => $fixture['offer']->getKey(),
            'isp_id' => $fixture['isp']->getKey(),
            'lists' => [(string) $fixture['list']->getKey()],
        ]);
        $firstReport = $this->dropHitPathReport($fixture, $drop, '3.5000');
        $secondReport = $this->dropHitPathReport($fixture, $drop, '4.7500');
        $requests = 0;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$requests, $firstReport, $secondReport) {
            $requests++;

            return Http::response($requests === 1 ? $firstReport : $secondReport);
        });
        $importer = new DmsSponsorLeadImporter(
            new DmsAffiliateProviderSalesReader(new DmsAffiliateProviderHttp(static fn (string $host): array => ['93.184.216.34'])),
            new DmsCampaignAffiliateAttribution,
            new DmsDropAffiliateAttribution,
            new CampaignIpGeoResolver,
            new TrackedCountryListManager,
        );

        $first = $importer->import($fixture['sponsor'], now()->subDay()->startOfDay(), now()->endOfDay());
        $second = $importer->import($fixture['sponsor'], now()->subDay()->startOfDay(), now()->endOfDay());

        $this->assertSame(['read' => 1, 'created' => 1, 'updated' => 0, 'skipped' => 0], $first);
        $this->assertSame(['read' => 1, 'created' => 0, 'updated' => 1, 'skipped' => 0], $second);
        $this->assertSame(1, WorkspaceDropVisitor::query()->where('type', 'lead')->count());
        $visitor = WorkspaceDropVisitor::query()->where('type', 'lead')->firstOrFail();
        $this->assertSame('4.7500', number_format((float) $visitor->revenue, 4, '.', ''));
        $this->assertSame((string) $fixture['contact']->getKey(), $visitor->email_id);
        $this->assertSame((string) $fixture['list']->getKey(), $visitor->list_id);
        $this->assertSame('lead', $fixture['membership']->fresh()->email_type);
        $this->assertSame(1, ContactListMembershipEvent::query()->where('source', 'affiliate_conversion')->count());

        app(AudienceListHistoryCalculator::class)->calculate($fixture['list'], Carbon::today());
        $this->assertSame(1, (int) AudienceListHistory::query()->firstOrFail()->lead);

        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);

        $this->actingAs($fixture['owner'])
            ->get(route('console.analytics.index', $fixture['workspace']->getKey()))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['leads'] === 1
                && $totals['lead_revenue'] === 4.75);
    }

    /** @return array<string, mixed> */
    private function recipientFixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Synthetic lead workspace',
            'slug' => 'synthetic-lead-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);
        $sponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Synthetic lead sponsor',
            'affiliate_id' => '12345',
            'url' => 'https://affiliate.example.test',
            'username' => 'fixture-user',
            'password' => 'fixture-password',
            'api_key' => 'fixture-api-key',
            'type' => 'hitpath',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $offer = WorkspaceOffer::query()->create([
            'workspace_id' => $workspace->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => 'lead-fixture-offer',
            'name' => 'Synthetic lead offer',
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
        $isp = Isp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Synthetic lead ISP',
            'is_active' => true,
            'allowed_users' => [],
            'allowed_sponsors' => [],
            'tracked_countries' => [],
            'quality_emails' => [],
        ]);
        $list = AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Lead fixture audience',
            'slug' => 'lead-fixture-audience',
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'lead-fixture-list'),
            'isp_id' => $isp->getKey(),
        ]);
        $contact = Contact::query()->create([
            'workspace_id' => $workspace->getKey(),
            'email' => 'synthetic-lead@example.test',
            'email_lookup_hash' => hash('sha256', 'synthetic-lead@example.test'),
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
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Synthetic attributed campaign',
            'kind' => 'marketing',
            'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(),
            'revision_number' => 1,
            'payload' => ['send_setup' => ['offer_id' => $offer->getKey(), 'isp_id' => $isp->getKey()]],
            'content_sha256' => hash('sha256', 'lead-fixture-revision'),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'lead-fixture-revision-request'),
            'created_by' => $owner->getKey(),
            'created_at' => now(),
        ]);
        $snapshot = CampaignRecipientSnapshot::query()->create([
            'workspace_id' => $workspace->getKey(),
            'campaign_id' => $campaign->getKey(),
            'revision_id' => $revision->getKey(),
            'offer_id' => $offer->getKey(),
            'suppression_sha256' => hash('sha256', 'synthetic suppression'),
            'selection' => ['audience_segments' => [$list->getKey().'_fresh']],
            'recipient_count' => 1,
            'recipients_sha256' => hash('sha256', (string) $contact->getKey()),
            'created_at' => now(),
        ]);
        $item = CampaignRecipientSnapshotItem::query()->create([
            'workspace_id' => $workspace->getKey(),
            'snapshot_id' => $snapshot->getKey(),
            'contact_id' => $contact->getKey(),
            'position' => 1,
            'email_lookup_hash' => $contact->email_lookup_hash,
            'source_list_id' => $list->getKey(),
            'source_email_type' => 'fresh',
            'created_at' => now(),
        ]);

        return compact('owner', 'workspace', 'sponsor', 'offer', 'isp', 'list', 'contact', 'membership', 'campaign', 'revision', 'snapshot', 'item');
    }

    /** @param array<string, mixed> $fixture */
    private function hitPathReport(array $fixture, string $revenue): string
    {
        $uuidHex = static fn (string $uuid): string => str_replace('-', '', $uuid);
        $appToken = hash('adler32', (string) config('app.name', 'NA'), false);
        $s1 = $this->s1('C_'.$fixture['campaign']->getKey());
        $s2 = implode('-', [
            $uuidHex((string) $fixture['campaign']->getKey()),
            $uuidHex((string) $fixture['snapshot']->getKey()),
            $uuidHex((string) $fixture['contact']->getKey()),
            'f',
            $uuidHex((string) $fixture['list']->getKey()),
            (string) $fixture['isp']->getKey(),
            '0', '0', '0', '0', '0', '0',
            'synthetic_'.$appToken,
            '0',
        ]);

        return "ignored,date,s1,s2,s3,ip,earning\n".implode(',', [
            'x', now()->format('Y-m-d H:i:s'), $s1, $s2, 'third', '192.0.2.15', $revenue,
        ])."\n";
    }

    /** @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private function trackingPayload(
        array $fixture,
        CampaignRecipientSnapshotItem $item,
        Contact $contact,
        string $action,
    ): array {
        return [
            'key' => 'synthetic-tracking-key',
            'action' => $action,
            'ip_address' => '203.0.113.25',
            'user_agent' => 'AlphaLink synthetic tracker',
            'campaign_id' => (string) $fixture['campaign']->getKey(),
            'send_id' => 'synthetic-send-'.$contact->getKey(),
            'email_id' => (string) $contact->getKey(),
            'snapshot_item_id' => (string) $item->getKey(),
            'type' => 'f',
            'list_id' => (string) $item->source_list_id,
            'isp_id' => $fixture['isp']->getKey(),
            'server_id' => '0',
            'ip_id' => '0',
            'domain_id' => '0',
            'from_id' => '0',
            'subject_id' => '0',
            'creative_id' => '0',
            'token' => 'synthetic-tracking-token-'.$contact->getKey(),
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function dropHitPathReport(array $fixture, WorkspaceDrop $drop, string $revenue): string
    {
        $uuidHex = static fn (string $uuid): string => str_replace('-', '', $uuid);
        $appToken = hash('adler32', (string) config('app.name', 'NA'), false);
        $s2 = implode('-', [
            (string) $drop->getKey(),
            (string) $drop->getKey(),
            $uuidHex((string) $fixture['contact']->getKey()),
            'f',
            $uuidHex((string) $fixture['list']->getKey()),
            (string) $fixture['isp']->getKey(),
            '0', '0', '0', '0', '0', '0',
            'synthetic_'.$appToken,
            '0',
        ]);

        return "ignored,date,s1,s2,s3,ip,earning\n".implode(',', [
            'x', now()->format('Y-m-d H:i:s'), $this->s1('D_'.$drop->getKey()), $s2, 'third', '192.0.2.16', $revenue,
        ])."\n";
    }

    private function s1(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
