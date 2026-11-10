<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Authorization\AccessRolePolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceAccess
{
    public function __construct(private readonly AccessRolePolicy $accessRolePolicy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $routeName = (string) ($request->route()?->getName() ?? '');
        abort_unless(
            $this->accessRolePolicy->canAccessWebRoute($user, $routeName, $request->method()),
            403,
            'Your access role does not allow this page or action.',
        );

        return $next($request);
    }
}
