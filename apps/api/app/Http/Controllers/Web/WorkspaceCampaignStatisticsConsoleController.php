<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AudienceList;
use App\Models\Campaign;
use App\Models\Isp;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WorkspaceCampaignStatisticsConsoleController extends Controller
{
    private const ALLOWED_ROLES = ['owner', 'admin', 'operator', 'viewer', 'privacy_officer'];

    public function index(Request $request, string $workspaceId): View|JsonResponse
    {
        $workspace = $this->authorizedWorkspace($request->user(), $workspaceId);
        $dates = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:from'],
            'campaign_id' => ['nullable', 'uuid'],
            'campaign_ids' => ['nullable', 'array', 'min:1', 'max:100'],
            'campaign_ids.*' => ['required', 'uuid', 'distinct'],
            'isp_id' => ['nullable', 'integer', 'min:1'],
            'list_id' => ['nullable', 'uuid'],
            'inline' => ['sometimes', 'boolean'],
            'register' => ['sometimes', 'boolean'],
        ]);
        $from = Carbon::parse($dates['from'] ?? today()->toDateString())->startOfDay();
        $to = Carbon::parse($dates['to'] ?? today()->toDateString())->endOfDay();
        $operator = $workspace->pivot->role === 'operator';

        $campaigns = Campaign::query()
            ->where('workspace_id', $workspace->getKey())
            ->when($operator, fn ($query) => $query->where('created_by', $request->user()->getKey()))
            ->orderBy('name')
            ->get(['id', 'name']);
        $isps = Isp::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)
            ->when($operator, fn ($query) => $query->whereJsonContains('allowed_users', (int) $request->user()->getKey()))
            ->orderBy('name')
            ->get(['id', 'name']);
        $lists = AudienceList::query()
            ->where('workspace_id', $workspace->getKey())
            ->when($operator, fn ($query) => $query->whereJsonContains('allowed_users', (int) $request->user()->getKey()))
            ->orderBy('name')
            ->get(['id', 'name']);

        $selectedCampaignId = $dates['campaign_id'] ?? '';
        $selectedIspId = $dates['isp_id'] ?? '';
        $selectedListId = $dates['list_id'] ?? '';
        abort_if($selectedCampaignId !== '' && ! $campaigns->contains('id', $selectedCampaignId), 404);
        abort_if($selectedIspId !== '' && ! $isps->contains('id', (int) $selectedIspId), 404);
        abort_if($selectedListId !== '' && ! $lists->contains('id', $selectedListId), 404);

        if ($request->boolean('register')) {
            abort_if(! $request->expectsJson(), 400);
            abort_if($selectedCampaignId !== '' && ! empty($dates['campaign_ids']), 400);
            $registerCampaignIds = $dates['campaign_ids']
                ?? ($selectedCampaignId !== '' ? [$selectedCampaignId] : []);
            $registerCampaigns = Campaign::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('created_by', $request->user()->getKey())
                ->when($registerCampaignIds !== [], fn ($query) => $query->whereIn('id', $registerCampaignIds));
            abort_unless($registerCampaignIds === [] || $registerCampaigns->count() === count($registerCampaignIds), 404);

            return response()->json($this->campaignRegisterSummary(
                $workspace,
                (int) $request->user()->getKey(),
                $registerCampaignIds,
                $from,
                $to,
            ));
        }

        if ($request->boolean('inline')) {
            abort_if(! $request->expectsJson() || $selectedCampaignId === '', 400);

            return response()->json($this->inlineCampaignSummary(
                $workspace,
                $selectedCampaignId,
                $from,
                $to,
            ));
        }

        $dailyRows = DB::table('workspace_daily_analytics as a')
            ->join('campaigns as c', function (JoinClause $join): void {
                $join->on('c.id', '=', 'a.campaign_id')->on('c.workspace_id', '=', 'a.workspace_id');
            })
            ->leftJoin('users as u', 'u.id', '=', 'c.created_by')
            ->leftJoin('audience_lists as al', function (JoinClause $join): void {
                $join->on(DB::raw('CAST(al.id AS TEXT)'), '=', 'a.list_id')->on('al.workspace_id', '=', 'a.workspace_id');
            })
            ->leftJoin('isps as i', function (JoinClause $join): void {
                $join->on(DB::raw('CAST(i.id AS TEXT)'), '=', 'a.isp_id')->on('i.workspace_id', '=', 'a.workspace_id');
            })
            ->where('a.workspace_id', $workspace->getKey())
            ->where('a.source_type', 'campaign')
            ->where('a.attribution_scope', 'list')
            ->whereBetween('a.at', [$from->toDateString(), $to->toDateString()])
            ->when($operator, fn ($query) => $query->where('a.user_id', $request->user()->getKey()))
            ->when($selectedCampaignId !== '', fn ($query) => $query->whereRaw(
                DB::connection()->getDriverName() === 'pgsql'
                    ? 'a.campaign_id = CAST(? AS UUID)'
                    : 'a.campaign_id = ?',
                [$selectedCampaignId],
            ))
            ->when($selectedIspId !== '', fn ($query) => $query->where('a.isp_id', (string) (int) $selectedIspId))
            ->when($selectedListId !== '', fn ($query) => $query->where('a.list_id', $selectedListId))
            ->selectRaw('a.at AS event_date, a.campaign_id, c.name AS campaign, COALESCE(u.name, u.username, \'Unknown\') AS mailer')
            ->selectRaw('a.user_id, a.list_id AS audience_list_id, al.name AS audience_list, a.isp_id, i.name AS isp, a.list_type')
            ->selectRaw('a.server_id, a.ip_id, a.domain_id, a.sponsor_id, a.offer_id, a.from_id, a.subject_id, a.creative_id')
            ->selectRaw('SUM(a.deliveries) AS deliveries, SUM(a.soft_bounces) AS soft_bounces, SUM(a.hard_bounces) AS hard_bounces')
            ->selectRaw('SUM(a.opens) AS opens, SUM(a.clicks) AS clicks, SUM(a.leads) AS leads')
            ->selectRaw('SUM(CASE WHEN a.lead_revenue > 0 THEN 1 ELSE 0 END) AS conversions')
            ->selectRaw('SUM(a.unsubscribes) AS unsubscribes, SUM(a.bots) AS bots, SUM(a.lead_revenue) AS lead_revenue')
            ->groupBy(
                'a.at', 'a.user_id', 'a.campaign_id', 'c.name', 'c.created_by', 'u.name', 'u.username',
                'a.list_id', 'al.name', 'a.isp_id', 'i.name', 'a.list_type', 'a.server_id', 'a.ip_id',
                'a.domain_id', 'a.sponsor_id', 'a.offer_id', 'a.from_id', 'a.subject_id', 'a.creative_id',
            )
            ->orderByDesc('event_date')
            ->get();

        if ($request->expectsJson()) {
            $idsFor = fn (string $column): Collection => $dailyRows->pluck($column)
                ->filter(fn (mixed $value): bool => $value !== null && $value !== '' && (string) $value !== '0' && (string) $value !== 'NA')
                ->map(fn (mixed $value): string => (string) $value)
                ->unique()
                ->values();
            $workspaceLabels = fn (string $table, Collection $ids, string $label = 'name'): Collection => $ids->isEmpty()
                ? collect()
                : DB::table($table)->where('workspace_id', $workspace->getKey())->whereIn('id', $ids)->pluck($label, 'id');
            $sponsors = $workspaceLabels('workspace_sponsors', $idsFor('sponsor_id'));
            $offers = $idsFor('offer_id')->isEmpty()
                ? collect()
                : DB::table('workspace_offers')->where('workspace_id', $workspace->getKey())
                    ->whereIn('id', $idsFor('offer_id'))->get(['id', 'name', 'vertical'])->keyBy('id');
            $servers = $idsFor('server_id')->isEmpty()
                ? collect()
                : DB::table('managed_servers')->where('workspace_id', $workspace->getKey())
                    ->whereIn('id', $idsFor('server_id'))->get(['id', 'name', 'provider'])->keyBy('id');
            $ips = $workspaceLabels('managed_server_ips', $idsFor('ip_id'), 'ip');
            $domainIds = $idsFor('domain_id')
                ->map(fn (string $id): ?string => $this->canonicalUuid($id))
                ->filter()
                ->unique()
                ->values();
            $domains = $workspaceLabels('sending_domains', $domainIds);
            $assets = $workspaceLabels(
                'workspace_offer_assets',
                $idsFor('from_id')->merge($idsFor('subject_id'))->merge($idsFor('creative_id'))->unique(),
                'value',
            );
            $pivotRows = $dailyRows->map(function (object $row) use ($sponsors, $offers, $servers, $ips, $domains, $assets): array {
                $offer = $offers[(string) ($row->offer_id ?? '')] ?? null;
                $server = $servers[(string) ($row->server_id ?? '')] ?? null;

                return [
                    'at' => (string) $row->event_date,
                    'user' => (string) $row->mailer,
                    'campaign_id' => (string) $row->campaign_id,
                    'isp' => (string) ($row->isp ?? 'NA'),
                    'list' => (string) ($row->audience_list ?? 'NA'),
                    'list_type' => (string) ($row->list_type ?: 'NA'),
                    'provider' => (string) ($server->provider ?? 'NA'),
                    'server' => (string) ($server->name ?? 'NA'),
                    'ip' => (string) ($ips[(string) ($row->ip_id ?? '')] ?? 'NA'),
                    'domain' => (string) ($domains[$this->canonicalUuid((string) ($row->domain_id ?? '')) ?? ''] ?? 'NA'),
                    'sponsor' => (string) ($sponsors[(string) ($row->sponsor_id ?? '')] ?? 'NA'),
                    'offer' => (string) ($offer->name ?? 'NA'),
                    'vertical' => (string) ($offer->vertical ?? 'NA'),
                    'from' => (string) ($assets[(string) ($row->from_id ?? '')] ?? 'NA'),
                    'subject' => (string) ($assets[(string) ($row->subject_id ?? '')] ?? 'NA'),
                    'creative' => (string) ($assets[(string) ($row->creative_id ?? '')] ?? 'NA'),
                    'deliveries' => (int) $row->deliveries,
                    'soft_bounces' => (int) $row->soft_bounces,
                    'hard_bounces' => (int) $row->hard_bounces,
                    'opens' => (int) $row->opens,
                    'clicks' => (int) $row->clicks,
                    'leads' => (int) $row->leads,
                    'conversions' => (int) $row->conversions,
                    'unsubscribes' => (int) $row->unsubscribes,
                    'bots' => (int) $row->bots,
                    'lead_revenue' => (float) $row->lead_revenue,
                ];
            })->values();

            return response()->json($pivotRows);
        }

        $rows = $this->mergeRows($dailyRows, collect());
        $summary = [
            'deliveries' => $rows->sum('deliveries'),
            'soft_bounces' => $rows->sum('soft_bounces'),
            'hard_bounces' => $rows->sum('hard_bounces'),
            'opens' => $rows->sum('opens'),
            'clicks' => $rows->sum('clicks'),
            'leads' => $rows->sum('leads'),
            'conversions' => $rows->sum('conversions'),
            'unsubscribes' => $rows->sum('unsubscribes'),
            'lead_revenue' => $rows->sum('lead_revenue'),
        ];

        $viewData = [
            'workspace' => $workspace,
            'campaigns' => $campaigns,
            'isps' => $isps,
            'lists' => $lists,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'selectedCampaignId' => $selectedCampaignId,
            'selectedIspId' => (string) $selectedIspId,
            'selectedListId' => $selectedListId,
            'summary' => $summary,
            'rows' => $this->paginateRows($request, $rows),
        ];

        if ($request->boolean('fragment')) {
            return view('console.campaigns.partials.statistics-results', $viewData);
        }

        return view('console.campaigns.statistics', $viewData);
    }

    /** @return list<array{opens:int, clicks:int, leads:int, lead_revenue:float, unsubscribes:int, bots:int}> */
    private function inlineCampaignSummary(Workspace $workspace, string $campaignId, Carbon $from, Carbon $to): array
    {
        $engagement = DB::table('campaign_engagement_unique_actions as unique_actions')
            ->join('campaign_engagement_events as events', function (JoinClause $join): void {
                $join->on('events.id', '=', 'unique_actions.engagement_event_id')
                    ->on('events.workspace_id', '=', 'unique_actions.workspace_id');
            })
            ->where('unique_actions.workspace_id', $workspace->getKey())
            ->where('unique_actions.campaign_id', $campaignId)
            ->whereBetween('unique_actions.created_at', [$from, $to])
            ->selectRaw("SUM(CASE WHEN unique_actions.action = 'open' THEN 1 ELSE 0 END) AS opens")
            ->selectRaw("SUM(CASE WHEN unique_actions.action = 'click' THEN 1 ELSE 0 END) AS clicks")
            ->selectRaw("SUM(CASE WHEN unique_actions.action = 'unsubscribe' THEN 1 ELSE 0 END) AS unsubscribes")
            ->selectRaw("SUM(CASE WHEN unique_actions.action = 'bot' THEN 1 ELSE 0 END) AS bots")
            ->first();
        $leads = DB::table('campaign_lead_conversions')
            ->where('workspace_id', $workspace->getKey())
            ->where('campaign_id', $campaignId)
            ->whereBetween('converted_at', [$from, $to])
            ->selectRaw('COUNT(*) AS leads')
            ->selectRaw('COALESCE(SUM(revenue), 0) AS lead_revenue')
            ->first();

        $summary = [
            'opens' => (int) ($engagement->opens ?? 0),
            'clicks' => (int) ($engagement->clicks ?? 0),
            'leads' => (int) ($leads->leads ?? 0),
            'lead_revenue' => (float) ($leads->lead_revenue ?? 0),
            'unsubscribes' => (int) ($engagement->unsubscribes ?? 0),
            'bots' => (int) ($engagement->bots ?? 0),
        ];

        return array_sum(array_map(
            static fn (string $key): int|float => $summary[$key],
            ['opens', 'clicks', 'leads', 'lead_revenue', 'unsubscribes', 'bots'],
        )) === 0 ? [] : [$summary];
    }

    /**
     * Return privacy-safe daily campaign totals for the My Campaigns intelligence panel.
     *
     * @return list<array<string, mixed>>
     */
    private function campaignRegisterSummary(Workspace $workspace, int $userId, array $campaignIds, Carbon $from, Carbon $to): array
    {
        $engagementRows = DB::table('campaign_engagement_unique_actions as unique_actions')
            ->join('campaigns as c', function (JoinClause $join): void {
                $join->on('c.id', '=', 'unique_actions.campaign_id')
                    ->on('c.workspace_id', '=', 'unique_actions.workspace_id');
            })
            ->where('unique_actions.workspace_id', $workspace->getKey())
            ->where('c.created_by', $userId)
            ->when($campaignIds !== [], fn ($query) => $query->whereIn('unique_actions.campaign_id', $campaignIds))
            ->whereBetween('unique_actions.created_at', [$from, $to])
            ->selectRaw('DATE(unique_actions.created_at) AS event_date')
            ->selectRaw("SUM(CASE WHEN unique_actions.action = 'open' THEN 1 ELSE 0 END) AS opens")
            ->selectRaw("SUM(CASE WHEN unique_actions.action = 'click' THEN 1 ELSE 0 END) AS clicks")
            ->selectRaw("SUM(CASE WHEN unique_actions.action = 'unsubscribe' THEN 1 ELSE 0 END) AS unsubscribes")
            ->groupByRaw('DATE(unique_actions.created_at)')
            ->get();

        $leadRows = DB::table('campaign_lead_conversions as conversions')
            ->join('campaigns as c', function (JoinClause $join): void {
                $join->on('c.id', '=', 'conversions.campaign_id')
                    ->on('c.workspace_id', '=', 'conversions.workspace_id');
            })
            ->where('conversions.workspace_id', $workspace->getKey())
            ->where('c.created_by', $userId)
            ->when($campaignIds !== [], fn ($query) => $query->whereIn('conversions.campaign_id', $campaignIds))
            ->whereBetween('conversions.converted_at', [$from, $to])
            ->selectRaw('DATE(conversions.converted_at) AS event_date, COUNT(*) AS leads, COALESCE(SUM(conversions.revenue), 0) AS lead_revenue')
            ->groupByRaw('DATE(conversions.converted_at)')
            ->get();

        $feedbackRows = DB::table('workspace_pmta_feedback_events as feedback')
            ->join('campaigns as c', function (JoinClause $join): void {
                $join->on('c.id', '=', 'feedback.campaign_id')
                    ->on('c.workspace_id', '=', 'feedback.workspace_id');
            })
            ->where('feedback.workspace_id', $workspace->getKey())
            ->where('feedback.source_type', 'campaign')
            ->whereIn('feedback.metric', ['deliveries', 'soft_bounces', 'hard_bounces'])
            ->where('c.created_by', $userId)
            ->when($campaignIds !== [], fn ($query) => $query->whereIn('feedback.campaign_id', $campaignIds))
            ->whereBetween('feedback.at', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('feedback.at AS event_date')
            ->selectRaw("SUM(CASE WHEN feedback.metric = 'deliveries' THEN 1 ELSE 0 END) AS deliveries")
            ->selectRaw("SUM(CASE WHEN feedback.metric = 'soft_bounces' THEN 1 ELSE 0 END) AS soft_bounces")
            ->selectRaw("SUM(CASE WHEN feedback.metric = 'hard_bounces' THEN 1 ELSE 0 END) AS hard_bounces")
            ->groupBy('feedback.at')
            ->get();

        $sentRows = DB::table('campaign_delivery_attempts as attempts')
            ->join('campaign_send_runs as runs', function (JoinClause $join): void {
                $join->on('runs.id', '=', 'attempts.send_run_id')
                    ->on('runs.workspace_id', '=', 'attempts.workspace_id');
            })
            ->join('campaigns as c', function (JoinClause $join): void {
                $join->on('c.id', '=', 'runs.campaign_id')
                    ->on('c.workspace_id', '=', 'runs.workspace_id');
            })
            ->where('attempts.workspace_id', $workspace->getKey())
            ->where('attempts.state', 'accepted')
            ->whereNotNull('attempts.finished_at')
            ->where('c.created_by', $userId)
            ->when($campaignIds !== [], fn ($query) => $query->whereIn('runs.campaign_id', $campaignIds))
            ->whereBetween('attempts.finished_at', [$from, $to])
            ->selectRaw('DATE(attempts.finished_at) AS event_date, COUNT(*) AS sent')
            ->groupByRaw('DATE(attempts.finished_at)')
            ->get();

        $daily = [];
        foreach ($engagementRows as $row) {
            $date = (string) $row->event_date;
            $daily[$date] ??= $this->emptyCampaignRegisterDay($date);
            foreach (['opens', 'clicks', 'unsubscribes'] as $metric) {
                $daily[$date][$metric] = (int) $row->{$metric};
            }
        }
        foreach ($leadRows as $row) {
            $date = (string) $row->event_date;
            $daily[$date] ??= $this->emptyCampaignRegisterDay($date);
            $daily[$date]['leads'] = (int) $row->leads;
            $daily[$date]['lead_revenue'] = (float) $row->lead_revenue;
        }
        foreach ($feedbackRows as $row) {
            $date = (string) $row->event_date;
            $daily[$date] ??= $this->emptyCampaignRegisterDay($date);
            $daily[$date]['deliveries'] = (int) $row->deliveries;
            $daily[$date]['soft_bounces'] = (int) $row->soft_bounces;
            $daily[$date]['hard_bounces'] = (int) $row->hard_bounces;
            $daily[$date]['bounces'] = $daily[$date]['soft_bounces'] + $daily[$date]['hard_bounces'];
        }
        foreach ($sentRows as $row) {
            $date = (string) $row->event_date;
            $daily[$date] ??= $this->emptyCampaignRegisterDay($date);
            $daily[$date]['sent'] = (int) $row->sent;
        }

        ksort($daily);
        $summary = [
            'sent' => 0,
            'deliveries' => 0,
            'opens' => 0,
            'clicks' => 0,
            'leads' => 0,
            'lead_revenue' => 0.0,
            'unsubscribes' => 0,
            'soft_bounces' => 0,
            'hard_bounces' => 0,
            'bounces' => 0,
        ];
        foreach ($daily as $day) {
            foreach ($summary as $metric => $value) {
                $summary[$metric] += $day[$metric];
            }
        }

        return [$summary + [
            'timeline' => array_values($daily),
            'coverage' => 'Sent counts SMTP-accepted recipient attempts; deliveries and bounces count imported PMTA feedback; opens, clicks, and unsubscribes are unique tracked actions; leads are attributed conversions. No recipient details are returned.',
        ]];
    }

    /** @return array{date: string, sent: int, deliveries: int, opens: int, clicks: int, leads: int, lead_revenue: float, unsubscribes: int, soft_bounces: int, hard_bounces: int, bounces: int} */
    private function emptyCampaignRegisterDay(string $date): array
    {
        return [
            'date' => $date,
            'sent' => 0,
            'deliveries' => 0,
            'opens' => 0,
            'clicks' => 0,
            'leads' => 0,
            'lead_revenue' => 0.0,
            'unsubscribes' => 0,
            'soft_bounces' => 0,
            'hard_bounces' => 0,
            'bounces' => 0,
        ];
    }

    /** @param Collection<int, object> $engagementRows
     * @param  Collection<int, object>  $leadRows
     * @return Collection<int, array<string, mixed>>
     */
    private function mergeRows(Collection $engagementRows, Collection $leadRows): Collection
    {
        $merged = [];
        foreach ($engagementRows->concat($leadRows) as $row) {
            $key = implode('|', [
                $row->campaign_id,
                $row->audience_list_id,
                $row->isp_id ?? 'na',
                $row->list_type,
                $row->event_date,
            ]);
            $merged[$key] ??= [
                'event_date' => (string) $row->event_date,
                'campaign' => $row->campaign,
                'mailer' => $row->mailer,
                'isp' => $row->isp ?? 'NA',
                'audience_list' => $row->audience_list ?? 'NA',
                'list_type' => $row->list_type,
                'opens' => 0,
                'clicks' => 0,
                'leads' => 0,
                'conversions' => 0,
                'deliveries' => 0,
                'soft_bounces' => 0,
                'hard_bounces' => 0,
                'unsubscribes' => 0,
                'bots' => 0,
                'lead_revenue' => 0.0,
            ];
            foreach (['deliveries', 'soft_bounces', 'hard_bounces', 'opens', 'clicks', 'leads', 'conversions', 'unsubscribes', 'bots'] as $metric) {
                $merged[$key][$metric] += (int) $row->{$metric};
            }
            $merged[$key]['lead_revenue'] += (float) $row->lead_revenue;
        }

        return collect($merged)->sortBy([
            ['event_date', 'desc'],
            ['campaign', 'asc'],
            ['audience_list', 'asc'],
        ])->values();
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
        $workspace = $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, self::ALLOWED_ROLES, true), 403);

        return $workspace;
    }

    private function canonicalUuid(string $value): ?string
    {
        $compact = strtolower(str_replace('-', '', $value));
        if (! preg_match('/^[a-f0-9]{32}$/', $compact)) {
            return null;
        }

        return substr($compact, 0, 8).'-'.substr($compact, 8, 4).'-'.substr($compact, 12, 4).'-'.substr($compact, 16, 4).'-'.substr($compact, 20);
    }
}
