<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceEncodingConsoleController extends Controller
{
    public function index(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);

        return view('console.tools.encoding', compact('workspace'));
    }

    public function popup(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);

        return view('console.tools.encoding', compact('workspace'));
    }

    public function encode(Request $request, string $workspace): JsonResponse
    {
        $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate([
            'string' => ['required', 'string'],
            'schema' => ['required', 'string'],
            'charset' => ['required', 'string'],
        ]);

        if ($data['schema'] === 'HQ') {
            $encoded = '';
            for ($index = 0, $length = strlen($data['string']); $index < $length; $index++) {
                $encoded .= '='.bin2hex($data['string'][$index]);
            }

            $result = '=?'.$data['charset'].'?Q?'.strtoupper($encoded).'?=';
        } elseif (in_array($data['schema'], ['B', 'Q'], true)) {
            $encoded = iconv_mime_encode('', $data['string'], [
                'scheme' => $data['schema'],
                'input-charset' => 'UTF-8',
                'output-charset' => $data['charset'],
                'line-length' => 76,
                'line-break-chars' => "\r\n",
            ]);
            $result = trim(str_replace(':', '', $encoded));
        } else {
            $result = $data['string'];
        }

        return response()->json($result);
    }

    private function workspaceFor(User $user, string $workspaceId): Workspace
    {
        return $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
    }
}
