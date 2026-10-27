<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCampaignHeader;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceCampaignHeaderController extends Controller
{
    private const CAMPAIGN_ROLES = ['owner', 'admin', 'operator'];

    public function index(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceFor($request, $workspaceId);

        return response()->json(['headers' => $this->visibleHeaders($workspace, $request->user())->get(['id', 'name'])]);
    }

    public function show(Request $request, string $workspaceId, int $headerId): JsonResponse
    {
        $workspace = $this->workspaceFor($request, $workspaceId);
        $header = $this->visibleHeaders($workspace, $request->user())->whereKey($headerId)->firstOrFail();

        return response()->json(['data' => $header->text]);
    }

    public function store(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceFor($request, $workspaceId);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'text' => ['required', 'string', 'max:65535'],
        ]);
        $header = DB::transaction(function () use ($request, $workspace, $data): WorkspaceCampaignHeader {
            $header = $workspace->campaignHeaders()->create([
                'created_by' => $request->user()->getKey(),
                'name' => $data['name'],
                'text' => $data['text'],
            ]);

            $this->recordAudit($request, $workspace, 'campaign.header.created', $header);

            return $header;
        });

        return response()->json([
            'status' => true,
            'header_id' => $header->getKey(),
            'headers' => $this->visibleHeaders($workspace, $request->user())->get(['id', 'name']),
        ]);
    }

    public function destroy(Request $request, string $workspaceId, int $headerId): JsonResponse
    {
        $workspace = $this->workspaceFor($request, $workspaceId);
        $header = $workspace->campaignHeaders()->whereKey($headerId)->firstOrFail();
        $role = $workspace->getRelation('authorized_member')->pivot->role;
        abort_unless(in_array($role, ['owner', 'admin'], true) || (int) $header->created_by === (int) $request->user()->getKey(), 403);

        DB::transaction(function () use ($request, $workspace, $header): void {
            $this->recordAudit($request, $workspace, 'campaign.header.deleted', $header);
            $header->delete();
        });

        return response()->json([
            'status' => true,
            'headers' => $this->visibleHeaders($workspace, $request->user())->get(['id', 'name']),
        ]);
    }

    private function workspaceFor(Request $request, string $workspaceId): Workspace
    {
        $workspace = Workspace::query()
            ->whereKey($workspaceId)
            ->whereHas('users', fn ($query) => $query->whereKey($request->user()->getKey()))
            ->firstOrFail();
        $member = $workspace->users()->whereKey($request->user()->getKey())->firstOrFail();
        abort_unless(in_array($member->pivot->role, self::CAMPAIGN_ROLES, true), 403);
        $workspace->setRelation('authorized_member', $member);

        return $workspace;
    }

    private function visibleHeaders(Workspace $workspace, User $user): HasMany
    {
        $role = $workspace->getRelation('authorized_member')->pivot->role;

        return $workspace->campaignHeaders()
            ->when($role === 'operator', fn ($query) => $query->where('created_by', $user->getKey()))
            ->orderBy('name')
            ->orderBy('id');
    }

    private function recordAudit(Request $request, Workspace $workspace, string $action, WorkspaceCampaignHeader $header): void
    {
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => $action,
            'subject_type' => 'campaign_header',
            'subject_id' => (string) $header->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => ['name' => $header->name],
            'occurred_at' => now(),
        ]);
    }
}
