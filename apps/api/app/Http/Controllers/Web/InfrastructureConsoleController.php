<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\ManagedServer;
use App\Models\ServerProvider;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsBulkInstallEligibility;
use App\Services\Infrastructure\DmsBulkInstallTranscriptStore;
use App\Services\Infrastructure\HostProbeException;
use App\Services\Infrastructure\InfrastructureManager;
use App\Services\Infrastructure\PmtaCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InfrastructureConsoleController extends Controller
{
    public function __construct(private readonly InfrastructureManager $manager) {}

    public function index(Request $request, string $workspaceId, PmtaCatalog $catalog): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $servers = $workspace->managedServers()->orderByDesc('created_at')->get();
        $domains = $workspace->sendingDomains()->orderByDesc('created_at')->get();
        $plans = $workspace->installationPlans()->orderByDesc('created_at')->limit(10)->get();
        $plan = $request->query('plan')
            ? $workspace->installationPlans()->whereKey($request->query('plan'))->firstOrFail()
            : null;

        return view('console.infrastructure.index', [
            'workspace' => $workspace,
            'servers' => $servers,
            'domains' => $domains,
            'plans' => $plans,
            'plan' => $plan,
            'versions' => $catalog->versions(),
        ]);
    }

    public function bulkInstall(Request $request, string $workspaceId, DmsBulkInstallEligibility $eligibility): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $candidates = $eligibility->for($request->user(), $workspace);

        return view('console.infrastructure.bulk-install', [
            'workspace' => $workspace,
            'servers' => $candidates['servers'],
            'domains' => $candidates['domains'],
            'missingUsername' => $candidates['missing_username'],
        ]);
    }

    public function bulkInstallLogs(
        Request $request,
        string $workspaceId,
        string $runId,
        DmsBulkInstallTranscriptStore $transcripts,
    ): JsonResponse {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $transcript = $transcripts->read((string) $workspace->getKey(), $runId);
        abort_if($transcript === null, 404);

        return response()->json($transcript);
    }

    public function createServer(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $dmsProviderOrder = ['Digital', 'digital', 'Ovh', 'Linode', 'linode', 'ikoula', 'vultr', 'Azure', 'reg'];
        $providerOrder = array_flip($dmsProviderOrder);
        $providers = ServerProvider::query()->get(['id', 'name'])
            ->sortBy(fn (ServerProvider $provider) => $providerOrder[$provider->name] ?? count($dmsProviderOrder))
            ->values();

        return view('console.infrastructure.server-create', [
            'workspace' => $workspace,
            'providers' => $providers,
            'defaultExpireAt' => now()->addDays(30)->format('Y-m-d'),
        ]);
    }

    public function serversIndex(Request $request, string $workspaceId): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, ['owner', 'admin', 'operator']);
        $canManageServers = in_array($workspace->pivot->role, ['owner', 'admin'], true);
        $canOperateServers = $canManageServers || $workspace->pivot->role === 'operator';
        $returned = $request->boolean('returned');
        $serversQuery = $workspace->managedServers();
        if ($returned) {
            $serversQuery->onlyTrashed();
        }

        if (! $canManageServers) {
            $serversQuery->whereHas('allowedUsers', fn ($query) => $query
                ->where('users.id', $request->user()->getKey())
                ->where('managed_server_user.workspace_id', $workspace->getKey()));
        }

        $servers = $serversQuery
            ->with(['serverProvider:id,name', 'allowedUsers:id', 'history'])
            ->orderBy('expire_at')
            ->orderByDesc('id')
            ->get();

        if ($request->wantsJson()) {
            return response()->json([
                'data' => $servers->map(fn ($server) => [
                    'id' => $server->getKey(),
                    'name' => $server->name,
                    'hostname' => null,
                    'ip' => $server->ip_address,
                    'country' => $server->country,
                    'is_installed' => $server->is_installed,
                    'is_locked' => $server->is_locked,
                    'allowed_users' => $server->allowedUsers->pluck('id')->values(),
                    'can_manage' => $canManageServers,
                    'can_operate' => $canOperateServers,
                    'can_return' => $canOperateServers && ! $server->is_locked,
                    'can_restore' => $canManageServers,
                    'is_active' => $server->is_active,
                    'expire_at' => $server->expire_at?->format('Y-m-d'),
                    'deleted_at' => $server->deleted_at?->toISOString(),
                    'servers_provider_id' => $server->servers_provider_id,
                    'provider' => [
                        'id' => $server->serverProvider?->getKey(),
                        'name' => $server->serverProvider?->name,
                    ],
                    'history' => $returned || ! $server->history ? null : [
                        'is_ssh_down' => $server->history->is_ssh_down,
                        'is_apache_down' => $server->history->is_apache_down,
                        'is_pmta_down' => $server->history->is_pmta_down,
                    ],
                    'ips_count' => null,
                ])->values(),
            ]);
        }

        return view('console.infrastructure.servers', [
            'workspace' => $workspace,
            'serversDataUrl' => route('console.infrastructure.servers.index', $workspace->id),
            'createUrl' => $canManageServers ? route('console.infrastructure.servers.create', $workspace->id) : null,
            'canManageServers' => $canManageServers,
            'canReturnServers' => $canOperateServers,
        ]);
    }

    public function serverHistory(Request $request, string $workspaceId, string $serverId): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, ['owner', 'admin', 'operator']);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        $this->authorizeServerOperator($workspace, $request->user(), $server);

        if (! $request->wantsJson()) {
            return view('console.infrastructure.server-history', [
                'workspace' => $workspace,
                'server' => $server,
                'historyDataUrl' => route('console.infrastructure.servers.history', [$workspace->id, $server->id]),
            ]);
        }

        $query = $server->histories();
        $recordsTotal = (clone $query)->count();
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn ($builder) => $builder
                ->where('id', 'like', "%{$search}%")
                ->orWhere('created_at', 'like', "%{$search}%"));
        }
        $recordsFiltered = (clone $query)->count();

        $sortColumns = [0 => 'id', 1 => 'is_ssh_down', 2 => 'is_apache_down', 3 => 'is_pmta_down', 4 => 'created_at'];
        $orderColumn = (int) $request->input('order.0.column', 0);
        $direction = strtolower((string) $request->input('order.0.dir', 'asc'));
        $query->orderBy($sortColumns[$orderColumn] ?? 'id', $direction === 'desc' ? 'desc' : 'asc');

        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);
        $limit = $length === -1 ? 500 : min(max($length, 1), 100);
        $data = $query->offset($start)->limit($limit)->get([
            'id', 'is_ssh_down', 'is_apache_down', 'is_pmta_down', 'created_at',
        ])->map(fn ($history) => [
            'id' => $history->id,
            'is_ssh_down' => $history->is_ssh_down,
            'is_apache_down' => $history->is_apache_down,
            'is_pmta_down' => $history->is_pmta_down,
            'created_at' => $history->created_at,
        ])->values();

        return response()->json([
            'draw' => max(0, (int) $request->input('draw', 0)),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function serverUsers(Request $request, string $workspaceId, string $serverId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        $users = $workspace->users()->wherePivot('role', 'operator')->orderBy('users.name')->get(['users.id', 'users.name', 'users.email']);
        $allowedUserIds = DB::table('managed_server_user')
            ->where('workspace_id', $workspace->getKey())
            ->where('managed_server_id', $server->getKey())
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        return view('console.infrastructure.server-users', compact('workspace', 'server', 'users', 'allowedUserIds'));
    }

    public function updateServerUsers(Request $request, string $workspaceId, string $serverId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        $data = $request->validate([
            'users' => ['sometimes', 'nullable', 'array'],
            'users.*' => ['integer', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->getKey())->where('role', 'operator')],
        ]);
        $userIds = collect($data['users'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();

        DB::transaction(function () use ($workspace, $server, $userIds, $request): void {
            $this->replaceAllowedUsers($workspace, $server, $userIds);
            $this->auditServerChange($workspace, $request->user(), 'server.allowed_users_updated', $server, $request, ['user_ids' => $userIds]);
        });

        return response()->json(['data' => 'Updated successfully']);
    }

    public function bulkServerUsers(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid'],
        ]);
        $ids = array_values(array_unique($data['ids']));
        $servers = $workspace->managedServers()->whereIn('id', $ids)->orderBy('id')->get(['id', 'workspace_id', 'name']);
        abort_unless($servers->count() === count($ids), 404);
        $users = $workspace->users()->wherePivot('role', 'operator')->orderBy('users.name')->get(['users.id', 'users.name', 'users.email']);

        return view('console.infrastructure.server-bulk-users', compact('workspace', 'servers', 'users'));
    }

    public function updateBulkServerUsers(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $data = $request->validate([
            'servers' => ['required', 'array', 'min:1'],
            'servers.*' => ['required', 'uuid'],
            'users' => ['sometimes', 'nullable', 'array'],
            'users.*' => ['integer', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->getKey())->where('role', 'operator')],
        ]);
        $serverIds = array_values(array_unique($data['servers']));
        $servers = $workspace->managedServers()->whereIn('id', $serverIds)->get();
        abort_unless($servers->count() === count($serverIds), 404);
        $userIds = collect($data['users'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();

        DB::transaction(function () use ($workspace, $servers, $userIds, $request): void {
            foreach ($servers as $server) {
                $this->replaceAllowedUsers($workspace, $server, $userIds);
                $this->auditServerChange($workspace, $request->user(), 'server.allowed_users_updated', $server, $request, ['user_ids' => $userIds, 'bulk' => true]);
            }
        });

        return response()->json(['data' => 'Updated successfully']);
    }

    public function setServerLock(Request $request, string $workspaceId, string $serverId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $server->update(['is_locked' => (bool) $data['status']]);
        $this->auditServerChange($workspace, $request->user(), 'server.lock_changed', $server, $request, ['is_locked' => $server->is_locked]);

        return response()->json(['data' => 'Changed successfully']);
    }

    public function setServerStatus(Request $request, string $workspaceId, string $serverId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, ['owner', 'admin', 'operator']);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        $this->authorizeServerOperator($workspace, $request->user(), $server);
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $server->update(['is_active' => (bool) $data['status']]);
        $this->auditServerChange($workspace, $request->user(), 'server.status_changed', $server, $request, ['is_active' => $server->is_active]);

        return response()->json(['data' => 'Changed successfully']);
    }

    public function returnServers(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, ['owner', 'admin', 'operator']);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid'],
        ]);
        $ids = array_values(array_unique($data['ids']));
        $serversQuery = $workspace->managedServers()->whereIn('id', $ids)->where('is_locked', false);
        if (! in_array($workspace->pivot->role, ['owner', 'admin'], true)) {
            $serversQuery->whereHas('allowedUsers', fn ($query) => $query
                ->where('users.id', $request->user()->getKey())
                ->where('managed_server_user.workspace_id', $workspace->getKey()));
        }
        $servers = $serversQuery->get();

        DB::transaction(function () use ($workspace, $servers, $request): void {
            foreach ($servers as $server) {
                $server->delete();
                $this->auditServerChange($workspace, $request->user(), 'server.returned', $server, $request);
            }
        });

        if ($servers->isEmpty()) {
            return response()->json(['data' => 'Failed'], 400);
        }

        return response()->json(['data' => 'Servers returned', 'returned' => $servers->count()]);
    }

    public function restoreServer(Request $request, string $workspaceId, string $serverId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $server = $workspace->managedServers()->onlyTrashed()->whereKey($serverId)->firstOrFail();
        $server->restore();
        $this->auditServerChange($workspace, $request->user(), 'server.restored', $server, $request);

        return response()->json(['data' => 'Server restored']);
    }

    public function storeServer(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $data = $request->validate([
            'servers_provider_id' => ['required', 'numeric'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'ips' => ['required', 'array', 'min:1'],
            'port' => ['required', 'numeric'],
            'username' => ['required', 'string'],
            'login_type' => ['required', 'string', 'in:password,ssh_key'],
            'password' => ['required', 'string', 'min:4'],
            'expire_at' => ['required', 'date', 'after_or_equal:today'],
        ]);
        $result = $this->manager->registerServers($workspace, $request->user(), $data, $request->attributes->get('request_id'));

        return response()->json([
            'data' => "Added {$result['added']}, Restored {$result['restored']}",
            'added' => $result['added'],
            'restored' => $result['restored'],
            'servers' => $result['servers']->map(fn ($server) => $server->only(['id', 'name', 'servers_provider_id', 'ip_address', 'ssh_port', 'ssh_username', 'login_type', 'expire_at', 'state']))->values(),
        ]);
    }

    public function testServerConnection(Request $request, string $workspaceId): JsonResponse
    {
        $this->workspaceFor($request->user(), $workspaceId);
        $data = $request->validate([
            'ips' => ['required', 'array', 'min:1'],
            'port' => ['required', 'numeric'],
            'username' => ['required', 'string'],
            'login_type' => ['required', 'string', 'in:password,ssh_key'],
            'password' => ['required', 'string', 'min:4'],
        ]);
        $results = $this->manager->testServerConnections(
            $data['ips'], (int) $data['port'], $data['username'], $data['login_type'],
            $data['password'],
        );

        return response()->json([
            'data' => array_column($results, 'message'),
            'results' => $results,
        ]);
    }

    public function storeDomain(Request $request, string $workspaceId): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $data = $request->validate(['name' => ['required', 'string', 'max:253']]);
        $this->manager->registerDomain($workspace, $request->user(), $data['name'], $request->attributes->get('request_id'));

        return redirect()->route('console.infrastructure.index', $workspace->id)->with('status', 'Domain saved. Publish its TXT record to prove ownership.');
    }

    public function storeDmsInstallDomains(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $data = $request->validate([
            'provider' => ['required', 'numeric'],
            'domains' => ['required', 'array'],
            'domains.*' => ['string'],
        ]);

        $added = $this->manager->registerDmsInstallDomains(
            $workspace,
            $request->user(),
            $data['provider'],
            $data['domains'],
            $request->attributes->get('request_id'),
        );

        return response()->json(['data' => $added.' Domain Created']);
    }

    public function preflightServer(Request $request, string $workspaceId, string $serverId): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        try {
            $this->manager->verifyServerHost($workspace, $request->user(), $server, $request->attributes->get('request_id'));
        } catch (HostProbeException $exception) {
            return redirect()->route('console.infrastructure.index', $workspace->id)->with('warning', $exception->getMessage());
        }

        return redirect()->route('console.infrastructure.index', $workspace->id)->with('status', 'Host identity and OS preflight verified.');
    }

    public function verifyDomain(Request $request, string $workspaceId, string $domainId): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $domain = $workspace->sendingDomains()->whereKey($domainId)->firstOrFail();
        $verified = $this->manager->verifyDomain($workspace, $domain, $request->user(), $request->attributes->get('request_id'));

        return redirect()->route('console.infrastructure.index', $workspace->id)
            ->with($verified ? 'status' : 'warning', $verified ? 'Domain ownership verified.' : 'TXT record not found yet. Check its name and value, then try again.');
    }

    public function storePlan(Request $request, string $workspaceId): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $data = $request->validate([
            'server_id' => ['required', 'uuid'],
            'domain_id' => ['required', 'uuid'],
            'pmta_version' => ['required', 'in:4.0,4.5,5.0'],
            'tracking' => ['sometimes', 'accepted'],
            'dkim' => ['sometimes', 'accepted'],
            'dmarc' => ['sometimes', 'accepted'],
            'proxy' => ['sometimes', 'accepted'],
            'spf' => ['sometimes', 'accepted'],
        ]);
        $server = $workspace->managedServers()->whereKey($data['server_id'])->firstOrFail();
        $domain = $workspace->sendingDomains()->whereKey($data['domain_id'])->firstOrFail();
        $options = collect(['tracking', 'dkim', 'dmarc', 'proxy', 'spf'])
            ->mapWithKeys(fn ($key) => [$key => array_key_exists($key, $data)])->all();
        $plan = $this->manager->createPlan($workspace, $request->user(), $server, $domain, $data['pmta_version'], $options, $request->attributes->get('request_id'));

        return redirect()->route('console.infrastructure.index', ['workspace' => $workspace->id, 'plan' => $plan->id]);
    }

    private function workspaceFor(User $user, string $workspaceId, array $allowedRoles = ['owner', 'admin']): Workspace
    {
        $workspace = $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, $allowedRoles, true), 403);

        return $workspace;
    }

    /** @param array<int, int> $userIds */
    private function replaceAllowedUsers(Workspace $workspace, ManagedServer $server, array $userIds): void
    {
        DB::table('managed_server_user')
            ->where('workspace_id', $workspace->getKey())
            ->where('managed_server_id', $server->getKey())
            ->delete();

        if ($userIds !== []) {
            DB::table('managed_server_user')->insert(array_map(
                fn (int $userId): array => [
                    'workspace_id' => $workspace->getKey(),
                    'managed_server_id' => $server->getKey(),
                    'user_id' => $userId,
                ],
                $userIds,
            ));
        }
    }

    private function auditServerChange(Workspace $workspace, User $actor, string $action, ManagedServer $server, Request $request, array $properties = []): void
    {
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $actor->getKey(),
            'action' => $action,
            'subject_type' => 'managed_server',
            'subject_id' => $server->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => $properties,
            'occurred_at' => now(),
        ]);
    }

    private function authorizeServerOperator(Workspace $workspace, User $actor, ManagedServer $server): void
    {
        if (in_array($workspace->pivot->role, ['owner', 'admin'], true)) {
            return;
        }

        abort_unless($server->allowedUsers()
            ->where('users.id', $actor->getKey())
            ->wherePivot('workspace_id', $workspace->getKey())
            ->exists(), 403);
    }
}
