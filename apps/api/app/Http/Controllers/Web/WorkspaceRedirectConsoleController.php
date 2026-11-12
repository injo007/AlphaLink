<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CampaignEngagementEvent;
use App\Models\ManagedServer;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Campaigns\CampaignRedirectUrlPolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceRedirectConsoleController extends Controller
{
    private const ALLOWED_ROLES = ['owner', 'admin', 'operator', 'viewer', 'privacy_officer'];

    public function __construct(private readonly CampaignRedirectUrlPolicy $redirectUrlPolicy) {}

    public function index(Request $request, string $workspaceId): View
    {
        $workspace = $this->authorizedWorkspace($request->user(), $workspaceId);
        $servers = ManagedServer::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_installed', true)
            ->orderBy('name')
            ->get(['id', 'name', 'ip_address', 'state', 'is_active', 'install_parameters', 'last_probe_at'])
            ->map(function (ManagedServer $server): array {
                $installParameters = (array) $server->install_parameters;
                $trackingEnabled = (bool) ($installParameters['tracking'] ?? false);
                $httpsEnabled = (bool) ($installParameters['https'] ?? false);
                $trackingHost = $this->redirectUrlPolicy->readyTrackingHost($installParameters);
                $ready = $trackingHost !== null;

                return [
                    'id' => (string) $server->getKey(),
                    'name' => (string) $server->name,
                    'ip_address' => (string) $server->ip_address,
                    'state' => (string) $server->state,
                    'is_active' => (bool) $server->is_active,
                    'tracking_host' => $trackingHost,
                    'tracking_enabled' => $trackingEnabled,
                    'https_enabled' => $httpsEnabled,
                    'ready' => $ready,
                    'last_probe_at' => $server->last_probe_at,
                    'status' => ! $trackingEnabled ? 'Tracking off' : (! $httpsEnabled ? 'HTTPS required' : ($trackingHost === null ? 'DNS tracking host required' : 'Ready')),
                ];
            });

        $clicksLast24Hours = CampaignEngagementEvent::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('action', 'click')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $recentClicks = DB::table('campaign_engagement_events as events')
            ->join('campaigns', function (JoinClause $join): void {
                $join->on('campaigns.id', '=', 'events.campaign_id')
                    ->on('campaigns.workspace_id', '=', 'events.workspace_id');
            })
            ->leftJoin('isps', 'isps.id', '=', 'events.isp_id')
            ->where('events.workspace_id', $workspace->getKey())
            ->where('events.action', 'click')
            ->where('events.created_at', '>=', now()->subDay())
            ->orderByDesc('events.created_at')
            ->limit(25)
            ->get(['campaigns.name as campaign_name', 'isps.name as isp_name', 'events.created_at']);

        return view('console.redirects.index', [
            'workspace' => $workspace,
            'trackingServers' => $servers,
            'readyEndpointCount' => $servers->where('ready', true)->count(),
            'clicksLast24Hours' => $clicksLast24Hours,
            'recentClicks' => $recentClicks,
        ]);
    }

    private function authorizedWorkspace(User $user, string $workspaceId): Workspace
    {
        $workspace = $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, self::ALLOWED_ROLES, true), 403);

        return $workspace;
    }
}
