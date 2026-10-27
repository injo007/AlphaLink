<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectLegacyWorkspaceUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true) || $request->ajax() || $request->wantsJson()) {
            return $next($request);
        }

        $workspaceId = $request->route('workspace');
        $workspace = $request->user()?->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->first();

        abort_unless($workspace instanceof Workspace, 404);

        $request->session()->put('active_workspace_id', $workspace->getKey());

        $legacyPrefix = 'app/workspaces/'.(string) $workspace->getKey();
        $path = trim($request->path(), '/');
        $suffix = ltrim(substr($path, strlen($legacyPrefix)), '/');
        $cleanPath = match ($suffix) {
            '', 'analytics' => 'dashboard/main',
            'campaigns' => 'mailer/campaigns',
            default => str_starts_with($suffix, 'campaigns/')
                ? 'mailer/campaigns/'.substr($suffix, strlen('campaigns/'))
                : $suffix,
        };
        $destination = url('/'.$cleanPath);
        $query = $request->getQueryString();

        return redirect()->to($query ? $destination.'?'.$query : $destination);
    }
}
