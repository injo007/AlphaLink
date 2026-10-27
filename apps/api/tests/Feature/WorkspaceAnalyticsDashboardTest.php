<?php

namespace Tests\Feature;

use App\Models\AudienceListHistory;
use App\Models\Isp;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDrop;
use App\Models\WorkspaceDropVisitor;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceAnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_reports_recorded_drop_events_and_latest_list_states(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $sponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Analytics sponsor',
            'url' => 'https://sponsor.example.test',
            'username' => 'fixture-user',
            'password' => 'fixture-password',
            'api_key' => 'fixture-key',
            'type' => 'hitpath',
            'allowed_users' => [],
        ]);
        $offer = WorkspaceOffer::query()->create([
            'workspace_id' => $workspace->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'name' => 'Analytics offer',
            'offer_id' => 'analytics-offer',
            'type' => 'CPA',
            'vertical' => 'test',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://offer.example.test',
            'allowed_days' => ['Mon'],
            'status' => 'live',
            'allowed_users' => [],
        ]);
        $isp = Isp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Analytics ISP',
            'is_active' => true,
            'allowed_users' => [],
            'allowed_sponsors' => [],
            'tracked_countries' => [],
            'quality_emails' => [],
        ]);
        $drop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create([
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'isp_id' => $isp->getKey(),
        ]);

        $this->addVisitor($workspace, $drop, 'open', now()->subDay());
        $this->addVisitor($workspace, $drop, 'open', now());
        $this->addVisitor($workspace, $drop, 'click', now()->subDay());
        $this->addVisitor($workspace, $drop, 'click', now());
        $this->addVisitor($workspace, $drop, 'lead', now()->subDays(2), 7.5, (int) $isp->getKey());
        $this->addVisitor($workspace, $drop, 'lead', now(), 2.5, (int) $isp->getKey());
        $this->addVisitor($workspace, $drop, 'unsubscribe', now());
        $this->addVisitor($workspace, $drop, 'bot', now());

        $list = $workspace->audienceLists()->create([
            'created_by' => $owner->getKey(),
            'name' => 'Engagement sample',
            'slug' => 'engagement-sample',
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'analytics-list'),
        ]);
        AudienceListHistory::query()->create([
            'workspace_id' => $workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'at' => now()->toDateString(),
            'fresh' => 11,
            'delivery' => 8,
            'open' => 5,
            'click' => 3,
            'lead' => 2,
            'unsubscribe' => 1,
        ]);

        $this->artisan('workspace:analytics-rebuild-daily', ['days' => 3])->assertExitCode(0);

        $this->actingAs($owner);
        $this->get(route('console.analytics.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('CAMPAIGN & DROP ENGAGEMENT')
            ->assertSee('LATEST AUDIENCE-LIST STATES')
            ->assertSee(route('console.analytics.all-stats', $workspace->getKey()), false)
            ->assertSee(route('console.analytics.isp-stats', $workspace->getKey()), false)
            ->assertSee(route('console.analytics.mailer-revenue', $workspace->getKey()), false)
            ->assertSee('attributed sponsor-report campaign leads')
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 2
                && $totals['clicks'] === 2
                && $totals['leads'] === 2
                && $totals['unsubscribes'] === 1
                && $totals['lead_revenue'] === 10.0
            )
            ->assertViewHas('dashboardHighlights', fn (array $highlights): bool => $highlights['opens'] === [
                'today' => 1,
                'ytd' => 1,
                'mtd' => 2,
            ]
                && $highlights['clicks'] === [
                    'today' => 1,
                    'ytd' => 1,
                    'mtd' => 2,
                ]
                && $highlights['revenue'] === [
                    'today' => 2.5,
                    'ytd' => 0.0,
                    'mtd' => 10.0,
                ]
            )
            ->assertViewHas('dashboardCharts', function (array $charts): bool {
                $todayIndex = now()->day - 1;
                $currentMonthIndex = now()->month - 1;

                return count($charts['mtd_actions']['labels']) === now()->daysInMonth
                    && $charts['mtd_actions']['series'][0]['values'][$todayIndex] === 1
                    && $charts['mtd_actions']['series'][1]['values'][$todayIndex] === 1
                    && $charts['mtd_actions']['series'][2]['values'][$todayIndex] === 1
                    && $charts['mtd_actions']['series'][3]['values'][$todayIndex] === 1
                    && array_sum($charts['mtd_revenue']['series'][0]['values']) === 10.0
                    && count($charts['ytd_revenue']['labels']) === 12
                    && $charts['ytd_revenue']['series'][0]['values'][$currentMonthIndex] === 10.0;
            })
            ->assertViewHas('mtdLeadRevenue', function (array $revenue) use ($owner): bool {
                $this->assertSame([
                    'label' => 'Analytics sponsor',
                    'today' => 2.5,
                    'mtd' => 10.0,
                ], $revenue['sponsors'][0]);
                $this->assertSame([
                    'label' => 'Analytics ISP',
                    'today' => 2.5,
                    'mtd' => 10.0,
                ], $revenue['isps'][0]);
                $this->assertSame($owner->name, $revenue['mailers'][0]['label']);
                $this->assertSame(10.0, $revenue['mailers'][0]['mtd']);
                $this->assertSame([
                    'label' => 'Analytics offer',
                    'today' => 2.5,
                    'mtd' => 10.0,
                ], $revenue['offers'][0]);

                return true;
            })
            ->assertViewHas('snapshotCounts', fn (array $counts): bool => $counts['open'] === 5
                && $counts['click'] === 3
                && $counts['lead'] === 2
                && $counts['unsubscribe'] === 1
            );

        $this->get(route('console.audiences.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('OPEN')
            ->assertSee('CLICK')
            ->assertSee('LEAD')
            ->assertSee('UNSUB')
            ->assertViewHas('audienceLists', fn ($lists): bool => $lists->first()->history->open === 5
                && $lists->first()->history->click === 3
                && $lists->first()->history->lead === 2
                && $lists->first()->history->unsubscribe === 1
            );
    }

    public function test_dashboard_keeps_event_totals_inside_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $drop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create();
        $this->addVisitor($workspace, $drop, 'open', now());

        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->makeWorkspace($otherOwner, 'owner');
        $otherDrop = WorkspaceDrop::factory()->forWorkspace($otherWorkspace, $otherOwner)->create();
        $this->addVisitor($otherWorkspace, $otherDrop, 'click', now());

        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);

        $this->actingAs($owner)
            ->get(route('console.analytics.index', $workspace->getKey()))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 1 && $totals['clicks'] === 0
            );
    }

    public function test_main_dashboard_refresh_fragment_uses_the_same_workspace_scope(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $drop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create();
        $this->addVisitor($workspace, $drop, 'open', now());

        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);

        $this->actingAs($owner)
            ->get(route('console.analytics.index', $workspace->getKey()).'?fragment=1')
            ->assertOk()
            ->assertViewIs('console.analytics.partials.main-dashboard-content')
            ->assertSee('data-main-dashboard-refresh', false)
            ->assertSee('data-refresh-interval="300000"', false)
            ->assertDontSee('<!doctype html', false)
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 1);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)
            ->get(route('console.analytics.index', $workspace->getKey()).'?fragment=1')
            ->assertNotFound();
    }

    public function test_operator_sees_only_their_drop_events(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $operator = User::factory()->create();
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $isp = Isp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Operator ISP',
            'is_active' => true,
            'allowed_users' => [(int) $operator->getKey()],
            'allowed_sponsors' => [],
            'tracked_countries' => [],
            'quality_emails' => [],
        ]);

        $operatorDrop = WorkspaceDrop::factory()->forWorkspace($workspace, $operator)->create(['isp_id' => $isp->getKey()]);
        $ownerDrop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create(['isp_id' => $isp->getKey()]);
        $this->addVisitor($workspace, $operatorDrop, 'click', now(), ispId: (int) $isp->getKey());
        $this->addVisitor($workspace, $ownerDrop, 'click', now(), ispId: (int) $isp->getKey());
        $ownerOnlyList = $workspace->audienceLists()->create([
            'created_by' => $owner->getKey(),
            'name' => 'Owner only list',
            'slug' => 'owner-only-list',
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'owner-only-list'),
            'allowed_users' => [(int) $owner->getKey()],
        ]);
        AudienceListHistory::query()->create([
            'workspace_id' => $workspace->getKey(),
            'audience_list_id' => $ownerOnlyList->getKey(),
            'at' => now()->toDateString(),
            'fresh' => 0,
            'delivery' => 0,
            'open' => 90,
            'click' => 0,
            'lead' => 0,
            'unsubscribe' => 0,
        ]);

        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);

        $this->actingAs($operator)
            ->get(route('console.analytics.index', $workspace->getKey()))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['clicks'] === 1)
            ->assertViewHas('snapshotCounts', fn (array $counts): bool => $counts['open'] === 0);

        $this->get(route('console.analytics.isp-stats', [
            $workspace->getKey(),
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
            'isp_id' => $isp->getKey(),
        ]))
            ->assertOk()
            ->assertViewHas('chartData', fn (array $data): bool => count($data['mailers']) === 1
                && $data['mailers'][0]['label'] === $operator->name
                && $data['mailers'][0]['values']['clicks'] === [1]
                && $data['totals'][0]['clicks'] === 1
            );

        $this->get(route('console.audiences.index', $workspace->getKey()))
            ->assertOk()
            ->assertDontSee('Owner only list');
    }

    public function test_main_dashboard_cache_refreshes_each_minute(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $drop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create();
        $this->addVisitor($workspace, $drop, 'open', now());

        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);

        $this->actingAs($owner)
            ->get(route('console.analytics.index', $workspace->getKey()))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 1 && $totals['clicks'] === 0);

        $this->addVisitor($workspace, $drop, 'click', now());
        $this->get(route('console.analytics.index', $workspace->getKey()))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 1 && $totals['clicks'] === 0);

        $this->travel(61)->seconds();
        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);
        $this->get(route('console.analytics.index', $workspace->getKey()))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 1 && $totals['clicks'] === 1);
    }

    public function test_scheduled_dashboard_warmer_primes_and_refreshes_each_member_scope(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $operator = User::factory()->create();
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);

        $ownerDrop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create();
        $operatorDrop = WorkspaceDrop::factory()->forWorkspace($workspace, $operator)->create();
        $this->addVisitor($workspace, $ownerDrop, 'open', now());
        $this->addVisitor($workspace, $operatorDrop, 'click', now());
        $this->addVisitor($workspace, $ownerDrop, 'lead', now(), 1.0);

        $this->artisan('workspace:analytics-warm-dashboard')
            ->expectsOutput('Warmed dashboard metrics for 2 active workspace members.')
            ->assertExitCode(0);

        $this->addVisitor($workspace, $ownerDrop, 'open', now());
        $this->addVisitor($workspace, $operatorDrop, 'click', now());
        $this->addVisitor($workspace, $ownerDrop, 'lead', now(), 2.0);

        $this->actingAs($owner)
            ->get(route('console.analytics.index', $workspace->getKey()))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 1 && $totals['clicks'] === 1 && $totals['leads'] === 1)
            ->assertViewHas('mtdLeadRevenue', fn (array $revenue): bool => $revenue['mailers'][0]['mtd'] === 1.0);

        $this->actingAs($operator)
            ->get(route('console.analytics.index', $workspace->getKey()))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 0 && $totals['clicks'] === 1);

        $this->travel(61)->seconds();
        $this->artisan('workspace:analytics-warm-dashboard')
            ->expectsOutput('Warmed dashboard metrics for 2 active workspace members.')
            ->assertExitCode(0);

        $this->actingAs($owner)
            ->get(route('console.analytics.index', $workspace->getKey()))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 2 && $totals['clicks'] === 2 && $totals['leads'] === 2)
            ->assertViewHas('mtdLeadRevenue', fn (array $revenue): bool => $revenue['mailers'][0]['mtd'] === 3.0);

        $this->actingAs($operator)
            ->get(route('console.analytics.index', $workspace->getKey()))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 0 && $totals['clicks'] === 2);
    }

    public function test_main_dashboard_cache_is_scoped_to_the_authenticated_workspace_role(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $operator = User::factory()->create();
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $ownerDrop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create();
        $operatorDrop = WorkspaceDrop::factory()->forWorkspace($workspace, $operator)->create();
        $this->addVisitor($workspace, $ownerDrop, 'open', now());
        $this->addVisitor($workspace, $operatorDrop, 'click', now());

        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);

        $this->actingAs($owner)
            ->get(route('console.analytics.index', $workspace->getKey()))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 1 && $totals['clicks'] === 1);

        $this->actingAs($operator)
            ->get(route('console.analytics.index', $workspace->getKey()))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 0 && $totals['clicks'] === 1);
    }

    public function test_daily_analytics_command_rebuilds_workspace_event_totals_idempotently(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $drop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create();
        $this->addVisitor($workspace, $drop, 'open', now());
        $this->addVisitor($workspace, $drop, 'click', now());
        $this->addVisitor($workspace, $drop, 'lead', now(), 3.5);
        $this->addVisitor($workspace, $drop, 'unsubscribe', now());

        $this->artisan('workspace:analytics-rebuild-daily')
            ->expectsOutput('Rebuilt 1 daily campaign and Drop analytics rows.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('workspace_daily_analytics', [
            'workspace_id' => $workspace->getKey(),
            'source_type' => 'drop',
            'source_id' => (string) $drop->getKey(),
            'at' => today()->toDateString(),
            'opens' => 1,
            'clicks' => 1,
            'leads' => 1,
            'unsubscribes' => 1,
            'lead_revenue' => 3.5,
        ]);

        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);

        $this->assertDatabaseCount('workspace_daily_analytics', 1);
    }

    public function test_daily_analytics_command_rejects_a_negative_lookback_without_writing_rows(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $drop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create();
        $this->addVisitor($workspace, $drop, 'open', now());

        $this->artisan('workspace:analytics-rebuild-daily', ['days' => '-1'])
            ->expectsOutput('The analytics lookback must be a non-negative whole number of days.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('workspace_daily_analytics', 0);
    }

    public function test_daily_analytics_all_option_backfills_older_source_events(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $drop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create();
        $this->addVisitor($workspace, $drop, 'open', now()->subDays(40));
        $this->addVisitor($workspace, $drop, 'click', now());

        $this->artisan('workspace:analytics-rebuild-daily', ['--all' => true])
            ->expectsOutput('Backfilled 2 daily campaign and Drop analytics rows from stored source events.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('workspace_daily_analytics', [
            'workspace_id' => $workspace->getKey(),
            'source_type' => 'drop',
            'source_id' => (string) $drop->getKey(),
            'at' => today()->subDays(40)->toDateString(),
            'opens' => 1,
        ]);
    }

    public function test_daily_analytics_command_rebuilds_a_valid_explicit_date_range(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $drop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create();
        $date = today()->subDays(5)->toDateString();
        $this->addVisitor($workspace, $drop, 'click', Carbon::parse($date)->setTime(12, 0));

        $this->artisan('workspace:analytics-rebuild-daily', ['--from' => $date, '--to' => $date])
            ->expectsOutput("Rebuilt 1 daily campaign and Drop analytics rows for {$date} through {$date}.")
            ->assertExitCode(0);

        $this->assertDatabaseHas('workspace_daily_analytics', [
            'workspace_id' => $workspace->getKey(),
            'source_type' => 'drop',
            'source_id' => (string) $drop->getKey(),
            'at' => $date,
            'clicks' => 1,
        ]);
    }

    public function test_daily_analytics_command_rejects_incomplete_explicit_range_without_writes(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $drop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create();
        $this->addVisitor($workspace, $drop, 'open', now());

        $this->artisan('workspace:analytics-rebuild-daily', ['--from' => today()->toDateString()])
            ->expectsOutput('The to field is required.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('workspace_daily_analytics', 0);
    }

    public function test_dashboard_requires_an_active_workspace_membership(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('console.analytics.index', $workspace->getKey()))
            ->assertNotFound();
    }

    public function test_dashboard_charts_zero_fill_empty_month_and_year_periods(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');

        $this->actingAs($owner)
            ->get(route('console.analytics.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('MTD ACTIONS STATS')
            ->assertSee('MTD REVENUE STATS')
            ->assertSee('YTD REVENUE STATS')
            ->assertSee('analytics-line-chart__svg', false)
            ->assertViewHas('dashboardCharts', fn (array $charts): bool => count($charts['mtd_actions']['labels']) === now()->daysInMonth
                && count($charts['ytd_revenue']['labels']) === 12
                && collect($charts['mtd_actions']['series'])->every(fn (array $series): bool => array_sum($series['values']) === 0)
                && array_sum($charts['mtd_revenue']['series'][0]['values']) === 0.0
                && array_sum($charts['ytd_revenue']['series'][0]['values']) === 0.0
            );
    }

    public function test_report_pages_render_a_clear_empty_state_without_events(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $this->actingAs($owner);

        foreach ([
            route('console.analytics.all-stats', $workspace->getKey()),
            route('console.analytics.isp-stats', $workspace->getKey()),
            route('console.analytics.mailer-revenue', $workspace->getKey()),
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('No matching campaign events, Drop visitor events, or attributed campaign leads')
                ->assertSee('analytics-dates')
                ->assertSee('name="from"', false)
                ->assertSee('name="to"', false);
        }

        $this->get(route('console.analytics.all-stats', $workspace->getKey()))
            ->assertOk()
            ->assertSee('analytics-dates')
            ->assertSee('/vendor/daterangepicker/daterangepicker.js')
            ->assertSee('analytics-date-range.js')
            ->assertSee('data-storage-key="all_stats"', false)
            ->assertSee('data-clear-analytics-date-range="all_stats"', false);

        $this->get(route('console.analytics.isp-stats', $workspace->getKey()))
            ->assertOk()
            ->assertSee('ENGAGEMENT BY PERIOD')
            ->assertSee('OPENS BY MAILER')
            ->assertSee('CLICKS BY MAILER')
            ->assertSee('LEADS BY MAILER')
            ->assertSee('UNSUBSCRIBES BY MAILER')
            ->assertSee('isp-stats-charts.js')
            ->assertSee('data-isp-stats-chart-data', false)
            ->assertSee('data-analytics-report-reload', false)
            ->assertSee('data-isp-sortable', false)
            ->assertSee('NO DATA AVAILABLE')
            ->assertSee('data-storage-key="isp_stats"', false)
            ->assertSee('data-analytics-report-refresh', false)
            ->assertSee('analytics-report-refresh.js');

        $this->get(route('console.analytics.mailer-revenue', $workspace->getKey()))
            ->assertOk()
            ->assertSee('script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' https://cdn3.devexpress.com; style-src \'self\' \'unsafe-inline\' https://cdn3.devexpress.com;', false)
            ->assertSee('data-storage-key="mailers_stats"', false)
            ->assertSee('data-analytics-report-refresh', false)
            ->assertSee('analytics-report-refresh.js')
            ->assertSee('id="mailer-revenue-pivot-shell"', false)
            ->assertSee('id="mailer-revenue-pivot-reload"', false)
            ->assertSee('analytics-pivot.js');
    }

    public function test_dms_dashboard_report_pages_filter_and_group_recorded_drop_events(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $isp = Isp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Example ISP',
            'is_active' => true,
            'allowed_users' => [],
            'allowed_sponsors' => [],
            'tracked_countries' => [],
            'quality_emails' => [],
        ]);
        $drop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create(['isp_id' => $isp->getKey()]);
        $this->addVisitor($workspace, $drop, 'open', now(), ispId: $isp->getKey());
        $this->addVisitor($workspace, $drop, 'click', now(), ispId: $isp->getKey());
        $this->addVisitor($workspace, $drop, 'lead', now(), 4.25, $isp->getKey());
        $this->addVisitor($workspace, $drop, 'unsubscribe', now(), ispId: $isp->getKey());
        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);

        $this->actingAs($owner)
            ->get(route('console.analytics.all-stats', [$workspace->getKey(), 'from' => today()->toDateString(), 'to' => today()->toDateString()]))
            ->assertOk()
            ->assertSee('ALL STATS REPORT')
            ->assertSee('ALL STATS')
            ->assertSee('FROM')
            ->assertSee('TO')
            ->assertSee('SHOW')
            ->assertSee('Showing 1–4 of 4 rows')
            ->assertSee('DMS delivery, bounce, opt-out, seed, aggregate-rate and shared-statistics inputs')
            ->assertViewHas('rows', fn ($rows): bool => $rows->count() === 4
                && $rows->getCollection()->firstWhere('type', 'lead')['lead_revenue'] === 4.25
            );

        $this->get(route('console.analytics.isp-stats', [
            $workspace->getKey(),
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
            'isp_id' => $isp->getKey(),
            'groupby' => 'week',
        ]))
            ->assertOk()
            ->assertSee('ISP STATS REPORT')
            ->assertSee('Example ISP')
            ->assertViewHas('rows', fn ($rows): bool => $rows->count() === 1
                && $rows->getCollection()->first()['opens'] === 1
                && $rows->getCollection()->first()['clicks'] === 1
                && $rows->getCollection()->first()['leads'] === 1
                && $rows->getCollection()->first()['unsubscribes'] === 1
                && $rows->getCollection()->first()['lead_revenue'] === 4.25
            )
            ->assertViewHas('chartData', function (array $data) use ($owner): bool {
                $this->assertSame([
                    Carbon::parse(today()->toDateString())->format('o-W'),
                ], $data['labels']);
                $this->assertSame([
                    'label' => $owner->name,
                    'values' => [
                        'opens' => [1],
                        'clicks' => [1],
                        'leads' => [1],
                        'unsubscribes' => [1],
                        'lead_revenue' => [4.25],
                    ],
                ], $data['mailers'][0]);
                $this->assertSame([
                    'opens' => 1,
                    'clicks' => 1,
                    'leads' => 1,
                    'unsubscribes' => 1,
                    'lead_revenue' => 4.25,
                ], $data['totals'][0]);

                return true;
            });

        $this->get(route('console.analytics.mailer-revenue', [$workspace->getKey(), 'from' => today()->toDateString(), 'to' => today()->toDateString()]))
            ->assertOk()
            ->assertSee('MAILERS REV STATS REPORT')
            ->assertViewHas('rows', fn ($rows): bool => $rows->count() === 1
                && $rows->getCollection()->first()['leads'] === 1
                && $rows->getCollection()->first()['lead_revenue'] === 4.25
            );

        $this->get(route('console.analytics.isp-stats', [
            $workspace->getKey(),
            'from' => Carbon::today()->subDay()->toDateString(),
            'to' => today()->toDateString(),
            'isp_id' => $isp->getKey(),
            'groupby' => 'day',
        ]))
            ->assertOk()
            ->assertViewHas('chartData', fn (array $data): bool => $data['labels'] === [
                Carbon::today()->subDay()->toDateString(),
                today()->toDateString(),
            ]
                && $data['totals'][0]['opens'] === 0
                && $data['totals'][1]['opens'] === 1
            );

        $this->get(route('console.analytics.isp-stats', [
            $workspace->getKey(),
            'from' => Carbon::today()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'to' => today()->toDateString(),
            'isp_id' => $isp->getKey(),
            'groupby' => 'month',
        ]))
            ->assertOk()
            ->assertViewHas('chartData', fn (array $data): bool => $data['labels'] === [
                Carbon::today()->subMonthNoOverflow()->format('Y-m'),
                today()->format('Y-m'),
            ]
                && $data['totals'][0]['opens'] === 0
                && $data['totals'][1]['opens'] === 1
            );

        $this->getJson(route('console.analytics.mailer-revenue', [
            $workspace->getKey(),
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ]))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.user', $owner->name)
            ->assertJsonPath('0.opens', 1)
            ->assertJsonPath('0.clicks', 1)
            ->assertJsonPath('0.leads', 1)
            ->assertJsonPath('0.unsubscribes', 1)
            ->assertJsonPath('0.lead_revenue', 4.25)
            ->assertJsonMissingPath('0.email');

        $this->get(route('console.analytics.isp-stats', [
            $workspace->getKey(),
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
            'isp_id' => $isp->getKey(),
            'groupby' => 'week',
            'fragment' => 1,
        ]))
            ->assertOk()
            ->assertViewIs('console.analytics.partials.report-results')
            ->assertSee('id="analytics-report-results"', false)
            ->assertSee('ENGAGEMENT BY PERIOD')
            ->assertSee('data-isp-stats-chart-data', false)
            ->assertSee('Example ISP')
            ->assertDontSee('<!DOCTYPE html>');

        $this->get(route('console.analytics.mailer-revenue', [
            $workspace->getKey(),
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
            'fragment' => 1,
        ]))
            ->assertOk()
            ->assertViewIs('console.analytics.partials.report-results')
            ->assertSee('id="analytics-report-results"', false)
            ->assertSee('4.25')
            ->assertDontSee('<!DOCTYPE html>');
    }

    private function makeWorkspace(User $user, string $role): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Analytics workspace '.Str::random(5),
            'slug' => 'analytics-'.Str::lower(Str::random(8)),
            'created_by' => $user->getKey(),
        ]);
        $workspace->users()->attach($user->getKey(), ['role' => $role]);

        return $workspace;
    }

    private function addVisitor(Workspace $workspace, WorkspaceDrop $drop, string $type, Carbon $createdAt, float $revenue = 0, ?int $ispId = null): void
    {
        WorkspaceDropVisitor::factory()->forDrop($drop)->create([
            'workspace_id' => $workspace->getKey(),
            'type' => $type,
            'created_at' => $createdAt,
            'revenue' => $revenue,
            'isp_id' => $ispId,
        ]);
    }
}
