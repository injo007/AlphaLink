<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ManagedServerIp;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGoogleAccount;
use App\Models\WorkspaceGoogleProject;
use App\Services\Workspace\DmsWorkspaceDirectorySync;
use App\Services\Workspace\DmsWorkspaceSmtpConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Throwable;

class WorkspaceToolConsoleController extends Controller
{
    public function index(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $ips = ManagedServerIp::query()->with('server:id,name')
            ->where('workspace_id', $workspace->id)->where('is_active', true)
            ->whereHas('server', fn ($query) => $query->where('is_active', true)->where('is_installed', true))
            ->orderBy('managed_server_id')->orderBy('ip')->get();
        $projects = WorkspaceGoogleProject::query()
            ->where('workspace_id', $workspace->id)->orderByDesc('id')->get();

        return view('console.tools.workspace.index', compact('workspace', 'ips', 'projects'));
    }

    public function projects(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $projects = WorkspaceGoogleProject::query()->withCount('accounts')
            ->where('workspace_id', $workspace->id)->orderByDesc('id')->get();

        return view('console.tools.workspace.projects', compact('workspace', 'projects'));
    }

    public function accounts(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $projects = WorkspaceGoogleProject::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'name']);
        $selectedProjectId = $request->query('project');
        if ($selectedProjectId !== null) {
            $selectedProject = WorkspaceGoogleProject::query()->where('workspace_id', $workspace->id)->findOrFail($selectedProjectId);
            $selectedProjectId = $selectedProject->id;
        }
        $accountsQuery = WorkspaceGoogleAccount::query()->with('project:id,name')->where('workspace_id', $workspace->id);
        if ($selectedProjectId !== null) {
            $accountsQuery->where('project_id', $selectedProjectId);
        }
        $accounts = $accountsQuery->orderBy('id', 'desc')->get();

        return view('console.tools.workspace.accounts', compact('workspace', 'projects', 'accounts', 'selectedProjectId'));
    }

    public function storeProject(Request $request, string $workspace, DmsWorkspaceDirectorySync $directory): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate([
            'name' => ['required', 'string', 'email', 'max:255', 'unique:workspace_google_projects,name,NULL,id,workspace_id,'.$workspace->id],
            'service_account_json' => ['required', 'string', 'max:50000'],
        ]);
        $credentials = json_decode($data['service_account_json'], true);
        Validator::make(['credentials' => $credentials], [
            'credentials' => ['required', 'array'],
            'credentials.client_email' => ['required', 'email'],
            'credentials.private_key' => ['required', 'string'],
        ])->validate();

        $project = $workspace->id ? WorkspaceGoogleProject::query()->create([
            'workspace_id' => $workspace->id,
            'name' => strtolower($data['name']),
            'service_account_json' => $data['service_account_json'],
            'is_active' => true,
        ]) : null;

        if (! $project) {
            return response()->json(['message' => 'Project could not be saved.'], 500);
        }

        try {
            $count = $directory->sync($project);
        } catch (Throwable $exception) {
            $project->forceFill(['is_active' => false, 'sync_error' => substr($exception->getMessage(), 0, 1000)])->save();

            return response()->json([
                'message' => 'Project saved, but Google account synchronization failed. Correct the service account and retry sync.',
                'project_id' => $project->id,
                'error' => $project->sync_error,
            ], 422);
        }

        return response()->json(['message' => 'Project created and synced.', 'project_id' => $project->id, 'accounts' => $count]);
    }

    public function syncProject(Request $request, string $workspace, WorkspaceGoogleProject $project, DmsWorkspaceDirectorySync $directory): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless((string) $project->workspace_id === (string) $workspace->id, 404);
        try {
            $count = $directory->sync($project);
        } catch (Throwable $exception) {
            $project->forceFill(['is_active' => false, 'sync_error' => substr($exception->getMessage(), 0, 1000)])->save();

            return response()->json(['message' => 'Google account synchronization failed.', 'error' => $project->sync_error], 422);
        }

        return response()->json(['message' => 'Project synchronized.', 'accounts' => $count]);
    }

    public function statusProject(Request $request, string $workspace, WorkspaceGoogleProject $project): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless((string) $project->workspace_id === (string) $workspace->id, 404);
        $project->update(['is_active' => ! $project->is_active]);

        return response()->json(['is_active' => $project->fresh()->is_active, 'message' => 'Project status changed.']);
    }

    public function statusProjects(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'status' => ['required', 'boolean'],
        ]);
        $projects = WorkspaceGoogleProject::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $data['ids']);
        abort_unless($projects->count() === count($data['ids']), 404);

        $changed = $projects->update(['is_active' => (bool) $data['status']]);

        return response()->json([
            'data' => 'Projects status updated.',
            'message' => 'Selected project status updated.',
            'changed' => $changed,
        ]);
    }

    public function destroyProject(Request $request, string $workspace, WorkspaceGoogleProject $project): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless((string) $project->workspace_id === (string) $workspace->id, 404);
        $project->delete();

        return response()->json(['message' => 'Project deleted.']);
    }

    public function destroyProjects(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $projects = WorkspaceGoogleProject::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $data['ids']);
        abort_unless($projects->count() === count($data['ids']), 404);

        $deleted = $projects->delete();

        return response()->json([
            'data' => 'Projects deleted.',
            'message' => 'Selected projects deleted.',
            'deleted' => $deleted,
        ]);
    }

    public function storeAccounts(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'min:1'],
            'accounts' => ['required', 'array', 'min:1'],
            'accounts.*' => ['nullable', 'string', 'max:255'],
        ]);
        $project = WorkspaceGoogleProject::query()->where('workspace_id', $workspace->id)->findOrFail($data['project_id']);
        $added = 0;
        foreach (array_unique($data['accounts']) as $account) {
            $email = strtolower(trim((string) $account));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $created = WorkspaceGoogleAccount::query()->firstOrCreate([
                'workspace_id' => $workspace->id,
                'project_id' => $project->id,
                'email' => $email,
            ], ['is_active' => true]);
            $added += $created->wasRecentlyCreated ? 1 : 0;
        }

        return response()->json(['message' => "Added {$added} accounts.", 'added' => $added]);
    }

    public function statusAccounts(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate([
            'accounts' => ['required', 'array', 'min:1'],
            'accounts.*' => ['required', 'integer', 'min:1', 'distinct'],
            'status' => ['required', 'boolean'],
        ]);
        $count = DB::transaction(function () use ($workspace, $data): int {
            $accountIds = $this->lockSelectedAccountIds($workspace, $data['accounts']);

            return WorkspaceGoogleAccount::query()->where('workspace_id', $workspace->id)
                ->whereIn('id', $accountIds)->update(['is_active' => (bool) $data['status']]);
        });

        return response()->json([
            'data' => 'Account status changed.',
            'message' => 'Account status changed.',
            'changed' => $count,
        ]);
    }

    public function destroyAccounts(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $deleted = DB::transaction(function () use ($workspace, $data): int {
            $accountIds = $this->lockSelectedAccountIds($workspace, $data['ids']);

            return WorkspaceGoogleAccount::query()->where('workspace_id', $workspace->id)
                ->whereIn('id', $accountIds)->delete();
        });

        return response()->json([
            'data' => 'Accounts deleted.',
            'message' => 'Accounts deleted.',
            'deleted' => $deleted,
        ]);
    }

    /** @param array<int, int|string> $ids
     * @return array<int, int>
     */
    private function lockSelectedAccountIds(Workspace $workspace, array $ids): array
    {
        $selectedIds = WorkspaceGoogleAccount::query()->where('workspace_id', $workspace->id)
            ->whereIn('id', $ids)->lockForUpdate()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        abort_unless(count($selectedIds) === count($ids), 404);

        return $selectedIds;
    }

    public function push(Request $request, string $workspace, DmsWorkspaceSmtpConfiguration $smtp): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate([
            'ips' => ['required', 'array', 'min:1'], 'ips.*' => ['required', 'integer', 'min:1'],
            'projects' => ['required', 'array', 'min:1'], 'projects.*' => ['required', 'integer', 'min:1'],
            'split_on_ips' => ['required', 'boolean'],
        ]);
        try {
            $result = $smtp->push($workspace, $request->user(), array_values(array_unique($data['ips'])), array_values(array_unique($data['projects'])), (bool) $data['split_on_ips']);
        } catch (Throwable $exception) {
            return response()->json(['data' => $exception->getMessage(), 'error' => $exception->getMessage(), 'exit' => 1], 400);
        }

        return response()->json(['data' => $result, 'error' => null, 'exit' => 0], 200);
    }

    public function reset(Request $request, string $workspace, DmsWorkspaceSmtpConfiguration $smtp): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate(['ips' => ['required', 'array', 'min:1'], 'ips.*' => ['required', 'integer', 'min:1']]);
        try {
            $result = $smtp->reset($workspace, $request->user(), array_values(array_unique($data['ips'])));
        } catch (Throwable $exception) {
            return response()->json(['data' => $exception->getMessage(), 'error' => $exception->getMessage(), 'exit' => 1], 400);
        }

        return response()->json(['data' => $result, 'error' => null, 'exit' => 0], 200);
    }

    private function workspaceFor(?User $user, string $workspaceId): Workspace
    {
        abort_unless($user, 401);

        return $user->workspaces()->where('workspaces.status', 'active')->whereKey($workspaceId)->firstOrFail();
    }
}
