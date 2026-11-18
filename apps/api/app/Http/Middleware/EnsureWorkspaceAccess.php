<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AccessRolePolicy;
use App\Services\Authorization\WorkspaceAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceAccess
{
    public function __construct(
        private readonly AccessRolePolicy $accessRolePolicy,
        private readonly WorkspaceAccess $workspaceAccess,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $routeName = (string) ($request->route()?->getName() ?? '');
        $workspace = $request->attributes->get('alphalink.workspace');
        $workspaceRole = $request->attributes->get('alphalink.workspace_role')
            ?? ($workspace instanceof Workspace ? $workspace->pivot?->role : null);
        $workspaceParameter = $request->route('workspace');

        if (! $workspace instanceof Workspace && ($workspaceParameter instanceof Workspace || is_string($workspaceParameter))) {
            $workspaceId = $workspaceParameter instanceof Workspace
                ? (string) $workspaceParameter->getKey()
                : $workspaceParameter;
            $workspace = $this->workspaceAccess->find($user, $workspaceId);
            $workspaceRole ??= $workspace->pivot?->role;
        }

        abort_unless(
            $this->accessRolePolicy->canAccessWebRoute(
                $user,
                $routeName,
                $request->method(),
                is_string($workspaceRole) ? $workspaceRole : null,
                $workspace instanceof Workspace,
            ),
            403,
            'Your access role does not allow this page or action.',
        );

        return $next($request);
    }
}
