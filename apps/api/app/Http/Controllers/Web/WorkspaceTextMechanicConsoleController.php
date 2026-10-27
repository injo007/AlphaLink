<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceTextMechanicConsoleController extends Controller
{
    public function index(Request $request, string $workspace): View
    {
        $workspace = $request->user()->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspace)
            ->firstOrFail();

        return view('console.tools.text-mechanic', ['workspace' => $workspace]);
    }
}
