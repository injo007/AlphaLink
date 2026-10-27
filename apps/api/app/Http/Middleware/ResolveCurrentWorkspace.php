<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCurrentWorkspace
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        $workspaces = $user->workspaces()->where('workspaces.status', 'active');
        $workspaceSlug = $request->route('workspaceSlug');

        if (is_string($workspaceSlug) && $workspaceSlug !== '') {
            $workspace = $workspaces->where('workspaces.slug', $workspaceSlug)->first();
        } else {
            $activeWorkspaceId = $request->session()->get('active_workspace_id');
            $workspace = $activeWorkspaceId === null
                ? null
                : (clone $workspaces)->whereKey($activeWorkspaceId)->first();

            if (! $workspace) {
                $workspace = $workspaces->orderBy('workspaces.name')->first();
            }
        }

        abort_unless($workspace instanceof Workspace, 404);

        $request->session()->put('active_workspace_id', $workspace->getKey());
        $request->attributes->set('alphalink.workspace', $workspace);
        $request->attributes->set('alphalink.workspace_id', (string) $workspace->getKey());

        $route = $request->route();
        $routeParameters = $route->parameters();
        unset($routeParameters['workspaceSlug']);

        // Clean URLs deliberately omit the workspace ID, while the existing
        // controller actions still accept it as their first route argument.
        // Route exposes its bound parameter array publicly; preserve the
        // controller's expected argument order when inserting the active ID.
        $route->parameters = ['workspace' => (string) $workspace->getKey()] + $routeParameters;

        return $next($request);
    }
}
