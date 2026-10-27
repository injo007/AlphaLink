<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\DomainProvider;
use App\Models\Isp;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerIpHistory;
use App\Models\ManagedServerMta;
use App\Models\SendingDomain;
use App\Models\SendingDomainHistory;
use App\Models\ServerProvider;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\CloudflareDnsApi;
use App\Services\Infrastructure\CloudflareDnsApiException;
use App\Services\Infrastructure\DmsBulkInstallEligibility;
use App\Services\Infrastructure\DmsBulkInstallIspCatalog;
use App\Services\Infrastructure\DmsBulkInstallPayload;
use App\Services\Infrastructure\DmsBulkInstallPrivateAssetReader;
use App\Services\Infrastructure\DmsBulkInstallRowRunner;
use App\Services\Infrastructure\DmsBulkInstallRuntimeInputs;
use App\Services\Infrastructure\DmsBulkInstallTranscriptStore;
use App\Services\Infrastructure\DmsChangeDomainRunner;
use App\Services\Infrastructure\DmsChangeDomainSelectionLoader;
use App\Services\Infrastructure\DmsDomainProviderConnectionTester;
use App\Services\Infrastructure\DmsDomainProviderImport;
use App\Services\Infrastructure\DmsDomainProviderImportException;
use App\Services\Infrastructure\DmsDomainProviderRefresh;
use App\Services\Infrastructure\DmsDomainRecordsManager;
use App\Services\Infrastructure\DmsInfrastructureRunLauncher;
use App\Services\Infrastructure\DmsInstallerServerCredentialResolver;
use App\Services\Infrastructure\DmsInstallPlatform;
use App\Services\Infrastructure\DmsPmtaDeploymentGate;
use App\Services\Infrastructure\DmsReinstallIpConfigurator;
use App\Services\Infrastructure\DmsReinstallSelectionLoader;
use App\Services\Infrastructure\DmsReinstallSelectionValidator;
use App\Services\Infrastructure\DmsServerConsole;
use App\Services\Infrastructure\DmsSingleInstallIpLoader;
use App\Services\Infrastructure\HostProbeException;
use App\Services\Infrastructure\InfrastructureManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InfrastructureConsoleController extends Controller
{
    public function __construct(private readonly InfrastructureManager $manager) {}

    public function index(Request $request, string $workspaceId): RedirectResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);

        return redirect()->route('console.infrastructure.servers.index', $workspace->id);
    }

    public function install(
        Request $request,
        string $workspaceId,
        DmsBulkInstallEligibility $eligibility,
    ): View {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $candidates = $eligibility->forSingleInstall($workspace);

        return view('console.infrastructure.install', [
            'workspace' => $workspace,
            'servers' => $candidates['servers'],
            'domains' => $candidates['domains'],
        ]);
    }

    public function loadSingleInstallIps(
        Request $request,
        string $workspaceId,
        DmsSingleInstallIpLoader $loader,
        DmsBulkInstallEligibility $eligibility,
    ): JsonResponse {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $validated = $request->validate([
            'server_id' => ['required', 'uuid'],
            'domain_ids' => ['required', 'array', 'min:1', 'max:64'],
            'domain_ids.*' => ['required', 'uuid', 'distinct'],
            'with_ipv6' => ['sometimes', 'boolean'],
        ]);

        // The workspace-membership check above matches the user's server
        // control policy. Scope the target before making any SSH connection.
        // AlphaLink records a host key internally for safe, read-only discovery.

        $server = $eligibility->singleInstallServerForSelection($workspace, $validated['server_id']);
        abort_if($server === null, 404);

        try {
            if ($server->state !== 'verified'
                || blank($server->expected_host_fingerprint)
                || blank($server->observed_host_fingerprint)) {
                $this->manager->verifyServerHost(
                    $workspace,
                    $request->user(),
                    $server,
                    $request->attributes->get('request_id'),
                );
            }

            return response()->json($loader->load(
                $request->user(),
                $workspace,
                $validated['server_id'],
                $validated['domain_ids'],
                (bool) ($validated['with_ipv6'] ?? true),
            ));
        } catch (HostProbeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }
    }

    public function configureSingleInstallIps(
        Request $request,
        string $workspaceId,
        string $serverId,
        DmsReinstallIpConfigurator $configurator,
    ): JsonResponse {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $data = $request->validate([
            'ips' => ['required', 'array', 'min:1', 'max:128'],
            'ips.*' => ['required', 'string', 'max:64'],
            'permanent' => ['sometimes', 'boolean'],
        ]);
        $server = $workspace->managedServers()
            ->where('is_active', true)
            ->where('is_installed', false)
            ->whereKey($serverId)
            ->firstOrFail();

        try {
            $configurator->configure($workspace, $server, $data['ips'], (bool) ($data['permanent'] ?? false), false);
        } catch (HostProbeException|InvalidArgumentException|RuntimeException $exception) {
            return response()->json(['data' => $exception->getMessage()], 400);
        }

        return response()->json(['data' => 'Finish Settings Ips']);
    }

    public function changeDomain(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $domains = $workspace->sendingDomains()
            ->where('is_active', true)
            ->whereIn('status', ['free', 'new', 'used'])
            ->where(function ($query): void {
                $query->whereDoesntHave('histories')
                    ->orWhereHas('histories', function ($history): void {
                        $history->where('is_listed_spamhaus', false)
                            ->where('is_listed_hostkarma', false);
                    });
            })
            ->orderByDesc('is_our')
            ->orderBy('name')
            ->get(['id', 'name', 'status']);

        return view('console.infrastructure.change-domain', [
            'workspace' => $workspace,
            'servers' => $workspace->managedServers()
                ->where('is_active', true)
                ->where('is_installed', true)
                ->orderByDesc('id')
                ->get(['id', 'name', 'ip_address']),
            'domains' => $domains,
            'domainOptions' => $domains->map(static fn (SendingDomain $domain): array => [
                'id' => (string) $domain->getKey(),
                'name' => (string) $domain->name,
                'status' => (string) $domain->status,
            ])->values()->all(),
        ]);
    }

    public function loadChangeDomainIps(
        Request $request,
        string $workspaceId,
        DmsChangeDomainSelectionLoader $loader,
    ): JsonResponse {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $validated = $request->validate(['server_id' => ['required', 'uuid']]);
        $server = $workspace->managedServers()
            ->where('is_active', true)
            ->where('is_installed', true)
            ->whereKey($validated['server_id'])
            ->firstOrFail();

        try {
            return response()->json($loader->load($workspace, $server));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function changeManagedServerDomain(
        Request $request,
        string $workspaceId,
        DmsChangeDomainRunner $runner,
    ): JsonResponse {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $validated = $request->validate([
            'server_id' => ['required', 'uuid'],
            'data' => ['required', 'array', 'min:1', 'max:128'],
            'data.*.ip_id' => ['required', 'integer', 'min:1'],
            'data.*.ip' => ['required', 'ip'],
            'data.*.type' => ['required', Rule::in(['ipv4', 'ipv6'])],
            'data.*.old_domain_id' => ['required', 'uuid'],
            'data.*.domain_id' => ['required', 'uuid'],
            // Laravel's ConvertEmptyStringsToNull middleware turns blank text
            // inputs into null before validation. Blank subdomains are valid.
            'data.*.sub' => ['present', 'nullable', 'string', 'max:63'],
            'data.*.main' => ['required', 'boolean'],
            'https' => ['required', 'boolean'],
            'spf' => ['required', 'boolean'],
            'dkim' => ['required', 'boolean'],
            'dmarc' => ['required', 'boolean'],
        ]);
        $server = $workspace->managedServers()
            ->where('is_active', true)
            ->where('is_installed', true)
            ->whereKey($validated['server_id'])
            ->firstOrFail();
        $logKey = $this->changeDomainLogKey($workspace, $server->getKey());
        Cache::put($logKey, [], now()->addHours(2));
        $log = static function (string $message) use ($logKey): void {
            $lines = Cache::get($logKey, []);
            $lines[] = $message;
            Cache::put($logKey, $lines, now()->addHours(2));
        };

        try {
            set_time_limit(1800);
            $runner->run(
                $workspace,
                $request->user(),
                $server,
                array_map(static fn (array $row): array => [
                    'ip_id' => (int) $row['ip_id'],
                    'ip' => (string) $row['ip'],
                    'type' => (string) $row['type'],
                    'old_domain_id' => (string) $row['old_domain_id'],
                    'domain_id' => (string) $row['domain_id'],
                    'sub' => (string) ($row['sub'] ?? ''),
                    'main' => filter_var($row['main'], FILTER_VALIDATE_BOOLEAN),
                ], $validated['data']),
                [
                    'https' => (bool) $validated['https'],
                    'spf' => (bool) $validated['spf'],
                    'dkim' => (bool) $validated['dkim'],
                    'dmarc' => (bool) $validated['dmarc'],
                ],
                $log,
                $request->attributes->get('request_id'),
            );
        } catch (HostProbeException|InvalidArgumentException|RuntimeException $exception) {
            $log('Error: '.$exception->getMessage());

            return response()->json(['data' => 'Error Occurred', 'message' => $exception->getMessage()], 400);
        }

        return response()->json(['data' => 'Changed Successfully']);
    }

    public function changeDomainLogs(Request $request, string $workspaceId, string $serverId): JsonResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();

        return response()->json(Cache::get($this->changeDomainLogKey($workspace, $server->getKey()), []));
    }

    private function changeDomainLogKey(Workspace $workspace, int|string $serverId): string
    {
        return 'alphalink:server:change-domain:'.$workspace->getKey().':'.$serverId;
    }

    public function reinstall(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);

        return view('console.infrastructure.reinstall', [
            'workspace' => $workspace,
            'servers' => $workspace->managedServers()
                ->where('is_active', true)
                ->where('is_installed', true)
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function loadReinstallConfiguration(
        Request $request,
        string $workspaceId,
        string $serverId,
        DmsReinstallSelectionLoader $loader,
    ): JsonResponse {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $server = $workspace->managedServers()
            ->where('is_active', true)
            ->where('is_installed', true)
            ->whereKey($serverId)
            ->firstOrFail();

        return response()->json($loader->loadConfiguration($workspace, $server));
    }

    public function reloadReinstallIps(
        Request $request,
        string $workspaceId,
        string $serverId,
        DmsReinstallSelectionLoader $loader,
    ): JsonResponse {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $data = $request->validate(['with_ipv6' => ['sometimes', 'boolean']]);
        $server = $workspace->managedServers()
            ->where('is_active', true)
            ->where('is_installed', true)
            ->whereKey($serverId)
            ->firstOrFail();

        try {
            return response()->json([
                'data' => $loader->reloadIps($workspace, $server, (bool) ($data['with_ipv6'] ?? true)),
            ]);
        } catch (HostProbeException|RuntimeException $exception) {
            return response()->json(['data' => $exception->getMessage()], 400);
        }
    }

    public function configureReinstallIps(
        Request $request,
        string $workspaceId,
        string $serverId,
        DmsReinstallIpConfigurator $configurator,
    ): JsonResponse {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $data = $request->validate([
            'ips' => ['required', 'array', 'min:1', 'max:128'],
            'ips.*' => ['required', 'string', 'max:64'],
            'permanent' => ['sometimes', 'boolean'],
        ]);
        $server = $workspace->managedServers()
            ->where('is_active', true)
            ->where('is_installed', true)
            ->whereKey($serverId)
            ->firstOrFail();

        if (! config('infrastructure.reinstall_ip_config_execution_enabled', false)) {
            return response()->json([
                'data' => 'CONFIG IPS is disabled by ALPHALINK_REINSTALL_IP_CONFIG_EXECUTION_ENABLED.',
                'code' => 'server_reinstall_runner_unavailable',
            ], 503);
        }

        try {
            $configurator->configure($workspace, $server, $data['ips'], (bool) ($data['permanent'] ?? false));
        } catch (HostProbeException|InvalidArgumentException|RuntimeException $exception) {
            return response()->json(['data' => $exception->getMessage()], 400);
        }

        return response()->json(['data' => 'Finish Settings Ips']);
    }

    public function startReinstall(
        Request $request,
        string $workspaceId,
        string $serverId,
        DmsReinstallSelectionValidator $selectionValidator,
        DmsPmtaDeploymentGate $pmtaDeploymentGate,
        DmsBulkInstallRuntimeInputs $runtimeInputs,
        DmsBulkInstallTranscriptStore $transcripts,
        DmsInfrastructureRunLauncher $runLauncher,
    ): JsonResponse {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $payload = $request->validate([
            'pmta' => ['required', 'boolean'],
            'pmta_version' => ['required', 'integer', Rule::in([40, 45, 50])],
            'tracking' => ['required', 'boolean'],
            'https' => ['required', 'boolean'],
            'proxy' => ['required', 'boolean'],
            'imap' => ['required', 'boolean'],
            'spf' => ['required', 'boolean'],
            'dkim' => ['required', 'boolean'],
            'dmarc' => ['required', 'boolean'],
            'data' => ['required', 'array', 'min:1', 'max:128'],
            'data.*.ip' => ['required', 'ip'],
            'data.*.type' => ['required', Rule::in(['ipv4', 'ipv6'])],
            'data.*.main' => ['required', 'boolean'],
            'data.*.sub' => ['nullable', 'string', 'max:63'],
            'data.*.domain_id' => ['required', 'uuid'],
        ]);
        $server = $workspace->managedServers()
            ->where('is_active', true)
            ->where('is_installed', true)
            ->whereKey($serverId)
            ->firstOrFail();

        $options = [
            'pmta' => (bool) $payload['pmta'],
            'tracking' => (bool) $payload['tracking'],
            'proxy' => (bool) $payload['proxy'],
            'imap' => (bool) $payload['imap'],
            'spf' => (bool) $payload['spf'],
            'dkim' => (bool) $payload['dkim'],
            'dmarc' => (bool) $payload['dmarc'],
            'https' => (bool) $payload['https'],
        ];
        $componentOptions = [
            'tracking' => $options['tracking'],
            'proxy' => $options['proxy'],
            'imap' => $options['imap'],
            'spf' => $options['spf'],
            'dkim' => $options['dkim'],
            'dmarc' => $options['dmarc'],
            'https' => $options['https'],
        ];

        try {
            $selectionValidator->prepare($workspace, $server, $payload['data']);
            $hostFacts = is_array($server->host_facts) ? $server->host_facts : [];
            $platform = new DmsInstallPlatform;
            if ($platform->installerFamily((string) ($hostFacts['os_pretty_name'] ?? '')) !== 'centos9'
                || $platform->blockers($hostFacts) !== []) {
                return response()->json([
                    'message' => 'Reinstall currently supports the verified AlmaLinux 9 / CentOS 9 x86_64 workflow.',
                    'code' => 'server_reinstall_platform_unsupported',
                ], 422);
            }
            if ($options['pmta']) {
                $readiness = $pmtaDeploymentGate->evaluate($hostFacts, (int) $payload['pmta_version'], $componentOptions, reinstall: true);
                if (! $readiness['installation_authorized']) {
                    return response()->json([
                        'message' => 'PMTA deployment prerequisites are not verified.',
                        'code' => 'pmta_deployment_not_ready',
                        'blockers' => $readiness['blockers'],
                    ], 503);
                }
            }
            $runtimeInputs->resolve($componentOptions, $workspace, $options['pmta'] ? (int) $payload['pmta_version'] : null);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json([
                'message' => 'Reinstall prerequisites are not verified.',
                'code' => 'server_reinstall_inputs_not_ready',
                'blockers' => [$exception->getMessage()],
            ], 503);
        }

        if (! config('infrastructure.reinstall_execution_enabled', false)) {
            return response()->json([
                'message' => 'Server reinstall is disabled by ALPHALINK_REINSTALL_EXECUTION_ENABLED.',
                'code' => 'server_reinstall_runner_unavailable',
            ], 503);
        }

        $runId = $transcripts->createRun((string) $workspace->getKey());
        $requestId = (string) Str::uuid();
        try {
            $transcripts->writeRunPayload((string) $workspace->getKey(), $runId, [
                'kind' => 'reinstall',
                'actor_id' => (string) $request->user()->getKey(),
                'server_id' => (string) $server->getKey(),
                'request_id' => $requestId,
                'payload' => [
                    'data' => $payload['data'],
                    'pmta_version' => (int) $payload['pmta_version'],
                    'options' => $options,
                ],
            ]);
            $runLauncher->launch((string) $workspace->getKey(), $runId);
        } catch (RuntimeException $exception) {
            $transcripts->append((string) $workspace->getKey(), $runId, now()->format('Y-m-d H:i:s').': Error: '.$exception->getMessage());
            $transcripts->setRunStatus((string) $workspace->getKey(), $runId, 'failed', [], $exception->getMessage());

            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'server_reinstall_failed',
                'run_id' => $runId,
                'logs' => $transcripts->read((string) $workspace->getKey(), $runId),
            ], 503);
        }

        return response()->json([
            'data' => 'Reinstallation started.',
            'run_id' => $runId,
            'status' => 'queued',
        ], 202);
    }

    public function reinstallLogs(
        Request $request,
        string $workspaceId,
        string $runId,
        DmsBulkInstallTranscriptStore $transcripts,
    ): JsonResponse {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $log = $transcripts->read((string) $workspace->getKey(), $runId);
        abort_if($log === null, 404);

        $state = $transcripts->readRunStatus((string) $workspace->getKey(), $runId);
        abort_if($state === null, 404);

        return response()->json([
            'data' => $log,
            'status' => $state['status'],
            'results' => $state['results'],
            'error' => $state['error'],
        ]);
    }

    public function bulkInstall(
        Request $request,
        string $workspaceId,
        DmsBulkInstallEligibility $eligibility,
        DmsBulkInstallIspCatalog $ispCatalog,
    ): View {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $candidates = $eligibility->for($workspace);

        return view('console.infrastructure.bulk-install', [
            'workspace' => $workspace,
            'servers' => $candidates['servers'],
            'domains' => $candidates['domains'],
            'ispNames' => $ispCatalog->vmtaNames($workspace),
        ]);
    }

    public function isps(Request $request, string $workspaceId): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $isps = $workspace->isps()->withCount('lists')->orderBy('id')->get();

        if ($request->wantsJson()) {
            return response()->json(['data' => $isps->map(fn (Isp $isp): array => [
                'id' => $isp->getKey(),
                'name' => $isp->name,
                'lists_count' => $isp->lists_count,
                'allowed_users' => $isp->allowed_users ?? [],
                'allowed_sponsors' => $isp->allowed_sponsors ?? [],
                'tracked_countries' => $isp->tracked_countries ?? [],
                'quality_emails' => $isp->quality_emails ?? [],
                'is_active' => $isp->is_active,
                'created_at' => $isp->created_at?->toDateTimeString(),
            ])->values()]);
        }

        return view('console.infrastructure.isps.index', [
            'workspace' => $workspace,
            'isps' => $isps,
        ]);
    }

    public function domains(Request $request, string $workspaceId): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);

        if ($request->wantsJson()) {
            $domains = $workspace->sendingDomains()
                ->whereNotNull('domains_provider_id')
                ->with(['domainProvider', 'history'])
                ->withCount('histories')
                ->when(
                    $request->boolean('only_our'),
                    fn ($query) => $query->where('is_our', true),
                )
                ->get()
                ->map(function (SendingDomain $domain) use ($workspace): array {
                    $provider = $domain->domainProvider;
                    if ($provider?->workspace_id !== null && $provider->workspace_id !== $workspace->getKey()) {
                        $provider = null;
                    }

                    return [
                        'id' => (string) $domain->getKey(),
                        'domain_id' => $domain->provider_domain_id,
                        'provider' => $provider ? ['name' => $provider->name, 'type' => $provider->type] : null,
                        'dns' => $domain->name,
                        'status' => $domain->status,
                        'is_our' => $domain->is_our,
                        'have_who_is' => $domain->have_who_is,
                        'have_renew' => $domain->have_renew,
                        'is_active' => $domain->is_active,
                        'expire_at' => $domain->expire_at?->toDateString(),
                        'history' => $domain->history ? [
                            'is_listed_spamhaus' => $domain->history->is_listed_spamhaus,
                            'is_listed_hostkarma' => $domain->history->is_listed_hostkarma,
                        ] : null,
                        'histories_count' => $domain->histories_count,
                    ];
                });

            return response()->json(['data' => $domains]);
        }

        return view('console.infrastructure.domains.index', [
            'workspace' => $workspace,
        ]);
    }

    public function domainHistory(Request $request, string $workspaceId, string $domainId): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $domain = $workspace->sendingDomains()->whereKey($domainId)->firstOrFail();

        if (! $request->wantsJson()) {
            return view('console.infrastructure.domains.history', [
                'workspace' => $workspace,
                'domain' => $domain,
            ]);
        }

        $history = $domain->histories()->where('workspace_id', $workspace->getKey());
        $recordsTotal = (clone $history)->count();
        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $history->where(function ($query) use ($search): void {
                foreach (['a_record', 'spf', 'dkim', 'dmarc'] as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }
            });
        }

        $recordsFiltered = (clone $history)->count();
        $sortableColumns = ['id', 'a_record', 'spf', 'dkim', 'dmarc', 'is_listed_spamhaus', 'is_listed_hostkarma', 'created_at'];
        $orderIndex = max(0, min($request->integer('order.0.column', 0), count($sortableColumns) - 1));
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';
        $start = max(0, $request->integer('start', 0));
        $length = $request->integer('length', 10);

        if ($length < 1) {
            $length = 10;
        }

        $rows = $history
            ->orderBy($sortableColumns[$orderIndex], $direction)
            ->offset($start)
            ->limit(min($length, 500))
            ->get($sortableColumns)
            ->map(fn (SendingDomainHistory $row): array => [
                'id' => $row->getKey(),
                'a_record' => $row->a_record,
                'spf' => $row->spf,
                'dkim' => $row->dkim,
                'dmarc' => $row->dmarc,
                'is_listed_spamhaus' => $row->is_listed_spamhaus,
                'is_listed_hostkarma' => $row->is_listed_hostkarma,
                'created_at' => $row->created_at?->toDateTimeString(),
            ]);

        return response()->json([
            'draw' => $request->integer('draw', 0),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows,
        ]);
    }

    public function domainRecords(
        Request $request,
        string $workspaceId,
        string $domainId,
        DmsDomainRecordsManager $recordsManager,
        CloudflareDnsApi $cloudflareDnsApi,
    ): View|JsonResponse {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $domain = $workspace->sendingDomains()->whereKey($domainId)->firstOrFail();
        abort_unless($domain->is_our, 400, 'NOT OUR DOMAIN');

        if ($request->isMethod('GET')) {
            $provider = DomainProvider::query()
                ->whereKey($domain->domains_provider_id)
                ->where(fn ($query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $workspace->getKey()))
                ->firstOrFail();

            if ($provider->type === 'cloudflare') {
                try {
                    $records = ['status' => true, 'data' => $cloudflareDnsApi->listDnsRecords($provider, (string) $domain->provider_domain_id)];
                } catch (\Throwable) {
                    $records = ['status' => false, 'data' => 'Could not load Cloudflare DNS records. Check the saved token permissions and try again.'];
                }

                return view('console.infrastructure.domains.cloudflare-records', [
                    'workspace' => $workspace,
                    'domain' => $domain,
                    'records' => $records,
                ]);
            }

            try {
                $records = $recordsManager->load($domain);
            } catch (\Throwable) {
                $records = [
                    'hosts' => ['status' => false, 'data' => 'Could not load domain records'],
                    'nameservers' => ['status' => false, 'data' => 'Could not load domain nameservers'],
                ];
            }

            return view('console.infrastructure.domains.records', [
                'workspace' => $workspace,
                'domain' => $domain,
                ...$records,
            ]);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:hosts,nameservers,reset'],
            'data' => ['required', 'array', 'min:1'],
        ]);

        try {
            $updated = $recordsManager->update($domain, $validated['type'], $validated['data']);
        } catch (\Throwable) {
            $updated = false;
        }

        if ($updated) {
            $this->auditDomainChange(
                $workspace,
                $request->user(),
                $domain,
                $request,
                'domain.records.'.$validated['type'],
                ['record_count' => count($validated['data'])],
            );
        }

        return response()->json(['data' => $updated ? 'Updated Successfully' : 'Failed'], $updated ? 200 : 400);
    }

    public function createCloudflareDomainRecord(
        Request $request,
        string $workspaceId,
        string $domainId,
        CloudflareDnsApi $cloudflareDnsApi,
    ): JsonResponse {
        [$workspace, $domain, $provider] = $this->cloudflareDomainFor($request, $workspaceId, $domainId);
        $payload = $this->cloudflareDnsRecordPayload($request, $domain);

        try {
            $record = $cloudflareDnsApi->createDnsRecord($provider, (string) $domain->provider_domain_id, $payload);
        } catch (CloudflareDnsApiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\Throwable) {
            return response()->json(['message' => 'Cloudflare could not create this DNS record. Check the record fields and token permissions.'], 422);
        }

        $this->auditDomainChange($workspace, $request->user(), $domain, $request, 'domain.cloudflare_record.created', [
            'record_id' => (string) ($record['id'] ?? ''),
            'record_type' => (string) ($record['type'] ?? $payload['type']),
        ]);

        return response()->json(['data' => 'DNS record created', 'record' => $record]);
    }

    public function updateCloudflareDomainRecord(
        Request $request,
        string $workspaceId,
        string $domainId,
        string $recordId,
        CloudflareDnsApi $cloudflareDnsApi,
    ): JsonResponse {
        [$workspace, $domain, $provider] = $this->cloudflareDomainFor($request, $workspaceId, $domainId);
        $payload = $this->cloudflareDnsRecordPayload($request, $domain);

        try {
            $record = $cloudflareDnsApi->updateDnsRecord($provider, (string) $domain->provider_domain_id, $recordId, $payload);
        } catch (CloudflareDnsApiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\Throwable) {
            return response()->json(['message' => 'Cloudflare could not update this DNS record. Check the record fields and token permissions.'], 422);
        }

        $this->auditDomainChange($workspace, $request->user(), $domain, $request, 'domain.cloudflare_record.updated', [
            'record_id' => $recordId,
            'record_type' => (string) ($record['type'] ?? $payload['type']),
        ]);

        return response()->json(['data' => 'DNS record updated', 'record' => $record]);
    }

    public function deleteCloudflareDomainRecord(
        Request $request,
        string $workspaceId,
        string $domainId,
        string $recordId,
        CloudflareDnsApi $cloudflareDnsApi,
    ): JsonResponse {
        [$workspace, $domain, $provider] = $this->cloudflareDomainFor($request, $workspaceId, $domainId);

        try {
            $cloudflareDnsApi->deleteDnsRecord($provider, (string) $domain->provider_domain_id, $recordId);
        } catch (CloudflareDnsApiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\Throwable) {
            return response()->json(['message' => 'Cloudflare could not delete this DNS record. Check the token permissions and try again.'], 422);
        }

        $this->auditDomainChange($workspace, $request->user(), $domain, $request, 'domain.cloudflare_record.deleted', [
            'record_id' => $recordId,
        ]);

        return response()->json(['data' => 'DNS record deleted']);
    }

    public function refreshDomain(
        Request $request,
        string $workspaceId,
        string $domainId,
        DmsDomainProviderRefresh $refresh,
    ): JsonResponse {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $domain = $workspace->sendingDomains()->whereKey($domainId)->firstOrFail();
        $result = $refresh->refresh($workspace, $request->user(), $domain, (string) $request->ip());

        if ($result) {
            $this->auditDomainChange($workspace, $request->user(), $domain, $request, 'domain.dms_refreshed');
        }

        return response()->json(['data' => $result ? 'Domain updated' : 'Failed'], $result ? 200 : 400);
    }

    public function setDomainActive(Request $request, string $workspaceId, string $domainId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $domain = $workspace->sendingDomains()->whereKey($domainId)->firstOrFail();
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $result = $domain->fill(['is_active' => (bool) $data['status']])->save();

        if ($result) {
            $this->auditDomainChange($workspace, $request->user(), $domain, $request, 'domain.dms_active_changed', [
                'is_active' => (bool) $data['status'],
            ]);
        }

        return response()->json(['data' => $result ? 'Changed successfully' : 'Failed'], $result ? 200 : 400);
    }

    public function setDomainStatus(Request $request, string $workspaceId, string $domainId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $domain = $workspace->sendingDomains()->whereKey($domainId)->firstOrFail();
        $data = $request->validate(['status' => ['required', 'string', Rule::in(['new', 'free', 'used', 'redirect'])]]);
        $result = $domain->fill(['status' => $data['status']])->save();

        if ($result) {
            $this->auditDomainChange($workspace, $request->user(), $domain, $request, 'domain.dms_status_changed', [
                'status' => $data['status'],
            ]);
        }

        return response()->json(['data' => $result ? 'Changed successfully' : 'Failed'], $result ? 200 : 400);
    }

    public function deleteDomains(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid'],
        ]);
        $domains = $workspace->sendingDomains()->whereKey($data['ids'])->get();
        $removed = $domains->each(fn (SendingDomain $domain) => $domain->delete());

        foreach ($removed as $domain) {
            $this->auditDomainChange($workspace, $request->user(), $domain, $request, 'domain.dms_soft_deleted');
        }

        $result = $removed->isNotEmpty();

        return response()->json(['data' => $result ? 'Removed Successfully' : 'Failed'], $result ? 200 : 400);
    }

    public function createIsp(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);

        return view('console.infrastructure.isps.form', [
            'workspace' => $workspace,
            'isp' => null,
            'countries' => config('dms_install_countries'),
        ]);
    }

    public function editIsp(Request $request, string $workspaceId, int $ispId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $isp = $workspace->isps()->whereKey($ispId)->firstOrFail();

        return view('console.infrastructure.isps.form', [
            'workspace' => $workspace,
            'isp' => $isp,
            'countries' => config('dms_install_countries'),
        ]);
    }

    public function storeIsp(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $data = $this->validateIsp($request);
        $isp = $workspace->isps()->create([
            'name' => $data['name'],
            'tracked_countries' => $data['tracked_countries'] ?? [],
            'quality_emails' => $data['quality_emails'] ?? [],
            'allowed_users' => [],
            'allowed_sponsors' => [],
            'is_active' => true,
        ]);

        $this->auditIspChange($workspace, $request, 'isp.created', $isp);

        return response()->json(['data' => 'Isp created']);
    }

    public function updateIsp(Request $request, string $workspaceId, int $ispId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $isp = $workspace->isps()->whereKey($ispId)->firstOrFail();
        $data = $this->validateIsp($request);
        $isp->fill([
            'name' => $data['name'],
            'tracked_countries' => $data['tracked_countries'] ?? [],
            'quality_emails' => $data['quality_emails'] ?? [],
        ])->save();

        $this->auditIspChange($workspace, $request, 'isp.updated', $isp);

        return response()->json(['data' => 'Isp updated']);
    }

    public function setIspStatus(Request $request, string $workspaceId, int $ispId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $isp = $workspace->isps()->whereKey($ispId)->firstOrFail();
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $isp->forceFill(['is_active' => (bool) $data['status']])->save();
        $this->auditIspChange($workspace, $request, 'isp.status_changed', $isp, ['is_active' => $isp->is_active]);

        return response()->json(['data' => 'Changed successfully']);
    }

    public function ispUsers(Request $request, string $workspaceId, int $ispId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $isp = $workspace->isps()->whereKey($ispId)->firstOrFail();
        $users = $workspace->users()
            ->wherePivot('role', 'operator')
            ->orderBy('users.id')
            ->get(['users.id', 'users.name', 'users.username']);

        return view('console.infrastructure.isps.assignment', [
            'workspace' => $workspace,
            'isp' => $isp,
            'items' => $users,
            'selectedIds' => $isp->allowed_users ?? [],
            'type' => 'users',
        ]);
    }

    public function ispSponsors(Request $request, string $workspaceId, int $ispId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $isp = $workspace->isps()->whereKey($ispId)->firstOrFail();
        $sponsors = $workspace->sponsors()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'affiliate_id']);

        return view('console.infrastructure.isps.assignment', [
            'workspace' => $workspace,
            'isp' => $isp,
            'items' => $sponsors,
            'selectedIds' => $isp->allowed_sponsors ?? [],
            'type' => 'sponsors',
        ]);
    }

    public function updateIspUsers(Request $request, string $workspaceId, int $ispId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $isp = $workspace->isps()->whereKey($ispId)->firstOrFail();
        $data = $request->validate([
            'users' => ['nullable', 'array'],
            'users.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('workspace_user', 'user_id')
                    ->where('workspace_id', $workspace->getKey())
                    ->where('role', 'operator'),
            ],
        ]);
        $userIds = array_values(array_unique(array_map('intval', $data['users'] ?? [])));
        DB::transaction(function () use ($workspace, $request, $isp, $userIds): void {
            $isp = $workspace->isps()->whereKey($isp->getKey())->lockForUpdate()->firstOrFail();
            $lists = $isp->lists()->where('workspace_id', $workspace->getKey())->get();
            $isp->forceFill(['allowed_users' => $userIds])->save();
            foreach ($lists as $list) {
                $list->forceFill(['allowed_users' => $userIds])->save();
            }
            $this->auditIspChange($workspace, $request, 'isp.allowed_users_updated', $isp, [
                'allowed_user_ids' => $userIds,
                'lists_updated' => $lists->count(),
            ]);
        });

        return response()->json(['data' => 'Updated successfully']);
    }

    public function updateIspSponsors(Request $request, string $workspaceId, int $ispId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $isp = $workspace->isps()->whereKey($ispId)->firstOrFail();
        $data = $request->validate([
            'sponsors' => ['nullable', 'array'],
            'sponsors.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('workspace_sponsors', 'id')
                    ->where('workspace_id', $workspace->getKey())
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
        ]);
        $sponsorIds = array_values(array_unique(array_map('intval', $data['sponsors'] ?? [])));
        DB::transaction(function () use ($workspace, $request, $isp, $sponsorIds): void {
            $isp = $workspace->isps()->whereKey($isp->getKey())->lockForUpdate()->firstOrFail();
            $lists = $isp->lists()->where('workspace_id', $workspace->getKey())->get();
            $isp->forceFill(['allowed_sponsors' => $sponsorIds])->save();
            foreach ($lists as $list) {
                $list->forceFill(['allowed_sponsors' => $sponsorIds])->save();
            }
            $this->auditIspChange($workspace, $request, 'isp.allowed_sponsors_updated', $isp, [
                'allowed_sponsor_ids' => $sponsorIds,
                'lists_updated' => $lists->count(),
            ]);
        });

        return response()->json(['data' => 'Updated successfully']);
    }

    private function validateIsp(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'alpha_dash', 'min:3', 'max:255'],
            'tracked_countries' => ['nullable', 'array', 'min:1'],
            'quality_emails' => ['nullable', 'array', 'min:1'],
        ]);
    }

    private function auditIspChange(Workspace $workspace, Request $request, string $action, Isp $isp, array $properties = []): void
    {
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => $action,
            'subject_type' => 'isp',
            'subject_id' => (string) $isp->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => $properties,
            'occurred_at' => now(),
        ]);
    }

    public function domainProvidersIndex(Request $request, string $workspaceId): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);

        if ($request->wantsJson()) {
            $providers = DomainProvider::query()
                ->where('workspace_id', $workspace->getKey())
                ->withCount('sendingDomains')
                ->orderByDesc('id')
                ->get(['id', 'name', 'type', 'username', 'is_active', 'created_at']);

            return response()->json(['data' => $providers]);
        }

        return view('console.infrastructure.domain-providers.index', ['workspace' => $workspace]);
    }

    public function createDmsDomainProvider(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);

        return view('console.infrastructure.domain-provider-create', [
            'workspace' => $workspace,
            'types' => DomainProvider::TYPES,
            'provider' => null,
        ]);
    }

    public function editDmsDomainProvider(Request $request, string $workspaceId, int $providerId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $provider = DomainProvider::query()->where('workspace_id', $workspace->getKey())->findOrFail($providerId);

        return view('console.infrastructure.domain-provider-create', [
            'workspace' => $workspace,
            'types' => DomainProvider::TYPES,
            'provider' => $provider,
        ]);
    }

    public function createDmsInstallDomains(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $providers = DomainProvider::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $workspace->getKey()))
            ->latest()
            ->get(['id', 'name']);

        return view('console.infrastructure.domain-import', [
            'workspace' => $workspace,
            'providers' => $providers,
        ]);
    }

    public function editInstallUsername(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);

        return view('console.infrastructure.install-username', [
            'workspace' => $workspace,
            'user' => $request->user(),
        ]);
    }

    public function editInstallSettings(Request $request, string $workspaceId): RedirectResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);

        return redirect(route('console.app-settings.index', $workspace->getKey()).'#settings');
    }

    public function loadPmtaDomainSettings(Request $request, string $workspaceId, DmsBulkInstallPrivateAssetReader $assets): JsonResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $data = $request->validate([
            'pmta_version' => ['required', 'integer', Rule::in([40, 45, 50])],
        ]);
        $version = (int) $data['pmta_version'];
        $configs = $workspace->infrastructureSettings?->pmta_domain_configs;
        $contents = is_array($configs) ? ($configs[(string) $version] ?? null) : null;

        if (! is_string($contents)) {
            $root = config('infrastructure.install_assets_directory');
            if (is_string($root) && $root !== '') {
                try {
                    $contents = $assets->read($root, "install/pmta_{$version}/pmta_domains", false);
                } catch (RuntimeException) {
                    $contents = null;
                }
            }
        }

        return response()->json(['data' => $contents ?? '']);
    }

    public function updatePmtaDomainSettings(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $data = $request->validate([
            'pmta_version' => ['required', 'integer', Rule::in([40, 45, 50])],
            'data' => ['required', 'string', 'max:262144'],
        ]);
        $settings = $workspace->infrastructureSettings()->firstOrNew();
        $configs = $settings->pmta_domain_configs ?? [];
        $version = (string) (int) $data['pmta_version'];
        $configs[$version] = $data['data'];
        $settings->workspace_id = $workspace->getKey();
        $settings->pmta_domain_configs = $configs;
        $settings->save();

        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => 'infrastructure.pmta_domain_settings_updated',
            'subject_type' => 'workspace_pmta_domain_settings',
            'subject_id' => (string) $settings->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => ['pmta_version' => (int) $version, 'bytes' => strlen($data['data'])],
            'occurred_at' => now(),
        ]);

        return response()->json(['data' => 'Edited Successfully']);
    }

    public function bulkInstallLogs(
        Request $request,
        string $workspaceId,
        string $runId,
        DmsBulkInstallTranscriptStore $transcripts,
    ): JsonResponse {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $transcript = is_numeric($runId)
            ? $transcripts->readByLogKey((string) $workspace->getKey(), $runId)
            : $transcripts->read((string) $workspace->getKey(), $runId);
        abort_if($transcript === null, 404);

        $state = is_numeric($runId)
            ? $transcripts->readRunStatusByLogKey((string) $workspace->getKey(), $runId)
            : $transcripts->readRunStatus((string) $workspace->getKey(), $runId);
        abort_if($state === null, 404);

        return response()->json([
            'data' => $transcript,
            'status' => $state['status'],
            'results' => $state['results'],
            'error' => $state['error'],
        ]);
    }

    public function startBulkInstall(
        Request $request,
        string $workspaceId,
        DmsBulkInstallPayload $payloadNormalizer,
        DmsBulkInstallEligibility $eligibility,
        DmsPmtaDeploymentGate $pmtaDeploymentGate,
        DmsBulkInstallRuntimeInputs $runtimeInputs,
        DmsBulkInstallTranscriptStore $transcripts,
        DmsInfrastructureRunLauncher $runLauncher,
        CloudflareDnsApi $cloudflareDnsApi,
    ): JsonResponse {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $payload = $payloadNormalizer->validateAndNormalize($request->all());
        $singleInstallRequest = $request->routeIs('console.infrastructure.install.runs.store')
            || $request->attributes->get('single_install_request') === true;

        $selectedServers = [];
        $selectedDomains = [];

        foreach ($payload['data'] as $index => $row) {
            if (isset($row['ip_assignments']) && ! $singleInstallRequest) {
                throw ValidationException::withMessages([
                    "data.{$index}.ip_assignments" => 'Per-IP assignments are available on the single-server Install page only.',
                ]);
            }

            $server = $singleInstallRequest
                ? $eligibility->singleInstallServerForSelection($workspace, $row['id'])
                : $eligibility->serverForSelection($request->user(), $workspace, $row['id']);

            if ($server === null) {
                throw ValidationException::withMessages([
                    "data.{$index}.id" => $singleInstallRequest
                        ? 'The selected server is no longer eligible for installation.'
                        : 'The selected server is no longer eligible for bulk installation.',
                ]);
            }

            if (isset($row['ip_assignments'])) {
                if (count(array_filter($row['ip_assignments'], static fn (array $assignment): bool => $assignment['main'])) > 1) {
                    throw ValidationException::withMessages([
                        "data.{$index}.ip_assignments" => 'Select only one main IP address.',
                    ]);
                }

                foreach ($row['ip_assignments'] as $assignmentIndex => $assignment) {
                    $assignmentDomain = $eligibility->singleInstallDomainForSelection($workspace, $assignment['domain_id']);
                    if ($assignmentDomain === null) {
                        throw ValidationException::withMessages([
                            "data.{$index}.ip_assignments.{$assignmentIndex}.domain_id" => 'The selected domain is no longer eligible for installation.',
                        ]);
                    }
                    $selectedDomains[(string) $assignmentDomain->getKey()] = $assignmentDomain;
                }

                $effectiveDomainId = collect($row['ip_assignments'])->firstWhere('main', true)['domain_id']
                    ?? $row['ip_assignments'][0]['domain_id'];
                if ((string) $row['domain'] !== (string) $effectiveDomainId) {
                    throw ValidationException::withMessages([
                        "data.{$index}.domain" => 'Select the domain assigned to the main IP, or the first IP when no main IP is selected.',
                    ]);
                }
                $domain = $eligibility->singleInstallDomainForSelection($workspace, $effectiveDomainId);
            } else {
                $domain = $eligibility->domainForSelection($workspace, $row['domain']);
            }

            if ($domain === null) {
                throw ValidationException::withMessages([
                    "data.{$index}.domain" => 'The selected domain is no longer eligible for bulk installation.',
                ]);
            }

            $selectedServers[(string) $server->getKey()] = $server;
            $selectedDomains[(string) $domain->getKey()] = $domain;
        }

        foreach ($selectedDomains as $domain) {
            $provider = $domain->domainProvider;
            if ($provider?->type !== 'cloudflare') {
                continue;
            }

            try {
                $cloudflareDnsApi->zoneIdForExactDomain($provider, (string) $domain->name, $domain->provider_domain_id);
            } catch (CloudflareDnsApiException $exception) {
                return response()->json([
                    'message' => 'The selected domain DNS provider is not ready for installation.',
                    'code' => 'install_domain_dns_not_ready',
                    'blockers' => [$exception->getMessage()],
                ], 503);
            }
        }

        // Bulk Install has no separate LOAD IPS step. Match the DMS install
        // flow by verifying missing host facts before evaluating platform and
        // PMTA readiness; otherwise an unprobed server is incorrectly shown
        // as an unsupported OS/architecture. This preflight is read-only.
        $preflightFailures = [];
        foreach ($selectedServers as $serverId => $server) {
            $hostFacts = is_array($server->host_facts) ? $server->host_facts : [];
            $preflightRequired = $server->state !== 'verified'
                || blank($server->expected_host_fingerprint)
                || blank($server->observed_host_fingerprint)
                || blank($hostFacts['architecture'] ?? null)
                || blank($hostFacts['os_pretty_name'] ?? null)
                || (($hostFacts['pmta_binary_present'] ?? null) === true
                    && blank($hostFacts['pmta_reviewed_identity'] ?? null));

            if (! $preflightRequired) {
                continue;
            }

            try {
                $selectedServers[$serverId] = $this->manager->verifyServerHost(
                    $workspace,
                    $request->user(),
                    $server,
                    $request->attributes->get('request_id'),
                );
            } catch (HostProbeException $exception) {
                $preflightFailures[] = [
                    'server_id' => $serverId,
                    'blockers' => [$exception->getMessage()],
                ];
            }
        }

        if ($preflightFailures !== []) {
            return response()->json([
                'message' => 'Selected server preflight could not be verified.',
                'code' => 'server_preflight_not_verified',
                'servers' => $preflightFailures,
            ], 422);
        }

        $installOptions = [
            'tracking' => $payload['tracking'],
            'proxy' => $payload['proxy'],
            'imap' => $payload['imap'],
            'spf' => $payload['spf'],
            'dkim' => $payload['dkim'],
            'dmarc' => $payload['dmarc'],
            'https' => $payload['https'],
        ];
        $deploymentBlockers = [];

        foreach ($selectedServers as $server) {
            $readiness = $pmtaDeploymentGate->evaluate(
                is_array($server->host_facts) ? $server->host_facts : [],
                $payload['pmta_version'],
                $installOptions,
            );

            if (! $readiness['installation_authorized']) {
                $deploymentBlockers[] = [
                    'server_id' => (string) $server->getKey(),
                    'blockers' => $readiness['blockers'],
                ];
            }
        }

        if ($deploymentBlockers !== []) {
            return response()->json([
                'message' => 'PMTA deployment prerequisites are not verified.',
                'code' => 'pmta_deployment_not_ready',
                'servers' => $deploymentBlockers,
            ], 503);
        }

        try {
            $runtimeInputs->resolve($installOptions, $workspace, $payload['pmta_version']);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => 'Required AlphaLink installation settings or selected assets are not ready.',
                'code' => $singleInstallRequest ? 'single_install_inputs_not_ready' : 'bulk_install_inputs_not_ready',
                'blockers' => [$exception->getMessage()],
            ], 503);
        }

        $executionEnabled = $singleInstallRequest
            ? config('infrastructure.single_install_execution_enabled', false)
            : config('infrastructure.bulk_install_execution_enabled', false);
        if (! $executionEnabled) {
            return response()->json([
                'message' => $singleInstallRequest
                    ? 'Single-server installation execution is disabled by ALPHALINK_SINGLE_INSTALL_EXECUTION_ENABLED.'
                    : 'Bulk installation execution is disabled by ALPHALINK_INSTALL_EXECUTION_ENABLED.',
                'code' => $singleInstallRequest ? 'single_install_runner_unavailable' : 'bulk_install_runner_unavailable',
            ], 503);
        }

        if (! app()->bound(DmsBulkInstallRowRunner::class)) {
            return response()->json([
                'message' => $singleInstallRequest
                    ? 'The single-server installation runner is not registered in this app environment.'
                    : 'The bulk-install runner is not registered in this app environment.',
                'code' => $singleInstallRequest ? 'single_install_runner_unavailable' : 'bulk_install_runner_unavailable',
            ], 503);
        }

        $runId = $transcripts->createRun((string) $workspace->getKey(), $payload['key']);
        try {
            $transcripts->writeRunPayload((string) $workspace->getKey(), $runId, [
                'kind' => $singleInstallRequest ? 'single_install' : 'bulk_install',
                'actor_id' => (string) $request->user()->getKey(),
                'payload' => $payload,
            ]);
            $runLauncher->launch((string) $workspace->getKey(), $runId);
        } catch (RuntimeException $exception) {
            $transcripts->append((string) $workspace->getKey(), $runId, now()->format('[d/m/Y-H:i:s]').' Error: '.$exception->getMessage());
            $transcripts->setRunStatus((string) $workspace->getKey(), $runId, 'failed', [], $exception->getMessage());

            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $singleInstallRequest ? 'single_install_worker_unavailable' : 'bulk_install_worker_unavailable',
                'run_id' => $runId,
            ], 503);
        }

        return response()->json([
            'data' => 'Installation queued.',
            'run_id' => $runId,
            'status' => 'queued',
        ], 202);
    }

    public function startSingleInstall(
        Request $request,
        string $workspaceId,
        DmsBulkInstallPayload $payloadNormalizer,
        DmsBulkInstallEligibility $eligibility,
        DmsPmtaDeploymentGate $pmtaDeploymentGate,
        DmsBulkInstallRuntimeInputs $runtimeInputs,
        DmsBulkInstallTranscriptStore $transcripts,
        DmsInfrastructureRunLauncher $runLauncher,
        CloudflareDnsApi $cloudflareDnsApi,
    ): JsonResponse {
        if (! is_array($request->input('data')) || count($request->input('data')) !== 1) {
            throw ValidationException::withMessages([
                'data' => 'Install accepts exactly one server. Use Bulk Install for multiple servers.',
            ]);
        }
        if (! is_array($request->input('data.0.ip_assignments')) || $request->input('data.0.ip_assignments') === []) {
            throw ValidationException::withMessages([
                'data.0.ip_assignments' => 'Load and review the server IP assignments before installing.',
            ]);
        }
        $request->attributes->set('single_install_request', true);

        return $this->startBulkInstall(
            $request,
            $workspaceId,
            $payloadNormalizer,
            $eligibility,
            $pmtaDeploymentGate,
            $runtimeInputs,
            $transcripts,
            $runLauncher,
            $cloudflareDnsApi,
        );
    }

    public function createServer(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $dmsProviderOrder = ['Digital', 'digital', 'Ovh', 'Linode', 'linode', 'ikoula', 'vultr', 'Azure', 'reg'];
        $providerOrder = array_flip($dmsProviderOrder);
        $providers = ServerProvider::query()
            ->where(fn ($query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $workspace->getKey()))
            ->get(['id', 'name'])
            ->sortBy(fn (ServerProvider $provider) => $providerOrder[$provider->name] ?? count($dmsProviderOrder))
            ->values();

        return view('console.infrastructure.server-create', [
            'workspace' => $workspace,
            'providers' => $providers,
            'defaultExpireAt' => now()->addDays(30)->format('Y-m-d'),
        ]);
    }

    public function editServer(Request $request, string $workspaceId, string $serverId): View
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        $providers = ServerProvider::query()
            ->where(fn ($query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $workspace->getKey()))
            ->orderBy('name')
            ->get(['id', 'name']);
        $username = trim((string) $request->user()->username);
        $isBulkInstallEligible = $username !== '' && $workspace->managedServers()
            ->whereKey($server->getKey())
            ->where('name', 'like', '%_'.mb_strtoupper($username).'%')
            ->exists();
        $suggestedName = $server->name;
        if ($username !== '' && ! $isBulkInstallEligible) {
            $suggestedName = mb_substr(rtrim($server->name).'_'.mb_strtoupper($username), 0, 120);
        }

        return view('console.infrastructure.server-edit', [
            'workspace' => $workspace,
            'server' => $server,
            'providers' => $providers,
            'username' => $username,
            'isBulkInstallEligible' => $isBulkInstallEligible,
            'suggestedName' => $suggestedName,
            'serversUrl' => route('console.infrastructure.servers.index', $workspace->id),
        ]);
    }

    public function updateServer(Request $request, string $workspaceId, string $serverId): JsonResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        $data = $request->validate([
            'servers_provider_id' => ['required', 'integer', Rule::exists('server_providers', 'id')->where(fn ($query) => $query
                ->whereNull('deleted_at')
                ->where(fn ($scope) => $scope->whereNull('workspace_id')->orWhere('workspace_id', $workspace->getKey())))],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'ip' => ['required', 'ip', Rule::unique('managed_servers', 'ip_address')->where('workspace_id', $workspace->getKey())->ignore($server->getKey(), 'id')],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:32'],
            'login_type' => ['required', 'string', Rule::in(['password', 'ssh_key'])],
            'password' => ['nullable', 'string', 'min:4'],
            'expire_at' => ['required', 'date'],
        ]);

        $this->manager->updateManagedServer(
            $workspace,
            $request->user(),
            $server,
            $data,
            $request->attributes->get('request_id'),
        );

        return response()->json(['data' => 'Server updated']);
    }

    public function testExistingServerConnection(
        Request $request,
        string $workspaceId,
        string $serverId,
        DmsInstallerServerCredentialResolver $credentialResolver,
    ): JsonResponse {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        $data = $request->validate([
            'ip' => ['required', 'ip'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:32'],
            'login_type' => ['required', 'string', Rule::in(['password', 'ssh_key'])],
            'password' => ['nullable', 'string', 'min:4'],
        ]);
        $secret = $data['password'] ?? '';

        if ($secret === '') {
            $savedCredential = $credentialResolver->resolve($server);
            if ($savedCredential['type'] !== $data['login_type']) {
                throw ValidationException::withMessages(['password' => 'Enter a credential that matches the selected password type.']);
            }
            $secret = $savedCredential['secret'];
        }

        $results = $this->manager->testServerConnections(
            [$data['ip']],
            (int) $data['port'],
            $data['username'],
            $data['login_type'],
            $secret,
        );

        return response()->json(['data' => $results[0]['message'] ?? 'Connection test failed.', 'results' => $results]);
    }

    public function serversIndex(Request $request, string $workspaceId): View|JsonResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $canManageServers = true;
        $canOperateServers = true;
        $returned = $request->boolean('returned');
        $serversQuery = $workspace->managedServers();
        if ($returned) {
            $serversQuery->onlyTrashed();
        }

        $servers = $serversQuery
            ->when($request->has('provider_id'), fn ($query) => $query->where('servers_provider_id', $request->input('provider_id')))
            ->with(['serverProvider:id,name', 'allowedUsers:id', 'history'])
            ->withCount('ips')
            ->orderBy('expire_at')
            ->orderByDesc('id')
            ->get();

        if ($request->wantsJson()) {
            return response()->json([
                'data' => $servers->map(fn ($server) => [
                    'id' => $server->getKey(),
                    'name' => $server->name,
                    'hostname' => null,
                    'ip' => $server->ip_address,
                    'country' => $server->country,
                    'is_installed' => $server->is_installed,
                    'is_locked' => $server->is_locked,
                    'allowed_users' => $server->allowedUsers->pluck('id')->values(),
                    'can_manage' => $canManageServers,
                    'can_console' => $canManageServers,
                    'can_operate' => $canOperateServers,
                    'can_return' => $canOperateServers && ! $server->is_locked,
                    'can_restore' => $canManageServers,
                    'is_active' => $server->is_active,
                    'expire_at' => $server->expire_at?->format('Y-m-d'),
                    'deleted_at' => $server->deleted_at?->toISOString(),
                    'servers_provider_id' => $server->servers_provider_id,
                    'provider' => [
                        'id' => $server->serverProvider?->getKey(),
                        'name' => $server->serverProvider?->name,
                    ],
                    'history' => $returned || ! $server->history ? null : [
                        'is_ssh_down' => $server->history->is_ssh_down,
                        'is_apache_down' => $server->history->is_apache_down,
                        'is_pmta_down' => $server->history->is_pmta_down,
                    ],
                    'ips_count' => $server->ips_count,
                ])->values(),
            ]);
        }

        return view('console.infrastructure.servers', [
            'workspace' => $workspace,
            'serversDataUrl' => route('console.infrastructure.servers.index', ['workspace' => $workspace->id] + $request->only('provider_id')),
            'createUrl' => $canManageServers ? route('console.infrastructure.servers.create', $workspace->id) : null,
            'editUrlTemplate' => route('console.infrastructure.servers.edit', [$workspace->id, '__SERVER__']),
            'canManageServers' => $canManageServers,
            'canReturnServers' => $canOperateServers,
        ]);
    }

    public function serverHistory(Request $request, string $workspaceId, string $serverId): View|JsonResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();

        if (! $request->wantsJson()) {
            return view('console.infrastructure.server-history', [
                'workspace' => $workspace,
                'server' => $server,
                'historyDataUrl' => route('console.infrastructure.servers.history', [$workspace->id, $server->id]),
            ]);
        }

        $query = $server->histories();
        $recordsTotal = (clone $query)->count();
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn ($builder) => $builder
                ->where('id', 'like', "%{$search}%")
                ->orWhere('created_at', 'like', "%{$search}%"));
        }
        $recordsFiltered = (clone $query)->count();

        $sortColumns = [0 => 'id', 1 => 'is_ssh_down', 2 => 'is_apache_down', 3 => 'is_pmta_down', 4 => 'created_at'];
        $orderColumn = (int) $request->input('order.0.column', 0);
        $direction = strtolower((string) $request->input('order.0.dir', 'desc'));
        $query->orderBy($sortColumns[$orderColumn] ?? 'id', $direction === 'desc' ? 'desc' : 'asc');

        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);
        $limit = $length === -1 ? 500 : min(max($length, 1), 100);
        $data = $query->offset($start)->limit($limit)->get([
            'id', 'is_ssh_down', 'is_apache_down', 'is_pmta_down', 'created_at',
        ])->map(fn ($history) => [
            'id' => $history->id,
            'is_ssh_down' => $history->is_ssh_down,
            'is_apache_down' => $history->is_apache_down,
            'is_pmta_down' => $history->is_pmta_down,
            'created_at' => $history->created_at,
        ])->values();

        return response()->json([
            'draw' => max(0, (int) $request->input('draw', 0)),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function serverConsole(
        Request $request,
        string $workspaceId,
        string $serverId,
        DmsServerConsole $console,
    ): View|JsonResponse {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();

        if (! $request->isMethod('POST')) {
            return view('console.infrastructure.server-console', [
                'workspace' => $workspace,
                'server' => $server,
                'consoleUrl' => route('console.infrastructure.servers.console', [$workspace->id, $server->id]),
            ]);
        }

        $data = $request->validate(['cmd' => ['required', 'string']]);

        try {
            return response()->json($console->execute($server, $data['cmd']));
        } catch (RuntimeException $exception) {
            // DMS returns execution errors as a JSON string with HTTP 200.
            return response()->json($exception->getMessage());
        } catch (\Throwable) {
            return response()->json('Console command failed.');
        }
    }

    public function myIps(Request $request, string $workspaceId): View|JsonResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $serverId = $request->query('server_id');
        $ips = $this->myIpsQuery($workspace, is_string($serverId) ? $serverId : null);

        if (! $request->wantsJson()) {
            return view('console.infrastructure.my-ips', [
                'workspace' => $workspace,
                'ipsUrl' => route('console.infrastructure.my-ips', $workspace->id, false),
                'exportUrl' => route('console.infrastructure.my-ips.export', array_filter([
                    'workspace' => $workspace->id,
                    'server_id' => is_string($serverId) ? $serverId : null,
                ])),
            ]);
        }

        $rows = $ips->get([
            'id', 'workspace_id', 'managed_server_id', 'sending_domain_id', 'ip', 'type', 'dns',
        ])->map(fn (ManagedServerIp $ip): array => [
            'id' => $ip->getKey(),
            'ip' => $ip->ip,
            'type' => $ip->type,
            'dns' => $ip->dns,
            'server' => [
                'id' => $ip->server?->getKey(),
                'name' => $ip->server?->name,
                'ip' => $ip->server?->ip_address,
            ],
            'history' => $ip->history ? [
                'ptr' => $ip->history->ptr,
                'sender_score' => $ip->history->sender_score,
                'ip_listed_spamhaus' => $ip->history->ip_listed_spamhaus,
                'ip_listed_spamcop' => $ip->history->ip_listed_spamcop,
                'ip_listed_barracuda' => $ip->history->ip_listed_barracuda,
                'domain_listed_hostkarma' => $ip->history->domain_listed_hostkarma,
                'domain_listed_spamhaus_dbl' => $ip->history->domain_listed_spamhaus_dbl,
                'domain_listed_surbl' => $ip->history->domain_listed_surbl,
                'created_at' => $ip->history->created_at?->toDateTimeString(),
            ] : null,
        ])->values();

        return response()->json(['data' => $rows]);
    }

    public function exportMyIps(Request $request, string $workspaceId): StreamedResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $serverId = $request->query('server_id');
        $ips = $this->myIpsQuery($workspace, is_string($serverId) ? $serverId : null)->get();

        return response()->streamDownload(function () use ($ips): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                return;
            }

            fputcsv($stream, [
                'server', 'ssh down', 'apache down', 'pmta down', 'ip', 'ip type', 'ip domain', 'ip ptr', 'ip score',
                'ip listed barracuda', 'ip listed spamcop', 'ip listed spamhaus zen',
                'domain listed hostkarma', 'domain listed spamhaus dbl', 'domain listed surbl', 'checked at',
            ]);

            foreach ($ips as $ip) {
                $history = $ip->history;
                $serverHistory = $ip->server?->history;
                fputcsv($stream, [
                    ($ip->server?->name ?? '').($ip->server?->getKey() ?? ''),
                    $serverHistory?->is_ssh_down ? 'YES' : 'NO',
                    $serverHistory?->is_apache_down ? 'YES' : 'NO',
                    $serverHistory?->is_pmta_down ? 'YES' : 'NO',
                    $ip->ip,
                    $ip->type,
                    $ip->dns,
                    $history?->ptr ?? 'NA',
                    $history?->sender_score ?? 'NA',
                    $history?->ip_listed_barracuda ? 'YES' : 'NO',
                    $history?->ip_listed_spamcop ? 'YES' : 'NO',
                    $history?->ip_listed_spamhaus ? 'YES' : 'NO',
                    $history?->domain_listed_hostkarma ? 'YES' : 'NO',
                    $history?->domain_listed_spamhaus_dbl ? 'YES' : 'NO',
                    $history?->domain_listed_surbl ? 'YES' : 'NO',
                    $history?->created_at?->toDateTimeString() ?? 'NA',
                ]);
            }

            fclose($stream);
        }, sprintf('ips_%s.csv', now()->timestamp), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function myIpsQuery(Workspace $workspace, ?string $serverId = null)
    {
        return ManagedServerIp::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)
            ->whereHas('server', fn ($query) => $query
                ->where('workspace_id', $workspace->getKey())
                ->where('is_active', true)
                ->where('is_installed', true))
            ->when($serverId !== null, fn ($query) => $query->where('managed_server_id', $serverId))
            ->with([
                'history',
                'server:id,workspace_id,name,ip_address',
                'server.history',
            ])
            ->orderByDesc('id');
    }

    public function serverIps(Request $request, string $workspaceId, string $serverId): View|JsonResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $server = $workspace->managedServers()->withTrashed()->whereKey($serverId)->firstOrFail();

        if (! $request->wantsJson()) {
            return view('console.infrastructure.server-ips', [
                'workspace' => $workspace,
                'server' => $server,
                'ipsUrl' => route('console.infrastructure.servers.ips', [$workspace->id, $server->id]),
                'vmtaUrl' => route('console.infrastructure.servers.mtas', [$workspace->id, $server->id, 'vmta']),
                'smtpUrl' => route('console.infrastructure.servers.mtas', [$workspace->id, $server->id, 'smtp']),
                'ipStatusUrlTemplate' => route('console.infrastructure.servers.ips.status', [$workspace->id, $server->id, '__IP__']),
                'mtaStatusUrlTemplate' => route('console.infrastructure.servers.mtas.status', [$workspace->id, $server->id, '__MTA__']),
                'ipHistoryUrlTemplate' => route('console.infrastructure.servers.ips.history', [$workspace->id, $server->id, '__IP__']),
            ]);
        }

        $query = $server->ips()->with('history')->withCount('histories');
        $recordsTotal = (clone $query)->count();
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('ip', 'like', "%{$search}%")
                    ->orWhere('dns', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%");
                if (ctype_digit($search)) {
                    $builder->orWhere('id', (int) $search);
                }
            });
        }

        $recordsFiltered = (clone $query)->count();
        $sortColumns = [0 => 'id', 1 => 'ip', 2 => 'type', 3 => 'dns', 4 => 'created_at'];
        $orderColumn = (int) $request->input('order.0.column', 0);
        $direction = strtolower((string) $request->input('order.0.dir', 'desc')) === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortColumns[$orderColumn] ?? 'id', $direction);

        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);
        $limit = $length === -1 ? 500 : min(max($length, 1), 100);
        $rows = $query->offset($start)->limit($limit)->get([
            'id', 'workspace_id', 'managed_server_id', 'sending_domain_id', 'ip', 'type', 'dns', 'is_active', 'created_at',
        ])->map(fn (ManagedServerIp $ip): array => [
            'id' => $ip->getKey(),
            'ip' => $ip->ip,
            'type' => $ip->type,
            'dns' => $ip->dns,
            'is_active' => $ip->is_active,
            'created_at' => $ip->created_at?->toDateTimeString(),
            'histories_count' => $ip->histories_count,
            'history' => $ip->history ? [
                'ptr' => $ip->history->ptr,
                'ip_listed_spamhaus' => $ip->history->ip_listed_spamhaus,
                'ip_listed_spamcop' => $ip->history->ip_listed_spamcop,
                'ip_listed_barracuda' => $ip->history->ip_listed_barracuda,
                'sender_score' => $ip->history->sender_score,
                'domain_listed_hostkarma' => $ip->history->domain_listed_hostkarma,
                'domain_listed_spamhaus_dbl' => $ip->history->domain_listed_spamhaus_dbl,
                'domain_listed_surbl' => $ip->history->domain_listed_surbl,
                'created_at' => $ip->history->created_at?->toDateTimeString(),
            ] : null,
        ])->values();

        return response()->json([
            'draw' => max(0, (int) $request->input('draw', 0)),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows,
        ]);
    }

    public function serverMtas(Request $request, string $workspaceId, string $serverId, string $type): JsonResponse
    {
        abort_unless(in_array($type, ['vmta', 'smtp'], true), 404);
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $server = $workspace->managedServers()->withTrashed()->whereKey($serverId)->firstOrFail();
        $query = $server->mtas()->with('user:id,name')->where('mta_type', $type);
        $recordsTotal = (clone $query)->count();
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('ip', 'like', "%{$search}%")
                    ->orWhere('dns', 'like', "%{$search}%")
                    ->orWhere('ip_type', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
                if (ctype_digit($search)) {
                    $builder->orWhere('id', (int) $search);
                }
            });
        }

        $recordsFiltered = (clone $query)->count();
        $sortColumns = [0 => 'id', 1 => 'ip', 2 => 'ip_type', 3 => 'dns', 4 => 'created_at'];
        $orderColumn = (int) $request->input('order.0.column', 0);
        $direction = strtolower((string) $request->input('order.0.dir', 'desc')) === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortColumns[$orderColumn] ?? 'id', $direction);

        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);
        $limit = $length === -1 ? 500 : min(max($length, 1), 100);
        $rows = $query->offset($start)->limit($limit)->get([
            'id', 'workspace_id', 'managed_server_id', 'managed_server_ip_id', 'ip', 'dns', 'ip_type', 'mta_type', 'username', 'is_active', 'user_id', 'created_at',
        ])->map(fn (ManagedServerMta $mta): array => [
            'id' => $mta->getKey(),
            'ip' => $mta->ip,
            'dns' => $mta->dns,
            'ip_type' => $mta->ip_type,
            'is_active' => $mta->is_active,
            'username' => $mta->username,
            'created_at' => $mta->created_at?->toDateTimeString(),
            'user' => $mta->user ? ['name' => $mta->user->name] : null,
        ])->values();

        return response()->json([
            'draw' => max(0, (int) $request->input('draw', 0)),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows,
        ]);
    }

    public function setServerIpStatus(Request $request, string $workspaceId, string $serverId, int $ipId): JsonResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $server = $workspace->managedServers()->withTrashed()->whereKey($serverId)->firstOrFail();
        $ip = $server->ips()->whereKey($ipId)->firstOrFail();
        $data = $request->validate(['status' => ['required', 'boolean']]);

        $saved = DB::transaction(function () use ($workspace, $server, $ip, $data, $request): bool {
            if (! $ip->forceFill(['is_active' => (bool) $data['status']])->save()) {
                return false;
            }

            $this->auditServerChange($workspace, $request->user(), 'server.ip_status_changed', $server, $request, [
                'managed_server_ip_id' => $ip->getKey(),
                'is_active' => $ip->is_active,
            ]);

            return true;
        });

        return response()->json([
            'data' => $saved ? 'Changed successfully' : 'Failed',
        ], $saved ? 200 : 400);
    }

    public function setServerMtaStatus(Request $request, string $workspaceId, string $serverId, int $mtaId): JsonResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $server = $workspace->managedServers()->withTrashed()->whereKey($serverId)->firstOrFail();
        $mta = $server->mtas()->whereKey($mtaId)->firstOrFail();
        $data = $request->validate(['status' => ['required', 'boolean']]);

        $saved = DB::transaction(function () use ($workspace, $server, $mta, $data, $request): bool {
            if (! $mta->forceFill(['is_active' => (bool) $data['status']])->save()) {
                return false;
            }

            $this->auditServerChange($workspace, $request->user(), 'server.mta_status_changed', $server, $request, [
                'managed_server_mta_id' => $mta->getKey(),
                'is_active' => $mta->is_active,
            ]);

            return true;
        });

        return response()->json([
            'data' => $saved ? 'Changed successfully' : 'Failed',
        ], $saved ? 200 : 400);
    }

    public function serverIpHistory(Request $request, string $workspaceId, string $serverId, int $ipId): View|JsonResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $server = $workspace->managedServers()->withTrashed()->whereKey($serverId)->firstOrFail();
        $ip = $server->ips()->whereKey($ipId)->firstOrFail();

        if (! $request->wantsJson()) {
            return view('console.infrastructure.server-ip-history', [
                'workspace' => $workspace,
                'server' => $server,
                'ip' => $ip,
                'historyUrl' => route('console.infrastructure.servers.ips.history', [$workspace->id, $server->id, $ip->getKey()]),
            ]);
        }

        $query = $ip->histories();
        $recordsTotal = (clone $query)->count();
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('ptr', 'like', "%{$search}%")
                    ->orWhere('sender_score', 'like', "%{$search}%");
                if (ctype_digit($search)) {
                    $builder->orWhere('id', (int) $search);
                }
            });
        }

        $recordsFiltered = (clone $query)->count();
        $sortColumns = [0 => 'id', 1 => 'ptr', 3 => 'sender_score', 4 => 'created_at'];
        $orderColumn = (int) $request->input('order.0.column', 0);
        $direction = strtolower((string) $request->input('order.0.dir', 'desc')) === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortColumns[$orderColumn] ?? 'id', $direction);

        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);
        $limit = $length === -1 ? 500 : min(max($length, 1), 100);
        $rows = $query->offset($start)->limit($limit)->get([
            'id', 'workspace_id', 'managed_server_ip_id', 'ptr', 'ip_listed_spamhaus', 'ip_listed_spamcop',
            'ip_listed_barracuda', 'sender_score', 'domain_listed_hostkarma', 'domain_listed_spamhaus_dbl',
            'domain_listed_surbl', 'created_at',
        ])->map(fn (ManagedServerIpHistory $history): array => [
            'id' => $history->getKey(),
            'ptr' => $history->ptr,
            'ip_listed_spamhaus' => $history->ip_listed_spamhaus,
            'ip_listed_spamcop' => $history->ip_listed_spamcop,
            'ip_listed_barracuda' => $history->ip_listed_barracuda,
            'sender_score' => $history->sender_score,
            'domain_listed_hostkarma' => $history->domain_listed_hostkarma,
            'domain_listed_spamhaus_dbl' => $history->domain_listed_spamhaus_dbl,
            'domain_listed_surbl' => $history->domain_listed_surbl,
            'created_at' => $history->created_at?->toDateTimeString(),
        ])->values();

        return response()->json([
            'draw' => max(0, (int) $request->input('draw', 0)),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows,
        ]);
    }

    public function serverUsers(Request $request, string $workspaceId, string $serverId): View
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        $users = $workspace->users()->wherePivot('role', 'operator')->orderBy('users.name')->get(['users.id', 'users.name', 'users.email']);
        $allowedUserIds = DB::table('managed_server_user')
            ->where('workspace_id', $workspace->getKey())
            ->where('managed_server_id', $server->getKey())
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        return view('console.infrastructure.server-users', compact('workspace', 'server', 'users', 'allowedUserIds'));
    }

    public function updateServerUsers(Request $request, string $workspaceId, string $serverId): JsonResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        $data = $request->validate([
            'users' => ['sometimes', 'nullable', 'array'],
            'users.*' => ['integer', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->getKey())->where('role', 'operator')],
        ]);
        $userIds = collect($data['users'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();

        DB::transaction(function () use ($workspace, $server, $userIds, $request): void {
            $this->replaceAllowedUsers($workspace, $server, $userIds);
            $this->auditServerChange($workspace, $request->user(), 'server.allowed_users_updated', $server, $request, ['user_ids' => $userIds]);
        });

        return response()->json(['data' => 'Updated successfully']);
    }

    public function bulkServerUsers(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid'],
        ]);
        $ids = array_values(array_unique($data['ids']));
        $servers = $workspace->managedServers()->whereIn('id', $ids)->orderBy('id')->get(['id', 'workspace_id', 'name']);
        abort_unless($servers->count() === count($ids), 404);
        $users = $workspace->users()->wherePivot('role', 'operator')->orderBy('users.name')->get(['users.id', 'users.name', 'users.email']);

        return view('console.infrastructure.server-bulk-users', compact('workspace', 'servers', 'users'));
    }

    public function updateBulkServerUsers(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $data = $request->validate([
            'servers' => ['required', 'array', 'min:1'],
            'servers.*' => ['required', 'uuid'],
            'users' => ['sometimes', 'nullable', 'array'],
            'users.*' => ['integer', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->getKey())->where('role', 'operator')],
        ]);
        $serverIds = array_values(array_unique($data['servers']));
        $servers = $workspace->managedServers()->whereIn('id', $serverIds)->get();
        abort_unless($servers->count() === count($serverIds), 404);
        $userIds = collect($data['users'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();

        DB::transaction(function () use ($workspace, $servers, $userIds, $request): void {
            foreach ($servers as $server) {
                $this->replaceAllowedUsers($workspace, $server, $userIds);
                $this->auditServerChange($workspace, $request->user(), 'server.allowed_users_updated', $server, $request, ['user_ids' => $userIds, 'bulk' => true]);
            }
        });

        return response()->json(['data' => 'Updated successfully']);
    }

    public function setServerLock(Request $request, string $workspaceId, string $serverId): JsonResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $server->update(['is_locked' => (bool) $data['status']]);
        $this->auditServerChange($workspace, $request->user(), 'server.lock_changed', $server, $request, ['is_locked' => $server->is_locked]);

        return response()->json(['data' => 'Changed successfully']);
    }

    public function setServerStatus(Request $request, string $workspaceId, string $serverId): JsonResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $server->update(['is_active' => (bool) $data['status']]);
        $this->auditServerChange($workspace, $request->user(), 'server.status_changed', $server, $request, ['is_active' => $server->is_active]);

        return response()->json(['data' => 'Changed successfully']);
    }

    public function returnServers(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid'],
        ]);
        $ids = array_values(array_unique($data['ids']));
        $serversQuery = $workspace->managedServers()->whereIn('id', $ids)->where('is_locked', false);
        $servers = $serversQuery->get();

        DB::transaction(function () use ($workspace, $servers, $request): void {
            foreach ($servers as $server) {
                $server->delete();
                $this->auditServerChange($workspace, $request->user(), 'server.returned', $server, $request);
            }
        });

        if ($servers->isEmpty()) {
            return response()->json(['data' => 'Failed'], 400);
        }

        return response()->json(['data' => 'Servers returned', 'returned' => $servers->count()]);
    }

    public function restoreServer(Request $request, string $workspaceId, string $serverId): JsonResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $server = $workspace->managedServers()->onlyTrashed()->whereKey($serverId)->firstOrFail();
        $server->restore();
        $this->auditServerChange($workspace, $request->user(), 'server.restored', $server, $request);

        return response()->json(['data' => 'Server restored']);
    }

    public function storeServer(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $data = $request->validate([
            'servers_provider_id' => ['required', 'integer', Rule::exists('server_providers', 'id')->where(fn ($query) => $query
                ->whereNull('deleted_at')
                ->where(fn ($scope) => $scope->whereNull('workspace_id')->orWhere('workspace_id', $workspace->getKey())))],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'ips' => ['required', 'array', 'min:1'],
            'port' => ['required', 'numeric'],
            'username' => ['required', 'string'],
            'login_type' => ['required', 'string', 'in:password,ssh_key'],
            'password' => ['required', 'string', 'min:4'],
            'expire_at' => ['required', 'date', 'after_or_equal:today'],
        ]);
        $result = $this->manager->registerServers($workspace, $request->user(), $data, $request->attributes->get('request_id'));

        return response()->json([
            'data' => "Added {$result['added']}, Restored {$result['restored']}",
            'added' => $result['added'],
            'restored' => $result['restored'],
            'servers' => $result['servers']->map(fn ($server) => $server->only(['id', 'name', 'servers_provider_id', 'ip_address', 'ssh_port', 'ssh_username', 'login_type', 'expire_at', 'state']))->values(),
        ]);
    }

    public function testServerConnection(Request $request, string $workspaceId): JsonResponse
    {
        $this->workspaceForServerControl($request->user(), $workspaceId);
        $data = $request->validate([
            'ips' => ['required', 'array', 'min:1'],
            'port' => ['required', 'numeric'],
            'username' => ['required', 'string'],
            'login_type' => ['required', 'string', 'in:password,ssh_key'],
            'password' => ['required', 'string', 'min:4'],
        ]);
        $results = $this->manager->testServerConnections(
            $data['ips'], (int) $data['port'], $data['username'], $data['login_type'],
            $data['password'],
        );

        return response()->json([
            'data' => array_column($results, 'message'),
            'results' => $results,
        ]);
    }

    public function storeDomain(Request $request, string $workspaceId): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $data = $request->validate(['name' => ['required', 'string', 'max:253']]);
        $this->manager->registerDomain($workspace, $request->user(), $data['name'], $request->attributes->get('request_id'));

        return redirect()->route('console.campaigns.test-draft.create', $workspace->id)->with('status', 'Sender domain saved.');
    }

    public function storeDmsInstallDomains(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $data = $request->validate([
            'provider' => ['required', 'numeric'],
            'domains' => ['required', 'array'],
            'domains.*' => ['string'],
        ]);

        $added = $this->manager->registerDmsInstallDomains(
            $workspace,
            $request->user(),
            $data['provider'],
            $data['domains'],
            $request->attributes->get('request_id'),
        );

        return response()->json(['data' => $added.' Domain Created']);
    }

    public function importDmsInstallDomains(
        Request $request,
        string $workspaceId,
        DmsDomainProviderImport $domainImport,
    ): JsonResponse {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $data = $request->validate(['provider' => ['required', 'numeric']]);
        $provider = DomainProvider::query()
            ->whereKey($data['provider'])
            ->where(fn ($query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $workspace->getKey()))
            ->firstOrFail();

        try {
            $added = $domainImport->import(
                $workspace,
                $request->user(),
                $provider,
                (string) $request->ip(),
                $request->attributes->get('request_id'),
            );
        } catch (DmsDomainProviderImportException $exception) {
            return response()->json(['errors' => ['provider' => [$exception->getMessage()]]], 422);
        }

        return response()->json(['data' => sprintf('Added : %s new domains', $added)]);
    }

    public function storeDomainProvider(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $providerType = (string) $request->input('type');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:4', 'max:255'],
            'type' => ['required', 'string', Rule::in(DomainProvider::TYPES)],
            'username' => [Rule::requiredIf(in_array($providerType, ['namecheap', 'godaddy'], true)), 'nullable', 'string', 'min:4', 'max:255'],
            'password' => ['nullable', 'string', 'min:4', 'max:255'],
            'api_key' => ['required', 'string', 'min:4', 'max:255'],
            'api_secret' => ['nullable', 'string', 'min:4', 'max:255'],
        ]);

        $this->manager->registerDmsDomainProvider(
            $workspace,
            $request->user(),
            $data,
            $request->attributes->get('request_id'),
        );

        return response()->json(['data' => 'Provider created']);
    }

    public function testNewDomainProviderConnection(
        Request $request,
        string $workspaceId,
        DmsDomainProviderConnectionTester $tester,
    ): JsonResponse {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);

        return $this->testDomainProviderCredentials($request, $workspace, null, $tester);
    }

    public function testExistingDomainProviderConnection(
        Request $request,
        string $workspaceId,
        int $providerId,
        DmsDomainProviderConnectionTester $tester,
    ): JsonResponse {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $provider = DomainProvider::query()
            ->where('workspace_id', $workspace->getKey())
            ->findOrFail($providerId);

        return $this->testDomainProviderCredentials($request, $workspace, $provider, $tester);
    }

    private function testDomainProviderCredentials(
        Request $request,
        Workspace $workspace,
        ?DomainProvider $savedProvider,
        DmsDomainProviderConnectionTester $tester,
    ): JsonResponse {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(DomainProvider::TYPES)],
            'username' => ['nullable', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'api_secret' => ['nullable', 'string', 'max:255'],
        ]);

        $sameProviderType = $savedProvider !== null && $savedProvider->type === $data['type'];
        foreach (['username', 'api_key', 'api_secret'] as $credential) {
            if (blank($data[$credential] ?? null) && $sameProviderType) {
                $data[$credential] = $savedProvider->{$credential};
            }
        }

        $provider = new DomainProvider([
            'workspace_id' => $workspace->getKey(),
            'type' => $data['type'],
            'username' => $data['username'] ?? null,
            'api_key' => $data['api_key'] ?? null,
            'api_secret' => $data['api_secret'] ?? null,
        ]);

        try {
            $message = $tester->test($provider, (string) $request->ip());

            return response()->json(['success' => true, 'message' => $message]);
        } catch (DmsDomainProviderImportException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()]);
        }
    }

    public function updateDmsDomainProvider(Request $request, string $workspaceId, int $providerId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $provider = DomainProvider::query()->where('workspace_id', $workspace->getKey())->findOrFail($providerId);
        $providerType = (string) $request->input('type');
        $keyRequired = $provider->type !== $providerType || blank($provider->api_key);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:4', 'max:255'],
            'type' => ['required', 'string', Rule::in(DomainProvider::TYPES)],
            'username' => [Rule::requiredIf(in_array($providerType, ['namecheap', 'godaddy'], true)), 'nullable', 'string', 'min:4', 'max:255'],
            'password' => ['nullable', 'string', 'min:4', 'max:255'],
            'api_key' => [$keyRequired ? 'required' : 'nullable', 'string', 'min:4', 'max:255'],
            'api_secret' => ['nullable', 'string', 'min:4', 'max:255'],
        ]);

        $changes = array_intersect_key($data, array_flip(['name', 'type', 'username']));
        foreach (['password', 'api_key', 'api_secret'] as $secret) {
            if (filled($data[$secret] ?? null)) {
                $changes[$secret] = $data[$secret];
            }
        }
        $provider->fill($changes)->save();
        $this->auditDomainProviderChange($workspace, $request, $provider, 'domain_provider.updated', ['type' => $provider->type]);

        return response()->json(['data' => 'Provider updated']);
    }

    /** @return array{0: Workspace, 1: SendingDomain, 2: DomainProvider} */
    private function cloudflareDomainFor(Request $request, string $workspaceId, string $domainId): array
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $domain = $workspace->sendingDomains()->whereKey($domainId)->firstOrFail();
        abort_unless($domain->is_our, 400, 'NOT OUR DOMAIN');

        $provider = DomainProvider::query()
            ->whereKey($domain->domains_provider_id)
            ->where(fn ($query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $workspace->getKey()))
            ->firstOrFail();
        abort_unless($provider->type === 'cloudflare' && $provider->is_active, 422, 'The selected domain is not connected to an active Cloudflare provider.');

        return [$workspace, $domain, $provider];
    }

    /** @return array<string, mixed> */
    private function cloudflareDnsRecordPayload(Request $request, SendingDomain $domain): array
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(['A', 'AAAA', 'CAA', 'CERT', 'CNAME', 'DNSKEY', 'DS', 'HTTPS', 'LOC', 'MX', 'NAPTR', 'NS', 'OPENPGPKEY', 'PTR', 'SMIMEA', 'SRV', 'SSHFP', 'SVCB', 'TLSA', 'TXT', 'URI'])],
            'name' => ['required', 'string', 'max:253'],
            'content' => ['nullable', 'string', 'max:4096'],
            'ttl' => ['required', 'integer', 'min:1', 'max:86400'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'proxied' => ['sometimes', 'boolean'],
            'comment' => ['nullable', 'string', 'max:500'],
            'data_json' => ['nullable', 'string', 'max:4000'],
        ]);

        $zone = mb_strtolower(rtrim($domain->name, '.'));
        if ((int) $data['ttl'] !== 1 && (int) $data['ttl'] < 60) {
            throw ValidationException::withMessages(['ttl' => 'TTL must be automatic (1) or between 60 and 86400 seconds.']);
        }
        $name = mb_strtolower(rtrim(trim($data['name']), '.'));
        if ($name === '@') {
            $name = $zone;
        } elseif (! str_ends_with($name, '.'.$zone) && $name !== $zone) {
            if (str_contains($name, '.')) {
                throw ValidationException::withMessages(['name' => 'The DNS name must belong to this Cloudflare zone.']);
            }
            $name .= '.'.$zone;
        }
        if (strlen($name) > 253
            || ! preg_match('/\\A(?:\\*\\.)?(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\\.)*[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\\z/i', $name)
            || ($name !== $zone && ! str_ends_with($name, '.'.$zone))) {
            throw ValidationException::withMessages(['name' => 'Enter a valid DNS name inside this Cloudflare zone.']);
        }

        $record = [
            'type' => $data['type'],
            'name' => $name,
            'ttl' => (int) $data['ttl'],
        ];
        if (filled($data['content'] ?? null)) {
            $record['content'] = $data['content'];
        }
        if (filled($data['comment'] ?? null)) {
            $record['comment'] = $data['comment'];
        }
        if (filled($data['data_json'] ?? null)) {
            $decoded = json_decode($data['data_json']);
            if (! is_object($decoded)) {
                throw ValidationException::withMessages(['data_json' => 'Record data must be a JSON object.']);
            }
            $record['data'] = json_decode($data['data_json'], true, 512, JSON_THROW_ON_ERROR);
        }
        if (in_array($data['type'], ['MX', 'URI'], true)) {
            if (! array_key_exists('priority', $data) || $data['priority'] === null || $data['priority'] === '') {
                throw ValidationException::withMessages(['priority' => 'Priority is required for MX and URI records.']);
            }
            $record['priority'] = (int) $data['priority'];
        }
        if (in_array($data['type'], ['A', 'AAAA', 'CNAME'], true)) {
            $record['proxied'] = (bool) ($data['proxied'] ?? false);
        } elseif (($data['proxied'] ?? false) === true || ($data['proxied'] ?? false) === '1') {
            throw ValidationException::withMessages(['proxied' => 'Proxy status is available only for A, AAAA, and CNAME records.']);
        }
        if (! isset($record['content']) && ! isset($record['data'])) {
            throw ValidationException::withMessages(['content' => 'Enter a record value or the type-specific JSON data.']);
        }

        return $record;
    }

    public function setDmsDomainProviderStatus(Request $request, string $workspaceId, int $providerId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $provider = DomainProvider::query()->where('workspace_id', $workspace->getKey())->findOrFail($providerId);
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $provider->forceFill(['is_active' => (bool) $data['status']])->save();
        $this->auditDomainProviderChange($workspace, $request, $provider, 'domain_provider.status_changed', ['is_active' => $provider->is_active]);

        return response()->json(['data' => 'Changed successfully']);
    }

    public function updateInstallUsername(Request $request, string $workspaceId): JsonResponse
    {
        $this->workspaceForServerControl($request->user(), $workspaceId);
        $data = $request->validate([
            'username' => [
                'required', 'string', 'min:4', 'max:255',
                Rule::unique('users', 'username')->ignore($request->user()->getKey()),
            ],
        ]);

        $request->user()->forceFill(['username' => $data['username']])->save();

        return response()->json(['data' => 'Username updated']);
    }

    public function preflightServer(Request $request, string $workspaceId, string $serverId): RedirectResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $server = $workspace->managedServers()->whereKey($serverId)->firstOrFail();
        try {
            $this->manager->verifyServerHost($workspace, $request->user(), $server, $request->attributes->get('request_id'));
        } catch (HostProbeException $exception) {
            return redirect()->route('console.infrastructure.servers.index', $workspace->id)->with('warning', $exception->getMessage());
        }

        return redirect()->route('console.infrastructure.servers.index', $workspace->id)->with('status', 'Host identity and OS preflight verified.');
    }

    public function verifyDomain(Request $request, string $workspaceId, string $domainId): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $domain = $workspace->sendingDomains()->whereKey($domainId)->firstOrFail();
        $verified = $this->manager->verifyDomain($workspace, $domain, $request->user(), $request->attributes->get('request_id'));

        return redirect()->route('console.campaigns.test-draft.create', $workspace->id)
            ->with($verified ? 'status' : 'warning', $verified ? 'Domain ownership verified.' : 'TXT record not found yet. Check its name and value, then try again.');
    }

    public function storePlan(Request $request, string $workspaceId): RedirectResponse
    {
        $workspace = $this->workspaceForServerControl($request->user(), $workspaceId);
        $data = $request->validate([
            'server_id' => ['required', 'uuid'],
            'domain_id' => ['required', 'uuid'],
            'pmta_version' => ['required', 'in:4.0,4.5,5.0'],
            'tracking' => ['sometimes', 'accepted'],
            'dkim' => ['sometimes', 'accepted'],
            'dmarc' => ['sometimes', 'accepted'],
            'proxy' => ['sometimes', 'accepted'],
            'spf' => ['sometimes', 'accepted'],
        ]);
        $server = $workspace->managedServers()->whereKey($data['server_id'])->firstOrFail();
        $domain = $workspace->sendingDomains()->whereKey($data['domain_id'])->firstOrFail();
        $options = collect(['tracking', 'dkim', 'dmarc', 'proxy', 'spf'])
            ->mapWithKeys(fn ($key) => [$key => array_key_exists($key, $data)])->all();
        $plan = $this->manager->createPlan($workspace, $request->user(), $server, $domain, $data['pmta_version'], $options, $request->attributes->get('request_id'));

        return redirect()->route('console.infrastructure.servers.index', $workspace->id)
            ->with('status', 'Installation preview saved. No remote server changes were made.');
    }

    private function workspaceFor(User $user, string $workspaceId, array $allowedRoles = ['owner', 'admin']): Workspace
    {
        $workspace = $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, $allowedRoles, true), 403);

        return $workspace;
    }

    private function workspaceForServerControl(User $user, string $workspaceId): Workspace
    {
        return $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
    }

    private function auditDomainChange(
        Workspace $workspace,
        User $actor,
        SendingDomain $domain,
        Request $request,
        string $action,
        array $properties = [],
    ): void {
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $actor->getKey(),
            'action' => $action,
            'subject_type' => 'sending_domain',
            'subject_id' => (string) $domain->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => $properties,
            'occurred_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $properties */
    private function auditDomainProviderChange(Workspace $workspace, Request $request, DomainProvider $provider, string $action, array $properties): void
    {
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => $action,
            'subject_type' => 'domain_provider',
            'subject_id' => (string) $provider->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => $properties,
            'occurred_at' => now(),
        ]);
    }

    /** @param array<int, int> $userIds */
    private function replaceAllowedUsers(Workspace $workspace, ManagedServer $server, array $userIds): void
    {
        DB::table('managed_server_user')
            ->where('workspace_id', $workspace->getKey())
            ->where('managed_server_id', $server->getKey())
            ->delete();

        if ($userIds !== []) {
            DB::table('managed_server_user')->insert(array_map(
                fn (int $userId): array => [
                    'workspace_id' => $workspace->getKey(),
                    'managed_server_id' => $server->getKey(),
                    'user_id' => $userId,
                ],
                $userIds,
            ));
        }
    }

    private function auditServerChange(Workspace $workspace, User $actor, string $action, ManagedServer $server, Request $request, array $properties = []): void
    {
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $actor->getKey(),
            'action' => $action,
            'subject_type' => 'managed_server',
            'subject_id' => $server->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => $properties,
            'occurred_at' => now(),
        ]);
    }
}
