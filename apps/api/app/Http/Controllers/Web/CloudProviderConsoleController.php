<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCloudProvider;
use App\Services\Infrastructure\DmsCloudProviderApi;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CloudProviderConsoleController extends Controller
{
    public function index(Request $request, string $workspaceId): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);

        if ($request->wantsJson()) {
            $query = $workspace->cloudProviders();
            $recordsTotal = (clone $query)->count();
            $search = trim((string) $request->input('search.value', ''));

            if ($search !== '') {
                $query->where(function ($builder) use ($search): void {
                    $builder->where('type', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%');

                    if (ctype_digit($search)) {
                        $builder->orWhere('id', (int) $search);
                    }
                });
            }

            $recordsFiltered = (clone $query)->count();
            $sortableColumns = ['id', 'type', 'name', 'created_at'];
            $orderIndex = max(0, (int) $request->input('order.0.column', 0));
            $orderColumn = (string) $request->input("columns.{$orderIndex}.data", 'id');
            $direction = strtolower((string) $request->input('order.0.dir', 'asc')) === 'desc' ? 'desc' : 'asc';
            $start = max(0, (int) $request->input('start', 0));
            $requestedLength = (int) $request->input('length', 10);
            $length = $requestedLength === -1 ? max(1, $recordsFiltered) : min(100, max(1, $requestedLength));

            if (! in_array($orderColumn, $sortableColumns, true)) {
                $orderColumn = 'id';
            }

            return response()->json([
                'draw' => max(0, (int) $request->input('draw', 0)),
                'recordsTotal' => $recordsTotal,
                'recordsFiltered' => $recordsFiltered,
                'data' => $query->orderBy($orderColumn, $direction)
                    ->offset($start)
                    ->limit($length)
                    ->get(['id', 'type', 'name', 'created_at']),
            ]);
        }

        return view('console.infrastructure.cloud-providers.index', [
            'workspace' => $workspace,
        ]);
    }

    public function create(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);

        return view('console.infrastructure.cloud-providers.form', [
            'workspace' => $workspace,
            'provider' => null,
            'types' => WorkspaceCloudProvider::WORKFLOW_TYPES,
            'users' => $this->eligibleUsers($workspace),
        ]);
    }

    public function available(Request $request, string $workspaceId, string $type): JsonResponse
    {
        $workspace = $this->workspaceForMember($request->user(), $workspaceId);
        abort_unless(in_array($type, WorkspaceCloudProvider::WORKFLOW_TYPES, true), 404);

        $role = $workspace->users()
            ->whereKey($request->user()->getKey())
            ->firstOrFail()
            ->pivot
            ->role;
        $providers = $workspace->cloudProviders()
            ->where('type', $type)
            ->when($role === 'operator', fn ($query) => $query->whereJsonContains('allowed_users', $request->user()->getKey()))
            ->get(['id', 'name']);

        return response()->json(['data' => $providers]);
    }

    public function edit(Request $request, string $workspaceId, int $providerId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $provider = $workspace->cloudProviders()->whereKey($providerId)->firstOrFail();

        return view('console.infrastructure.cloud-providers.form', [
            'workspace' => $workspace,
            'provider' => $provider,
            'types' => array_values(array_unique([$provider->type, ...WorkspaceCloudProvider::WORKFLOW_TYPES])),
            'users' => $this->eligibleUsers($workspace),
        ]);
    }

    public function store(Request $request, string $workspaceId): RedirectResponse|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $data = $this->validatedProvider($request, $workspace);
        $provider = $workspace->cloudProviders()->create([
            'type' => $data['type'],
            'name' => $data['name'],
            'tokens' => ['api_token' => $data['api_token']],
            'allowed_users' => array_values(array_unique(array_map('intval', $data['allowed_users'] ?? []))),
        ]);
        $this->auditProviderChange($workspace, $request, 'cloud_provider.created', $provider);

        if ($request->wantsJson()) {
            return response()->json(['data' => 'Created successfully']);
        }

        return redirect()->route('console.infrastructure.cloud-providers.index', $workspace->getKey())
            ->with('status', 'Cloud provider saved.');
    }

    public function update(Request $request, string $workspaceId, int $providerId): RedirectResponse|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $provider = $workspace->cloudProviders()->whereKey($providerId)->firstOrFail();
        $data = $this->validatedProvider($request, $workspace, false);
        $replacementToken = $data['api_token'] ?? null;
        $hasReplacementToken = is_string($replacementToken) && $replacementToken !== '';

        if ($provider->type !== $data['type'] && ! $hasReplacementToken) {
            throw ValidationException::withMessages([
                'api_token' => 'Enter a new API token when changing the provider type.',
            ]);
        }

        $attributes = [
            'type' => $data['type'],
            'name' => $data['name'],
            'allowed_users' => array_values(array_unique(array_map('intval', $data['allowed_users'] ?? []))),
        ];

        if ($hasReplacementToken) {
            $attributes['tokens'] = ['api_token' => $replacementToken];
        }

        $provider->fill($attributes)->save();
        $this->auditProviderChange($workspace, $request, 'cloud_provider.updated', $provider);

        if ($request->wantsJson()) {
            return response()->json(['data' => 'Updated successfully']);
        }

        return redirect()->route('console.infrastructure.cloud-providers.index', $workspace->getKey())
            ->with('status', 'Cloud provider updated.');
    }

    public function testNewConnection(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);

        return $this->testProviderCredentials($request, $workspace, null);
    }

    public function testExistingConnection(Request $request, string $workspaceId, int $providerId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $provider = $workspace->cloudProviders()->whereKey($providerId)->firstOrFail();

        return $this->testProviderCredentials($request, $workspace, $provider);
    }

    private function testProviderCredentials(
        Request $request,
        Workspace $workspace,
        ?WorkspaceCloudProvider $savedProvider,
    ): JsonResponse {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(WorkspaceCloudProvider::WORKFLOW_TYPES)],
            'api_token' => ['nullable', 'string', 'max:9999'],
        ]);

        if (blank($data['api_token'] ?? null) && $savedProvider?->type === $data['type']) {
            $data['api_token'] = $savedProvider->tokens['api_token'] ?? null;
        }

        if (blank($data['api_token'] ?? null)) {
            return response()->json([
                'success' => false,
                'message' => 'Enter the cloud API token before testing this account.',
            ]);
        }

        $provider = new WorkspaceCloudProvider([
            'workspace_id' => $workspace->getKey(),
            'type' => $data['type'],
            'tokens' => ['api_token' => $data['api_token'] ?? ''],
        ]);

        try {
            $message = (new DmsCloudProviderApi($provider))->testConnection();

            return response()->json(['success' => true, 'message' => $message]);
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()]);
        }
    }

    public function destroy(Request $request, string $workspaceId, int $providerId): RedirectResponse|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $provider = $workspace->cloudProviders()->whereKey($providerId)->firstOrFail();
        $provider->forceDelete();
        $this->auditProviderChange($workspace, $request, 'cloud_provider.deleted', $provider);

        if ($request->wantsJson()) {
            return response()->json(['data' => 'Deleted Successfully']);
        }

        return redirect()->route('console.infrastructure.cloud-providers.index', $workspace->getKey())
            ->with('status', 'Cloud provider deleted.');
    }

    private function validatedProvider(Request $request, Workspace $workspace, bool $requireToken = true): array
    {
        return $request->validate([
            'type' => ['required', 'string', Rule::in(WorkspaceCloudProvider::WORKFLOW_TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'api_token' => $requireToken ? ['required', 'string'] : ['nullable', 'string'],
            'allowed_users' => ['nullable', 'array'],
            'allowed_users.*' => [
                'integer',
                Rule::exists('workspace_user', 'user_id')
                    ->where('workspace_id', $workspace->getKey())
                    ->where('role', 'operator'),
            ],
        ]);
    }

    private function eligibleUsers(Workspace $workspace)
    {
        return $workspace->users()
            ->where('workspace_user.role', 'operator')
            ->orderBy('users.id')
            ->get(['users.id', 'users.name', 'users.username']);
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

    private function workspaceForMember(User $user, string $workspaceId): Workspace
    {
        $workspace = $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin', 'operator'], true), 403);

        return $workspace;
    }

    private function auditProviderChange(Workspace $workspace, Request $request, string $action, WorkspaceCloudProvider $provider): void
    {
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => $action,
            'subject_type' => 'cloud_provider',
            'subject_id' => (string) $provider->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => ['type' => $provider->type, 'name' => $provider->name],
            'occurred_at' => now(),
        ]);
    }
}
