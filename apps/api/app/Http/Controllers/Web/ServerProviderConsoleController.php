<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\ServerProvider;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerProviderConsoleController extends Controller
{
    public function index(Request $request, string $workspaceId): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);

        if ($request->wantsJson()) {
            $providers = ServerProvider::query()
                ->where(fn ($query) => $query
                    ->whereNull('workspace_id')
                    ->orWhere('workspace_id', $workspace->getKey()))
                ->withCount(['managedServers as servers_count' => fn ($query) => $query
                    ->where('workspace_id', $workspace->getKey())])
                ->orderBy('id')
                ->get()
                ->map(fn (ServerProvider $provider): array => [
                    'id' => $provider->getKey(),
                    'name' => $provider->name,
                    'url' => $provider->url,
                    'username' => $provider->username,
                    'password' => $provider->password === null ? '' : '••••••••',
                    'servers_count' => $provider->servers_count,
                    'created_at' => $provider->created_at?->toDateTimeString(),
                    'can_manage' => $provider->workspace_id === $workspace->getKey(),
                ])->values();

            return response()->json(['data' => $providers]);
        }

        return view('console.infrastructure.server-providers.index', ['workspace' => $workspace]);
    }

    public function create(Request $request, string $workspaceId): View
    {
        return view('console.infrastructure.server-providers.form', [
            'workspace' => $this->workspaceFor($request->user(), $workspaceId),
            'provider' => null,
        ]);
    }

    public function edit(Request $request, string $workspaceId, int $providerId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $provider = $workspace->serverProviders()->whereKey($providerId)->firstOrFail();

        return view('console.infrastructure.server-providers.form', compact('workspace', 'provider'));
    }

    public function store(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'url' => ['required', 'string', 'url', 'max:255'],
            'username' => ['nullable', 'string', 'min:4', 'max:255'],
            'password' => ['required', 'string', 'min:4', 'max:255'],
        ]);
        $provider = $workspace->serverProviders()->create($data);
        $this->auditProviderChange($workspace, $request, 'server_provider.created', $provider);

        return response()->json(['data' => 'Provider created']);
    }

    public function update(Request $request, string $workspaceId, int $providerId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $provider = $workspace->serverProviders()->whereKey($providerId)->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'min:4', 'max:255'],
            'url' => ['required', 'string', 'url', 'max:255'],
            'username' => ['nullable', 'string', 'min:4', 'max:255'],
            'password' => ['nullable', 'string', 'min:4', 'max:255'],
        ]);
        $changes = [
            'name' => $data['name'],
            'url' => $data['url'],
            'username' => $data['username'] ?? null,
        ];
        if (filled($data['password'] ?? null)) {
            $changes['password'] = $data['password'];
        }
        $provider->fill($changes)->save();
        $this->auditProviderChange($workspace, $request, 'server_provider.updated', $provider);

        return response()->json(['data' => 'Provider updated']);
    }

    public function destroy(Request $request, string $workspaceId, int $providerId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $provider = $workspace->serverProviders()->whereKey($providerId)->firstOrFail();
        $deleted = $provider->delete();

        if ($deleted) {
            $this->auditProviderChange($workspace, $request, 'server_provider.deleted', $provider);
        }

        return response()->json(['data' => $deleted ? 'Provider deleted' : 'Failed'], $deleted ? 200 : 400);
    }

    private function workspaceFor(User $user, string $workspaceId): Workspace
    {
        return $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
    }

    private function auditProviderChange(Workspace $workspace, Request $request, string $action, ServerProvider $provider): void
    {
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => $action,
            'subject_type' => 'server_provider',
            'subject_id' => (string) $provider->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => ['name' => $provider->name],
            'occurred_at' => now(),
        ]);
    }
}
