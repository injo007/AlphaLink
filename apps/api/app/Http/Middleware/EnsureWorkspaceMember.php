<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $routeValue = $request->route('workspace');
        $workspaceId = $routeValue instanceof Workspace ? $routeValue->getKey() : $routeValue;

        $workspaceQuery = Workspace::query()
            ->whereKey($workspaceId)
            ->where('status', 'active');
        if (! $user->hasFullAccess()) {
            $workspaceQuery->whereHas('users', fn ($query) => $query->whereKey($user->getKey()));
        }
        $workspace = $workspaceQuery->first();

        // Hide workspace existence from non-members.
        abort_unless($workspace, 404);

        $role = $user->access_role;
        if (! $user->hasFullAccess()) {
            $member = $workspace->users()->whereKey($user->getKey())->firstOrFail();
            $role = $member->pivot->role;
        }

        $request->attributes->set('alphalink.workspace', $workspace);
        $request->attributes->set('alphalink.workspace_role', $role);
        if ($request->hasSession()) {
            $request->session()->put('active_workspace_id', $workspace->getKey());
        }

        return $next($request);
    }
}
