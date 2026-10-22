<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServerProvider;
use App\Models\Workspace;
use App\Services\Infrastructure\HostProbeException;
use App\Services\Infrastructure\InfrastructureManager;
use App\Services\Infrastructure\PmtaCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InfrastructureController extends Controller
{
    public function __construct(private readonly InfrastructureManager $manager) {}

    public function index(Request $request, PmtaCatalog $catalog): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');

        return response()->json(['data' => [
            'servers' => $workspace->managedServers()->latest()->get()
                ->map(fn ($server) => $this->server($server)),
            'providers' => ServerProvider::query()->get(['id', 'name']),
            'domains' => $workspace->sendingDomains()->latest()->get()
                ->map(fn ($domain) => $this->domain($domain)),
            'plans' => $workspace->installationPlans()->latest('created_at')->limit(25)->get()
                ->map(fn ($plan) => $this->plan($plan)),
            'pmta_versions' => $catalog->versions(),
        ]]);
    }

    public function storeServer(Request $request): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
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
            'servers' => $result['servers']->map(fn ($server) => $this->server($server))->values(),
        ]);
    }

    public function testServerConnection(Request $request): JsonResponse
    {
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
            $data['ips'],
            (int) $data['port'],
            $data['username'],
            $data['login_type'],
            $data['password'],
            $data['expected_host_fingerprints'] ?? [],
        );

        return response()->json([
            'data' => array_column($results, 'message'),
            'host_fingerprints' => collect($results)
                ->filter(fn ($result) => $result['status'] === 'pin_required')
                ->mapWithKeys(fn ($result) => [$result['ip'] => $result['observed_fingerprint']]),
            'results' => $results,
        ]);
    }

    public function storeDomain(Request $request): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $data = $request->validate(['name' => ['required', 'string', 'max:253']]);
        $domain = $this->manager->registerDomain($workspace, $request->user(), $data['name'], $request->attributes->get('request_id'));

        return response()->json(['data' => $this->domain($domain)], 201);
    }

    public function discoverHostKey(Request $request, string $workspaceId, string $serverId): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        try {
            $fingerprint = $this->manager->discoverServerFingerprint($workspace, $request->user(), $server, $request->attributes->get('request_id'));
        } catch (HostProbeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->server($server->refresh()), 'observed_fingerprint' => $fingerprint]);
    }

    public function preflightServer(Request $request, string $workspaceId, string $serverId): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $data = $request->validate([
            'expected_host_fingerprint' => ['required', 'string', 'regex:/^SHA256:[A-Za-z0-9+\/]{43}$/'],
        ]);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        try {
            $this->manager->verifyServerHost($workspace, $request->user(), $server, $data['expected_host_fingerprint'], $request->attributes->get('request_id'));
        } catch (HostProbeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->server($server->refresh())]);
    }

    public function verifyDomain(Request $request, string $workspaceId, string $domainId): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $domain = $workspace->sendingDomains()->whereKey($domainId)->firstOrFail();
        $verified = $this->manager->verifyDomain($workspace, $domain, $request->user(), $request->attributes->get('request_id'));

        return response()->json(['data' => $this->domain($domain->refresh()), 'verified' => $verified]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $data = $request->validate([
            'server_id' => ['required', 'uuid'],
            'domain_id' => ['required', 'uuid'],
            'pmta_version' => ['required', 'in:4.0,4.5,5.0'],
            'options' => ['sometimes', 'array'],
            'options.tracking' => ['sometimes', 'boolean'],
            'options.dkim' => ['sometimes', 'boolean'],
            'options.dmarc' => ['sometimes', 'boolean'],
            'options.proxy' => ['sometimes', 'boolean'],
            'options.spf' => ['sometimes', 'boolean'],
        ]);
        $server = $workspace->managedServers()->whereKey($data['server_id'])->firstOrFail();
        $domain = $workspace->sendingDomains()->whereKey($data['domain_id'])->firstOrFail();
        $options = collect($data['options'] ?? [])->map(fn ($value) => filter_var($value, FILTER_VALIDATE_BOOLEAN))->all();
        $plan = $this->manager->createPlan($workspace, $request->user(), $server, $domain, $data['pmta_version'], $options, $request->attributes->get('request_id'));

        return response()->json(['data' => $this->plan($plan)], 201);
    }

    private function server($server): array
    {
        return $server->only(['id', 'name', 'provider', 'servers_provider_id', 'ip_address', 'ssh_port', 'ssh_username', 'login_type', 'expire_at', 'expected_host_fingerprint', 'observed_host_fingerprint', 'last_probe_at', 'state', 'host_facts', 'created_at']);
    }

    private function domain($domain): array
    {
        return [
            ...$domain->only(['id', 'name', 'state', 'verified_at', 'created_at']),
            'verification_name' => '_alphalink-verify.'.$domain->name,
            'verification_value' => $domain->verification_token,
        ];
    }

    private function plan($plan): array
    {
        return $plan->only(['id', 'managed_server_id', 'sending_domain_id', 'pmta_version', 'options', 'preview', 'preview_sha256', 'state', 'created_at']);
    }
}
