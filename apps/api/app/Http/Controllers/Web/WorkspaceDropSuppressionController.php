<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWorkspaceDropSuppression;
use App\Models\AudienceList;
use App\Models\AuditEvent;
use App\Models\Isp;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDropSuppression;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use App\Services\Campaigns\DmsOfferSuppressionFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WorkspaceDropSuppressionController extends Controller
{
    private const ALLOWED_ROLES = ['owner', 'admin', 'operator'];

    private const FILE_MANAGEMENT_ROLES = ['owner', 'admin'];

    private const ACTIVE_TYPES = ['fresh', 'delivery', 'open', 'click', 'lead', 'unsubscribe'];

    public function index(Request $request, string $workspace): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        if (! $request->wantsJson()) {
            return view('console.drops.suppression', [
                'workspace' => $workspace,
                'sponsors' => $this->sponsors($workspace, $request->user()),
                'canManageSuppressionFiles' => in_array($workspace->pivot->role, self::FILE_MANAGEMENT_ROLES, true),
                'initialSponsorId' => $request->integer('sponsor_id') > 0 ? $request->integer('sponsor_id') : null,
                'initialOfferId' => $request->integer('offer_id') > 0 ? $request->integer('offer_id') : null,
                'today' => now()->toDateString(),
                'defaultFrom' => now()->subDays(6)->toDateString(),
            ]);
        }

        return $this->rows($request, $workspace);
    }

    public function catalog(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $sponsorId = $request->integer('sponsor_id');
        $offerId = $request->integer('offer_id');
        $ispIds = array_values(array_unique(array_filter(array_map('strval', (array) $request->input('isp_ids', [])))));

        $offers = collect();
        $isps = collect();
        $lists = collect();
        if ($sponsorId > 0) {
            $offers = $this->catalogOffers($workspace, $request->user(), $sponsorId)->get();
            $isps = $this->isps($workspace, $request->user(), $sponsorId)->get();
            if ($ispIds !== []) {
                $lists = $this->lists($workspace, $request->user(), $sponsorId, $ispIds)->get()
                    ->map(function (AudienceList $list): array {
                        return [
                            'id' => (string) $list->getKey(),
                            'name' => $list->name,
                            'total' => $this->listTotal($list),
                        ];
                    })->values();
            }
        }

        $eligibleOfferIds = $sponsorId > 0
            ? $this->eligibleOffers($workspace, $request->user(), $sponsorId)->pluck('id')->map(fn ($id): int => (int) $id)->all()
            : [];

        return response()->json([
            'sponsors' => $this->sponsors($workspace, $request->user())->map(fn (WorkspaceSponsor $sponsor): array => [
                'id' => (int) $sponsor->getKey(),
                'name' => $sponsor->name,
            ])->values(),
            'offers' => $offers->map(fn (WorkspaceOffer $offer): array => [
                'id' => (int) $offer->getKey(),
                'offer_id' => $offer->offer_id,
                'name' => $offer->name,
                'vertical' => $offer->vertical,
                'payout' => (string) $offer->payout,
                'is_active' => (bool) $offer->is_active,
                'status' => $offer->status,
                'has_suppression_file' => filled($offer->suppression_path)
                    && Storage::disk('local')->exists($offer->suppression_path),
                'suppression_format' => $offer->suppression_format,
                'suppression_updated_at' => $offer->suppression_updated_at?->toDateString(),
                'can_run_suppression' => in_array((int) $offer->getKey(), $eligibleOfferIds, true),
            ])->values(),
            'isps' => $isps->map(fn (Isp $isp): array => [
                'id' => (int) $isp->getKey(),
                'name' => mb_strtoupper($isp->name),
            ])->values(),
            'lists' => $lists,
        ]);
    }

    public function uploadOfferFile(Request $request, string $workspace, DmsOfferSuppressionFile $suppressionFiles): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless(in_array($workspace->pivot->role, self::FILE_MANAGEMENT_ROLES, true), 403);

        $data = $request->validate([
            'sponsor_id' => ['required', 'integer', 'min:1'],
            'offer_id' => ['required', 'integer', 'min:1'],
            'suppression_file' => ['required', 'file', 'mimes:txt,csv'],
        ]);
        $sponsor = $workspace->sponsors()->where('is_active', true)->whereKey($data['sponsor_id'])->firstOrFail();
        $offer = $workspace->offers()->whereKey($data['offer_id'])->where('sponsor_id', $sponsor->getKey())->firstOrFail();
        $stored = $suppressionFiles->store($workspace, $offer, $data['suppression_file']);
        $offer->forceFill([
            'suppression_path' => $stored['path'],
            'suppression_sha256' => $stored['sha256'],
            'suppression_format' => $stored['format'],
            'suppression_updated_at' => now()->toDateString(),
        ])->save();

        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => 'offer.suppression_uploaded',
            'subject_type' => WorkspaceOffer::class,
            'subject_id' => (string) $offer->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => [
                'sha256' => $stored['sha256'],
                'format' => $stored['format'],
                'entry_count' => $stored['count'],
                'source' => 'drops_suppression',
            ],
            'occurred_at' => now(),
        ]);

        $canRunSuppression = $this->eligibleOffers($workspace, $request->user(), (int) $sponsor->getKey())
            ->whereKey($offer->getKey())->exists();

        return response()->json(['data' => [
            'message' => 'Suppression file uploaded and verified.',
            'offer_id' => (int) $offer->getKey(),
            'format' => $stored['format'],
            'entry_count' => $stored['count'],
            'updated_at' => $offer->fresh()->suppression_updated_at?->toDateString(),
            'can_run_suppression' => $canRunSuppression,
        ]]);
    }

    public function downloadOfferFile(Request $request, string $workspace, int $offer): BinaryFileResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        abort_unless(in_array($workspace->pivot->role, self::FILE_MANAGEMENT_ROLES, true), 403);

        $selectedOffer = $workspace->offers()->whereKey($offer)->firstOrFail();
        $path = (string) $selectedOffer->suppression_path;
        $sha256 = (string) $selectedOffer->suppression_sha256;
        $expectedPath = sprintf(
            'offers/%s/%d/suppression/%s.txt',
            $workspace->getKey(),
            $selectedOffer->getKey(),
            $sha256,
        );
        $disk = Storage::disk('local');

        abort_unless(
            preg_match('/^[a-f0-9]{64}$/', $sha256) === 1
                && hash_equals($expectedPath, $path)
                && $disk->exists($path),
            404,
        );

        return response()->download($disk->path($path), 'suppression-offer-'.$selectedOffer->getKey().'.txt', [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function start(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate([
            'sponsor' => ['required', 'integer', 'min:1'],
            'offer' => ['required', 'integer', 'min:1'],
            'isp' => ['required', 'array', 'min:1', 'distinct'],
            'isp.*' => ['required', 'integer', 'min:1'],
            'lists' => ['required', 'array', 'min:1', 'distinct'],
            'lists.*' => ['required', 'uuid'],
        ]);

        $sponsor = $this->sponsors($workspace, $request->user())->firstWhere('id', (int) $data['sponsor']);
        abort_unless($sponsor !== null, 404);
        $offer = $this->eligibleOffers($workspace, $request->user(), (int) $data['sponsor'])
            ->whereKey((int) $data['offer'])->first();
        abort_unless($offer !== null, 404);

        $ispIds = array_map('intval', $data['isp']);
        $allowedIspIds = $this->isps($workspace, $request->user(), (int) $data['sponsor'])
            ->whereKey($ispIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        abort_unless(count($allowedIspIds) === count(array_unique($ispIds)), 404);

        $lists = $this->lists($workspace, $request->user(), (int) $data['sponsor'], array_map('strval', $allowedIspIds))
            ->whereIn('audience_lists.id', $data['lists'])->get();
        abort_unless($lists->count() === count(array_unique($data['lists'])), 404);

        $settings = $workspace->appSettings?->settings ?? [];
        $freshDays = max(0, (int) ($settings['campaign_expire'] ?? 2));
        $date = now()->subDays($freshDays)->startOfDay();
        $missingListIds = $lists->filter(function (AudienceList $list) use ($workspace, $offer, $date): bool {
            return ! WorkspaceDropSuppression::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('offer_id', $offer->getKey())
                ->where('status', 'like', 'complete%')
                ->where('created_at', '>=', $date)
                ->whereJsonContains('lists', (string) $list->getKey())
                ->exists();
        })->map(fn (AudienceList $list): string => (string) $list->getKey())->values()->all();

        if ($missingListIds === []) {
            return response()->json(['data' => 'Suppression up to date'], 400);
        }

        $run = WorkspaceDropSuppression::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $request->user()->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'isps' => array_values(array_unique($ispIds)),
            'lists' => $missingListIds,
            'status' => 'start',
            'found' => 0,
            'elapsed' => 'NA',
            'started_at' => now(),
        ]);

        ProcessWorkspaceDropSuppression::dispatch((int) $run->getKey());

        return response()->json(['data' => 'Process Started', 'id' => (int) $run->getKey()]);
    }

    public function monitor(Request $request, string $workspace, int $suppression): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $record = $this->suppressionFor($workspace, $request->user(), $suppression);

        return response()->json([
            'id' => (int) $record->getKey(),
            'status' => $record->status,
            'found' => (int) $record->found,
        ]);
    }

    public function kill(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $data = $request->validate(['suppression_id' => ['required', 'integer', 'min:1']]);
        $record = $this->suppressionFor($workspace, $request->user(), (int) $data['suppression_id']);
        if ($this->isTerminal($record->status)) {
            return response()->json(['data' => 'Error'], 400);
        }

        $killed = WorkspaceDropSuppression::query()
            ->whereKey($record->getKey())
            ->where('workspace_id', $workspace->getKey())
            ->where('status', 'not like', 'complete%')
            ->where('status', 'not like', 'error%')
            ->where('status', '!=', 'killed')
            ->update(['status' => 'killed', 'finished_at' => now()]);
        if ($killed === 0) {
            return response()->json(['data' => 'Error'], 400);
        }

        return response()->json(['data' => 'Killed Successfully']);
    }

    private function rows(Request $request, Workspace $workspace): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $from = Carbon::parse($filters['from'] ?? now()->subDays(6)->toDateString())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now()->toDateString())->endOfDay();
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to' => 'Date to must be on or after Date from.']);
        }

        $rows = WorkspaceDropSuppression::query()
            ->where('workspace_id', $workspace->getKey())
            ->with(['sponsor:id,name', 'offer:id,offer_id,name', 'user:id,username'])
            ->when($workspace->pivot->role === 'operator', fn (Builder $query) => $query->where('created_by', $request->user()->getKey()))
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('id')->get();
        $ispIds = $rows->flatMap(fn (WorkspaceDropSuppression $row): array => $row->isps ?? [])->unique()->values();
        $listIds = $rows->flatMap(fn (WorkspaceDropSuppression $row): array => $row->lists ?? [])->unique()->values();
        $ispNames = Isp::query()->where('workspace_id', $workspace->getKey())->whereIn('id', $ispIds)->pluck('name', 'id');
        $listNames = AudienceList::query()->where('workspace_id', $workspace->getKey())->whereIn('id', $listIds)->pluck('name', 'id');

        return response()->json(['data' => $rows->map(function (WorkspaceDropSuppression $row) use ($ispNames, $listNames): array {
            return [
                'id' => (int) $row->getKey(),
                'user' => ['username' => $row->user?->username ?? 'NA'],
                'sponsor' => ['name' => $row->sponsor?->name ?? 'NA'],
                'offer' => ['offer_id' => $row->offer?->offer_id, 'name' => $row->offer?->name],
                'isps' => collect($row->isps ?? [])->map(fn ($id): ?string => $ispNames->get((int) $id))->filter()->values()->all(),
                'lists' => collect($row->lists ?? [])->map(fn ($id): ?string => $listNames->get((string) $id))->filter()->values()->all(),
                'status' => $row->status,
                'found' => (int) $row->found,
                'elapsed' => $row->elapsed,
                'created_at' => $row->created_at?->toDateTimeString(),
            ];
        })->values()]);
    }

    private function sponsors(Workspace $workspace, User $user)
    {
        return WorkspaceSponsor::query()->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)->whereHas('offers')
            ->when($workspace->pivot->role === 'operator', fn (Builder $query) => $query->whereJsonContains('allowed_users', (int) $user->getKey()))
            ->orderBy('name')->get(['id', 'workspace_id', 'name']);
    }

    private function catalogOffers(Workspace $workspace, User $user, int $sponsorId): Builder
    {
        return WorkspaceOffer::query()->where('workspace_id', $workspace->getKey())
            ->where('sponsor_id', $sponsorId)
            ->whereHas('sponsor', fn (Builder $query) => $query->where('is_active', true)->whereNull('deleted_at'))
            ->when($workspace->pivot->role === 'operator', fn (Builder $query) => $query->whereJsonContains('allowed_users', (int) $user->getKey()))
            ->orderBy('name');
    }

    private function eligibleOffers(Workspace $workspace, User $user, int $sponsorId): Builder
    {
        $today = now()->format('D');

        return WorkspaceOffer::query()->where('workspace_id', $workspace->getKey())
            ->where('sponsor_id', $sponsorId)->where('is_active', true)->where('status', 'live')
            ->whereNotNull('suppression_updated_at')->where('suppression_updated_at', '>=', now()->subDays(7)->toDateString())
            ->whereJsonContains('allowed_days', $today)
            ->whereHas('sponsor', fn (Builder $query) => $query->where('is_active', true)->whereNull('deleted_at'))
            ->when($workspace->pivot->role === 'operator', fn (Builder $query) => $query->whereJsonContains('allowed_users', (int) $user->getKey()))
            ->orderBy('name');
    }

    private function isps(Workspace $workspace, User $user, int $sponsorId): Builder
    {
        return Isp::query()->where('workspace_id', $workspace->getKey())->where('is_active', true)
            ->whereHas('lists', fn (Builder $query) => $query->where('status', 'active'))
            ->whereJsonContains('allowed_sponsors', $sponsorId)
            ->when($workspace->pivot->role === 'operator', fn (Builder $query) => $query->whereJsonContains('allowed_users', (int) $user->getKey()))
            ->orderBy('name');
    }

    private function lists(Workspace $workspace, User $user, int $sponsorId, array $ispIds): Builder
    {
        return AudienceList::query()->with('history')
            ->where('audience_lists.workspace_id', $workspace->getKey())
            ->where('audience_lists.status', 'active')
            ->whereIn('audience_lists.isp_id', $ispIds)
            ->whereJsonContains('audience_lists.allowed_sponsors', $sponsorId)
            ->whereHas('history', function (Builder $query): void {
                $query->where(function (Builder $counts): void {
                    foreach (self::ACTIVE_TYPES as $index => $type) {
                        $method = $index === 0 ? 'where' : 'orWhere';
                        $counts->{$method}($type, '>', 0);
                    }
                });
            })
            ->when($workspace->pivot->role === 'operator', fn (Builder $query) => $query->whereJsonContains('audience_lists.allowed_users', (int) $user->getKey()))
            ->orderBy('audience_lists.name');
    }

    private function listTotal(AudienceList $list): int
    {
        return collect(self::ACTIVE_TYPES)->sum(fn (string $type): int => max(0, (int) ($list->history?->{$type} ?? 0)));
    }

    private function suppressionFor(Workspace $workspace, User $user, int $id): WorkspaceDropSuppression
    {
        return WorkspaceDropSuppression::query()->where('workspace_id', $workspace->getKey())
            ->whereKey($id)
            ->when($workspace->pivot->role === 'operator', fn (Builder $query) => $query->where('created_by', $user->getKey()))
            ->firstOrFail();
    }

    private function isTerminal(string $status): bool
    {
        return str_contains($status, 'complete') || str_contains($status, 'error') || str_contains($status, 'killed');
    }

    private function workspaceFor(User $user, string $workspaceId): Workspace
    {
        $workspace = $user->workspaces()->where('workspaces.status', 'active')->whereKey($workspaceId)->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, self::ALLOWED_ROLES, true), 403);

        return $workspace;
    }
}
