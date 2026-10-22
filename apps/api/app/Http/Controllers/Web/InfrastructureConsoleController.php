<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ServerProvider;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\HostProbeException;
use App\Services\Infrastructure\InfrastructureManager;
use App\Services\Infrastructure\PmtaCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $returned = $request->boolean('returned');
        $serversQuery = $workspace->managedServers();

        if ($returned) {
            $serversQuery = $workspace->managedServers()->onlyTrashed();
        }

        $servers = $serversQuery
            ->with('serverProvider:id,name')
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
                    'country' => null,
                    'is_installed' => false,
                    'is_locked' => false,
                    'allowed_users' => [],
                    'is_active' => true,
                    'expire_at' => $server->expire_at?->format('Y-m-d'),
                    'deleted_at' => $server->deleted_at?->toISOString(),
                    'servers_provider_id' => $server->servers_provider_id,
                    'provider' => [
                        'id' => $server->serverProvider?->getKey(),
                        'name' => $server->serverProvider?->name,
                    ],
                    'history' => null,
                    'ips_count' => 0,
                ])->values(),
            ]);
        }

        return view('console.infrastructure.servers', [
            'workspace' => $workspace,
            'serversDataUrl' => route('console.infrastructure.servers.index', $workspace->id),
            'createUrl' => route('console.infrastructure.servers.create', $workspace->id),
        ]);
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
            'expected_host_fingerprints' => ['sometimes', 'array'],
            'expected_host_fingerprints.*' => ['nullable', 'string', 'regex:/^SHA256:[A-Za-z0-9+\/]{43}$/'],
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
            'expected_host_fingerprints' => ['sometimes', 'array'],
            'expected_host_fingerprints.*' => ['nullable', 'string', 'regex:/^SHA256:[A-Za-z0-9+\/]{43}$/'],
        ]);
        $results = $this->manager->testServerConnections(
            $data['ips'], (int) $data['port'], $data['username'], $data['login_type'],
            $data['password'], $data['expected_host_fingerprints'] ?? [],
        );

        return response()->json([
            'data' => array_column($results, 'message'),
            'host_fingerprints' => collect($results)
                ->filter(fn ($result) => $result['status'] === 'pin_required')
                ->mapWithKeys(fn ($result) => [$result['ip'] => $result['observed_fingerprint']]),
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

    public function discoverHostKey(Request $request, string $workspaceId, string $serverId): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        try {
            $this->manager->discoverServerFingerprint($workspace, $request->user(), $server, $request->attributes->get('request_id'));
        } catch (HostProbeException $exception) {
            return redirect()->route('console.infrastructure.index', $workspace->id)->with('warning', $exception->getMessage());
        }

        return redirect()->route('console.infrastructure.index', $workspace->id)->with('status', 'Host key read. Compare the fingerprint below with your provider before preflight.');
    }

    public function preflightServer(Request $request, string $workspaceId, string $serverId): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        $data = $request->validate([
            'expected_host_fingerprint' => ['required', 'string', 'regex:/^SHA256:[A-Za-z0-9+\/]{43}$/'],
        ]);
        try {
            $this->manager->verifyServerHost($workspace, $request->user(), $server, $data['expected_host_fingerprint'], $request->attributes->get('request_id'));
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

    private function workspaceFor(User $user, string $workspaceId): Workspace
    {
        $workspace = $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin'], true), 403);

        return $workspace;
    }
}
