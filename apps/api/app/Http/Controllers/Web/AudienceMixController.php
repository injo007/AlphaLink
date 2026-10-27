<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AudienceList;
use App\Models\AudienceMixSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Services\Audiences\BuildAudienceMixSnapshot;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AudienceMixController extends Controller
{
    private const MANAGE_ROLES = ['owner', 'admin', 'operator'];

    private const EMAIL_TYPES = ['fresh', 'delivery', 'open', 'click', 'lead', 'unsubscribe'];

    private const EVENT_ACTIONS = ['open', 'click', 'lead', 'unsubscribe'];

    private const EVENT_FIELDS = ['country', 'offer_id', 'vertical'];

    public function index(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $this->expireStale($workspace);

        return view('console.audiences.mixes.create', [
            'workspace' => $workspace,
            'isps' => $this->availableIsps($workspace, $request->user(), $request->integer('sponsor_id') ?: null),
            'emailTypes' => self::EMAIL_TYPES,
            'selectedIspId' => $request->integer('isp'),
            'selectedCountry' => strtoupper((string) $request->input('country', '')),
            'sponsorId' => $request->integer('sponsor_id') ?: null,
        ]);
    }

    public function history(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $this->expireStale($workspace);
        $dates = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $from = $dates['from'] ?? now()->subDays(30)->format('Y-m-d');
        $to = $dates['to'] ?? now()->format('Y-m-d');
        if ($from > $to) {
            throw ValidationException::withMessages(['from' => 'The start date must be before or equal to the end date.']);
        }

        $mixes = AudienceMixSnapshot::query()
            ->with('isp:id,name')
            ->where('workspace_id', $workspace->getKey())
            ->where('created_by', $request->user()->getKey())
            ->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('console.audiences.mixes.history', [
            'workspace' => $workspace,
            'mixes' => $mixes,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function options(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $this->expireStale($workspace);
        $data = $request->validate([
            'isp_id' => ['required', 'integer'],
            'sponsor_id' => ['nullable', 'integer'],
        ]);
        $isp = $this->availableIsps($workspace, $request->user(), filled($data['sponsor_id'] ?? null) ? (int) $data['sponsor_id'] : null)
            ->firstWhere('id', (int) $data['isp_id']);
        if (! $isp) {
            throw ValidationException::withMessages(['isp_id' => 'Choose an active ISP available to this workspace.']);
        }

        $listsQuery = $workspace->audienceLists()
            ->with('history')
            ->where('isp_id', $isp->id)
            ->where('status', 'active')
            ->when($request->filled('country'), fn ($query) => $query->where('country', strtoupper((string) $request->input('country'))))
            ->when(filled($data['sponsor_id'] ?? null), fn ($query) => $query->whereJsonContains('allowed_sponsors', (int) $data['sponsor_id']))
            ->orderBy('name')
            ->orderBy('id');
        if ($workspace->pivot->role === 'operator') {
            $listsQuery->whereJsonContains('allowed_users', (int) $request->user()->getKey());
        }
        $lists = $listsQuery->get();
        $listIds = $lists->map(fn (AudienceList $list): string => (string) $list->getKey())->all();
        // DMS returns activity choices for the selected ISP while the country
        // filter narrows only the list table. Keep that selector behavior.
        $ispListIds = $workspace->audienceLists()
            ->where('isp_id', $isp->getKey())
            ->where('status', 'active')
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->whereJsonContains('allowed_users', (int) $request->user()->getKey()))
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $eventCountries = [];
        $eventDevices = [];
        $offerIds = collect();
        if ($ispListIds !== []) {
            $eventCountries = DB::table('campaign_engagement_events')
                ->where('workspace_id', $workspace->getKey())
                ->where('isp_id', $isp->getKey())
                ->whereIn('action', self::EVENT_ACTIONS)
                ->whereIn('audience_list_id', $ispListIds)
                ->whereNotNull('country')->distinct()->pluck('country')->all();
            $eventDevices = DB::table('campaign_engagement_events')
                ->where('workspace_id', $workspace->getKey())
                ->where('isp_id', $isp->getKey())
                ->whereIn('action', self::EVENT_ACTIONS)
                ->whereIn('audience_list_id', $ispListIds)
                ->whereNotNull('device')->distinct()->pluck('device')->all();
            $campaignOfferIds = DB::table('campaign_engagement_events as event')
                ->join('campaign_recipient_snapshots as snapshot', function ($join): void {
                    $join->on('snapshot.id', '=', 'event.recipient_snapshot_id')
                        ->on('snapshot.workspace_id', '=', 'event.workspace_id');
                })
                ->where('event.workspace_id', $workspace->getKey())
                ->where('event.isp_id', $isp->getKey())
                ->whereIn('event.action', self::EVENT_ACTIONS)
                ->whereIn('event.audience_list_id', $ispListIds)
                ->distinct()->pluck('snapshot.offer_id');
            $dropCountries = DB::table('workspace_drop_visitors')
                ->where('workspace_id', $workspace->getKey())
                ->where('isp_id', $isp->getKey())
                ->whereIn('type', self::EVENT_ACTIONS)
                ->whereIn('list_id', $ispListIds)
                ->whereNotNull('country')->distinct()->pluck('country')->all();
            $dropDevices = DB::table('workspace_drop_visitors')
                ->where('workspace_id', $workspace->getKey())
                ->where('isp_id', $isp->getKey())
                ->whereIn('type', self::EVENT_ACTIONS)
                ->whereIn('list_id', $ispListIds)
                ->whereNotNull('device')->distinct()->pluck('device')->all();
            $dropOfferIds = DB::table('workspace_drop_visitors as event')
                ->join('workspace_drops as source_drop', function ($join): void {
                    $join->on('source_drop.id', '=', 'event.workspace_drop_id')
                        ->on('source_drop.workspace_id', '=', 'event.workspace_id');
                })
                ->where('event.workspace_id', $workspace->getKey())
                ->where('event.isp_id', $isp->getKey())
                ->whereIn('event.type', self::EVENT_ACTIONS)
                ->whereIn('event.list_id', $ispListIds)
                ->distinct()->pluck('source_drop.offer_id');
            $leadCountries = DB::table('campaign_lead_conversions')
                ->where('workspace_id', $workspace->getKey())
                ->where('isp_id', $isp->getKey())
                ->whereIn('audience_list_id', $ispListIds)
                ->whereNotNull('country')->distinct()->pluck('country')->all();
            $eventCountries = array_values(array_unique([...$eventCountries, ...$dropCountries, ...$leadCountries]));
            $eventDevices = array_values(array_unique([...$eventDevices, ...$dropDevices]));
            $leadOfferIds = DB::table('campaign_lead_conversions')
                ->where('workspace_id', $workspace->getKey())
                ->where('isp_id', $isp->getKey())
                ->whereIn('audience_list_id', $ispListIds)
                ->distinct()->pluck('offer_id');
            $offerIds = $campaignOfferIds->merge($dropOfferIds)->merge($leadOfferIds)->filter()->unique()->values();
        }

        $offers = $offerIds->isEmpty()
            ? collect()
            : WorkspaceOffer::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereIn('id', $offerIds)
                ->where('is_active', true)
                ->where('status', 'live')
                ->whereHas('sponsor', fn ($query) => $query->where('is_active', true))
                ->with('sponsor:id,name')
                ->orderBy('name')
                ->get(['id', 'sponsor_id', 'name', 'vertical']);

        return response()->json([
            'data' => $lists->map(fn (AudienceList $list): array => [
                'id' => (string) $list->getKey(),
                'name' => $list->name,
                'country' => $list->country,
                'counts' => collect(self::EMAIL_TYPES)->mapWithKeys(fn (string $type): array => [
                    $type => (int) ($list->history?->{$type} ?? 0),
                ]),
                'locks' => collect(self::EMAIL_TYPES)->mapWithKeys(fn (string $type): array => [$type => (bool) $list->{'is_'.$type.'_locked'}]),
            ])->values(),
            'filters' => [
                'actions' => self::EVENT_ACTIONS,
                'countries' => $eventCountries,
                'devices' => $eventDevices,
                'fields' => self::EVENT_FIELDS,
                'offers' => $offers->map(fn (WorkspaceOffer $offer): array => [
                    'value' => (string) $offer->getKey(),
                    'label' => $offer->name,
                    'group' => $offer->sponsor?->name,
                ])->values(),
                'verticals' => $offers->pluck('vertical')->filter()->unique()->values(),
            ],
            'tracked_countries' => $isp->tracked_countries ?? [],
        ]);
    }

    public function store(Request $request, string $workspaceId, BuildAudienceMixSnapshot $builder): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $this->expireStale($workspace);
        $extensionsInput = $request->input('extensions', []);
        if (is_string($extensionsInput)) {
            $extensionsInput = preg_split('/[\s,;]+/', $extensionsInput, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $request->merge(['extensions' => array_values(array_unique(array_map(
            static fn (mixed $extension): string => strtolower(ltrim(trim((string) $extension), '@')),
            is_array($extensionsInput) ? $extensionsInput : [],
        )))]);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'isp_id' => ['required', 'integer'],
            'sponsor_id' => ['nullable', 'integer'],
            'country' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'audience_segments' => ['required', 'array', 'min:1'],
            'audience_segments.*' => ['required', 'string', 'distinct', 'regex:/^[0-9a-f-]{36}_(fresh|delivery|open|click|lead|unsubscribe)$/i'],
            'email_sex' => ['nullable', Rule::in(['men', 'women'])],
            'email_device' => ['nullable', 'string', 'max:100'],
            'last_insert_between' => ['nullable', 'array', 'max:2'],
            'last_insert_between.*' => ['date_format:Y-m-d'],
            'activity_between' => ['nullable', 'array', 'max:2'],
            'activity_between.*' => ['date_format:Y-m-d'],
            'extensions' => ['nullable', 'array', 'max:200'],
            'extensions.*' => ['string', 'max:253', 'regex:/^(?=.{1,253}$)(?!-)[a-z0-9-]+(?:\.[a-z0-9-]+)+$/i'],
            'filters' => ['nullable', 'array', 'max:12'],
            'filters.*.effect' => ['required', Rule::in(['include', 'exclude'])],
            'filters.*.field' => ['required', Rule::in(self::EVENT_FIELDS)],
            'filters.*.values' => ['required', 'array', 'min:1', 'max:250'],
            'filters.*.values.*' => ['required', 'string', 'max:128'],
            'filters.*.actions' => ['nullable', 'array', 'max:5'],
            'filters.*.actions.*' => ['string', Rule::in(self::EVENT_ACTIONS)],
        ]);
        foreach ($data['filters'] ?? [] as $index => $filter) {
            if ($filter['field'] === 'offer_id' && array_filter($filter['values'], static fn (string $value): bool => ! ctype_digit($value)) !== []) {
                throw ValidationException::withMessages(["filters.{$index}.values" => 'Choose an offer from the available ISP activity options.']);
            }
        }
        $this->validateDatePairs($data);
        $this->setExecutionLimit();
        $snapshot = $builder->create($workspace, $request->user(), $data);
        if (! $snapshot) {
            return response()->json(['data' => 'Found 0 Emails.'], 400);
        }

        return response()->json([
            'data' => sprintf('Mixed Successfully %s Emails', number_format($snapshot->recipient_count)),
            'id' => (string) $snapshot->getKey(),
            'recipient_count' => $snapshot->recipient_count,
            'status' => $snapshot->status,
        ], 201);
    }

    public function remix(Request $request, string $workspaceId, string $mixId, BuildAudienceMixSnapshot $builder): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $this->expireStale($workspace);
        $data = $request->validate(['name' => ['nullable', 'string', 'max:100']]);
        $source = AudienceMixSnapshot::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('created_by', $request->user()->getKey())
            ->where('status', 'expired')
            ->whereKey($mixId)
            ->firstOrFail();
        $this->setExecutionLimit();
        $criteria = $source->criteria;
        $criteria['name'] = $data['name'] ?? null;
        $snapshot = $builder->create($workspace, $request->user(), $criteria);
        if (! $snapshot) {
            return response()->json(['data' => 'Found 0 Emails.'], 400);
        }

        return response()->json([
            'data' => sprintf('Mixed Successfully %s Emails', number_format($snapshot->recipient_count)),
            'id' => (string) $snapshot->getKey(),
            'recipient_count' => $snapshot->recipient_count,
            'status' => $snapshot->status,
        ], 201);
    }

    private function availableIsps(Workspace $workspace, User $user, ?int $sponsorId = null)
    {
        return $workspace->isps()
            ->where('is_active', true)
            ->whereHas('lists')
            ->when($sponsorId !== null, fn ($query) => $query->whereJsonContains('allowed_sponsors', $sponsorId))
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->whereJsonContains('allowed_users', (int) $user->getKey()))
            ->orderBy('name')
            ->get(['id', 'name', 'tracked_countries']);
    }

    private function expireStale(Workspace $workspace): void
    {
        $cutoff = now()->subDay()->endOfDay();
        $staleIds = AudienceMixSnapshot::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('status', 'active')
            ->where('created_at', '<', $cutoff)
            ->pluck('id');
        if ($staleIds->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($staleIds): void {
            DB::table('audience_mix_snapshots')
                ->whereIn('id', $staleIds)
                ->where('status', 'active')
                ->update(['status' => 'expired', 'expired_at' => now(), 'updated_at' => now()]);
            DB::table('audience_mix_snapshot_members')->whereIn('snapshot_id', $staleIds)->delete();
        });
    }

    private function validateDatePairs(array $data): void
    {
        foreach (['last_insert_between', 'activity_between'] as $field) {
            $range = array_values(array_filter($data[$field] ?? [], static fn (mixed $value): bool => filled($value)));
            if ($range !== [] && count($range) !== 2) {
                throw ValidationException::withMessages([$field => 'Choose both a start and end date, or leave the range empty.']);
            }
            if (count($range) === 2 && $range[0] > $range[1]) {
                throw ValidationException::withMessages([$field => 'The start date must be before or equal to the end date.']);
            }
        }
    }

    private function setExecutionLimit(): void
    {
        set_time_limit(900);
    }

    private function workspaceFor(User $user, string $workspaceId): Workspace
    {
        return $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
    }

    private function authorizeManager(Workspace $workspace): void
    {
        abort_unless(in_array($workspace->pivot->role, self::MANAGE_ROLES, true), 403);
    }
}
