<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ContactListMembershipEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkspaceConsoleController extends Controller
{
    private const CAMPAIGN_ROLES = ['owner', 'admin', 'operator'];

    private const AUDIENCE_ROLES = ['owner', 'admin', 'operator', 'privacy_officer'];

    public function index(Request $request): View|RedirectResponse
    {
        $workspaces = $request->user()->workspaces()
            ->where('workspaces.status', 'active')
            ->orderBy('workspaces.name')
            ->get(['workspaces.id', 'workspaces.name', 'workspaces.slug']);

        if ($workspaces->count() === 1) {
            return redirect()->route('console.workspace', $workspaces->first()->id);
        }

        return view('console.index', ['workspaces' => $workspaces]);
    }

    public function show(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $role = $workspace->pivot->role;
        $canViewCampaigns = in_array($role, self::CAMPAIGN_ROLES, true);
        $canViewAudiences = in_array($role, self::AUDIENCE_ROLES, true);

        $campaigns = $canViewCampaigns
            ? $workspace->campaigns()->with('latestRevision')->orderByDesc('created_at')->limit(5)->get()
            : collect();

        $audienceLists = $canViewAudiences
            ? $workspace->audienceLists()
                ->withCount(['memberships as active_members_count' => fn ($query) => $query->where('status', 'active')])
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
            : collect();

        return view('console.workspace', [
            'workspace' => $workspace,
            'role' => $role,
            'contactCount' => $canViewAudiences ? $workspace->contacts()->where('status', 'active')->count() : null,
            'campaignCount' => $canViewCampaigns ? $workspace->campaigns()->count() : null,
            'audienceListCount' => $canViewAudiences ? $workspace->audienceLists()->count() : null,
            'campaigns' => $campaigns,
            'audienceLists' => $audienceLists,
            'canViewCampaigns' => $canViewCampaigns,
            'canViewAudiences' => $canViewAudiences,
        ]);
    }

    public function campaigns(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);

        $campaigns = $workspace->campaigns()
            ->with('latestRevision')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('console.campaigns.index', compact('workspace', 'campaigns'));
    }

    public function campaign(Request $request, string $workspace, string $campaign): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);

        $campaign = $workspace->campaigns()->with('latestRevision')->whereKey($campaign)->firstOrFail();
        $revisions = $campaign->revisions()->paginate(20);

        return view('console.campaigns.show', compact('workspace', 'campaign', 'revisions'));
    }

    public function audienceLists(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::AUDIENCE_ROLES);

        $audienceLists = $workspace->audienceLists()
            ->withCount(['memberships as active_members_count' => fn ($query) => $query->where('status', 'active')])
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('console.audiences.index', compact('workspace', 'audienceLists'));
    }

    public function audienceList(Request $request, string $workspace, string $audienceList): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::AUDIENCE_ROLES);

        $audienceList = $workspace->audienceLists()
            ->withCount(['memberships as active_members_count' => fn ($query) => $query->where('status', 'active')])
            ->whereKey($audienceList)
            ->firstOrFail();

        $membershipEvents = ContactListMembershipEvent::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('audience_list_id', $audienceList->getKey())
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get(['id', 'action', 'source', 'occurred_at']);

        return view('console.audiences.show', compact('workspace', 'audienceList', 'membershipEvents'));
    }

    private function workspaceFor(User $user, string $workspaceId): Workspace
    {
        return $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
    }

    /** @param array<int, string> $allowedRoles */
    private function authorizeRole(Workspace $workspace, array $allowedRoles): void
    {
        abort_unless(in_array($workspace->pivot->role, $allowedRoles, true), 403);
    }
}
