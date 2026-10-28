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
use App\Models\Isp;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceOfferAsset;
use App\Models\WorkspaceSponsor;
use App\Services\Analytics\ImportDmsPmtaFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceCampaignStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_statistics_combine_engagement_and_attributed_leads_without_contact_data(): void
    {
        $fixture = $this->campaignFixture();
        foreach (['open', 'click', 'unsubscribe', 'bot'] as $action) {
            $this->event($fixture, $action);
        }
        $this->leadConversion($fixture, 'synthetic-conversion-1', 8.75);
        $this->leadConversion($fixture, 'synthetic-conversion-2', 1.25);
        $zeroRevenueIsp = Isp::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(), 'name' => 'Synthetic zero-revenue ISP', 'is_active' => true,
            'allowed_users' => [], 'allowed_sponsors' => [], 'tracked_countries' => [], 'quality_emails' => [],
        ]);
        $this->leadConversion($fixture, 'synthetic-conversion-zero', 0, (int) $zeroRevenueIsp->getKey());
        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);
        DB::table('workspace_daily_analytics')
            ->where('workspace_id', $fixture['workspace']->getKey())
            ->where('campaign_id', $fixture['campaign']->getKey())
            ->where('isp_id', (string) $fixture['isp']->getKey())
            ->update(['deliveries' => 37, 'soft_bounces' => 4, 'hard_bounces' => 2]);

        $this->actingAs($fixture['owner'])
            ->get(route('clean.console.campaign-statistics.index', [
                'from' => today()->toDateString(),
                'to' => today()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' https://cdn3.devexpress.com; style-src \'self\' \'unsafe-inline\' https://cdn3.devexpress.com;', false)
            ->assertSee('CAMPAIGN STATISTICS')
            ->assertSee('CONVERSIONS')
            ->assertSee('Open, click and unsubscribe events are attributed to each selected eligible source List')
            ->assertSee('totals across Lists can exceed unique events')
            ->assertSee('Imported PMTA delivery and soft/hard bounce totals')
            ->assertSee('one-per-daily-stat-row positive-earnings rule')
            ->assertSee('no value is inferred')
            ->assertSee('analytics-dates')
            ->assertSee('/vendor/daterangepicker/daterangepicker.js')
            ->assertSee('data-min-date="none"', false)
            ->assertSee('data-storage-key="c_stats"', false)
            ->assertSee('data-analytics-report-refresh', false)
            ->assertSee('analytics-report-refresh.js')
            ->assertSee('id="campaign-statistics-pivot-shell"', false)
            ->assertSee('id="campaign-statistics-pivot-reload"', false)
            ->assertSee('analytics-pivot.js')
            ->assertSee('data-clear-analytics-date-range="c_stats"', false)
            ->assertDontSee('synthetic-statistics@example.test');

        $json = $this->getJson(route('clean.console.campaign-statistics.index', [
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ]));
        $json->assertOk()
            ->assertJsonCount(2)
            ->assertJsonStructure([['at', 'user', 'campaign_id', 'isp', 'list', 'list_type', 'provider', 'server', 'ip', 'domain', 'sponsor', 'offer', 'vertical', 'from', 'subject', 'creative', 'deliveries', 'soft_bounces', 'hard_bounces', 'opens', 'clicks', 'leads', 'conversions', 'unsubscribes', 'bots', 'lead_revenue']])
            ->assertJsonFragment(['isp' => 'Synthetic statistics ISP', 'deliveries' => 37, 'soft_bounces' => 4, 'hard_bounces' => 2, 'leads' => 2, 'conversions' => 1, 'lead_revenue' => 10])
            ->assertJsonFragment(['isp' => 'Synthetic zero-revenue ISP', 'leads' => 1, 'conversions' => 0, 'lead_revenue' => 0]);
        $this->assertStringNotContainsString('synthetic-statistics@example.test', $json->getContent());

        $this->get(route('clean.console.campaign-statistics.index', [
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
            'campaign_id' => $fixture['campaign']->getKey(),
            'fragment' => 1,
        ]))
            ->assertOk()
            ->assertViewIs('console.campaigns.partials.statistics-results')
            ->assertSee('id="analytics-report-results"', false)
            ->assertSee('Filtered campaign totals')
            ->assertSee('CONVERSIONS')
            ->assertSee('DELIVERIES')
            ->assertSee('SOFT BOUNCES')
            ->assertSee('HARD BOUNCES')
            ->assertSee('Synthetic statistics list')
            ->assertDontSee('<!DOCTYPE html>');
    }

    public function test_campaign_engagement_rollups_attribute_each_source_list_without_inflating_workspace_totals(): void
    {
        $fixture = $this->campaignFixture();
        $secondList = AudienceList::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'created_by' => $fixture['owner']->getKey(),
            'name' => 'Second synthetic statistics list',
            'slug' => 'statistics-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'second statistics list '.Str::random(8)),
            'isp_id' => $fixture['isp']->getKey(),
        ]);

        foreach (['open', 'click', 'unsubscribe'] as $action) {
            $event = $this->event($fixture, $action);
            foreach ([$fixture['list'], $secondList] as $sourceList) {
                $sourceKey = hash('sha256', implode(':', [$event->getKey(), $sourceList->getKey(), 'fresh']));
                DB::table('campaign_recipient_snapshot_item_sources')->insert([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $fixture['workspace']->getKey(),
                    'snapshot_item_id' => $fixture['item']->getKey(),
                    'source_membership_id' => null,
                    'source_list_id' => $sourceList->getKey(),
                    'source_email_type' => 'fresh',
                    'source_key' => $sourceKey,
                    'created_at' => now(),
                ]);
                DB::table('campaign_engagement_event_sources')->insert([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $fixture['workspace']->getKey(),
                    'engagement_event_id' => $event->getKey(),
                    'source_membership_id' => null,
                    'source_list_id' => $sourceList->getKey(),
                    'source_email_type' => 'fresh',
                    'source_key' => $sourceKey,
                    'created_at' => now(),
                ]);
            }
        }

        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);

        $workspaceTotals = DB::table('workspace_daily_analytics')
            ->where('workspace_id', $fixture['workspace']->getKey())
            ->where('source_type', 'campaign')
            ->where('campaign_id', $fixture['campaign']->getKey())
            ->where('attribution_scope', 'workspace')
            ->get();
        $this->assertSame(1, (int) $workspaceTotals->sum('opens'));
        $this->assertSame(1, (int) $workspaceTotals->sum('clicks'));
        $this->assertSame(1, (int) $workspaceTotals->sum('unsubscribes'));

        $listTotals = DB::table('workspace_daily_analytics')
            ->where('workspace_id', $fixture['workspace']->getKey())
            ->where('source_type', 'campaign')
            ->where('campaign_id', $fixture['campaign']->getKey())
            ->where('attribution_scope', 'list')
            ->orderBy('list_id')
            ->get();
        $this->assertCount(2, $listTotals);
        foreach ($listTotals as $listTotal) {
            $this->assertSame(1, (int) $listTotal->opens);
            $this->assertSame(1, (int) $listTotal->clicks);
            $this->assertSame(1, (int) $listTotal->unsubscribes);
        }

        $this->actingAs($fixture['owner'])
            ->getJson(route('clean.console.campaign-statistics.index', [
                'from' => today()->toDateString(),
                'to' => today()->toDateString(),
                'list_id' => $secondList->getKey(),
            ]))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'list' => 'Second synthetic statistics list',
                'opens' => 1,
                'clicks' => 1,
                'unsubscribes' => 1,
            ]);

    }

    public function test_all_stats_lists_campaign_engagement_and_leads_with_resolved_dms_dimensions(): void
    {
        $fixture = $this->campaignFixture();
        $server = ManagedServer::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'name' => 'Synthetic pivot server',
            'provider' => 'Synthetic provider',
            'ip_address' => '203.0.113.19',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'pivot server'),
            'created_by' => $fixture['owner']->getKey(),
        ]);
        $domain = SendingDomain::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'name' => 'pivot.example.test',
            'state' => 'verified',
            'verification_token' => 'synthetic-pivot-token',
            'created_by' => $fixture['owner']->getKey(),
        ]);
        $ip = ManagedServerIp::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => '203.0.113.19',
            'type' => 'ipv4',
            'dns' => 'pivot.example.test',
        ]);
        $from = $this->asset($fixture, 'from', 'Operations <ops@example.test>');
        $subject = $this->asset($fixture, 'subject', 'A useful update');
        $creative = $this->asset($fixture, 'creative', '<p>Welcome back</p>');
        $this->event($fixture, 'open', [
            'server_id' => (string) $server->getKey(),
            'ip_id' => (string) $ip->getKey(),
            'domain_id' => (string) $domain->getKey(),
            'from_id' => (string) $from->getKey(),
            'subject_id' => (string) $subject->getKey(),
            'creative_id' => (string) $creative->getKey(),
        ]);
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
            'provider_conversion_id' => 'synthetic-pivot-conversion',
            'revenue' => 6.25,
            'isp_id' => $fixture['isp']->getKey(),
            'converted_at' => now(),
        ]);
        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);

        $this->actingAs($fixture['owner'])
            ->get(route('clean.console.analytics.all-stats', [
                'from' => today()->toDateString(),
                'to' => today()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Synthetic statistics campaign')
            ->assertSee('Synthetic statistics ISP')
            ->assertSee('Synthetic statistics list')
            ->assertSee('Synthetic statistics sponsor')
            ->assertSee('Synthetic statistics offer')
            ->assertSee('Synthetic provider')
            ->assertSee('Synthetic pivot server')
            ->assertSee('203.0.113.19')
            ->assertSee('pivot.example.test')
            ->assertSee('Operations <ops@example.test>')
            ->assertSee('A useful update')
            ->assertSee('Welcome back')
            ->assertSee('Campaign opens, clicks, and unsubscribes are attributed to every selected eligible source List')
            ->assertSee('totals across Lists can exceed unique events')
            ->assertDontSee('synthetic-statistics@example.test');

        $this->actingAs($fixture['owner'])
            ->getJson(route('console.campaign-statistics.index', [
                $fixture['workspace']->getKey(),
                'from' => today()->toDateString(),
                'to' => today()->toDateString(),
            ]))
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment([
                'user' => $fixture['owner']->name,
                'campaign_id' => $fixture['campaign']->getKey(),
                'isp' => 'Synthetic statistics ISP',
                'list' => 'Synthetic statistics list',
                'list_type' => 'fresh',
                'provider' => 'Synthetic provider',
                'server' => 'Synthetic pivot server',
                'ip' => '203.0.113.19',
                'domain' => 'pivot.example.test',
                'sponsor' => 'Synthetic statistics sponsor',
                'offer' => 'Synthetic statistics offer',
                'vertical' => 'testing',
                'from' => 'Operations <ops@example.test>',
                'subject' => 'A useful update',
                'creative' => '<p>Welcome back</p>',
                'opens' => 1,
                'clicks' => 0,
                'leads' => 0,
            ])
            ->assertJsonMissing(['email' => 'synthetic-statistics@example.test']);
    }

    public function test_campaign_statistics_filters_are_workspace_scoped_and_include_only_permitted_options(): void
    {
        $fixture = $this->campaignFixture();
        $this->event($fixture, 'click');
        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->workspace($otherOwner, 'owner');
        $otherFixture = $this->campaignFixture($otherOwner, $otherWorkspace, 'Other workspace campaign');
        $this->event($otherFixture, 'click');
        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);

        $this->actingAs($fixture['owner'])
            ->getJson(route('clean.console.campaign-statistics.index', [
                'from' => today()->toDateString(),
                'to' => today()->toDateString(),
                'campaign_id' => $fixture['campaign']->getKey(),
                'isp_id' => $fixture['isp']->getKey(),
                'list_id' => $fixture['list']->getKey(),
            ]))
            ->assertOk()
            ->assertJsonFragment(['campaign_id' => $fixture['campaign']->getKey(), 'clicks' => 1])
            ->assertJsonMissing(['campaign_id' => $otherFixture['campaign']->getKey()]);

        $this->getJson(route('clean.console.campaign-statistics.index', [
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
            'campaign_id' => $otherFixture['campaign']->getKey(),
        ]))->assertNotFound();
    }

    public function test_campaign_statistics_show_idempotently_imported_pmta_delivery_and_bounce_counts(): void
    {
        $fixture = $this->campaignFixture();
        $server = ManagedServer::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'name' => 'Synthetic feedback server',
            'provider' => 'Synthetic provider',
            'ip_address' => '198.51.100.80',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'campaign statistics PMTA server'),
            'created_by' => $fixture['owner']->getKey(),
        ]);
        $domain = SendingDomain::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'name' => 'feedback.example.test',
            'state' => 'verified',
            'verification_token' => 'synthetic-feedback-domain-token',
            'created_by' => $fixture['owner']->getKey(),
        ]);
        $ip = ManagedServerIp::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => '198.51.100.80',
            'type' => 'ipv4',
            'dns' => 'feedback.example.test',
        ]);
        $date = today()->toDateString();
        $token = implode('-', [
            str_replace('-', '', (string) $fixture['campaign']->getKey()),
            str_replace('-', '', (string) $fixture['snapshot']->getKey()),
            str_replace('-', '', (string) $fixture['contact']->getKey()),
            'fresh',
            str_replace('-', '', (string) $fixture['list']->getKey()),
            (string) $fixture['isp']->getKey(),
            str_replace('-', '', (string) $server->getKey()),
            (string) $ip->getKey(),
            str_replace('-', '', (string) $domain->getKey()),
            '503', '504', '505', 'app', 'c',
        ]);
        $delivery = $this->csv([[$date, 'ok', 'synthetic-recipient@example.test', $token]]);
        $bounces = $this->csv([
            [$date, 'mailbox-full', 'ignored', 'ignored', $token],
            [$date, 'bad-mailbox', 'ignored', 'ignored', $token],
        ]);
        $importer = app(ImportDmsPmtaFeedback::class);

        $this->assertSame(
            ['imported' => 1, 'duplicate' => 0, 'unmatched' => 0],
            $importer->import($server, $delivery, 'delivery', 'campaign-stats-delivery.csv', $date),
        );
        $this->assertSame(
            ['imported' => 2, 'duplicate' => 0, 'unmatched' => 0],
            $importer->import($server, $bounces, 'bounce', 'campaign-stats-bounce.csv', $date),
        );
        rewind($delivery);
        $this->assertSame(
            ['imported' => 0, 'duplicate' => 1, 'unmatched' => 0],
            $importer->import($server, $delivery, 'delivery', 'campaign-stats-delivery.csv', $date),
        );

        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);
        $response = $this->actingAs($fixture['owner'])
            ->getJson(route('clean.console.campaign-statistics.index', [
                'from' => $date,
                'to' => $date,
            ]))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'campaign_id' => $fixture['campaign']->getKey(),
                'ip' => '198.51.100.80',
                'domain' => 'feedback.example.test',
                'deliveries' => 1,
                'soft_bounces' => 1,
                'hard_bounces' => 1,
            ]);
        $this->assertStringNotContainsString('synthetic-recipient@example.test', $response->getContent());

        $daily = DB::table('workspace_daily_analytics')
            ->where('workspace_id', $fixture['workspace']->getKey())
            ->where('campaign_id', $fixture['campaign']->getKey())
            ->where('at', $date)
            ->first();
        $this->assertNotNull($daily);
        $this->assertSame(1, (int) $daily->deliveries);
        $this->assertSame(1, (int) $daily->soft_bounces);
        $this->assertSame(1, (int) $daily->hard_bounces);
        $this->assertDatabaseCount('workspace_pmta_feedback_events', 3);
        $this->assertStringNotContainsString(
            'synthetic-recipient@example.test',
            json_encode(DB::table('workspace_pmta_feedback_events')->get(), JSON_THROW_ON_ERROR),
        );

        fclose($delivery);
        fclose($bounces);
    }

    public function test_all_stats_json_returns_unpaginated_pivot_rows_without_contact_addresses(): void
    {
        $fixture = $this->campaignFixture();
        $this->event($fixture, 'open');
        $this->event($fixture, 'click');
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
            'provider_conversion_id' => 'synthetic-pivot-json-conversion',
            'revenue' => 6.25,
            'isp_id' => $fixture['isp']->getKey(),
            'converted_at' => now(),
        ]);
        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);

        $response = $this->actingAs($fixture['owner'])->getJson(route('console.analytics.all-stats', [
            $fixture['workspace']->getKey(),
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ]));

        $response->assertOk()
            ->assertJsonCount(3)
            ->assertJsonStructure([['at', 'user', 'isp', 'list', 'list_type', 'provider', 'server', 'ip', 'domain', 'sponsor', 'offer', 'vertical', 'from', 'subject', 'creative', 'opens', 'clicks', 'leads', 'unsubscribes', 'bots', 'lead_revenue']])
            ->assertJsonFragment(['opens' => 1, 'clicks' => 0, 'leads' => 0])
            ->assertJsonFragment(['opens' => 0, 'clicks' => 1, 'leads' => 0])
            ->assertJsonFragment(['opens' => 0, 'clicks' => 0, 'leads' => 1, 'lead_revenue' => 6.25]);
        $this->assertStringNotContainsString('synthetic-statistics@example.test', $response->getContent());
    }

    public function test_campaign_statistics_rejects_reversed_and_future_date_ranges(): void
    {
        $fixture = $this->campaignFixture();
        $this->actingAs($fixture['owner'])
            ->get(route('clean.console.campaign-statistics.index', [
                'from' => today()->toDateString(),
                'to' => today()->addDay()->toDateString(),
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['to']);
    }

    /** @return array<string, mixed> */
    private function campaignFixture(?User $owner = null, ?Workspace $workspace = null, string $campaignName = 'Synthetic statistics campaign'): array
    {
        $owner ??= User::factory()->create();
        $workspace ??= $this->workspace($owner, 'owner');
        $sponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'Synthetic statistics sponsor',
            'affiliate_id' => 'statistics-'.Str::random(8), 'url' => 'https://affiliate.example.test',
            'username' => 'fixture-user', 'password' => 'fixture-password', 'api_key' => 'fixture-api-key',
            'type' => 'hitpath', 'allowed_users' => [], 'is_active' => true,
        ]);
        $offer = WorkspaceOffer::query()->create([
            'workspace_id' => $workspace->getKey(), 'sponsor_id' => $sponsor->getKey(),
            'offer_id' => 'statistics-'.Str::random(8), 'name' => 'Synthetic statistics offer',
            'type' => 'cpa', 'vertical' => 'testing', 'geo_target' => ['US'], 'payout' => 1,
            'redirect' => 'https://offer.example.test', 'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live', 'allowed_users' => [], 'is_active' => true,
        ]);
        $isp = Isp::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'Synthetic statistics ISP', 'is_active' => true,
            'allowed_users' => [], 'allowed_sponsors' => [], 'tracked_countries' => [], 'quality_emails' => [],
        ]);
        $list = AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
            'name' => 'Synthetic statistics list', 'slug' => 'statistics-'.Str::lower(Str::random(8)),
            'status' => 'active', 'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'statistics list '.Str::random(8)), 'isp_id' => $isp->getKey(),
        ]);
        $contact = Contact::query()->create([
            'workspace_id' => $workspace->getKey(), 'email' => 'synthetic-statistics@example.test',
            'email_lookup_hash' => hash('sha256', 'synthetic-statistics@example.test'),
            'lookup_key_version' => 1, 'status' => 'active', 'idempotency_key' => (string) Str::uuid(),
        ]);
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
            'name' => $campaignName, 'kind' => 'marketing', 'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(), 'revision_number' => 1,
            'payload' => ['send_setup' => ['offer_id' => $offer->getKey(), 'isp_id' => $isp->getKey()]],
            'content_sha256' => hash('sha256', 'statistics revision '.Str::random(8)),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'statistics revision request '.Str::random(8)),
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

        return compact('owner', 'workspace', 'sponsor', 'offer', 'isp', 'list', 'contact', 'campaign', 'revision', 'snapshot', 'item');
    }

    private function workspace(User $owner, string $role): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Synthetic statistics workspace '.Str::random(5),
            'slug' => 'statistics-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => $role]);

        return $workspace;
    }

    /** @param array<string, mixed> $fixture */
    private function event(array $fixture, string $action, array $dimensionIds = []): CampaignEngagementEvent
    {
        return CampaignEngagementEvent::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'campaign_id' => $fixture['campaign']->getKey(),
            'revision_id' => $fixture['revision']->getKey(),
            'recipient_snapshot_id' => $fixture['snapshot']->getKey(),
            'snapshot_item_id' => $fixture['item']->getKey(),
            'contact_id' => $fixture['contact']->getKey(),
            'audience_list_id' => $fixture['list']->getKey(),
            'source_email_type' => 'fresh', 'action' => $action,
            'isp_id' => $fixture['isp']->getKey(), 'client_ip' => '192.0.2.55',
            'dimension_ids' => $dimensionIds,
            'user_agent' => 'Synthetic analytics test', 'token_sha256' => hash('sha256', Str::random(20)),
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $fixture */
    private function leadConversion(array $fixture, string $conversionId, float $revenue, ?int $ispId = null): void
    {
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
            'provider_conversion_id' => $conversionId,
            'revenue' => $revenue,
            'isp_id' => $ispId ?? $fixture['isp']->getKey(),
            'converted_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $fixture */
    private function asset(array $fixture, string $type, string $value): WorkspaceOfferAsset
    {
        return WorkspaceOfferAsset::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'offer_id' => $fixture['offer']->getKey(),
            'type' => $type,
            'value' => $value,
            'is_active' => true,
            'created_by' => $fixture['owner']->getKey(),
        ]);
    }

    /** @param list<list<string>> $rows
     * @return resource
     */
    private function csv(array $rows)
    {
        $stream = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            fputcsv($stream, $row, escape: '');
        }
        rewind($stream);

        return $stream;
    }
}
