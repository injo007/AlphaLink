<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class GlobalUserManagementController extends Controller
{
    private const IMPERSONATOR_SESSION_KEY = 'alphalink.impersonator_id';

    public function index(Request $request): View|JsonResponse
    {
        $this->authorizeUserManagement($request);
        $users = User::query()->orderBy('id')->get(['id', 'username', 'name', 'type', 'is_active', 'seen_at']);

        if ($request->wantsJson()) {
            return response()->json(['data' => $users->map(fn (User $user): array => [
                'id' => $user->getKey(),
                'username' => $user->username,
                'full_name' => $user->name,
                'type' => $user->type,
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
            'roles' => User::getRoles(),
            'workspace' => $this->layoutWorkspace($request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeUserManagement($request);
        $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);
        $data = $request->validate([
            'username' => ['required', 'alpha', 'min:4', 'max:255', $this->uniqueUsernameRule()],
            'full_name' => ['required', 'string', 'min:4', 'max:255'],
            'type' => ['required', 'string', Rule::in(User::TYPES)],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::in(array_keys(User::getRoles()))],
        ]);

        $password = Str::random(8);
        $user = User::query()->create([
            'name' => $data['full_name'],
            'username' => $data['username'],
            'email' => null,
            'password' => Hash::make($password),
            'type' => $data['type'],
            'roles' => array_values(array_unique($data['roles'] ?? [])),
            'is_active' => true,
        ]);

        return response()->json([
            'data' => 'User created',
            'info' => sprintf('%s:%s', $user->username, $password),
        ]);
    }

    public function edit(Request $request, User $user): View
    {
        $this->authorizeUserManagement($request);

        return view('console.global-users.edit', [
            'user' => $user,
            'types' => User::TYPES,
            'roles' => User::getRoles(),
            'workspace' => $this->layoutWorkspace($request->user()),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeUserManagement($request);
        $data = $request->validate([
            'username' => ['required', 'string', 'min:4', 'max:255', $this->uniqueUsernameRule($user)],
            'full_name' => ['required', 'string', 'min:4', 'max:255'],
            'type' => ['required', 'string', Rule::in(User::TYPES)],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::in(array_keys(User::getRoles()))],
        ]);

        $saved = $user->forceFill([
            'username' => $data['username'],
            'name' => $data['full_name'],
            'type' => $data['type'],
            'roles' => array_values(array_unique($data['roles'] ?? [])),
        ])->save();

        return response()->json(['data' => $saved ? 'User updated' : 'Failed'], $saved ? 200 : 400);
    }

    public function status(Request $request, User $user): JsonResponse
    {
        $actor = $this->authorizeUserManagement($request);
        abort_if($actor->is($user), 401, 'Not Allowed');
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $saved = $user->forceFill(['is_active' => (bool) $data['status']])->save();

        return response()->json(['data' => $saved ? 'Changed successfully' : 'Failed'], $saved ? 200 : 400);
    }

    public function password(Request $request, User $user): View
    {
        $this->authorizeUserManagement($request);

        return view('console.global-users.password', [
            'user' => $user,
            'workspace' => $this->layoutWorkspace($request->user()),
        ]);
    }

    public function updatePassword(Request $request, User $user): JsonResponse
    {
        $this->authorizeUserManagement($request);
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
        abort_unless($actor instanceof User && $actor->hasDmsRole('manage_users'), 403);

        return $actor;
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

    private function layoutWorkspace(User $user): ?Workspace
    {
        return $user->workspaces()
            ->where('workspaces.status', 'active')
            ->orderBy('workspaces.name')
            ->first();
    }
}
