<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Authorization\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceTextMechanicConsoleController extends Controller
{
    public function index(Request $request, string $workspace): View
    {
        $workspace = app(WorkspaceAccess::class)->find($request->user(), $workspace);

        return view('console.tools.text-mechanic', ['workspace' => $workspace]);
    }
}
