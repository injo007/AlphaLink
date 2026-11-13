<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\RunWorkspaceSpfComparison;
use App\Jobs\RunWorkspaceSpfScan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSpfRoot;
use App\Models\WorkspaceSpfScan;
use App\Models\WorkspaceSpfSearchResult;
use App\Models\WorkspaceSpfSearchRun;
use App\Services\Spf\AlphaLinkSpfDnsCheckMatcher;
use App\Services\Spf\AlphaLinkSpfScanActivity;
use App\Services\Spf\AlphaLinkSpfScanLauncher;
use App\Services\Spf\AlphaLinkSpfScanner;
use App\Services\Spf\SpfParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkspaceSpfScannerController extends Controller
{
    private bool $searchDnsCheckLimitReached = false;

    public function index(Request $request, string $workspace): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $roots = $workspace->id;
        $jsonArrayLength = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? 'JSON_LENGTH'
            : 'json_array_length';
        $inventory = WorkspaceSpfRoot::query()
            ->where('workspace_id', $roots)
            ->selectRaw("COALESCE(SUM(CASE WHEN hosts IS NULL THEN 0 ELSE {$jsonArrayLength}(hosts) END), 0) AS host_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN cidrs IS NULL THEN 0 ELSE {$jsonArrayLength}(cidrs) END), 0) AS cidr_count")
            ->first();
        $stats = [
            'roots' => WorkspaceSpfRoot::query()->where('workspace_id', $roots)->count(),
            'hosts' => (int) ($inventory?->host_count ?? 0),
            'cidrs' => (int) ($inventory?->cidr_count ?? 0),
        ];

        if ($request->wantsJson()) {
            return response()->json($request->boolean('detailed') ? $stats + [
                'ok' => WorkspaceSpfRoot::query()->where('workspace_id', $roots)->where('status', 'ok')->count(),
                'no_spf' => WorkspaceSpfRoot::query()->where('workspace_id', $roots)->where('status', 'no_spf')->count(),
                'error' => WorkspaceSpfRoot::query()->where('workspace_id', $roots)->where('status', 'error')->count(),
            ] : $stats);
        }

        $maxWorkers = min(16, max(1, (int) config('spf.max_workers', 8)));
        $maxUploadBytes = max(1, (int) config('spf.max_upload_kilobytes', 1_048_576)) * 1024;

        return view('console.tools.spf-scanner.index', compact('workspace', 'stats', 'maxWorkers', 'maxUploadBytes'));
    }

    public function domains(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $search = strtolower(trim((string) $request->query('search', '')));
        $perPage = in_array((int) $request->query('per_page', 25), [25, 50, 100], true) ? (int) $request->query('per_page', 25) : 25;
        $sort = in_array($request->query('sort'), ['id', 'domain', 'status', 'scanned_at'], true) ? $request->query('sort') : 'id';
        $direction = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $query = WorkspaceSpfRoot::query()->where('workspace_id', $workspace->id)
            ->when($search !== '', fn ($builder) => $builder->where('domain', 'like', $search.'%'))
            ->orderBy($sort, $direction);
        $domains = $query->paginate($perPage)->withQueryString();

        return view('console.tools.spf-scanner.domains', compact('workspace', 'domains', 'search', 'perPage', 'sort', 'direction'));
    }

    public function records(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate(['id' => ['required', 'integer', 'min:1']]);
        $root = WorkspaceSpfRoot::query()->where('workspace_id', $workspace->id)->find($data['id']);
        if (! $root) {
            return response()->json(['error' => 'Records not found'], 404);
        }

        return response()->json([
            'record' => SpfParser::normalizeRecord($root->record),
            'formatted' => SpfParser::formatRecord($root->record),
            'hosts' => $root->hosts ?? [],
        ]);
    }

    public function export(Request $request, string $workspace): StreamedResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $search = strtolower(trim((string) $request->query('search', '')));
        $query = WorkspaceSpfRoot::query()->where('workspace_id', $workspace->id)
            ->when($search !== '', fn ($builder) => $builder->where('domain', 'like', $search.'%'))
            ->orderBy('id');

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['id', 'domain', 'status', 'scanned_at', 'record']);
            foreach ($query->cursor() as $row) {
                fputcsv($output, [$row->id, $row->domain, $row->status, $row->scanned_at?->toISOString(), $row->record]);
            }
            fclose($output);
        }, 'spf_roots_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function search(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate(['ips' => ['required', 'array', 'min:1', 'max:100'], 'ips.*' => ['required', 'string', 'ip']]);
        $ips = array_values(array_unique($data['ips']));

        if ($request->hasAny(['draw', 'start', 'length', 'search'])) {
            return response()->json($this->dataTableSearchResponse($request, $workspace, $ips));
        }

        $rows = [];
        $limit = (int) config('spf.max_search_table_rows', 20_000);
        $truncated = false;
        foreach ($this->searchRows($workspace, $ips) as $row) {
            if (count($rows) >= $limit) {
                $truncated = true;

                continue;
            }
            $rows[] = $row;
        }

        return response()->json([
            'data' => $rows,
            'truncated' => $truncated,
            'dns_checks_limited' => $this->searchDnsCheckLimitReached,
        ]);
    }

    public function searchExport(Request $request, string $workspace): StreamedResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate(['ips' => ['required', 'array', 'min:1', 'max:100'], 'ips.*' => ['required', 'string', 'ip']]);
        $ips = array_values(array_unique($data['ips']));

        return response()->streamDownload(function () use ($workspace, $ips): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['matched_ip', 'root', 'include', 'network']);
            foreach ($this->searchRows($workspace, $ips) as $row) {
                fputcsv($output, [$row['matched_ip'], $row['root'], $row['include'], $row['network']]);
            }
            fclose($output);
        }, 'spf_search_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function createSearchRun(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate([
            'ips' => ['required', 'array', 'min:1', 'max:100'],
            'ips.*' => ['required', 'string', 'ip'],
        ]);
        $ips = array_values(array_unique($data['ips']));
        $run = WorkspaceSpfSearchRun::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'ips' => $ips,
            'status' => WorkspaceSpfSearchRun::STATUS_PENDING,
            'stage' => 'queued',
            'total_roots' => WorkspaceSpfRoot::query()->where('workspace_id', $workspace->id)->count(),
        ]);

        try {
            RunWorkspaceSpfComparison::dispatch((int) $run->getKey());
        } catch (\Throwable $exception) {
            report($exception);
            $run->update([
                'status' => WorkspaceSpfSearchRun::STATUS_FAILED,
                'stage' => 'failed',
                'failure' => 'The comparison could not be queued. Check queue health and try again.',
                'ended_at' => now(),
            ]);

            return response()->json(['error' => $run->failure], 503);
        }

        return response()->json(['id' => $run->getKey(), 'status' => $run->status], 202);
    }

    public function searchRunStatus(Request $request, string $workspace, WorkspaceSpfSearchRun $run): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless((string) $run->workspace_id === (string) $workspace->id, 404);
        $heartbeatAge = $run->heartbeat_at?->diffInSeconds(now());
        $queueAge = $run->status === WorkspaceSpfSearchRun::STATUS_PENDING ? $run->created_at?->diffInSeconds(now()) : null;
        $health = match (true) {
            $run->status === WorkspaceSpfSearchRun::STATUS_PENDING && $queueAge !== null && $queueAge > 60 => 'queued_overdue',
            $run->status === WorkspaceSpfSearchRun::STATUS_PENDING => 'queued',
            $run->status !== WorkspaceSpfSearchRun::STATUS_PROCESSING => $run->status,
            $heartbeatAge === null => 'no_heartbeat',
            $heartbeatAge > 90 => 'stale_heartbeat',
            default => 'active',
        };

        return response()->json($run->toArray() + [
            'progress_total' => $run->total_roots,
            'progress_display' => sprintf('%d / %d roots', $run->progress, $run->total_roots),
            'heartbeat_age_seconds' => $heartbeatAge,
            'queue_age_seconds' => $queueAge,
            'health' => $health,
        ]);
    }

    public function searchRunResults(Request $request, string $workspace, WorkspaceSpfSearchRun $run): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless((string) $run->workspace_id === (string) $workspace->id, 404);
        $draw = max(0, (int) $request->query('draw', 0));
        $start = max(0, (int) $request->query('start', 0));
        $length = min(250, max(1, (int) $request->query('length', 200)));
        $search = strtolower(trim((string) $request->query('search.value', '')));
        $order = $request->query('order.0', []);
        $column = match ((int) data_get($order, 'column', 1)) {
            0 => 'matched_ip',
            2 => 'include',
            3 => 'network',
            default => 'root',
        };
        $direction = strtolower((string) data_get($order, 'dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $baseQuery = WorkspaceSpfSearchResult::query()->where('search_run_id', $run->getKey());
        $recordsTotal = (clone $baseQuery)->count();
        $query = (clone $baseQuery)->when($search !== '', function ($query) use ($search): void {
            $query->where(function ($query) use ($search): void {
                $query->where('root', 'like', $search.'%')->orWhere('include', 'like', $search.'%');
            });
        });
        $recordsFiltered = (clone $query)->count();
        $rows = $query->orderBy($column, $direction)->orderBy('id')->offset($start)->limit($length)->get([
            'root_id', 'matched_ip', 'root', 'include', 'network',
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'exactMatches' => $recordsFiltered,
            'data' => $rows,
        ]);
    }

    public function searchRunExport(Request $request, string $workspace, WorkspaceSpfSearchRun $run): StreamedResponse|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless((string) $run->workspace_id === (string) $workspace->id, 404);
        if ($run->status !== WorkspaceSpfSearchRun::STATUS_COMPLETE) {
            return response()->json(['error' => 'Wait for the comparison to finish before exporting its results.'], 409);
        }

        $rows = WorkspaceSpfSearchResult::query()->where('search_run_id', $run->getKey())->orderBy('id');

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['matched_ip', 'root', 'include', 'network']);
            foreach ($rows->cursor() as $row) {
                fputcsv($output, [$row->matched_ip, $row->root, $row->include, $row->network]);
            }
            fclose($output);
        }, 'spf_comparison_'.$run->getKey().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function cancelSearchRun(Request $request, string $workspace, WorkspaceSpfSearchRun $run): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless((string) $run->workspace_id === (string) $workspace->id, 404);
        if (! in_array($run->status, [WorkspaceSpfSearchRun::STATUS_PENDING, WorkspaceSpfSearchRun::STATUS_PROCESSING], true)) {
            return response()->json(['error' => 'The comparison is no longer running.'], 409);
        }
        $run->update([
            'status' => WorkspaceSpfSearchRun::STATUS_CANCELLED,
            'stage' => 'cancelled',
            'ended_at' => now(),
        ]);

        return response()->json(['message' => 'Comparison cancelled. The worker will stop at its next checkpoint.']);
    }

    public function upload(Request $request, string $workspace, AlphaLinkSpfScanLauncher $launcher, AlphaLinkSpfScanActivity $activity): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $maxWorkers = min(16, max(1, (int) config('spf.max_workers', 8)));
        $maxUploadKilobytes = max(1, (int) config('spf.max_upload_kilobytes', 1_048_576));
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:txt', 'max:'.$maxUploadKilobytes],
            'batch_size' => ['required', 'integer', 'min:1', 'max:1000'],
            'workers' => ['sometimes', 'integer', 'min:1', 'max:'.$maxWorkers],
            'partition_count' => ['sometimes', 'integer', 'in:1,4,5'],
            'skip_existing' => ['sometimes', 'boolean'],
        ]);

        $path = $request->file('file')->store('spf-scans', 'local');
        $scan = WorkspaceSpfScan::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'file_path' => $path,
            'status' => WorkspaceSpfScan::STATUS_PENDING,
            'batch_size' => $data['batch_size'],
            'workers' => $data['workers'] ?? $maxWorkers,
            'partition_count' => $data['partition_count'] ?? 1,
            'skip_existing' => $request->boolean('skip_existing'),
        ]);

        if (! $launcher->start($scan)) {
            Storage::disk('local')->delete($path);

            return back()->withErrors(['file' => 'The SPF scan process could not be started.'])->withInput();
        }
        $activity->recordEvent((int) $scan->getKey(), 'info', 'scan_queued', 'SPF scan queued for background validation and processing.', [
            'partition_count' => (int) $scan->partition_count,
            'workers' => (int) $scan->workers,
            'batch_size' => (int) $scan->batch_size,
        ]);

        return back()->with('status', 'SPF scan queued. The file will be validated in the background, then processed with up to '.$scan->workers.' DNS workers across '.$scan->partition_count.' '.((int) $scan->partition_count === 1 ? 'file' : 'equal parts').'.');
    }

    public function manual(Request $request, string $workspace, AlphaLinkSpfScanner $scanner): JsonResponse
    {
        $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate(['domains' => ['required', 'array', 'min:1', 'max:50'], 'domains.*' => ['nullable', 'string', 'max:253']]);
        $domains = array_values(array_unique(array_filter(array_map(
            static fn ($domain) => SpfParser::normalizeDomain(rtrim(trim((string) $domain), '.')),
            $data['domains'],
        ), fn ($domain) => SpfParser::isHostname($domain))));
        $rows = [];
        foreach ($domains as $domain) {
            $scan = $scanner->scanDomain($domain);
            foreach ($scan['hosts'] as $host) {
                if ($host['record'] !== null) {
                    $rows[] = ['root' => $domain, 'domain' => $host['domain'], 'spf' => SpfParser::formatRecord($host['record'])];
                }
            }
        }

        return response()->json($rows);
    }

    public function volume(Request $request, string $workspace): JsonResponse
    {
        $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate(['domain' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i']]);
        $result = SpfParser::domainVolume($data['domain']);
        if (! is_array($result) || $result === []) {
            return response()->json(['error' => 'No data found'], 400);
        }

        return response()->json(isset($result['error']) ? ['error' => $result['error']] : $result, isset($result['error']) ? 400 : 200);
    }

    public function scans(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $scans = WorkspaceSpfScan::query()->where('workspace_id', $workspace->id)->orderByDesc('id')->limit(100)->get([
            'id', 'pid', 'status', 'stage', 'current_domain', 'heartbeat_at', 'progress_at', 'total_lines', 'valid_domains', 'progress', 'batch_size', 'workers', 'partition_count', 'file_size_bytes', 'preparation_bytes', 'skip_existing', 'created_at', 'started_at', 'total_found', 'ended_at', 'failure',
        ])->map(function (WorkspaceSpfScan $scan): array {
            $progressTotal = $scan->valid_domains > 0 ? $scan->valid_domains : $scan->total_lines;
            $heartbeatAge = $scan->heartbeat_at?->diffInSeconds(now());
            $progressAge = $scan->progress_at?->diffInSeconds(now());
            $queueAge = $scan->status === WorkspaceSpfScan::STATUS_PENDING ? $scan->created_at?->diffInSeconds(now()) : null;
            $preparationPercent = $scan->file_size_bytes > 0
                ? min(100, (int) floor($scan->preparation_bytes / $scan->file_size_bytes * 100))
                : 0;
            $health = match (true) {
                $scan->status === WorkspaceSpfScan::STATUS_PENDING && in_array($scan->stage, ['preparing', 'validating', 'partitioning', 'preparing_ranges'], true) => 'preparing',
                $scan->status === WorkspaceSpfScan::STATUS_PENDING && $queueAge !== null && $queueAge > 60 => 'queued_overdue',
                $scan->status === WorkspaceSpfScan::STATUS_PENDING => 'queued',
                $scan->status !== WorkspaceSpfScan::STATUS_SCANNING => $scan->status,
                $heartbeatAge === null => 'no_heartbeat',
                $heartbeatAge > 90 => 'stale_heartbeat',
                $progressAge !== null && $progressAge > 180 => 'no_recent_progress',
                default => 'active',
            };

            return $scan->toArray() + [
                'progress_total' => $progressTotal,
                'progress_display' => $progressTotal > 0
                    ? sprintf('%d / %d (%.2f%%)', $scan->progress, $progressTotal, min(100, $scan->progress / $progressTotal * 100))
                    : '0%',
                'heartbeat_age_seconds' => $heartbeatAge,
                'progress_age_seconds' => $progressAge,
                'queue_age_seconds' => $queueAge,
                'preparation_percent' => $preparationPercent,
                'health' => $health,
            ];
        });

        return response()->json(['data' => $scans]);
    }

    public function activity(Request $request, string $workspace, WorkspaceSpfScan $scan): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless((string) $scan->workspace_id === (string) $workspace->id, 404);

        $workers = $scan->workerActivity()->get()->map(fn ($worker): array => [
            'worker' => (int) $worker->worker_index + 1,
            'status' => $worker->status,
            'stage' => $worker->stage,
            'current_domain' => $worker->current_domain,
            'domains_processed' => (int) $worker->domains_processed,
            'spf_records_found' => (int) $worker->spf_records_found,
            'dns_errors' => (int) $worker->dns_errors,
            'last_lookup_duration_ms' => $worker->last_lookup_duration_ms,
            'last_batch_duration_ms' => $worker->last_batch_duration_ms,
            'heartbeat_at' => $worker->heartbeat_at?->toIso8601String(),
            'heartbeat_age_seconds' => $worker->heartbeat_at?->diffInSeconds(now()),
        ]);
        $events = $scan->events()->orderByDesc('id')->limit(100)->get([
            'id', 'level', 'event_type', 'message', 'metadata', 'created_at',
        ])->reverse()->values()->map(fn ($event): array => [
            'id' => (int) $event->id,
            'level' => $event->level,
            'event_type' => $event->event_type,
            'message' => $event->message,
            'metadata' => $event->metadata ?? [],
            'created_at' => $event->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'scan' => [
                'id' => (int) $scan->getKey(),
                'status' => $scan->status,
                'progress' => (int) $scan->progress,
                'total' => (int) $scan->valid_domains,
                'stage' => $scan->stage,
                'preparation_percent' => $scan->file_size_bytes > 0 ? min(100, (int) floor($scan->preparation_bytes / $scan->file_size_bytes * 100)) : 0,
                'spf_records_found' => (int) $scan->total_found,
                'updated_at' => $scan->heartbeat_at?->toIso8601String(),
            ],
            'workers' => $workers,
            'events' => $events,
            'telemetry_available' => $workers->isNotEmpty() || $events->isNotEmpty(),
        ]);
    }

    public function kill(Request $request, string $workspace, WorkspaceSpfScan $scan, AlphaLinkSpfScanActivity $activity): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless((string) $scan->workspace_id === (string) $workspace->id, 404);
        if (! in_array($scan->status, [WorkspaceSpfScan::STATUS_PENDING, WorkspaceSpfScan::STATUS_SCANNING, WorkspaceSpfScan::STATUS_PAUSED], true)) {
            return response()->json(['error' => 'Scan is not running'], 400);
        }
        $wasPausedAndIdle = $scan->status === WorkspaceSpfScan::STATUS_PAUSED && $scan->stage === 'paused';
        $scan->update([
            'status' => WorkspaceSpfScan::STATUS_KILLED,
            'stage' => 'cancelled',
            'ended_at' => now(),
            'current_domain' => null,
        ]);
        $activity->recordEvent((int) $scan->getKey(), 'warning', 'scan_cancel_requested', 'Cancellation was requested; active DNS lookups will finish before workers exit.');
        if ($wasPausedAndIdle) {
            $scan->refresh();
            $activity->finishScan($scan, WorkspaceSpfScan::STATUS_KILLED);
            Storage::disk('local')->delete(array_values(array_unique(array_merge([$scan->file_path], $scan->partition_paths ?? []))));
            Storage::disk('local')->deleteDirectory('spf-scans/'.$scan->getKey().'-parts');
        }

        return response()->json(['message' => 'Scan cancelled. Active DNS lookups will finish before their workers exit.']);
    }

    public function pause(Request $request, string $workspace, WorkspaceSpfScan $scan, AlphaLinkSpfScanActivity $activity): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless((string) $scan->workspace_id === (string) $workspace->id, 404);
        $paused = WorkspaceSpfScan::query()
            ->whereKey($scan->getKey())
            ->where('workspace_id', $workspace->id)
            ->where('status', WorkspaceSpfScan::STATUS_SCANNING)
            ->where('stage', '!=', 'resuming')
            ->update([
                'status' => WorkspaceSpfScan::STATUS_PAUSED,
                'stage' => 'pausing',
                'heartbeat_at' => now(),
            ]);
        if ($paused === 0) {
            return response()->json(['error' => 'Only an active domain scan can be paused.'], 409);
        }

        $activity->recordEvent((int) $scan->getKey(), 'warning', 'scan_pause_requested', 'Pause requested. Active DNS batches will finish and each worker will save its next domain checkpoint.');

        return response()->json(['message' => 'Pause requested. Active DNS batches will finish before the scan pauses.']);
    }

    public function resume(Request $request, string $workspace, WorkspaceSpfScan $scan, AlphaLinkSpfScanActivity $activity): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless((string) $scan->workspace_id === (string) $workspace->id, 404);
        $resumed = WorkspaceSpfScan::query()
            ->whereKey($scan->getKey())
            ->where('workspace_id', $workspace->id)
            ->where('status', WorkspaceSpfScan::STATUS_PAUSED)
            ->where('stage', 'paused')
            ->whereNull('pid')
            ->update([
                'status' => WorkspaceSpfScan::STATUS_SCANNING,
                'stage' => 'resuming',
                'heartbeat_at' => now(),
                'progress_at' => now(),
                'ended_at' => null,
                'failure' => null,
            ]);
        if ($resumed === 0) {
            return response()->json(['error' => 'The scan is still pausing or is no longer available to resume.'], 409);
        }

        try {
            RunWorkspaceSpfScan::dispatch((int) $scan->getKey(), max(1, (int) $scan->workers), true);
        } catch (\Throwable $exception) {
            report($exception);
            WorkspaceSpfScan::query()
                ->whereKey($scan->getKey())
                ->where('status', WorkspaceSpfScan::STATUS_SCANNING)
                ->where('stage', 'resuming')
                ->update([
                    'status' => WorkspaceSpfScan::STATUS_PAUSED,
                    'stage' => 'paused',
                    'heartbeat_at' => now(),
                ]);

            return response()->json(['error' => 'The scan could not be queued to resume. It remains paused.'], 503);
        }

        $activity->recordEvent((int) $scan->getKey(), 'info', 'scan_resume_requested', 'Resume requested. Workers will continue from their last completed domain batches.');

        return response()->json(['message' => 'Resume requested. The scan is continuing from its saved checkpoints.']);
    }

    /** @return \Generator<int, array{root_id: int|string, matched_ip: string, root: string, include: string, network: string, record?: ?string}> */
    private function searchRows(Workspace $workspace, array $ips): \Generator
    {
        $matcher = app(AlphaLinkSpfDnsCheckMatcher::class);
        $this->searchDnsCheckLimitReached = false;
        $remainingDnsChecks = null;
        $roots = WorkspaceSpfRoot::query()
            ->where('workspace_id', $workspace->id)
            ->select(['id', 'domain', 'record', 'hosts']);

        foreach ($roots->lazyById(100) as $root) {
            $rows = [];
            foreach ($ips as $ip) {
                foreach ($root->hosts ?? [] as $host) {
                    foreach ($host['cidrs'] ?? [] as $cidr) {
                        if (SpfParser::ipInCidr($ip, $cidr)) {
                            $rows[] = ['root_id' => $root->id, 'matched_ip' => $ip, 'root' => $root->domain, 'include' => $host['domain'] ?? $root->domain, 'network' => $cidr];
                        }
                    }
                }
            }
            foreach ($matcher->findMatches([$root], $ips, $remainingDnsChecks) as $row) {
                $rows[] = $row;
            }

            $uniqueRows = [];
            foreach ($rows as $row) {
                $key = $row['matched_ip'].'|'.$row['root'].'|'.$row['include'];
                $uniqueRows[$key] ??= $row;
            }
            foreach ($uniqueRows as $row) {
                yield $row;
            }
        }

        $this->searchDnsCheckLimitReached = $matcher->dnsCheckLimitReached();
    }

    private function dataTableSearchResponse(Request $request, Workspace $workspace, array $ips): array
    {
        $search = strtolower(trim((string) data_get($request->input('search', []), 'value', '')));
        $order = $request->input('order.0', []);
        $orderColumn = (int) ($order['column'] ?? 1);
        $orderDirection = strtolower((string) ($order['dir'] ?? 'asc')) === 'desc' ? -1 : 1;
        $orderKey = match ($orderColumn) {
            0 => 'matched_ip',
            2 => 'include',
            3 => 'network',
            default => 'root',
        };
        $limit = max(1000, (int) config('spf.max_search_table_rows', 20_000));
        $draw = max(0, (int) $request->input('draw', 0));
        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 200);
        $pageLength = min(250, max(1, $length));
        $allCount = 0;
        $filteredCount = 0;
        $candidates = [];

        foreach ($this->searchRows($workspace, $ips) as $row) {
            $allCount++;
            if ($search !== '' && ! str_starts_with(strtolower((string) $row['root']), $search)
                && ! str_starts_with(strtolower((string) $row['include']), $search)) {
                continue;
            }

            $filteredCount++;
            $candidates[] = $row;
            if (count($candidates) >= $limit * 2) {
                $this->sortSearchRows($candidates, $orderKey, $orderDirection);
                $candidates = array_slice($candidates, 0, $limit);
            }
        }

        $this->sortSearchRows($candidates, $orderKey, $orderDirection);
        $data = array_slice($candidates, $start, $pageLength);
        $visibleCount = min($filteredCount, $limit);

        return [
            'draw' => $draw,
            'recordsTotal' => min($allCount, $limit),
            'recordsFiltered' => $visibleCount,
            'exactMatches' => $filteredCount,
            'truncated' => $filteredCount > $limit,
            'dns_checks_limited' => $this->searchDnsCheckLimitReached,
            'data' => $data,
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function sortSearchRows(array &$rows, string $orderKey, int $direction): void
    {
        usort($rows, static function (array $left, array $right) use ($direction, $orderKey): int {
            $primary = strnatcasecmp((string) $left[$orderKey], (string) $right[$orderKey]);
            if ($primary !== 0) {
                return $primary * $direction;
            }

            $secondary = strnatcasecmp((string) $left['include'], (string) $right['include']);
            if ($secondary !== 0) {
                return $secondary * $direction;
            }

            return strnatcasecmp((string) $left['matched_ip'], (string) $right['matched_ip']) * $direction;
        });
    }

    private function workspaceFor(?User $user, string $workspaceId): Workspace
    {
        abort_unless($user, 401);

        return $user->workspaces()->where('workspaces.status', 'active')->whereKey($workspaceId)->firstOrFail();
    }
}
