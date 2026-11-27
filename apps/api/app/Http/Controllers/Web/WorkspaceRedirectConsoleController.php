<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CampaignEngagementEvent;
use App\Models\ManagedServer;
use App\Models\ManagedServerIpDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\WorkspaceAccess;
use App\Services\Campaigns\CampaignRedirectUrlPolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceRedirectConsoleController extends Controller
{
    private const ALLOWED_ROLES = ['owner', 'admin', 'operator', 'viewer', 'privacy_officer'];

    public function __construct(private readonly CampaignRedirectUrlPolicy $redirectUrlPolicy) {}

    public function index(Request $request, string $workspaceId): View
    {
        $workspace = $this->authorizedWorkspace($request->user(), $workspaceId);
        $servers = ManagedServer::withTrashed()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_installed', true)
            ->where(function ($query): void {
                $query->whereNull('deleted_at')
                    ->orWhere('deleted_at', '>=', now()->subDays(30));
            })
            ->with(['ipDomainAssignments' => fn ($query) => $query
                ->with(['ip:id,ip,type', 'domain:id,name'])
                ->orderBy('managed_server_ip_id')
                ->orderBy('hostname')])
            ->orderBy('name')
            ->get(['id', 'name', 'ip_address', 'state', 'is_active', 'is_installed', 'install_parameters', 'last_probe_at', 'deleted_at'])
            ->flatMap(function (ManagedServer $server): array {
                $installParameters = (array) $server->install_parameters;
                $trackingEnabled = (bool) ($installParameters['tracking'] ?? false);
                $httpsEnabled = (bool) ($installParameters['tracking_https_ready'] ?? false);
                $assignments = $server->ipDomainAssignments;

                if ($assignments->isNotEmpty()) {
                    return $assignments->map(function (ManagedServerIpDomain $assignment) use ($server, $installParameters): array {
                        $trackingHost = trim((string) $assignment->tracking_host);
                        $trackingEnabled = (bool) ($installParameters['tracking'] ?? false) && $trackingHost !== '';
                        $httpsEnabled = (bool) $assignment->tracking_https_ready;
                        $readyHost = $this->redirectUrlPolicy->readyTrackingHost([
                            'tracking' => $trackingEnabled,
                            'tracking_https_ready' => $httpsEnabled,
                            'tracking_host' => $trackingHost,
                        ]);

                        return $this->endpointRow(
                            $server,
                            (string) $assignment->hostname,
                            $assignment->ip?->ip ?: $server->ip_address,
                            $readyHost ?? ($trackingHost !== '' ? $trackingHost : null),
                            $trackingEnabled,
                            $httpsEnabled,
                            $readyHost !== null,
                        );
                    })->all();
                }

                // Older installs only saved one server-level tracking hostname.
                $trackingHost = $this->redirectUrlPolicy->readyTrackingHost($installParameters);

                return [$this->endpointRow(
                    $server,
                    $trackingHost,
                    $server->ip_address,
                    $trackingHost,
                    $trackingEnabled,
                    $httpsEnabled,
                    $trackingHost !== null,
                )];
            });

        $filters = [
            'search' => is_string($request->query('search')) ? trim($request->query('search')) : '',
            'server' => is_string($request->query('server')) ? trim($request->query('server')) : '',
            'status' => is_string($request->query('status')) && in_array($request->query('status'), [
                'ready', 'attention', 'tracking_off', 'https_required', 'dns_required', 'active', 'inactive', 'retained',
            ], true) ? $request->query('status') : '',
        ];

        $baseFilteredServers = $servers->filter(function (array $server) use ($filters): bool {
            if ($filters['server'] !== '' && $server['id'] !== $filters['server']) {
                return false;
            }

            if ($filters['search'] === '') {
                return true;
            }

            $searchableText = implode(' ', array_filter([
                $server['name'],
                $server['ip_address'],
                $server['domain'],
                $server['tracking_host'],
            ]));

            return Str::contains(Str::lower($searchableText), Str::lower($filters['search']));
        })->values();

        $filterCounts = [
            'all' => $baseFilteredServers->count(),
            'ready' => $baseFilteredServers->filter(fn (array $server): bool => $server['ready'] && ! $server['retained'])->count(),
            'attention' => $baseFilteredServers->filter(fn (array $server): bool => ! $server['ready'] && ! $server['retained'])->count(),
            'retained' => $baseFilteredServers->where('retained', true)->count(),
        ];

        $filteredServers = $baseFilteredServers->filter(function (array $server) use ($filters): bool {
            return match ($filters['status']) {
                'ready' => $server['ready'] && ! $server['retained'],
                'attention' => ! $server['ready'] && ! $server['retained'],
                'tracking_off' => ! $server['tracking_enabled'] && ! $server['retained'],
                'https_required' => $server['tracking_enabled'] && ! $server['https_enabled'] && ! $server['retained'],
                'dns_required' => $server['tracking_enabled'] && $server['https_enabled'] && ! $server['ready'] && ! $server['retained'],
                'active' => $server['is_active'] && ! $server['retained'],
                'inactive' => ! $server['is_active'] && ! $server['retained'],
                'retained' => $server['retained'],
                default => true,
            };
        })->values();

        $serverOptions = $servers->groupBy('id')->map(function ($endpoints): array {
            $server = $endpoints->first();

            return [
                'id' => $server['id'],
                'name' => $server['name'],
                'ip_address' => $server['ip_address'],
                'endpoint_count' => $endpoints->count(),
            ];
        })->values();

        $perPage = 25;
        $lastPage = max(1, (int) ceil($filteredServers->count() / $perPage));
        $currentPage = min(max(1, LengthAwarePaginator::resolveCurrentPage()), $lastPage);
        $trackingServers = new LengthAwarePaginator(
            $filteredServers->forPage($currentPage, $perPage)->values(),
            $filteredServers->count(),
            $perPage,
            $currentPage,
            [
                'path' => $request->url(),
                'query' => array_filter($filters, fn (string $value): bool => $value !== ''),
            ],
        );

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
            'trackingServers' => $trackingServers,
            'trackingEndpointCount' => $servers->count(),
            'serverOptions' => $serverOptions,
            'filters' => $filters,
            'filterCounts' => $filterCounts,
            'readyEndpointCount' => $servers->where('ready', true)->count(),
            'retainedEndpointCount' => $servers->where('retained', true)->count(),
            'clicksLast24Hours' => $clicksLast24Hours,
            'recentClicks' => $recentClicks,
        ]);
    }

    private function endpointRow(
        ManagedServer $server,
        ?string $domain,
        ?string $ipAddress,
        ?string $trackingHost,
        bool $trackingEnabled,
        bool $httpsEnabled,
        bool $ready,
    ): array {
        $retained = $server->deleted_at !== null;
        $retainedUntil = $retained ? $server->deleted_at->copy()->addDays(30) : null;

        return [
            'id' => (string) $server->getKey(),
            'name' => (string) $server->name,
            'ip_address' => (string) ($ipAddress ?: $server->ip_address),
            'domain' => $domain,
            'state' => (string) $server->state,
            'is_active' => (bool) $server->is_active,
            'tracking_host' => $trackingHost,
            'tracking_enabled' => $trackingEnabled,
            'https_enabled' => $httpsEnabled,
            'ready' => $ready,
            'retained' => $retained,
            'retained_until' => $retainedUntil,
            'last_probe_at' => $server->last_probe_at,
            'status' => $retained
                ? 'Retained after server removal'
                : (! $trackingEnabled ? 'Tracking off' : (! $httpsEnabled ? 'HTTPS required' : ($ready ? 'Ready' : 'DNS tracking host required'))),
        ];
    }

    private function authorizedWorkspace(User $user, string $workspaceId): Workspace
    {
        return app(WorkspaceAccess::class)->find($user, $workspaceId, self::ALLOWED_ROLES);
    }
}
