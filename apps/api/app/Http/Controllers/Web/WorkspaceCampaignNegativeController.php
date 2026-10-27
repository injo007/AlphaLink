<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCampaignNegative;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class WorkspaceCampaignNegativeController extends Controller
{
    private const CAMPAIGN_ROLES = ['owner', 'admin', 'operator'];

    public function index(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request, $workspace);
        $negatives = $workspace->campaignNegatives()
            ->where('created_by', $request->user()->getKey())
            ->orderByDesc('created_at')
            ->get();

        return view('console.campaign-negatives.index', compact('workspace', 'negatives'));
    }

    public function create(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request, $workspace);

        return view('console.campaign-negatives.create', compact('workspace'));
    }

    public function store(Request $request, string $workspace): RedirectResponse
    {
        $workspace = $this->workspaceFor($request, $workspace);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:csv,txt,html'],
        ]);
        $file = $data['file'];
        $path = $file->store('campaign-negatives/'.$workspace->getKey().'/'.$request->user()->getKey(), 'local');
        abort_unless(is_string($path) && $path !== '', 500, 'AlphaLink could not store the negative file.');

        try {
            $negative = DB::transaction(function () use ($request, $workspace, $data, $file, $path): WorkspaceCampaignNegative {
                $negative = $workspace->campaignNegatives()->create([
                    'created_by' => $request->user()->getKey(),
                    'name' => trim($data['name']),
                    'path' => $path,
                    'original_filename' => Str::limit(basename($file->getClientOriginalName()) ?: 'negative.txt', 255, ''),
                    'sha256' => hash_file('sha256', $file->getRealPath()),
                    'size_bytes' => $file->getSize(),
                ]);

                $this->recordAudit($request, $workspace, 'campaign.negative.uploaded', $negative);

                return $negative;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return redirect()->route('console.campaign-negatives.index', $workspace->getKey())
            ->with('status', 'Negative file uploaded. It is available in your campaign setup.');
    }

    public function download(Request $request, string $workspace, int $negativeId)
    {
        $workspace = $this->workspaceFor($request, $workspace);
        $negative = $this->ownedNegative($workspace, $request->user(), $negativeId);
        abort_unless(Storage::disk('local')->exists($negative->path), 404);

        $safeName = trim(preg_replace('/[\\\\\/\r\n]+/', '_', $negative->name) ?? 'negative');

        return Storage::disk('local')->download($negative->path, $safeName.'_'.now()->timestamp.'.txt');
    }

    public function destroy(Request $request, string $workspace, int $negativeId): RedirectResponse
    {
        $workspace = $this->workspaceFor($request, $workspace);
        $negative = $this->ownedNegative($workspace, $request->user(), $negativeId);
        $path = $negative->path;

        DB::transaction(function () use ($request, $workspace, $negative): void {
            $this->recordAudit($request, $workspace, 'campaign.negative.deleted', $negative);
            $negative->delete();
        });
        Storage::disk('local')->delete($path);

        return redirect()->route('console.campaign-negatives.index', $workspace->getKey())
            ->with('status', 'Negative file deleted. Drafts that referenced it retain their saved ID, but the file is no longer available.');
    }

    private function workspaceFor(Request $request, string $workspaceId): Workspace
    {
        $workspace = $request->user()->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, self::CAMPAIGN_ROLES, true), 403);

        return $workspace;
    }

    private function ownedNegative(Workspace $workspace, User $user, int $negativeId): WorkspaceCampaignNegative
    {
        return $workspace->campaignNegatives()
            ->where('created_by', $user->getKey())
            ->whereKey($negativeId)
            ->firstOrFail();
    }

    private function recordAudit(Request $request, Workspace $workspace, string $action, WorkspaceCampaignNegative $negative): void
    {
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => $action,
            'subject_type' => 'campaign_negative',
            'subject_id' => (string) $negative->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => [
                'name' => $negative->name,
                'sha256' => $negative->sha256,
                'size_bytes' => $negative->size_bytes,
            ],
            'occurred_at' => now(),
        ]);
    }
}
