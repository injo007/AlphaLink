<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
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
        $monthFrom = $today->copy()->startOfMonth()->startOfDay();
        $to = $today->copy()->endOfDay();

        $this->dailyEvents($workspace, $user, $yearFrom, $to);
        $this->mtdLeadRevenueBreakdowns($workspace, $user, $monthFrom, $today->copy()->startOfDay(), $to);

        return true;
    }

    public function index(Request $request, string $workspace): View
    {
        $workspace = $this->authorizedWorkspace($request->user(), $workspace);

        $today = now();
        $lastThirtyDaysFrom = $today->copy()->subDays(29)->startOfDay();
        $yearFrom = $today->copy()->startOfYear()->startOfDay();
        $monthFrom = $today->copy()->startOfMonth()->startOfDay();
        $yesterdayFrom = $today->copy()->subDay()->startOfDay();
        $eventFrom = $yearFrom;

        $to = $today->copy()->endOfDay();
        $dailyEvents = $this->dailyEvents($workspace, $request->user(), $eventFrom, $to);
        $dailyByDate = $dailyEvents->keyBy('at');
        $dailyRows = collect(range(0, 29))->map(function (int $daysAgo) use ($lastThirtyDaysFrom, $dailyByDate): array {
            $at = $lastThirtyDaysFrom->copy()->addDays($daysAgo)->toDateString();
            $row = $dailyByDate->get($at);

            return [
                'at' => $at,
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

        /** DMS calls the prior-day value YTD; its captured source query uses yesterday only. */
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
        $mtdLeadRevenue = $this->mtdLeadRevenueBreakdowns(
            $workspace,
            $request->user(),
            $monthFrom,
            $today->copy()->startOfDay(),
            $to,
        );

        $eventTotals = [
            'opens' => $dailyRows->sum('opens'),
            'clicks' => $dailyRows->sum('clicks'),
            'leads' => $dailyRows->sum('leads'),
            'unsubscribes' => $dailyRows->sum('unsubscribes'),
            'lead_revenue' => $dailyRows->sum('lead_revenue'),
        ];
        $maxDailyEvents = max(1, $dailyRows->max(fn (array $row): int => $row['opens'] + $row['clicks'] + $row['leads'] + $row['unsubscribes']));

        $mtdChartRows = collect(range(0, $today->copy()->endOfMonth()->day - 1))
            ->map(function (int $dayOffset) use ($monthFrom, $dailyByDate): array {
                $date = $monthFrom->copy()->addDays($dayOffset)->toDateString();
                $row = $dailyByDate->get($date);

                return [
                    'date' => $date,
                    'label' => Carbon::parse($date)->format('j'),
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
                    ['label' => 'Opens', 'color' => '#17685f', 'values' => $mtdChartRows->pluck('opens')->all()],
                    ['label' => 'Clicks', 'color' => '#417da0', 'values' => $mtdChartRows->pluck('clicks')->all()],
                    ['label' => 'Leads', 'color' => '#b08b31', 'values' => $mtdChartRows->pluck('leads')->all()],
                    ['label' => 'Unsubscribes', 'color' => '#b84d35', 'values' => $mtdChartRows->pluck('unsubscribes')->all()],
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
                'mtdLeadRevenue' => $mtdLeadRevenue,
                'maxDailyEvents' => $maxDailyEvents,
                'snapshotCounts' => $snapshotCounts,
                'hasAudienceSnapshots' => $hasAudienceSnapshots,
                'from' => $lastThirtyDaysFrom,
                'to' => $to,
            ]);
    }

    public function allStats(Request $request, string $workspace): View|JsonResponse
    {
        [$workspace, $from, $to] = $this->reportContext($request, $workspace);
        $aggregateRows = DB::table('workspace_daily_analytics as a')
            ->leftJoin('campaigns as c', function ($join): void {
                $join->on('c.id', '=', 'a.campaign_id')->on('c.workspace_id', '=', 'a.workspace_id');
            })
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.workspace_id', $workspace->getKey())
            ->whereBetween('a.at', [$from->toDateString(), $to->toDateString()])
            ->whereIn('a.source_type', ['campaign', 'drop'])
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->where('a.user_id', $request->user()->getKey()))
            ->select([
                'a.at', 'a.source_type', 'a.source_id', 'a.user_id', 'a.isp_id', 'a.list_id', 'a.list_type',
                'a.server_id', 'a.ip_id', 'a.domain_id', 'a.sponsor_id', 'a.offer_id', 'a.from_id', 'a.subject_id',
                'a.creative_id', 'a.opens', 'a.clicks', 'a.leads', 'a.unsubscribes', 'a.bots',
                'a.deliveries', 'a.soft_bounces', 'a.hard_bounces', 'a.lead_revenue',
                'c.name as campaign_name',
            ])
            ->selectRaw("COALESCE(u.name, u.username, 'Unknown') AS mailer")
            ->get();

        $rawRows = $aggregateRows->flatMap(function (object $row): Collection {
            $source = $row->source_type === 'drop'
                ? 'Drop #'.$row->source_id
                : 'Campaign '.($row->campaign_name ?: '#'.$row->source_id);
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
        $domains = $workspaceLabels('sending_domains', $idsFor('domain_id'));
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
            'coverage' => 'Rows include stored campaign/Drop engagement, attributed leads and locally imported PMTA delivery/bounce feedback. Automatic host-file ingestion, opt-out, seed, aggregate-rate and shared-statistics inputs remain unavailable.',
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
            ->whereBetween('at', [$from->toDateString(), $to->toDateString()])
            ->whereIn('source_type', ['campaign', 'drop'])
            ->whereNotNull('isp_id')
            ->whereIn('isp_id', $isps->pluck('id'))
            ->when(isset($filters['isp_id']), fn ($query) => $query->where('isp_id', (int) $filters['isp_id']))
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->where('user_id', $request->user()->getKey()))
            ->selectRaw('isp_id, at AS event_date, SUM(opens) AS opens, SUM(clicks) AS clicks')
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
            ->whereBetween('a.at', [$from->toDateString(), $to->toDateString()])
            ->whereIn('a.source_type', ['campaign', 'drop'])
            ->whereNotNull('a.isp_id')
            ->whereIn('a.isp_id', $isps->pluck('id'))
            ->when(isset($filters['isp_id']), fn ($query) => $query->where('a.isp_id', (int) $filters['isp_id']))
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->where('a.user_id', $request->user()->getKey()))
            ->select('a.user_id', 'a.at as event_date')
            ->selectRaw("COALESCE(NULLIF(u.name, ''), 'Unknown') AS mailer")
            ->selectRaw('SUM(a.opens) AS opens, SUM(a.clicks) AS clicks, SUM(a.leads) AS leads')
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
        $chartMetrics = ['opens', 'clicks', 'leads', 'unsubscribes', 'lead_revenue'];
        $chartTotals = $mailerDaily->groupBy(fn (object $row): string => $periodKey((string) $row->event_date))
            ->map(fn (Collection $periodRows): array => [
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
            'headers' => ['PERIOD', 'ISP', 'OPENS', 'CLICKS', 'LEADS', 'UNSUBSCRIBES', 'LEAD REVENUE'],
            'coverage' => 'Charts use saved campaign and Drop engagement rollups plus attributed affiliate campaign leads. DMS delivery/bounce totals and delivery-based rates are unavailable. Attributed lead revenue is not equivalent to DMS earnings.',
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
            ->whereBetween('a.at', [$from->toDateString(), $to->toDateString()])
            ->whereIn('a.source_type', ['campaign', 'drop'])
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->where('a.user_id', $request->user()->getKey()))
            ->select('a.user_id')
            ->selectRaw("COALESCE(u.name, u.username, 'Unknown') AS mailer")
            ->selectRaw('SUM(a.opens) AS opens, SUM(a.clicks) AS clicks, SUM(a.leads) AS leads')
            ->selectRaw('SUM(a.unsubscribes) AS unsubscribes, SUM(a.bots) AS bots, SUM(a.lead_revenue) AS lead_revenue')
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
            'headers' => ['MAILER', 'OPENS', 'CLICKS', 'LEADS', 'UNSUBSCRIBES', 'LEAD REVENUE'],
            'coverage' => 'Drop leads, attributed campaign conversions and engagement are grouped by owner. PMTA delivery/bounce totals and DMS conversion rates are not connected yet.',
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

    /**
     * @return array<string, list<array{label: string, today: float, mtd: float}>>
     */
    private function mtdLeadRevenueBreakdowns(Workspace $workspace, User $user, Carbon $monthFrom, Carbon $todayFrom, Carbon $to): array
    {
        $cacheKey = $this->mainDashboardCacheKey('mtd-lead-revenue', $workspace, $user, $monthFrom, $to);

        return Cache::remember(
            $cacheKey,
            self::MAIN_DASHBOARD_CACHE_SECONDS,
            fn (): array => $this->loadMtdLeadRevenueBreakdowns($workspace, $user, $monthFrom, $todayFrom, $to),
        );
    }

    /**
     * @return array<string, list<array{label: string, today: float, mtd: float}>>
     */
    private function loadMtdLeadRevenueBreakdowns(Workspace $workspace, User $user, Carbon $monthFrom, Carbon $todayFrom, Carbon $to): array
    {
        $rows = DB::table('workspace_daily_analytics')
            ->where('workspace_id', $workspace->getKey())
            ->whereBetween('at', [$monthFrom->toDateString(), $to->toDateString()])
            ->where('leads', '>', 0)
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->where('user_id', $user->getKey()))
            ->select('sponsor_id', 'isp_id', 'user_id', 'offer_id')
            ->selectRaw('SUM(CASE WHEN at = ? THEN lead_revenue ELSE 0 END) AS today_revenue', [$todayFrom->toDateString()])
            ->selectRaw('SUM(lead_revenue) AS mtd_revenue')
            ->groupBy('sponsor_id', 'isp_id', 'user_id', 'offer_id')
            ->get();
        $userIds = $rows->pluck('user_id')->filter()->unique()->values();

        $labels = [
            'sponsor_id' => DB::table('workspace_sponsors')
                ->where('workspace_id', $workspace->getKey())
                ->pluck('name', 'id'),
            'isp_id' => DB::table('isps')
                ->where('workspace_id', $workspace->getKey())
                ->pluck('name', 'id'),
            'user_id' => DB::table('users')
                ->whereIn('id', $userIds)
                ->selectRaw("id, COALESCE(name, username, 'Unknown') AS label")
                ->pluck('label', 'id'),
            'offer_id' => DB::table('workspace_offers')
                ->where('workspace_id', $workspace->getKey())
                ->pluck('name', 'id'),
        ];

        $breakdowns = [];
        foreach ([
            'sponsors' => ['sponsor_id', 'affiliate'],
            'isps' => ['isp_id', 'ISP'],
            'mailers' => ['user_id', 'mailer'],
            'offers' => ['offer_id', 'offer'],
        ] as $key => [$column, $fallback]) {
            $breakdown = $rows->groupBy(fn (object $row): string => (string) ($row->{$column} ?? 'unknown'))
                ->map(function (Collection $dimensionRows, string $id) use ($column, $fallback, $labels): array {
                    return [
                        'label' => $labels[$column][$id] ?? 'Unknown '.$fallback,
                        'today' => round($dimensionRows->sum(fn (object $row): float => (float) $row->today_revenue), 2),
                        'mtd' => round($dimensionRows->sum(fn (object $row): float => (float) $row->mtd_revenue), 2),
                    ];
                })
                ->filter(fn (array $row): bool => $row['mtd'] > 0)
                ->sortByDesc('mtd')
                ->values();

            $breakdowns[$key] = $key === 'offers' ? $breakdown->take(10)->values()->all() : $breakdown->all();
        }

        return $breakdowns;
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

        return 'alphalink:dashboard:main:v1:'.$dataset.':'.hash('sha256', $scope);
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
