<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AccessRoleAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class WorkspaceUserManagementController extends Controller
{
    public function index(Request $request, string $workspace): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeManager($workspace);
        $users = $workspace->users()->orderBy('users.id')->get([
            'users.id', 'users.name', 'users.email', 'users.username', 'users.type', 'users.access_role',
            'users.roles', 'users.is_active', 'users.seen_at',
        ]);

        if ($request->wantsJson()) {
            return response()->json(['data' => $users->map(fn (User $user): array => [
                'id' => $user->getKey(),
                'username' => $user->username,
                'full_name' => $user->name,
                'email' => $user->email,
                'type' => $user->type,
                'access_role' => $user->access_role,
                'is_active' => $user->is_active,
                'seen_at' => $user->seen_at,
                'seen_at_human' => $user->seen_at?->diffForHumans(),
                'workspace_role' => $user->pivot->role,
            ])->values()]);
        }

        return view('console.users.index', [
            'workspace' => $workspace,
            'users' => $users,
            'availableUsers' => User::query()
                ->whereDoesntHave('workspaces', fn ($query) => $query->whereKey($workspace->getKey()))
                ->orderBy('name')
                ->get(['id', 'name', 'username', 'type', 'access_role', 'is_active']),
            'types' => User::TYPES,
            'workspaceRoles' => $this->assignableRoles($request->user()),
        ]);
    }

    public function create(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeManager($workspace);

        return view('console.users.create', [
            'workspace' => $workspace,
            'types' => User::TYPES,
            'workspaceRoles' => $this->assignableRoles($request->user()),
        ]);
    }

    public function store(Request $request, string $workspace, AccessRoleAssignment $accessRoleAssignment): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeManager($workspace);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'username' => ['required', 'alpha', 'min:4', 'max:255', Rule::unique('users', 'username')],
            'name' => ['required', 'string', 'min:4', 'max:255'],
            'type' => ['required', 'string', Rule::in(User::TYPES)],
            'workspace_role' => ['required', 'string', Rule::in($this->assignableRoles($request->user()))],
        ]);

        $password = Str::random(8);
        $user = DB::transaction(function () use ($request, $workspace, $data, $password, $accessRoleAssignment): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'username' => Str::lower($data['username']),
                'password' => Hash::make($password),
                'type' => $data['type'],
                'access_role' => User::ACCESS_ROLE_VIEWER,
                'is_active' => true,
            ]);
            $accessRoleAssignment->assign($request->user(), $user, $data['workspace_role']);
            $workspace->users()->attach($user->getKey(), ['role' => $data['workspace_role']]);

            return $user;
        });

        return response()->json([
            'data' => 'User created',
            'info' => sprintf('%s:%s', $user->username, $password),
            'login_email' => $user->email,
        ]);
    }

    public function attachExisting(Request $request, string $workspace, AccessRoleAssignment $accessRoleAssignment): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeManager($workspace);
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'workspace_role' => ['required', 'string', Rule::in($this->assignableRoles($request->user()))],
        ]);
        abort_if($workspace->users()->whereKey($data['user_id'])->exists(), 409, 'This account already belongs to the workspace.');
        $member = User::query()->findOrFail($data['user_id']);
        $this->authorizeTarget($request->user(), $member);
        DB::transaction(function () use ($workspace, $member, $data, $request, $accessRoleAssignment): void {
            $accessRoleAssignment->assign($request->user(), $member, $data['workspace_role']);
            $workspace->users()->attach($member->getKey(), [
                'role' => $data['workspace_role'],
                'joined_at' => now(),
            ]);
        });

        return response()->json(['data' => 'Account added to workspace']);
    }

    public function edit(Request $request, string $workspace, int $user): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeManager($workspace);

        $member = $this->memberFor($workspace, $user);
        $this->authorizeTarget($request->user(), $member);

        return view('console.users.edit', [
            'workspace' => $workspace,
            'user' => $member,
            'types' => User::TYPES,
            'workspaceRoles' => $this->assignableRoles($request->user(), $member),
        ]);
    }

    public function update(Request $request, string $workspace, int $user, AccessRoleAssignment $accessRoleAssignment): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeManager($workspace);
        $member = $this->memberFor($workspace, $user);
        $this->authorizeTarget($request->user(), $member);
        $data = $request->validate([
            'username' => ['required', 'string', 'min:4', 'max:255', Rule::unique('users', 'username')->ignoreModel($member)],
            'name' => ['required', 'string', 'min:4', 'max:255'],
            'type' => ['required', 'string', Rule::in(User::TYPES)],
            'workspace_role' => ['required', 'string', Rule::in($this->assignableRoles($request->user(), $member))],
        ]);

        DB::transaction(function () use ($request, $workspace, $member, $data, $accessRoleAssignment): void {
            $member->forceFill([
                'username' => $data['username'],
                'name' => $data['name'],
                'type' => $data['type'],
            ])->save();
            $accessRoleAssignment->assign($request->user(), $member, $data['workspace_role']);
            $workspace->users()->updateExistingPivot($member->getKey(), ['role' => $data['workspace_role']]);
        });

        return response()->json(['data' => 'User updated']);
    }

    public function status(Request $request, string $workspace, int $user): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeManager($workspace);
        $member = $this->memberFor($workspace, $user);
        $this->authorizeTarget($request->user(), $member);
        abort_if($request->user()->is($member), 401, 'Not Allowed');
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $member->forceFill(['is_active' => (bool) $data['status']])->save();

        return response()->json(['data' => 'Changed successfully']);
    }

    public function password(Request $request, string $workspace, int $user): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeManager($workspace);
        $member = $this->memberFor($workspace, $user);
        $this->authorizeTarget($request->user(), $member);

        return view('console.users.password', [
            'workspace' => $workspace,
            'user' => $member,
        ]);
    }

    public function updatePassword(Request $request, string $workspace, int $user): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeManager($workspace);
        $member = $this->memberFor($workspace, $user);
        $this->authorizeTarget($request->user(), $member);
        $data = $request->validate(['password' => ['required', Password::default()]]);
        $member->forceFill(['password' => Hash::make($data['password'])])->save();

        return response()->json(['data' => 'Changed successfully']);
    }

    private function workspaceFor(User $actor, string $workspaceId): Workspace
    {
        return $actor->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
    }

    private function memberFor(Workspace $workspace, int $userId): User
    {
        return $workspace->users()->whereKey($userId)->firstOrFail();
    }

    private function authorizeManager(Workspace $workspace): void
    {
        abort_unless(request()->user()?->hasFullAccess(), 403);
    }

    private function authorizeTarget(User $actor, User $target): void
    {
        abort_if($target->isOwner() && ! $actor->isOwner(), 403, 'Only the Owner can manage the Owner account.');
    }

    /** @return list<string> */
    private function assignableRoles(User $actor, ?User $target = null): array
    {
        if ($target?->isOwner()) {
            return $actor->isOwner() ? [User::ACCESS_ROLE_OWNER] : [];
        }

        $roles = array_values(array_filter(
            User::ACCESS_ROLES,
            static fn (string $role): bool => $role !== User::ACCESS_ROLE_OWNER || $actor->isOwner(),
        ));

        return array_values(array_unique($roles));
    }
}
