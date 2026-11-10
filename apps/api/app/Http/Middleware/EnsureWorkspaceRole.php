<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $accessRole = $request->user()?->access_role;

        abort_unless(
            in_array($accessRole, ['owner', 'admin'], true) || in_array($accessRole, $roles, true),
            403,
        );

        return $next($request);
    }
}
