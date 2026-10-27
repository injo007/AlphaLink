<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AudienceList;
use App\Models\ManagedServer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDrop;
use App\Models\WorkspaceDropVisitor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
