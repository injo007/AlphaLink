<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCloudProvider;
use App\Services\Infrastructure\DmsCloudIpReputationProbe;
use App\Services\Infrastructure\DmsCloudProviderApi;
use App\Services\Infrastructure\InfrastructureManager;
use App\Services\Infrastructure\WorkspaceCloudSshKeyPair;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CloudWorkflowConsoleController extends Controller
{
    private const PROVIDER_LABELS = [
        'linode' => 'Linode',
        'digitalocean' => 'DigitalOcean',
    ];

    public function index(Request $request, string $workspaceId, string $type): View
    {
        $workspace = $this->workspaceForMember($request->user(), $workspaceId);
        abort_unless(in_array($type, WorkspaceCloudProvider::WORKFLOW_TYPES, true), 404);
        $providers = $this->providersFor($workspace, $request->user(), $type);
        $role = (string) $workspace->pivot->role;

        return view('console.infrastructure.cloud-workflow', [
            'workspace' => $workspace,
            'type' => $type,
            'title' => self::PROVIDER_LABELS[$type],
            'providers' => $providers,
            'hasProviders' => $providers->isNotEmpty(),
            'canManageCloudProviders' => in_array($role, ['owner', 'admin'], true),
            'cloudProviderManagementUrl' => route('console.infrastructure.cloud-providers.index', $workspace->id),
            'actionRoutes' => [
                'info' => route('console.clouds.info', [$workspace->id, $type, '__provider__']),
                'instances' => route('console.clouds.instances', [$workspace->id, $type, '__provider__']),
                'create' => route('console.clouds.create', [$workspace->id, $type, '__provider__']),
                'destroy' => route('console.clouds.destroy', [$workspace->id, $type, '__provider__']),
                'servers' => route('console.clouds.servers', [$workspace->id, $type, '__provider__']),
                'reputation' => route('console.clouds.reputation', [$workspace->id, $type, '__provider__']),
                ...($type === 'linode' ? [
                    'rdns' => route('console.clouds.rdns', [$workspace->id, $type, '__provider__']),
                ] : []),
            ],
            'canSetRdns' => $type === 'linode',
        ]);
    }

    public function info(Request $request, string $workspaceId, string $type, int $providerId): JsonResponse
    {
        $provider = $this->providerFor($request, $workspaceId, $type, $providerId);

        try {
            return response()->json((new DmsCloudProviderApi($provider))->catalog());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function instances(Request $request, string $workspaceId, string $type, int $providerId): JsonResponse
    {
        $provider = $this->providerFor($request, $workspaceId, $type, $providerId);

        try {
            return response()->json(['data' => (new DmsCloudProviderApi($provider))->instances()]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function create(Request $request, string $workspaceId, string $type, int $providerId, WorkspaceCloudSshKeyPair $sshKeys): JsonResponse
    {
        $provider = $this->providerFor($request, $workspaceId, $type, $providerId);
        $data = $request->validate([
            'image' => ['required', 'string'],
            'region' => ['required', 'string'],
            'type' => ['required', 'string'],
            'name' => ['nullable', 'string', ...($type === 'digitalocean'
                ? [function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                        $fail('Not a valid hostname');
                    }
                }]
                : ['alpha_num'])],
            'count' => ['required', 'numeric'],
        ]);
        $password = $provider->workspace->infrastructureSettings?->cloud_servers_password;
        $api = new DmsCloudProviderApi($provider);
        if ($type === 'linode' && (! is_string($password) || $password === '')) {
            return response()->json(['message' => 'Set the Cloud Server Password in Settings before creating instances.'], 422);
        }

        try {
            $keyPair = $api->needsSshKeyPair() ? $sshKeys->getOrCreate($provider->workspace) : null;
            $created = $api->create($data, $request->user()->username, $password, $keyPair);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->audit($request, $provider, 'cloud.instance_create_requested', ['created' => $created]);

        return response()->json(['data' => "Created {$created} Instances"]);
    }

    public function destroy(Request $request, string $workspaceId, string $type, int $providerId): JsonResponse
    {
        $provider = $this->providerFor($request, $workspaceId, $type, $providerId);
        $data = $request->validate([
            'instances' => ['required', 'array', 'min:1'],
            'instances.*.id' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'instances.*.ip' => ['required', 'ip'],
        ]);

        try {
            $api = new DmsCloudProviderApi($provider);
            $current = collect($api->instances())->keyBy('id');
            foreach ($data['instances'] as $target) {
                abort_unless(($current->get((string) $target['id'])['ip'] ?? null) === $target['ip'], 422, 'The selected instance no longer matches this provider account. Reload the instance list.');
            }

            $results = $api->delete($data['instances']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->audit($request, $provider, 'cloud.instance_delete_requested', ['count' => count($results)]);

        return response()->json(['data' => $results]);
    }

    public function addServers(
        Request $request,
        string $workspaceId,
        string $type,
        int $providerId,
        InfrastructureManager $manager,
        WorkspaceCloudSshKeyPair $sshKeys,
    ): JsonResponse {
        $provider = $this->providerFor($request, $workspaceId, $type, $providerId);
        $data = $request->validate([
            'instances' => ['required', 'array', 'min:1'],
            'instances.*.id' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'instances.*.ip' => ['required', 'ip'],
        ]);
        $workspace = $provider->workspace;
        $settings = $workspace->infrastructureSettings;
        $ips = [];
        try {
            $current = collect((new DmsCloudProviderApi($provider))->instances())->keyBy('id');
            foreach ($data['instances'] as $instance) {
                abort_unless(($current->get((string) $instance['id'])['ip'] ?? null) === $instance['ip'], 422, 'The selected instance no longer matches this provider account. Reload the instance list.');
                $ips[] = $instance['ip'];
            }

            $sshKeyPair = null;
            if ($type === 'digitalocean') {
                $sshKeyPair = $sshKeys->getOrCreate($workspace);
            } elseif (! is_string($settings?->cloud_servers_password) || $settings->cloud_servers_password === '') {
                return response()->json(['message' => 'Set the Cloud Server Password in Settings before adding cloud instances to Servers.'], 422);
            }

            $serverProviderName = $type === 'linode' ? 'linode' : 'digital';
            $serverProvider = $workspace->serverProviders()->firstOrCreate(
                ['name' => $serverProviderName],
                ['url' => $this->providerUrl($type), 'username' => 'test', 'password' => 'test'],
            );
            $prefix = $type === 'linode'
                ? 'lin_'.$request->user()->username
                : 'digi_'.$request->user()->username.'_';
            $result = $manager->registerServers($workspace, $request->user(), [
                'servers_provider_id' => $serverProvider->getKey(),
                'ips' => array_values(array_unique($ips)),
                'name' => mb_substr($prefix, 0, 120),
                'username' => 'root',
                'password' => $sshKeyPair['private_key'] ?? $settings->cloud_servers_password,
                'port' => 22,
                'login_type' => $sshKeyPair === null ? 'password' : 'ssh_key',
                'expire_at' => now()->addDays(30)->toDateString(),
            ], $request->attributes->get('request_id'));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        foreach ($result['servers'] as $server) {
            $server->allowedUsers()->syncWithoutDetaching([
                $request->user()->getKey() => ['workspace_id' => $workspace->getKey()],
            ]);
        }

        $this->audit($request, $provider, 'cloud.instances_added_to_servers', ['added' => $result['added'], 'restored' => $result['restored']]);

        return response()->json(['data' => "Added {$result['added']}, Restored {$result['restored']}"]);
    }

    public function setRdns(Request $request, string $workspaceId, string $type, int $providerId): JsonResponse
    {
        $provider = $this->providerFor($request, $workspaceId, $type, $providerId);
        $data = $request->validate([
            'id' => ['required', 'string', 'max:255'],
            'ip' => ['required', 'ip'],
            'rdns' => ['required', 'string'],
        ]);

        try {
            $api = new DmsCloudProviderApi($provider);
            $current = collect($api->instances())->keyBy('id');
            abort_unless(($current->get((string) $data['id'])['ip'] ?? null) === $data['ip'], 422, 'The selected instance no longer matches this provider account. Reload the instance list.');
            $result = $api->setRdns($data['id'], $data['ip'], $data['rdns']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->audit($request, $provider, 'cloud.rdns_updated', ['success' => $result['success']]);

        return response()->json(['data' => $result['message']], $result['success'] ? 200 : 400);
    }

    public function checkReputation(
        Request $request,
        string $workspaceId,
        string $type,
        int $providerId,
        DmsCloudIpReputationProbe $probe,
    ): JsonResponse {
        $provider = $this->providerFor($request, $workspaceId, $type, $providerId);
        $data = $request->validate([
            'id' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'ip' => ['required', 'ip'],
        ]);

        try {
            $current = collect((new DmsCloudProviderApi($provider))->instances())->keyBy('id');
            abort_unless(($current->get((string) $data['id'])['ip'] ?? null) === $data['ip'], 422, 'The selected instance no longer matches this provider account. Reload the instance list.');

            return response()->json($probe->check($data['ip']));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function providerFor(Request $request, string $workspaceId, string $type, int $providerId): WorkspaceCloudProvider
    {
        $workspace = $this->workspaceForMember($request->user(), $workspaceId);
        abort_unless(in_array($type, WorkspaceCloudProvider::WORKFLOW_TYPES, true), 404);
        $provider = $workspace->cloudProviders()->whereKey($providerId)->where('type', $type)->firstOrFail();
        $role = (string) $workspace->pivot->role;
        abort_if($role === 'operator' && ! in_array($request->user()->getKey(), array_map('intval', $provider->allowed_users ?? []), true), 403);

        return $provider;
    }

    private function providersFor(Workspace $workspace, User $user, string $type)
    {
        $role = (string) $workspace->pivot->role;

        return $workspace->cloudProviders()
            ->where('type', $type)
            ->when($role === 'operator', fn ($query) => $query->whereJsonContains('allowed_users', $user->getKey()))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function workspaceForMember(User $user, string $workspaceId): Workspace
    {
        $workspace = $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin', 'operator'], true), 403);

        return $workspace;
    }

    private function providerUrl(string $type): string
    {
        return $type === 'linode' ? 'linode.com' : 'https://digitalocean.com';
    }

    private function audit(Request $request, WorkspaceCloudProvider $provider, string $action, array $properties): void
    {
        AuditEvent::query()->create([
            'workspace_id' => $provider->workspace_id,
            'actor_user_id' => $request->user()->getKey(),
            'action' => $action,
            'subject_type' => 'cloud_provider',
            'subject_id' => (string) $provider->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => ['type' => $provider->type, 'name' => $provider->name, ...$properties],
            'occurred_at' => now(),
        ]);
    }
}
