<?php

namespace Tests\Feature;

use App\Http\Middleware\RewriteLegacyWorkspaceUrls;
use App\Models\AudienceListHistory;
use App\Models\Isp;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDrop;
use App\Models\WorkspaceDropVisitor;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceAnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(RewriteLegacyWorkspaceUrls::class);
    }

    public function test_daily_analytics_warmer_runs_in_the_scheduler_service_foreground(): void
    {
        Artisan::call('schedule:list');
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'workspace:analytics-warm-dashboard'));

        $this->assertNotNull($event);
        $this->assertFalse($event->runInBackground);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_daily_analytics_source_indexes_lead_with_the_event_time(): void
    {
        $expectedIndexes = [
            'campaign_engagement_events' => [
                'campaign_events_analytics_time_idx' => ['created_at', 'workspace_id'],
            ],
            'campaign_engagement_event_sources' => [
                'campaign_event_sources_analytics_time_idx' => ['created_at', 'workspace_id'],
            ],
            'campaign_lead_conversions' => [
                'campaign_leads_analytics_time_idx' => ['converted_at', 'workspace_id'],
            ],
            'workspace_drop_visitors' => [
                'drop_visitors_analytics_time_idx' => ['created_at', 'workspace_id'],
            ],
            'workspace_pmta_feedback_events' => [
                'pmta_feedback_analytics_time_idx' => ['at', 'workspace_id'],
            ],
        ];

        foreach ($expectedIndexes as $table => $indexes) {
            $actualIndexes = collect(Schema::getIndexes($table))->keyBy('name');

            foreach ($indexes as $name => $columns) {
                $this->assertSame($columns, $actualIndexes->get($name)['columns'] ?? null, "Missing or incorrectly ordered index: {$name}");
            }
        }
    }

    public function test_dashboard_aliases_load_react_assets_and_embed_parseable_json(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $this->actingAs($owner);

        $routes = [
            route('console.dashboard.main'),
            route('console.workspace.dashboard.main', ['workspaceSlug' => $workspace->slug]),
            route('console.analytics.index', ['workspace' => $workspace->getKey()]),
        ];

        foreach ($routes as $url) {
            $response = $this->get($url)->assertOk();
            $html = $response->getContent();

            $this->assertStringContainsString('command-os-dashboard-root', $html);
            $this->assertMatchesRegularExpression('/<script\\b[^>]*src="[^"]*command-os-[^"]*\\.js"/', $html);
            $this->assertMatchesRegularExpression('/<link\\b[^>]*href="[^"]*command-os-[^"]*\\.css"/', $html);
            $this->assertCommandOsPayloadIsParseable($html);
        }

        $fragment = $this->get(route('console.analytics.index', [
            'workspace' => $workspace->getKey(),
            'fragment' => 1,
        ]))->assertOk();
        $this->assertCommandOsPayloadIsParseable($fragment->getContent());
    }

    public function test_dashboard_header_shortcuts_and_page_search_follow_workspace_permissions(): void
    {
        foreach (['owner', 'admin', 'operator'] as $role) {
            $user = User::factory()->create();
            $workspace = $this->makeWorkspace($user, $role);
            $html = $this->actingAs($user)->get(route('console.dashboard.main'))->assertOk()->getContent();

            $this->assertStringContainsString('aria-label="Campaigns"', $html);
            $this->assertStringContainsString('href="'.route('console.mailer.campaigns').'" title="CAMPAIGNS" aria-label="Campaigns"', $html);
            $this->assertStringContainsString('href="'.route('clean.console.global-tests.index').'" title="GLOBAL TEST" aria-label="Global Test"', $html);
            $this->assertStringContainsString('data-action="command_os_search_open"', $html);
            $this->assertStringContainsString('command-os-header-leading', $html);
            $this->assertStringContainsString('command-os-header-trailing', $html);
            $this->assertStringContainsString('aria-label="Account menu"', $html);
            $this->assertStringContainsString('id="command-os-search-dialog"', $html);
            $this->assertStringContainsString('command-os-search.js', $html);
        }

        $privacyOfficer = User::factory()->create();
        $this->makeWorkspace($privacyOfficer, 'privacy_officer');
        $html = $this->actingAs($privacyOfficer)->get(route('console.dashboard.main'))->assertOk()->getContent();

        $this->assertStringNotContainsString('aria-label="Campaigns"', $html);
        $this->assertStringNotContainsString('aria-label="Global Test"', $html);
        $this->assertStringContainsString('data-action="command_os_search_open"', $html);
        $this->assertStringContainsString('command-os-header-leading', $html);
        $this->assertStringContainsString('command-os-header-trailing', $html);
        $this->assertStringContainsString('aria-label="Account menu"', $html);
        $this->assertStringContainsString('id="command-os-search-dialog"', $html);
        $this->assertStringContainsString('aria-label="Open navigation"', $html);
        $this->assertStringContainsString('aria-controls="sidebar" aria-expanded="false"', $html);
        $this->assertStringContainsString('data-action="sidebar_backdrop"', $html);
        $this->assertStringContainsString('command-os-drawer.js', $html);
    }

    public function test_dashboard_nested_campaign_and_global_test_links_match_the_workspace_role(): void
    {
        $campaignLinks = [
            'Send' => route('console.mailer.campaigns'),
            'GSend Projects' => route('clean.console.gcampaigns.projects'),
            'GSend Accounts' => route('clean.console.gcampaigns.accounts'),
            'Mixs' => route('clean.console.audience-mixes.history'),
        ];

        foreach (['owner', 'admin', 'operator'] as $role) {
            $user = User::factory()->create();
            $this->makeWorkspace($user, $role);
            $html = $this->actingAs($user)->get(route('console.dashboard.main'))->assertOk()->getContent();

            $this->assertSame($campaignLinks, $this->dashboardNavigationLinks($html, 'nav-submenu-campaigns'));
            $this->assertStringContainsString('aria-controls="nav-submenu-tools-utilities"', $html);
            $this->assertStringContainsString('href="'.route('clean.console.global-tests.index').'"', $html);
            $this->assertStringContainsString('href="'.route('clean.console.global-tests.histories').'"', $html);
            $this->assertStringContainsString('<summary aria-label="Toggle Campaigns pages" aria-controls="nav-submenu-campaigns"', $html);
            $this->assertDoesNotMatchRegularExpression('/<details class="command-os-navigation-disclosure" open>\s*<summary aria-label="Toggle Campaigns pages"/', $html);
            $this->assertStringContainsString('aria-disabled="true"', $html);
            $this->assertStringContainsString('class="command-os-navigation"', $html);
            $this->assertStringContainsString('>Dashboard</span>', $html);
            $this->assertStringContainsString('>Lists &amp; Audiences</span>', $html);
            $this->assertStringContainsString('>ISP &amp; GEO</span>', $html);
        }

        $privacyOfficer = User::factory()->create();
        $privacyWorkspace = $this->makeWorkspace($privacyOfficer, 'privacy_officer');
        $html = $this->actingAs($privacyOfficer)->get(route('console.dashboard.main'))->assertOk()->getContent();

        $this->assertSame([
            'GSend Projects' => $campaignLinks['GSend Projects'],
            'GSend Accounts' => $campaignLinks['GSend Accounts'],
        ], $this->dashboardNavigationLinks($html, 'nav-submenu-campaigns'));
        $this->assertStringNotContainsString('href="'.route('console.mailer.campaigns').'"', $html);
        $this->assertStringNotContainsString('href="'.route('clean.console.campaign-negatives.index').'"', $html);
        $this->assertStringNotContainsString('href="'.route('clean.console.global-tests.index').'"', $html);
        $this->assertStringNotContainsString('href="'.route('clean.console.global-tests.histories').'"', $html);
        $this->assertStringNotContainsString('href="'.route('clean.console.offers.index').'"', $html);
        $this->assertStringContainsString('href="'.route('clean.console.networks.offers.index').'"', $html);
        $this->assertStringContainsString('aria-disabled="true"', $html);

        $this->get(route('console.gcampaigns.projects', $privacyWorkspace->getKey()))
            ->assertOk()
            ->assertSee('href="'.route('clean.console.global-tests.index').'"', false);

    }

    public function test_dashboard_quick_actions_use_role_authorized_get_pages_and_disable_unavailable_tracking(): void
    {
        $owner = User::factory()->create();
        $ownerWorkspace = $this->makeWorkspace($owner, 'owner');

        $ownerLabels = ['Create Campaign', 'New Drop', 'Start Warmup', 'Upload List', 'Check Deliverability', 'Track a Link'];
        foreach ([false, true] as $fragment) {
            $query = $fragment ? ['fragment' => 1] : [];
            $html = $this->actingAs($owner)
                ->get(route('console.analytics.index', ['workspace' => $ownerWorkspace->getKey()] + $query))
                ->assertOk()->getContent();
            $payload = $this->assertCommandOsPayloadIsParseable($html);
            $this->assertSame($ownerLabels, collect($payload['actions'])->pluck('label')->all());
            $this->assertNotContains('All Stats', collect($payload['actions'])->pluck('label')->all());
        }

        $this->actingAs($owner)
            ->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertViewHas('commandOsDashboard', function (array $dashboard) use ($ownerWorkspace): bool {
                $actions = collect($dashboard['actions'])->keyBy('label');

                return $actions->keys()->all() === [
                    'Create Campaign',
                    'New Drop',
                    'Start Warmup',
                    'Upload List',
                    'Check Deliverability',
                    'Track a Link',
                ]
                    && $actions->get('Create Campaign')['href'] === route('console.campaigns.create', $ownerWorkspace->getKey())
                    && $actions->get('New Drop')['href'] === route('console.drops.drafts.create', $ownerWorkspace->getKey())
                    && $actions->get('Start Warmup')['href'] === route('console.warmups.create', $ownerWorkspace->getKey())
                    && $actions->get('Upload List')['href'] === route('console.infrastructure.lists.index', $ownerWorkspace->getKey())
                    && $actions->get('Check Deliverability')['href'] === route('console.global-tests.index', $ownerWorkspace->getKey())
                    && $actions->get('Track a Link')['href'] === null
                    && $actions->get('Track a Link')['disabled'] === true
                    && $actions->get('Track a Link')['reason'] !== '';
            });

        foreach ([
            route('console.campaigns.create', $ownerWorkspace->getKey()),
            route('console.drops.drafts.create', $ownerWorkspace->getKey()),
            route('console.warmups.create', $ownerWorkspace->getKey()),
            route('console.infrastructure.lists.index', $ownerWorkspace->getKey()),
            route('console.global-tests.index', $ownerWorkspace->getKey()),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        $operator = User::factory()->create();
        $operatorWorkspace = $this->makeWorkspace($operator, 'operator');
        $this->actingAs($operator)
            ->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertViewHas('commandOsDashboard', function (array $dashboard): bool {
                $labels = collect($dashboard['actions'])->pluck('label')->all();

                return $labels === ['Create Campaign', 'New Drop', 'Start Warmup', 'Check Deliverability', 'Track a Link']
                    && ! collect($dashboard['actions'])->contains(fn (array $action): bool => $action['label'] === 'Upload List' && filled($action['href']));
            });

        foreach ([false, true] as $fragment) {
            $query = $fragment ? ['fragment' => 1] : [];
            $payload = $this->assertCommandOsPayloadIsParseable($this->actingAs($operator)
                ->get(route('console.analytics.index', ['workspace' => $operatorWorkspace->getKey()] + $query))
                ->assertOk()->getContent());
            $labels = collect($payload['actions'])->pluck('label')->all();
            $this->assertSame(['Create Campaign', 'New Drop', 'Start Warmup', 'Check Deliverability', 'Track a Link'], $labels);
            $this->assertNotContains('All Stats', $labels);
        }

        $privacyOfficer = User::factory()->create();
        $privacyWorkspace = $this->makeWorkspace($privacyOfficer, 'privacy_officer');
        $this->actingAs($privacyOfficer)
            ->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertViewHas('commandOsDashboard', fn (array $dashboard): bool => $dashboard['actions'] === [[
                'label' => 'Track a Link',
                'href' => null,
                'icon' => 'link',
                'disabled' => true,
                'reason' => 'No safe link-tracking page is available yet.',
            ]]);

        foreach ([false, true] as $fragment) {
            $query = $fragment ? ['fragment' => 1] : [];
            $payload = $this->assertCommandOsPayloadIsParseable($this->actingAs($privacyOfficer)
                ->get(route('console.analytics.index', ['workspace' => $privacyWorkspace->getKey()] + $query))
                ->assertOk()->getContent());
            $this->assertSame([[
                'label' => 'Track a Link', 'href' => null, 'icon' => 'link', 'disabled' => true,
                'reason' => 'No safe link-tracking page is available yet.',
            ]], $payload['actions']);
        }

        foreach ([
            route('console.campaigns.create', $privacyWorkspace->getKey()),
            route('console.drops.drafts.create', $privacyWorkspace->getKey()),
            route('console.warmups.create', $privacyWorkspace->getKey()),
            route('console.global-tests.index', $privacyWorkspace->getKey()),
        ] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get(route('console.infrastructure.lists.index', $privacyWorkspace->getKey()))->assertForbidden();
    }

    public function test_last_thirty_days_includes_data_from_december_when_january_starts(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $this->travelTo(Carbon::parse('2027-01-10 12:00:00'));

        DB::table('workspace_daily_analytics')->insert([
            'workspace_id' => $workspace->getKey(),
            'source_type' => 'drop',
            'source_id' => 'december-boundary',
            'at' => '2026-12-20',
            'dimensions_hash' => hash('sha256', 'december-boundary'),
            'user_id' => $owner->getKey(),
            'opens' => 7,
            'clicks' => 3,
            'leads' => 1,
            'unsubscribes' => 0,
            'bots' => 0,
            'lead_revenue' => 5.25,
            'deliveries' => 1200,
            'soft_bounces' => 4,
            'hard_bounces' => 2,
            'attribution_scope' => 'workspace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertViewHas('commandOsDashboard', function (array $dashboard): bool {
                $dayIndex = array_search('Dec 20', $dashboard['trend']['labels'], true);

                return $dashboard['period']['from'] === '2026-12-12'
                    && $dayIndex !== false
                    && $dashboard['trend']['deliveries'][$dayIndex] === 1200
                    && $dashboard['trend']['opens'][$dayIndex] === 7;
            });
    }

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

        $this->addVisitor($workspace, $drop, 'open', now()->subDay(), country: 'United States');
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
        $this->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertSee('command-os-dashboard-root', false)
            ->assertSee('Dashboard')
            ->assertSee('CAMPAIGN & DROP ENGAGEMENT')
            ->assertSee('LATEST AUDIENCE-LIST STATES')
            ->assertSee(route('clean.console.analytics.all-stats'), false)
            ->assertSee(route('clean.console.analytics.isp-stats'), false)
            ->assertSee(route('clean.console.analytics.mailer-revenue'), false)
            ->assertSee('Attributed lead revenue only')
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 2
                && $totals['clicks'] === 2
                && $totals['leads'] === 2
                && $totals['unsubscribes'] === 1
                && $totals['lead_revenue'] === 10.0
            )
            ->assertViewHas('commandOsDashboard', function (array $dashboard) use ($owner): bool {
                $metrics = collect($dashboard['metrics'])->keyBy('key');
                $analytics = $dashboard['analytics'];
                $graphNodes = collect($analytics['graph']['nodes'])->keyBy('id');

                return $dashboard['workspaceRole'] === 'owner'
                    && $metrics->get('opens')['value'] === 2
                    && $metrics->get('clicks')['value'] === 2
                    && $metrics->get('leads')['value'] === 2
                    && $metrics->get('revenue')['value'] === 10.0
                    && $metrics->get('unsubscribes')['value'] === 1
                    && count($dashboard['trend']['labels']) === 30
                    && count($dashboard['trend']['deliveries']) === 30
                    && $dashboard['refresh']['interval'] === 300000
                    && str_contains($dashboard['refresh']['url'], 'fragment=1')
                    && $analytics['period']['key'] === 'this_month'
                    && $analytics['sponsors'][0]['label'] === 'Analytics sponsor'
                    && $analytics['sponsors'][0]['attributed_revenue'] === 10.0
                    && $analytics['isps'][0]['label'] === 'Analytics ISP'
                    && $analytics['isps'][0]['attributed_revenue'] === 10.0
                    && $analytics['offers'][0]['label'] === 'Analytics offer'
                    && $analytics['offers'][0]['attributed_revenue'] === 10.0
                    && $analytics['mailers'][0]['label'] === $owner->name
                    && $analytics['mailers'][0]['attributed_revenue'] === 10.0
                    && $analytics['geo']['countries'][0]['country'] === 'United States'
                    && $analytics['geo']['countries'][0]['opens'] === 1
                    && count($dashboard['charts']['mtd_actions']['labels']) === now()->day
                    && count($dashboard['charts']['mtd_revenue']['labels']) === now()->day
                    && count($dashboard['charts']['ytd_revenue']['labels']) === now()->month
                    && $graphNodes->get('drops')['count'] === 1
                    && $graphNodes->get('tracking')['count'] === 7;
            })
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
                $actions = collect($charts['mtd_actions']['series'])->keyBy('label');

                return count($charts['mtd_actions']['labels']) === now()->daysInMonth
                    && $actions->get('Deliveries')['values'][$todayIndex] === 0
                    && $actions->get('Bounces')['values'][$todayIndex] === 0
                    && $actions->get('Opens')['values'][$todayIndex] === 1
                    && $actions->get('Clicks')['values'][$todayIndex] === 1
                    && $actions->get('Unsubscribes')['values'][$todayIndex] === 1
                    && $actions->get('Leads (AlphaLink)')['values'][$todayIndex] === 1
                    && array_sum($charts['mtd_revenue']['series'][0]['values']) === 10.0
                    && count($charts['ytd_revenue']['labels']) === 12
                    && $charts['ytd_revenue']['series'][0]['values'][$currentMonthIndex] === 10.0;
            })
            ->assertViewHas('snapshotCounts', fn (array $counts): bool => $counts['open'] === 5
                && $counts['click'] === 3
                && $counts['lead'] === 2
                && $counts['unsubscribe'] === 1
            );

        $this->get(route('console.analytics.index', [
            'workspace' => $workspace->getKey(),
            'analytics_period' => 'today',
        ]))
            ->assertOk()
            ->assertViewHas('commandOsDashboard', function (array $dashboard) use ($owner): bool {
                $analytics = $dashboard['analytics'];

                return $analytics['period']['key'] === 'today'
                    && $analytics['sponsors'][0]['attributed_revenue'] === 2.5
                    && $analytics['isps'][0]['attributed_revenue'] === 2.5
                    && $analytics['mailers'][0]['attributed_revenue'] === 2.5
                    && $analytics['offers'][0]['attributed_revenue'] === 2.5
                    && $analytics['sponsors'][0]['leads'] === 1
                    && $analytics['isps'][0]['leads'] === 1
                    && $analytics['mailers'][0]['leads'] === 1
                    && $analytics['offers'][0]['leads'] === 1
                    && $analytics['mailers'][0]['label'] === $owner->name;
            });

        $this->get(route('clean.console.audiences.index'))
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

    public function test_dashboard_analytics_filter_supports_all_presets_and_custom_dates(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $today = Carbon::today();
        $cases = [
            'today' => [$today->toDateString(), $today->toDateString()],
            'yesterday' => [$today->copy()->subDay()->toDateString(), $today->copy()->subDay()->toDateString()],
            'this_week' => [$today->copy()->startOfWeek(Carbon::MONDAY)->toDateString(), $today->toDateString()],
            'this_month' => [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
        ];

        foreach ($cases as $key => [$from, $to]) {
            $this->actingAs($owner)
                ->get(route('console.analytics.index', ['workspace' => $workspace->getKey(), 'analytics_period' => $key]))
                ->assertOk()
                ->assertViewHas('commandOsDashboard', fn (array $dashboard): bool => $dashboard['analytics']['period']['key'] === $key
                    && $dashboard['analytics']['period']['from'] === $from
                    && $dashboard['analytics']['period']['to'] === $to);
        }

        $from = $today->copy()->subDays(4)->toDateString();
        $to = $today->copy()->subDay()->toDateString();
        $this->actingAs($owner)
            ->get(route('console.analytics.index', [
                'workspace' => $workspace->getKey(),
                'fragment' => 1,
                'analytics_period' => 'custom',
                'analytics_from' => $from,
                'analytics_to' => $to,
            ]))
            ->assertOk()
            ->assertSee('analytics_period=custom', false)
            ->assertSee('analytics_from='.$from, false)
            ->assertSee('analytics_to='.$to, false)
            ->assertViewHas('commandOsDashboard', function (array $dashboard) use ($from, $to): bool {
                $query = [];
                parse_str((string) parse_url($dashboard['refresh']['url'], PHP_URL_QUERY), $query);

                return $dashboard['analytics']['period']['key'] === 'custom'
                    && $dashboard['analytics']['period']['from'] === $from
                    && $dashboard['analytics']['period']['to'] === $to
                    && ($query['analytics_period'] ?? null) === 'custom'
                    && ($query['analytics_from'] ?? null) === $from
                    && ($query['analytics_to'] ?? null) === $to;
            });

        $this->actingAs($owner)
            ->get(route('console.analytics.index', [
                'workspace' => $workspace->getKey(),
                'analytics_period' => 'custom',
                'analytics_from' => $today->toDateString(),
                'analytics_to' => $today->copy()->subDay()->toDateString(),
            ]))
            ->assertSessionHasErrors(['analytics_to']);
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
            ->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 1 && $totals['clicks'] === 0
            );
    }

    public function test_dashboard_uses_workspace_totals_not_list_attribution_rollups(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $isp = Isp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Attribution scope ISP',
            'is_active' => true,
            'allowed_users' => [],
            'allowed_sponsors' => [],
            'tracked_countries' => [],
            'quality_emails' => [],
        ]);
        $base = [
            'workspace_id' => $workspace->getKey(),
            'source_type' => 'campaign',
            'at' => today()->toDateString(),
            'user_id' => $owner->getKey(),
            'isp_id' => (string) $isp->getKey(),
            'opens' => 0,
            'clicks' => 0,
            'leads' => 0,
            'unsubscribes' => 0,
            'bots' => 0,
            'deliveries' => 0,
            'soft_bounces' => 0,
            'hard_bounces' => 0,
            'lead_revenue' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('workspace_daily_analytics')->insert([
            ...$base,
            'source_id' => 'synthetic-list-rollup',
            'dimensions_hash' => hash('sha256', 'list-scope'),
            'attribution_scope' => 'list',
            'opens' => 9,
            'clicks' => 9,
            'leads' => 9,
            'unsubscribes' => 9,
            'lead_revenue' => 90,
        ]);
        DB::table('workspace_daily_analytics')->insert([
            ...$base,
            'source_id' => 'synthetic-workspace-rollup',
            'dimensions_hash' => hash('sha256', 'workspace-scope'),
            'attribution_scope' => 'workspace',
            'opens' => 1,
            'clicks' => 1,
            'leads' => 1,
            'unsubscribes' => 1,
            'lead_revenue' => 2.5,
        ]);

        $this->actingAs($owner)
            ->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 1
                && $totals['clicks'] === 1
                && $totals['leads'] === 1
                && $totals['unsubscribes'] === 1
            )
            ->assertViewHas('commandOsDashboard', function (array $dashboard) use ($owner): bool {
                $analytics = $dashboard['analytics'];

                return $analytics['isps'][0] === [
                    'label' => 'Attribution scope ISP',
                    'leads' => 1,
                    'clicks' => 1,
                    'attributed_revenue' => 2.5,
                ]
                    && $analytics['mailers'][0]['label'] === $owner->name
                    && $analytics['mailers'][0]['attributed_revenue'] === 2.5;
            });

        $this->getJson(route('clean.console.analytics.mailer-revenue', [
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ]))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.opens', 1)
            ->assertJsonPath('0.clicks', 1)
            ->assertJsonPath('0.leads', 1)
            ->assertJsonPath('0.unsubscribes', 1)
            ->assertJsonPath('0.lead_revenue', 2.5);

        $this->get(route('clean.console.analytics.isp-stats', [
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
            'isp_id' => $isp->getKey(),
        ]))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows): bool => $rows->count() === 1
                && $rows->first()['opens'] === 1
                && $rows->first()['clicks'] === 1
                && $rows->first()['leads'] === 1
                && $rows->first()['unsubscribes'] === 1
                && $rows->first()['lead_revenue'] === 2.5
            );

        $this->getJson(route('clean.console.analytics.all-stats', [
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ]))
            ->assertOk()
            ->assertJsonCount(4)
            ->assertJsonFragment(['opens' => 9])
            ->assertJsonFragment(['clicks' => 9])
            ->assertJsonFragment(['unsubscribes' => 9])
            ->assertJsonFragment(['lead_revenue' => 90]);
    }

    public function test_main_dashboard_refresh_fragment_uses_the_same_workspace_scope(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $drop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create();
        $this->addVisitor($workspace, $drop, 'open', now());

        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);

        $this->get(route('console.dashboard.main').'?fragment=1')
            ->assertRedirect(route('login'));

        $fragment = $this->actingAs($owner)
            ->get(route('console.dashboard.main').'?fragment=1');
        $fragment
            ->assertOk()
            ->assertViewIs('console.analytics.partials.main-dashboard-content')
            ->assertSee('data-main-dashboard-refresh', false)
            ->assertSee('data-refresh-interval="300000"', false)
            ->assertDontSee('<!doctype html', false)
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 1);
        $this->assertCommandOsPayloadIsParseable($fragment->getContent());

        $stranger = User::factory()->create();
        $this->actingAs($stranger)
            ->get(route('console.dashboard.main').'?fragment=1')
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
            ->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['clicks'] === 1)
            ->assertViewHas('commandOsDashboard', function (array $dashboard) use ($operator): bool {
                $clicks = collect($dashboard['metrics'])->firstWhere('key', 'clicks');
                $nodes = collect($dashboard['analytics']['graph']['nodes'])->keyBy('id');

                return $dashboard['workspaceRole'] === 'operator'
                    && $dashboard['userName'] === $operator->name
                    && $clicks['value'] === 1
                    && $dashboard['analytics']['totals']['clicks'] === 1
                    && $nodes->get('drops')['count'] === 1
                    && $nodes->get('tracking')['count'] === 1;
            })
            ->assertViewHas('snapshotCounts', fn (array $counts): bool => $counts['open'] === 0);

        $operatorIspReport = $this->get(route('clean.console.analytics.isp-stats', [
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
            'isp_id' => $isp->getKey(),
        ]))
            ->assertOk();
        $operatorChart = $this->ispChartData((string) $operatorIspReport->getContent());
        $this->assertCount(1, $operatorChart['mailers']);
        $this->assertSame($operator->name, $operatorChart['mailers'][0]['label']);
        $this->assertSame([1], $operatorChart['mailers'][0]['values']['clicks']);
        $this->assertSame([0], $operatorChart['mailers'][0]['values']['deliveries']);
        $this->assertSame(1, $operatorChart['totals'][0]['clicks']);

        $this->get(route('clean.console.audiences.index'))
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
            ->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 1 && $totals['clicks'] === 0);

        $this->addVisitor($workspace, $drop, 'click', now());
        $this->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 1 && $totals['clicks'] === 0);

        $this->travel(61)->seconds();
        $this->artisan('workspace:analytics-rebuild-daily')->assertExitCode(0);
        $this->get(route('console.dashboard.main'))
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
            ->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 1 && $totals['clicks'] === 1 && $totals['leads'] === 1)
            ->assertViewHas('commandOsDashboard', fn (array $dashboard): bool => $dashboard['analytics']['mailers'][0]['attributed_revenue'] === 1.0);

        $this->actingAs($operator)
            ->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 0 && $totals['clicks'] === 1);

        $this->travel(61)->seconds();
        $this->artisan('workspace:analytics-warm-dashboard')
            ->expectsOutput('Warmed dashboard metrics for 2 active workspace members.')
            ->assertExitCode(0);

        $this->actingAs($owner)
            ->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 2 && $totals['clicks'] === 2 && $totals['leads'] === 2)
            ->assertViewHas('commandOsDashboard', fn (array $dashboard): bool => $dashboard['analytics']['mailers'][0]['attributed_revenue'] === 3.0);

        $this->actingAs($operator)
            ->get(route('console.dashboard.main'))
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
            ->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertViewHas('eventTotals', fn (array $totals): bool => $totals['opens'] === 1 && $totals['clicks'] === 1);

        $this->actingAs($operator)
            ->get(route('console.dashboard.main'))
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
            ->get(route('console.dashboard.main'))
            ->assertNotFound();
    }

    public function test_dashboard_charts_zero_fill_empty_month_and_year_periods(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');

        $this->actingAs($owner)
            ->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertSee('MTD ACTIONS STATS')
            ->assertSee('MTD REVENUE STATS')
            ->assertSee('YTD REVENUE STATS')
            ->assertSee('analytics-charts', false)
            ->assertSee('analytics-line-chart__svg', false)
            ->assertSee('Leads (AlphaLink)')
            ->assertViewHas('dashboardCharts', fn (array $charts): bool => count($charts['mtd_actions']['labels']) === now()->daysInMonth
                && count($charts['ytd_revenue']['labels']) === 12
                && collect($charts['mtd_actions']['series'])->pluck('label')->all() === ['Deliveries', 'Bounces', 'Opens', 'Clicks', 'Unsubscribes', 'Leads (AlphaLink)']
                && collect($charts['mtd_actions']['series'])->pluck('color')->unique()->count() === 6
                && collect($charts['mtd_actions']['series'])->every(fn (array $series): bool => array_sum($series['values']) === 0)
                && array_sum($charts['mtd_revenue']['series'][0]['values']) === 0.0
                && array_sum($charts['ytd_revenue']['series'][0]['values']) === 0.0
            );
    }

    public function test_report_pages_render_a_clear_empty_state_without_events(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->makeWorkspace($owner, 'owner');
        $this->actingAs($owner)->get(route('console.dashboard.main'))->assertOk();

        foreach ([
            route('clean.console.analytics.all-stats'),
            route('clean.console.analytics.isp-stats'),
            route('clean.console.analytics.mailer-revenue'),
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('No matching campaign events, Drop visitor events, or attributed campaign leads')
                ->assertSee('analytics-dates')
                ->assertSee('name="from"', false)
                ->assertSee('name="to"', false);
        }

        $this->get(route('clean.console.analytics.all-stats'))
            ->assertOk()
            ->assertSee('analytics-dates')
            ->assertSee('/vendor/daterangepicker/daterangepicker.js')
            ->assertSee('analytics-date-range.js')
            ->assertSee('data-storage-key="all_stats"', false)
            ->assertSee('data-clear-analytics-date-range="all_stats"', false);

        $this->get(route('clean.console.analytics.isp-stats'))
            ->assertOk()
            ->assertSee('ENGAGEMENT BY PERIOD')
            ->assertSee('DELIVERIES BY MAILER')
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

        $this->get(route('clean.console.analytics.mailer-revenue'))
            ->assertOk()
            ->assertSee('script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' https://cdn3.devexpress.com; style-src \'self\' \'unsafe-inline\' https://cdn3.devexpress.com;', false)
            ->assertSee('data-storage-key="mailers_stats"', false)
            ->assertSee('data-analytics-report-refresh', false)
            ->assertSee('analytics-report-refresh.js')
            ->assertSee('id="mailer-revenue-pivot-shell"', false)
            ->assertSee('id="mailer-revenue-pivot-reload"', false)
            ->assertSee('analytics-pivot.js');
    }

    public function test_reference_dashboard_report_pages_filter_and_group_recorded_drop_events(): void
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

        $this->actingAs($owner)->get(route('console.dashboard.main'))->assertOk();
        $this->get(route('clean.console.analytics.all-stats', ['from' => today()->toDateString(), 'to' => today()->toDateString()]))
            ->assertOk()
            ->assertSee('ALL STATS REPORT')
            ->assertSee('ALL STATS')
            ->assertSee('name="from" type="hidden" value="'.today()->toDateString().'" required', false)
            ->assertSee('name="to" type="hidden" value="'.today()->toDateString().'" required', false)
            ->assertSee('SHOW')
            ->assertSee('Showing 1–4 of 4 rows')
            ->assertSee('matching warmup/seed rows')
            ->assertSee('4.25');

        $weekResponse = $this->get(route('clean.console.analytics.isp-stats', [
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
            'isp_id' => $isp->getKey(),
            'groupby' => 'week',
        ]));
        $weekResponse
            ->assertOk()
            ->assertSee('ISP STATS REPORT')
            ->assertSee('Example ISP')
            ->assertSee('Open-rate and click-rate formulas');
        $this->assertSame([
            Carbon::parse(today()->toDateString())->startOfWeek()->toDateString(),
            'Example ISP', '0', '1', '1', '1', '1', '$4.25',
        ], $this->reportTableRows((string) $weekResponse->getContent())[0]);
        $weekChart = $this->ispChartData((string) $weekResponse->getContent());
        $this->assertSame([Carbon::parse(today()->toDateString())->format('o-W')], $weekChart['labels']);
        $this->assertSame([
            'label' => $owner->name,
            'values' => [
                'deliveries' => [0],
                'opens' => [1],
                'clicks' => [1],
                'leads' => [1],
                'unsubscribes' => [1],
                'lead_revenue' => [4.25],
            ],
        ], $weekChart['mailers'][0]);
        $this->assertSame([
            'deliveries' => 0,
            'opens' => 1,
            'clicks' => 1,
            'leads' => 1,
            'unsubscribes' => 1,
            'lead_revenue' => 4.25,
        ], $weekChart['totals'][0]);

        $mailerResponse = $this->get(route('clean.console.analytics.mailer-revenue', ['from' => today()->toDateString(), 'to' => today()->toDateString()]))
            ->assertOk()
            ->assertSee('MAILERS REV STATS REPORT');
        $this->assertSame([
            $owner->name, '0', '0', '0', '1', '1', '1', '1', '0', '$4.25',
        ], $this->reportTableRows((string) $mailerResponse->getContent())[0]);

        $dayResponse = $this->get(route('clean.console.analytics.isp-stats', [
            'from' => Carbon::today()->subDay()->toDateString(),
            'to' => today()->toDateString(),
            'isp_id' => $isp->getKey(),
            'groupby' => 'day',
        ]))->assertOk();
        $dayChart = $this->ispChartData((string) $dayResponse->getContent());
        $this->assertSame([
            Carbon::today()->subDay()->toDateString(),
            today()->toDateString(),
        ], $dayChart['labels']);
        $this->assertSame([0, 1], array_column($dayChart['totals'], 'opens'));
        $this->assertSame([0, 0], array_column($dayChart['totals'], 'deliveries'));

        $monthResponse = $this->get(route('clean.console.analytics.isp-stats', [
            'from' => Carbon::today()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'to' => today()->toDateString(),
            'isp_id' => $isp->getKey(),
            'groupby' => 'month',
        ]))->assertOk();
        $monthChart = $this->ispChartData((string) $monthResponse->getContent());
        $this->assertSame([
            Carbon::today()->subMonthNoOverflow()->format('Y-m'),
            today()->format('Y-m'),
        ], $monthChart['labels']);
        $this->assertSame([0, 1], array_column($monthChart['totals'], 'opens'));

        $this->getJson(route('clean.console.analytics.mailer-revenue', [
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ]))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.user', $owner->name)
            ->assertJsonPath('0.opens', 1)
            ->assertJsonPath('0.clicks', 1)
            ->assertJsonPath('0.leads', 1)
            ->assertJsonPath('0.deliveries', 0)
            ->assertJsonPath('0.soft_bounces', 0)
            ->assertJsonPath('0.hard_bounces', 0)
            ->assertJsonPath('0.unsubscribes', 1)
            ->assertJsonPath('0.lead_revenue', 4.25)
            ->assertJsonMissingPath('0.email');

        $this->get(route('clean.console.analytics.isp-stats', [
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

        $this->get(route('clean.console.analytics.mailer-revenue', [
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

    /** @return array<string, string> */
    private function dashboardNavigationLinks(string $html, string $submenuId): array
    {
        $document = new \DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        $xpath = new \DOMXPath($document);
        $links = $xpath->query(sprintf('//*[@id="%s"]//a[@href and not(@aria-disabled="true") and not(@data-toggle="submenu")]', $submenuId));
        $result = [];
        foreach ($links as $link) {
            $label = trim(preg_replace('/\s+/', ' ', $link->textContent) ?? '');
            $result[$label] = $link->getAttribute('href');
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function assertCommandOsPayloadIsParseable(string $html): array
    {
        if (preg_match('/<script type="application\\/json" id="command-os-dashboard-data">(.*?)<\\/script>/s', $html, $matches) !== 1) {
            $this->fail('The Command OS dashboard JSON payload was not rendered.');
        }

        $payload = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('metrics', $payload);
        $this->assertArrayHasKey('trend', $payload);

        return $payload;
    }

    private function addVisitor(Workspace $workspace, WorkspaceDrop $drop, string $type, Carbon $createdAt, float $revenue = 0, ?int $ispId = null, ?string $country = null): void
    {
        WorkspaceDropVisitor::factory()->forDrop($drop)->create([
            'workspace_id' => $workspace->getKey(),
            'type' => $type,
            'created_at' => $createdAt,
            'revenue' => $revenue,
            'isp_id' => $ispId,
            'country' => $country,
        ]);
    }

    /** @return array<int, array<int, string>> */
    private function reportTableRows(string $html): array
    {
        $previousErrors = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        $xpath = new \DOMXPath($document);
        $rows = $xpath->query('//*[@id="analytics-report-results"]//table[contains(concat(" ", normalize-space(@class), " "), " table-striped ")]/tbody/tr');

        return array_map(function (\DOMElement $row): array {
            $cells = [];
            foreach ($row->childNodes as $cell) {
                if ($cell instanceof \DOMElement && in_array(strtolower($cell->tagName), ['th', 'td'], true)) {
                    $cells[] = trim(preg_replace('/\s+/', ' ', $cell->textContent) ?? '');
                }
            }

            return $cells;
        }, iterator_to_array($rows));
    }

    /** @return array{labels: array<int, string>, totals: array<int, array<string, int|float>>, mailers: array<int, array{label: string, values: array<string, array<int, int|float>>}>} */
    private function ispChartData(string $html): array
    {
        $matched = preg_match('~<script type="application/json" data-isp-stats-chart-data>(.*?)</script>~s', $html, $matches);
        $this->assertSame(1, $matched, 'The rendered ISP report must expose its chart data for the page behavior.');

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }
}
