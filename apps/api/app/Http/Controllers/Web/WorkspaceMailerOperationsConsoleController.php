<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AudienceList;
use App\Models\Campaign;
use App\Models\Isp;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSeedList;
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

    public function drops(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);

        return $this->draftIndex($workspace, $request->user(), 'drop');
    }

    public function createDrop(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);

        return $this->draftForm($workspace, $request->user(), 'drop');
    }

    public function storeDrop(Request $request, string $workspace): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $content = $this->validatedDropContent($request, $workspace);

        return $this->storeDraft($request, $workspace, 'drop', $content);
    }

    public function editDrop(Request $request, string $workspace, string $campaign): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $draft = $this->draftFor($request->user(), $workspace, $campaign, 'drop');

        return $this->draftForm($workspace, $request->user(), 'drop', $draft);
    }

    public function updateDrop(Request $request, string $workspace, string $campaign): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $draft = $this->draftFor($request->user(), $workspace, $campaign, 'drop');
        $content = $this->validatedDropContent($request, $workspace);

        return $this->updateDraft($request, $workspace, $draft, $content);
    }

    public function warmups(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);

        return $this->draftIndex($workspace, $request->user(), 'warmup');
    }

    public function createWarmup(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);

        return $this->draftForm($workspace, $request->user(), 'warmup');
    }

    public function storeWarmup(Request $request, string $workspace): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $content = $this->validatedWarmupContent($request, $workspace);

        return $this->storeDraft($request, $workspace, 'warmup', $content);
    }

    public function editWarmup(Request $request, string $workspace, string $campaign): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $draft = $this->draftFor($request->user(), $workspace, $campaign, 'warmup');

        return $this->draftForm($workspace, $request->user(), 'warmup', $draft);
    }

    public function updateWarmup(Request $request, string $workspace, string $campaign): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $draft = $this->draftFor($request->user(), $workspace, $campaign, 'warmup');
        $content = $this->validatedWarmupContent($request, $workspace);

        return $this->updateDraft($request, $workspace, $draft, $content);
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

    private function draftIndex(Workspace $workspace, User $user, string $kind): View
    {
        $drafts = $workspace->campaigns()
            ->with('latestRevision')
            ->where('created_by', $user->getKey())
            ->where('kind', $kind)
            ->where('status', 'draft')
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->paginate(20);

        return view('console.mailer-drafts.index', [
            'workspace' => $workspace,
            'drafts' => $drafts,
            'kind' => $kind,
            'createUrl' => route($kind === 'drop' ? 'console.drops.drafts.create' : 'console.warmups.create', $workspace->getKey()),
            'editRoute' => $kind === 'drop' ? 'console.drops.drafts.edit' : 'console.warmups.edit',
        ]);
    }

    private function draftForm(Workspace $workspace, User $user, string $kind, ?Campaign $draft = null): View
    {
        $operator = $workspace->pivot->role === 'operator';
        $lists = AudienceList::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('status', 'active')
            ->when($operator, fn (Builder $query) => $query->whereJsonContains('allowed_users', (int) $user->getKey()))
            ->with('isp:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'country', 'isp_id']);
        $isps = Isp::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)
            ->when($operator, fn (Builder $query) => $query->whereJsonContains('allowed_users', (int) $user->getKey()))
            ->orderBy('name')
            ->get(['id', 'name']);
        $seedLists = WorkspaceSeedList::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)
            ->when($operator, fn (Builder $query) => $query->where('user_id', $user->getKey()))
            ->withCount('emails')
            ->orderBy('name')
            ->get(['id', 'name', 'user_id']);
        $content = $draft?->latestRevision?->payload ?? [];
        $idempotencyKey = (string) Str::uuid();

        return view('console.mailer-drafts.form', [
            'workspace' => $workspace,
            'kind' => $kind,
            'draft' => $draft,
            'content' => $content,
            'idempotencyKey' => $idempotencyKey,
            'lists' => $lists,
            'isps' => $isps,
            'seedLists' => $seedLists,
            'storeUrl' => route($kind === 'drop' ? 'console.drops.drafts.store' : 'console.warmups.store', $workspace->getKey()),
            'updateUrl' => $draft ? route($kind === 'drop' ? 'console.drops.drafts.update' : 'console.warmups.update', [$workspace->getKey(), $draft->getKey()]) : null,
            'indexUrl' => route($kind === 'drop' ? 'console.drops.drafts.index' : 'console.warmups.index', $workspace->getKey()),
            'seedListsUrl' => route('console.warmups.seeds.index', $workspace->getKey()),
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedDropContent(Request $request, Workspace $workspace): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'from' => ['nullable', 'string', 'max:320'],
            'subject' => ['required', 'string', 'max:998'],
            'header' => ['nullable', 'string', 'max:50000'],
            'html_body' => ['required', 'string', 'max:1000000'],
            'isp_id' => ['nullable', Rule::exists('isps', 'id')->where('workspace_id', $workspace->getKey())],
            'list_ids' => ['nullable', 'array', 'max:100'],
            'list_ids.*' => ['uuid'],
            'countries' => ['nullable', 'array', 'max:250'],
            'countries.*' => ['string', 'size:2', 'alpha'],
            'idempotency_key' => ['required', 'uuid'],
            'source_revision_id' => ['nullable', 'uuid'],
        ]);
        $listIds = array_values(array_unique(array_map('strval', $data['list_ids'] ?? [])));
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
        if (! empty($data['isp_id']) && $selectedLists->contains(fn (AudienceList $list): bool => (int) $list->isp_id !== (int) $data['isp_id'])) {
            throw ValidationException::withMessages(['list_ids' => 'Each selected audience list must belong to the selected ISP.']);
        }
        $selectedCountries = array_values(array_unique(array_map('strtoupper', $data['countries'] ?? [])));
        $listCountries = $selectedLists->pluck('country')->filter()->map(fn (string $country): string => strtoupper($country))->unique()->values()->all();
        if ($selectedCountries !== [] && $listCountries !== [] && array_diff($selectedCountries, $listCountries) !== []) {
            throw ValidationException::withMessages(['countries' => 'Choose countries represented by the selected audience lists.']);
        }
        if (! empty($data['isp_id'])) {
            $ispAvailable = Isp::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('is_active', true)
                ->when($workspace->pivot->role === 'operator', fn (Builder $query) => $query->whereJsonContains('allowed_users', (int) $request->user()->getKey()))
                ->whereKey((int) $data['isp_id'])->exists();
            if (! $ispAvailable) {
                throw ValidationException::withMessages(['isp_id' => 'Choose an active ISP available to this workspace member.']);
            }
        }

        return [
            'draft_type' => 'drop',
            'from' => trim((string) ($data['from'] ?? '')),
            'subject' => trim($data['subject']),
            'header' => (string) ($data['header'] ?? ''),
            'html_body' => $data['html_body'],
            'isp_id' => isset($data['isp_id']) ? (int) $data['isp_id'] : null,
            'list_ids' => $listIds,
            'countries' => $selectedCountries,
        ];
    }

    /** @return array<string, mixed> */
    private function validatedWarmupContent(Request $request, Workspace $workspace): array
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

        return [
            'draft_type' => 'warmup',
            'return_path' => trim((string) ($data['return_path'] ?? '')),
            'email_test_addresses' => array_values(array_unique($data['email_test_addresses'] ?? [])),
            'header' => $data['header'],
            'html_body' => $data['html_body'],
            'seed_list_ids' => $seedListIds,
            'send_type' => $data['send_type'],
            'total_send' => isset($data['total_send']) ? (int) $data['total_send'] : null,
            'fraction_ip' => isset($data['fraction_ip']) ? (int) $data['fraction_ip'] : null,
            'sleep_email' => isset($data['sleep_email']) ? (int) $data['sleep_email'] : null,
            'sleep_ip' => isset($data['sleep_ip']) ? (int) $data['sleep_ip'] : null,
            'seed_every' => isset($data['seed_every']) ? (int) $data['seed_every'] : null,
            'bounce_limit' => isset($data['bounce_limit']) ? (int) $data['bounce_limit'] : null,
        ];
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
            abort_unless($lockedDraft->status === 'draft' && $lockedDraft->is_active, 409, 'Only active drafts can be edited.');
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
            ->where('status', 'draft')
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
