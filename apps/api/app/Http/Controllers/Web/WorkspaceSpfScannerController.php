<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSpfRoot;
use App\Models\WorkspaceSpfScan;
use App\Services\Spf\DmsSpfDnsCheckMatcher;
use App\Services\Spf\DmsSpfScanLauncher;
use App\Services\Spf\DmsSpfScanner;
use App\Services\Spf\SpfParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkspaceSpfScannerController extends Controller
{
    public function index(Request $request, string $workspace): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $roots = $workspace->id;
        $stats = [
            'roots' => WorkspaceSpfRoot::query()->where('workspace_id', $roots)->count(),
            'hosts' => WorkspaceSpfRoot::query()->where('workspace_id', $roots)->get(['hosts'])
                ->flatMap(fn (WorkspaceSpfRoot $row): array => collect($row->hosts ?? [])->pluck('domain')->all())->unique()->count(),
            'cidrs' => WorkspaceSpfRoot::query()->where('workspace_id', $roots)->get(['cidrs'])
                ->flatMap(fn (WorkspaceSpfRoot $row): array => $row->cidrs ?? [])->unique()->count(),
        ];

        if ($request->wantsJson()) {
            return response()->json($request->boolean('detailed') ? $stats + [
                'ok' => WorkspaceSpfRoot::query()->where('workspace_id', $roots)->where('status', 'ok')->count(),
                'no_spf' => WorkspaceSpfRoot::query()->where('workspace_id', $roots)->where('status', 'no_spf')->count(),
                'error' => WorkspaceSpfRoot::query()->where('workspace_id', $roots)->where('status', 'error')->count(),
            ] : $stats);
        }

        return view('console.tools.spf-scanner.index', compact('workspace', 'stats'));
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
        $rows = $this->searchRows($workspace, $ips);

        if ($request->hasAny(['draw', 'start', 'length', 'search'])) {
            return response()->json($this->dataTableSearchResponse($request, $rows));
        }

        return response()->json(['data' => $rows]);
    }

    public function searchExport(Request $request, string $workspace): StreamedResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate(['ips' => ['required', 'array', 'min:1', 'max:100'], 'ips.*' => ['required', 'string', 'ip']]);
        $ips = array_values(array_unique($data['ips']));
        $rows = $this->searchRows($workspace, $ips);

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['matched_ip', 'root', 'include', 'network']);
            foreach ($rows as $row) {
                fputcsv($output, [$row['matched_ip'], $row['root'], $row['include'], $row['network']]);
            }
            fclose($output);
        }, 'spf_search_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function upload(Request $request, string $workspace, DmsSpfScanLauncher $launcher): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:txt'],
            'batch_size' => ['required', 'integer', 'min:1', 'max:1000'],
            'workers' => ['sometimes', 'integer', 'min:1', 'max:8'],
            'skip_existing' => ['sometimes', 'boolean'],
        ]);
        $path = $request->file('file')->store('spf-scans', 'local');
        $scan = WorkspaceSpfScan::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'file_path' => $path,
            'status' => WorkspaceSpfScan::STATUS_PENDING,
            'batch_size' => $data['batch_size'],
            'workers' => $data['workers'] ?? 1,
            'skip_existing' => $request->boolean('skip_existing'),
        ]);

        if (! $launcher->start($scan)) {
            return back()->withErrors(['file' => 'The SPF scan process could not be started.'])->withInput();
        }

        return back()->with('status', 'SPF scan started. Progress updates appear in the Scans table.');
    }

    public function manual(Request $request, string $workspace, DmsSpfScanner $scanner): JsonResponse
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
            'id', 'pid', 'status', 'total_lines', 'progress', 'batch_size', 'workers', 'skip_existing', 'created_at', 'total_found', 'ended_at', 'failure',
        ])->map(function (WorkspaceSpfScan $scan): array {
            return $scan->toArray() + ['progress_display' => $scan->total_lines > 0
                ? sprintf('%d / %d (%.1f%%)', $scan->progress, $scan->total_lines, min(100, $scan->progress / $scan->total_lines * 100))
                : '0%'];
        });

        return response()->json(['data' => $scans]);
    }

    public function kill(Request $request, string $workspace, WorkspaceSpfScan $scan): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless((string) $scan->workspace_id === (string) $workspace->id, 404);
        if ($scan->status !== WorkspaceSpfScan::STATUS_SCANNING) {
            return response()->json(['error' => 'Scan is not running'], 400);
        }
        $scan->update(['status' => WorkspaceSpfScan::STATUS_KILLED]);

        return response()->json(['message' => 'Scan stopped. Workers will exit after their current DNS lookup.']);
    }

    private function searchRows(Workspace $workspace, array $ips): array
    {
        $roots = WorkspaceSpfRoot::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('id')
            ->get(['id', 'domain', 'record', 'hosts']);
        $rows = [];
        foreach ($roots as $root) {
            foreach ($ips as $ip) {
                foreach ($root->hosts ?? [] as $host) {
                    foreach ($host['cidrs'] ?? [] as $cidr) {
                        if (SpfParser::ipInCidr($ip, $cidr)) {
                            $rows[] = ['root_id' => $root->id, 'matched_ip' => $ip, 'root' => $root->domain, 'include' => $host['domain'] ?? $root->domain, 'network' => $cidr];
                        }
                    }
                }
            }
        }

        $rows = array_merge($rows, app(DmsSpfDnsCheckMatcher::class)->findMatches($roots, $ips));
        $uniqueRows = [];
        foreach ($rows as $row) {
            $key = $row['matched_ip'].'|'.$row['root'].'|'.$row['include'];
            $uniqueRows[$key] ??= $row;
        }

        return array_values($uniqueRows);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function dataTableSearchResponse(Request $request, array $rows): array
    {
        $search = strtolower(trim((string) data_get($request->input('search', []), 'value', '')));
        $filtered = $search === '' ? $rows : array_values(array_filter($rows, static fn (array $row): bool => str_starts_with(strtolower((string) $row['root']), $search)
            || str_starts_with(strtolower((string) $row['include']), $search)
        ));
        $rootsByInclude = [];
        foreach ($rows as $row) {
            $rootsByInclude[$row['include']][(string) $row['root_id']] = true;
        }
        $estimatedRootCount = $rootsByInclude === [] ? 0 : max(array_map('count', $rootsByInclude));

        $order = $request->input('order.0', []);
        $orderColumn = (int) ($order['column'] ?? 1);
        $orderDirection = strtolower((string) ($order['dir'] ?? 'asc')) === 'desc' ? -1 : 1;
        $orderKey = match ($orderColumn) {
            0 => 'matched_ip',
            2 => 'include',
            3 => 'network',
            default => 'root',
        };
        usort($filtered, static function (array $left, array $right) use ($orderDirection, $orderKey): int {
            $primary = strnatcasecmp((string) $left[$orderKey], (string) $right[$orderKey]);
            if ($primary !== 0) {
                return $primary * $orderDirection;
            }

            return strnatcasecmp((string) $left['include'], (string) $right['include']);
        });

        $draw = max(0, (int) $request->input('draw', 0));
        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 200);
        $page = $length < 0 ? $filtered : array_slice($filtered, $start, min(250, max(1, $length)));

        return [
            'draw' => $draw,
            'recordsTotal' => $estimatedRootCount,
            'recordsFiltered' => $estimatedRootCount,
            'estimated' => true,
            'data' => $page,
        ];
    }

    private function workspaceFor(?User $user, string $workspaceId): Workspace
    {
        abort_unless($user, 401);

        return $user->workspaces()->where('workspaces.status', 'active')->whereKey($workspaceId)->firstOrFail();
    }
}
