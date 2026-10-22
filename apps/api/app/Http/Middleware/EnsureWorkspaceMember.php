<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $routeValue = $request->route('workspace');
        $workspaceId = $routeValue instanceof Workspace ? $routeValue->getKey() : $routeValue;

        $workspace = Workspace::query()
            ->whereKey($workspaceId)
            ->where('status', 'active')
            ->whereHas('users', fn ($query) => $query->whereKey($user->getKey()))
            ->first();

        // Hide workspace existence from non-members.
        abort_unless($workspace, 404);

        $member = $workspace->users()->whereKey($user->getKey())->firstOrFail();

        $request->attributes->set('alphalink.workspace', $workspace);
        $request->attributes->set('alphalink.workspace_role', $member->pivot->role);

        return $next($request);
    }
}
