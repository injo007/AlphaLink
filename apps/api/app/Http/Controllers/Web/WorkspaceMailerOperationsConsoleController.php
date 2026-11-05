<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\PrepareAndStartDropSend;
use App\Models\AudienceList;
use App\Models\Campaign;
use App\Models\CampaignSendRun;
use App\Models\ContactListMembership;
use App\Models\Isp;
use App\Models\ManagedServer;
use App\Models\User;
use App\Models\WarmupSendRun;
use App\Models\Workspace;
use App\Models\WorkspaceCampaignTestAddress;
use App\Models\WorkspaceSeedList;
use App\Services\Campaigns\AlphaLinkCampaignSendCatalog;
use App\Services\Campaigns\PrepareWarmupSendRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WorkspaceMailerOperationsConsoleController extends Controller
{
    private const ALLOWED_ROLES = ['owner', 'admin', 'operator'];

    private const WARMUP_SEND_TYPES = ['delay', 'delayp', 'delayps', 'hs', 'hsp', 'hsps', 'pickup', 'pickups'];

    private const DROP_SEND_TYPES = ['delay', 'delayp', 'hs', 'hsp', 'pickup'];

    public function drops(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);

        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:160'],
            'per_page' => ['sometimes', 'integer', 'in:25,50,100'],
        ]);
        $perPage = (int) ($filters['per_page'] ?? 50);
        $drafts = $workspace->campaigns()
            ->with('latestRevision')
            ->where('created_by', $request->user()->getKey())
            ->where('kind', 'drop')
            ->whereIn('status', ['draft', 'preparing_snapshot', 'snapshot_failed'])
            ->where('is_active', true)
            ->when(filled($filters['q'] ?? null), fn (Builder $query) => $query->where('name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']).'%'))
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
        $pageRows = $drafts->getCollection();
        $payloads = $pageRows->mapWithKeys(fn (Campaign $campaign): array => [(string) $campaign->getKey() => (array) ($campaign->latestRevision?->payload ?? [])]);
        $ispIds = $payloads->map(fn (array $payload): ?int => isset($payload['send_setup']['isp_id']) ? (int) $payload['send_setup']['isp_id'] : (isset($payload['isp_id']) ? (int) $payload['isp_id'] : null))->filter()->unique()->values();
        $ispNames = Isp::query()->where('workspace_id', $workspace->getKey())->whereIn('id', $ispIds)->pluck('name', 'id');
        $latestSendRuns = CampaignSendRun::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('campaign_id', $pageRows->modelKeys())
            ->orderByDesc('created_at')
            ->get()
            ->unique('campaign_id')
            ->keyBy(fn (CampaignSendRun $run): string => (string) $run->campaign_id);
        $pageRows->each(function (Campaign $campaign) use ($payloads, $ispNames, $latestSendRuns): void {
            $payload = $payloads->get((string) $campaign->getKey(), []);
            $setup = (array) ($payload['send_setup'] ?? []);
            $ispId = $setup['isp_id'] ?? $payload['isp_id'] ?? null;
            $countries = (array) ($payload['countries'] ?? []);
            if ($countries === [] && filled($setup['country'] ?? null)) {
                $countries = [(string) $setup['country']];
            }

            $campaign->setAttribute('drop_isp_name', $ispNames->get((int) $ispId, '—'));
            $campaign->setAttribute('drop_list_count', count($payload['list_ids'] ?? []));
            $campaign->setAttribute('drop_countries', collect($countries)->map(fn (string $country): string => strtoupper($country))->unique()->implode(', '));
            $campaign->setAttribute('drop_config', $setup);
            $latestRun = $latestSendRuns->get((string) $campaign->getKey());
            $campaign->setAttribute('drop_send_run', $latestRun && (string) $latestRun->revision_id === (string) $campaign->latestRevision?->getKey() ? $latestRun : null);
        });

        return view('console.drops.drafts.index', [
            'workspace' => $workspace,
            'drafts' => $drafts,
            'query' => (string) ($filters['q'] ?? ''),
            'perPage' => $perPage,
            'createUrl' => route('console.drops.drafts.create', $workspace->getKey()),
        ]);
    }

    public function createDrop(Request $request, string $workspace, AlphaLinkCampaignSendCatalog $catalog): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);

        return $this->draftForm($workspace, $request->user(), 'drop', null, $request, $catalog);
    }

    public function storeDrop(Request $request, string $workspace, AlphaLinkCampaignSendCatalog $catalog): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $content = $this->validatedDropContent($request, $workspace, $catalog);

        return $this->storeDraft($request, $workspace, 'drop', $content);
    }

    public function editDrop(Request $request, string $workspace, string $campaign, AlphaLinkCampaignSendCatalog $catalog): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $draft = $this->draftFor($request->user(), $workspace, $campaign, 'drop');

        return $this->draftForm($workspace, $request->user(), 'drop', $draft, $request, $catalog);
    }

    public function updateDrop(Request $request, string $workspace, string $campaign, AlphaLinkCampaignSendCatalog $catalog): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $draft = $this->draftFor($request->user(), $workspace, $campaign, 'drop');
        $content = $this->validatedDropContent($request, $workspace, $catalog);

        return $this->updateDraft($request, $workspace, $draft, $content);
    }

    public function sendDrop(Request $request, string $workspace, string $campaign, AlphaLinkCampaignSendCatalog $catalog): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $draft = $workspace->campaigns()
            ->with('latestRevision')
            ->where('created_by', $request->user()->getKey())
            ->where('kind', 'drop')
            ->where('is_active', true)
            ->whereIn('status', ['draft', 'snapshot_failed', 'preparing_snapshot'])
            ->whereKey($campaign)
            ->firstOrFail();
        $revision = $draft->latestRevision;
        abort_unless($revision && is_array($revision->payload['send_setup'] ?? null), 409, 'Save the Drop setup before sending.');
        $setup = (array) $revision->payload['send_setup'];
        abort_if(empty($setup['offer_id']), 422, 'Select an active Offer before sending this Drop.');
        abort_if(empty($setup['audience_segments']), 422, 'Select at least one audience list segment before sending this Drop.');
        $catalog->validateRevisionRouting(
            $workspace,
            $request->user(),
            (string) $workspace->pivot->role,
            (string) ($setup['route_option'] ?? ''),
            (string) ($setup['ip_type'] ?? ''),
            array_map('strval', (array) ($setup['server_ids'] ?? [])),
            array_map('strval', (array) ($setup['ip_ids'] ?? [])),
        );

        $shouldDispatch = DB::transaction(function () use ($workspace, $draft): bool {
            $locked = Campaign::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereKey($draft->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->status === 'preparing_snapshot') {
                return false;
            }
            abort_unless(in_array($locked->status, ['draft', 'snapshot_failed'], true), 409, 'This Drop is not ready to send.');
            $activeRun = CampaignSendRun::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('campaign_id', $draft->getKey())
                ->whereIn('state', ['planned', 'running'])
                ->exists();
            if ($activeRun) {
                return false;
            }
            $locked->forceFill([
                'status' => 'preparing_snapshot',
                'snapshot_build_stage' => 'queued',
                'snapshot_build_percent' => 0,
                'snapshot_build_processed' => 0,
                'snapshot_build_total' => null,
                'snapshot_build_error' => null,
                'snapshot_build_updated_at' => now(),
            ])->save();

            return true;
        }, attempts: 3);

        if ($shouldDispatch) {
            PrepareAndStartDropSend::dispatch(
                (string) $workspace->getKey(),
                (string) $draft->getKey(),
                (string) $revision->getKey(),
                (int) $request->user()->getKey(),
                (string) $data['idempotency_key'],
            )->afterCommit();
        }

        return redirect()->route('console.drops.drafts.index', $workspace->getKey())
            ->with('status', $shouldDispatch
                ? 'Drop audience preparation queued. Sending will start automatically when its snapshot and PMTA routes pass validation.'
                : 'Drop audience preparation or sending is already in progress. Refresh for the latest status.');
    }

    public function stopDrop(Request $request, string $workspace, string $campaign, string $run): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $sendRun = CampaignSendRun::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('campaign_id', $campaign)
            ->whereHas('campaign', fn ($query) => $query->where('created_by', $request->user()->getKey())->where('kind', 'drop'))
            ->whereKey($run)
            ->firstOrFail();
        if (in_array($sendRun->state, ['planned', 'running'], true)) {
            $sendRun->state = 'stopped';
            $sendRun->completed_at = now();
            $sendRun->save();
        }

        return redirect()->route('console.drops.drafts.index', $workspace->getKey())
            ->with('status', 'Drop send stopped. A message already in progress may finish; no new recipients will be started.');
    }

    public function warmups(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $filters = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:from'],
            'q' => ['sometimes', 'nullable', 'string', 'max:160'],
            'per_page' => ['sometimes', 'integer', 'in:25,50,100'],
        ]);
        $from = Carbon::parse($filters['from'] ?? now()->subDays(30)->toDateString())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now()->toDateString())->endOfDay();
        $perPage = (int) ($filters['per_page'] ?? 50);
        $drafts = $workspace->campaigns()
            ->with('latestRevision')
            ->where('created_by', $request->user()->getKey())
            ->where('kind', 'warmup')
            ->where('status', 'draft')
            ->where('is_active', true)
            ->whereBetween('created_at', [$from, $to])
            ->when(filled($filters['q'] ?? null), fn (Builder $query) => $query->where('name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']).'%'))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
        $pageRows = $drafts->getCollection();
        $payloads = $pageRows->mapWithKeys(fn (Campaign $campaign): array => [(string) $campaign->getKey() => (array) ($campaign->latestRevision?->payload ?? [])]);
        $serverIds = $payloads->flatMap(fn (array $payload): array => (array) ($payload['send_setup']['server_ids'] ?? []))->unique()->values();
        $serverNames = ManagedServer::query()->where('workspace_id', $workspace->getKey())->whereIn('id', $serverIds)->pluck('name', 'id');
        $seedListIds = $payloads->flatMap(fn (array $payload): array => (array) ($payload['seed_list_ids'] ?? []))->map(fn ($id): int => (int) $id)->unique()->values();
        $seedCounts = WorkspaceSeedList::query()->where('workspace_id', $workspace->getKey())->whereIn('id', $seedListIds)->withCount('emails')->get(['id'])->keyBy('id');
        $warmupRuns = WarmupSendRun::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('campaign_id', $pageRows->modelKeys())
            ->orderByDesc('created_at')
            ->get()
            ->unique('campaign_id')
            ->keyBy(fn (WarmupSendRun $run): string => (string) $run->campaign_id);
        $activeSends = WarmupSendRun::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('campaign_id', $workspace->campaigns()->where('created_by', $request->user()->getKey())->where('kind', 'warmup')->select('id'))
            ->whereIn('state', ['planned', 'running'])
            ->count();
        $pageRows->each(function (Campaign $campaign) use ($payloads, $serverNames, $seedCounts, $warmupRuns): void {
            $payload = $payloads->get((string) $campaign->getKey(), []);
            $setup = (array) ($payload['send_setup'] ?? []);
            $campaign->setAttribute('warmup_config', $payload);
            $campaign->setAttribute('warmup_servers', collect($setup['server_ids'] ?? [])->map(fn ($id): string => (string) ($serverNames->get($id) ?? 'Unknown server'))->values());
            $campaign->setAttribute('warmup_seed_count', collect($payload['seed_list_ids'] ?? [])->sum(fn ($id): int => (int) ($seedCounts->get((int) $id)?->emails_count ?? 0)));
            $campaign->setAttribute('warmup_seed_lists', count($payload['seed_list_ids'] ?? []));
            $campaign->setAttribute('warmup_send_run', $warmupRuns->get((string) $campaign->getKey()));
        });

        return view('console.warmups.index', [
            'workspace' => $workspace,
            'drafts' => $drafts,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'query' => (string) ($filters['q'] ?? ''),
            'perPage' => $perPage,
            'activeSends' => $activeSends,
            'createUrl' => route('console.warmups.create', $workspace->getKey()),
        ]);
    }

    public function createWarmup(Request $request, string $workspace, AlphaLinkCampaignSendCatalog $catalog): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);

        return $this->draftForm($workspace, $request->user(), 'warmup', null, $request, $catalog);
    }

    public function storeWarmup(Request $request, string $workspace, AlphaLinkCampaignSendCatalog $catalog): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $content = $this->validatedWarmupContent($request, $workspace, $catalog);

        return $this->storeDraft($request, $workspace, 'warmup', $content);
    }

    public function editWarmup(Request $request, string $workspace, string $campaign, AlphaLinkCampaignSendCatalog $catalog): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $draft = $this->draftFor($request->user(), $workspace, $campaign, 'warmup');

        return $this->draftForm($workspace, $request->user(), 'warmup', $draft, $request, $catalog);
    }

    public function updateWarmup(Request $request, string $workspace, string $campaign, AlphaLinkCampaignSendCatalog $catalog): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $draft = $this->draftFor($request->user(), $workspace, $campaign, 'warmup');
        $content = $this->validatedWarmupContent($request, $workspace, $catalog);

        return $this->updateDraft($request, $workspace, $draft, $content);
    }

    public function sendWarmup(Request $request, string $workspace, string $campaign, PrepareWarmupSendRun $sendRun): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $draft = $this->draftFor($request->user(), $workspace, $campaign, 'warmup');
        $run = $sendRun->create($workspace, $draft, $request->user(), (string) $data['idempotency_key']);

        return redirect()->route('console.warmups.index', $workspace->getKey())
            ->with('status', in_array($run->state, ['planned', 'running'], true)
                ? 'Warmup send queued or already running. Progress is shown in the Warmups register.'
                : 'Warmup send request saved. Review its latest state in the Warmups register.');
    }

    public function stopWarmup(Request $request, string $workspace, string $campaign, string $run): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $sendRun = WarmupSendRun::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('campaign_id', $campaign)
            ->whereHas('campaign', fn ($query) => $query->where('created_by', $request->user()->getKey()))
            ->whereKey($run)
            ->firstOrFail();
        abort_unless(in_array($sendRun->state, ['planned', 'running'], true), 409, 'This Warmup send has already finished.');
        $sendRun->state = 'stopped';
        $sendRun->completed_at = now();
        $sendRun->save();

        return redirect()->route('console.warmups.index', $workspace->getKey())
            ->with('status', 'Warmup send stopped. A message already in progress may finish; no new recipients will be started.');
    }

    public function warmupStatistics(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $filters = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:from'],
        ]);
        $from = Carbon::parse($filters['from'] ?? now()->subDays(29)->toDateString())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now()->toDateString())->endOfDay();
        $rows = DB::table('campaign_engagement_unique_actions as unique_actions')
            ->join('campaigns as campaigns', function ($join): void {
                $join->on('campaigns.id', '=', 'unique_actions.campaign_id')
                    ->on('campaigns.workspace_id', '=', 'unique_actions.workspace_id');
            })
            ->where('unique_actions.workspace_id', $workspace->getKey())
            ->where('campaigns.kind', 'warmup')
            ->where('campaigns.created_by', $request->user()->getKey())
            ->whereBetween('unique_actions.created_at', [$from, $to])
            ->select(['campaigns.id', 'campaigns.name'])
            ->selectRaw("SUM(CASE WHEN unique_actions.action = 'open' THEN 1 ELSE 0 END) AS opens")
            ->selectRaw("SUM(CASE WHEN unique_actions.action = 'click' THEN 1 ELSE 0 END) AS clicks")
            ->selectRaw("SUM(CASE WHEN unique_actions.action = 'lead' THEN 1 ELSE 0 END) AS leads")
            ->selectRaw("SUM(CASE WHEN unique_actions.action = 'unsubscribe' THEN 1 ELSE 0 END) AS unsubscribes")
            ->selectRaw("SUM(CASE WHEN unique_actions.action = 'bot' THEN 1 ELSE 0 END) AS bots")
            ->groupBy('campaigns.id', 'campaigns.name')
            ->orderBy('campaigns.name')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'opens' => (int) $row->opens,
                'clicks' => (int) $row->clicks,
                'leads' => (int) $row->leads,
                'unsubscribes' => (int) $row->unsubscribes,
                'bots' => (int) $row->bots,
            ]);

        return view('console.warmups.statistics', [
            'workspace' => $workspace,
            'rows' => $rows,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => [
                'opens' => $rows->sum('opens'),
                'clicks' => $rows->sum('clicks'),
                'leads' => $rows->sum('leads'),
                'unsubscribes' => $rows->sum('unsubscribes'),
                'bots' => $rows->sum('bots'),
            ],
        ]);
    }

    private function draftForm(Workspace $workspace, User $user, string $kind, ?Campaign $draft, Request $request, AlphaLinkCampaignSendCatalog $catalog): View
    {
        $operator = $workspace->pivot->role === 'operator';
        $seedLists = WorkspaceSeedList::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)
            ->when($operator, fn (Builder $query) => $query->where('user_id', $user->getKey()))
            ->withCount('emails')
            ->orderBy('name')
            ->get(['id', 'name', 'user_id']);
        $content = $draft?->latestRevision?->payload ?? [];
        $savedSetup = (array) ($content['send_setup'] ?? []);
        $filters = [
            'sponsor_id' => $request->query('sponsor_id', $savedSetup['sponsor_id'] ?? null),
            'offer_country' => $request->query('offer_country', $savedSetup['offer_country'] ?? 'ALL'),
            'isp_id' => $request->query('isp_id', $savedSetup['isp_id'] ?? ($content['isp_id'] ?? null)),
            // Load every Geo into the composer; its multi-select filters locally.
            'country' => $request->query('country', 'ALL'),
            'server_ids' => $request->query('server_ids', $savedSetup['server_ids'] ?? []),
            'route' => $request->query('route', $savedSetup['route_option'] ?? 'ip'),
            'ip_type' => $request->query('ip_type', $savedSetup['ip_type'] ?? 'all'),
        ];
        if (! is_array($filters['server_ids'])) {
            $filters['server_ids'] = [];
        }
        $sendOptions = $catalog->options($workspace, $user, (string) $workspace->pivot->role, $filters);
        $savedEmails = WorkspaceCampaignTestAddress::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())
            ->orderBy('email')
            ->get(['id', 'email']);
        $headers = $workspace->campaignHeaders()
            ->when(! $user->hasRole('share_headers'), fn ($query) => $query->where('created_by', $user->getKey()))
            ->orderBy('name')->get(['id', 'name']);
        $idempotencyKey = (string) Str::uuid();

        return view('console.mailer-drafts.form', [
            'workspace' => $workspace,
            'kind' => $kind,
            'draft' => $draft,
            'content' => $content,
            'idempotencyKey' => $idempotencyKey,
            'seedLists' => $seedLists,
            'sendOptions' => $sendOptions,
            'savedEmails' => $savedEmails,
            'headers' => $headers,
            'storeUrl' => route($kind === 'drop' ? 'console.drops.drafts.store' : 'console.warmups.store', $workspace->getKey()),
            'updateUrl' => $draft ? route($kind === 'drop' ? 'console.drops.drafts.update' : 'console.warmups.update', [$workspace->getKey(), $draft->getKey()]) : null,
            'indexUrl' => route($kind === 'drop' ? 'console.drops.drafts.index' : 'console.warmups.index', $workspace->getKey()),
            'seedListsUrl' => route('console.warmups.seeds.index', $workspace->getKey()),
            'sendUrl' => $draft ? route($kind === 'drop' ? 'console.drops.send' : 'console.warmups.send', [$workspace->getKey(), $draft->getKey()]) : null,
            'optionsUrl' => route('console.campaigns.send-options', $workspace->getKey()),
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedDropContent(Request $request, Workspace $workspace, AlphaLinkCampaignSendCatalog $catalog): array
    {
        $testAddressText = (string) $request->input('email_test_addresses_text', '');
        $testAddresses = preg_split('/[\r\n,;]+/', $testAddressText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $request->merge(['email_test_addresses' => array_values(array_unique(array_filter(array_map('trim', $testAddresses))))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'from' => ['nullable', 'string', 'max:320'],
            'subject' => ['required', 'string', 'max:998'],
            'from_values_text' => ['nullable', 'string', 'max:50000'],
            'subject_values_text' => ['nullable', 'string', 'max:50000'],
            'return_path' => ['nullable', 'string', 'max:320'],
            'isp_id' => ['nullable', 'integer', 'min:1'],
            'map_body' => ['sometimes', 'boolean'],
            'email_test_addresses' => ['nullable', 'array', 'max:50'],
            'email_test_addresses.*' => ['email:rfc', 'max:320'],
            'header' => ['nullable', 'string', 'max:50000'],
            'html_body' => ['required', 'string', 'max:1000000'],
            'send_setup' => ['sometimes', 'array'],
            'send_setup.sponsor_id' => ['nullable', 'integer', 'min:1'],
            'send_setup.offer_id' => ['nullable', 'integer', 'min:1'],
            'send_setup.offer_country' => ['nullable', 'string', 'max:8'],
            'send_setup.email_types' => ['sometimes', 'array', 'max:32'],
            'send_setup.email_types.*' => ['string', Rule::in(ContactListMembership::CAMPAIGN_TYPES), 'distinct'],
            'send_setup.negative_id' => ['nullable', 'integer', 'min:1'],
            'send_setup.domain' => ['nullable', 'string', 'max:253'],
            'send_setup.from_asset_ids' => ['sometimes', 'array', 'max:100'],
            'send_setup.from_asset_ids.*' => ['integer', 'min:1', 'distinct'],
            'send_setup.subject_asset_ids' => ['sometimes', 'array', 'max:100'],
            'send_setup.subject_asset_ids.*' => ['integer', 'min:1', 'distinct'],
            'send_setup.creative_asset_id' => ['nullable', 'integer', 'min:1'],
            'send_setup.isp_id' => ['nullable', 'integer', 'min:1'],
            'send_setup.country' => ['nullable', 'string', 'regex:/\A(?:ALL|[a-zA-Z]{2})\z/'],
            'send_setup.audience_segments' => ['sometimes', 'array', 'max:500'],
            'send_setup.audience_segments.*' => ['string', 'max:80', 'distinct', 'regex:/^[0-9a-f-]{36}_(fresh|delivery|open|click|lead|unsubscribe)$/i'],
            'send_setup.order_by' => ['nullable', 'in:default,asc,desc,random'],
            'send_setup.start_from' => ['nullable', 'integer', 'min:0'],
            'send_setup.route_option' => ['nullable', 'in:ip,vmta,smtp'],
            'send_setup.ip_type' => ['nullable', 'in:all,ipv4,ipv6'],
            'send_setup.server_ids' => ['sometimes', 'array', 'max:100'],
            'send_setup.server_ids.*' => ['uuid', 'distinct'],
            'send_setup.ip_ids' => ['sometimes', 'array', 'max:1000'],
            'send_setup.ip_ids.*' => ['string', 'max:80', 'distinct'],
            'send_setup.send_type' => ['nullable', Rule::in(self::DROP_SEND_TYPES)],
            'send_setup.total_send' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'send_setup.duplicate_data' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'send_setup.fraction_ip' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'send_setup.sleep_email' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'send_setup.sleep_ip' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'send_setup.test_after' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'send_setup.batch' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'send_setup.delay' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'send_setup.bounce_limit' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'list_ids' => ['nullable', 'array', 'max:100'],
            'list_ids.*' => ['uuid'],
            'countries' => ['nullable', 'array', 'max:250'],
            'countries.*' => ['string', 'size:2', 'alpha'],
            'idempotency_key' => ['required', 'uuid'],
            'source_revision_id' => ['nullable', 'uuid'],
        ]);
        $setup = (array) ($data['send_setup'] ?? []);
        $listIds = array_values(array_unique(array_map('strval', $data['list_ids'] ?? [])));
        foreach (($setup['audience_segments'] ?? []) as $segmentId) {
            if (preg_match('/\A([0-9a-f-]{36})_(?:fresh|delivery|open|click|lead|unsubscribe)\z/i', (string) $segmentId, $matches) === 1) {
                $listIds[] = strtolower($matches[1]);
            }
        }
        $listIds = array_values(array_unique($listIds));
        $availableListIds = AudienceList::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('status', 'active')
            ->when($workspace->pivot->role === 'operator', fn (Builder $query) => $query->whereJsonContains('allowed_users', (int) $request->user()->getKey()))
            ->whereIn('id', $listIds)
            ->pluck('id')->map('strval')->all();
        if (array_diff($listIds, $availableListIds) !== []) {
            throw ValidationException::withMessages(['list_ids' => 'Choose active lists available to this workspace member.']);
        }
        $selectedLists = AudienceList::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('id', $listIds)
            ->get(['id', 'isp_id', 'country']);
        $ispId = $setup['isp_id'] ?? $data['isp_id'] ?? null;
        if ($ispId !== null) {
            $setup['isp_id'] = (int) $ispId;
        }
        if ($ispId && $selectedLists->contains(fn (AudienceList $list): bool => (int) $list->isp_id !== (int) $ispId)) {
            throw ValidationException::withMessages(['list_ids' => 'Each selected audience list must belong to the selected ISP.']);
        }
        $selectedCountries = array_values(array_unique(array_map('strtoupper', $data['countries'] ?? [])));
        if (filled($setup['country'] ?? null) && strtoupper((string) $setup['country']) !== 'ALL') {
            $selectedCountries[] = strtoupper((string) $setup['country']);
            $selectedCountries = array_values(array_unique($selectedCountries));
        }
        $listCountries = $selectedLists->pluck('country')->filter()->map(fn (string $country): string => strtoupper($country))->unique()->values()->all();
        if ($selectedCountries !== [] && $listCountries !== [] && array_diff($selectedCountries, $listCountries) !== []) {
            throw ValidationException::withMessages(['countries' => 'Choose countries represented by the selected audience lists.']);
        }
        if ($ispId) {
            $ispAvailable = Isp::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('is_active', true)
                ->when($workspace->pivot->role === 'operator', fn (Builder $query) => $query->whereJsonContains('allowed_users', (int) $request->user()->getKey()))
                ->whereKey((int) $ispId)->exists();
            if (! $ispAvailable) {
                throw ValidationException::withMessages(['isp_id' => 'Choose an active ISP available to this workspace member.']);
            }
        }

        $sendSetup = $this->validatedDraftSendSetup($request, $workspace, $catalog, $setup, $selectedCountries, $listIds);

        return [
            'draft_type' => 'drop',
            'from' => trim((string) ($data['from'] ?? '')),
            'subject' => trim($data['subject']),
            'from_values' => $this->splitLines((string) ($data['from_values_text'] ?? '')),
            'subject_values' => $this->splitLines((string) ($data['subject_values_text'] ?? '')),
            'return_path' => trim((string) ($data['return_path'] ?? '[rnd]@[domain]')),
            'email_test_addresses' => array_values(array_unique($data['email_test_addresses'] ?? [])),
            'header' => (string) ($data['header'] ?? ''),
            'html_body' => $data['html_body'],
            'map_body' => (bool) ($data['map_body'] ?? false),
            'isp_id' => $ispId !== null ? (int) $ispId : null,
            'list_ids' => $listIds,
            'countries' => $selectedCountries,
            'send_setup' => $sendSetup,
        ];
    }

    /** @return array<string, mixed> */
    private function validatedWarmupContent(Request $request, Workspace $workspace, AlphaLinkCampaignSendCatalog $catalog): array
    {
        $testAddressText = (string) $request->input('email_test_addresses_text', '');
        $testAddresses = preg_split('/[\r\n,;]+/', $testAddressText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $testAddresses = array_filter(array_map('trim', $testAddresses), fn (string $address): bool => $address !== '');
        $request->merge(['email_test_addresses' => array_values(array_unique($testAddresses))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'return_path' => ['nullable', 'string', 'max:320'],
            'email_test_addresses' => ['nullable', 'array', 'max:50'],
            'email_test_addresses.*' => ['email:rfc', 'max:320'],
            'header' => ['required', 'string', 'max:50000'],
            'html_body' => ['required', 'string', 'max:1000000'],
            'seed_list_ids' => ['nullable', 'array', 'max:100'],
            'seed_list_ids.*' => ['integer', 'min:1'],
            'send_type' => ['required', Rule::in(self::WARMUP_SEND_TYPES)],
            'total_send' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'fraction_ip' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'sleep_email' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'sleep_ip' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'seed_every' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'bounce_limit' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'start_from' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'test_after' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'batch' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'seed_accounts_text' => ['nullable', 'string', 'max:1000000'],
            'map_body' => ['sometimes', 'boolean'],
            'sponsor_id' => ['nullable', 'integer', 'min:1'],
            'offer_country' => ['nullable', 'string', 'max:8'],
            'offer_id' => ['nullable', 'integer', 'min:1'],
            'domain' => ['nullable', 'string', 'max:253'],
            'negative_id' => ['nullable', 'integer', 'min:1'],
            'route_option' => ['nullable', 'in:ip,vmta,smtp'],
            'ip_type' => ['nullable', 'in:all,ipv4,ipv6'],
            'server_ids' => ['nullable', 'array', 'max:100'],
            'server_ids.*' => ['uuid', 'distinct'],
            'ip_ids' => ['nullable', 'array', 'max:1000'],
            'ip_ids.*' => ['string', 'max:80', 'distinct'],
            'placeholder_combination' => ['sometimes', 'boolean'],
            'placeholder_all_combination' => ['sometimes', 'boolean'],
            'placeholder_inputs' => ['sometimes', 'array', 'max:50'],
            'placeholder_inputs.*.data_text' => ['nullable', 'string', 'max:20000'],
            'placeholder_inputs.*.rotate_every' => ['required_with:placeholder_inputs', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
            'source_revision_id' => ['nullable', 'uuid'],
        ]);
        $seedListIds = array_values(array_unique(array_map('intval', $data['seed_list_ids'] ?? [])));
        $availableSeedListIds = WorkspaceSeedList::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)
            ->when($workspace->pivot->role === 'operator', fn (Builder $query) => $query->where('user_id', $request->user()->getKey()))
            ->whereIn('id', $seedListIds)
            ->pluck('id')->map('intval')->all();
        if (array_diff($seedListIds, $availableSeedListIds) !== []) {
            throw ValidationException::withMessages(['seed_list_ids' => 'Choose active seed lists available to this workspace member.']);
        }

        $sendSetup = $this->validatedDraftSendSetup($request, $workspace, $catalog, [
            'sponsor_id' => $data['sponsor_id'] ?? null,
            'offer_country' => $data['offer_country'] ?? 'ALL',
            'offer_id' => $data['offer_id'] ?? null,
            'negative_id' => $data['negative_id'] ?? null,
            'domain' => $data['domain'] ?? null,
            'route_option' => $data['route_option'] ?? 'ip',
            'ip_type' => $data['ip_type'] ?? 'all',
            'server_ids' => $data['server_ids'] ?? [],
            'ip_ids' => $data['ip_ids'] ?? [],
            'placeholder_combination' => $data['placeholder_combination'] ?? false,
            'placeholder_all_combination' => $data['placeholder_all_combination'] ?? false,
            'placeholder_inputs' => $data['placeholder_inputs'] ?? [],
        ], [], []);

        return [
            'draft_type' => 'warmup',
            'return_path' => trim((string) ($data['return_path'] ?? '[rnd]@[domain]')),
            'email_test_addresses' => array_values(array_unique($data['email_test_addresses'] ?? [])),
            'header' => $data['header'],
            'html_body' => $data['html_body'],
            'map_body' => (bool) ($data['map_body'] ?? false),
            'seed_list_ids' => $seedListIds,
            'send_type' => $data['send_type'],
            'total_send' => isset($data['total_send']) ? (int) $data['total_send'] : null,
            'fraction_ip' => isset($data['fraction_ip']) ? (int) $data['fraction_ip'] : null,
            'sleep_email' => isset($data['sleep_email']) ? (int) $data['sleep_email'] : null,
            'sleep_ip' => isset($data['sleep_ip']) ? (int) $data['sleep_ip'] : null,
            'seed_every' => isset($data['seed_every']) ? (int) $data['seed_every'] : null,
            'bounce_limit' => isset($data['bounce_limit']) ? (int) $data['bounce_limit'] : null,
            'start_from' => isset($data['start_from']) ? (int) $data['start_from'] : null,
            'test_after' => isset($data['test_after']) ? (int) $data['test_after'] : null,
            'batch' => isset($data['batch']) ? (int) $data['batch'] : null,
            'seed_accounts_text' => trim((string) ($data['seed_accounts_text'] ?? '')),
            'send_setup' => $sendSetup,
        ];
    }

    /** @return array<string, mixed> */
    private function validatedDraftSendSetup(Request $request, Workspace $workspace, AlphaLinkCampaignSendCatalog $catalog, array $setup, array $countries, array $listIds): array
    {
        $role = (string) $workspace->pivot->role;
        $sponsorId = filled($setup['sponsor_id'] ?? null) ? (int) $setup['sponsor_id'] : null;
        $offerId = filled($setup['offer_id'] ?? null) ? (int) $setup['offer_id'] : null;
        $ispId = filled($setup['isp_id'] ?? null) ? (int) $setup['isp_id'] : null;
        $country = strtoupper((string) ($setup['country'] ?? ($countries[0] ?? 'ALL')));
        $serverIds = array_values(array_unique(array_map('strval', $setup['server_ids'] ?? [])));
        $options = $catalog->options($workspace, $request->user(), $role, [
            'sponsor_id' => $sponsorId,
            'offer_country' => (string) ($setup['offer_country'] ?? 'ALL'),
            'isp_id' => $ispId,
            // Validate selected lists against all available geos, then enforce the
            // selected multi-country subset against the lists above.
            'country' => 'ALL',
            'server_ids' => $serverIds,
            'route' => (string) ($setup['route_option'] ?? 'ip'),
            'ip_type' => (string) ($setup['ip_type'] ?? 'all'),
        ]);
        if ($sponsorId !== null && ! collect($options['sponsors'])->contains(fn (array $row): bool => (int) $row['id'] === $sponsorId)) {
            throw ValidationException::withMessages(['send_setup.sponsor_id' => 'Choose an active sponsor available to this workspace.']);
        }
        $selectedOffer = $offerId === null ? null : collect($options['offers'])->first(fn (array $row): bool => (int) $row['id'] === $offerId);
        if ($offerId !== null && ! $selectedOffer) {
            throw ValidationException::withMessages(['send_setup.offer_id' => 'Choose an active offer from the selected sponsor and country.']);
        }
        $fromAssetIds = array_values(array_unique(array_map('intval', $setup['from_asset_ids'] ?? [])));
        $subjectAssetIds = array_values(array_unique(array_map('intval', $setup['subject_asset_ids'] ?? [])));
        $creativeAssetId = $setup['creative_asset_id'] ?? null;
        $offerAssets = ['from_asset_ids' => $fromAssetIds, 'subject_asset_ids' => $subjectAssetIds, 'creative_asset_id' => null, 'html_asset_id' => null];
        if ($offerId !== null) {
            $offerAssets = $catalog->validateRevisionOfferAssets($workspace, $offerId, $fromAssetIds, $subjectAssetIds, $creativeAssetId, null);
        } elseif ($fromAssetIds !== [] || $subjectAssetIds !== [] || filled($creativeAssetId)) {
            throw ValidationException::withMessages(['send_setup.offer_id' => 'Select an offer before selecting its From, Subject, or Creative values.']);
        }
        $ispExists = $ispId !== null && Isp::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)
            ->when($workspace->pivot->role === 'operator', fn (Builder $query) => $query->whereJsonContains('allowed_users', (int) $request->user()->getKey()))
            ->whereKey($ispId)
            ->exists();
        if ($ispId !== null && (! $ispExists || ($sponsorId !== null && ! collect($options['isps'])->contains(fn (array $row): bool => (int) $row['id'] === $ispId)))) {
            throw ValidationException::withMessages(['send_setup.isp_id' => 'Choose an ISP available to the selected sponsor and workspace member.']);
        }
        $negativeId = filled($setup['negative_id'] ?? null) ? (int) $setup['negative_id'] : null;
        if ($negativeId !== null && ! collect($options['negatives'])->contains(fn (array $row): bool => (int) $row['id'] === $negativeId)) {
            throw ValidationException::withMessages(['send_setup.negative_id' => 'Choose a negative file owned by this workspace member.']);
        }
        $segments = array_values(array_unique(array_map('strval', $setup['audience_segments'] ?? [])));
        $availableSegments = collect($options['segments'])->keyBy('id');
        foreach ($segments as $segment) {
            if (! $availableSegments->has($segment)) {
                throw ValidationException::withMessages(['send_setup.audience_segments' => 'One or more audience segments are empty, locked, or unavailable for the selected ISP and country.']);
            }
        }
        $availableServerIds = collect($options['servers'])->pluck('id')->map('strval')->all();
        if (array_diff($serverIds, $availableServerIds) !== []) {
            throw ValidationException::withMessages(['send_setup.server_ids' => 'Choose installed servers available to this workspace member.']);
        }
        $ipIds = array_values(array_unique(array_map('strval', $setup['ip_ids'] ?? [])));
        $availableIpIds = collect($options['ips'])->pluck('id')->map('strval')->all();
        if (array_diff($ipIds, $availableIpIds) !== []) {
            throw ValidationException::withMessages(['send_setup.ip_ids' => 'Choose IPs or MTAs available on the selected servers.']);
        }
        $domain = trim((string) ($setup['domain'] ?? ''));
        if ($domain !== '' && ! collect($options['campaign_domains'])->contains(fn (array $row): bool => hash_equals((string) $row['value'], $domain))) {
            throw ValidationException::withMessages(['domain' => 'Choose a domain available to this workspace and selected route.']);
        }
        $placeholderData = $request->validate([
            'placeholder_combination' => ['sometimes', 'boolean'],
            'placeholder_all_combination' => ['sometimes', 'boolean'],
            'placeholder_inputs' => ['sometimes', 'array', 'max:50'],
            'placeholder_inputs.*.data_text' => ['nullable', 'string', 'max:20000'],
            'placeholder_inputs.*.rotate_every' => ['required_with:placeholder_inputs', 'integer', 'min:1'],
            'send_setup.placeholder_combination' => ['sometimes', 'boolean'],
            'send_setup.placeholder_all_combination' => ['sometimes', 'boolean'],
            'send_setup.placeholder_inputs' => ['sometimes', 'array', 'max:50'],
            'send_setup.placeholder_inputs.*.data_text' => ['nullable', 'string', 'max:20000'],
            'send_setup.placeholder_inputs.*.rotate_every' => ['required_with:send_setup.placeholder_inputs', 'integer', 'min:1'],
        ]);
        $nestedPlaceholders = (array) ($placeholderData['send_setup'] ?? []);
        $combination = (bool) ($placeholderData['placeholder_combination'] ?? $nestedPlaceholders['placeholder_combination'] ?? $setup['placeholder_combination'] ?? false);
        $allCombination = (bool) ($placeholderData['placeholder_all_combination'] ?? $nestedPlaceholders['placeholder_all_combination'] ?? $setup['placeholder_all_combination'] ?? false);
        if ($combination && $allCombination) {
            throw ValidationException::withMessages(['placeholder_all_combination' => 'Only one placeholder combination mode can be enabled at a time.']);
        }
        $placeholderRows = $placeholderData['placeholder_inputs'] ?? $nestedPlaceholders['placeholder_inputs'] ?? $setup['placeholder_inputs'] ?? [];
        $placeholders = [];
        foreach ($placeholderRows as $row) {
            $values = $this->splitLines((string) ($row['data_text'] ?? ''));
            if ($values !== []) {
                $placeholders[] = ['data' => $values, 'rotate_every' => max(1, (int) ($row['rotate_every'] ?? 1))];
            }
        }

        return [
            'sponsor_id' => $sponsorId,
            'offer_id' => $offerId,
            'offer_country' => strtoupper((string) ($setup['offer_country'] ?? 'ALL')),
            'negative_id' => $negativeId,
            'domain' => $domain ?: null,
            'from_asset_ids' => $offerAssets['from_asset_ids'],
            'subject_asset_ids' => $offerAssets['subject_asset_ids'],
            'creative_asset_id' => $offerAssets['creative_asset_id'],
            'isp_id' => $ispId,
            'country' => $country,
            'countries' => array_values(array_unique(array_map('strtoupper', $countries))),
            'email_types' => array_values(array_unique(array_map('strval', $setup['email_types'] ?? []))),
            'audience_segments' => $segments,
            'audience_list_ids' => $listIds,
            'order_by' => (string) ($setup['order_by'] ?? 'default'),
            'start_from' => (int) ($setup['start_from'] ?? 0),
            'route_option' => (string) ($setup['route_option'] ?? 'ip'),
            'ip_type' => (string) ($setup['ip_type'] ?? 'all'),
            'server_ids' => $serverIds,
            'ip_ids' => $ipIds,
            'send_type' => (string) ($setup['send_type'] ?? 'delay'),
            'total_send' => isset($setup['total_send']) ? (int) $setup['total_send'] : null,
            'duplicate_data' => isset($setup['duplicate_data']) ? (int) $setup['duplicate_data'] : null,
            'fraction_ip' => isset($setup['fraction_ip']) ? (int) $setup['fraction_ip'] : null,
            'sleep_email' => isset($setup['sleep_email']) ? (int) $setup['sleep_email'] : null,
            'sleep_ip' => isset($setup['sleep_ip']) ? (int) $setup['sleep_ip'] : null,
            'test_after' => isset($setup['test_after']) ? (int) $setup['test_after'] : null,
            'batch' => isset($setup['batch']) ? (int) $setup['batch'] : null,
            'delay' => isset($setup['delay']) ? (int) $setup['delay'] : null,
            'bounce_limit' => isset($setup['bounce_limit']) ? (int) $setup['bounce_limit'] : null,
            'placeholder_combination' => $combination,
            'placeholder_all_combination' => $allCombination,
            'placeholders' => $placeholders,
        ];
    }

    /** @return list<string> */
    private function splitLines(string $text): array
    {
        $lines = preg_split('/[\r\n,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $lines), static fn (string $value): bool => $value !== '')));
    }

    /** @param array<string, mixed> $content */
    private function storeDraft(Request $request, Workspace $workspace, string $kind, array $content): RedirectResponse
    {
        $name = trim((string) $request->input('name'));
        $idempotencyKey = (string) $request->input('idempotency_key');
        $contentHash = $this->contentHash($content);
        $existing = $workspace->campaigns()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            $existingRevision = $existing->revisions()->where('revision_number', 1)->first();
            abort_unless(
                $existing->created_by === $request->user()->getKey()
                    && $existing->name === $name
                    && $existing->kind === $kind
                    && $existingRevision !== null
                    && hash_equals($existingRevision->content_sha256, $contentHash),
                409,
                'This draft submission key was already used for different content.'
            );

            return redirect()->route($kind === 'drop' ? 'console.drops.drafts.edit' : 'console.warmups.edit', [$workspace->getKey(), $existing->getKey()])
                ->with('status', 'Draft already saved; no duplicate was created.');
        }

        $draft = DB::transaction(function () use ($request, $workspace, $kind, $name, $idempotencyKey, $content, $contentHash): Campaign {
            $draft = $workspace->campaigns()->create([
                'created_by' => $request->user()->getKey(),
                'name' => $name,
                'kind' => $kind,
                'status' => 'draft',
                'is_active' => true,
                'idempotency_key' => $idempotencyKey,
            ]);
            $draft->revisions()->create([
                'revision_number' => 1,
                'payload' => $content,
                'content_sha256' => $contentHash,
                'idempotency_key' => 'create:'.hash('sha256', $idempotencyKey),
                'request_fingerprint' => $contentHash,
                'created_by' => $request->user()->getKey(),
                'created_at' => now(),
            ]);

            return $draft;
        });

        return redirect()->route($kind === 'drop' ? 'console.drops.drafts.edit' : 'console.warmups.edit', [$workspace->getKey(), $draft->getKey()])
            ->with('status', 'Draft saved. No message was sent.');
    }

    /** @param array<string, mixed> $content */
    private function updateDraft(Request $request, Workspace $workspace, Campaign $draft, array $content): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'idempotency_key' => ['required', 'uuid'],
            'source_revision_id' => ['required', 'uuid'],
        ]);
        $contentHash = $this->contentHash($content);
        $status = 'Draft saved as a new revision. No message was sent.';

        DB::transaction(function () use ($request, $workspace, $draft, $data, $content, $contentHash, &$status): void {
            $lockedDraft = Campaign::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('created_by', $request->user()->getKey())
                ->where('kind', $draft->kind)
                ->whereKey($draft->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(in_array($lockedDraft->status, ['draft', 'snapshot_failed'], true) && $lockedDraft->is_active, 409, 'Only active drafts can be edited.');
            $latest = $lockedDraft->revisions()->lockForUpdate()->first();
            abort_unless((string) ($latest?->getKey() ?? '') === (string) $data['source_revision_id'], 409, 'This draft changed while you were editing it. Reload the latest version before saving.');
            $existingRevision = $lockedDraft->revisions()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existingRevision) {
                abort_unless(hash_equals($existingRevision->request_fingerprint, $contentHash), 409, 'This save key was already used for different draft content.');
                $status = 'Draft revision already saved; no duplicate was created.';

                return;
            }
            if ($latest && hash_equals($latest->content_sha256, $contentHash) && $lockedDraft->name === trim($data['name'])) {
                $status = 'Draft is unchanged; nothing new was saved.';

                return;
            }

            $lockedDraft->name = trim($data['name']);
            $lockedDraft->status = 'draft';
            $lockedDraft->snapshot_build_error = null;
            $lockedDraft->save();
            $lockedDraft->revisions()->create([
                'revision_number' => ($latest?->revision_number ?? 0) + 1,
                'payload' => $content,
                'content_sha256' => $contentHash,
                'idempotency_key' => $data['idempotency_key'],
                'request_fingerprint' => $contentHash,
                'created_by' => $request->user()->getKey(),
                'created_at' => now(),
            ]);
        });

        return redirect()->route($draft->kind === 'drop' ? 'console.drops.drafts.edit' : 'console.warmups.edit', [$workspace->getKey(), $draft->getKey()])
            ->with('status', $status);
    }

    private function draftFor(User $user, Workspace $workspace, string $campaignId, string $kind): Campaign
    {
        return $workspace->campaigns()
            ->with('latestRevision')
            ->where('created_by', $user->getKey())
            ->where('kind', $kind)
            ->whereIn('status', ['draft', 'snapshot_failed'])
            ->where('is_active', true)
            ->whereKey($campaignId)
            ->firstOrFail();
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

    /** @param array<string, mixed> $content */
    private function contentHash(array $content): string
    {
        return hash('sha256', json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
