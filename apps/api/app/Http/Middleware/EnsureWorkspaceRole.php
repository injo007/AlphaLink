<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Authorization\AccessRolePolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceRole
{
    public function __construct(private readonly AccessRolePolicy $accessRolePolicy) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && (
                $user->hasFullAccess()
                || ($user->roles === null && in_array($user->access_role, $roles, true))
                || $this->accessRolePolicy->canAccessCustomApiRequest($user, $request)
            ),
            403,
        );

        return $next($request);
    }
}
