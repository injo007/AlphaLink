<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\BuildCampaignRecipientSnapshot;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRevision;
use App\Models\CampaignTestMailAttempt;
use App\Models\ContactListMembershipEvent;
use App\Models\Isp;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\OutboxMessage;
use App\Models\PmtaActionHistory;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCampaignNegative;
use App\Models\WorkspaceCampaignTestAddress;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceOfferAsset;
use App\Services\Campaigns\DmsCampaignRecipientSnapshotBuilder;
use App\Services\Campaigns\DmsCampaignSendCatalog;
use App\Services\Campaigns\DmsCampaignTagRenderer;
use App\Services\Campaigns\DmsPmtaAdminRestart;
use App\Services\Campaigns\PmtaCampaignTestTransport;
use App\Services\Campaigns\PmtaMonitorProxy;
use App\Services\Campaigns\PmtaPanelUrl;
use App\Services\Campaigns\PmtaQueueAdmin;
use App\Services\Infrastructure\DmsBulkInstallVmtaPlanner;
use App\Services\PmtaAuditLogger;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Throwable;

class WorkspaceConsoleController extends Controller
{
    private const CAMPAIGN_ROLES = ['owner', 'admin', 'operator'];

    private const CAMPAIGN_IP_SELECTION_REQUIRED = 'Choose at least one IP in the Selected IPs list before saving. Test Campaign does not require a separate IP selection; it uses the current Header and Email Test addresses with the selected server inventory.';

    private const AUDIENCE_ROLES = ['owner', 'admin', 'operator', 'privacy_officer'];

    public function index(Request $request): View|RedirectResponse
    {
        $workspaces = $request->user()->workspaces()
            ->where('workspaces.status', 'active')
            ->orderBy('workspaces.name')
            ->get(['workspaces.id', 'workspaces.name', 'workspaces.slug']);

        if ($workspaces->count() === 1) {
            return redirect()->route('console.dashboard.main');
        }

        return view('console.index', ['workspaces' => $workspaces]);
    }

    public function show(Request $request, string $workspace): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $request->session()->put('active_workspace_id', $workspace->getKey());

        return redirect()->route('console.dashboard.main');
    }

    public function campaigns(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);

        $filters = $request->validate([
            'from' => ['sometimes', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'disabled' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'in:20,50,100'],
        ]);
        $from = Carbon::parse($filters['from'] ?? now()->subDays(30)->toDateString())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now()->toDateString())->endOfDay();
        $search = trim((string) ($filters['q'] ?? ''));
        $showDisabled = (bool) ($filters['disabled'] ?? false);
        $perPage = (int) ($filters['per_page'] ?? 50);

        $campaignQuery = $workspace->campaigns()
            ->with('latestRevision')
            ->where('created_by', $request->user()->getKey())
            ->where('is_active', ! $showDisabled)
            ->whereBetween('created_at', [$from, $to])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('kind', 'like', '%'.$search.'%')
                        ->orWhere('status', 'like', '%'.$search.'%');

                    if (preg_match('/^[0-9a-f-]{8,36}$/i', $search) === 1) {
                        $query->orWhere('id', $search);
                    }
                });
            })
            ->orderByDesc('created_at');

        $campaigns = $campaignQuery->paginate($perPage)->withQueryString();

        $sendSetups = $campaigns->getCollection()->mapWithKeys(fn (Campaign $campaign): array => [
            $campaign->getKey() => $campaign->latestRevision?->payload['send_setup'] ?? [],
        ]);
        $ispIds = $sendSetups->pluck('isp_id')->filter()->unique()->values();
        $offerIds = $sendSetups->pluck('offer_id')->filter()->unique()->values();
        $serverIds = $sendSetups->flatMap(fn (array $setup): array => $setup['server_ids'] ?? [])->filter()->unique()->values();
        $ispNames = Isp::query()->where('workspace_id', $workspace->getKey())->whereIn('id', $ispIds)->pluck('name', 'id');
        $offerNames = WorkspaceOffer::withTrashed()->where('workspace_id', $workspace->getKey())->whereIn('id', $offerIds)->get(['id', 'offer_id', 'name'])->keyBy('id');
        $servers = ManagedServer::withTrashed()->where('workspace_id', $workspace->getKey())->whereIn('id', $serverIds)->get(['id', 'name', 'ip_address', 'install_parameters'])->keyBy('id');
        $serverNames = $servers->mapWithKeys(fn (ManagedServer $server): array => [$server->getKey() => $server->name]);
        $configuredPmtaAdminPort = $workspace->infrastructureSettings?->pmta_admin_port;
        $pmtaAdminPort = is_numeric($configuredPmtaAdminPort) && (int) $configuredPmtaAdminPort >= 1 && (int) $configuredPmtaAdminPort <= 65535
            ? (int) $configuredPmtaAdminPort
            : (int) config('infrastructure.pmta_admin_port', 1213);

        $campaigns->getCollection()->transform(function (Campaign $campaign) use ($sendSetups, $ispNames, $offerNames, $serverNames, $servers, $pmtaAdminPort): Campaign {
            $setup = $sendSetups->get($campaign->getKey(), []);
            $offer = $offerNames->get($setup['offer_id'] ?? null);
            $parameters = $setup['parameters'] ?? [];
            $pmtaPanels = collect($setup['server_ids'] ?? [])->map(function (string $id) use ($servers, $pmtaAdminPort): ?array {
                $server = $servers->get($id);
                if (! $server || ! filter_var($server->ip_address, FILTER_VALIDATE_IP)) {
                    return null;
                }

                return ['name' => $server->name, 'url' => PmtaPanelUrl::forServer($server, $pmtaAdminPort)];
            })->filter()->values()->all();
            $campaign->setAttribute('send_register', [
                'isp' => $ispNames->get($setup['isp_id'] ?? null),
                'offer' => $offer ? trim('('.$offer->offer_id.') '.$offer->name) : null,
                'servers' => collect($setup['server_ids'] ?? [])->map(fn (string $id): ?string => $serverNames->get($id))->filter()->values()->all(),
                'pmta_panels' => $pmtaPanels,
                'total_send' => $parameters['total_send'] ?? null,
                'fraction_ip' => $parameters['fraction_ip'] ?? null,
                'sleep_email' => $parameters['sleep_email'] ?? null,
                'sleep_ip' => $parameters['sleep_ip'] ?? null,
                'seed_every' => $parameters['seed_every'] ?? null,
                'send_type' => $setup['send_type'] ?? null,
                'configured' => $setup !== [],
            ]);

            return $campaign;
        });

        return view('console.campaigns.index', compact('workspace', 'campaigns', 'from', 'to', 'search', 'showDisabled', 'perPage'));
    }

    public function createCampaign(Request $request, string $workspace, DmsCampaignSendCatalog $catalog): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        $preparingCampaign = null;
        $preparingCampaignId = $request->query('preparing_campaign');
        if (is_string($preparingCampaignId) && $preparingCampaignId !== '') {
            $preparingCampaign = $workspace->campaigns()
                ->with('latestRevision')
                ->where('created_by', $request->user()->getKey())
                ->where('kind', 'marketing')
                ->whereKey($preparingCampaignId)
                ->first();
        }
        $sendOptions = $catalog->options($workspace, $request->user(), (string) $workspace->pivot->role, [
            'sponsor_id' => $request->old('send_setup.sponsor_id') ? (int) $request->old('send_setup.sponsor_id') : null,
            'offer_country' => $request->old('send_setup.offer_country'),
            'isp_id' => $request->old('send_setup.isp_id') ? (int) $request->old('send_setup.isp_id') : null,
            'country' => $request->old('send_setup.country'),
            'server_ids' => array_values(array_map('strval', (array) $request->old('send_setup.server_ids', []))),
            'route' => $request->old('send_setup.route_option', 'ip'),
            'ip_type' => $request->old('send_setup.ip_type', 'ipv4'),
        ]);

        return view('console.campaigns.create', [
            'workspace' => $workspace,
            'preparingCampaign' => $preparingCampaign,
            'idempotencyKey' => (string) Str::uuid(),
            'sendOptions' => $sendOptions,
            'sendOptionsUrl' => route('console.campaigns.send-options', $workspace->getKey()),
            'countries' => config('dms_install_countries', []),
            'savedEmailAddresses' => WorkspaceCampaignTestAddress::query()->where('workspace_id', $workspace->getKey())->where('user_id', $request->user()->getKey())->orderBy('id')->get(),
        ]);
    }

    public function editCampaign(Request $request, string $workspace, string $campaign, DmsCampaignSendCatalog $catalog): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        $editingCampaign = $workspace->campaigns()
            ->with('latestRevision')
            ->where('created_by', $request->user()->getKey())
            ->whereKey($campaign)
            ->firstOrFail();
        abort_unless($editingCampaign->kind === 'marketing' && $editingCampaign->status === 'draft' && $editingCampaign->is_active, 409, 'Only active marketing drafts can be edited.');
        $savedSetup = $editingCampaign->latestRevision?->payload['send_setup'] ?? null;
        $sendOptions = $catalog->options($workspace, $request->user(), (string) $workspace->pivot->role, [
            'sponsor_id' => $savedSetup['sponsor_id'] ?? null,
            'offer_country' => $savedSetup['offer_country'] ?? 'ALL',
            'isp_id' => $savedSetup['isp_id'] ?? null,
            'country' => $savedSetup['country'] ?? 'ALL',
            'server_ids' => array_values(array_map('strval', (array) $request->old('send_setup.server_ids', $savedSetup['server_ids'] ?? []))),
            'route' => (string) $request->old('send_setup.route_option', $savedSetup['route_option'] ?? 'ip'),
            'ip_type' => (string) $request->old('send_setup.ip_type', $savedSetup['ip_type'] ?? 'ipv4'),
        ]);
        $savedOfferId = filter_var($savedSetup['offer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return view('console.campaigns.create', [
            'workspace' => $workspace,
            'editingCampaign' => $editingCampaign,
            'idempotencyKey' => (string) Str::uuid(),
            'sendOptions' => $sendOptions,
            'savedOfferAssets' => $catalog->savedOfferAssets($workspace, $savedOfferId === false ? null : (int) $savedOfferId),
            'sendOptionsUrl' => route('console.campaigns.send-options', $workspace->getKey()),
            'countries' => config('dms_install_countries', []),
            'savedEmailAddresses' => WorkspaceCampaignTestAddress::query()->where('workspace_id', $workspace->getKey())->where('user_id', $request->user()->getKey())->orderBy('id')->get(),
        ]);
    }

    public function pmtaAdmin(Request $request, string $workspace, DmsCampaignSendCatalog $catalog): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $options = $catalog->options($workspace, $request->user(), 'admin', [
            'route' => 'ip',
            'ip_type' => 'all',
        ]);
        $selectedServerIds = array_values(array_unique(array_map('strval', (array) $request->query('server_ids', []))));
        $availableServerIds = collect($options['servers'])->pluck('id')->map('strval')->all();

        abort_if(array_diff($selectedServerIds, $availableServerIds) !== [], 404);

        $servers = collect($options['servers']);
        $pmtaFrameSources = $servers
            ->pluck('pmta_panel_url')
            ->filter(fn (mixed $url): bool => is_string($url) && str_starts_with($url, 'https://'))
            ->map(function (string $url): ?string {
                $parts = parse_url($url);
                if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'https') {
                    return null;
                }

                return 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        return view('console.campaigns.pmta-admin', [
            'workspace' => $workspace,
            'servers' => $servers,
            'selectedServerIds' => $selectedServerIds,
            'pmtaFrameSources' => $pmtaFrameSources,
            'pmtaAdminPort' => $options['settings']['pmta_admin_port'],
            'actionUrl' => route('console.pmta-admin.action', $workspace->getKey()),
            'restartUrl' => route('console.pmta-admin.restart', $workspace->getKey()),
        ]);
    }

    public function myServers(Request $request, string $workspace): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $servers = $workspace->managedServers()
            ->where('is_active', true)
            ->where('is_installed', true)
            ->with('history:id,managed_server_id,is_ssh_down,is_apache_down,is_pmta_down,created_at')
            ->withCount(['ips as ips_count' => fn ($query) => $query->where('is_active', true)])
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'ip_address', 'country', 'created_at']);

        if ($request->wantsJson()) {
            return response()->json([
                'data' => $servers->map(fn (ManagedServer $server): array => [
                    'id' => $server->getKey(),
                    'name' => $server->name,
                    'ip' => $server->ip_address,
                    'country' => $server->country,
                    'ips_count' => $server->ips_count,
                    'history' => $server->history ? [
                        'is_ssh_down' => $server->history->is_ssh_down,
                        'is_apache_down' => $server->history->is_apache_down,
                        'is_pmta_down' => $server->history->is_pmta_down,
                        'created_at' => $server->history->created_at?->toISOString(),
                    ] : null,
                ])->values(),
            ]);
        }

        return view('console.servers.my-servers', [
            'workspace' => $workspace,
            'serversUrl' => route('console.my-servers.index', $workspace->getKey()),
            'myIpsUrl' => route('console.infrastructure.my-ips', $workspace->getKey()),
            'pmtaAdminUrl' => route('console.pmta-admin.index', $workspace->getKey()),
        ]);
    }

    public function pmtaAdminMonitor(
        Request $request,
        string $workspace,
        string $server,
        PmtaMonitorProxy $monitor,
        DmsCampaignSendCatalog $catalog,
        string $monitorPath = '',
    ): Response {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $options = $catalog->options($workspace, $request->user(), 'admin', [
            'route' => 'ip',
            'ip_type' => 'all',
        ]);
        $availableServerIds = collect($options['servers'])->pluck('id')->map('strval')->all();
        abort_unless(in_array($server, $availableServerIds, true), 404);

        $managedServer = $workspace->managedServers()
            ->whereKey($server)
            ->where('is_active', true)
            ->where('is_installed', true)
            ->firstOrFail();

        return $monitor->get($managedServer, (int) $options['settings']['pmta_admin_port'], $monitorPath, (string) $workspace->getKey());
    }

    public function pmtaAdminHistory(Request $request, string $workspace): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $serverId = $request->query('server');

        if (! $request->wantsJson()) {
            $servers = $workspace->managedServers()
                ->where('is_active', true)
                ->where('is_installed', true)
                ->orderBy('id')
                ->get(['id', 'name', 'ip_address']);

            return view('console.campaigns.pmta-action-history', [
                'workspace' => $workspace,
                'servers' => $servers,
                'historyUrl' => route('console.pmta-admin.history', $workspace->getKey()),
            ]);
        }

        $history = PmtaActionHistory::query()
            ->with(['server:id,name', 'actor:id,username'])
            ->where('workspace_id', $workspace->getKey())
            ->whereHas('server', fn ($query) => $query
                ->where('workspace_id', $workspace->getKey())
                ->where('is_active', true)
                ->where('is_installed', true))
            ->when(is_string($serverId) && $serverId !== '' && $serverId !== '0', fn ($query) => $query->where('managed_server_id', $serverId))
            ->orderByDesc('id');

        $recordsTotal = (clone $history)->count();
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $history->where(function ($query) use ($search): void {
                $query->where('action', 'like', '%'.$search.'%')
                    ->orWhere('result', 'like', '%'.$search.'%')
                    ->orWhere('managed_server_id', 'like', '%'.$search.'%')
                    ->orWhereHas('server', fn ($serverQuery) => $serverQuery->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('actor', fn ($actorQuery) => $actorQuery->where('username', 'like', '%'.$search.'%'));
            });
        }
        $recordsFiltered = (clone $history)->count();

        $orderColumn = (int) $request->input('order.0.column', -1);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $orderableColumns = [0 => 'id', 2 => 'action', 3 => 'result', 5 => 'created_at'];
        if (isset($orderableColumns[$orderColumn])) {
            $history->reorder($orderableColumns[$orderColumn], $orderDirection)->orderByDesc('id');
        }

        $draw = max(0, (int) $request->input('draw', 0));
        $start = max(0, (int) $request->input('start', 0));
        $requestedLength = (int) $request->input('length', 50);
        $length = $requestedLength === -1 ? 500 : min(500, max(1, $requestedLength));
        $events = $history->skip($start)->take($length)->get();
        $data = $events->map(fn (PmtaActionHistory $event): array => [
            'id' => $event->getKey(),
            'server' => $event->server ? [
                'id' => $event->server->getKey(),
                'name' => $event->server->name,
            ] : null,
            'action' => $event->action,
            'result' => $event->result,
            'user' => ['username' => $event->actor?->username ?? 'N/A'],
            'created_at' => $event->created_at?->toDateTimeString() ?? '',
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function pmtaAdminAction(Request $request, string $workspace, DmsCampaignSendCatalog $catalog, PmtaQueueAdmin $pmta, PmtaAuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate([
            'server_ids' => ['required', 'array', 'min:1'],
            'server_ids.*' => ['required', 'uuid'],
            'action' => ['required', Rule::in(['pause', 'resume', 'delete', 'reset', 'schedule'])],
            'queue' => ['required', Rule::in(['domain', 'campaign'])],
            'tags' => ['required', 'array', 'min:1'],
            'tags.*' => ['required', 'string', 'max:255'],
        ]);
        $serverIds = array_values(array_map('strval', $data['server_ids']));
        $options = $catalog->options($workspace, $request->user(), 'admin', [
            'server_ids' => $serverIds,
            'route' => 'ip',
            'ip_type' => 'all',
        ]);
        $availableServers = collect($options['servers'])->keyBy(fn (array $server): string => (string) $server['id']);

        if (array_diff($serverIds, $availableServers->keys()->all()) !== []) {
            throw ValidationException::withMessages(['server_ids' => 'Select active installed servers available to your account.']);
        }

        $servers = $workspace->managedServers()->whereIn('id', $serverIds)->where('is_active', true)->where('is_installed', true)->get()->keyBy(fn (ManagedServer $server): string => (string) $server->getKey());
        $port = (int) $options['settings']['pmta_admin_port'];
        $tags = array_values(array_filter(array_map(fn (string $tag): string => trim($tag), $data['tags'] ?? []), fn (string $tag): bool => $tag !== ''));
        if (! in_array('*/*', $tags, true)) {
            throw ValidationException::withMessages(['tags' => 'Select the */* queue tag before executing a PMTA command.']);
        }

        $results = [];

        foreach ($serverIds as $serverId) {
            $server = $servers->get($serverId);
            if (! $server) {
                $results[] = ['id' => $serverId, 'name' => 'SERVER', 'result' => 'Server is no longer active and installed.', 'success' => false];

                continue;
            }

            try {
                $commandResults = $pmta->run($server, $port, $data['action'], $data['queue'], $tags);
            } catch (Throwable $exception) {
                $results[] = ['id' => $serverId, 'name' => $server->name, 'result' => e($exception->getMessage()), 'success' => false];

                continue;
            }

            $audit->logSuccessfulAction(
                $workspace,
                $request->user(),
                $server,
                $data['action'],
                implode("\n", $commandResults),
                $request->attributes->get('request_id'),
            );

            $results[] = [
                'id' => $serverId,
                'name' => $server->name,
                'result' => implode('<br>', array_map('e', $commandResults)),
                'success' => true,
            ];
        }

        return response()->json(['data' => $results]);
    }

    public function pmtaAdminRestart(Request $request, string $workspace, DmsCampaignSendCatalog $catalog, DmsPmtaAdminRestart $restart, PmtaAuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate([
            'server_ids' => ['required', 'array', 'min:1'],
            'server_ids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $serverIds = array_values(array_map('strval', $data['server_ids']));
        $options = $catalog->options($workspace, $request->user(), 'admin', [
            'server_ids' => $serverIds,
            'route' => 'ip',
            'ip_type' => 'all',
        ]);
        $availableServerIds = collect($options['servers'])->pluck('id')->map('strval')->all();
        if (array_diff($serverIds, $availableServerIds) !== []) {
            throw ValidationException::withMessages(['server_ids' => 'Select active installed servers available to your account.']);
        }

        $servers = $workspace->managedServers()
            ->whereIn('id', $serverIds)
            ->where('is_active', true)
            ->where('is_installed', true)
            ->get()
            ->keyBy(fn (ManagedServer $server): string => (string) $server->getKey());
        $results = [];
        foreach ($serverIds as $serverId) {
            $server = $servers->get($serverId);
            if (! $server) {
                $results[] = ['id' => $serverId, 'name' => 'SERVER', 'result' => 'Server is no longer active and installed.'];

                continue;
            }

            try {
                $result = $restart->run($server);
                $audit->logSuccessfulAction(
                    $workspace,
                    $request->user(),
                    $server,
                    'restart',
                    $result,
                    $request->attributes->get('request_id'),
                );

                $results[] = [
                    'id' => $serverId,
                    'name' => $server->name,
                    'result' => e($result),
                    'success' => true,
                ];
            } catch (Throwable $exception) {
                $results[] = ['id' => $serverId, 'name' => $server->name, 'result' => e($exception->getMessage()), 'success' => false];
            }
        }

        return response()->json(['data' => $results]);
    }

    public function storeCampaignTestAddress(Request $request, string $workspace): JsonResponse|RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $address = WorkspaceCampaignTestAddress::query()->create([
            'workspace_id' => $workspace->getKey(), 'user_id' => $request->user()->getKey(),
            'email' => mb_strtolower(trim($data['email'])),
        ]);

        return $request->expectsJson()
            ? response()->json(['data' => ['id' => $address->getKey(), 'email' => $address->email]], 201)
            : back(303)->with('status', 'Test address saved.');
    }

    public function deleteCampaignTestAddress(Request $request, string $workspace, int $address): JsonResponse|RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        WorkspaceCampaignTestAddress::query()->where('workspace_id', $workspace->getKey())
            ->where('user_id', $request->user()->getKey())->whereKey($address)->firstOrFail()->delete();

        return $request->expectsJson()
            ? response()->json(['message' => 'Test address deleted.'])
            : back(303)->with('status', 'Test address deleted.');
    }

    public function createCampaignTestDraft(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        $senderDomains = $workspace->sendingDomains()
            ->orderBy('name')
            ->get(['id', 'name', 'state', 'verification_token', 'verified_at']);

        return view('console.campaigns.test-draft', [
            'workspace' => $workspace,
            'idempotencyKey' => (string) Str::uuid(),
            'dmsHeader' => "MIME-version: 1.0\nContent-type: text/html\nTo: [to]\nfrom: [from]\nSubject: [subject]\nDate: [date]",
            'senderDomains' => $senderDomains,
        ]);
    }

    public function campaignSendOptions(Request $request, string $workspace, DmsCampaignSendCatalog $catalog): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        $filters = $request->validate([
            'sponsor_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'offer_country' => ['sometimes', 'nullable', 'string', 'max:8'],
            'isp_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'country' => ['sometimes', 'nullable', 'string', 'max:8'],
            'server_ids' => ['sometimes', 'array'],
            'server_ids.*' => ['required', 'uuid', 'distinct'],
            'route' => ['sometimes', 'required', 'in:ip,vmta,smtp'],
            'ip_type' => ['sometimes', 'required', 'in:all,ipv4,ipv6'],
        ]);

        return response()->json([
            'data' => $catalog->options($workspace, $request->user(), (string) $workspace->pivot->role, $filters),
        ]);
    }

    public function campaign(Request $request, string $workspace, string $campaign, DmsCampaignSendCatalog $catalog): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);

        $campaign = $workspace->campaigns()
            ->with('latestRevision')
            ->where('created_by', $request->user()->getKey())
            ->whereKey($campaign)
            ->firstOrFail();
        $revisions = $campaign->revisions()->paginate(20);
        $testMailAttempts = CampaignTestMailAttempt::query()
            ->with('revision')
            ->where('workspace_id', $workspace->getKey())
            ->where('campaign_id', $campaign->getKey())
            ->latest('created_at')
            ->limit(10)
            ->get();
        $manualTestAttempts = DB::table('campaign_manual_test_attempts as attempts')
            ->leftJoin('managed_servers as servers', 'servers.id', '=', 'attempts.managed_server_id')
            ->where('attempts.workspace_id', $workspace->getKey())
            ->where('attempts.campaign_id', $campaign->getKey())
            ->orderByDesc('attempts.created_at')
            ->orderByDesc('attempts.id')
            ->paginate(25, [
                'attempts.id',
                'attempts.status',
                'attempts.exception_type',
                'attempts.test_snapshot',
                'attempts.created_at',
                'servers.name as server_name',
            ], 'test_logs_page');
        $manualTestAttempts->getCollection()->transform(function (object $attempt): object {
            if (! filled($attempt->test_snapshot)) {
                $attempt->test_snapshot = null;

                return $attempt;
            }

            try {
                $attempt->test_snapshot = json_decode(
                    Crypt::decryptString($attempt->test_snapshot),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
            } catch (DecryptException|\JsonException) {
                $attempt->test_snapshot = null;
            }

            return $attempt;
        });
        $sendSetup = $campaign->latestRevision?->payload['send_setup'] ?? null;
        $recipientSnapshot = $campaign->latestRevision
            ? CampaignRecipientSnapshot::query()->where('revision_id', $campaign->latestRevision->getKey())->first()
            : null;
        $sendOptions = is_array($sendSetup)
            ? $catalog->options($workspace, $request->user(), (string) $workspace->pivot->role, [
                'sponsor_id' => $sendSetup['sponsor_id'] ?? null,
                'offer_country' => $sendSetup['offer_country'] ?? null,
                'isp_id' => $sendSetup['isp_id'] ?? null,
                'country' => $sendSetup['country'] ?? null,
                'server_ids' => array_map('strval', $sendSetup['server_ids'] ?? []),
                'route' => $sendSetup['route_option'] ?? 'ip',
                'ip_type' => $sendSetup['ip_type'] ?? 'all',
            ])
            : [];
        $smtpReady = $this->campaignSmtpConfigured(allowArrayTransport: true);
        $smtpDestination = $smtpReady ? $this->campaignSmtpDestination() : null;
        $latestSmtpCheck = $smtpReady
            ? AuditEvent::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereIn('action', ['campaign.smtp_connection_succeeded', 'campaign.smtp_connection_failed'])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->first()
            : null;
        $senderDomainIsReserved = $campaign->latestRevision
            ? $this->campaignSenderUsesReservedDomain($campaign->latestRevision->payload['from_email'] ?? null)
            : false;
        $senderDomainIsVerified = $campaign->latestRevision
            ? $this->campaignSenderDomainIsVerified($workspace, $campaign->latestRevision->payload['from_email'] ?? null)
            : false;

        $pmtaPort = $this->pmtaListenerPort($workspace);

        return view('console.campaigns.show', compact('workspace', 'campaign', 'revisions', 'testMailAttempts', 'manualTestAttempts', 'smtpReady', 'smtpDestination', 'latestSmtpCheck', 'senderDomainIsReserved', 'senderDomainIsVerified', 'sendSetup', 'sendOptions', 'recipientSnapshot', 'pmtaPort'));
    }

    public function cloneCampaign(Request $request, string $workspace, string $campaign, DmsCampaignSendCatalog $catalog): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        $source = $this->campaignCloneSource($request, $workspace, $campaign);
        $sourceRevision = $source->latestRevision;
        $sendSetup = $sourceRevision->payload['send_setup'];
        $selectedIsp = (int) $request->session()->getOldInput('send_setup.isp_id', $sendSetup['isp_id']);
        $selectedCountry = strtoupper((string) $request->session()->getOldInput('send_setup.country', $sendSetup['country']));
        $sendOptions = $catalog->options($workspace, $request->user(), (string) $workspace->pivot->role, [
            'sponsor_id' => $sendSetup['sponsor_id'],
            'offer_country' => $sendSetup['offer_country'],
            'isp_id' => $selectedIsp,
            'country' => $selectedCountry,
            'server_ids' => array_map('strval', $sendSetup['server_ids']),
            'route' => $sendSetup['route_option'],
            'ip_type' => $sendSetup['ip_type'],
        ]);
        $requestedSegments = (array) $request->session()->getOldInput('send_setup.audience_segments', $sendSetup['audience_segments']);
        $availableSegmentIds = collect($sendOptions['segments'])->pluck('id')->map(fn ($id): string => (string) $id)->all();

        return view('console.campaigns.clone', [
            'workspace' => $workspace,
            'source' => $source,
            'sourceRevision' => $sourceRevision,
            'sendSetup' => $sendSetup,
            'sendOptions' => $sendOptions,
            'sendOptionsUrl' => route('console.campaigns.send-options', $workspace->getKey()),
            'idempotencyKey' => (string) Str::uuid(),
            'selectedSegments' => array_values(array_intersect(array_map('strval', $requestedSegments), $availableSegmentIds)),
        ]);
    }

    public function storeCampaignClone(
        Request $request,
        string $workspace,
        string $campaign,
        DmsCampaignSendCatalog $catalog,
        DmsCampaignRecipientSnapshotBuilder $snapshotBuilder,
    ): RedirectResponse {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        $source = $this->campaignCloneSource($request, $workspace, $campaign);
        $sourceRevision = $source->latestRevision;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'idempotency_key' => ['required', 'uuid'],
            'source_revision_id' => ['required', 'uuid'],
            'send_setup.isp_id' => ['required', 'integer', 'min:1'],
            'send_setup.country' => ['required', 'string', 'max:8'],
            'send_setup.audience_segments' => ['required', 'array', 'min:1'],
            'send_setup.audience_segments.*' => ['required', 'string', 'max:80', 'distinct', 'regex:/^[0-9a-f-]{36}_(fresh|delivery|open|click|lead|unsubscribe|mix)$/i'],
            'send_setup.order_by' => ['required', 'in:default,asc,desc,random'],
            'send_setup.start_from' => ['required', 'integer', 'min:0'],
        ]);
        abort_unless((string) $data['source_revision_id'] === (string) $sourceRevision->getKey(), 409, 'The source campaign changed. Reload the clone form and review the latest revision.');

        $sourceSetup = $sourceRevision->payload['send_setup'];
        $selection = [
            ...$sourceSetup,
            'isp_id' => (int) $data['send_setup']['isp_id'],
            'country' => strtoupper($data['send_setup']['country']),
            'audience_segments' => array_values(array_map('strval', $data['send_setup']['audience_segments'])),
            'order_by' => $data['send_setup']['order_by'],
            'start_from' => (int) $data['send_setup']['start_from'],
        ];
        $validatedSelection = $catalog->validateSelection(
            $workspace,
            $request->user(),
            (string) $workspace->pivot->role,
            [...$selection, ...($selection['parameters'] ?? [])],
        );
        $sendSetup = [
            ...$sourceSetup,
            ...$validatedSelection,
            'placeholder_combination' => (bool) ($sourceSetup['placeholder_combination'] ?? false),
            'placeholder_all_combination' => (bool) ($sourceSetup['placeholder_all_combination'] ?? false),
            'placeholders' => $sourceSetup['placeholders'] ?? [],
        ];
        $content = $sourceRevision->payload;
        $content['send_setup'] = $sendSetup;
        $contentHash = $this->campaignContentHash($content);
        $name = trim($data['name']);
        $idempotencyKey = $data['idempotency_key'];
        $requestFingerprint = hash('sha256', implode("\n", [
            (string) $source->getKey(),
            (string) $sourceRevision->getKey(),
            $name,
            $contentHash,
        ]));

        $prior = $workspace->campaigns()->where('idempotency_key', $idempotencyKey)->first();
        if ($prior) {
            $this->assertCampaignCloneReplay($prior, $request->user()->getKey(), $name, $requestFingerprint);

            return redirect()->route('console.campaigns.show', [$workspace->getKey(), $prior->getKey()])
                ->with('status', 'Clone already saved; no duplicate draft was created.');
        }

        try {
            $clone = DB::transaction(function () use ($request, $workspace, $source, $sourceRevision, $name, $idempotencyKey, $requestFingerprint, $content, $contentHash, $sendSetup, $snapshotBuilder): Campaign {
                $lockedSource = Campaign::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->where('created_by', $request->user()->getKey())
                    ->whereKey($source->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $latestSourceRevision = $lockedSource->latestRevision()->lockForUpdate()->first();
                abort_unless(
                    (string) ($latestSourceRevision?->getKey() ?? '') === (string) $sourceRevision->getKey(),
                    409,
                    'The source campaign changed while its clone was being prepared. Reload and try again.',
                );
                $this->assertCampaignCloneOffer($workspace, $sendSetup);

                $clone = $workspace->campaigns()->create([
                    'created_by' => $request->user()->getKey(),
                    'name' => $name,
                    'kind' => 'marketing',
                    'status' => 'draft',
                    'is_active' => true,
                    'idempotency_key' => $idempotencyKey,
                ]);
                $revision = $clone->revisions()->create([
                    'revision_number' => 1,
                    'payload' => $content,
                    'content_sha256' => $contentHash,
                    'idempotency_key' => 'clone:'.hash('sha256', $idempotencyKey),
                    'request_fingerprint' => $requestFingerprint,
                    'created_by' => $request->user()->getKey(),
                    'created_at' => now(),
                ]);
                $snapshot = $snapshotBuilder->build($workspace, $clone, (string) $revision->getKey(), $sendSetup);

                $this->recordCampaignEvent($request, $workspace, $clone, 'campaign.cloned', [
                    'source_campaign_id' => $lockedSource->getKey(),
                    'source_revision_id' => $sourceRevision->getKey(),
                    'revision_id' => $revision->getKey(),
                    'content_sha256' => $contentHash,
                    'recipient_snapshot_id' => $snapshot->getKey(),
                    'recipient_count' => $snapshot->recipient_count,
                    'recipient_manifest_sha256' => $snapshot->recipients_sha256,
                ]);

                return $clone;
            });
        } catch (QueryException $exception) {
            $prior = $workspace->campaigns()->where('idempotency_key', $idempotencyKey)->first();
            if (! $prior) {
                throw $exception;
            }
            $this->assertCampaignCloneReplay($prior, $request->user()->getKey(), $name, $requestFingerprint);

            return redirect()->route('console.campaigns.show', [$workspace->getKey(), $prior->getKey()])
                ->with('status', 'Clone already saved; no duplicate draft was created.');
        }

        return redirect()->route('console.campaigns.show', [$workspace->getKey(), $clone->getKey()])
            ->with('status', 'Campaign cloned as a new draft with a fresh suppression snapshot. Native campaign sending remains unavailable.');
    }

    public function createCampaignDraft(Request $request, string $workspace, DmsCampaignSendCatalog $catalog): RedirectResponse|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        $data = $this->validatedCampaignContent($request, true);
        $sendSetup = $data['kind'] === 'marketing'
            ? $this->validatedCampaignSendSetup($request, $workspace, $catalog)
            : null;
        $idempotencyKey = $data['idempotency_key'];
        $content = $this->campaignContent($data, $sendSetup, $data['kind'] === 'transactional');
        $contentHash = $this->campaignContentHash($content);

        $prior = $workspace->campaigns()->where('idempotency_key', $idempotencyKey)->first();
        if ($prior) {
            $priorRevision = $prior->revisions()->where('revision_number', 1)->first();
            abort_unless(
                $prior->created_by === $request->user()->getKey()
                    && $prior->name === trim($data['name'])
                    && $prior->kind === $data['kind']
                    && $priorRevision
                    && hash_equals($priorRevision->content_sha256, $contentHash),
                409,
                'This draft submission key was already used for different content.'
            );

            if ($request->expectsJson() && $prior->kind === 'marketing') {
                return $this->campaignSnapshotResponse($workspace, $prior, 'Draft already saved; no duplicate was created.');
            }

            return redirect()->route('console.campaigns.create', [
                'workspace' => $workspace->getKey(),
                ...($prior->kind === 'marketing' ? ['preparing_campaign' => $prior->getKey()] : []),
            ])->with('status', $prior->status === 'preparing_snapshot'
                ? 'Campaign is saved. Suppression and recipient preparation are still running.'
                : 'Draft already saved; no duplicate was created.');
        }

        [$campaign, $revision] = DB::transaction(function () use ($request, $workspace, $data, $content, $contentHash, $idempotencyKey): array {
            $isMarketing = $data['kind'] === 'marketing';
            $campaign = $workspace->campaigns()->create([
                'created_by' => $request->user()->getKey(),
                'name' => trim($data['name']),
                'kind' => $data['kind'],
                'status' => $isMarketing ? 'preparing_snapshot' : 'draft',
                'idempotency_key' => $idempotencyKey,
                ...($isMarketing ? [
                    'snapshot_build_stage' => 'queued',
                    'snapshot_build_percent' => 0,
                    'snapshot_build_processed' => 0,
                    'snapshot_build_updated_at' => now(),
                ] : []),
            ]);
            $revision = $campaign->revisions()->create([
                'revision_number' => 1,
                'payload' => $content,
                'content_sha256' => $contentHash,
                'idempotency_key' => 'create:'.hash('sha256', $idempotencyKey),
                'request_fingerprint' => $contentHash,
                'created_by' => $request->user()->getKey(),
                'created_at' => now(),
            ]);

            $this->recordCampaignEvent($request, $workspace, $campaign, 'campaign.draft_created', [
                'revision_id' => $revision->getKey(),
                'content_sha256' => $contentHash,
                ...($isMarketing ? [
                    'recipient_snapshot_status' => 'queued',
                ] : []),
            ]);

            return [$campaign, $revision];
        });

        if ($campaign->kind === 'marketing') {
            $this->enqueueCampaignSnapshotBuild($workspace, $campaign, $revision, $request->user()->getKey());
            $campaign->refresh();

            if ($request->expectsJson()) {
                return $this->campaignSnapshotResponse($workspace, $campaign, 'Campaign saved. Suppression and recipient preparation are running.');
            }
        }

        return redirect()->route('console.campaigns.create', [
            'workspace' => $workspace->getKey(),
            ...($campaign->kind === 'marketing' ? ['preparing_campaign' => $campaign->getKey()] : []),
        ])
            ->with('status', $campaign->kind === 'marketing'
                ? ($campaign->status === 'draft'
                    ? 'Campaign saved. Suppression and recipient preparation completed.'
                    : ($campaign->status === 'snapshot_failed'
                        ? 'Campaign saved, but suppression and recipient preparation failed. Review the status and retry.'
                        : 'Campaign saved. Suppression and recipient preparation are running.'))
                : 'Draft saved as revision 1.');
    }

    private function campaignSnapshotResponse(Workspace $workspace, Campaign $campaign, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'campaign_id' => (string) $campaign->getKey(),
            'status' => $campaign->status,
            'snapshot_progress_url' => route('console.campaigns.snapshot-progress', [$workspace->getKey(), $campaign->getKey()]),
            'campaign_url' => route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()]),
            'edit_url' => route('console.campaigns.edit', [$workspace->getKey(), $campaign->getKey()]),
        ], $campaign->status === 'preparing_snapshot' ? 202 : 200)->header('Cache-Control', 'no-store, private');
    }

    public function campaignSnapshotProgress(Request $request, string $workspace, string $campaign): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        $campaign = $workspace->campaigns()
            ->where('created_by', $request->user()->getKey())
            ->whereKey($campaign)
            ->firstOrFail();
        $revision = $campaign->latestRevision;
        $snapshot = $revision
            ? CampaignRecipientSnapshot::query()->where('revision_id', $revision->getKey())->first()
            : null;

        return response()->json([
            'status' => $snapshot ? 'draft' : $campaign->status,
            'ready' => $snapshot !== null,
            'stage' => $campaign->snapshot_build_stage,
            'percent' => (int) $campaign->snapshot_build_percent,
            'processed' => (int) $campaign->snapshot_build_processed,
            'total' => $campaign->snapshot_build_total === null ? null : (int) $campaign->snapshot_build_total,
            'updated_at' => $campaign->snapshot_build_updated_at?->toIso8601String(),
            'error' => $campaign->snapshot_build_error,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function retryCampaignSnapshot(Request $request, string $workspace, string $campaign): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        $campaign = $workspace->campaigns()
            ->where('created_by', $request->user()->getKey())
            ->whereKey($campaign)
            ->firstOrFail();

        $revision = $campaign->latestRevision;
        abort_unless($campaign->kind === 'marketing' && $revision && is_array($revision->payload['send_setup'] ?? null), 409, 'This campaign has no marketing audience to prepare.');

        DB::transaction(function () use ($workspace, $campaign): void {
            $locked = Campaign::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereKey($campaign->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($locked->status === 'snapshot_failed', 409, 'Recipient preparation can only be retried after a failed attempt.');
            $hasSnapshot = $locked->latestRevision
                ? CampaignRecipientSnapshot::query()->where('revision_id', $locked->latestRevision->getKey())->exists()
                : false;
            abort_unless(! $hasSnapshot, 409, 'This campaign already has a recipient snapshot.');

            $locked->forceFill([
                'status' => 'preparing_snapshot',
                'snapshot_build_stage' => 'queued',
                'snapshot_build_percent' => 0,
                'snapshot_build_processed' => 0,
                'snapshot_build_total' => null,
                'snapshot_build_error' => null,
                'snapshot_build_updated_at' => now(),
            ])->save();
        });

        $this->enqueueCampaignSnapshotBuild($workspace, $campaign, $revision, $request->user()->getKey());

        return redirect()->route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()])
            ->with('status', 'Recipient preparation retry started.');
    }

    private function enqueueCampaignSnapshotBuild(Workspace $workspace, Campaign $campaign, CampaignRevision $revision, ?int $actorUserId): void
    {
        try {
            BuildCampaignRecipientSnapshot::dispatch(
                (string) $workspace->getKey(),
                (string) $campaign->getKey(),
                (string) $revision->getKey(),
                $actorUserId,
            );
        } catch (Throwable $exception) {
            report($exception);
            Campaign::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereKey($campaign->getKey())
                ->where('status', 'preparing_snapshot')
                ->update([
                    'status' => 'snapshot_failed',
                    'snapshot_build_stage' => 'failed',
                    'snapshot_build_error' => 'The background preparation queue is unavailable. Contact an administrator before retrying.',
                    'snapshot_build_updated_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    public function reviseCampaignDraft(
        Request $request,
        string $workspace,
        string $campaign,
        DmsCampaignSendCatalog $catalog,
        DmsCampaignRecipientSnapshotBuilder $snapshotBuilder,
    ): RedirectResponse {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        $campaign = $workspace->campaigns()
            ->where('created_by', $request->user()->getKey())
            ->whereKey($campaign)
            ->firstOrFail();
        abort_unless($campaign->status === 'draft' && $campaign->is_active, 409, 'Only active draft campaigns can be revised.');

        $latestPayload = $campaign->latestRevision?->payload ?? [];
        $sourceRevisionId = $campaign->latestRevision?->getKey();
        $sendSetup = $latestPayload['send_setup'] ?? null;
        $data = $this->validatedCampaignContent($request, false, is_array($sendSetup), $campaign->kind);
        $expectedRevisionId = $request->validate(['expected_revision_id' => ['required', 'uuid']])['expected_revision_id'];
        abort_unless((string) $sourceRevisionId === $expectedRevisionId, 409, 'The campaign changed. Reload the edit form before saving.');
        $idempotencyKey = $data['idempotency_key'];
        $revisionSetupInput = $request->input('send_setup', []);
        if (is_array($sendSetup) && is_array($revisionSetupInput)
            && (array_key_exists('route_option', $revisionSetupInput) || array_key_exists('server_ids', $revisionSetupInput))) {
            $routing = $request->validate([
                'send_setup.route_option' => ['required', 'in:ip,vmta,smtp'],
                'send_setup.ip_type' => ['required', 'in:all,ipv4,ipv6'],
                'send_setup.server_ids' => ['required', 'array', 'min:1'],
                'send_setup.server_ids.*' => ['required', 'uuid', 'distinct'],
                'send_setup.ip_ids' => ['required', 'array', 'min:1'],
                'send_setup.ip_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            ], [
                'send_setup.ip_ids.required' => self::CAMPAIGN_IP_SELECTION_REQUIRED,
                'send_setup.ip_ids.min' => self::CAMPAIGN_IP_SELECTION_REQUIRED,
            ])['send_setup'];
            $sendSetup = [...$sendSetup, ...$routing];
            $sendSetup = [...$sendSetup, ...$catalog->validateRevisionRouting(
                $workspace,
                $request->user(),
                (string) $workspace->pivot->role,
                $sendSetup['route_option'],
                $sendSetup['ip_type'],
                $sendSetup['server_ids'],
                $sendSetup['ip_ids'],
            )];
        }
        if (is_array($sendSetup) && array_key_exists('domain', $data)) {
            $sendSetup['domain'] = $catalog->validateRevisionDomain(
                $workspace,
                $request->user(),
                (string) $workspace->pivot->role,
                (string) ($sendSetup['route_option'] ?? 'ip'),
                (string) ($sendSetup['ip_type'] ?? 'all'),
                array_map('strval', $sendSetup['server_ids'] ?? []),
                array_map('strval', $sendSetup['ip_ids'] ?? []),
                $data['domain'] === null ? null : (string) $data['domain'],
            );
        }
        if (is_array($sendSetup) && is_array($revisionSetupInput)) {
            $editable = ['from_asset_ids', 'subject_asset_ids', 'send_type', 'total_send', 'fraction_ip', 'sleep_email', 'sleep_ip', 'seed_every', 'bounce_limit', 'duplicate_data'];
            if (array_intersect($editable, array_keys($revisionSetupInput)) !== []) {
                $fields = $request->validate([
                    'send_setup.from_asset_ids' => ['sometimes', 'array'],
                    'send_setup.from_asset_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
                    'send_setup.subject_asset_ids' => ['sometimes', 'array'],
                    'send_setup.subject_asset_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
                    'send_setup.send_type' => ['required', 'in:delay,delayp,delayps,hs,hsp,hsps,pickup,pickups'],
                    'send_setup.total_send' => ['required', 'integer', 'min:1'],
                    'send_setup.fraction_ip' => ['required', 'integer', 'min:1'],
                    'send_setup.sleep_email' => ['required', 'integer', 'min:0'],
                    'send_setup.sleep_ip' => ['required', 'integer', 'min:0'],
                    'send_setup.seed_every' => ['required', 'integer', 'min:0'],
                    'send_setup.bounce_limit' => ['required', 'integer', 'min:0'],
                    'send_setup.duplicate_data' => ['required', 'integer', 'min:1'],
                ])['send_setup'];
                $sendSetup['from_asset_ids'] = $fields['from_asset_ids'] ?? [];
                $sendSetup['subject_asset_ids'] = $fields['subject_asset_ids'] ?? [];
                $sendSetup['send_type'] = $fields['send_type'];
                $sendSetup['parameters'] = array_intersect_key($fields, array_flip(['total_send', 'fraction_ip', 'sleep_email', 'sleep_ip', 'seed_every', 'bounce_limit', 'duplicate_data']));
            }
        }
        if (is_array($sendSetup) && is_array($revisionSetupInput) && array_key_exists('negative_id', $revisionSetupInput)) {
            $negativeSelection = $request->validate(['send_setup.negative_id' => ['nullable', 'integer', 'min:1']]);
            $sendSetup['negative_id'] = $catalog->validateNegativeSelection(
                $workspace,
                $request->user(),
                $negativeSelection['send_setup']['negative_id'] ?? null,
            );
        }
        if (is_array($sendSetup) && is_array($revisionSetupInput)
            && (array_key_exists('from_asset_ids', $revisionSetupInput)
                || array_key_exists('subject_asset_ids', $revisionSetupInput)
                || array_key_exists('creative_asset_id', $revisionSetupInput)
                || array_key_exists('html_asset_id', $revisionSetupInput))) {
            $assetSelection = $request->validate([
                'send_setup.creative_asset_id' => ['nullable', 'integer', 'min:1'],
                'send_setup.html_asset_id' => ['nullable', 'integer', 'min:1'],
            ]);
            $sendSetup = [...$sendSetup, ...$catalog->validateRevisionOfferAssets(
                $workspace,
                (int) $sendSetup['offer_id'],
                $sendSetup['from_asset_ids'] ?? [],
                $sendSetup['subject_asset_ids'] ?? [],
                $assetSelection['send_setup']['creative_asset_id'] ?? null,
                $assetSelection['send_setup']['html_asset_id'] ?? null,
            )];
        }
        if (is_array($sendSetup) && is_array($revisionSetupInput)
            && (array_key_exists('placeholder_inputs', $revisionSetupInput)
                || array_key_exists('placeholder_combination', $revisionSetupInput)
                || array_key_exists('placeholder_all_combination', $revisionSetupInput))) {
            $sendSetup = [...$sendSetup, ...$this->validatedCampaignPlaceholders($request)];
        }
        $content = $this->campaignContent($data, $sendSetup, $campaign->kind === 'transactional');
        $contentHash = $this->campaignContentHash($content);
        $prior = $campaign->revisions()->where('idempotency_key', $idempotencyKey)->first();
        if ($prior) {
            abort_unless(hash_equals($prior->request_fingerprint, $contentHash), 409, 'This revision key was already used for different content.');

            return redirect()->route($campaign->kind === 'marketing' ? 'console.campaigns.index' : 'console.campaigns.show', $campaign->kind === 'marketing' ? [$workspace->getKey()] : [$workspace->getKey(), $campaign->getKey()])
                ->with('status', 'Draft revision already saved; no duplicate was created.');
        }

        $unchangedMarketingEdit = false;
        DB::transaction(function () use ($request, $workspace, $campaign, $content, $contentHash, $idempotencyKey, $sourceRevisionId, $snapshotBuilder, &$unchangedMarketingEdit): void {
            $lockedCampaign = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($lockedCampaign->status === 'draft' && $lockedCampaign->is_active, 409, 'Only active draft campaigns can be revised.');
            $latest = $campaign->revisions()->lockForUpdate()->first();
            abort_unless(
                (string) ($latest?->getKey() ?? '') === (string) ($sourceRevisionId ?? ''),
                409,
                'The campaign changed while this revision was being prepared. Reload the latest revision and try again.',
            );
            if ($lockedCampaign->kind === 'marketing' && $latest && hash_equals($latest->content_sha256, $contentHash)) {
                $unchangedMarketingEdit = true;

                return;
            }

            $revision = $campaign->revisions()->create([
                'revision_number' => ($latest?->revision_number ?? 0) + 1,
                'payload' => $content,
                'content_sha256' => $contentHash,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $contentHash,
                'created_by' => $request->user()->getKey(),
                'created_at' => now(),
            ]);

            $sourceSnapshot = $sourceRevisionId === null
                ? null
                : CampaignRecipientSnapshot::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->where('campaign_id', $campaign->getKey())
                    ->where('revision_id', $sourceRevisionId)
                    ->first();
            $snapshot = $sourceSnapshot ? $snapshotBuilder->copyToRevision($sourceSnapshot, (string) $revision->getKey()) : null;

            $this->recordCampaignEvent($request, $workspace, $campaign, 'campaign.revised', [
                'revision_id' => $revision->getKey(),
                'content_sha256' => $contentHash,
                ...($snapshot ? [
                    'recipient_snapshot_id' => $snapshot->getKey(),
                    'recipient_count' => $snapshot->recipient_count,
                    'recipient_manifest_sha256' => $snapshot->recipients_sha256,
                ] : []),
            ]);
        });

        if ($unchangedMarketingEdit) {
            throw ValidationException::withMessages(['campaign' => 'Nothing Changed.']);
        }

        return redirect()->route($campaign->kind === 'marketing' ? 'console.campaigns.index' : 'console.campaigns.show', $campaign->kind === 'marketing' ? [$workspace->getKey()] : [$workspace->getKey(), $campaign->getKey()])
            ->with('status', $campaign->kind === 'marketing' ? 'Campaign edited and saved as a new revision.' : 'Draft content saved as a new immutable revision.');
    }

    public function checkCampaignSmtp(Request $request, string $workspace): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        abort_unless(
            $this->campaignSmtpConfigured(),
            503,
            'AlphaLink SMTP is not configured for sending. Set MAIL_MAILER=smtp, MAIL_HOST, and MAIL_PORT in the environment loaded by this AlphaLink runtime, then reload its configuration before checking the connection.'
        );

        $exceptionType = null;
        $connected = false;
        $transport = null;
        try {
            $transport = Mail::mailer('smtp')->getSymfonyTransport();
            abort_unless(
                $transport instanceof SmtpTransport,
                503,
                'AlphaLink could not create its configured Symfony SMTP transport.'
            );
            $transport->start();
            $connected = true;
        } catch (Throwable $exception) {
            $exceptionType = class_basename($exception);
        } finally {
            if ($transport instanceof SmtpTransport) {
                try {
                    $transport->stop();
                } catch (Throwable) {
                    $connected = false;
                    $exceptionType ??= 'TransportException';
                }
            }
        }

        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => $connected ? 'campaign.smtp_connection_succeeded' : 'campaign.smtp_connection_failed',
            'subject_type' => 'mail_transport',
            'request_id' => $request->attributes->get('request_id'),
            'properties' => [
                'transport' => 'smtp',
                'connected' => $connected,
                'exception_type' => $exceptionType,
                'message_sent' => false,
            ],
            'occurred_at' => now(),
        ]);

        if ($connected) {
            return back(303)
                ->with('status', 'Symfony SMTP handshake succeeded. No email was sent; verify sender acceptance with a one-recipient test.');
        }

        return back(303)
            ->withErrors(['smtp_connection' => 'AlphaLink could not connect to the configured SMTP server. No email was sent. Check the host, port, TLS settings, and server availability.']);
    }

    public function sendCampaignAutomaticTest(
        Request $request,
        string $workspace,
        string $campaign,
        DmsCampaignSendCatalog $catalog,
        PmtaCampaignTestTransport $transportFactory,
        DmsCampaignTagRenderer $tagRenderer,
        DmsBulkInstallVmtaPlanner $vmtaPlanner,
    ): RedirectResponse|JsonResponse {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'send_type' => ['required', 'string', Rule::in(['delay', 'delayp', 'delayps', 'hs', 'hsp', 'hsps', 'pickup', 'pickups'])],
        ]);
        $campaign = $workspace->campaigns()
            ->with('latestRevision')
            ->where('created_by', $request->user()->getKey())
            ->whereKey($campaign)
            ->firstOrFail();
        abort_unless($campaign->kind === 'marketing', 409, 'Only marketing campaigns support the native campaign test action.');

        $revision = $campaign->latestRevision;
        abort_unless($revision, 409, 'Save the campaign before testing it.');
        $payload = $revision->payload;
        $emailTests = $payload['email_tests'] ?? [];
        if (! is_array($emailTests) || $emailTests === []) {
            if ($request->expectsJson()) {
                return response()->json(['data' => 'No email test'], 400);
            }

            throw ValidationException::withMessages(['email_test_addresses' => 'No Email Test addresses are saved for this campaign.']);
        }

        $sendSetup = $payload['send_setup'] ?? [];
        abort_unless(is_array($sendSetup), 409, 'The saved campaign has no usable send setup.');
        $request->attributes->set('automatic_campaign_test', true);
        $request->merge([
            'idempotency_key' => $data['idempotency_key'],
            'test_campaign_id' => $campaign->getKey(),
            'expected_revision_id' => $revision->getKey(),
            'return_path' => (string) ($payload['return_path'] ?? ''),
            'email_test_addresses' => implode("\n", array_map('strval', $emailTests)),
            'header' => (string) ($payload['header'] ?? ''),
            'body' => (string) ($payload['body'] ?? ''),
            'domain' => (string) ($sendSetup['domain'] ?? ''),
            'send_setup' => [...$sendSetup, 'send_type' => $data['send_type']],
        ]);

        return $this->sendCampaignManualTest($request, (string) $workspace->getKey(), $catalog, $transportFactory, $tagRenderer, $vmtaPlanner);
    }

    public function sendCampaignManualTest(
        Request $request,
        string $workspace,
        DmsCampaignSendCatalog $catalog,
        PmtaCampaignTestTransport $transportFactory,
        DmsCampaignTagRenderer $tagRenderer,
        DmsBulkInstallVmtaPlanner $vmtaPlanner,
    ): RedirectResponse|JsonResponse {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'test_campaign_id' => ['nullable', 'uuid'],
            'expected_revision_id' => ['nullable', 'uuid'],
            'return_path' => ['nullable', 'string', 'max:320'],
            'email_test_addresses' => ['required', 'string'],
            'header' => ['required', 'string'],
            'body' => ['nullable', 'string'],
            'domain' => ['nullable', 'string', 'max:253'],
            'send_setup.server_ids' => ['required', 'array', 'min:1'],
            'send_setup.server_ids.*' => ['required', 'uuid', 'distinct'],
            'send_setup.ip_ids' => ['sometimes', 'array'],
            'send_setup.ip_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'send_setup.route_option' => ['required', 'in:ip,vmta,smtp'],
            'send_setup.ip_type' => ['required', 'in:all,ipv4,ipv6'],
            'send_setup.send_type' => ['required', 'in:delay,delayp,delayps,hs,hsp,hsps,pickup,pickups'],
            'send_setup.from_asset_ids' => ['sometimes', 'array'],
            'send_setup.from_asset_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'send_setup.subject_asset_ids' => ['sometimes', 'array'],
            'send_setup.subject_asset_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'send_setup.creative_asset_id' => ['nullable', 'integer', 'min:1'],
            'send_setup.negative_id' => ['nullable', 'integer', 'min:1'],
            'send_setup.placeholder_combination' => ['sometimes', 'boolean'],
            'send_setup.placeholder_all_combination' => ['sometimes', 'boolean'],
            'send_setup.placeholder_inputs' => ['sometimes', 'array'],
            'send_setup.placeholder_inputs.*.data_text' => ['nullable', 'string'],
            'send_setup.placeholder_inputs.*.rotate_every' => ['required', 'integer', 'min:1'],
            'send_setup.bounce_limit' => ['sometimes', 'integer', 'min:0'],
            'send_setup.total_send' => ['sometimes', 'integer', 'min:1'],
            'send_setup.fraction_ip' => ['sometimes', 'integer', 'min:1'],
            'send_setup.sleep_email' => ['sometimes', 'integer', 'min:0'],
            'send_setup.sleep_ip' => ['sometimes', 'integer', 'min:0'],
            'send_setup.seed_every' => ['sometimes', 'integer', 'min:0'],
            'send_setup.duplicate_data' => ['sometimes', 'integer', 'min:1'],
        ]);
        $campaign = null;
        if (filled($data['test_campaign_id'] ?? null)) {
            $campaign = $workspace->campaigns()->where('created_by', $request->user()->getKey())
                ->whereKey($data['test_campaign_id'])->firstOrFail();
            abort_unless($campaign->kind === 'marketing', 409, 'Only marketing campaigns support the native campaign test action.');
            abort_unless(
                $request->attributes->get('automatic_campaign_test') === true || ($campaign->status === 'draft' && $campaign->is_active),
                409,
                'Only active marketing drafts can be tested from the Edit composer.'
            );
            abort_unless((string) $campaign->latestRevision?->getKey() === (string) $request->input('expected_revision_id'), 409, 'The campaign changed. Reload the edit form before testing.');
        }
        $recipients = $this->campaignEmailTests($data['email_test_addresses']);
        if ($recipients === []) {
            throw ValidationException::withMessages(['email_test_addresses' => 'Enter at least one Email Test address.']);
        }
        foreach ($recipients as $index => $recipient) {
            $recipients[$index] = mb_strtolower(trim($recipient));
            if (mb_strlen($recipients[$index]) > 320 || filter_var($recipients[$index], FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages(['email_test_addresses' => 'Enter a valid email address on each Email Test line.']);
            }
        }
        $options = $catalog->options($workspace, $request->user(), (string) $workspace->pivot->role, [
            'server_ids' => array_map('strval', $data['send_setup']['server_ids']),
            'route' => $data['send_setup']['route_option'],
            'ip_type' => $data['send_setup']['ip_type'],
        ]);
        $availableServerIds = collect($options['servers'])->pluck('id')->map('strval')->all();
        $serverIds = array_map('strval', $data['send_setup']['server_ids']);
        if (array_diff($serverIds, $availableServerIds) !== []) {
            throw ValidationException::withMessages(['send_setup.server_ids' => 'Select active installed servers available to your account.']);
        }
        $selectedIps = array_map('strval', $data['send_setup']['ip_ids'] ?? []);
        if ($selectedIps !== [] && array_diff($selectedIps, collect($options['ips'])->pluck('id')->map('strval')->all()) !== []) {
            throw ValidationException::withMessages(['send_setup.ip_ids' => 'Select IPs from the currently selected server and route options.']);
        }
        $serversById = $workspace->managedServers()->whereIn('id', $serverIds)->where('is_active', true)->where('is_installed', true)->get()->keyBy(fn (ManagedServer $server): string => (string) $server->getKey());
        abort_unless($serversById->count() === count($serverIds), 422, 'A selected server is no longer active and installed.');
        $servers = collect($serverIds)->map(fn (string $serverId): ?ManagedServer => $serversById->get($serverId))->filter()->values();
        $server = $servers->first();
        $port = $this->pmtaListenerPort($workspace);
        abort_unless($port !== null, 503, 'Configure a valid workspace PMTA SMTP listener port before testing.');

        $recipientHash = hash('sha256', json_encode($recipients, JSON_THROW_ON_ERROR));
        $header = str_replace(["\r\n", "\r"], "\n", $data['header']);
        $submittedHeader = $header;
        $headerTemplate = preg_replace('/^\s*to\s*:\s*.*$/im', 'To: [to]', $header) ?? $header;
        if (! preg_match('/^\s*to\s*:/im', $headerTemplate)) {
            $headerTemplate = rtrim($headerTemplate)."\nTo: [to]";
        }
        $fromAssetIds = array_map('intval', $data['send_setup']['from_asset_ids'] ?? []);
        $subjectAssetIds = array_map('intval', $data['send_setup']['subject_asset_ids'] ?? []);
        $fromAssets = $this->campaignTestAssets($workspace, $fromAssetIds, 'from');
        $subjectAssets = $this->campaignTestAssets($workspace, $subjectAssetIds, 'subject');
        $negative = filled($data['send_setup']['negative_id'] ?? null)
            ? WorkspaceCampaignNegative::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('created_by', $request->user()->getKey())
                ->whereKey($data['send_setup']['negative_id'])
                ->first()
            : null;
        $negativePath = $negative ? Storage::disk('local')->path($negative->path) : '';
        $placeholderRows = $data['send_setup']['placeholder_inputs'] ?? $data['send_setup']['placeholders'] ?? [];
        $routeChoices = $servers->mapWithKeys(fn (ManagedServer $selectedServer): array => [
            (string) $selectedServer->getKey() => $this->campaignTestRouteChoice(
                $options['ips'],
                $selectedIps,
                $selectedServer,
                $data['send_setup']['route_option'],
                $data['send_setup']['ip_type'],
            ),
        ]);
        if ($data['send_setup']['route_option'] === 'ip') {
            foreach ($servers as $selectedServer) {
                $ipChoice = $routeChoices->get((string) $selectedServer->getKey());
                if (! is_array($ipChoice) || filter_var((string) ($ipChoice['ip'] ?? ''), FILTER_VALIDATE_IP) === false) {
                    throw ValidationException::withMessages([
                        'send_setup.ip_type' => 'No active IP route matching the selected IPv4/IPv6 filter is available on a selected server.',
                    ]);
                }
            }
        }
        if (in_array($data['send_setup']['route_option'], ['vmta', 'smtp'], true)) {
            foreach ($servers as $selectedServer) {
                $virtualMtaName = trim((string) ($routeChoices->get((string) $selectedServer->getKey())['pmta_name'] ?? ''));
                if (preg_match('/\A[A-Za-z0-9_.:-]{1,255}\z/', $virtualMtaName) !== 1) {
                    throw ValidationException::withMessages([
                        'send_setup.route_option' => $data['send_setup']['route_option'] === 'smtp'
                            ? 'No pushed SMTP route is available for the selected server. Configure and push an SMTP route, then reload this campaign before testing.'
                            : 'No pushed VMTA is available for the selected server. Push a VMTA in PMTA VMTA, then reload this campaign before testing.',
                    ]);
                }
            }
        }
        $ipTagData = $servers->mapWithKeys(fn (ManagedServer $selectedServer): array => [
            (string) $selectedServer->getKey() => $this->campaignTestIpTags(
                $workspace,
                $selectedServer,
                $data['send_setup']['route_option'],
                $routeChoices->get((string) $selectedServer->getKey()),
            ),
        ]);
        $domain = (string) ($ipTagData->first()['domain'] ?? '');
        $ispId = $data['send_setup']['isp_id']
            ?? $campaign?->latestRevision?->payload['send_setup']['isp_id']
            ?? '0';
        $creativeId = $data['send_setup']['creative_asset_id'] ?? '0';
        $testMessage = function (int $index, string $recipient, ManagedServer $selectedServer) use (
            $tagRenderer,
            $headerTemplate,
            $data,
            $campaign,
            $ispId,
            $fromAssets,
            $subjectAssets,
            $ipTagData,
            $routeChoices,
            $negativePath,
            $placeholderRows,
            $vmtaPlanner,
        ): array {
            $from = $fromAssets === [] ? ['id' => 0, 'value' => ''] : $fromAssets[$index % count($fromAssets)];
            $subject = $subjectAssets === [] ? ['id' => 0, 'value' => ''] : $subjectAssets[$index % count($subjectAssets)];
            $ipTags = $ipTagData->get((string) $selectedServer->getKey(), []);
            $tags = [
                ...$ipTags,
                'to' => $recipient,
                'name' => explode('@', $recipient)[0],
                'from' => (string) ($from['value'] ?? ''),
                'subject' => (string) ($subject['value'] ?? ''),
                'date' => now()->toRfc2822String(),
                'negative' => $negativePath,
                'token' => $tagRenderer->campaignTestToken(
                    $campaign?->getKey() ?? '0',
                    $ispId,
                ),
                'boundary' => Str::random(30),
            ];
            $cache = [];
            $renderedHeader = $tagRenderer->render($headerTemplate, $tags, $placeholderRows, $index, $cache);
            $renderedBody = $tagRenderer->render((string) ($data['body'] ?? ''), $tags, $placeholderRows, $index, $cache);
            $renderedReturnPath = $tagRenderer->render((string) ($data['return_path'] ?? ''), $tags, $placeholderRows, $index, $cache);
            if ($data['send_setup']['route_option'] === 'vmta') {
                $campaignDomain = trim((string) ($data['domain'] ?? ''));
                $vmtaDomain = trim((string) ($ipTags['domain'] ?? ''));
                $renderedHeader = $this->replaceCampaignTestDomain($renderedHeader, $campaignDomain, $vmtaDomain);
                $renderedBody = $this->replaceCampaignTestDomain($renderedBody, $campaignDomain, $vmtaDomain);
                $renderedReturnPath = $this->replaceCampaignTestDomain($renderedReturnPath, $campaignDomain, $vmtaDomain);
                $renderedHeader = $this->campaignTestHeaderWithVirtualMta(
                    $renderedHeader,
                    (string) $routeChoices->get((string) $selectedServer->getKey())['pmta_name'],
                );
            } elseif ($data['send_setup']['route_option'] === 'ip') {
                $ipChoice = $routeChoices->get((string) $selectedServer->getKey());
                $renderedHeader = $this->campaignTestHeaderWithVirtualMta(
                    $renderedHeader,
                    $vmtaPlanner->ipVirtualMtaName((string) $ipChoice['ip']),
                );
            } elseif ($data['send_setup']['route_option'] === 'smtp') {
                $smtpChoice = $routeChoices->get((string) $selectedServer->getKey());
                $renderedHeader = $this->campaignTestHeaderWithVirtualMta(
                    $renderedHeader,
                    (string) $smtpChoice['pmta_name'],
                );
            }
            $fromAddress = $this->campaignHeaderEmailAddress($renderedHeader, 'from');
            $returnPathAddress = trim($renderedReturnPath) === ''
                ? $fromAddress
                : $this->campaignEmailAddress($renderedReturnPath);

            return [
                'header' => $renderedHeader,
                'body' => $renderedBody,
                'return_path' => $renderedReturnPath,
                'from_address' => $fromAddress,
                'envelope_from' => $returnPathAddress,
            ];
        };
        $firstTestMessage = null;
        $checkedServers = [];
        foreach ($recipients as $index => $recipient) {
            $selectedServer = $servers[$index % $servers->count()];
            $serverId = (string) $selectedServer->getKey();
            if (isset($checkedServers[$serverId])) {
                continue;
            }

            $preview = $testMessage($index, $recipient, $selectedServer);
            foreach (['from_address' => 'Header', 'envelope_from' => 'Return-Path'] as $addressField => $fieldName) {
                if (filter_var($preview[$addressField], FILTER_VALIDATE_EMAIL) === false) {
                    $errorKey = $addressField === 'from_address' ? 'header' : 'return_path';
                    throw ValidationException::withMessages([$errorKey => "The current {$fieldName} must resolve to a valid email address. Check its tags and the selected server/IP data."]);
                }
            }
            $checkedServers[$serverId] = true;
            if ($index === 0) {
                $firstTestMessage = $preview;
            }
            if (count($checkedServers) === $servers->count()) {
                break;
            }
        }
        $firstTestMessage ??= $testMessage(0, $recipients[0], $server);
        $keyHash = hash('sha256', $data['idempotency_key']);
        $fingerprint = hash('sha256', json_encode([
            $campaign?->getKey(), $serverIds, $selectedIps, $recipients, $submittedHeader,
            $data['return_path'] ?? '', $data['body'] ?? '', $data['domain'] ?? '', $data['send_setup'],
        ], JSON_THROW_ON_ERROR));
        $nextFormInput = [...$request->except('_token'), 'idempotency_key' => (string) Str::uuid()];
        $testSnapshot = [
            'return_path' => $data['return_path'] ?? '',
            'resolved_return_path' => $firstTestMessage['return_path'],
            'email_tests' => $recipients,
            'from' => array_map('strval', $data['send_setup']['from_asset_ids'] ?? []),
            'subjects' => array_map('strval', $data['send_setup']['subject_asset_ids'] ?? []),
            'creative_id' => $data['send_setup']['creative_asset_id'] ?? null,
            'header' => $submittedHeader,
            'resolved_header' => $firstTestMessage['header'],
            'domain' => $domain,
            'negative_id' => $data['send_setup']['negative_id'] ?? null,
            'body' => $data['body'] ?? '',
            'resolved_body' => $firstTestMessage['body'],
            'mta' => $data['send_setup']['route_option'],
            'type' => $data['send_setup']['ip_type'],
            'virtual_mtas' => in_array($data['send_setup']['route_option'], ['ip', 'vmta', 'smtp'], true)
                ? $servers->map(fn (ManagedServer $selectedServer): array => [
                    'server_id' => (string) $selectedServer->getKey(),
                    'catalog_id' => (string) ($routeChoices->get((string) $selectedServer->getKey())['id'] ?? ''),
                    'name' => $data['send_setup']['route_option'] === 'ip'
                        ? (string) ($routeChoices->get((string) $selectedServer->getKey())['ip'] ?? '')
                        : (string) ($data['send_setup']['route_option'] === 'smtp'
                            ? ($routeChoices->get((string) $selectedServer->getKey())['username'] ?? '')
                            : ($routeChoices->get((string) $selectedServer->getKey())['name'] ?? '')),
                    'pmta_name' => $data['send_setup']['route_option'] === 'ip'
                        ? $vmtaPlanner->ipVirtualMtaName((string) $routeChoices->get((string) $selectedServer->getKey())['ip'])
                        : (string) ($routeChoices->get((string) $selectedServer->getKey())['pmta_name'] ?? ''),
                    'domain' => (string) ($data['send_setup']['route_option'] === 'ip'
                        ? ($routeChoices->get((string) $selectedServer->getKey())['dns'] ?? '')
                        : ($data['send_setup']['route_option'] === 'smtp'
                            ? ($routeChoices->get((string) $selectedServer->getKey())['dns'] ?? '')
                            : ($routeChoices->get((string) $selectedServer->getKey())['vmta_domain'] ?? ''))),
                ])->values()->all()
                : [],
            'servers' => collect($serverIds)->map(fn (string $serverId): array => [
                'id' => $serverId,
                'name' => $serversById->get($serverId)?->name,
            ])->values()->all(),
            'ips' => $selectedIps,
            'placeholder_combination' => $request->boolean('send_setup.placeholder_combination'),
            'placeholder_all_combination' => $request->boolean('send_setup.placeholder_all_combination'),
            'placeholders' => $placeholderRows,
            'send_type' => $data['send_setup']['send_type'],
            'bounce_limit' => $data['send_setup']['bounce_limit'] ?? null,
            'duplicate_data' => $data['send_setup']['duplicate_data'] ?? null,
            'total_send' => $data['send_setup']['total_send'] ?? null,
            'fraction_ip' => $data['send_setup']['fraction_ip'] ?? null,
            'sleep_email' => $data['send_setup']['sleep_email'] ?? null,
            'sleep_ip' => $data['send_setup']['sleep_ip'] ?? null,
            'seed_every' => $data['send_setup']['seed_every'] ?? null,
        ];
        $prior = DB::table('campaign_manual_test_attempts')->where('idempotency_key_hash', $keyHash)->first();
        if ($prior) {
            abort_unless($prior->workspace_id === $workspace->getKey() && $prior->request_fingerprint === $fingerprint, 409, 'This test key was already used for different content.');

            $message = match ($prior->status) {
                'accepted_by_transport' => 'PMTA already accepted this test submission. No duplicate was sent; mailbox delivery is unconfirmed.',
                'rejected_by_transport' => 'The previous PMTA test was rejected. No duplicate was sent; review the relay/access error before retrying.',
                'transport_unavailable' => 'The previous test could not connect to PMTA. No duplicate was sent; check the selected server before retrying.',
                default => 'This test key has an unconfirmed result and will not be resent. A fresh test submission uses a new key and will be logged separately.',
            };

            return $this->campaignTestResponse($request, $nextFormInput, $message, (string) $prior->status, (string) $nextFormInput['idempotency_key'], (string) $prior->id);
        }
        $attemptId = (string) Str::uuid();
        try {
            DB::transaction(function () use ($request, $workspace, $campaign, $server, $serverIds, $recipients, $recipientHash, $keyHash, $fingerprint, $attemptId, $testSnapshot): void {
                DB::table('campaign_manual_test_attempts')->insert([
                    'id' => $attemptId, 'workspace_id' => $workspace->getKey(), 'campaign_id' => $campaign?->getKey(),
                    'actor_user_id' => $request->user()->getKey(), 'managed_server_id' => $server->getKey(),
                    'recipient_sha256' => $recipientHash, 'idempotency_key_hash' => $keyHash,
                    'request_fingerprint' => $fingerprint, 'status' => 'preparing',
                    'test_snapshot' => Crypt::encryptString(json_encode($testSnapshot, JSON_THROW_ON_ERROR)),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                AuditEvent::query()->create([
                    'workspace_id' => $workspace->getKey(), 'actor_user_id' => $request->user()->getKey(),
                    'action' => 'campaign.manual_test_started', 'subject_type' => 'campaign_manual_test_attempt',
                    'subject_id' => $attemptId, 'request_id' => $request->attributes->get('request_id'),
                    'properties' => ['campaign_id' => $campaign?->getKey(), 'server_ids' => $serverIds, 'recipient_count' => count($recipients), 'recipient_sha256' => $recipientHash, 'message_sha256' => $fingerprint],
                    'occurred_at' => now(),
                ]);
            });
        } catch (QueryException $exception) {
            $prior = DB::table('campaign_manual_test_attempts')->where('idempotency_key_hash', $keyHash)->first();
            if (! $prior) {
                throw $exception;
            }
            abort_unless($prior->workspace_id === $workspace->getKey() && $prior->request_fingerprint === $fingerprint, 409, 'This test key was already used for different content.');

            return $this->campaignTestResponse($request, $nextFormInput, 'This test submission was already recorded; no duplicate was sent.', (string) $prior->status, (string) $nextFormInput['idempotency_key'], (string) $prior->id);
        }

        $transport = null;
        $connected = false;
        $acceptedCount = 0;
        try {
            foreach ($recipients as $index => $recipient) {
                $server = $servers[$index % $servers->count()];
                $rendered = $index === 0 ? $firstTestMessage : $testMessage($index, $recipient, $server);
                $transport = $transportFactory->forServer($server, $port);
                $transport->start();
                $connected = true;
                $raw = new RawMessage($rendered['header']."\r\n\r\n".$rendered['body']);
                $envelope = new Envelope(new Address($rendered['envelope_from']), [new Address($recipient)]);
                (new SymfonyMailer($transport))->send($raw, $envelope);
                $acceptedCount++;
                $transport->stop();
                $transport = null;
            }
            DB::table('campaign_manual_test_attempts')->where('id', $attemptId)->update(['status' => 'accepted_by_transport', 'updated_at' => now()]);
            AuditEvent::query()->create([
                'workspace_id' => $workspace->getKey(), 'actor_user_id' => $request->user()->getKey(),
                'action' => 'campaign.manual_test_accepted', 'subject_type' => 'campaign_manual_test_attempt',
                'subject_id' => $attemptId, 'request_id' => $request->attributes->get('request_id'),
                'properties' => ['server_ids' => $serverIds, 'recipient_count' => $acceptedCount], 'occurred_at' => now(),
            ]);

            return $this->campaignTestResponse(
                $request,
                $nextFormInput,
                'Selected PMTA servers accepted the current-form Email Test messages. Mailbox delivery is unconfirmed.',
                'accepted_by_transport',
                (string) $nextFormInput['idempotency_key'],
                $attemptId,
            );
        } catch (Throwable $exception) {
            $smtpRejected = $exception instanceof UnexpectedResponseException;
            $relayDenied = $smtpRejected && str_contains(mb_strtolower($exception->getMessage()), 'relaying denied');
            $smtpResponseCode = $smtpRejected && $exception->getCode() >= 400 && $exception->getCode() <= 599
                ? $exception->getCode()
                : null;
            $status = $acceptedCount > 0
                ? 'partially_accepted'
                : ($smtpRejected ? 'rejected_by_transport' : ($connected ? 'outcome_unknown' : 'transport_unavailable'));
            $failureReason = $relayDenied ? 'relay_denied' : ($smtpRejected ? 'smtp_rejected' : null);

            DB::table('campaign_manual_test_attempts')->where('id', $attemptId)->update([
                'status' => $status,
                'exception_type' => class_basename($exception), 'updated_at' => now(),
            ]);
            AuditEvent::query()->create([
                'workspace_id' => $workspace->getKey(), 'actor_user_id' => $request->user()->getKey(),
                'action' => 'campaign.manual_test_failed', 'subject_type' => 'campaign_manual_test_attempt',
                'subject_id' => $attemptId, 'request_id' => $request->attributes->get('request_id'),
                'properties' => [
                    'server_ids' => $serverIds,
                    'accepted_count' => $acceptedCount,
                    'exception_type' => class_basename($exception),
                    'smtp_response_code' => $smtpResponseCode,
                    'failure_reason' => $failureReason,
                    'outcome_unknown' => ! $smtpRejected && ($connected || $acceptedCount > 0),
                ],
                'occurred_at' => now(),
            ]);

            if ($relayDenied) {
                $message = $acceptedCount > 0
                    ? "PMTA accepted {$acceptedCount} earlier test message(s), then denied relay access for the next message. Correct the selected server's PMTA relay authorization before retrying. Campaign Test does not require sender-domain DNS verification."
                    : "PMTA denied relay access for AlphaLink's sending host; no test message was accepted. Save AlphaLink's stable public IPv4 egress in App Settings → PMTA RELAY SOURCE IPS and ensure that source is authorized on this server. Campaign Test does not require sender-domain DNS verification.";
            } elseif ($smtpRejected) {
                $response = $smtpResponseCode === null ? 'an unexpected SMTP response' : "SMTP response {$smtpResponseCode}";
                $message = $acceptedCount > 0
                    ? "PMTA accepted {$acceptedCount} earlier test message(s), then rejected the next with {$response}. Check the selected server's PMTA logs before retrying."
                    : "PMTA rejected the test with {$response}; no test message was accepted. Check the selected server's PMTA logs before retrying.";
            } else {
                $message = $connected
                    ? 'PMTA did not confirm every test result. Check its logs and the recipient mailbox before retrying.'
                    : 'No message was sent: AlphaLink could not connect to the selected PMTA listener.';
            }

            return $this->campaignTestResponse(
                $request,
                $nextFormInput,
                $message,
                $status,
                (string) $nextFormInput['idempotency_key'],
                $attemptId,
                422,
            );
        } finally {
            if ($transport instanceof SmtpTransport) {
                try {
                    $transport->stop();
                } catch (Throwable) {
                    // The recorded send outcome is unaffected by shutdown.
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $formInput
     */
    /**
     * @param  list<int>  $assetIds
     * @return list<array{id: int, value: string}>
     */
    private function campaignTestAssets(Workspace $workspace, array $assetIds, string $type): array
    {
        if ($assetIds === []) {
            return [];
        }

        $assets = WorkspaceOfferAsset::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)
            ->where('type', $type)
            ->whereIn('id', $assetIds)
            ->get(['id', 'value'])
            ->keyBy('id');

        return collect($assetIds)
            ->map(fn (int $id): ?array => $assets->has($id) ? [
                'id' => (int) $assets->get($id)->getKey(),
                'value' => (string) $assets->get($id)->value,
            ] : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Resolve the DMS send-text values for one selected AlphaLink server.
     * Campaign Test may omit explicit IP IDs, so its approved server-only path
     * falls back to that server's primary active IP, then its first active IP.
     *
     * @return array<string, string>
     */
    private function campaignTestIpTags(
        Workspace $workspace,
        ManagedServer $server,
        string $route,
        ?array $routeChoice,
    ): array {
        $serverId = (string) $server->getKey();
        $choice = $routeChoice;

        $serverIps = ManagedServerIp::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('managed_server_id', $server->getKey())
            ->where('is_active', true)
            ->with(['domain:id,name', 'history'])
            ->orderBy('id')
            ->get();
        $primaryIp = $serverIps->first(fn (ManagedServerIp $ip): bool => (string) $ip->ip === (string) $server->ip_address);
        $ipRecordId = $choice['managed_server_ip_id'] ?? ($route === 'ip' ? ($choice['id'] ?? null) : null);
        $ip = $ipRecordId === null
            ? null
            : $serverIps->first(fn (ManagedServerIp $candidate): bool => (string) $candidate->getKey() === (string) $ipRecordId);

        if ($ip === null && filled($choice['ip'] ?? null)) {
            $ip = $serverIps->first(fn (ManagedServerIp $candidate): bool => (string) $candidate->ip === (string) $choice['ip']);
        }
        $ip ??= $primaryIp ?? $serverIps->first();

        $vmtaDomain = trim((string) ($choice['vmta_domain'] ?? ''));
        if ($route === 'vmta' && $vmtaDomain === '') {
            throw ValidationException::withMessages([
                'send_setup.route_option' => 'The selected VMTA has no configured domain. Set its domain in PMTA VMTA, push it, and reload this campaign before testing.',
            ]);
        }
        $domain = $route === 'vmta'
            ? $vmtaDomain
            : trim((string) ($ip?->dns ?: $ip?->domain?->name ?: $choice['dns'] ?? ''));
        $mainDomain = trim((string) ($primaryIp?->dns ?: $primaryIp?->domain?->name ?: 'NA'));
        $rdns = strtolower(rtrim(trim((string) $ip?->history?->ptr), '.'));
        if ($rdns === '' || $rdns === 'na') {
            $rdns = $domain;
        }

        return [
            'server' => (string) $server->name.(string) $server->getKey(),
            'main_domain' => $mainDomain,
            'ip' => (string) ($ip?->ip ?: $choice['ip'] ?? $server->ip_address),
            'domain' => $domain,
            'rdns' => $rdns,
            'idi' => (string) ($ip?->getKey() ?? '0'),
            'idd' => (string) ($ip?->sending_domain_id ?? $choice['sending_domain_id'] ?? '0'),
            'ids' => $serverId,
            'ide' => '0',
            'reply' => '',
            'textarea' => '',
            'smtp' => $route === 'smtp' ? (string) ($choice['username'] ?? '') : '',
            'smtp_name' => $route === 'smtp' ? (string) ($choice['name'] ?? '') : '',
            'tag' => '',
        ];
    }

    /**
     * Resolve a selected IP/MTA for a server. The manual Campaign Test also
     * supports the DMS-mapped server-only form by using that server's first
     * active route choice when no explicit IP/MTA row was selected.
     *
     * @param  list<array<string, mixed>>  $choices
     * @param  list<string>  $selectedChoiceIds
     * @return array<string, mixed>|null
     */
    private function campaignTestRouteChoice(
        iterable $choices,
        array $selectedChoiceIds,
        ManagedServer $server,
        string $route,
        string $ipType,
    ): ?array {
        $serverChoices = collect($choices)
            ->filter(fn (array $choice): bool => (string) ($choice['server_id'] ?? '') === (string) $server->getKey())
            ->values();

        foreach ($selectedChoiceIds as $selectedId) {
            $choice = $serverChoices->first(fn (array $candidate): bool => (string) ($candidate['id'] ?? '') === $selectedId);
            if ($choice !== null) {
                return $choice;
            }
        }

        if ($route !== 'ip') {
            return $serverChoices->first();
        }

        $defaultChoice = $serverChoices->first();
        if ($defaultChoice !== null) {
            return $defaultChoice;
        }

        $primaryIp = trim((string) $server->ip_address);
        $primaryType = filter_var($primaryIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? 'ipv6'
            : 'ipv4';
        if (filter_var($primaryIp, FILTER_VALIDATE_IP) === false || ($ipType !== 'all' && $primaryType !== $ipType)) {
            return null;
        }

        return [
            'id' => 'server-primary:'.(string) $server->getKey(),
            'server_id' => (string) $server->getKey(),
            'ip' => $primaryIp,
            'type' => $primaryType,
            'dns' => null,
            'sending_domain_id' => null,
        ];
    }

    /**
     * Force the selected PMTA VMTA onto the message so an editable header
     * cannot silently route the test through PMTA's default virtual MTA.
     */
    private function campaignTestHeaderWithVirtualMta(string $header, string $virtualMtaName): string
    {
        $header = str_replace(["\r\n", "\r"], "\n", $header);
        $header = preg_replace('/^x-virtual-mta\s*:[^\n]*(?:\n[ \t][^\n]*)*/im', '', $header) ?? $header;

        return rtrim($header, "\n")."\nX-virtual-MTA: ".$virtualMtaName;
    }

    private function replaceCampaignTestDomain(string $text, string $campaignDomain, string $vmtaDomain): string
    {
        if ($text === '' || $campaignDomain === '' || $vmtaDomain === '' || strcasecmp($campaignDomain, $vmtaDomain) === 0) {
            return $text;
        }

        $pattern = '/(?<![A-Za-z0-9.-])'.preg_quote($campaignDomain, '/').'(?![A-Za-z0-9.-])/i';

        return preg_replace($pattern, $vmtaDomain, $text) ?? $text;
    }

    private function campaignHeaderEmailAddress(string $header, string $field): string
    {
        $line = collect(preg_split('/\r\n|\r|\n/', $header) ?: [])
            ->first(fn (string $line): bool => preg_match('/^\s*'.preg_quote($field, '/').'\s*:/i', $line) === 1);

        return $line === null ? '' : $this->campaignEmailAddress((string) preg_replace('/^\s*'.preg_quote($field, '/').'\s*:\s*/i', '', $line));
    }

    private function campaignEmailAddress(string $value): string
    {
        if (preg_match('/<\s*([^<>]+)\s*>/', $value, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($value);
    }

    private function campaignTestResponse(
        Request $request,
        array $formInput,
        string $message,
        string $outcome,
        string $nextIdempotencyKey,
        ?string $attemptId = null,
        int $statusCode = 200,
    ): RedirectResponse|JsonResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'data' => $message,
                'outcome' => $outcome,
                'attempt_id' => $attemptId,
                'next_idempotency_key' => $nextIdempotencyKey,
            ], $statusCode);
        }

        $response = back(303)->withInput($formInput);

        if ($outcome === 'accepted_by_transport' || $statusCode === 200) {
            return $response->with('status', $message);
        }

        return $response->withErrors(['manual_test' => $message]);
    }

    public function sendCampaignPmtaTest(Request $request, string $workspace, string $campaign, DmsCampaignSendCatalog $catalog, PmtaCampaignTestTransport $transportFactory): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        $data = $request->validate([
            'revision_id' => ['required', 'uuid'],
            'server_id' => ['required', 'uuid'],
            'recipient' => ['required', 'email:rfc', 'max:320'],
            'from_email' => ['required', 'email:rfc', 'max:320'],
            'subject' => ['required', 'string', 'max:998'],
            'body' => ['required', 'string'],
            'idempotency_key' => ['required', 'uuid'],
            'confirm_authorized_recipient' => ['accepted'],
            'confirm_retry_unknown' => ['sometimes', 'accepted'],
        ]);
        $campaign = $workspace->campaigns()
            ->where('created_by', $request->user()->getKey())
            ->whereKey($campaign)
            ->firstOrFail();
        abort_unless($campaign->kind === 'marketing' && $campaign->status === 'draft' && $campaign->is_active, 409, 'Only active marketing drafts can send a PMTA campaign test.');
        $revision = $campaign->revisions()->whereKey($data['revision_id'])->firstOrFail();
        abort_unless($campaign->latestRevision?->is($revision), 409, 'The campaign changed. Reload its latest revision before testing.');
        $setup = $revision->payload['send_setup'] ?? null;
        abort_unless(is_array($setup) && in_array($data['server_id'], $setup['server_ids'] ?? [], true), 422, 'Choose a server saved on this campaign revision.');
        $recipient = mb_strtolower(trim($data['recipient']));
        abort_unless(in_array($recipient, array_map(fn (string $address): string => mb_strtolower(trim($address)), $revision->payload['email_tests'] ?? []), true), 422, 'Save this recipient in the campaign Email Test addresses before sending.');
        $options = $catalog->options($workspace, $request->user(), (string) $workspace->pivot->role, [
            'server_ids' => [$data['server_id']],
            'route' => $setup['route_option'] ?? 'ip',
            'ip_type' => $setup['ip_type'] ?? 'all',
        ]);
        abort_unless(collect($options['servers'])->contains(fn (array $server): bool => $server['id'] === $data['server_id']), 422, 'The selected server is no longer installed, active, or available to this user.');
        $server = $workspace->managedServers()->whereKey($data['server_id'])->where('is_active', true)->where('is_installed', true)->firstOrFail();
        $port = $this->pmtaListenerPort($workspace);
        abort_unless($port !== null, 503, 'Configure a valid PMTA SMTP listener port before sending a campaign test.');

        $recipientHash = hash('sha256', $recipient);
        $idempotencyHash = hash('sha256', $data['idempotency_key']);
        $fingerprint = hash('sha256', json_encode([$revision->getKey(), $server->getKey(), $recipient, $data['from_email'], $data['subject'], $data['body']], JSON_THROW_ON_ERROR));
        $prior = CampaignTestMailAttempt::query()->where('idempotency_key_hash', $idempotencyHash)->first();
        if ($prior) {
            abort_unless($prior->campaign_id === $campaign->getKey() && $prior->revision_id === $revision->getKey() && $prior->recipient_sha256 === $recipientHash && $prior->mailer === 'pmta_smtp' && $prior->request_fingerprint === $fingerprint, 409, 'This test submission key was already used for another message.');

            return redirect()->route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()])
                ->with('status', 'This PMTA test submission was already recorded; no duplicate was sent.');
        }

        try {
            $attempt = DB::transaction(function () use ($request, $workspace, $campaign, $revision, $server, $recipientHash, $idempotencyHash, $fingerprint): CampaignTestMailAttempt {
                $locked = $workspace->campaigns()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
                abort_unless($locked->status === 'draft' && $locked->is_active && $locked->latestRevision?->is($revision), 409, 'The campaign changed. Reload before testing.');
                $uncertain = CampaignTestMailAttempt::query()->where('campaign_id', $campaign->getKey())->where('revision_id', $revision->getKey())->where('recipient_sha256', $recipientHash)->where('mailer', 'pmta_smtp')->whereIn('status', ['preparing', 'outcome_unknown'])->exists();
                abort_unless(! $uncertain || $request->boolean('confirm_retry_unknown'), 409, 'A prior PMTA test has an uncertain result. Check the mailbox and PMTA logs before explicitly retrying.');
                $attempt = CampaignTestMailAttempt::query()->create([
                    'workspace_id' => $workspace->getKey(), 'campaign_id' => $campaign->getKey(), 'revision_id' => $revision->getKey(),
                    'actor_user_id' => $request->user()->getKey(), 'recipient_sha256' => $recipientHash,
                    'idempotency_key_hash' => $idempotencyHash, 'mailer' => 'pmta_smtp', 'status' => 'preparing',
                    'managed_server_id' => $server->getKey(), 'request_fingerprint' => $fingerprint,
                ]);
                $this->recordCampaignEvent($request, $workspace, $campaign, 'campaign.pmta_test_started', [
                    'attempt_id' => $attempt->getKey(), 'revision_id' => $revision->getKey(),
                    'server_id' => $server->getKey(), 'recipient_sha256' => $recipientHash, 'message_sha256' => $fingerprint,
                ]);

                return $attempt;
            });
        } catch (QueryException $exception) {
            $prior = CampaignTestMailAttempt::query()->where('idempotency_key_hash', $idempotencyHash)->first();
            if (! $prior) {
                throw $exception;
            }
            abort_unless($prior->request_fingerprint === $fingerprint && $prior->campaign_id === $campaign->getKey(), 409, 'This test submission key was already used for another message.');

            return redirect()->route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()])
                ->with('status', 'This PMTA test submission was already recorded; no duplicate was sent.');
        }

        $transport = null;
        $connected = false;
        try {
            $transport = $transportFactory->forServer($server, $port);
            $transport->start();
            $connected = true;
            $email = (new Email)
                ->from(new Address(mb_strtolower(trim($data['from_email']))))
                ->to(new Address($recipient))
                ->subject($data['subject'])
                ->html($data['body']);
            (new SymfonyMailer($transport))->send($email);
            $attempt->forceFill(['status' => 'accepted_by_transport', 'updated_at' => now()])->save();
            $this->recordCampaignEvent($request, $workspace, $campaign, 'campaign.pmta_test_accepted', [
                'attempt_id' => $attempt->getKey(), 'revision_id' => $revision->getKey(), 'server_id' => $server->getKey(),
            ]);

            return redirect()->route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()])
                ->with('status', 'The selected server’s PMTA SMTP listener accepted this test message. Mailbox delivery is unconfirmed.');
        } catch (Throwable $exception) {
            $attempt->forceFill(['status' => $connected ? 'outcome_unknown' : 'transport_unavailable', 'exception_type' => class_basename($exception), 'updated_at' => now()])->save();
            $this->recordCampaignEvent($request, $workspace, $campaign, 'campaign.pmta_test_failed', [
                'attempt_id' => $attempt->getKey(), 'revision_id' => $revision->getKey(), 'server_id' => $server->getKey(),
                'exception_type' => class_basename($exception), 'outcome_unknown' => $connected,
            ]);

            return redirect()->route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()])
                ->withErrors(['pmta_test' => $connected
                    ? 'PMTA did not confirm the send result. Check its logs and the recipient mailbox before retrying.'
                    : 'No message was sent: AlphaLink could not connect to the selected PMTA listener.']);
        } finally {
            if ($transport instanceof SmtpTransport) {
                try {
                    $transport->stop();
                } catch (Throwable) {
                    // The recorded send outcome is unaffected by shutdown.
                }
            }
        }
    }

    public function sendCampaignTestEmail(Request $request, string $workspace, string $campaign): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        $data = $request->validate([
            'recipient' => ['required', 'string', 'email:rfc', 'max:320'],
            'revision_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'uuid'],
            'confirm_authorized_recipient' => ['accepted'],
            'confirm_retry_unknown' => ['sometimes', 'accepted'],
        ]);
        $campaign = $workspace->campaigns()
            ->where('created_by', $request->user()->getKey())
            ->whereKey($campaign)
            ->firstOrFail();
        abort_unless($campaign->kind === 'transactional', 409, 'Symfony one-recipient tests are available only for transactional test messages. DMS campaign tests use the native server/IP sender.');
        abort_unless($campaign->status === 'draft' && $campaign->is_active, 409, 'Only active draft campaigns can send a test email.');
        $revision = $campaign->revisions()->whereKey($data['revision_id'])->firstOrFail();
        abort_unless($campaign->latestRevision?->is($revision), 409, 'The saved content changed. Reload the page and review the latest revision before testing it.');

        if ($this->campaignSenderUsesReservedDomain($revision->payload['from_email'] ?? null)) {
            return redirect()->route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()])
                ->withErrors(['test_email' => 'This saved revision uses a reserved example/test sender domain. For the separate Symfony transactional test path, add and verify a domain under New Test Message sender-domain settings, then save a new revision with a From address at that domain.']);
        }

        if (! $this->campaignSenderDomainIsVerified($workspace, $revision->payload['from_email'] ?? null)) {
            return redirect()->route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()])
                ->withErrors(['test_email' => 'The sender domain is not verified in this workspace. For the separate Symfony transactional test path, add a domain under New Test Message sender-domain settings, publish its AlphaLink verification TXT record, verify ownership, then save a new revision with a From address at that exact domain.']);
        }

        $recipient = mb_strtolower(trim($data['recipient']));
        $recipientHash = hash('sha256', $recipient);
        $idempotencyHash = hash('sha256', $data['idempotency_key']);
        $prior = CampaignTestMailAttempt::query()->where('idempotency_key_hash', $idempotencyHash)->first();
        if ($prior) {
            return $this->duplicateCampaignTestAttemptResponse($workspace, $campaign, (string) $revision->getKey(), $recipientHash, $prior);
        }

        $smtpSettings = config('mail.mailers.smtp', []);
        abort_unless(
            $this->campaignSmtpConfigured(allowArrayTransport: true),
            503,
            'AlphaLink SMTP is not configured for sending. Set MAIL_MAILER=smtp, MAIL_HOST, and MAIL_PORT in the environment loaded by this AlphaLink runtime, then reload its configuration before sending a test.'
        );

        $transport = Mail::mailer('smtp')->getSymfonyTransport();
        $smtpTransport = ($smtpSettings['transport'] ?? null) === 'smtp';
        abort_unless(
            ! $smtpTransport || $transport instanceof SmtpTransport,
            503,
            'AlphaLink could not create its configured Symfony SMTP transport.'
        );

        try {
            $reservation = DB::transaction(function () use ($request, $workspace, $campaign, $revision, $recipientHash, $idempotencyHash): array {
                $lockedCampaign = $workspace->campaigns()
                    ->where('created_by', $request->user()->getKey())
                    ->whereKey($campaign->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_unless($lockedCampaign->status === 'draft' && $lockedCampaign->is_active, 409, 'Only active draft campaigns can send a test email.');

                $lockedRevision = $lockedCampaign->revisions()->whereKey($revision->getKey())->lockForUpdate()->firstOrFail();
                abort_unless($lockedCampaign->latestRevision?->is($lockedRevision), 409, 'The saved content changed. Reload the page and review the latest revision before testing it.');

                $prior = CampaignTestMailAttempt::query()
                    ->where('idempotency_key_hash', $idempotencyHash)
                    ->lockForUpdate()
                    ->first();
                if ($prior) {
                    abort_unless(
                        $prior->campaign_id === $campaign->getKey()
                            && $prior->revision_id === $revision->getKey()
                            && hash_equals($prior->recipient_sha256, $recipientHash),
                        409,
                        'This test submission key was already used for another message.'
                    );

                    return ['attempt' => $prior, 'duplicate' => true];
                }

                $uncertain = CampaignTestMailAttempt::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->where('campaign_id', $campaign->getKey())
                    ->where('revision_id', $revision->getKey())
                    ->where('recipient_sha256', $recipientHash)
                    ->whereIn('status', ['preparing', 'outcome_unknown'])
                    ->lockForUpdate()
                    ->exists();
                abort_unless(
                    ! $uncertain || $request->boolean('confirm_retry_unknown'),
                    409,
                    'A previous test to this recipient has no confirmed SMTP result. Check the mailbox and SMTP logs, then explicitly confirm before retrying.'
                );

                $attempt = CampaignTestMailAttempt::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'campaign_id' => $campaign->getKey(),
                    'revision_id' => $revision->getKey(),
                    'actor_user_id' => $request->user()->getKey(),
                    'recipient_sha256' => $recipientHash,
                    'idempotency_key_hash' => $idempotencyHash,
                    'mailer' => 'smtp',
                    'status' => 'preparing',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->recordCampaignEvent($request, $workspace, $campaign, 'campaign.test_email_started', [
                    'attempt_id' => $attempt->getKey(),
                    'revision_id' => $revision->getKey(),
                    'recipient_sha256' => $recipientHash,
                    'retry_after_uncertain' => $request->boolean('confirm_retry_unknown'),
                ]);

                return ['attempt' => $attempt, 'duplicate' => false];
            });
        } catch (QueryException $exception) {
            $prior = CampaignTestMailAttempt::query()->where('idempotency_key_hash', $idempotencyHash)->first();
            if (! $prior) {
                throw $exception;
            }

            return $this->duplicateCampaignTestAttemptResponse($workspace, $campaign, (string) $revision->getKey(), $recipientHash, $prior);
        }

        if ($reservation['duplicate']) {
            return $this->duplicateCampaignTestAttemptResponse($workspace, $campaign, (string) $revision->getKey(), $recipientHash, $reservation['attempt']);
        }

        $attempt = $reservation['attempt'];

        try {
            if ($smtpTransport) {
                try {
                    $transport->start();
                } catch (Throwable $exception) {
                    DB::transaction(function () use ($request, $workspace, $campaign, $revision, $recipientHash, $attempt, $exception): void {
                        $attempt->forceFill([
                            'status' => 'transport_unavailable',
                            'exception_type' => class_basename($exception),
                            'updated_at' => now(),
                        ])->save();
                        $this->recordCampaignEvent($request, $workspace, $campaign, 'campaign.test_email_transport_unavailable', [
                            'attempt_id' => $attempt->getKey(),
                            'revision_id' => $revision->getKey(),
                            'recipient_sha256' => $recipientHash,
                            'exception_type' => class_basename($exception),
                        ]);
                    });

                    return redirect()->route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()])
                        ->withErrors(['test_email' => 'No email was sent: AlphaLink could not connect to the configured SMTP server. Check the SMTP host, port, TLS settings, and server availability, then retry.']);
                }
            }

            $content = $revision->payload;
            $email = (new Email)
                ->from(new Address($content['from_email'], $content['from_name']))
                ->to(new Address($recipient))
                ->subject($content['subject'])
                ->text($content['text_body'] ?? '')
                ->html($content['html_body'] ?? '<p>'.nl2br(e($content['text_body'] ?? '')).'</p>');

            if (filled($content['reply_to'] ?? null)) {
                $email->replyTo(new Address($content['reply_to']));
            }

            (new SymfonyMailer($transport))->send($email);

            DB::transaction(function () use ($request, $workspace, $campaign, $revision, $attempt): void {
                $attempt->forceFill(['status' => 'accepted_by_transport', 'updated_at' => now()])->save();
                $this->recordCampaignEvent($request, $workspace, $campaign, 'campaign.test_email_transport_accepted', [
                    'attempt_id' => $attempt->getKey(),
                    'revision_id' => $revision->getKey(),
                ]);
            });

            return redirect()->route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()])
                ->with('status', 'The SMTP transport accepted this one test email. Mailbox delivery is not confirmed.');
        } catch (Throwable $exception) {
            DB::transaction(function () use ($request, $workspace, $campaign, $revision, $attempt, $exception): void {
                $attempt->forceFill([
                    'status' => 'outcome_unknown',
                    'exception_type' => class_basename($exception),
                    'updated_at' => now(),
                ])->save();
                $this->recordCampaignEvent($request, $workspace, $campaign, 'campaign.test_email_outcome_unknown', [
                    'attempt_id' => $attempt->getKey(),
                    'revision_id' => $revision->getKey(),
                    'exception_type' => class_basename($exception),
                ]);
            });

            return redirect()->route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()])
                ->withErrors(['test_email' => 'The SMTP transport did not confirm the result. Check the mailbox and SMTP logs before retrying; AlphaLink did not automatically resend.']);
        } finally {
            if ($transport instanceof SmtpTransport) {
                try {
                    $transport->stop();
                } catch (Throwable) {
                    // The send outcome above is already recorded; shutdown cannot change it.
                }
            }
        }
    }

    private function validatedCampaignContent(Request $request, bool $includeCampaignFields, bool $requireDmsEmailTest = false, ?string $campaignKind = null): array
    {
        $rules = [
            'idempotency_key' => ['required', 'uuid'],
            'subject' => ['nullable', 'string', 'max:998'],
            'from_name' => ['nullable', 'string', 'max:160'],
            'from_email' => ['nullable', 'email:rfc', 'max:320'],
            'reply_to' => ['nullable', 'email:rfc', 'max:320'],
            'return_path' => ['nullable', 'string', 'max:320'],
            'email_test_addresses' => ['nullable', 'string'],
            'domain' => ['nullable', 'string', 'max:253'],
            'header' => ['required', 'string'],
            'body' => ['nullable', 'string'],
            'text_body' => ['nullable', 'string'],
            'html_body' => ['nullable', 'string'],
        ];

        if ($includeCampaignFields) {
            $rules['name'] = ['required', 'string', 'max:160'];
            $rules['kind'] = ['required', 'in:marketing,transactional'];
        }

        $kind = $includeCampaignFields ? (string) $request->input('kind', '') : (string) $campaignKind;
        $isTransactional = $kind === 'transactional';
        if ($isTransactional) {
            $rules['subject'] = ['required', 'string', 'max:998'];
            $rules['from_name'] = ['required', 'string', 'max:160'];
            $rules['from_email'] = ['required', 'email:rfc', 'max:320'];
        }

        $data = $request->validate($rules);
        if ($isTransactional && blank($data['text_body'] ?? null) && blank($data['html_body'] ?? null)) {
            throw ValidationException::withMessages(['text_body' => 'Enter a plain-text message or an HTML message.']);
        }

        $requiresEmailTests = $requireDmsEmailTest || ($includeCampaignFields && ($data['kind'] ?? null) === 'marketing');
        if ($requiresEmailTests
            && array_key_exists('email_test_addresses', $request->all())
            && $this->campaignEmailTests($data['email_test_addresses'] ?? null) === []) {
            throw ValidationException::withMessages(['email_test_addresses' => 'DMS requires at least one Email Test address when the field is submitted.']);
        }

        return $data;
    }

    private function validatedCampaignSendSetup(Request $request, Workspace $workspace, DmsCampaignSendCatalog $catalog): array
    {
        $data = $request->validate([
            'send_setup' => ['required', 'array'],
            'send_setup.sponsor_id' => ['required', 'integer', 'min:1'],
            'send_setup.offer_id' => ['required', 'integer', 'min:1'],
            'send_setup.offer_country' => ['required', 'string', 'max:8'],
            'send_setup.isp_id' => ['required', 'integer', 'min:1'],
            'send_setup.country' => ['required', 'string', 'max:8'],
            'send_setup.audience_segments' => ['required', 'array', 'min:1'],
            'send_setup.audience_segments.*' => ['required', 'string', 'max:80', 'distinct', 'regex:/^[0-9a-f-]{36}_(fresh|delivery|open|click|lead|unsubscribe|mix)$/i'],
            'send_setup.from_asset_ids' => ['sometimes', 'array'],
            'send_setup.from_asset_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'send_setup.subject_asset_ids' => ['sometimes', 'array'],
            'send_setup.subject_asset_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'send_setup.creative_asset_id' => ['nullable', 'integer', 'min:1'],
            'send_setup.html_asset_id' => ['nullable', 'integer', 'min:1'],
            'send_setup.order_by' => ['required', 'in:default,asc,desc,random'],
            'send_setup.start_from' => ['required', 'integer', 'min:0'],
            'send_setup.route_option' => ['required', 'in:ip,vmta,smtp'],
            'send_setup.ip_type' => ['required', 'in:all,ipv4,ipv6'],
            'send_setup.server_ids' => ['required', 'array', 'min:1'],
            'send_setup.server_ids.*' => ['required', 'uuid', 'distinct'],
            'send_setup.ip_ids' => ['required', 'array', 'min:1'],
            'send_setup.ip_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'send_setup.send_type' => ['required', 'in:delay,delayp,delayps,hs,hsp,hsps,pickup,pickups'],
            'send_setup.total_send' => ['required', 'integer', 'min:1'],
            'send_setup.fraction_ip' => ['required', 'integer', 'min:1'],
            'send_setup.sleep_email' => ['required', 'integer', 'min:0'],
            'send_setup.sleep_ip' => ['required', 'integer', 'min:0'],
            'send_setup.seed_every' => ['required', 'integer', 'min:0'],
            'send_setup.bounce_limit' => ['required', 'integer', 'min:0'],
            'send_setup.duplicate_data' => ['required', 'integer', 'min:1'],
            'send_setup.negative_id' => ['nullable', 'integer', 'min:1'],
        ], [
            'send_setup.ip_ids.required' => self::CAMPAIGN_IP_SELECTION_REQUIRED,
            'send_setup.ip_ids.min' => self::CAMPAIGN_IP_SELECTION_REQUIRED,
        ]);

        $selection = $catalog->validateSelection(
            $workspace,
            $request->user(),
            (string) $workspace->pivot->role,
            [...$data['send_setup'], 'domain' => (string) $request->input('domain', '')],
        );

        return [...$selection, ...$this->validatedCampaignPlaceholders($request)];
    }

    private function campaignSetupForValidation(array $setup): array
    {
        return [
            ...$setup,
            ...($setup['parameters'] ?? []),
        ];
    }

    /**
     * DMS stores one ordered value list and a rotate interval for each
     * [placeholderN] token. It also makes its two combination modes mutually
     * exclusive in the browser; enforce that rule on the server as well.
     *
     * @return array{placeholder_combination: bool, placeholder_all_combination: bool, placeholders: list<array{data: list<string>, rotate_every: int}>}
     */
    private function validatedCampaignPlaceholders(Request $request): array
    {
        $data = $request->validate([
            'send_setup.placeholder_combination' => ['sometimes', 'boolean'],
            'send_setup.placeholder_all_combination' => ['sometimes', 'boolean'],
            'send_setup.placeholder_inputs' => ['sometimes', 'array'],
            'send_setup.placeholder_inputs.*.data_text' => ['nullable', 'string'],
            'send_setup.placeholder_inputs.*.rotate_every' => ['required', 'integer', 'min:1'],
        ]);

        $input = $data['send_setup'] ?? [];
        $combination = (bool) ($input['placeholder_combination'] ?? false);
        $allCombination = (bool) ($input['placeholder_all_combination'] ?? false);
        if ($combination && $allCombination) {
            throw ValidationException::withMessages([
                'send_setup.placeholder_all_combination' => 'DMS allows only one placeholder combination mode at a time.',
            ]);
        }

        $placeholders = [];
        foreach ($input['placeholder_inputs'] ?? [] as $row) {
            $values = preg_split('/\\R/u', (string) ($row['data_text'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $values = array_values(array_filter(array_map(static fn (string $value): string => trim($value), $values), static fn (string $value): bool => $value !== ''));
            if ($values === []) {
                continue;
            }

            $placeholders[] = [
                'data' => $values,
                'rotate_every' => (int) $row['rotate_every'],
            ];
        }

        return [
            'placeholder_combination' => $combination,
            'placeholder_all_combination' => $allCombination,
            'placeholders' => $placeholders,
        ];
    }

    private function campaignContent(array $data, ?array $sendSetup = null, bool $transactional = false): array
    {
        $emailTests = $this->campaignEmailTests($data['email_test_addresses'] ?? null);

        $content = [
            'return_path' => filled($data['return_path'] ?? null) ? trim($data['return_path']) : '[rnd]@[domain]',
            'email_tests' => $emailTests,
            'header' => $data['header'],
            'body' => $data['body'] ?? null,
        ];

        if ($transactional) {
            $content = [
                ...$content,
                'subject' => trim($data['subject']),
                'from_name' => trim($data['from_name']),
                'from_email' => mb_strtolower(trim($data['from_email'])),
                'reply_to' => isset($data['reply_to']) ? mb_strtolower(trim($data['reply_to'])) : null,
                'text_body' => $data['text_body'] ?? null,
                'html_body' => $data['html_body'] ?? null,
            ];
        }

        if ($sendSetup !== null) {
            $content['send_setup'] = $sendSetup;
        }

        return $content;
    }

    private function campaignEmailTests(mixed $addresses): array
    {
        $emailTests = preg_split('/\R/u', (string) ($addresses ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($emailTests)
            ->map(fn (string $address): string => trim($address))
            ->filter()
            ->values()
            ->all();
    }

    private function campaignSmtpConfigured(bool $allowArrayTransport = false): bool
    {
        $smtpSettings = config('mail.mailers.smtp', []);
        $smtpTransport = ($smtpSettings['transport'] ?? null) === 'smtp'
            && config('mail.default') === 'smtp';
        $arrayTransport = $allowArrayTransport
            && app()->environment('testing')
            && ($smtpSettings['transport'] ?? null) === 'array';

        return ($smtpTransport || $arrayTransport)
            && filled($smtpSettings['host'] ?? null)
            && filled($smtpSettings['port'] ?? null);
    }

    /**
     * @return array{host: string, port: int, security: string}|null
     */
    private function campaignSmtpDestination(): ?array
    {
        try {
            $transport = Mail::mailer('smtp')->getSymfonyTransport();
        } catch (Throwable) {
            return null;
        }

        if (! $transport instanceof SmtpTransport || ! $transport->getStream() instanceof SocketStream) {
            return null;
        }

        $stream = $transport->getStream();
        $host = $stream->getHost();
        $parsedHost = parse_url($host, PHP_URL_HOST);
        if (is_string($parsedHost) && $parsedHost !== '') {
            $host = $parsedHost;
        } elseif (str_contains($host, '@')) {
            $host = substr($host, strrpos($host, '@') + 1);
        }

        $security = $stream->isTLS()
            ? 'Implicit TLS'
            : ($transport instanceof EsmtpTransport && $transport->isAutoTls()
                ? 'STARTTLS when offered by the server'
                : 'No TLS');

        return [
            'host' => $host,
            'port' => $stream->getPort(),
            'security' => $security,
        ];
    }

    private function duplicateCampaignTestAttemptResponse(
        Workspace $workspace,
        Campaign $campaign,
        string $revisionId,
        string $recipientHash,
        CampaignTestMailAttempt $prior,
    ): RedirectResponse {
        abort_unless(
            $prior->campaign_id === $campaign->getKey()
                && $prior->revision_id === $revisionId
                && hash_equals($prior->recipient_sha256, $recipientHash),
            409,
            'This test submission key was already used for another message.'
        );

        $status = match ($prior->status) {
            'accepted_by_transport' => 'SMTP already accepted this test submission; no duplicate was sent.',
            'transport_unavailable' => 'No email was sent because SMTP was unavailable. Fix the SMTP connection, then submit a new test.',
            default => 'This test submission has no confirmed SMTP result. Check the mailbox and SMTP logs before trying again.',
        };

        return redirect()->route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()])->with('status', $status);
    }

    private function campaignSenderUsesReservedDomain(?string $address): bool
    {
        $address = trim((string) $address);
        $atPosition = strrpos($address, '@');
        if ($atPosition === false) {
            return true;
        }

        $domain = strtolower(rtrim(substr($address, $atPosition + 1), '.'));
        foreach (['example.com', 'example.net', 'example.org'] as $reservedExampleDomain) {
            if ($domain === $reservedExampleDomain || str_ends_with($domain, '.'.$reservedExampleDomain)) {
                return true;
            }
        }

        foreach (['test', 'example', 'invalid', 'localhost'] as $reservedTopLevelDomain) {
            if ($domain === $reservedTopLevelDomain || str_ends_with($domain, '.'.$reservedTopLevelDomain)) {
                return true;
            }
        }

        return false;
    }

    private function campaignSenderDomainIsVerified(Workspace $workspace, ?string $address): bool
    {
        $address = trim((string) $address);
        $atPosition = strrpos($address, '@');
        if ($atPosition === false || $this->campaignSenderUsesReservedDomain($address)) {
            return false;
        }

        $domain = strtolower(rtrim(substr($address, $atPosition + 1), '.'));

        return $workspace->sendingDomains()
            ->whereRaw('LOWER(name) = ?', [$domain])
            ->where('state', 'verified')
            ->whereNotNull('verified_at')
            ->exists();
    }

    private function campaignContentHash(array $content): string
    {
        return hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function campaignCloneSource(Request $request, Workspace $workspace, string $campaign): Campaign
    {
        $source = $workspace->campaigns()
            ->with('latestRevision')
            ->where('created_by', $request->user()->getKey())
            ->whereKey($campaign)
            ->firstOrFail();
        $sendSetup = $source->latestRevision?->payload['send_setup'] ?? null;

        abort_unless($source->kind === 'marketing' && is_array($sendSetup), 409, 'Only saved DMS Send campaigns can be cloned.');
        $this->assertCampaignCloneOffer($workspace, $sendSetup);

        return $source;
    }

    /** @param array<string, mixed> $sendSetup */
    private function assertCampaignCloneOffer(Workspace $workspace, array $sendSetup): void
    {
        $offerId = filter_var($sendSetup['offer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $offer = $offerId === false ? null : WorkspaceOffer::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereKey($offerId)
            ->first();

        abort_unless($offer && $offer->status === 'live' && $offer->is_active, 409, 'Offer is not live or not active.');
    }

    private function assertCampaignCloneReplay(Campaign $prior, int|string $userId, string $name, string $requestFingerprint): void
    {
        $priorRevision = $prior->revisions()->where('revision_number', 1)->first();
        abort_unless(
            (string) $prior->created_by === (string) $userId
                && $prior->name === $name
                && $prior->kind === 'marketing'
                && $priorRevision
                && hash_equals($priorRevision->request_fingerprint, $requestFingerprint),
            409,
            'This clone submission key was already used for different campaign content.',
        );
    }

    private function recordCampaignEvent(Request $request, Workspace $workspace, Campaign $campaign, string $action, array $properties): void
    {
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => $action,
            'subject_type' => 'campaign',
            'subject_id' => $campaign->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => $properties,
            'occurred_at' => now(),
        ]);

        OutboxMessage::query()->create([
            'workspace_id' => $workspace->getKey(),
            'event_type' => $action,
            'payload' => ['campaign_id' => $campaign->getKey()] + $properties,
            'idempotency_key' => (string) Str::uuid(),
            'available_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function updateCampaignActiveStatus(Request $request, string $workspace, string $campaign): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $isActive = (bool) $validated['is_active'];

        DB::transaction(function () use ($request, $workspace, $campaign, $isActive): void {
            $record = $workspace->campaigns()
                ->where('created_by', $request->user()->getKey())
                ->whereKey($campaign)
                ->lockForUpdate()
                ->firstOrFail();
            $wasActive = (bool) $record->is_active;
            if ($wasActive === $isActive) {
                return;
            }

            $record->forceFill(['is_active' => $isActive])->save();
            AuditEvent::query()->create([
                'workspace_id' => $workspace->getKey(),
                'actor_user_id' => $request->user()->getKey(),
                'action' => 'campaign.availability_changed',
                'subject_type' => 'campaign',
                'subject_id' => $record->getKey(),
                'request_id' => $request->attributes->get('request_id'),
                'properties' => ['from_active' => $wasActive, 'to_active' => $isActive],
                'occurred_at' => now(),
            ]);
        });

        return back(303)->with('status', $isActive ? 'Campaign restored.' : 'Campaign disabled.');
    }

    public function disableCampaigns(Request $request, string $workspace): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::CAMPAIGN_ROLES);
        $data = $request->validate([
            'campaign_ids' => ['required', 'array', 'min:1'],
            'campaign_ids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $ids = array_values(array_unique($data['campaign_ids']));

        DB::transaction(function () use ($request, $workspace, $ids): void {
            $campaigns = $workspace->campaigns()
                ->where('created_by', $request->user()->getKey())
                ->whereKey($ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            abort_unless($campaigns->count() === count($ids), 404);

            foreach ($campaigns as $campaign) {
                if (! $campaign->is_active) {
                    continue;
                }

                $campaign->forceFill(['is_active' => false])->save();
                AuditEvent::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'actor_user_id' => $request->user()->getKey(),
                    'action' => 'campaign.availability_changed',
                    'subject_type' => 'campaign',
                    'subject_id' => $campaign->getKey(),
                    'request_id' => $request->attributes->get('request_id'),
                    'properties' => ['from_active' => true, 'to_active' => false, 'bulk' => true],
                    'occurred_at' => now(),
                ]);
            }
        });

        return back(303)->with('status', count($ids).' campaign'.(count($ids) === 1 ? ' was' : 's were').' disabled.');
    }

    public function audienceLists(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeRole($workspace, self::AUDIENCE_ROLES);

        $audienceLists = $workspace->audienceLists()
            ->withCount(['memberships as active_members_count' => fn ($query) => $query->where('status', 'active')])
            ->with('history')
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->whereJsonContains('allowed_users', (int) $request->user()->getKey()))
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
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->whereJsonContains('allowed_users', (int) $request->user()->getKey()))
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

    private function pmtaListenerPort(Workspace $workspace): ?int
    {
        $configuredPort = $workspace->infrastructureSettings?->pmta_port ?? config('infrastructure.pmta_port');
        $port = filter_var($configuredPort, FILTER_VALIDATE_INT);

        return $port !== false && $port >= 1 && $port <= 65535 ? $port : null;
    }

    /** @param array<int, string> $allowedRoles */
    private function authorizeRole(Workspace $workspace, array $allowedRoles): void
    {
        abort_unless(in_array($workspace->pivot->role, $allowedRoles, true), 403);
    }
}
