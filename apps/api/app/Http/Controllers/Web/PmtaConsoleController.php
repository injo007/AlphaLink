<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerMta;
use App\Models\PmtaConfigHistory;
use App\Models\PmtaScheduledAction;
use App\Models\PmtaSmtpConfig;
use App\Models\PmtaVirtualMta;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsBulkInstallPrivateAssetReader;
use App\Services\PmtaAuditLogger;
use App\Services\PmtaBounceLogReader;
use App\Services\PmtaRemoteConfiguration;
use App\Services\PmtaScheduleFrequency;
use App\Services\PmtaSmtpConfigBuilder;
use App\Services\PmtaVirtualMtaConfigBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class PmtaConsoleController extends Controller
{
    public function scheduler(Request $request, string $workspace, PmtaScheduleFrequency $frequencies): View
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);

        return view('console.pmta.scheduler', [
            'workspace' => $workspaceModel,
            'servers' => $this->servers($workspaceModel),
            'actions' => PmtaScheduledAction::query()->where('workspace_id', $workspaceModel->getKey())->with('creator:id,username')->orderByDesc('id')->get(),
            'frequencies' => $frequencies->options(),
        ]);
    }

    public function scheduledActions(Request $request, string $workspace, PmtaScheduleFrequency $frequencies): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $servers = $this->servers($workspaceModel)->keyBy(fn (ManagedServer $server): string => (string) $server->getKey());
        $rows = PmtaScheduledAction::query()->where('workspace_id', $workspaceModel->getKey())
            ->with('creator:id,username')->orderByDesc('id')->get()
            ->map(fn (PmtaScheduledAction $action): array => [
                'id' => $action->getKey(),
                'user' => $action->creator?->username ?? 'N/A',
                'servers' => collect($action->server_ids)->map(fn (string $id): string => $servers->get($id)?->name ?? 'Unavailable')->all(),
                'queue' => strtoupper($action->queue),
                'tags' => $action->tags,
                'action' => $action->action,
                'frequency' => $frequencies->options()[$action->frequency] ?? $action->frequency,
                'created_at' => $action->created_at?->toDateTimeString(),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function storeScheduledAction(Request $request, string $workspace, PmtaScheduleFrequency $frequencies): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $data = $request->validate([
            'server_ids' => ['required', 'array', 'min:1'], 'server_ids.*' => ['required', 'uuid', 'distinct'],
            'queue' => ['required', Rule::in(['domain', 'campaign'])], 'tags' => ['required', 'array', 'min:1'],
            'tags.*' => ['required', 'string', 'max:255'], 'action' => ['required', Rule::in(['pause', 'resume', 'delete', 'reset', 'schedule'])],
            'frequency' => ['required', Rule::in(array_keys($frequencies->options()))],
        ]);
        $serverIds = array_values(array_unique(array_map('strval', $data['server_ids'])));
        $this->assertServers($workspaceModel, $serverIds);
        $tags = array_values(array_unique(array_filter(array_map('trim', $data['tags']), fn (string $tag): bool => $tag !== '')));
        if (! in_array('*/*', $tags, true)) {
            throw ValidationException::withMessages(['tags' => 'Select the */* queue tag before scheduling a PMTA command.']);
        }
        $action = PmtaScheduledAction::query()->create([
            'workspace_id' => $workspaceModel->getKey(), 'created_by' => $request->user()->getKey(), 'server_ids' => $serverIds,
            'queue' => $data['queue'], 'tags' => $tags, 'action' => $data['action'], 'frequency' => $data['frequency'], 'is_active' => true,
        ]);

        return response()->json(['success' => true, 'id' => $action->getKey(), 'message' => 'PMTA task added.']);
    }

    public function deleteScheduledAction(Request $request, string $workspace, int $actionId): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        PmtaScheduledAction::query()->where('workspace_id', $workspaceModel->getKey())->findOrFail($actionId)->delete();

        return response()->json(['success' => true, 'message' => 'PMTA task removed.']);
    }

    public function settings(Request $request, string $workspace): View
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);

        return view('console.pmta.settings', ['workspace' => $workspaceModel, 'servers' => $this->servers($workspaceModel)]);
    }

    public function settingsServers(Request $request, string $workspace): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $servers = $this->servers($workspaceModel)->map(fn (ManagedServer $server): array => [
            'id' => $server->getKey(),
            'name' => $server->name,
            'ip_address' => $server->ip_address,
        ]);

        return response()->json(['data' => $servers]);
    }

    public function settingsFiles(Request $request, string $workspace, PmtaRemoteConfiguration $remote): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $server = $this->serverFor($workspaceModel, (string) $request->query('server_id'));

        return response()->json(['success' => true, 'files' => $remote->listFiles($server)]);
    }

    public function settingsFile(Request $request, string $workspace, PmtaRemoteConfiguration $remote): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $data = $request->validate(['server_id' => ['required', 'uuid'], 'file' => ['required', 'string', 'max:120']]);
        $server = $this->serverFor($workspaceModel, $data['server_id']);

        return response()->json(['success' => true, 'file' => $data['file'], 'contents' => $remote->readFile($server, $data['file'])]);
    }

    public function editSettingsFile(Request $request, string $workspace, PmtaRemoteConfiguration $remote): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $data = $request->validate(['server_id' => ['required', 'uuid'], 'file' => ['required', 'string', 'max:120'], 'contents' => ['required', 'string', 'max:1048576']]);
        $server = $this->serverFor($workspaceModel, $data['server_id']);
        try {
            $result = $remote->writeAndReload($server, $data['file'], $data['contents']);
            $this->recordConfig($workspaceModel, $request->user(), $server, $data['file'], 'edit', $data['contents'], $result, true);
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'PMTA configuration saved and reloaded.']);
    }

    public function pushSettingsFile(Request $request, string $workspace, PmtaRemoteConfiguration $remote): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $data = $request->validate([
            'server_ids' => ['required', 'array', 'min:1'], 'server_ids.*' => ['required', 'uuid', 'distinct'],
            'file' => ['required', 'string', 'max:120'], 'contents' => ['required', 'string', 'max:1048576'],
        ]);
        $servers = $this->assertServers($workspaceModel, array_map('strval', $data['server_ids']));
        $results = [];
        foreach ($servers as $server) {
            try {
                $result = $remote->pushFile($server, $data['file'], $data['contents']);
                $this->recordConfig($workspaceModel, $request->user(), $server, $data['file'], 'push', $data['contents'], $result, true);
                $results[] = ['server' => $server->name, 'success' => true, 'result' => 'Configuration pushed and reloaded.'];
            } catch (Throwable $exception) {
                $results[] = ['server' => $server->name, 'success' => false, 'result' => $exception->getMessage()];
            }
        }

        return response()->json(['success' => collect($results)->every(fn (array $row): bool => $row['success']), 'results' => $results]);
    }

    public function defaultDomains(Request $request, string $workspace, DmsBulkInstallPrivateAssetReader $assets): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $data = $request->validate(['server_id' => ['required', 'uuid']]);
        $server = $this->serverFor($workspaceModel, $data['server_id']);
        $version = (int) ($server->install_parameters['pmta_version'] ?? 40);
        abort_unless(in_array($version, [40, 45, 50], true), 422);
        $saved = $workspaceModel->infrastructureSettings?->pmta_domain_configs;
        $contents = is_array($saved) ? ($saved[(string) $version] ?? null) : null;
        if (! is_string($contents)) {
            $root = config('infrastructure.install_assets_directory');
            abort_unless(is_string($root) && $root !== '', 503, 'The default PMTA configuration bundle is unavailable.');
            $contents = $assets->read($root, "install/pmta_{$version}/pmta_domains", false);
        }

        return response()->json(['success' => true, 'contents' => $contents ?? '']);
    }

    public function virtualMtas(Request $request, string $workspace): View
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);

        return view('console.pmta.vmta', [
            'workspace' => $workspaceModel,
            'servers' => $this->servers($workspaceModel),
            'ips' => ManagedServerIp::query()->where('workspace_id', $workspaceModel->getKey())->where('is_active', true)
                ->whereHas('server', fn (Builder $query) => $query->where('is_active', true)->where('is_installed', true))
                ->with('server:id,name')->orderBy('ip')->get(),
        ]);
    }

    public function vmtaRows(Request $request, string $workspace): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $rows = PmtaVirtualMta::query()->where('workspace_id', $workspaceModel->getKey())->where('created_by', $request->user()->getKey())->with('server:id,name')->orderByDesc('id')->get()
            ->map(fn (PmtaVirtualMta $row): array => ['id' => $row->getKey(), 'name' => $row->name, 'ip' => $row->ip, 'dns' => $row->domain, 'created_at' => $row->created_at?->toDateTimeString(), 'is_active' => $row->is_active]);

        return response()->json(['data' => $rows]);
    }

    public function pushVmta(Request $request, string $workspace, PmtaRemoteConfiguration $remote, PmtaVirtualMtaConfigBuilder $builder, PmtaAuditLogger $audit): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $data = $request->validate([
            'ip_ids' => ['required', 'array', 'min:1'], 'ip_ids.*' => ['required', 'integer'],
            'domains' => ['required', 'string'], 'route' => ['required', 'boolean'], 'duplicate' => ['required', 'numeric', 'min:1'],
        ]);
        $ips = ManagedServerIp::query()->where('workspace_id', $workspaceModel->getKey())->whereIn('id', $data['ip_ids'])->where('is_active', true)
            ->whereHas('server', fn (Builder $query) => $query->where('is_active', true)->where('is_installed', true))->with('server')->get();
        if ($ips->count() !== count(array_unique($data['ip_ids']))) {
            throw ValidationException::withMessages(['ip_ids' => 'Choose active IPs from installed servers in this workspace.']);
        }
        $lines = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $data['domains']) ?: []))));
        if ($lines === []) {
            throw ValidationException::withMessages(['domains' => 'Enter at least one domain.']);
        }
        $entries = array_map(static function (string $line): array {
            $parts = array_map('trim', explode(',', $line, 2));

            return [$parts[0], $parts[1] ?? ''];
        }, $lines);
        $duplicateCount = (int) $data['duplicate'];
        $results = [];
        foreach ($ips->groupBy('managed_server_id') as $serverIps) {
            $server = $serverIps->first()->server;
            try {
                $count = DB::transaction(function () use ($workspaceModel, $request, $server, $serverIps, $entries, $data, $duplicateCount, $remote, $builder): int {
                    $rows = [];
                    $sequence = 1;
                    foreach ($serverIps as $ip) {
                        foreach ($entries as [$domain, $routeHost]) {
                            for ($duplicate = 1; $duplicate <= $duplicateCount; $duplicate++) {
                                $name = 'v_'.$sequence;
                                $record = PmtaVirtualMta::query()->create([
                                    'workspace_id' => $workspaceModel->getKey(), 'managed_server_id' => $server->getKey(),
                                    'managed_server_ip_id' => $ip->getKey(), 'created_by' => $request->user()->getKey(),
                                    'ip' => $ip->ip, 'domain' => $domain ?: $ip->dns, 'name' => $name,
                                    'duplicate_index' => $sequence, 'route' => (bool) $data['route'], 'is_active' => true,
                                ]);
                                $this->createCampaignVmta($workspaceModel, $request->user(), $record, $ip);
                                $rows[] = ['id' => (int) $record->getKey(), 'ip' => $ip->ip, 'dns' => $ip->dns, 'domain' => $domain ?: $ip->dns, 'route_host' => $routeHost ?: null, 'duplicate_index' => $sequence];
                                $sequence++;
                            }
                        }
                    }
                    $configuration = $builder->build($rows, (bool) $data['route'], (int) ($server->install_parameters['pmta_version'] ?? 40));
                    $file = 'vmta_'.$request->user()->getKey();
                    $reloadResult = $remote->pushVmtaConfiguration($server, (int) $request->user()->getKey(), $configuration);
                    $this->recordConfig($workspaceModel, $request->user(), $server, $file, 'vmta_push', $configuration, $reloadResult, true);

                    return count($rows);
                });
                $audit->log($workspaceModel, $request->user(), $server, 'vmta.push', $count.' VMTA entries pushed.', $request->attributes->get('request_id'));
                $results[] = ['server' => $server->name, 'success' => true, 'count' => $count, 'message' => 'Virtual MTA configuration pushed.'];
            } catch (Throwable $exception) {
                $results[] = ['server' => $server->name, 'success' => false, 'message' => $exception->getMessage()];
            }
        }

        return response()->json(['success' => collect($results)->every(fn (array $row): bool => $row['success']), 'results' => $results]);
    }

    public function pushVmtaRows(Request $request, string $workspace, PmtaRemoteConfiguration $remote, PmtaVirtualMtaConfigBuilder $builder, PmtaAuditLogger $audit): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $data = $request->validate(['server_id' => ['required', 'uuid'], 'rows' => ['required', 'string']]);
        $server = $this->serverFor($workspaceModel, $data['server_id']);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $data['rows']) ?: [])));
        if ($lines === []) {
            throw ValidationException::withMessages(['rows' => 'Enter one IP and VMTA host per line.']);
        }
        try {
            $count = DB::transaction(function () use ($workspaceModel, $request, $server, $lines, $remote, $builder): int {
                $rows = [];
                foreach ($lines as $index => $line) {
                    $parts = array_map('trim', str_getcsv($line));
                    if (count($parts) !== 2) {
                        throw ValidationException::withMessages(['rows' => 'Each row must contain exactly: IP,VMTA host.']);
                    }
                    // DMS resolves v2 IP rows by address, independently of the selected
                    // server that receives the generated config. Keep that behavior
                    // within this workspace and its active, installed server inventory.
                    $ip = ManagedServerIp::query()->where('workspace_id', $workspaceModel->getKey())->where('ip', $parts[0])->where('is_active', true)
                        ->whereHas('server', fn (Builder $query) => $query->where('is_active', true)->where('is_installed', true))->first();
                    if (! $ip) {
                        throw ValidationException::withMessages(['rows' => 'Every IP must belong to an active, installed server in this workspace.']);
                    }
                    $record = PmtaVirtualMta::query()->create([
                        // DMS associates the MTA row with the server that owns the IP,
                        // even though the v2 config is pushed to the selected server.
                        'workspace_id' => $workspaceModel->getKey(), 'managed_server_id' => $ip->managed_server_id, 'managed_server_ip_id' => $ip->getKey(),
                        'created_by' => $request->user()->getKey(), 'ip' => $ip->ip, 'domain' => $parts[1], 'name' => 'v_'.($index + 1),
                        'duplicate_index' => $index + 1, 'route' => false, 'is_active' => true,
                    ]);
                    $this->createCampaignVmta($workspaceModel, $request->user(), $record, $ip, $ip->dns);
                    $rows[] = ['id' => (int) $record->getKey(), 'ip' => $ip->ip, 'dns' => $ip->dns, 'domain' => $parts[1], 'route_host' => null, 'duplicate_index' => $index + 1];
                }
                $configuration = $builder->build($rows, false, (int) ($server->install_parameters['pmta_version'] ?? 40));
                $file = 'vmta_'.$request->user()->getKey();
                $result = $remote->pushVmtaV2($server, (int) $request->user()->getKey(), $configuration);
                $this->recordConfig($workspaceModel, $request->user(), $server, $file, 'vmta_v2_push', $configuration, $result, true);

                return count($rows);
            });
            $audit->log($workspaceModel, $request->user(), $server, 'vmta.v2.push', $count.' VMTA entries pushed.', $request->attributes->get('request_id'));

            return response()->json(['success' => true, 'message' => $count.' VMTA entry(s) pushed.']);
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function restoreVmta(Request $request, string $workspace, PmtaRemoteConfiguration $remote): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $data = $request->validate(['ip_ids' => ['required', 'array', 'min:1'], 'ip_ids.*' => ['required', 'integer']]);
        $ips = ManagedServerIp::query()->where('workspace_id', $workspaceModel->getKey())->whereIn('id', $data['ip_ids'])->where('is_active', true)
            ->whereHas('server', fn (Builder $query) => $query->where('is_active', true)->where('is_installed', true))->with('server')->get();
        if ($ips->count() !== count(array_unique($data['ip_ids']))) {
            throw ValidationException::withMessages(['ip_ids' => 'Choose active IPs from installed servers in this workspace.']);
        }

        $servers = $ips->groupBy('managed_server_id')->map(fn ($serverIps) => $serverIps->first()->server);

        return $this->restoreVmtaServers($request, $workspaceModel, $servers, $remote);
    }

    public function restoreVmtaV2(Request $request, string $workspace, PmtaRemoteConfiguration $remote): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $data = $request->validate(['server_id' => ['required', 'uuid']]);
        $server = $this->serverFor($workspaceModel, (string) $data['server_id']);

        return $this->restoreVmtaServers($request, $workspaceModel, [$server], $remote);
    }

    /**
     * @param  iterable<ManagedServer>  $servers
     */
    private function restoreVmtaServers(Request $request, Workspace $workspace, iterable $servers, PmtaRemoteConfiguration $remote): JsonResponse
    {
        $results = [];
        foreach ($servers as $server) {
            $file = 'vmta_'.$request->user()->getKey();
            try {
                // DMS removes the selected user's local rows before remote cleanup.
                // Preserve that failure ordering: a later SSH error does not restore them.
                PmtaVirtualMta::query()->where('workspace_id', $workspace->getKey())->where('managed_server_id', $server->getKey())->where('created_by', $request->user()->getKey())->delete();
                $session = $remote->open($server);
                $path = escapeshellarg('/etc/pmta/conf/'.$file);
                // DMS ignores command output/false results and reports success unless SSH setup throws.
                $session->exec('rm -rf '.$path);
                $session->exec('pmta reload');
                $results[] = ['server' => $server->name, 'success' => true, 'message' => 'Restored Successfully'];
            } catch (Throwable $exception) {
                $results[] = ['server' => $server->name, 'success' => false, 'message' => $exception->getMessage()];
            }
        }

        return response()->json(['success' => collect($results)->every(fn (array $row): bool => $row['success']), 'results' => $results]);
    }

    public function deleteVmtaRows(Request $request, string $workspace): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['required', 'integer', 'distinct']]);
        $deleted = PmtaVirtualMta::query()->where('workspace_id', $workspaceModel->getKey())->where('created_by', $request->user()->getKey())->whereIn('id', $data['ids'])->delete();

        return response()->json(['success' => $deleted > 0, 'message' => $deleted.' VMTA record(s) removed.']);
    }

    public function smtp(Request $request, string $workspace): View
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);

        return view('console.pmta.smtp', [
            'workspace' => $workspaceModel, 'servers' => $this->servers($workspaceModel),
            'ips' => ManagedServerIp::query()->where('workspace_id', $workspaceModel->getKey())->where('is_active', true)
                ->whereHas('server', fn (Builder $query) => $query->where('is_active', true)->where('is_installed', true))
                ->with('server:id,name')->orderBy('ip')->get(),
        ]);
    }

    public function smtpRows(Request $request, string $workspace): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $rows = PmtaSmtpConfig::query()->where('workspace_id', $workspaceModel->getKey())->where('created_by', $request->user()->getKey())->with('server:id,name')->orderByDesc('id')->get()
            ->map(fn (PmtaSmtpConfig $row): array => ['id' => $row->getKey(), 'ip' => $row->ip, 'dns' => $row->dns, 'username' => $row->username, 'created_at' => $row->created_at?->toDateTimeString(), 'is_active' => $row->is_active]);

        return response()->json(['data' => $rows]);
    }

    public function pushSmtp(Request $request, string $workspace, PmtaRemoteConfiguration $remote, PmtaSmtpConfigBuilder $builder, PmtaAuditLogger $audit, DmsBulkInstallPrivateAssetReader $assets): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $data = $request->validate([
            'ip_ids' => ['required', 'array', 'min:1'], 'ip_ids.*' => ['required', 'integer'],
            'smtp' => ['required', 'array', 'min:1'], 'smtp.*' => ['required', 'array', 'min:2'],
            'smtp.*.*' => ['present', 'string', 'max:500', 'regex:/\A[^\r\n\x00]+\z/'], 'google_script' => ['nullable', 'boolean'],
        ]);
        $googleScript = (bool) ($data['google_script'] ?? false);
        $googleScriptBinary = null;
        $googleScriptService = null;
        $smtpRows = [];
        foreach ($data['smtp'] as $index => $row) {
            $row = array_map('trim', $row);
            $requiredColumns = $googleScript ? 2 : 4;
            if (count($row) < $requiredColumns || count(array_filter($row, static fn (string $value): bool => $value !== '')) !== count($row)) {
                continue;
            }
            if ($googleScript) {
                if (count($row) !== 2) {
                    continue;
                }
                $smtpRows[] = [$row[0], $row[1], '', '25', $row[4] ?? ''];

                continue;
            }
            if (preg_match('/\A[A-Za-z0-9:._-]+\z/', $row[2]) !== 1 || filter_var($row[3], FILTER_VALIDATE_INT) === false || (int) $row[3] < 1 || (int) $row[3] > 65535) {
                throw ValidationException::withMessages(["smtp.{$index}" => 'Use username,password,route,port and an optional domain.']);
            }
            if (isset($row[4]) && preg_match('/\A[A-Za-z0-9:._-]+\z/', $row[4]) !== 1) {
                throw ValidationException::withMessages(["smtp.{$index}" => 'The optional domain contains unsupported characters.']);
            }
            $smtpRows[] = [$row[0], $row[1], $row[2], (string) $row[3], $row[4] ?? ''];
        }
        $smtpRows = array_values(array_unique($smtpRows, SORT_REGULAR));
        if ($smtpRows === []) {
            throw ValidationException::withMessages(['smtp' => $googleScript ? 'Enter username,token rows.' : 'Enter username,password,route,port rows.']);
        }
        if ($googleScript) {
            $assetRoot = config('infrastructure.install_assets_directory');
            if (! is_string($assetRoot) || $assetRoot === '') {
                throw ValidationException::withMessages(['google_script' => 'Google Script runtime assets are unavailable in this AlphaLink installation.']);
            }
            try {
                $googleScriptBinary = $assets->read($assetRoot, 'install/gs');
                $googleScriptService = $assets->read($assetRoot, 'install/gs.service');
            } catch (Throwable) {
                throw ValidationException::withMessages(['google_script' => 'Google Script runtime assets are unavailable in this AlphaLink installation.']);
            }
        }
        $ips = ManagedServerIp::query()->where('workspace_id', $workspaceModel->getKey())->whereIn('id', $data['ip_ids'])->where('is_active', true)
            ->whereHas('server', fn (Builder $query) => $query->where('is_active', true)->where('is_installed', true))->with('server')->get();
        if ($ips->count() !== count(array_unique($data['ip_ids']))) {
            throw ValidationException::withMessages(['ip_ids' => 'Choose active IPs from installed servers in this workspace.']);
        }
        $results = [];
        foreach ($ips->groupBy('managed_server_id') as $serverIps) {
            $server = $serverIps->first()->server;
            try {
                $count = DB::transaction(function () use ($workspaceModel, $request, $server, $serverIps, $smtpRows, $googleScript, $googleScriptBinary, $googleScriptService, $remote, $builder): int {
                    $contents = '';
                    foreach ($serverIps as $ip) {
                        foreach ($smtpRows as [$username, $password, $host, $port, $dns]) {
                            $row = PmtaSmtpConfig::query()->create([
                                'workspace_id' => $workspaceModel->getKey(), 'managed_server_id' => $server->getKey(), 'managed_server_ip_id' => $ip->getKey(),
                                'created_by' => $request->user()->getKey(), 'ip' => $ip->ip, 'dns' => $dns ?: $ip->dns,
                                'username' => $username, 'password' => $password, 'host' => $host,
                                'port' => $port, 'google_script' => $googleScript, 'is_active' => true,
                            ]);
                            $contents .= $builder->build($row, (int) ($server->install_parameters['pmta_version'] ?? 40));
                        }
                    }
                    if ($googleScript && is_string($googleScriptBinary) && is_string($googleScriptService)) {
                        $remote->installGoogleScript($server, $googleScriptBinary, $googleScriptService);
                    }
                    $file = 'smtp_'.$request->user()->getKey();
                    $existing = in_array($file, $remote->listFiles($server), true) ? $remote->readFile($server, $file) : '';
                    $remote->pushFile($server, $file, rtrim($existing)."\n".$contents);
                    $this->recordConfig($workspaceModel, $request->user(), $server, $file, 'smtp_push', $contents, 'SMTP configuration pushed and reloaded.', true);

                    return $serverIps->count() * count($smtpRows);
                });
                $audit->log($workspaceModel, $request->user(), $server, 'smtp.push', $count.' SMTP routes pushed.', $request->attributes->get('request_id'));
                $results[] = ['server' => $server->name, 'success' => true, 'count' => $count, 'message' => 'SMTP configuration pushed.'];
            } catch (Throwable $exception) {
                $results[] = ['server' => $server->name, 'success' => false, 'message' => $exception->getMessage()];
            }
        }

        return response()->json(['success' => collect($results)->every(fn (array $row): bool => $row['success']), 'results' => $results]);
    }

    public function restoreSmtp(Request $request, string $workspace, PmtaRemoteConfiguration $remote): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $data = $request->validate(['ip_ids' => ['required', 'array', 'min:1'], 'ip_ids.*' => ['required', 'integer']]);
        $ips = ManagedServerIp::query()->where('workspace_id', $workspaceModel->getKey())->whereIn('id', $data['ip_ids'])->where('is_active', true)
            ->whereHas('server', fn (Builder $query) => $query->where('is_active', true)->where('is_installed', true))->with('server')->get();
        if ($ips->count() !== count(array_unique($data['ip_ids']))) {
            throw ValidationException::withMessages(['ip_ids' => 'Choose active IPs from installed servers in this workspace.']);
        }
        $results = [];
        foreach ($ips->groupBy('managed_server_id')->map(fn ($serverIps) => $serverIps->first()->server) as $server) {
            $file = 'smtp_'.$request->user()->getKey();
            try {
                PmtaSmtpConfig::query()->where('workspace_id', $workspaceModel->getKey())->where('managed_server_id', $server->getKey())->where('created_by', $request->user()->getKey())->delete();
                $session = $remote->open($server);
                $session->exec('rm -rf /etc/pmta/conf/'.$file);
                $session->exec('pmta reload');
                $results[] = ['server' => $server->name, 'success' => true, 'message' => 'Restored Successfully'];
            } catch (Throwable $exception) {
                $results[] = ['server' => $server->name, 'success' => false, 'message' => $exception->getMessage()];
            }
        }

        return response()->json(['success' => collect($results)->every(fn (array $row): bool => $row['success']), 'results' => $results]);
    }

    public function deleteSmtpRows(Request $request, string $workspace): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['required', 'integer', 'distinct']]);
        $deleted = PmtaSmtpConfig::query()->where('workspace_id', $workspaceModel->getKey())->where('created_by', $request->user()->getKey())->whereIn('id', $data['ids'])->delete();

        return response()->json(['success' => $deleted > 0, 'message' => $deleted.' SMTP record(s) removed.']);
    }

    public function errors(Request $request, string $workspace): View
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);

        return view('console.pmta.errors', ['workspace' => $workspaceModel, 'servers' => $this->servers($workspaceModel)]);
    }

    public function bounces(Request $request, string $workspace, PmtaRemoteConfiguration $remote, PmtaBounceLogReader $reader): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $data = $request->validate([
            'server_ids' => ['required', 'array', 'min:1'], 'server_ids.*' => ['required', 'uuid', 'distinct'],
            'emails' => ['nullable', 'array'], 'emails.*' => ['required', 'string'],
        ]);
        ini_set('max_execution_time', '900');
        $results = [];
        foreach ($this->assertServers($workspaceModel, array_map('strval', $data['server_ids'])) as $server) {
            try {
                array_push($results, ...$reader->read($remote->open($server), $server, $data['emails'] ?? []));
            } catch (Throwable $exception) {
                $results[] = ['server' => $server->name, 'error' => $exception->getMessage()];
            }
        }

        return response()->json(['data' => $results]);
    }

    public function configHistory(Request $request, string $workspace): View|JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        if (! $request->wantsJson()) {
            return view('console.pmta.config-history', ['workspace' => $workspaceModel, 'servers' => $this->servers($workspaceModel)]);
        }
        $query = PmtaConfigHistory::query()->where('workspace_id', $workspaceModel->getKey())->with('server:id,name', 'actor:id,username');
        $serverId = (string) $request->query('server', '0');
        if ($serverId !== '' && $serverId !== '0') {
            $query->where('managed_server_id', $serverId);
        }
        $recordsTotal = (clone $query)->count();
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $pattern = '%'.$search.'%';
                $searchQuery->where('id', 'like', $pattern)
                    ->orWhere('file', 'like', $pattern)
                    ->orWhere('action', 'like', $pattern)
                    ->orWhere('result', 'like', $pattern)
                    ->orWhereHas('server', fn (Builder $serverQuery) => $serverQuery->where('name', 'like', $pattern))
                    ->orWhereHas('actor', fn (Builder $actorQuery) => $actorQuery->where('username', 'like', $pattern));
            });
        }
        $recordsFiltered = (clone $query)->count();
        $orderableColumns = [0 => 'id', 2 => 'action', 3 => 'file', 6 => 'created_at'];
        $orderColumn = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($orderableColumns[$orderColumn] ?? 'id', $orderDirection);
        $start = max(0, (int) $request->query('start', 0));
        $length = (int) $request->query('length', 50);
        $rowsQuery = $query->offset($start);
        if ($length !== -1) {
            $rowsQuery->limit(max(1, $length));
        }
        $rows = $rowsQuery->get()->map(fn (PmtaConfigHistory $row): array => [
            'id' => $row->getKey(), 'server' => $row->server?->name, 'action' => $row->action, 'file' => $row->file,
            'has_config' => $row->getRawOriginal('contents') !== null, 'user' => $row->actor?->username, 'created_at' => $row->created_at?->toDateTimeString(),
        ]);

        return response()->json([
            'draw' => (int) $request->query('draw', 0), 'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered, 'data' => $rows,
        ]);
    }

    public function configHistoryContents(Request $request, string $workspace, int $historyId): JsonResponse
    {
        $workspaceModel = $this->workspaceFor($request, $workspace);
        $history = PmtaConfigHistory::query()->where('workspace_id', $workspaceModel->getKey())->findOrFail($historyId);

        return response()->json(['contents' => $history->contents]);
    }

    /** @return Collection<int, ManagedServer> */
    private function servers(Workspace $workspace): Collection
    {
        return $workspace->managedServers()->where('is_active', true)->where('is_installed', true)->orderBy('name')->get();
    }

    private function createCampaignVmta(Workspace $workspace, User $user, PmtaVirtualMta $vmta, ManagedServerIp $ip, ?string $catalogDns = null): ManagedServerMta
    {
        return ManagedServerMta::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $vmta->managed_server_id,
            'managed_server_ip_id' => $ip->getKey(),
            'ip' => $vmta->ip,
            'dns' => $catalogDns ?? $vmta->domain,
            'ip_type' => $ip->type,
            'mta_type' => 'vmta',
            'name' => $vmta->name,
            'pmta_virtual_mta_id' => $vmta->getKey(),
            'is_active' => $vmta->is_active,
            'user_id' => $user->getKey(),
        ]);
    }

    private function workspaceFor(Request $request, string $workspaceId): Workspace
    {
        return $request->user()->workspaces()->whereKey($workspaceId)->firstOrFail();
    }

    private function serverFor(Workspace $workspace, string $serverId): ManagedServer
    {
        return $workspace->managedServers()->whereKey($serverId)->where('is_active', true)->where('is_installed', true)->firstOrFail();
    }

    /** @param list<string> $serverIds @return \Illuminate\Database\Eloquent\Collection<int, ManagedServer> */
    private function assertServers(Workspace $workspace, array $serverIds): Collection
    {
        $servers = $workspace->managedServers()->whereIn('id', $serverIds)->where('is_active', true)->where('is_installed', true)->get();
        if ($servers->count() !== count(array_unique($serverIds))) {
            throw ValidationException::withMessages(['server_ids' => 'Select active installed servers from this workspace.']);
        }

        return $servers;
    }

    private function recordConfig(Workspace $workspace, User $actor, ManagedServer $server, string $file, string $action, ?string $contents, string $result, bool $succeeded): void
    {
        PmtaConfigHistory::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $server->getKey(), 'actor_user_id' => $actor->getKey(),
            'file' => $file, 'action' => $action, 'contents' => $contents, 'result' => $result, 'succeeded' => $succeeded,
        ]);
    }
}
