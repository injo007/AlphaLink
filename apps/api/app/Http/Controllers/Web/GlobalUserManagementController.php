<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AccessRoleAssignment;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class GlobalUserManagementController extends Controller
{
    private const IMPERSONATOR_SESSION_KEY = 'alphalink.impersonator_id';

    public function index(Request $request): View|JsonResponse
    {
        $this->authorizeUserManagement($request);
        $users = User::query()->orderBy('id')->get(['id', 'username', 'name', 'type', 'access_role', 'is_active', 'seen_at', 'avatar_path']);

        if ($request->wantsJson()) {
            return response()->json(['data' => $users->map(fn (User $user): array => [
                'id' => $user->getKey(),
                'username' => $user->username,
                'full_name' => $user->name,
                'type' => $user->type,
                'access_role' => $user->access_role,
                'is_active' => $user->is_active,
                'seen_at' => $user->seen_at,
                'seen_at_human' => $user->seen_at?->diffForHumans(),
            ])->values()]);
        }

        return view('console.global-users.index', [
            'users' => $users,
            'workspace' => $this->layoutWorkspace($request->user()),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeUserManagement($request);

        return view('console.global-users.create', [
            'types' => User::TYPES,
            'accessRoles' => $this->assignableAccessRoles($request->user()),
            'permissionGroups' => User::PERMISSION_GROUPS,
            'permissionChoices' => User::permissionChoices(),
            'permissionDefaults' => User::DEFAULT_PERMISSIONS_BY_ACCESS_ROLE,
            'selectedPermissions' => User::defaultPermissions(User::ACCESS_ROLE_OPERATOR),
            'permissionsAreRoleDefaults' => true,
            'selectedAccessRole' => User::ACCESS_ROLE_OPERATOR,
            'mailerPermissions' => User::MAILER_SENDING_PERMISSIONS,
            'workspace' => $this->layoutWorkspace($request->user()),
        ]);
    }

    public function store(Request $request, AccessRoleAssignment $accessRoleAssignment): JsonResponse
    {
        $actor = $this->authorizeUserManagement($request);
        $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);
        $data = $request->validate([
            'username' => ['required', 'alpha', 'min:4', 'max:255', $this->uniqueUsernameRule()],
            'full_name' => ['required', 'string', 'min:4', 'max:255'],
            'type' => ['required', 'string', Rule::in(User::TYPES)],
            'access_role' => ['required', 'string', Rule::in($this->assignableAccessRoles($actor))],
            'permission_selection_submitted' => ['required', 'accepted'],
            'roles' => ['sometimes', 'array', 'max:64'],
            'roles.*' => ['string', Rule::in(array_keys(User::permissionChoices()))],
            'avatar' => $this->avatarUploadRules(),
        ]);

        $password = Str::random(8);
        $avatarPath = null;
        try {
            $user = DB::transaction(function () use ($actor, $data, $password, $request, $accessRoleAssignment, &$avatarPath): User {
                $permissions = in_array($data['access_role'], [User::ACCESS_ROLE_OWNER, User::ACCESS_ROLE_ADMIN], true)
                    ? null
                    : User::normalizePermissions($data['roles'] ?? []);
                $user = User::query()->create([
                    'name' => $data['full_name'],
                    'username' => $data['username'],
                    'email' => null,
                    'password' => Hash::make($password),
                    'type' => $data['type'],
                    'access_role' => User::ACCESS_ROLE_VIEWER,
                    'roles' => $permissions,
                    'is_active' => true,
                ]);

                if ($request->hasFile('avatar')) {
                    $avatarPath = $request->file('avatar')->store('user-avatars/'.$user->getKey(), 'local');
                    if (! is_string($avatarPath) || $avatarPath === '') {
                        throw ValidationException::withMessages(['avatar' => 'The image could not be stored. Please try again.']);
                    }

                    $user->forceFill(['avatar_path' => $avatarPath])->save();
                }

                $accessRoleAssignment->assign($actor, $user, $data['access_role']);

                return $user;
            });
        } catch (Throwable $exception) {
            if (is_string($avatarPath)) {
                Storage::disk('local')->delete($avatarPath);
            }

            throw $exception;
        }

        return response()->json([
            'data' => 'User created',
            'info' => sprintf('%s:%s', $user->username, $password),
        ]);
    }

    public function edit(Request $request, User $user): View
    {
        $actor = $this->authorizeUserManagement($request);
        $this->authorizeTarget($actor, $user);

        return view('console.global-users.edit', [
            'user' => $user,
            'types' => User::TYPES,
            'accessRoles' => $this->assignableAccessRoles($request->user(), $user),
            'permissionGroups' => User::PERMISSION_GROUPS,
            'permissionChoices' => User::permissionChoices(),
            'permissionDefaults' => User::DEFAULT_PERMISSIONS_BY_ACCESS_ROLE,
            'selectedPermissions' => $user->effectivePermissions(),
            'permissionsAreRoleDefaults' => $user->roles === null,
            'selectedAccessRole' => (string) $user->access_role,
            'mailerPermissions' => User::MAILER_SENDING_PERMISSIONS,
            'workspace' => $this->layoutWorkspace($request->user()),
        ]);
    }

    public function update(Request $request, User $user, AccessRoleAssignment $accessRoleAssignment): JsonResponse
    {
        $actor = $this->authorizeUserManagement($request);
        $this->authorizeTarget($actor, $user);
        $data = $request->validate([
            'username' => ['required', 'string', 'min:4', 'max:255', $this->uniqueUsernameRule($user)],
            'full_name' => ['required', 'string', 'min:4', 'max:255'],
            'type' => ['required', 'string', Rule::in(User::TYPES)],
            'access_role' => ['required', 'string', Rule::in($this->assignableAccessRoles($actor, $user))],
            'permission_selection_submitted' => ['required', 'accepted'],
            'roles' => ['sometimes', 'array', 'max:64'],
            'roles.*' => ['string', Rule::in(array_keys(User::permissionChoices()))],
            'avatar' => $this->avatarUploadRules(),
            'remove_avatar' => ['sometimes', 'boolean'],
        ]);

        $oldAvatarPath = $user->avatar_path;
        $newAvatarPath = null;
        $avatarPath = $oldAvatarPath;

        if ($request->hasFile('avatar')) {
            $newAvatarPath = $request->file('avatar')->store('user-avatars/'.$user->getKey(), 'local');
            if (! is_string($newAvatarPath) || $newAvatarPath === '') {
                throw ValidationException::withMessages(['avatar' => 'The image could not be stored. Please try again.']);
            }
            $avatarPath = $newAvatarPath;
        } elseif (filter_var($data['remove_avatar'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $avatarPath = null;
        }

        try {
            $saved = DB::transaction(function () use ($actor, $user, $data, $avatarPath, $accessRoleAssignment): bool {
                $permissions = in_array($data['access_role'], [User::ACCESS_ROLE_OWNER, User::ACCESS_ROLE_ADMIN], true)
                    ? $user->roles
                    : User::normalizePermissions($data['roles'] ?? []);
                $saved = $user->forceFill([
                    'username' => $data['username'],
                    'name' => $data['full_name'],
                    'type' => $data['type'],
                    'avatar_path' => $avatarPath,
                    'roles' => $permissions,
                ])->save();
                $accessRoleAssignment->assign($actor, $user, $data['access_role']);

                return $saved;
            });
        } catch (Throwable $exception) {
            if (is_string($newAvatarPath)) {
                Storage::disk('local')->delete($newAvatarPath);
            }

            throw $exception;
        }

        if (! $saved) {
            if (is_string($newAvatarPath)) {
                Storage::disk('local')->delete($newAvatarPath);
            }

            return response()->json(['data' => 'Failed'], 400);
        }

        if (is_string($oldAvatarPath) && $oldAvatarPath !== $avatarPath) {
            Storage::disk('local')->delete($oldAvatarPath);
        }

        return response()->json(['data' => $saved ? 'User updated' : 'Failed'], $saved ? 200 : 400);
    }

    public function avatar(Request $request, User $user): StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && ($actor->is($user) || $actor->hasRole('manage_users')), 403);
        abort_unless(is_string($user->avatar_path) && Storage::disk('local')->exists($user->avatar_path), 404);

        return Storage::disk('local')->response($user->avatar_path, null, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function status(Request $request, User $user): JsonResponse
    {
        $actor = $this->authorizeUserManagement($request);
        $this->authorizeTarget($actor, $user);
        abort_if($actor->is($user), 401, 'Not Allowed');
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $saved = $user->forceFill(['is_active' => (bool) $data['status']])->save();

        return response()->json(['data' => $saved ? 'Changed successfully' : 'Failed'], $saved ? 200 : 400);
    }

    public function password(Request $request, User $user): View
    {
        $actor = $this->authorizeUserManagement($request);
        $this->authorizeTarget($actor, $user);

        return view('console.global-users.password', [
            'user' => $user,
            'workspace' => $this->layoutWorkspace($request->user()),
        ]);
    }

    public function updatePassword(Request $request, User $user): JsonResponse
    {
        $actor = $this->authorizeUserManagement($request);
        $this->authorizeTarget($actor, $user);
        $data = $request->validate(['password' => ['required', Password::default()]]);
        $saved = $user->forceFill(['password' => Hash::make($data['password'])])->save();

        return response()->json(['data' => $saved ? 'Changed successfully' : 'Failed'], $saved ? 200 : 400);
    }

    public function startImpersonating(Request $request, User $user): RedirectResponse
    {
        $actor = $this->authorizeUserManagement($request);
        abort_unless($actor->canImpersonate(), 403);
        abort_unless($user->canBeImpersonated() && $user->is_active && ! $actor->is($user), 403, 'This account cannot be impersonated.');
        abort_if($request->session()->has(self::IMPERSONATOR_SESSION_KEY), 409, 'An impersonation session is already active.');

        $request->session()->put(self::IMPERSONATOR_SESSION_KEY, $actor->getKey());
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('console.index');
    }

    public function stopImpersonating(Request $request): RedirectResponse
    {
        $actorId = $request->session()->pull(self::IMPERSONATOR_SESSION_KEY);
        abort_unless(is_numeric($actorId), 403, 'No impersonation session is active.');
        $actor = User::query()->whereKey((int) $actorId)->where('is_active', true)->firstOrFail();
        Auth::login($actor);
        $request->session()->regenerate();

        return redirect()->route('console.global-users.index');
    }

    private function authorizeUserManagement(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->hasRole('manage_users'), 403);

        return $actor;
    }

    private function authorizeTarget(User $actor, User $target): void
    {
        abort_if($target->isOwner() && ! $actor->isOwner(), 403, 'Only the Owner can manage the Owner account.');
    }

    /** @return list<string> */
    private function assignableAccessRoles(User $actor, ?User $target = null): array
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

    private function uniqueUsernameRule(?User $ignore = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
            if (! is_string($value)) {
                return;
            }

            $query = User::query()->whereRaw('LOWER(username) = LOWER(?)', [$value]);
            if ($ignore instanceof User) {
                $query->where('id', '!=', $ignore->getKey());
            }

            if ($query->exists()) {
                $fail('The username has already been taken.');
            }
        };
    }

    /** @return array<int, string> */
    private function avatarUploadRules(): array
    {
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=2048,max_height=2048'];
    }

    private function layoutWorkspace(User $user): ?Workspace
    {
        return $user->workspaces()
            ->where('workspaces.status', 'active')
            ->orderBy('workspaces.name')
            ->first();
    }
}
