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
    private const ALLOWED_ROLES = ['owner', 'admin', 'operator', 'privacy_officer'];

    public function index(Request $request, string $workspaceId): View|JsonResponse
    {
        $workspace = $this->authorizedWorkspace($request->user(), $workspaceId);
        $dates = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:from'],
            'campaign_id' => ['nullable', 'uuid'],
            'isp_id' => ['nullable', 'integer', 'min:1'],
            'list_id' => ['nullable', 'uuid'],
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

        $dailyRows = DB::table('workspace_daily_analytics as a')
            ->join('campaigns as c', function (JoinClause $join): void {
                $join->on('c.id', '=', 'a.campaign_id')->on('c.workspace_id', '=', 'a.workspace_id');
            })
            ->leftJoin('users as u', 'u.id', '=', 'c.created_by')
            ->leftJoin('audience_lists as al', function (JoinClause $join): void {
                $join->on('al.id', '=', 'a.list_id')->on('al.workspace_id', '=', 'a.workspace_id');
            })
            ->leftJoin('isps as i', function (JoinClause $join): void {
                $join->on('i.id', '=', 'a.isp_id')->on('i.workspace_id', '=', 'a.workspace_id');
            })
            ->where('a.workspace_id', $workspace->getKey())
            ->where('a.source_type', 'campaign')
            ->whereBetween('a.at', [$from->toDateString(), $to->toDateString()])
            ->when($operator, fn ($query) => $query->where('a.user_id', $request->user()->getKey()))
            ->when($selectedCampaignId !== '', fn ($query) => $query->where('a.campaign_id', $selectedCampaignId))
            ->when($selectedIspId !== '', fn ($query) => $query->where('a.isp_id', (int) $selectedIspId))
            ->when($selectedListId !== '', fn ($query) => $query->where('a.list_id', $selectedListId))
            ->selectRaw('a.at AS event_date, a.campaign_id, c.name AS campaign, COALESCE(u.name, u.username, \'Unknown\') AS mailer')
            ->selectRaw('a.user_id, a.list_id AS audience_list_id, al.name AS audience_list, a.isp_id, i.name AS isp, a.list_type')
            ->selectRaw('a.server_id, a.ip_id, a.domain_id, a.sponsor_id, a.offer_id, a.from_id, a.subject_id, a.creative_id')
            ->selectRaw('SUM(a.opens) AS opens, SUM(a.clicks) AS clicks, SUM(a.leads) AS leads')
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
            $domains = $workspaceLabels('sending_domains', $idsFor('domain_id'));
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
                    'domain' => (string) ($domains[(string) ($row->domain_id ?? '')] ?? 'NA'),
                    'sponsor' => (string) ($sponsors[(string) ($row->sponsor_id ?? '')] ?? 'NA'),
                    'offer' => (string) ($offer->name ?? 'NA'),
                    'vertical' => (string) ($offer->vertical ?? 'NA'),
                    'from' => (string) ($assets[(string) ($row->from_id ?? '')] ?? 'NA'),
                    'subject' => (string) ($assets[(string) ($row->subject_id ?? '')] ?? 'NA'),
                    'creative' => (string) ($assets[(string) ($row->creative_id ?? '')] ?? 'NA'),
                    'opens' => (int) $row->opens,
                    'clicks' => (int) $row->clicks,
                    'leads' => (int) $row->leads,
                    'unsubscribes' => (int) $row->unsubscribes,
                    'bots' => (int) $row->bots,
                    'lead_revenue' => (float) $row->lead_revenue,
                ];
            })->values();

            return response()->json($pivotRows);
        }

        $rows = $this->mergeRows($dailyRows, collect());
        $summary = [
            'opens' => $rows->sum('opens'),
            'clicks' => $rows->sum('clicks'),
            'leads' => $rows->sum('leads'),
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
                'unsubscribes' => 0,
                'bots' => 0,
                'lead_revenue' => 0.0,
            ];
            foreach (['opens', 'clicks', 'leads', 'unsubscribes', 'bots'] as $metric) {
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
}
