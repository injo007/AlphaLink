<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceContactSubmission;
use App\Models\WorkspaceSubscriber;
use App\Services\AppErrorLogReader;
use App\Services\AppHealthReport;
use App\Services\AppLogArchiveService;
use App\Services\OpenObserve\ErrorIntelligenceService;
use App\Services\Telegram\TelegramAlertService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\HorizonServiceProvider;

class MiscellaneousConsoleController extends Controller
{
    public function appHealth(Request $request, string $workspace, AppHealthReport $health): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin'], true), 403);

        $report = $request->boolean('refresh') ? $health->run() : $health->latest();

        if ($request->has('json') || $request->wantsJson()) {
            return response()->json($report);
        }

        return view('console.miscellaneous.app-health', compact('workspace', 'report'));
    }

    public function systemMetrics(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin'], true), 403);

        $meminfo = is_readable('/proc/meminfo') ? (string) file_get_contents('/proc/meminfo') : '';
        preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $meminfo, $totalMatch);
        preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $meminfo, $availMatch);
        $memTotalKb = (int) ($totalMatch[1] ?? 0);
        $memAvailKb = (int) ($availMatch[1] ?? 0);
        $memUsedKb = max(0, $memTotalKb - $memAvailKb);
        $ramPercent = $memTotalKb > 0 ? (int) max(0, min(100, round(($memUsedKb / $memTotalKb) * 100))) : 0;

        $readCpuSample = static function (): ?array {
            if (! is_readable('/proc/stat')) {
                return null;
            }
            $stat = (string) file_get_contents('/proc/stat');
            if (preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stat, $m) !== 1) {
                return null;
            }
            $idle = (int) $m[4] + (int) $m[5];
            $total = array_sum(array_map('intval', array_slice($m, 1, 8)));

            return ['idle' => $idle, 'total' => $total];
        };

        $previous = Cache::get('alphalink:system-metrics:cpu');
        if ($request->boolean('refresh') || ! is_array($previous)) {
            $first = $readCpuSample();
            usleep(120000);
            $current = $readCpuSample();
            if ($first !== null && $current !== null) {
                $previous = $first;
            }
        } else {
            $current = $readCpuSample();
        }

        if ($current !== null) {
            Cache::put('alphalink:system-metrics:cpu', $current, now()->addMinutes(5));
        }

        $loadAvg = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        $load1m = is_array($loadAvg) ? round((float) ($loadAvg[0] ?? 0), 2) : 0.0;

        $cpuPercent = null;
        if (is_array($previous) && $current !== null) {
            $deltaTotal = ($current['total'] ?? 0) - ($previous['total'] ?? 0);
            $deltaIdle = ($current['idle'] ?? 0) - ($previous['idle'] ?? 0);
            if ($deltaTotal > 0) {
                $cpuPercent = (int) max(0, min(100, round((($deltaTotal - $deltaIdle) / $deltaTotal) * 100)));
            }
        }
        if ($cpuPercent === null) {
            $cpuInfo = is_readable('/proc/cpuinfo') ? (string) file_get_contents('/proc/cpuinfo') : '';
            $cores = max(1, substr_count($cpuInfo, 'processor'));
            $cpuPercent = (int) max(0, min(100, round(($load1m / $cores) * 100)));
        }

        return response()->json([
            'cpu_percent' => $cpuPercent,
            'ram_percent' => $ramPercent,
            'ram_used_gb' => round($memUsedKb / 1048576, 1),
            'ram_total_gb' => round($memTotalKb / 1048576, 1),
            'load_1m' => $load1m,
        ]);
    }

    public function appErrors(Request $request, string $workspace, AppErrorLogReader $logs, ErrorIntelligenceService $service, TelegramAlertService $telegram): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin'], true), 403);

        $filters = [
            'search' => $request->input('search'),
            'level' => $request->input('level'),
            'module' => $request->input('module'),
            'time_range' => $request->input('time_range'),
            'grouped' => $request->input('grouped', '1'),
            'page' => max(1, (int) $request->input('page', 1)),
            'per_page' => min(100, max(10, (int) $request->input('per_page', 25))),
        ];

        $intelligence = $service->getIntelligence($filters);

        if ($request->wantsJson() || $request->has('json')) {
            return response()->json($intelligence);
        }

        $entries = $logs->entries();
        $telegramConfig = $telegram->getConfigForWorkspace($workspace);

        return view('console.miscellaneous.app-errors', compact('workspace', 'entries', 'intelligence', 'filters', 'telegramConfig'));
    }

    public function appErrorsSync(Request $request, string $workspace, ErrorIntelligenceService $service): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin'], true), 403);

        $limit = min(500, max(10, (int) $request->input('limit', 200)));
        $result = $service->syncToOpenObserve($limit);

        return response()->json($result);
    }

    public function appErrorsArchiveLogs(Request $request, string $workspace, AppLogArchiveService $archiveService): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin'], true), 403);

        $result = $archiveService->archiveAndRotate(5);

        return response()->json($result);
    }

    public function appErrorsTelegramSend(Request $request, string $workspace, TelegramAlertService $telegram): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin'], true), 403);

        $validated = $request->validate([
            'channel_type' => ['required', 'in:it,main,both'],
            'custom_message' => ['nullable', 'string', 'max:1000'],
            'error_summary' => ['required', 'string', 'max:500'],
            'level' => ['nullable', 'string', 'max:32'],
            'module' => ['nullable', 'string', 'max:64'],
            'file' => ['nullable', 'string', 'max:255'],
            'request_id' => ['nullable', 'string', 'max:128'],
            'fingerprint' => ['nullable', 'string', 'max:64'],
            'occurrences_count' => ['nullable', 'integer', 'min:1'],
            'include_summary' => ['nullable', 'boolean'],
            'include_request_id' => ['nullable', 'boolean'],
            'include_link' => ['nullable', 'boolean'],
        ]);

        $result = $telegram->sendCustomAlert(
            $workspace,
            $validated,
            $request->user()?->getKey(),
            $request->attributes->get('request_id')
        );

        return response()->json($result);
    }

    public function appErrorsTelegramConfig(Request $request, string $workspace, TelegramAlertService $telegram): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin'], true), 403);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'target_channel' => ['required', 'in:it,main,both'],
            'severity_threshold' => ['required', 'in:CRITICAL,ERROR'],
            'cooldown_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'custom_template' => ['nullable', 'string', 'max:1500'],
            'custom_prefix' => ['nullable', 'string', 'max:255'],
        ]);

        $config = $telegram->saveRules($workspace, $validated);

        return response()->json(['success' => true, 'config' => $config]);
    }

    public function appErrorsTelegramTest(Request $request, string $workspace, TelegramAlertService $telegram): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin'], true), 403);

        $validated = $request->validate([
            'channel_type' => ['nullable', 'in:it,main,both'],
        ]);

        $result = $telegram->sendTestPing($workspace, $validated['channel_type'] ?? 'it');

        return response()->json($result);
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
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin'], true), 403);
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
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin', 'privacy_officer'], true), 403);

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
