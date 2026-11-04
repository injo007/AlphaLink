<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AudienceList;
use App\Models\Isp;
use App\Models\ManagedServer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDrop;
use App\Models\WorkspaceDropVisitor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WorkspaceDropsConsoleController extends Controller
{
    private const ALLOWED_ROLES = ['owner', 'admin', 'operator'];

    public function monitoring(Request $request, string $workspace): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $filters = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'drop_type' => ['sometimes', 'string', Rule::in(['all', 'send', 'test'])],
        ]);

        if (! $request->wantsJson()) {
            return view('console.drops.monitoring', [
                'workspace' => $workspace,
                'today' => now()->toDateString(),
            ]);
        }

        $fromDate = $filters['from'] ?? now()->toDateString();
        $toDate = $filters['to'] ?? now()->toDateString();
        $from = Carbon::parse($fromDate)->startOfDay();
        $to = Carbon::parse($toDate)->endOfDay();
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to' => 'Date to must be on or after Date from.']);
        }
        $drops = WorkspaceDrop::query()
            ->where('workspace_id', $workspace->getKey())
            ->with(['sponsor:id,name', 'offer:id,offer_id,name', 'isp:id,name', 'user:id,username'])
            ->when($workspace->pivot->role === 'operator', fn (Builder $query) => $query->where('user_id', $request->user()->getKey()))
            ->when(($filters['drop_type'] ?? 'all') !== 'all', fn (Builder $query) => $query->where('send_type', $filters['drop_type']))
            ->where('is_processed', true)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('id')
            ->get();

        $serverIds = $drops->flatMap(fn (WorkspaceDrop $drop): array => $drop->servers ?? [])->unique()->values();
        $servers = ManagedServer::withTrashed()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('id', $serverIds)
            ->get(['id', 'name', 'is_active', 'is_installed', 'deleted_at'])
            ->keyBy(fn (ManagedServer $server): string => (string) $server->getKey());
        $listIds = $drops->flatMap(fn (WorkspaceDrop $drop): array => $drop->lists ?? [])->unique()->values();
        $lists = AudienceList::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('id', $listIds)
            ->get(['id', 'name'])
            ->keyBy(fn (AudienceList $list): string => (string) $list->getKey());

        return response()->json([
            'data' => $drops->map(function (WorkspaceDrop $drop) use ($servers, $lists): array {
                $serverNames = collect($drop->servers ?? [])->map(function ($serverId) use ($servers): string {
                    $server = $servers->get((string) $serverId);

                    return ($server?->name ?? 'NA').($server?->getKey() ?? '0');
                })->values()->all();
                $pmtaServerIds = collect($drop->servers ?? [])->filter(function ($serverId) use ($servers): bool {
                    $server = $servers->get((string) $serverId);

                    return $server !== null && $server->deleted_at === null && $server->is_active && $server->is_installed;
                })->map(fn ($serverId): string => (string) $serverId)->values()->all();
                $listNames = collect($drop->lists ?? [])->map(fn ($listId) => $lists->get((string) $listId)?->name)
                    ->filter(fn (?string $name): bool => $name !== null && $name !== '')
                    ->values()->all();

                return [
                    'id' => (int) $drop->getKey(),
                    'send_type' => $drop->send_type,
                    'servers' => $serverNames,
                    'server_ids' => $pmtaServerIds,
                    'send_status' => $drop->send_status,
                    'send_progress' => $drop->send_progress,
                    'send_count' => $drop->send_count,
                    'filters' => array_values($drop->filters ?? []),
                    'countries' => array_values($drop->countries ?? []),
                    'lists' => $listNames,
                    'offer' => sprintf('(%s) - (%s) - %s', $drop->sponsor?->name ?? '', $drop->offer?->offer_id ?? '', $drop->offer?->name ?? ''),
                    'isp' => $drop->isp?->name ?? 'NA',
                    'user' => $drop->user?->username ?? 'NA',
                    'is_scheduled' => $drop->is_scheduled,
                    'scheduled_at' => $drop->scheduled_at?->toISOString(),
                    'is_restricted' => $drop->is_restricted,
                    'created_at' => $drop->created_at?->toISOString(),
                ];
            })->all(),
        ]);
    }

    public function statistics(Request $request, string $workspace): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:from'],
            'drop_id' => ['nullable', 'integer', 'min:1'],
            'isp_id' => ['nullable', 'integer', 'min:1'],
            'list_id' => ['nullable', 'uuid'],
            'country' => ['nullable', 'string', 'max:80'],
            'send_type' => ['nullable', 'string', Rule::in(['send', 'test'])],
        ]);
        $from = Carbon::parse($filters['from'] ?? today()->toDateString())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? today()->toDateString())->endOfDay();
        $operator = $workspace->pivot->role === 'operator';

        $accessibleDrops = WorkspaceDrop::query()
            ->where('workspace_id', $workspace->getKey())
            ->when($operator, fn (Builder $query) => $query->where('user_id', $request->user()->getKey()));
        $selectedDropId = (string) ($filters['drop_id'] ?? '');
        if ($selectedDropId !== '') {
            abort_if(! (clone $accessibleDrops)->whereKey((int) $selectedDropId)->exists(), 404);
        }

        $isps = Isp::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)
            ->when($operator, fn (Builder $query) => $query->whereJsonContains('allowed_users', (int) $request->user()->getKey()))
            ->orderBy('name')
            ->get(['id', 'name']);
        $lists = AudienceList::query()
            ->where('workspace_id', $workspace->getKey())
            ->when($operator, fn (Builder $query) => $query->whereJsonContains('allowed_users', (int) $request->user()->getKey()))
            ->orderBy('name')
            ->get(['id', 'name']);
        $selectedIspId = (string) ($filters['isp_id'] ?? '');
        $selectedListId = (string) ($filters['list_id'] ?? '');
        abort_if($selectedIspId !== '' && ! $isps->contains('id', (int) $selectedIspId), 404);
        abort_if($selectedListId !== '' && ! $lists->contains('id', $selectedListId), 404);

        $eventScope = DB::table('workspace_drop_visitors as v')
            ->join('workspace_drops as d', function ($join): void {
                $join->on('d.id', '=', 'v.workspace_drop_id')->on('d.workspace_id', '=', 'v.workspace_id');
            })
            ->where('v.workspace_id', $workspace->getKey())
            ->whereBetween('v.created_at', [$from, $to])
            ->whereIn('v.type', ['open', 'click', 'lead', 'unsubscribe', 'bot'])
            ->when($operator, fn ($query) => $query->where('d.user_id', $request->user()->getKey()));
        $dropOptions = (clone $accessibleDrops)
            ->select(['id', 'send_type'])
            ->orderByDesc('id')
            ->limit(250)
            ->get()
            ->map(fn (object $drop): array => [
                'id' => (int) $drop->id,
                'label' => sprintf('#%d · %s', $drop->id, strtoupper((string) ($drop->send_type ?: 'unknown'))),
            ]);
        if ($selectedDropId !== '' && ! $dropOptions->contains('id', (int) $selectedDropId)) {
            $selectedDrop = (clone $accessibleDrops)->whereKey((int) $selectedDropId)->first(['id', 'send_type']);
            if ($selectedDrop !== null) {
                $dropOptions->prepend([
                    'id' => (int) $selectedDrop->getKey(),
                    'label' => sprintf('#%d · %s', $selectedDrop->getKey(), strtoupper((string) ($selectedDrop->send_type ?: 'unknown'))),
                ]);
            }
        }
        $countryOptions = (clone $eventScope)
            ->whereNotNull('v.country')
            ->where('v.country', '<>', '')
            ->distinct()
            ->orderBy('v.country')
            ->limit(250)
            ->pluck('v.country');
        $selectedCountry = (string) ($filters['country'] ?? '');
        abort_if($selectedCountry !== '' && ! $countryOptions->contains($selectedCountry), 404);

        $query = DB::table('workspace_drop_visitors as v')
            ->join('workspace_drops as d', function ($join): void {
                $join->on('d.id', '=', 'v.workspace_drop_id')->on('d.workspace_id', '=', 'v.workspace_id');
            })
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('isps as event_isp', function ($join): void {
                $join->on('event_isp.workspace_id', '=', 'v.workspace_id')
                    ->on('event_isp.id', '=', 'v.isp_id');
            })
            ->leftJoin('isps as drop_isp', function ($join): void {
                $join->on('drop_isp.id', '=', 'd.isp_id')->on('drop_isp.workspace_id', '=', 'd.workspace_id');
            })
            ->leftJoin('audience_lists as al', function ($join): void {
                $join->on('al.workspace_id', '=', 'v.workspace_id')
                    ->whereRaw('CAST(al.id AS TEXT) = v.list_id');
            })
            ->leftJoin('managed_servers as ms', function ($join): void {
                $join->on('ms.workspace_id', '=', 'v.workspace_id')
                    ->whereRaw('CAST(ms.id AS TEXT) = v.server_id');
            })
            ->leftJoin('managed_server_ips as msi', function ($join): void {
                $join->on('msi.workspace_id', '=', 'v.workspace_id')
                    ->whereRaw('CAST(msi.id AS TEXT) = v.ip_id');
            })
            ->leftJoin('sending_domains as sd', function ($join): void {
                $join->on('sd.workspace_id', '=', 'v.workspace_id')
                    ->whereRaw('CAST(sd.id AS TEXT) = v.domain_id');
            })
            ->leftJoin('workspace_sponsors as sponsor', function ($join): void {
                $join->on('sponsor.id', '=', 'd.sponsor_id')->on('sponsor.workspace_id', '=', 'd.workspace_id');
            })
            ->leftJoin('workspace_offers as offer', function ($join): void {
                $join->on('offer.id', '=', 'd.offer_id')->on('offer.workspace_id', '=', 'd.workspace_id');
            })
            ->leftJoin('workspace_offer_assets as from_asset', function ($join): void {
                $join->on('from_asset.workspace_id', '=', 'v.workspace_id')
                    ->whereRaw('CAST(from_asset.id AS TEXT) = v.from_id')
                    ->where('from_asset.type', 'from');
            })
            ->leftJoin('workspace_offer_assets as subject_asset', function ($join): void {
                $join->on('subject_asset.workspace_id', '=', 'v.workspace_id')
                    ->whereRaw('CAST(subject_asset.id AS TEXT) = v.subject_id')
                    ->where('subject_asset.type', 'subject');
            })
            ->where('v.workspace_id', $workspace->getKey())
            ->whereBetween('v.created_at', [$from, $to])
            ->whereIn('v.type', ['open', 'click', 'lead', 'unsubscribe', 'bot'])
            ->when($operator, fn ($query) => $query->where('d.user_id', $request->user()->getKey()))
            ->when($selectedDropId !== '', fn ($query) => $query->where('d.id', (int) $selectedDropId))
            ->when($selectedIspId !== '', fn ($query) => $query->where('v.isp_id', $selectedIspId))
            ->when($selectedListId !== '', fn ($query) => $query->where('v.list_id', $selectedListId))
            ->when($selectedCountry !== '', fn ($query) => $query->where('v.country', $selectedCountry))
            ->when(isset($filters['send_type']), fn ($query) => $query->where('d.send_type', $filters['send_type']))
            ->selectRaw('DATE(v.created_at) AS at, d.id AS drop_id, d.send_type AS drop_type')
            ->selectRaw("COALESCE(NULLIF(u.name, ''), NULLIF(u.username, ''), 'Unknown') AS user")
            ->selectRaw("COALESCE(event_isp.name, drop_isp.name, 'NA') AS isp, COALESCE(al.name, 'NA') AS list, COALESCE(NULLIF(v.list_type, ''), 'NA') AS list_type")
            ->selectRaw("COALESCE(ms.provider, 'NA') AS provider, COALESCE(ms.name, 'NA') AS server, COALESCE(CAST(msi.ip AS TEXT), 'NA') AS ip, COALESCE(sd.name, 'NA') AS domain")
            ->selectRaw("COALESCE(sponsor.name, 'NA') AS sponsor, COALESCE(offer.name, 'NA') AS offer, COALESCE(offer.vertical, 'NA') AS vertical")
            ->selectRaw("COALESCE(from_asset.value, 'NA') AS from_name, COALESCE(subject_asset.value, 'NA') AS subject, COALESCE(NULLIF(v.creative_id, ''), 'NA') AS creative, COALESCE(NULLIF(v.country, ''), 'NA') AS country")
            ->selectRaw("SUM(CASE WHEN v.type = 'open' THEN 1 ELSE 0 END) AS opens")
            ->selectRaw("SUM(CASE WHEN v.type = 'click' THEN 1 ELSE 0 END) AS clicks")
            ->selectRaw("SUM(CASE WHEN v.type = 'lead' THEN 1 ELSE 0 END) AS leads")
            ->selectRaw("SUM(CASE WHEN v.type = 'lead' AND v.revenue > 0 THEN 1 ELSE 0 END) AS conversions")
            ->selectRaw("SUM(CASE WHEN v.type = 'unsubscribe' THEN 1 ELSE 0 END) AS unsubscribes")
            ->selectRaw("SUM(CASE WHEN v.type = 'bot' THEN 1 ELSE 0 END) AS bots")
            ->selectRaw("SUM(CASE WHEN v.type = 'lead' THEN v.revenue ELSE 0 END) AS lead_revenue")
            ->groupByRaw('DATE(v.created_at)')
            ->groupBy(
                'd.id', 'd.send_type', 'u.name', 'u.username', 'event_isp.name', 'drop_isp.name', 'al.name',
                'v.list_type', 'ms.provider', 'ms.name', 'msi.ip', 'sd.name', 'sponsor.name', 'offer.name',
                'offer.vertical', 'from_asset.value', 'subject_asset.value', 'v.creative_id', 'v.country',
            )
            ->orderByDesc('at')
            ->orderBy('drop_id');

        $rows = $query->get()->map(fn (object $row): array => [
            'at' => (string) $row->at,
            'drop_id' => (string) $row->drop_id,
            'drop_type' => (string) ($row->drop_type ?: 'NA'),
            'user' => (string) $row->user,
            'isp' => (string) $row->isp,
            'list' => (string) $row->list,
            'list_type' => (string) $row->list_type,
            'provider' => (string) $row->provider,
            'server' => (string) $row->server,
            'ip' => (string) $row->ip,
            'domain' => (string) $row->domain,
            'sponsor' => (string) $row->sponsor,
            'offer' => (string) $row->offer,
            'vertical' => (string) $row->vertical,
            'from' => (string) $row->from_name,
            'subject' => (string) $row->subject,
            'creative' => (string) $row->creative,
            'country' => (string) $row->country,
            'opens' => (int) $row->opens,
            'clicks' => (int) $row->clicks,
            'leads' => (int) $row->leads,
            'conversions' => (int) $row->conversions,
            'unsubscribes' => (int) $row->unsubscribes,
            'bots' => (int) $row->bots,
            'lead_revenue' => (float) $row->lead_revenue,
        ])->values();

        if ($request->expectsJson()) {
            return response()->json($rows);
        }

        $summary = [
            'opens' => $rows->sum('opens'),
            'clicks' => $rows->sum('clicks'),
            'leads' => $rows->sum('leads'),
            'unsubscribes' => $rows->sum('unsubscribes'),
            'bots' => $rows->sum('bots'),
            'lead_revenue' => $rows->sum('lead_revenue'),
        ];
        $page = LengthAwarePaginator::resolveCurrentPage();
        $perPage = 50;
        $pageRows = $rows->forPage($page, $perPage)->values();

        $viewData = [
            'workspace' => $workspace,
            'drops' => $dropOptions,
            'isps' => $isps,
            'lists' => $lists,
            'countries' => $countryOptions,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'selectedDropId' => $selectedDropId,
            'selectedIspId' => $selectedIspId,
            'selectedListId' => $selectedListId,
            'selectedCountry' => $selectedCountry,
            'selectedSendType' => (string) ($filters['send_type'] ?? ''),
            'summary' => $summary,
            'rows' => new LengthAwarePaginator($pageRows, $rows->count(), $perPage, $page, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]),
        ];

        if ($request->boolean('fragment')) {
            return view('console.drops.partials.statistics-results', $viewData);
        }

        return view('console.drops.statistics', $viewData);
    }

    public function monitor(Request $request, string $workspace, int $drop): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $query = WorkspaceDrop::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereKey($drop);
        if ($workspace->pivot->role === 'operator') {
            $query->where('user_id', $request->user()->getKey());
        }
        $record = $query->firstOrFail();

        return response()->json([
            'id' => (int) $record->getKey(),
            'send_status' => $record->send_status,
            'send_count' => $record->send_count,
            'send_progress' => $record->send_progress,
        ]);
    }

    public function stats(Request $request, string $workspace, int $drop): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $record = $this->dropFor($request->user(), $workspace, $drop);
        $stats = $record->visitors()->selectRaw(<<<'SQL'
            COUNT(CASE WHEN type = 'open' THEN id END) AS open,
            COUNT(CASE WHEN type = 'click' THEN id END) AS click,
            COUNT(CASE WHEN type = 'lead' THEN id END) AS count_lead,
            COALESCE(SUM(revenue), 0) AS lead,
            COUNT(CASE WHEN type = 'unsubscribe' THEN id END) AS unsubscribe,
            COUNT(CASE WHEN type = 'bot' THEN id END) AS bot
        SQL)->first();

        return response()->json([
            'open' => (int) ($stats?->open ?? 0),
            'click' => (int) ($stats?->click ?? 0),
            'count_lead' => (int) ($stats?->count_lead ?? 0),
            'lead' => (float) ($stats?->lead ?? 0),
            'unsubscribe' => (int) ($stats?->unsubscribe ?? 0),
            'bot' => (int) ($stats?->bot ?? 0),
        ]);
    }

    public function visitors(Request $request, string $workspace, int $drop): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $record = $this->dropFor($request->user(), $workspace, $drop);

        if (! $request->wantsJson()) {
            return view('console.drops.visitors', [
                'workspace' => $workspace,
                'drop' => $record,
            ]);
        }

        $visitors = $record->visitors();
        if ($request->has('chart')) {
            $countries = $visitors->select('country')->selectRaw(<<<'SQL'
                COUNT(CASE WHEN type = 'open' THEN id END) AS open,
                COUNT(CASE WHEN type = 'click' THEN id END) AS click,
                COUNT(CASE WHEN type = 'lead' THEN id END) AS lead,
                COUNT(CASE WHEN type = 'unsubscribe' THEN id END) AS unsubscribe
            SQL)->groupBy('country')->orderBy('country')->get();

            return response()->json($countries->map(fn (WorkspaceDropVisitor $visitor): array => [
                'country' => $visitor->country,
                'open' => (int) $visitor->open,
                'click' => (int) $visitor->click,
                'lead' => (int) $visitor->lead,
                'unsubscribe' => (int) $visitor->unsubscribe,
            ])->all());
        }

        $filters = $request->validate([
            'draw' => ['sometimes', 'integer', 'min:0'],
            'start' => ['sometimes', 'integer', 'min:0'],
            'length' => ['sometimes', 'integer', 'in:20,50,100'],
            'search.value' => ['sometimes', 'nullable', 'string', 'max:200'],
            'order.0.column' => ['sometimes', 'integer', 'min:0', 'max:11'],
            'order.0.dir' => ['sometimes', Rule::in(['asc', 'desc'])],
        ]);
        $columns = ['id', 'type', 'email_id', 'ip', 'country', 'state', 'city', 'device', 'platform', 'browser', 'is_mobile', 'created_at'];
        $recordsTotal = (clone $visitors)->count();
        $search = trim((string) ($filters['search']['value'] ?? ''));
        $filtered = clone $visitors;
        if ($search !== '') {
            $filtered->where(function (Builder $query) use ($search): void {
                foreach (['type', 'email_id', 'ip', 'country', 'state', 'city', 'device', 'platform', 'browser', 'created_at'] as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }
        $recordsFiltered = (clone $filtered)->count();
        $orderIndex = (int) ($filters['order'][0]['column'] ?? 0);
        $orderColumn = $columns[$orderIndex] ?? 'id';
        $orderDirection = $filters['order'][0]['dir'] ?? 'asc';
        $rows = $filtered->orderBy($orderColumn, $orderDirection)
            ->offset((int) ($filters['start'] ?? 0))
            ->limit((int) ($filters['length'] ?? 20))
            ->get(array_merge($columns, ['revenue']))
            ->map(fn (WorkspaceDropVisitor $visitor): array => [
                'id' => (int) $visitor->getKey(),
                'type' => $visitor->type,
                'revenue' => (float) $visitor->revenue,
                'email_id' => $visitor->email_id,
                'ip' => $visitor->ip,
                'country' => $visitor->country,
                'state' => $visitor->state,
                'city' => $visitor->city,
                'device' => $visitor->device,
                'platform' => $visitor->platform,
                'browser' => $visitor->browser,
                'is_mobile' => (bool) $visitor->is_mobile,
                'created_at' => $visitor->created_at?->toDateTimeString(),
            ])->all();

        return response()->json([
            'draw' => (int) ($filters['draw'] ?? 0),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows,
        ]);
    }

    private function dropFor(User $user, Workspace $workspace, int $dropId): WorkspaceDrop
    {
        return WorkspaceDrop::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereKey($dropId)
            ->when($workspace->pivot->role === 'operator', fn (Builder $query) => $query->where('user_id', $user->getKey()))
            ->firstOrFail();
    }

    private function workspaceFor(User $user, string $workspaceId): Workspace
    {
        $workspace = $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, self::ALLOWED_ROLES, true), 403);

        return $workspace;
    }
}
