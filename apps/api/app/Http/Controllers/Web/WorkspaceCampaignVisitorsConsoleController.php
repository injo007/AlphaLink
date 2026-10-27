<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkspaceCampaignVisitorsConsoleController extends Controller
{
    private const ALLOWED_ROLES = ['owner', 'admin', 'operator'];

    private const TABLE_COLUMNS = [
        'id', 'action', 'send_id', 'contact_id', 'revision_id', 'ip',
        'country', 'state', 'city', 'device', 'platform', 'browser', 'is_mobile', 'created_at',
    ];

    public function index(Request $request, string $workspaceId, string $campaignId): View|JsonResponse
    {
        $workspace = $this->authorizedWorkspace($request->user(), $workspaceId);
        $campaign = $workspace->campaigns()
            ->where('created_by', $request->user()->getKey())
            ->whereKey($campaignId)
            ->firstOrFail();

        if (! $request->wantsJson()) {
            $tableState = $request->validate([
                'q' => ['sometimes', 'nullable', 'string', 'max:200'],
                'start' => ['sometimes', 'integer', 'min:0'],
                'length' => ['sometimes', 'integer', Rule::in([20, 50, 100])],
                'order_index' => ['sometimes', 'integer', 'min:0', 'max:13'],
                'order_dir' => ['sometimes', Rule::in(['asc', 'desc'])],
            ]);
            $events = DB::table('campaign_engagement_events')
                ->where('workspace_id', $workspace->getKey())
                ->where('campaign_id', $campaign->getKey());
            $eventTotals = (clone $events)->selectRaw(<<<'SQL'
                COUNT(CASE WHEN action = 'open' THEN 1 END) AS opens,
                COUNT(CASE WHEN action = 'click' THEN 1 END) AS clicks,
                COUNT(CASE WHEN action = 'unsubscribe' THEN 1 END) AS unsubscribes,
                COUNT(CASE WHEN action = 'bot' THEN 1 END) AS bots
            SQL)->first();
            $leadTotals = DB::table('campaign_lead_conversions')
                ->where('workspace_id', $workspace->getKey())
                ->where('campaign_id', $campaign->getKey())
                ->selectRaw('COUNT(*) AS leads, COALESCE(SUM(revenue), 0) AS revenue')
                ->first();

            return view('console.campaigns.visitors', [
                'workspace' => $workspace,
                'campaign' => $campaign,
                'totals' => [
                    'opens' => (int) ($eventTotals?->opens ?? 0),
                    'clicks' => (int) ($eventTotals?->clicks ?? 0),
                    'leads' => (int) ($leadTotals?->leads ?? 0),
                    'revenue' => (float) ($leadTotals?->revenue ?? 0),
                    'unsubscribes' => (int) ($eventTotals?->unsubscribes ?? 0),
                    'bots' => (int) ($eventTotals?->bots ?? 0),
                ],
                'search' => trim((string) ($tableState['q'] ?? '')),
                'tableState' => [
                    'start' => (int) ($tableState['start'] ?? 0),
                    'length' => (int) ($tableState['length'] ?? 20),
                    'orderIndex' => (int) ($tableState['order_index'] ?? 0),
                    'orderDirection' => (string) ($tableState['order_dir'] ?? 'asc'),
                ],
            ]);
        }

        if ($request->boolean('chart')) {
            $countries = DB::query()->fromSub($this->visitorRows($workspace, $campaign), 'visitor_rows')
                ->select('country')
                ->selectRaw(<<<'SQL'
                    SUM(CASE WHEN action = 'open' THEN 1 ELSE 0 END) AS open,
                    SUM(CASE WHEN action = 'click' THEN 1 ELSE 0 END) AS click,
                    SUM(CASE WHEN action = 'lead' THEN 1 ELSE 0 END) AS lead,
                    SUM(CASE WHEN action = 'unsubscribe' THEN 1 ELSE 0 END) AS unsubscribe
                SQL)
                ->groupBy('country')
                ->get();

            return response()->json($countries->map(fn (object $row): array => [
                'country' => $row->country,
                'open' => (int) $row->open,
                'click' => (int) $row->click,
                'lead' => (int) $row->lead,
                'unsubscribe' => (int) $row->unsubscribe,
            ])->all());
        }

        $filters = $request->validate([
            'draw' => ['sometimes', 'integer', 'min:0'],
            'start' => ['sometimes', 'integer', 'min:0'],
            'length' => ['sometimes', 'integer', Rule::in([20, 50, 100])],
            'search.value' => ['sometimes', 'nullable', 'string', 'max:200'],
            'order.0.column' => ['sometimes', 'integer', 'min:0', 'max:13'],
            'order.0.dir' => ['sometimes', Rule::in(['asc', 'desc'])],
        ]);

        $records = DB::query()->fromSub($this->visitorRows($workspace, $campaign), 'visitor_rows');
        $recordsTotal = (clone $records)->count();
        $filtered = clone $records;
        $search = trim((string) ($filters['search']['value'] ?? $request->query('q', '')));
        if ($search !== '') {
            $filtered->where(function (Builder $query) use ($search): void {
                foreach (['id', 'action', 'send_id', 'contact_id', 'revision_id', 'ip', 'country', 'state', 'city', 'device', 'platform', 'browser'] as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        $recordsFiltered = (clone $filtered)->count();
        $orderColumn = self::TABLE_COLUMNS[(int) ($filters['order'][0]['column'] ?? 0)] ?? 'id';
        $orderDirection = $filters['order'][0]['dir'] ?? 'asc';
        $rows = $filtered->orderBy($orderColumn, $orderDirection)
            ->orderBy('id', 'asc')
            ->offset((int) ($filters['start'] ?? 0))
            ->limit((int) ($filters['length'] ?? 20))
            ->get(array_merge(self::TABLE_COLUMNS, ['revenue']))
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'type' => (string) $row->action,
                'revenue' => (float) ($row->revenue ?? 0),
                'send_id' => $row->send_id,
                'contact_id' => (string) $row->contact_id,
                'revision_id' => (string) $row->revision_id,
                'ip' => $row->ip,
                'country' => $row->country,
                'state' => $row->state,
                'city' => $row->city,
                'device' => $row->device,
                'platform' => $row->platform,
                'browser' => $row->browser,
                'is_mobile' => $row->is_mobile === null ? null : (bool) $row->is_mobile,
                'created_at' => $row->created_at,
            ])->all();

        return response()->json([
            'draw' => (int) ($filters['draw'] ?? 0),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows,
        ]);
    }

    private function visitorRows(Workspace $workspace, Campaign $campaign): Builder
    {
        $events = DB::table('campaign_engagement_events')
            ->where('workspace_id', $workspace->getKey())
            ->where('campaign_id', $campaign->getKey())
            ->select([
                'id', 'action', 'send_id', 'contact_id', 'revision_id', 'client_ip as ip',
                'country', 'state', 'city', 'device', 'platform', 'browser', 'is_mobile',
                DB::raw('CAST(NULL AS DECIMAL(16, 4)) AS revenue'),
                'created_at',
            ]);
        $leads = DB::table('campaign_lead_conversions')
            ->where('workspace_id', $workspace->getKey())
            ->where('campaign_id', $campaign->getKey())
            ->select([
                'id', DB::raw("'lead' AS action"),
                DB::raw('CAST(NULL AS VARCHAR(128)) AS send_id'),
                'contact_id', 'revision_id', 'conversion_ip as ip',
                'country', 'state', 'city',
                DB::raw('CAST(NULL AS VARCHAR(128)) AS device'),
                DB::raw('CAST(NULL AS VARCHAR(128)) AS platform'),
                DB::raw('CAST(NULL AS VARCHAR(128)) AS browser'),
                DB::raw('CAST(NULL AS BOOLEAN) AS is_mobile'),
                'revenue',
                'converted_at as created_at',
            ]);

        return $events->unionAll($leads);
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
