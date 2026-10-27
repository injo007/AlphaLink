<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceContactSubmission;
use App\Models\WorkspaceSubscriber;
use App\Services\AppErrorLogReader;
use App\Services\AppHealthReport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\HorizonServiceProvider;

class MiscellaneousConsoleController extends Controller
{
    public function appHealth(Request $request, string $workspace, AppHealthReport $health): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin'], true), 403);

        $report = $health->latest();

        if ($request->has('json') || $request->wantsJson()) {
            return response()->json($report);
        }

        return view('console.miscellaneous.app-health', compact('workspace', 'report'));
    }

    public function appErrors(Request $request, string $workspace, AppErrorLogReader $logs): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin'], true), 403);

        $entries = $logs->entries();

        return view('console.miscellaneous.app-errors', compact('workspace', 'entries'));
    }

    public function subscribers(Request $request, string $workspace): View|JsonResponse
    {
        $workspace = $this->adminWorkspace($request->user(), $workspace);
        $columns = ['id', 'email', 'country', 'state', 'city', 'device', 'platform', 'browser', 'is_mobile', 'created_at'];

        if ($request->wantsJson() || $request->has('json')) {
            return $this->dataTable($request, $workspace->subscribers(), $columns, $columns);
        }

        return view('console.miscellaneous.subscribers', compact('workspace'));
    }

    public function contacts(Request $request, string $workspace): View|JsonResponse
    {
        $workspace = $this->adminWorkspace($request->user(), $workspace);
        $columns = ['id', 'full_name', 'email', 'phone', 'message', 'ip', 'country', 'state', 'city', 'device', 'platform', 'browser', 'is_mobile', 'created_at'];

        if ($request->wantsJson() || $request->has('json')) {
            return $this->dataTable($request, $workspace->contactSubmissions(), $columns, $columns);
        }

        return view('console.miscellaneous.contacts', compact('workspace'));
    }

    public function horizon(Request $request, string $workspace): View
    {
        $workspace = $this->adminWorkspace($request->user(), $workspace);
        $available = class_exists(HorizonServiceProvider::class) && Route::has('horizon.index');
        $connection = (string) config('queue.default', 'unknown');

        return view('console.miscellaneous.horizon', compact('workspace', 'available', 'connection'));
    }

    /**
     * @param  Relation<WorkspaceSubscriber|WorkspaceContactSubmission, *, *>  $query
     * @param  list<string>  $columns
     * @param  list<string>  $searchable
     */
    private function dataTable(Request $request, Relation $query, array $columns, array $searchable): JsonResponse
    {
        $recordsTotal = (clone $query)->count();
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search, $searchable): void {
                foreach ($searchable as $column) {
                    if ($column === 'id') {
                        if (ctype_digit($search)) {
                            $builder->orWhere('id', (int) $search);
                        }

                        continue;
                    }

                    if ($column === 'is_mobile') {
                        $lowered = strtolower($search);
                        if (in_array($lowered, ['yes', 'no', 'true', 'false'], true)) {
                            $builder->orWhere('is_mobile', in_array($lowered, ['yes', 'true'], true));
                        }

                        continue;
                    }

                    $builder->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        $recordsFiltered = (clone $query)->count();
        $orderIndex = max(0, (int) $request->input('order.0.column', 0));
        $orderColumn = (string) $request->input("columns.{$orderIndex}.data", 'id');
        if (! in_array($orderColumn, $columns, true)) {
            $orderColumn = 'id';
        }
        $direction = strtolower((string) $request->input('order.0.dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $start = max(0, (int) $request->input('start', 0));
        $requestedLength = (int) $request->input('length', 10);
        $length = $requestedLength === -1 ? max(1, $recordsFiltered) : min(100, max(1, $requestedLength));

        return response()->json([
            'draw' => max(0, (int) $request->input('draw', 0)),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $query->orderBy($orderColumn, $direction)
                ->offset($start)
                ->limit($length)
                ->get($columns),
        ]);
    }

    private function adminWorkspace(User $user, string $workspaceId): Workspace
    {
        $workspace = $this->workspaceFor($user, $workspaceId);
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin'], true), 403);

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
