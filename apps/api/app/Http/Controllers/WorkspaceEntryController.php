<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Web\WorkspaceAnalyticsConsoleController;
use App\Http\Controllers\Web\WorkspaceConsoleController;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceEntryController extends Controller
{
    public function dashboard(Request $request): View
    {
        return app(WorkspaceAnalyticsConsoleController::class)->index($request, $this->workspaceId($request));
    }

    public function campaigns(Request $request): View
    {
        return app(WorkspaceConsoleController::class)->campaigns($request, $this->workspaceId($request));
    }

    private function workspaceId(Request $request): string
    {
        return (string) $request->attributes->get('alphalink.workspace_id');
    }
}
