<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCampaignTestAddress;
use App\Services\Campaigns\DmsCampaignSendCatalog;
use App\Services\Campaigns\DmsGlobalTestReverseDnsResolver;
use App\Services\Campaigns\DmsGlobalTestRunner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WorkspaceGlobalTestController extends Controller
{
    private const ALLOWED_ROLES = ['owner', 'admin', 'operator'];

    private const DEFAULT_HEADER = "MIME-version: 1.0\nContent-type: text/html\nTo: [to]\nfrom: [rnd] <info@[domain]>\nSubject: [ip]_[rnda_5]_[domain]\nDate: [date]";

    public function index(Request $request, string $workspace, DmsCampaignSendCatalog $catalog): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $options = $catalog->options($workspace, $request->user(), (string) $workspace->pivot->role);
        $role = (string) $workspace->pivot->role;
        $headers = $workspace->campaignHeaders()
            ->when($role === 'operator', fn (Builder $query) => $query->where('created_by', $request->user()->getKey()))
            ->orderBy('name')
            ->get(['id', 'name']);
        $negatives = $workspace->campaignNegatives()
            ->where('created_by', $request->user()->getKey())
            ->orderBy('name')
            ->get(['id', 'name']);
        $savedAddresses = WorkspaceCampaignTestAddress::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $request->user()->getKey())
            ->orderBy('email')
            ->get(['id', 'email']);

        return view('console.global-tests.index', [
            'workspace' => $workspace,
            'servers' => $options['servers'],
            'pmtaAdminPort' => $options['settings']['pmta_admin_port'],
            'domains' => $options['campaign_domains'],
            'headers' => $headers,
            'negatives' => $negatives,
            'savedAddresses' => $savedAddresses,
            'defaultHeader' => self::DEFAULT_HEADER,
            'senderConfigured' => is_string(config('global-tests.sender_path'))
                && is_file((string) config('global-tests.sender_path'))
                && is_executable((string) config('global-tests.sender_path')),
        ]);
    }

    public function encode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'string' => ['required', 'string'],
            'schema' => ['required', 'string', Rule::in(['B', 'Q', 'HQ'])],
            'charset' => ['required', 'string', Rule::in(['UTF-8', 'UTF-7', 'ISO-8859-1', 'ISO-8859-15', 'windows-1252', 'US-ASCII', 'CP1256'])],
        ]);

        if ($data['schema'] === 'HQ') {
            $encoded = '=?'.$data['charset'].'?Q?'.strtoupper(implode('', array_map(
                static fn (string $character): string => '='.strtoupper(bin2hex($character)),
                str_split($data['string']),
            ))).'?=';

            return response()->json($encoded);
        }

        $encoded = iconv_mime_encode('', $data['string'], [
            'scheme' => $data['schema'],
            'input-charset' => 'UTF-8',
            'output-charset' => $data['charset'],
            'line-length' => 76,
            'line-break-chars' => "\r\n",
        ]);
        if ($encoded === false) {
            throw ValidationException::withMessages(['string' => 'This string cannot be encoded with the selected charset.']);
        }

        return response()->json(trim(str_replace(':', '', $encoded)));
    }

    public function catalog(Request $request, string $workspace, DmsCampaignSendCatalog $catalog): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $filters = $request->validate([
            'option' => ['sometimes', 'string', Rule::in(['ip', 'vmta', 'smtp'])],
            'type' => ['sometimes', 'string', Rule::in(['all', 'ipv4', 'ipv6'])],
            'servers' => ['sometimes', 'array', 'max:100'],
            'servers.*' => ['required', 'uuid', 'distinct'],
        ]);
        $options = $catalog->options(
            $workspace,
            $request->user(),
            (string) $workspace->pivot->role,
            [
                'route' => $filters['option'] ?? 'ip',
                'ip_type' => $filters['type'] ?? 'ipv4',
                'server_ids' => $filters['servers'] ?? [],
            ],
        );

        return response()->json([
            'servers' => $options['servers'],
            'ips' => $options['ips'],
        ]);
    }

    public function test(
        Request $request,
        string $workspace,
        DmsCampaignSendCatalog $catalog,
        DmsGlobalTestReverseDnsResolver $rdnsResolver,
        DmsGlobalTestRunner $runner,
    ): JsonResponse {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate([
            'return_path' => ['nullable', 'string'],
            'email_test' => ['required', 'array', 'min:1'],
            'email_test.*' => ['required', 'string', 'max:320'],
            'negative' => ['nullable', 'integer', 'min:1'],
            'header' => ['required', 'string'],
            'body' => ['nullable', 'string'],
            'option' => ['required', 'string', Rule::in(['ip', 'vmta', 'smtp'])],
            'type' => ['required', 'string', Rule::in(['all', 'ipv4', 'ipv6'])],
            'servers' => ['required', 'array', 'min:1', 'max:100'],
            'servers.*' => ['required', 'uuid', 'distinct'],
            'ips' => ['required', 'array', 'min:1', 'max:10000'],
            'ips.*' => ['required', 'integer', 'min:1', 'distinct'],
            'placeholder_combination' => ['nullable', 'boolean'],
            'placeholder_all_combination' => ['nullable', 'boolean'],
            'placeholders' => ['nullable', 'array', 'min:1'],
            'placeholders.*.data' => ['required_with:placeholders', 'array'],
            'placeholders.*.data.*' => ['required', 'string', 'max:50000'],
            'placeholders.*.rotate_every' => ['required_with:placeholders', 'integer', 'min:1', 'max:2147483647'],
            'repeat' => ['required', 'integer', 'min:1', 'max:2147483647'],
        ]);

        $header = base64_decode($data['header'], true);
        $body = base64_decode((string) ($data['body'] ?? ''), true);
        if ($header === false || trim($header) === '') {
            throw ValidationException::withMessages(['header' => 'Enter a valid message header.']);
        }
        if ($body === false) {
            throw ValidationException::withMessages(['body' => 'The message body is not valid encoded text.']);
        }

        $role = (string) $workspace->pivot->role;
        $selectedOptions = $catalog->options($workspace, $request->user(), $role, [
            'route' => $data['option'],
            'ip_type' => $data['type'],
            'server_ids' => $data['servers'],
        ]);
        $eligibleServerIds = collect($selectedOptions['servers'])->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        if (array_diff($data['servers'], $eligibleServerIds) !== []) {
            throw ValidationException::withMessages(['servers' => 'Select active, installed servers available in this workspace.']);
        }

        $availableIps = collect($selectedOptions['ips'])->keyBy(static fn (array $ip): string => (string) $ip['id']);
        $selectedIpIds = array_map('strval', $data['ips']);
        if (array_diff($selectedIpIds, $availableIps->keys()->all()) !== []) {
            throw ValidationException::withMessages(['ips' => 'Select IPs or MTAs belonging to the selected servers.']);
        }

        if (! empty($data['negative']) && ! $workspace->campaignNegatives()
            ->where('created_by', $request->user()->getKey())
            ->whereKey($data['negative'])
            ->exists()) {
            throw ValidationException::withMessages(['negative' => 'Select one of your saved negative lists.']);
        }

        $selectedIps = collect($selectedIpIds)->map(fn (string $id): array => $availableIps->get($id))->values();
        if (! $runner->isConfigured()) {
            return response()->json([
                'data' => 'Could Not Send The AlphaLink Global Test sender is not configured or is not executable.',
            ], 503);
        }

        $configuration = $this->senderConfiguration($workspace, $request->user(), $data, $selectedIps, $rdnsResolver);
        $result = $runner->run($configuration);
        if ($result['output'] !== '') {
            $log = $workspace->globalTestLogs()->create([
                'actor_user_id' => $request->user()->getKey(),
                'data' => [
                    'return_path' => $data['return_path'] ?? null,
                    'email_tests' => $data['email_test'],
                    'negative' => $data['negative'] ?? null,
                    'header' => $data['header'],
                    'body' => $data['body'] ?? '',
                    'placeholder_combination' => (bool) ($data['placeholder_combination'] ?? false),
                    'placeholder_all_combination' => (bool) ($data['placeholder_all_combination'] ?? false),
                    'placeholders' => $data['placeholders'] ?? [],
                    'mta' => $data['option'],
                    'type' => $data['type'],
                    'servers' => $data['servers'],
                    'ips' => $data['ips'],
                ],
                'output' => $result['output'],
            ]);

            return response()->json(['data' => $result['output'], 'log_id' => $log->getKey()]);
        }

        $status = $result['configured'] ? 400 : 503;

        return response()->json(['data' => trim('Could Not Send '.$result['error'])], $status);
    }

    public function histories(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $filters = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        if (isset($filters['from'], $filters['to']) && $filters['to'] < $filters['from']) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }

        $role = (string) $workspace->pivot->role;
        $logs = $workspace->globalTestLogs()
            ->when($role === 'operator', fn (Builder $query) => $query->where('actor_user_id', $request->user()->getKey()))
            ->when(isset($filters['from']), fn (Builder $query) => $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay()))
            ->when(isset($filters['to']), fn (Builder $query) => $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay()))
            ->with('actor:id,name,email')
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('console.global-tests.histories', compact('workspace', 'logs', 'filters'));
    }

    public function history(Request $request, string $workspace, string $logId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $logQuery = $workspace->globalTestLogs();
        if ($workspace->pivot->role === 'operator') {
            $logQuery->where('actor_user_id', $request->user()->getKey());
        }
        $log = $logQuery->with('actor:id,name,email')->whereKey($logId)->firstOrFail();

        return view('console.global-tests.history', compact('workspace', 'log'));
    }

    private function workspaceFor(User $user, string $workspaceId): Workspace
    {
        $workspace = $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, self::ALLOWED_ROLES, true), 403);

        return $workspace;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, array<string, mixed>>  $selectedIps
     * @return array<string, mixed>
     */
    private function senderConfiguration(Workspace $workspace, User $user, array $data, Collection $selectedIps, DmsGlobalTestReverseDnsResolver $rdnsResolver): array
    {
        $servers = $workspace->managedServers()
            ->whereIn('id', $data['servers'])
            ->where('is_active', true)
            ->where('is_installed', true)
            ->with('ips')
            ->get()
            ->keyBy(fn (ManagedServer $server): string => (string) $server->getKey());
        $parentIps = ManagedServerIp::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('id', $selectedIps->pluck('managed_server_ip_id')->filter()->unique())
            ->get(['id', 'sending_domain_id'])
            ->keyBy(fn (ManagedServerIp $ip): string => (string) $ip->getKey());
        $serverPayloads = [];

        foreach ($selectedIps as $selectedIp) {
            $serverId = (string) $selectedIp['server_id'];
            $server = $servers->get($serverId);
            if (! $server) {
                continue;
            }
            $domainId = $selectedIp['sending_domain_id']
                ?? $parentIps->get((string) ($selectedIp['managed_server_ip_id'] ?? ''))?->sending_domain_id;
            $ip = (string) $selectedIp['ip'];
            $domain = (string) ($selectedIp['dns'] ?? '');
            $username = (string) ($selectedIp['username'] ?? '');
            $vmta = $data['option'] === 'ip'
                ? 'mta-'.$ip
                : $data['option'].'-'.$ip.'-'.$selectedIp['id'].($data['option'] === 'vmta' && $username !== '' ? '-'.$username : '');
            $serverPayloads[$serverId] ??= [
                'id' => $server->getKey(),
                'name' => $server->name.$server->getKey(),
                'main_ip' => $server->ip_address,
                'main_domain' => $server->ips->firstWhere('ip', $server->ip_address)?->dns ?? 'NA',
                'main_port' => (int) config('global-tests.pmta_port', 25),
                'ips' => [],
            ];
            $serverPayloads[$serverId]['ips'][] = [
                'ip' => $ip,
                'domain' => $domain,
                'rdns' => $rdnsResolver->resolve($ip, $domain !== '' ? $domain : 'NA'),
                'idi' => $selectedIp['managed_server_ip_id'] ?? $selectedIp['id'],
                'idd' => $data['option'] === 'ip' ? $domainId : 0,
                'ids' => $server->getKey(),
                'vmta' => $vmta,
                'username' => $username,
            ];
        }

        return [
            'userId' => $user->getKey(),
            'workspaceId' => $workspace->getKey(),
            'return_path' => $data['return_path'] ?? null,
            'email_tests' => $data['email_test'],
            'header' => $data['header'],
            'body' => $data['body'] ?? '',
            'servers' => array_values($serverPayloads),
            'repeat' => (int) $data['repeat'],
            'placeholder_combination' => (bool) ($data['placeholder_combination'] ?? false),
            'placeholder_all_combination' => (bool) ($data['placeholder_all_combination'] ?? false),
            'placeholders' => $data['placeholders'] ?? [],
            'negative_id' => $data['negative'] ?? null,
            'artisan' => base_path('artisan'),
        ];
    }
}
