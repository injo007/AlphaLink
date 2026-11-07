<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CampaignRevision;
use App\Models\Isp;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceAnalyticsConsoleController extends Controller
{
    public const ACCESS_ROLES = ['owner', 'admin', 'operator', 'privacy_officer'];

    private const MAIN_DASHBOARD_CACHE_SECONDS = 600;

    public function warmMainDashboardCaches(User $user, string $workspaceId): bool
    {
        $workspace = $this->workspaceFor($user, $workspaceId);
        if (! in_array($workspace->pivot->role, self::ACCESS_ROLES, true)) {
            return false;
        }

        $today = now();
        $yearFrom = $today->copy()->startOfYear()->startOfDay();
        $to = $today->copy()->endOfDay();

        $this->dailyEvents($workspace, $user, $yearFrom, $to);

        return true;
    }

    public function index(Request $request, string $workspace): View
    {
        $workspace = $this->authorizedWorkspace($request->user(), $workspace);
        $analyticsPeriod = $this->dashboardAnalyticsPeriod($request);

        $today = now();
        $lastThirtyDaysFrom = $today->copy()->subDays(29)->startOfDay();
        $yearFrom = $today->copy()->startOfYear()->startOfDay();
        $monthFrom = $today->copy()->startOfMonth()->startOfDay();
        $yesterdayFrom = $today->copy()->subDay()->startOfDay();
        $eventFrom = $lastThirtyDaysFrom->lessThan($yearFrom) ? $lastThirtyDaysFrom : $yearFrom;

        $to = $today->copy()->endOfDay();
        $dailyEvents = $this->dailyEvents($workspace, $request->user(), $eventFrom, $to);
        $dailyByDate = $dailyEvents->keyBy('at');
        $dailyRows = collect(range(0, 29))->map(function (int $daysAgo) use ($lastThirtyDaysFrom, $dailyByDate): array {
            $at = $lastThirtyDaysFrom->copy()->addDays($daysAgo)->toDateString();
            $row = $dailyByDate->get($at);

            return [
                'at' => $at,
                'deliveries' => (int) ($row->deliveries ?? 0),
                'bounces' => (int) ($row->soft_bounces ?? 0) + (int) ($row->hard_bounces ?? 0),
                'opens' => (int) ($row->opens ?? 0),
                'clicks' => (int) ($row->clicks ?? 0),
                'leads' => (int) ($row->leads ?? 0),
                'unsubscribes' => (int) ($row->unsubscribes ?? 0),
                'lead_revenue' => (float) ($row->lead_revenue ?? 0),
            ];
        });

        $todayEvents = $dailyByDate->get($today->toDateString());
        $yesterdayEvents = $dailyByDate->get($yesterdayFrom->toDateString());
        $monthEvents = $dailyEvents->filter(fn (object $row): bool => $row->at >= $monthFrom->toDateString());

        /** AlphaLink calls the prior-day value YTD; its captured source query uses yesterday only. */
        $dashboardHighlights = [
            'deliveries' => [
                'today' => (int) ($todayEvents->deliveries ?? 0),
                'ytd' => (int) ($yesterdayEvents->deliveries ?? 0),
                'mtd' => $monthEvents->sum(fn (object $row): int => (int) ($row->deliveries ?? 0)),
                'soft_bounces' => (int) ($todayEvents->soft_bounces ?? 0),
                'hard_bounces' => (int) ($todayEvents->hard_bounces ?? 0),
            ],
            'opens' => [
                'today' => (int) ($todayEvents->opens ?? 0),
                'ytd' => (int) ($yesterdayEvents->opens ?? 0),
                'mtd' => $monthEvents->sum(fn (object $row): int => (int) $row->opens),
            ],
            'clicks' => [
                'today' => (int) ($todayEvents->clicks ?? 0),
                'ytd' => (int) ($yesterdayEvents->clicks ?? 0),
                'mtd' => $monthEvents->sum(fn (object $row): int => (int) $row->clicks),
            ],
            'revenue' => [
                'today' => (float) ($todayEvents->lead_revenue ?? 0),
                'ytd' => (float) ($yesterdayEvents->lead_revenue ?? 0),
                'mtd' => $monthEvents->sum(fn (object $row): float => (float) $row->lead_revenue),
            ],
        ];
        $eventTotals = [
            'opens' => $dailyRows->sum('opens'),
            'clicks' => $dailyRows->sum('clicks'),
            'leads' => $dailyRows->sum('leads'),
            'unsubscribes' => $dailyRows->sum('unsubscribes'),
            'lead_revenue' => $dailyRows->sum('lead_revenue'),
        ];
        $commandOsMetrics = [
            ['key' => 'deliveries', 'label' => 'Deliveries', 'value' => $dailyRows->sum('deliveries'), 'format' => 'number', 'detail' => 'Imported PMTA delivery records'],
            ['key' => 'opens', 'label' => 'Opens', 'value' => $eventTotals['opens'], 'format' => 'number', 'detail' => 'Campaign and Drop visitor events'],
            ['key' => 'clicks', 'label' => 'Clicks', 'value' => $eventTotals['clicks'], 'format' => 'number', 'detail' => 'Campaign and Drop visitor events'],
            ['key' => 'leads', 'label' => 'Leads', 'value' => $eventTotals['leads'], 'format' => 'number', 'detail' => 'Recorded campaign and Drop leads'],
            ['key' => 'revenue', 'label' => 'Revenue', 'value' => $eventTotals['lead_revenue'], 'format' => 'currency', 'detail' => 'Attributed lead revenue only'],
            ['key' => 'unsubscribes', 'label' => 'Unsubscribes', 'value' => $eventTotals['unsubscribes'], 'format' => 'number', 'detail' => 'Campaign and Drop visitor events'],
            ['key' => 'bounces', 'label' => 'Bounces', 'value' => $dailyRows->sum('bounces'), 'format' => 'number', 'detail' => 'Imported PMTA soft and hard bounces'],
        ];
        $commandOsDashboard = [
            'userName' => $request->user()->name ?: $request->user()->email,
            'workspaceName' => $workspace->name,
            'workspaceRole' => $workspace->pivot->role,
            'period' => [
                'label' => 'Last 30 days',
                'from' => $lastThirtyDaysFrom->toDateString(),
                'to' => $to->toDateString(),
            ],
            'metrics' => $commandOsMetrics,
            'trend' => [
                'labels' => $dailyRows->map(fn (array $row): string => Carbon::parse($row['at'])->format('M j'))->all(),
                'deliveries' => $dailyRows->pluck('deliveries')->all(),
                'opens' => $dailyRows->pluck('opens')->all(),
            ],
            'refresh' => [
                'url' => route('console.analytics.index', ['workspace' => $workspace->getKey(), 'fragment' => 1]),
                'interval' => 300000,
            ],
            'coverage' => [
                'deliveries' => 'Locally imported PMTA delivery records and stored campaign/Drop visitor events.',
                'revenue' => 'Revenue is attributed to recorded campaign and Drop leads; it is not PMTA earnings.',
                'geo' => 'GEO delivery feedback is grouped only where a campaign or single-country Drop target can be proven. Country feedback totals are not a complete sent denominator or delivery rate.',
                'graph' => 'The graph maps workspace modules and their stored activity. Spokes show module membership; they do not claim record-to-record relationships.',
            ],
            'actions' => $this->dashboardQuickActions($workspace),
            'analytics' => $this->dateFilteredDashboardAnalytics(
                $workspace,
                $request->user(),
                $analyticsPeriod['from'],
                $analyticsPeriod['to'],
            ) + [
                'period' => [
                    ...$analyticsPeriod,
                    'action' => route('console.analytics.index', ['workspace' => $workspace->getKey()]),
                ],
            ],
        ];
        $commandOsDashboard['refresh']['url'] = route('console.analytics.index', [
            'workspace' => $workspace->getKey(),
            'fragment' => 1,
            'analytics_period' => $analyticsPeriod['key'],
            'analytics_from' => $analyticsPeriod['key'] === 'custom' ? $analyticsPeriod['from'] : null,
            'analytics_to' => $analyticsPeriod['key'] === 'custom' ? $analyticsPeriod['to'] : null,
        ]);
        $maxDailyEvents = max(1, $dailyRows->max(fn (array $row): int => $row['opens'] + $row['clicks'] + $row['leads'] + $row['unsubscribes']));

        $mtdChartRows = collect(range(0, $today->copy()->endOfMonth()->day - 1))
            ->map(function (int $dayOffset) use ($monthFrom, $dailyByDate): array {
                $date = $monthFrom->copy()->addDays($dayOffset)->toDateString();
                $row = $dailyByDate->get($date);

                return [
                    'date' => $date,
                    'label' => Carbon::parse($date)->format('j'),
                    'deliveries' => (int) ($row->deliveries ?? 0),
                    'bounces' => (int) ($row->soft_bounces ?? 0) + (int) ($row->hard_bounces ?? 0),
                    'opens' => (int) ($row->opens ?? 0),
                    'clicks' => (int) ($row->clicks ?? 0),
                    'leads' => (int) ($row->leads ?? 0),
                    'unsubscribes' => (int) ($row->unsubscribes ?? 0),
                    'lead_revenue' => (float) ($row->lead_revenue ?? 0),
                ];
            });
        $yearChartRows = collect(range(0, 11))->map(function (int $monthOffset) use ($yearFrom, $dailyEvents): array {
            $month = $yearFrom->copy()->addMonths($monthOffset);
            $monthEnd = $month->copy()->endOfMonth()->toDateString();
            $monthRevenue = $dailyEvents
                ->filter(fn (object $row): bool => $row->at >= $month->toDateString() && $row->at <= $monthEnd)
                ->sum(fn (object $row): float => (float) $row->lead_revenue);

            return [
                'label' => $month->format('M'),
                'revenue' => (float) $monthRevenue,
            ];
        });
        $dashboardCharts = [
            'mtd_actions' => [
                'labels' => $mtdChartRows->pluck('label')->all(),
                'series' => [
                    ['label' => 'Deliveries', 'color' => '#17685f', 'values' => $mtdChartRows->pluck('deliveries')->all()],
                    ['label' => 'Bounces', 'color' => '#b84d35', 'values' => $mtdChartRows->pluck('bounces')->all()],
                    ['label' => 'Opens', 'color' => '#417da0', 'values' => $mtdChartRows->pluck('opens')->all()],
                    ['label' => 'Clicks', 'color' => '#b08b31', 'values' => $mtdChartRows->pluck('clicks')->all()],
                    ['label' => 'Unsubscribes', 'color' => '#76558f', 'values' => $mtdChartRows->pluck('unsubscribes')->all()],
                    ['label' => 'Leads (AlphaLink)', 'color' => '#687773', 'values' => $mtdChartRows->pluck('leads')->all()],
                ],
            ],
            'mtd_revenue' => [
                'labels' => $mtdChartRows->pluck('label')->all(),
                'series' => [[
                    'label' => 'Attributed lead revenue',
                    'color' => '#17685f',
                    'values' => $mtdChartRows->pluck('lead_revenue')->all(),
                ]],
            ],
            'ytd_revenue' => [
                'labels' => $yearChartRows->pluck('label')->all(),
                'series' => [[
                    'label' => 'Attributed lead revenue',
                    'color' => '#17685f',
                    'values' => $yearChartRows->pluck('revenue')->all(),
                ]],
            ],
        ];
        $commandOsDashboard['charts'] = [
            'mtd_actions' => [
                'labels' => array_slice($dashboardCharts['mtd_actions']['labels'], 0, $today->day),
                'series' => array_map(
                    fn (array $series): array => [...$series, 'values' => array_slice($series['values'], 0, $today->day)],
                    $dashboardCharts['mtd_actions']['series'],
                ),
            ],
            'mtd_revenue' => [
                'labels' => array_slice($dashboardCharts['mtd_revenue']['labels'], 0, $today->day),
                'series' => array_map(
                    fn (array $series): array => [...$series, 'values' => array_slice($series['values'], 0, $today->day)],
                    $dashboardCharts['mtd_revenue']['series'],
                ),
            ],
            'ytd_revenue' => [
                'labels' => array_slice($dashboardCharts['ytd_revenue']['labels'], 0, $today->month),
                'series' => array_map(
                    fn (array $series): array => [...$series, 'values' => array_slice($series['values'], 0, $today->month)],
                    $dashboardCharts['ytd_revenue']['series'],
                ),
            ],
        ];

        $audienceLists = $workspace->audienceLists()
            ->with('history')
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->whereJsonContains('allowed_users', (int) $request->user()->getKey()))
            ->orderBy('name')
            ->get(['id', 'name']);
        $snapshotCounts = array_fill_keys(['fresh', 'delivery', 'open', 'click', 'lead', 'unsubscribe'], 0);
        $hasAudienceSnapshots = false;

        foreach ($audienceLists as $audienceList) {
            $history = $audienceList->history;
            if (! $history->exists) {
                continue;
            }

            $hasAudienceSnapshots = true;
            foreach (array_keys($snapshotCounts) as $type) {
                $snapshotCounts[$type] += (int) $history->{$type};
            }
        }

        return view($request->boolean('fragment')
            ? 'console.analytics.partials.main-dashboard-content'
            : 'console.analytics.index', [
                'workspace' => $workspace,
                'dailyRows' => $dailyRows->reverse()->values(),
                'eventTotals' => $eventTotals,
                'dashboardCharts' => $dashboardCharts,
                'dashboardHighlights' => $dashboardHighlights,
                'commandOsDashboard' => $commandOsDashboard,
                'maxDailyEvents' => $maxDailyEvents,
                'snapshotCounts' => $snapshotCounts,
                'hasAudienceSnapshots' => $hasAudienceSnapshots,
                'from' => $lastThirtyDaysFrom,
                'to' => $to,
                'analyticsPeriod' => $analyticsPeriod,
            ]);
    }

    /**
     * @return array{key: string, label: string, from: string, to: string, maxDate: string}
     */
    private function dashboardAnalyticsPeriod(Request $request): array
    {
        $filters = $request->validate([
            'analytics_period' => ['nullable', 'in:today,yesterday,this_week,this_month,last_month,custom'],
            'analytics_from' => ['nullable', 'required_if:analytics_period,custom', 'date_format:Y-m-d', 'before_or_equal:today'],
            'analytics_to' => ['nullable', 'required_if:analytics_period,custom', 'date_format:Y-m-d', 'after_or_equal:analytics_from', 'before_or_equal:today'],
        ]);

        $today = now()->startOfDay();
        $key = $filters['analytics_period'] ?? 'this_month';
        [$from, $to] = match ($key) {
            'today' => [$today->copy(), $today->copy()->endOfDay()],
            'yesterday' => [$today->copy()->subDay()->startOfDay(), $today->copy()->subDay()->endOfDay()],
            'this_week' => [$today->copy()->startOfWeek(Carbon::MONDAY), $today->copy()->endOfDay()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'custom' => [Carbon::parse($filters['analytics_from'])->startOfDay(), Carbon::parse($filters['analytics_to'])->endOfDay()],
            default => [$today->copy()->startOfMonth(), $today->copy()->endOfDay()],
        };

        return [
            'key' => $key,
            'label' => match ($key) {
                'today' => 'Today',
                'yesterday' => 'Yesterday',
                'this_week' => 'This week',
                'this_month' => 'This month',
                'last_month' => 'Last month',
                default => 'Custom range',
            },
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'maxDate' => $today->toDateString(),
        ];
    }

    /**
     * @return array{
     *     totals: array{deliveries: int, opens: int, clicks: int, leads: int, unsubscribes: int, bounces: int, attributed_revenue: float},
     *     sponsors: list<array{label: string, leads: int, clicks: int, attributed_revenue: float}>,
     *     isps: list<array{label: string, leads: int, clicks: int, attributed_revenue: float}>,
     *     mailers: list<array{label: string, deliveries: int, opens: int, clicks: int, leads: int, unsubscribes: int, bounces: int, attributed_revenue: float}>,
     *     offers: list<array{label: string, leads: int, clicks: int, attributed_revenue: float}>,
     *     geo: array{countries: list<array{country: string, opens: int, clicks: int, leads: int, unsubscribes: int, deliveries: int, bounces: int}>, feedback_note: string},
     *     graph: array{nodes: list<array{id: string, label: string, count: int, color: string, href: string|null}>, note: string}
     * }
     */
    private function dateFilteredDashboardAnalytics(Workspace $workspace, User $user, string $from, string $to): array
    {
        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->endOfDay();
        $cacheKey = $this->mainDashboardCacheKey('attribution-analytics', $workspace, $user, $fromDate, $toDate);

        return Cache::remember(
            $cacheKey,
            self::MAIN_DASHBOARD_CACHE_SECONDS,
            fn (): array => $this->loadDateFilteredDashboardAnalytics($workspace, $user, $from, $to),
        );
    }

    /**
     * @return array{
     *     totals: array{deliveries: int, opens: int, clicks: int, leads: int, unsubscribes: int, bounces: int, attributed_revenue: float},
     *     sponsors: list<array{label: string, leads: int, clicks: int, attributed_revenue: float}>,
     *     isps: list<array{label: string, leads: int, clicks: int, attributed_revenue: float}>,
     *     mailers: list<array{label: string, deliveries: int, opens: int, clicks: int, leads: int, unsubscribes: int, bounces: int, attributed_revenue: float}>,
     *     offers: list<array{label: string, leads: int, clicks: int, attributed_revenue: float}>,
     *     geo: array{countries: list<array{country: string, opens: int, clicks: int, leads: int, unsubscribes: int, deliveries: int, bounces: int}>, feedback_note: string},
     *     graph: array{nodes: list<array{id: string, label: string, count: int, color: string, href: string|null}>, note: string}
     * }
     */
    private function loadDateFilteredDashboardAnalytics(Workspace $workspace, User $user, string $from, string $to): array
    {
        $rows = DB::table('workspace_daily_analytics')
            ->where('workspace_id', $workspace->getKey())
            ->where('attribution_scope', 'workspace')
            ->whereIn('source_type', ['campaign', 'drop'])
            ->whereBetween('at', [$from, $to])
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->where('user_id', $user->getKey()))
            ->select('sponsor_id', 'isp_id', 'offer_id', 'user_id')
            ->selectRaw('SUM(deliveries) AS deliveries, SUM(opens) AS opens, SUM(clicks) AS clicks')
            ->selectRaw('SUM(leads) AS leads, SUM(unsubscribes) AS unsubscribes, SUM(soft_bounces + hard_bounces) AS bounces')
            ->selectRaw('SUM(lead_revenue) AS attributed_revenue')
            ->groupBy('sponsor_id', 'isp_id', 'offer_id', 'user_id')
            ->get();

        $sponsorIds = $rows->pluck('sponsor_id')->filter()->unique()->values();
        $offerIds = $rows->pluck('offer_id')->filter()->unique()->values();
        $mailerIds = $rows->pluck('user_id')->filter()->unique()->values();
        $labels = [
            'sponsor_id' => $sponsorIds->isEmpty() ? collect() : DB::table('workspace_sponsors')
                ->where('workspace_id', $workspace->getKey())
                ->whereIn('id', $sponsorIds)
                ->pluck('name', 'id'),
            'isp_id' => DB::table('isps')
                ->where('workspace_id', $workspace->getKey())
                ->pluck('name', 'id'),
            'offer_id' => $offerIds->isEmpty() ? collect() : DB::table('workspace_offers')
                ->where('workspace_id', $workspace->getKey())
                ->whereIn('id', $offerIds)
                ->pluck('name', 'id'),
            'user_id' => $mailerIds->isEmpty() ? collect() : DB::table('users')
                ->whereIn('id', $mailerIds)
                ->selectRaw("id, COALESCE(name, username, 'Unknown') AS label")
                ->pluck('label', 'id'),
        ];

        $mapDimension = function (string $column, string $fallback, int $limit, array $fields) use ($rows, $labels): array {
            return $rows
                ->groupBy(fn (object $row): string => (string) ($row->{$column} ?? 'unknown'))
                ->map(function (Collection $dimensionRows, string $id) use ($column, $fallback, $fields, $labels): array {
                    $result = ['label' => $labels[$column][$id] ?? ($id === 'unknown' ? 'Unknown '.$fallback : $fallback.' #'.$id)];
                    foreach ($fields as $field) {
                        $result[$field] = $field === 'attributed_revenue'
                            ? round($dimensionRows->sum(fn (object $row): float => (float) $row->{$field}), 2)
                            : (int) $dimensionRows->sum(fn (object $row): int => (int) $row->{$field});
                    }

                    return $result;
                })
                ->filter(fn (array $row): bool => $row['attributed_revenue'] > 0)
                ->sort(fn (array $left, array $right): int => ($right['attributed_revenue'] <=> $left['attributed_revenue'])
                    ?: ($right['leads'] <=> $left['leads'])
                    ?: (($right['clicks'] ?? 0) <=> ($left['clicks'] ?? 0)))
                ->take($limit)
                ->values()
                ->all();
        };

        return [
            'totals' => [
                'deliveries' => (int) $rows->sum(fn (object $row): int => (int) $row->deliveries),
                'opens' => (int) $rows->sum(fn (object $row): int => (int) $row->opens),
                'clicks' => (int) $rows->sum(fn (object $row): int => (int) $row->clicks),
                'leads' => (int) $rows->sum(fn (object $row): int => (int) $row->leads),
                'unsubscribes' => (int) $rows->sum(fn (object $row): int => (int) $row->unsubscribes),
                'bounces' => (int) $rows->sum(fn (object $row): int => (int) $row->bounces),
                'attributed_revenue' => round($rows->sum(fn (object $row): float => (float) $row->attributed_revenue), 2),
            ],
            'sponsors' => $mapDimension('sponsor_id', 'sponsor', 5, ['leads', 'clicks', 'attributed_revenue']),
            'isps' => $mapDimension('isp_id', 'ISP', 5, ['leads', 'clicks', 'attributed_revenue']),
            'mailers' => $mapDimension('user_id', 'mailer', 5, ['deliveries', 'opens', 'clicks', 'leads', 'unsubscribes', 'bounces', 'attributed_revenue']),
            'offers' => $mapDimension('offer_id', 'offer', 10, ['leads', 'clicks', 'attributed_revenue']),
            'geo' => $this->dashboardGeoAnalytics($workspace, $user, $from, $to),
            'graph' => $this->dashboardMarketingGraph($workspace, $user, $from, $to),
        ];
    }

    /**
     * Build GEO engagement from country-tagged visitor events and delivery feedback only
     * where AlphaLink can tie a send snapshot to one country.
     *
     * @return array{countries: list<array{country: string, opens: int, clicks: int, leads: int, unsubscribes: int, deliveries: int, bounces: int}>, feedback_note: string}
     */
    private function dashboardGeoAnalytics(Workspace $workspace, User $user, string $from, string $to): array
    {
        $countries = [];
        $add = function (string $country, string $metric, int $count) use (&$countries): void {
            $country = trim($country);
            if ($country === '' || $country === '0' || $count < 1) {
                return;
            }
            $key = strtoupper($country);
            $countries[$key] ??= [
                'country' => $country,
                'opens' => 0,
                'clicks' => 0,
                'leads' => 0,
                'unsubscribes' => 0,
                'deliveries' => 0,
                'bounces' => 0,
            ];
            $countries[$key][$metric] += $count;
        };

        $campaignEvents = DB::table('campaign_engagement_events as e')
            ->join('campaigns as c', function ($join): void {
                $join->on('c.id', '=', 'e.campaign_id')->on('c.workspace_id', '=', 'e.workspace_id');
            })
            ->where('e.workspace_id', $workspace->getKey())
            ->whereBetween('e.created_at', [$from.' 00:00:00', $to.' 23:59:59.999999'])
            ->whereIn('e.action', ['open', 'click', 'unsubscribe'])
            ->whereNotNull('e.country_code')
            ->where('e.country_code', '<>', '')
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->where('c.created_by', $user->getKey()))
            ->select('e.country_code', 'e.action')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('e.country_code', 'e.action')
            ->get();
        foreach ($campaignEvents as $event) {
            $metric = match ($event->action) {
                'open' => 'opens',
                'click' => 'clicks',
                default => 'unsubscribes',
            };
            $add((string) $event->country_code, $metric, (int) $event->total);
        }

        $campaignLeads = DB::table('campaign_lead_conversions as l')
            ->join('campaigns as c', function ($join): void {
                $join->on('c.id', '=', 'l.campaign_id')->on('c.workspace_id', '=', 'l.workspace_id');
            })
            ->where('l.workspace_id', $workspace->getKey())
            ->whereBetween('l.converted_at', [$from.' 00:00:00', $to.' 23:59:59.999999'])
            ->whereNotNull('l.country')
            ->where('l.country', '<>', '')
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->where('c.created_by', $user->getKey()))
            ->select('l.country')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('l.country')
            ->get();
        foreach ($campaignLeads as $lead) {
            $add((string) $lead->country, 'leads', (int) $lead->total);
        }

        $dropEvents = DB::table('workspace_drop_visitors as v')
            ->join('workspace_drops as d', function ($join): void {
                $join->on('d.id', '=', 'v.workspace_drop_id')->on('d.workspace_id', '=', 'v.workspace_id');
            })
            ->where('v.workspace_id', $workspace->getKey())
            ->whereBetween('v.created_at', [$from.' 00:00:00', $to.' 23:59:59.999999'])
            ->whereNotNull('v.country')
            ->where('v.country', '<>', '')
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->where('d.user_id', $user->getKey()))
            ->select('v.country', 'v.type')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('v.country', 'v.type')
            ->get();
        foreach ($dropEvents as $event) {
            $metric = match ((string) $event->type) {
                'open' => 'opens',
                'click' => 'clicks',
                'unsubscribe' => 'unsubscribes',
                'lead', 'conversion' => 'leads',
                default => null,
            };
            if ($metric !== null) {
                $add((string) $event->country, $metric, (int) $event->total);
            }
        }

        $campaignFeedback = DB::table('workspace_pmta_feedback_events as f')
            ->join('campaigns as c', function ($join): void {
                $join->on('c.id', '=', 'f.campaign_id')->on('c.workspace_id', '=', 'f.workspace_id');
            })
            ->where('f.workspace_id', $workspace->getKey())
            ->where('f.source_type', 'campaign')
            ->whereBetween('f.at', [$from, $to])
            ->whereNotNull('f.snapshot_id')
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->where('c.created_by', $user->getKey()))
            ->select('f.snapshot_id', 'f.metric')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('f.snapshot_id', 'f.metric')
            ->get();
        $snapshotIds = $campaignFeedback->pluck('snapshot_id')->unique()->values();
        $snapshots = $snapshotIds->isEmpty() ? collect() : DB::table('campaign_recipient_snapshots')
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('id', $snapshotIds)
            ->get(['id', 'revision_id'])
            ->keyBy('id');
        $revisionIds = $snapshots->pluck('revision_id')->unique()->values();
        $revisions = $revisionIds->isEmpty() ? collect() : CampaignRevision::query()
            ->whereIn('id', $revisionIds)
            ->get(['id', 'payload'])
            ->keyBy('id');
        foreach ($campaignFeedback as $event) {
            $snapshot = $snapshots->get($event->snapshot_id);
            $revision = $snapshot ? $revisions->get($snapshot->revision_id) : null;
            $country = strtoupper(trim((string) data_get($revision?->payload, 'send_setup.country', '')));
            $metric = $this->dashboardFeedbackMetric((string) $event->metric);
            if ($metric !== null && preg_match('/^[A-Z]{2}$/', $country)) {
                $add($country, $metric, (int) $event->total);
            }
        }

        $dropFeedback = DB::table('workspace_pmta_feedback_events as f')
            ->join('workspace_drops as d', function ($join): void {
                $join->on('d.id', '=', 'f.workspace_drop_id')->on('d.workspace_id', '=', 'f.workspace_id');
            })
            ->where('f.workspace_id', $workspace->getKey())
            ->where('f.source_type', 'drop')
            ->whereBetween('f.at', [$from, $to])
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->where('d.user_id', $user->getKey()))
            ->select('f.workspace_drop_id', 'f.metric')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('f.workspace_drop_id', 'f.metric')
            ->get();
        $dropIds = $dropFeedback->pluck('workspace_drop_id')->unique()->values();
        $drops = $dropIds->isEmpty() ? collect() : DB::table('workspace_drops')
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('id', $dropIds)
            ->pluck('countries', 'id');
        foreach ($dropFeedback as $event) {
            $selectedCountries = $drops->get($event->workspace_drop_id);
            if (is_string($selectedCountries)) {
                $selectedCountries = json_decode($selectedCountries, true);
            }
            $selectedCountries = is_array($selectedCountries) ? array_values(array_unique(array_filter(array_map('strval', $selectedCountries)))) : [];
            $country = count($selectedCountries) === 1 ? trim($selectedCountries[0]) : '';
            $metric = $this->dashboardFeedbackMetric((string) $event->metric);
            if ($metric !== null && $country !== '' && ! in_array(strtoupper($country), ['ALL', 'MIX', 'MULTI'], true)) {
                $add($country, $metric, (int) $event->total);
            }
        }

        $rows = array_values($countries);
        usort($rows, fn (array $left, array $right): int => ($right['deliveries'] + $right['opens'] + $right['clicks'] + $right['leads']) <=> ($left['deliveries'] + $left['opens'] + $left['clicks'] + $left['leads']));

        return [
            'countries' => $rows,
            'feedback_note' => 'Delivery and bounce values are imported PMTA feedback tied to one send country. They are feedback totals only; a complete sent-recipient denominator is unavailable, so no delivery rate is shown. Engagement is observed campaign and Drop activity.',
        ];
    }

    private function dashboardFeedbackMetric(string $metric): ?string
    {
        return match ($metric) {
            'deliveries' => 'deliveries',
            'soft_bounces', 'hard_bounces' => 'bounces',
            default => null,
        };
    }

    /**
     * @return array{nodes: list<array{id: string, label: string, count: int, color: string, href: string|null}>, note: string}
     */
    private function dashboardMarketingGraph(Workspace $workspace, User $user, string $from, string $to): array
    {
        $role = (string) $workspace->pivot->role;
        $canUseCampaignOperations = in_array($role, ['owner', 'admin', 'operator'], true);
        $canManageSponsorsAndOffers = in_array($role, ['owner', 'admin'], true);
        $canManageInfrastructure = in_array($role, ['owner', 'admin'], true);
        $aggregate = DB::table('workspace_daily_analytics')
            ->where('workspace_id', $workspace->getKey())
            ->where('attribution_scope', 'workspace')
            ->whereBetween('at', [$from, $to])
            ->when($role === 'operator', fn ($query) => $query->where('user_id', $user->getKey()))
            ->selectRaw('COUNT(DISTINCT campaign_id) AS campaigns, COUNT(DISTINCT workspace_drop_id) AS drops')
            ->selectRaw("COUNT(DISTINCT CASE WHEN source_type = 'warmup' THEN source_id END) AS warmups")
            ->selectRaw('COUNT(DISTINCT server_id) AS servers, COUNT(DISTINCT domain_id) AS domains, COUNT(DISTINCT list_id) AS lists')
            ->selectRaw('COUNT(DISTINCT sponsor_id) AS sponsors, COUNT(DISTINCT offer_id) AS offers, COUNT(DISTINCT isp_id) AS isps')
            ->selectRaw('SUM(opens + clicks + leads + unsubscribes) AS tracked_events')
            ->first();

        $count = fn (string $key): int => (int) ($aggregate->{$key} ?? 0);
        $allNodes = [
            ['id' => 'campaigns', 'label' => 'Campaigns', 'count' => $count('campaigns'), 'color' => 'blue', 'href' => $canUseCampaignOperations ? route('console.mailer.campaigns') : null],
            ['id' => 'drops', 'label' => 'Drops', 'count' => $count('drops'), 'color' => 'violet', 'href' => $canUseCampaignOperations ? route('clean.console.drops.drafts.index') : null],
            ['id' => 'warmups', 'label' => 'Warmups', 'count' => $count('warmups'), 'color' => 'pink', 'href' => $canUseCampaignOperations ? route('clean.console.warmups.index') : null],
            ['id' => 'servers', 'label' => 'Servers', 'count' => $count('servers'), 'color' => 'cyan', 'href' => route('clean.console.infrastructure.servers.index')],
            ['id' => 'domains', 'label' => 'Domains', 'count' => $count('domains'), 'color' => 'blue', 'href' => $canManageInfrastructure ? route('clean.console.infrastructure.domains.index') : null],
            ['id' => 'lists', 'label' => 'Lists', 'count' => $count('lists'), 'color' => 'cyan', 'href' => $canManageInfrastructure ? route('clean.console.infrastructure.lists.index') : null],
            ['id' => 'offers', 'label' => 'Offers', 'count' => $count('offers'), 'color' => 'violet', 'href' => $canManageSponsorsAndOffers ? route('clean.console.offers.index') : null],
            ['id' => 'sponsors', 'label' => 'Sponsors', 'count' => $count('sponsors'), 'color' => 'pink', 'href' => $canManageSponsorsAndOffers ? route('clean.console.sponsors.index') : null],
            ['id' => 'isps', 'label' => 'ISPs', 'count' => $count('isps'), 'color' => 'blue', 'href' => $canManageInfrastructure ? route('clean.console.infrastructure.isps.index') : null],
            ['id' => 'tracking', 'label' => 'Tracking', 'count' => $count('tracked_events'), 'color' => 'indigo', 'href' => route('clean.console.analytics.all-stats')],
        ];

        return [
            'nodes' => $role === 'privacy_officer' ? [['id' => 'workspace-activity', 'label' => 'Workspace activity', 'count' => $count('tracked_events'), 'color' => 'indigo', 'href' => null]] : $allNodes,
            'note' => 'Badges show workspace-scoped entities with activity in this range; Tracking shows event volume. Lines radiate from AlphaLink as a module map and do not claim record-to-record attribution.',
        ];
    }

    /** @return array<int, array{label: string, href: string|null, icon: string, disabled?: bool, reason?: string}> */
    private function dashboardQuickActions(Workspace $workspace): array
    {
        $role = (string) $workspace->pivot->role;
        $campaignRoles = ['owner', 'admin', 'operator'];
        $actions = [];

        if (in_array($role, $campaignRoles, true)) {
            $actions[] = [
                'label' => 'Create Campaign',
                'href' => route('console.campaigns.create', $workspace->getKey()),
                'icon' => 'send',
            ];
            $actions[] = [
                'label' => 'New Drop',
                'href' => route('console.drops.drafts.create', $workspace->getKey()),
                'icon' => 'layers',
            ];
            $actions[] = [
                'label' => 'Start Warmup',
                'href' => route('console.warmups.create', $workspace->getKey()),
                'icon' => 'flame',
            ];
        }

        if (in_array($role, ['owner', 'admin'], true)) {
            $actions[] = [
                'label' => 'Upload List',
                'href' => route('console.infrastructure.lists.index', $workspace->getKey()),
                'icon' => 'upload',
            ];
        }

        if (in_array($role, $campaignRoles, true)) {
            $actions[] = [
                'label' => 'Check Deliverability',
                'href' => route('console.global-tests.index', $workspace->getKey()),
                'icon' => 'shield',
            ];
        }

        $actions[] = [
            'label' => 'Track a Link',
            'href' => null,
            'icon' => 'link',
            'disabled' => true,
            'reason' => 'No safe link-tracking page is available yet.',
        ];

        return $actions;
    }

    public function allStats(Request $request, string $workspace): View|JsonResponse
    {
        [$workspace, $from, $to] = $this->reportContext($request, $workspace);
        $aggregateRows = DB::table('workspace_daily_analytics as a')
            ->leftJoin('campaigns as c', function ($join): void {
                $join->on('c.id', '=', 'a.campaign_id')->on('c.workspace_id', '=', 'a.workspace_id');
            })
            ->leftJoin('campaigns as warmup', function ($join): void {
                $join->on(DB::raw('CAST(warmup.id AS TEXT)'), '=', 'a.source_id')
                    ->on('warmup.workspace_id', '=', 'a.workspace_id')
                    ->where('warmup.kind', '=', 'warmup')
                    ->where('a.source_type', '=', 'warmup');
            })
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.workspace_id', $workspace->getKey())
            ->whereBetween('a.at', [$from->toDateString(), $to->toDateString()])
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->where('a.source_type', 'campaign')->where('a.attribution_scope', 'list');
                })->orWhere(function ($query): void {
                    $query->whereIn('a.source_type', ['drop', 'warmup'])->where('a.attribution_scope', 'workspace');
                });
            })
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->where('a.user_id', $request->user()->getKey()))
            ->select([
                'a.at', 'a.source_type', 'a.source_id', 'a.user_id', 'a.isp_id', 'a.list_id', 'a.list_type',
                'a.server_id', 'a.ip_id', 'a.domain_id', 'a.sponsor_id', 'a.offer_id', 'a.from_id', 'a.subject_id',
                'a.creative_id', 'a.opens', 'a.clicks', 'a.leads', 'a.unsubscribes', 'a.bots',
                'a.deliveries', 'a.soft_bounces', 'a.hard_bounces', 'a.lead_revenue',
                DB::raw('COALESCE(c.name, warmup.name) AS campaign_name'),
            ])
            ->selectRaw("COALESCE(u.name, u.username, 'Unknown') AS mailer")
            ->get();

        $rawRows = $aggregateRows->flatMap(function (object $row): Collection {
            $source = match ($row->source_type) {
                'drop' => 'Drop #'.$row->source_id,
                'warmup' => 'Seed '.($row->campaign_name ?: '#'.$row->source_id),
                default => 'Campaign '.($row->campaign_name ?: '#'.$row->source_id),
            };
            $dimensions = [
                'server_id' => $row->server_id,
                'ip_id' => $row->ip_id,
                'domain_id' => $row->domain_id,
                'from_id' => $row->from_id,
                'subject_id' => $row->subject_id,
                'creative_id' => $row->creative_id,
            ];
            $rows = [];
            foreach ([
                'delivery' => ['deliveries', 0.0],
                'soft_bounce' => ['soft_bounces', 0.0],
                'hard_bounce' => ['hard_bounces', 0.0],
                'open' => ['opens', 0.0],
                'click' => ['clicks', 0.0],
                'lead' => ['leads', (float) $row->lead_revenue],
                'unsubscribe' => ['unsubscribes', 0.0],
                'bot' => ['bots', 0.0],
            ] as $type => [$column, $revenue]) {
                $count = (int) $row->{$column};
                if ($count === 0) {
                    continue;
                }

                $rows[] = (object) [
                    'at' => (string) $row->at,
                    'source' => $source,
                    'user_id' => (string) ($row->user_id ?? ''),
                    'mailer' => $row->mailer,
                    'isp_id' => $row->isp_id,
                    'list_id' => $row->list_id,
                    'list_type' => $type === 'lead' ? 'lead' : $row->list_type,
                    'dimension_ids' => $dimensions,
                    'sponsor_id' => $row->sponsor_id,
                    'offer_id' => $row->offer_id,
                    'type' => $type,
                    'event_count' => $count,
                    'lead_revenue' => $revenue,
                ];
            }

            return collect($rows);
        })->values();

        $decodeDimensions = fn (mixed $dimensions): array => is_array($dimensions)
            ? $dimensions
            : (json_decode((string) $dimensions, true) ?: []);
        $dimensionIds = $rawRows->map(fn (object $row): array => $decodeDimensions($row->dimension_ids))->all();
        $idsFor = fn (string $key): Collection => collect($dimensionIds)
            ->pluck($key)
            ->merge($rawRows->pluck($key))
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '' && (string) $value !== '0' && (string) $value !== 'NA')
            ->map(fn (mixed $value): string => (string) $value)
            ->unique()
            ->values();
        $workspaceLabels = fn (string $table, Collection $ids, string $label = 'name'): Collection => $ids->isEmpty()
            ? collect()
            : DB::table($table)->where('workspace_id', $workspace->getKey())->whereIn('id', $ids)->pluck($label, 'id');

        $isps = $workspaceLabels('isps', $idsFor('isp_id'));
        $lists = $workspaceLabels('audience_lists', $idsFor('list_id'));
        $sponsors = $workspaceLabels('workspace_sponsors', $idsFor('sponsor_id'));
        $offers = $idsFor('offer_id')->isEmpty()
            ? collect()
            : DB::table('workspace_offers')->where('workspace_id', $workspace->getKey())
                ->whereIn('id', $idsFor('offer_id'))->get(['id', 'name', 'vertical'])->keyBy('id');
        $servers = $idsFor('server_id')->isEmpty()
            ? collect()
            : DB::table('managed_servers')->where('workspace_id', $workspace->getKey())
                ->whereIn('id', $idsFor('server_id'))->get(['id', 'name', 'provider', 'ip_address'])->keyBy('id');
        $ips = $workspaceLabels('managed_server_ips', $idsFor('ip_id'), 'ip');
        $domains = $workspaceLabels('sending_domains', $idsFor('domain_id')->filter(
            fn (string $id): bool => Str::isUuid($id),
        )->values());
        $assets = $workspaceLabels('workspace_offer_assets', $idsFor('from_id')->merge($idsFor('subject_id'))->merge($idsFor('creative_id'))->unique(), 'value');
        $rows = $rawRows->map(function (object $row) use ($decodeDimensions, $isps, $lists, $sponsors, $offers, $servers, $ips, $domains, $assets): array {
            $dimensions = $decodeDimensions($row->dimension_ids);
            $serverId = (string) ($dimensions['server_id'] ?? '');
            $ipId = (string) ($dimensions['ip_id'] ?? '');
            $domainId = (string) ($dimensions['domain_id'] ?? '');
            $fromId = (string) ($dimensions['from_id'] ?? '');
            $subjectId = (string) ($dimensions['subject_id'] ?? '');
            $creativeId = (string) ($dimensions['creative_id'] ?? '');
            $offer = $offers[(string) ($row->offer_id ?? '')] ?? null;

            return [
                'at' => (string) $row->at,
                'source' => $row->source,
                'mailer' => $row->mailer,
                'isp' => $isps[(string) ($row->isp_id ?? '')] ?? 'NA',
                'list' => $lists[(string) ($row->list_id ?? '')] ?? 'NA',
                'list_type' => $row->list_type ?: 'NA',
                'provider' => $servers[$serverId]->provider ?? 'NA',
                'server' => $servers[$serverId]->name ?? 'NA',
                'ip' => $ips[$ipId] ?? 'NA',
                'domain' => $domains[$domainId] ?? 'NA',
                'sponsor' => $sponsors[(string) ($row->sponsor_id ?? '')] ?? 'NA',
                'offer' => $offer->name ?? 'NA',
                'vertical' => $offer->vertical ?? 'NA',
                'from' => $assets[$fromId] ?? 'NA',
                'subject' => $assets[$subjectId] ?? 'NA',
                'creative' => $assets[$creativeId] ?? 'NA',
                'type' => $row->type,
                'event_count' => (int) $row->event_count,
                'lead_revenue' => (float) $row->lead_revenue,
            ];
        })->sortBy([
            ['at', 'desc'],
            ['source', 'asc'],
            ['type', 'asc'],
        ])->values();

        if ($request->expectsJson()) {
            return response()->json($rows->map(fn (array $row): array => [
                'at' => $row['at'],
                'user' => $row['mailer'],
                'isp' => $row['isp'],
                'list' => $row['list'],
                'list_type' => $row['list_type'],
                'provider' => $row['provider'],
                'server' => $row['server'],
                'ip' => $row['ip'],
                'domain' => $row['domain'],
                'sponsor' => $row['sponsor'],
                'offer' => $row['offer'],
                'vertical' => $row['vertical'],
                'from' => $row['from'],
                'subject' => $row['subject'],
                'creative' => $row['creative'],
                'opens' => $row['type'] === 'open' ? $row['event_count'] : 0,
                'clicks' => $row['type'] === 'click' ? $row['event_count'] : 0,
                'leads' => $row['type'] === 'lead' ? $row['event_count'] : 0,
                'unsubscribes' => $row['type'] === 'unsubscribe' ? $row['event_count'] : 0,
                'bots' => $row['type'] === 'bot' ? $row['event_count'] : 0,
                'deliveries' => $row['type'] === 'delivery' ? $row['event_count'] : 0,
                'soft_bounces' => $row['type'] === 'soft_bounce' ? $row['event_count'] : 0,
                'hard_bounces' => $row['type'] === 'hard_bounce' ? $row['event_count'] : 0,
                'lead_revenue' => $row['lead_revenue'],
            ])->all());
        }

        return view('console.analytics.report', [
            'workspace' => $workspace,
            'kind' => 'all-stats',
            'title' => 'ALL STATS',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $this->paginateRows($request, $rows),
            'headers' => ['DATE', 'CAMPAIGN / DROP', 'MAILER', 'ISP', 'LIST', 'LIST TYPE', 'PROVIDER', 'SERVER', 'IP', 'DOMAIN', 'SPONSOR', 'OFFER', 'VERTICAL', 'FROM', 'SUBJECT', 'CREATIVE', 'EVENT', 'COUNT', 'LEAD REVENUE'],
            'coverage' => 'Campaign opens, clicks, and unsubscribes are attributed to every selected eligible source List, so totals across Lists can exceed unique events; workspace Dashboard, ISP, and Mailer totals count each event once. Rows also include Drop engagement, attributed leads, imported PMTA delivery/bounce feedback, and matching warmup/seed rows. Host ingestion is scheduled for verified servers but has only synthetic SFTP coverage; legacy seed aggregation, opt-out, aggregate-rate and shared-statistics compatibility remain unverified.',
        ]);
    }

    public function ispStats(Request $request, string $workspace): View
    {
        [$workspace, $from, $to] = $this->reportContext($request, $workspace);
        $filters = $request->validate([
            'isp_id' => ['nullable', 'integer'],
            'groupby' => ['nullable', 'in:day,week,month'],
        ]);
        $groupBy = $filters['groupby'] ?? 'day';
        $isps = Isp::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->whereJsonContains('allowed_users', (int) $request->user()->getKey()))
            ->orderBy('name')
            ->get(['id', 'name']);
        abort_if(isset($filters['isp_id']) && ! $isps->contains('id', (int) $filters['isp_id']), 404);

        $daily = DB::table('workspace_daily_analytics')
            ->where('workspace_id', $workspace->getKey())
            ->where('attribution_scope', 'workspace')
            ->whereBetween('at', [$from->toDateString(), $to->toDateString()])
            ->whereIn('source_type', ['campaign', 'drop'])
            ->whereNotNull('isp_id')
            ->whereIn('isp_id', $isps->pluck('id'))
            ->when(isset($filters['isp_id']), fn ($query) => $query->where('isp_id', (int) $filters['isp_id']))
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->where('user_id', $request->user()->getKey()))
            ->selectRaw('isp_id, at AS event_date, SUM(deliveries) AS deliveries, SUM(opens) AS opens, SUM(clicks) AS clicks')
            ->selectRaw('SUM(leads) AS leads, SUM(unsubscribes) AS unsubscribes, SUM(lead_revenue) AS lead_revenue')
            ->groupBy('isp_id', 'at')
            ->orderBy('event_date')
            ->get();
        $ispNames = $isps->pluck('name', 'id');
        $rows = $daily->groupBy(function (object $row) use ($groupBy): string {
            $date = Carbon::parse($row->event_date);
            $period = match ($groupBy) {
                'week' => $date->startOfWeek()->toDateString(),
                'month' => $date->startOfMonth()->toDateString(),
                default => $date->toDateString(),
            };

            return $row->isp_id.'|'.$period;
        })->map(function (Collection $periodRows, string $key) use ($ispNames): array {
            [$ispId, $period] = explode('|', $key, 2);

            return [
                'period' => $period,
                'isp' => $ispNames[$ispId] ?? 'Unknown',
                'deliveries' => $periodRows->sum(fn (object $row): int => (int) $row->deliveries),
                'opens' => $periodRows->sum(fn (object $row): int => (int) $row->opens),
                'clicks' => $periodRows->sum(fn (object $row): int => (int) $row->clicks),
                'leads' => $periodRows->sum(fn (object $row): int => (int) $row->leads),
                'unsubscribes' => $periodRows->sum(fn (object $row): int => (int) $row->unsubscribes),
                'lead_revenue' => $periodRows->sum(fn (object $row): float => (float) $row->lead_revenue),
            ];
        })->values();

        $mailerDaily = DB::table('workspace_daily_analytics as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.workspace_id', $workspace->getKey())
            ->where('a.attribution_scope', 'workspace')
            ->whereBetween('a.at', [$from->toDateString(), $to->toDateString()])
            ->whereIn('a.source_type', ['campaign', 'drop'])
            ->whereNotNull('a.isp_id')
            ->whereIn('a.isp_id', $isps->pluck('id'))
            ->when(isset($filters['isp_id']), fn ($query) => $query->where('a.isp_id', (int) $filters['isp_id']))
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->where('a.user_id', $request->user()->getKey()))
            ->select('a.user_id', 'a.at as event_date')
            ->selectRaw("COALESCE(NULLIF(u.name, ''), 'Unknown') AS mailer")
            ->selectRaw('SUM(a.deliveries) AS deliveries, SUM(a.opens) AS opens, SUM(a.clicks) AS clicks, SUM(a.leads) AS leads')
            ->selectRaw('SUM(a.unsubscribes) AS unsubscribes, SUM(a.lead_revenue) AS lead_revenue')
            ->groupBy('a.user_id', 'a.at', 'u.name')
            ->orderBy('event_date')
            ->get();

        $periodKey = function (string $eventDate) use ($groupBy): string {
            $date = Carbon::parse($eventDate);

            return match ($groupBy) {
                'week' => $date->format('o-W'),
                'month' => $date->format('Y-m'),
                default => $date->toDateString(),
            };
        };
        $chartLabels = collect();
        $chartDate = $from->copy()->startOfDay();
        $chartEnd = $to->copy()->startOfDay();
        if ($groupBy === 'month') {
            $chartDate->startOfMonth();
        }
        while ($chartDate->lte($chartEnd)) {
            $label = $periodKey($chartDate->toDateString());
            if (! $chartLabels->contains($label)) {
                $chartLabels->push($label);
            }

            $chartDate = match ($groupBy) {
                'week' => $chartDate->addWeek(),
                'month' => $chartDate->addMonth(),
                default => $chartDate->addDay(),
            };
        }
        $chartMetrics = ['deliveries', 'opens', 'clicks', 'leads', 'unsubscribes', 'lead_revenue'];
        $chartTotals = $mailerDaily->groupBy(fn (object $row): string => $periodKey((string) $row->event_date))
            ->map(fn (Collection $periodRows): array => [
                'deliveries' => $periodRows->sum(fn (object $row): int => (int) $row->deliveries),
                'opens' => $periodRows->sum(fn (object $row): int => (int) $row->opens),
                'clicks' => $periodRows->sum(fn (object $row): int => (int) $row->clicks),
                'leads' => $periodRows->sum(fn (object $row): int => (int) $row->leads),
                'unsubscribes' => $periodRows->sum(fn (object $row): int => (int) $row->unsubscribes),
                'lead_revenue' => $periodRows->sum(fn (object $row): float => (float) $row->lead_revenue),
            ]);
        $chartMailers = $mailerDaily->groupBy(fn (object $row): string => (string) ($row->user_id ?? 'unknown'))
            ->map(function (Collection $mailerRows) use ($chartLabels, $chartMetrics, $periodKey): array {
                $periodRows = $mailerRows->groupBy(fn (object $row): string => $periodKey((string) $row->event_date));
                $values = [];

                foreach ($chartMetrics as $metric) {
                    $values[$metric] = $chartLabels->map(function (string $label) use ($metric, $periodRows): int|float {
                        $rows = $periodRows->get($label, collect());
                        $cast = $metric === 'lead_revenue' ? 'float' : 'int';

                        return $rows->sum(fn (object $row): int|float => $cast === 'float'
                            ? (float) $row->{$metric}
                            : (int) $row->{$metric});
                    })->all();
                }

                return [
                    'label' => (string) $mailerRows->first()->mailer,
                    'values' => $values,
                ];
            })
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
        $chartData = [
            'labels' => $chartLabels->all(),
            'totals' => $chartLabels->map(fn (string $label): array => $chartTotals->get($label, array_fill_keys($chartMetrics, 0)))->all(),
            'mailers' => $chartMailers->all(),
        ];

        $viewData = [
            'workspace' => $workspace,
            'kind' => 'isp-stats',
            'title' => 'ISP STATS',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $this->paginateRows($request, $rows),
            'isps' => $isps,
            'selectedIspId' => $filters['isp_id'] ?? '',
            'groupBy' => $groupBy,
            'chartData' => $chartData,
            'headers' => ['PERIOD', 'ISP', 'DELIVERIES', 'OPENS', 'CLICKS', 'LEADS', 'UNSUBSCRIBES', 'LEAD REVENUE'],
            'coverage' => 'Delivery counts use imported PMTA feedback for campaign and Drop rows. Open-rate and click-rate formulas, legacy bounce measures, and earnings remain unavailable; attributed lead revenue is not equivalent to the legacy earnings measure.',
        ];

        if ($request->boolean('fragment')) {
            return view('console.analytics.partials.report-results', $viewData);
        }

        return view('console.analytics.report', $viewData);
    }

    public function mailerRevenue(Request $request, string $workspace): View|JsonResponse
    {
        [$workspace, $from, $to] = $this->reportContext($request, $workspace);
        $rows = DB::table('workspace_daily_analytics as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.workspace_id', $workspace->getKey())
            ->where('a.attribution_scope', 'workspace')
            ->whereBetween('a.at', [$from->toDateString(), $to->toDateString()])
            ->whereIn('a.source_type', ['campaign', 'drop'])
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->where('a.user_id', $request->user()->getKey()))
            ->select('a.user_id')
            ->selectRaw("COALESCE(u.name, u.username, 'Unknown') AS mailer")
            ->selectRaw('SUM(a.opens) AS opens, SUM(a.clicks) AS clicks, SUM(a.leads) AS leads')
            ->selectRaw('SUM(a.unsubscribes) AS unsubscribes, SUM(a.bots) AS bots, SUM(a.lead_revenue) AS lead_revenue')
            ->selectRaw('SUM(a.deliveries) AS deliveries, SUM(a.soft_bounces) AS soft_bounces, SUM(a.hard_bounces) AS hard_bounces')
            ->groupBy('a.user_id', 'u.name', 'u.username')
            ->orderByDesc('lead_revenue')
            ->get()
            ->map(fn (object $row): array => [
                'user_id' => (string) ($row->user_id ?? ''),
                'user' => $row->mailer,
                'mailer' => $row->mailer,
                'opens' => (int) $row->opens,
                'clicks' => (int) $row->clicks,
                'leads' => (int) $row->leads,
                'deliveries' => (int) $row->deliveries,
                'soft_bounces' => (int) $row->soft_bounces,
                'hard_bounces' => (int) $row->hard_bounces,
                'unsubscribes' => (int) $row->unsubscribes,
                'bots' => (int) $row->bots,
                'lead_revenue' => (float) $row->lead_revenue,
            ])
            ->sortByDesc('lead_revenue')
            ->values();

        if ($request->expectsJson()) {
            return response()->json($rows->map(fn (array $row): array => [
                'user' => $row['user'],
                'opens' => $row['opens'],
                'clicks' => $row['clicks'],
                'leads' => $row['leads'],
                'deliveries' => $row['deliveries'],
                'soft_bounces' => $row['soft_bounces'],
                'hard_bounces' => $row['hard_bounces'],
                'unsubscribes' => $row['unsubscribes'],
                'bots' => $row['bots'],
                'lead_revenue' => $row['lead_revenue'],
            ])->values());
        }

        $viewData = [
            'workspace' => $workspace,
            'kind' => 'mailer-revenue',
            'title' => 'MAILERS REV STATS',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $this->paginateRows($request, $rows),
            'headers' => ['MAILER', 'DELIVERIES', 'SOFT BOUNCES', 'HARD BOUNCES', 'OPENS', 'CLICKS', 'LEADS', 'UNSUBSCRIBES', 'BOTS', 'LEAD REVENUE'],
            'coverage' => 'Imported PMTA delivery and bounce counts are grouped by owner with campaign and Drop engagement. Attributed lead revenue is a local proxy for earnings; opt-outs and delivery-based rates are not available.',
        ];

        if ($request->boolean('fragment')) {
            return view('console.analytics.partials.report-results', $viewData);
        }

        return view('console.analytics.report', $viewData);
    }

    /**
     * @return Collection<int, object>
     */
    private function dailyEvents(Workspace $workspace, User $user, Carbon $from, Carbon $to): Collection
    {
        $cacheKey = $this->mainDashboardCacheKey('daily-events', $workspace, $user, $from, $to);
        $rows = Cache::remember(
            $cacheKey,
            self::MAIN_DASHBOARD_CACHE_SECONDS,
            fn (): array => $this->loadDailyEvents($workspace, $user, $from, $to)
                ->map(fn (object $row): array => get_object_vars($row))
                ->all(),
        );

        return collect($rows)->map(fn (array $row): object => (object) $row);
    }

    /**
     * @return Collection<int, object>
     */
    private function loadDailyEvents(Workspace $workspace, User $user, Carbon $from, Carbon $to): Collection
    {
        return DB::table('workspace_daily_analytics')
            ->where('workspace_id', $workspace->getKey())
            ->where('attribution_scope', 'workspace')
            ->whereBetween('at', [$from->toDateString(), $to->toDateString()])
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->where('user_id', $user->getKey()))
            ->select('at')
            ->selectRaw('SUM(opens) AS opens, SUM(clicks) AS clicks, SUM(leads) AS leads')
            ->selectRaw('SUM(unsubscribes) AS unsubscribes, SUM(lead_revenue) AS lead_revenue')
            ->selectRaw('SUM(deliveries) AS deliveries, SUM(soft_bounces) AS soft_bounces, SUM(hard_bounces) AS hard_bounces')
            ->groupBy('at')
            ->orderBy('at')
            ->get();
    }

    private function mainDashboardCacheKey(string $dataset, Workspace $workspace, User $user, Carbon $from, Carbon $to): string
    {
        $scope = implode(':', [
            $workspace->getKey(),
            $user->getKey(),
            $workspace->pivot->role,
            $from->toIso8601String(),
            $to->toIso8601String(),
            now()->format('YmdHi'),
        ]);

        return 'alphalink:dashboard:main:v2:'.$dataset.':'.hash('sha256', $scope);
    }

    private function reportContext(Request $request, string $workspaceId): array
    {
        $workspace = $this->authorizedWorkspace($request->user(), $workspaceId);
        $dates = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $today = now();
        $from = Carbon::parse($dates['from'] ?? $today->toDateString())->startOfDay();
        $to = Carbon::parse($dates['to'] ?? $today->toDateString())->endOfDay();

        return [$workspace, $from, $to];
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function paginateRows(Request $request, Collection $rows): LengthAwarePaginator
    {
        $perPage = 50;
        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    private function authorizedWorkspace(User $user, string $workspaceId): Workspace
    {
        $workspace = $this->workspaceFor($user, $workspaceId);
        abort_unless(in_array($workspace->pivot->role, self::ACCESS_ROLES, true), 403);

        return $workspace;
    }

    private function workspaceFor(User $user, string $workspaceId): Workspace
    {
        return $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
    }
}
