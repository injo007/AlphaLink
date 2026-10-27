<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ManagedServer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSeedEmail;
use App\Models\WorkspaceSeedList;
use App\Services\PmtaRemoteConfiguration;
use App\Services\WorkspaceSeedListAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WorkspaceSeedListConsoleController extends Controller
{
    private const ALLOWED_ROLES = ['owner', 'admin', 'operator'];

    public function index(Request $request, string $workspace): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        if (! $request->wantsJson()) {
            return view('console.warmups.seeds', ['workspace' => $workspace]);
        }

        $filters = $request->validate([
            'draw' => ['sometimes', 'integer', 'min:0'],
            'start' => ['sometimes', 'integer', 'min:0'],
            'length' => ['sometimes', 'integer', 'in:10,25,50,100'],
            'search.value' => ['sometimes', 'nullable', 'string', 'max:200'],
            'order.0.column' => ['sometimes', 'integer', 'min:0', 'max:4'],
            'order.0.dir' => ['sometimes', Rule::in(['asc', 'desc'])],
        ]);

        $base = WorkspaceSeedList::query()->where('workspace_id', $workspace->getKey());
        $recordsTotal = (clone $base)->count();
        $filtered = clone $base;
        $search = trim((string) ($filters['search']['value'] ?? ''));
        if ($search !== '') {
            $filtered->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhereHas('user', fn (Builder $userQuery) => $userQuery->where('username', 'like', '%'.$search.'%'));
            });
        }

        $recordsFiltered = (clone $filtered)->count();
        $orderColumns = ['id', 'user_id', 'name', 'id', 'created_at'];
        $orderIndex = (int) ($filters['order'][0]['column'] ?? 0);
        $orderColumn = $orderColumns[$orderIndex] ?? 'id';
        $orderDirection = $filters['order'][0]['dir'] ?? 'desc';
        $rows = $filtered->with('user:id,username')->withCount('emails')
            ->orderBy($orderColumn, $orderDirection)
            ->offset((int) ($filters['start'] ?? 0))
            ->limit((int) ($filters['length'] ?? 50))
            ->get()
            ->map(fn (WorkspaceSeedList $seedList): array => [
                'id' => (int) $seedList->getKey(),
                'user' => ['username' => $seedList->user?->username],
                'name' => $seedList->name,
                'seed_emails_count' => (int) $seedList->emails_count,
                'created_at' => $seedList->created_at?->toDateTimeString(),
                'is_active' => $seedList->is_active,
                'can_delete' => in_array($workspace->pivot->role, ['owner', 'admin'], true),
            ])->all();

        return response()->json([
            'draw' => (int) ($filters['draw'] ?? 0),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows,
        ]);
    }

    public function create(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);

        return view('console.warmups.partials.seed-create', ['workspace' => $workspace]);
    }

    public function store(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $workspace->seedLists()->create([
            'user_id' => $request->user()->getKey(),
            'name' => $data['name'],
            'is_active' => true,
        ]);

        return response()->json(['data' => 'Seed List Created']);
    }

    public function cleanForm(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $servers = ManagedServer::query()->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)->where('is_installed', true)->orderBy('name')->get(['id', 'name', 'ip_address']);

        return view('console.warmups.partials.seed-clean', [
            'workspace' => $workspace,
            'servers' => $servers,
            'today' => now()->toDateString(),
        ]);
    }

    public function clean(Request $request, string $workspace, WorkspaceSeedListAction $action, PmtaRemoteConfiguration $remote): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate([
            'server' => ['required', 'uuid'],
            'date' => ['required', 'date'],
        ]);
        $server = ManagedServer::query()->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)->where('is_installed', true)->whereKey($data['server'])->firstOrFail();
        $result = $action->cleanBouncedEmails(
            $workspace,
            $request->user(),
            $server,
            Carbon::parse($data['date'])->format('Y-m-d'),
            in_array($workspace->pivot->role, ['owner', 'admin'], true),
            $remote,
        );

        return response()->json(['data' => 'Removed '.$result.' Emails']);
    }

    public function show(Request $request, string $workspace, int $seed): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $seedList = $this->seedListForWorkspace($workspace, $seed);
        if (! $request->wantsJson()) {
            return view('console.warmups.seed-emails', [
                'workspace' => $workspace,
                'seedList' => $seedList,
            ]);
        }

        $filters = $request->validate([
            'draw' => ['sometimes', 'integer', 'min:0'],
            'start' => ['sometimes', 'integer', 'min:0'],
            'length' => ['sometimes', 'integer', 'in:10,25,50,100'],
            'search.value' => ['sometimes', 'nullable', 'string', 'max:200'],
            'order.0.column' => ['sometimes', 'integer', 'min:0', 'max:3'],
            'order.0.dir' => ['sometimes', Rule::in(['asc', 'desc'])],
        ]);
        $base = $seedList->emails();
        $recordsTotal = (clone $base)->count();
        $filtered = clone $base;
        $search = trim((string) ($filters['search']['value'] ?? ''));
        if ($search !== '') {
            $filtered->where('email', 'like', '%'.$search.'%');
        }
        $recordsFiltered = (clone $filtered)->count();
        $orderColumn = [(int) ($filters['order'][0]['column'] ?? 0)];
        $columns = ['id', 'email', 'created_at', 'updated_at'];
        $orderDirection = $filters['order'][0]['dir'] ?? 'desc';
        $rows = $filtered->orderBy($columns[$orderColumn[0]] ?? 'id', $orderDirection)
            ->offset((int) ($filters['start'] ?? 0))->limit((int) ($filters['length'] ?? 50))
            ->get()->map(fn (WorkspaceSeedEmail $email): array => [
                'id' => (int) $email->getKey(),
                'email' => $email->email,
                'created_at' => $email->created_at?->toDateTimeString(),
                'updated_at' => $email->updated_at?->toDateTimeString(),
            ])->all();

        return response()->json([
            'draw' => (int) ($filters['draw'] ?? 0),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows,
        ]);
    }

    public function uploadForm(Request $request, string $workspace, int $seed): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $seedList = $this->ownedSeedList($request->user(), $workspace, $seed);

        return view('console.warmups.partials.seed-upload', [
            'workspace' => $workspace,
            'seedList' => $seedList,
        ]);
    }

    public function upload(Request $request, string $workspace, int $seed, WorkspaceSeedListAction $action): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $seedList = $this->ownedSeedList($request->user(), $workspace, $seed);
        $data = $request->validate(['emails' => ['required', 'array', 'min:1']]);
        $result = $action->upload($seedList, $data['emails']);

        return response()->json(['data' => $result ? 'Inserted '.$result : 'Failed inserted '.$result], $result ? 200 : 400);
    }

    public function setStatus(Request $request, string $workspace, int $seed): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $seedList = $this->ownedSeedList($request->user(), $workspace, $seed);
        $request->validate(['status' => ['required']]);
        $seedList->fill(['is_active' => $request->boolean('status')])->save();

        return response()->json(['data' => 'Changed successfully']);
    }

    public function destroy(Request $request, string $workspace, int $seed): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin'], true), 403);
        $this->seedListForWorkspace($workspace, $seed)->delete();

        return response()->json(['data' => 'List Removed']);
    }

    public function loadEmails(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct']]);
        $seedLists = WorkspaceSeedList::query()->where('workspace_id', $workspace->getKey())
            ->whereIn('id', $data['ids'])->pluck('id');
        if ($seedLists->count() !== count($data['ids'])) {
            throw ValidationException::withMessages(['ids' => 'Choose seed lists from this workspace.']);
        }

        return response()->json(WorkspaceSeedEmail::query()->whereIn('workspace_seed_list_id', $seedLists)->pluck('email'));
    }

    public function editEmailForm(Request $request, string $workspace, int $email): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $seedEmail = $this->ownedEmail($request->user(), $workspace, $email);

        return view('console.warmups.partials.seed-email-edit', [
            'workspace' => $workspace,
            'seedEmail' => $seedEmail,
        ]);
    }

    public function updateEmail(Request $request, string $workspace, int $email): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $seedEmail = $this->ownedEmail($request->user(), $workspace, $email);
        $data = $request->validate(['email' => ['required', 'string', 'min:4', 'max:255']]);
        $seedEmail->fill($data)->save();

        return response()->json(['data' => 'Email updated']);
    }

    public function removeEmails(Request $request, string $workspace, int $seed, WorkspaceSeedListAction $action): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $seedList = $this->ownedSeedList($request->user(), $workspace, $seed);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct']]);
        $result = $action->removeEmails($seedList, $data['ids']);

        return response()->json(['data' => $result ? 'Removed '.$result : 'Failed removed '.$result], $result ? 200 : 400);
    }

    private function seedListForWorkspace(Workspace $workspace, int $seed): WorkspaceSeedList
    {
        return WorkspaceSeedList::query()->where('workspace_id', $workspace->getKey())->whereKey($seed)->firstOrFail();
    }

    private function ownedSeedList(User $user, Workspace $workspace, int $seed): WorkspaceSeedList
    {
        return WorkspaceSeedList::query()->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())->whereKey($seed)->firstOrFail();
    }

    private function ownedEmail(User $user, Workspace $workspace, int $email): WorkspaceSeedEmail
    {
        return WorkspaceSeedEmail::query()->whereKey($email)->whereHas('seedList', fn (Builder $query) => $query
            ->where('workspace_id', $workspace->getKey())->where('user_id', $user->getKey()))->firstOrFail();
    }

    private function workspaceFor(User $user, string $workspaceId): Workspace
    {
        $workspace = $user->workspaces()->where('workspaces.status', 'active')->whereKey($workspaceId)->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, self::ALLOWED_ROLES, true), 403);

        return $workspace;
    }
}
