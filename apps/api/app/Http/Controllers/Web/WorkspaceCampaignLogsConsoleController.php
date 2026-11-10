<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\CampaignRevision;
use App\Models\CampaignSendRun;
use App\Models\Isp;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WorkspaceCampaignLogsConsoleController extends Controller
{
    private const ALLOWED_ROLES = ['owner', 'admin', 'operator'];

    public function index(Request $request, string $workspaceId): View
    {
        $workspace = $this->authorizedWorkspace($request, $workspaceId);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'in:20,50,100'],
        ]);

        $from = Carbon::parse($filters['from'] ?? today()->subDays(30)->toDateString())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? today()->toDateString())->endOfDay();
        $search = trim((string) ($filters['q'] ?? ''));
        $perPage = (int) ($filters['per_page'] ?? 50);

        $campaignQuery = Campaign::query()
            ->with(['latestRevision', 'creator'])
            ->withCount([
                'revisions as updates_count' => fn ($query) => $query->where('revision_number', '>', 1),
                'sendRuns as sends_count' => fn ($query) => $query->where('workspace_id', $workspace->getKey()),
            ])
            ->where('workspace_id', $workspace->getKey())
            ->where('created_by', $request->user()->getKey())
            ->whereIn('kind', ['marketing', 'transactional'])
            ->whereBetween('created_at', [$from, $to])
            ->selectSub(
                DB::table('campaign_manual_test_attempts')
                    ->whereColumn('campaign_id', 'campaigns.id')
                    ->where('workspace_id', $workspace->getKey())
                    ->selectRaw('COUNT(*)'),
                'manual_tests_count',
            )
            ->selectSub(
                DB::table('campaign_test_mail_attempts')
                    ->whereColumn('campaign_id', 'campaigns.id')
                    ->where('workspace_id', $workspace->getKey())
                    ->selectRaw('COUNT(*)'),
                'saved_tests_count',
            )
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('campaigns.name', 'like', '%'.$search.'%')
                        ->orWhere('campaigns.status', 'like', '%'.$search.'%')
                        ->orWhere('campaigns.id', 'like', '%'.$search.'%')
                        ->orWhereHas('creator', function ($userQuery) use ($search): void {
                            $userQuery->where('name', 'like', '%'.$search.'%')
                                ->orWhere('username', 'like', '%'.$search.'%');
                        });
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $campaigns = $campaignQuery->paginate($perPage)->withQueryString();
        $campaignIds = $campaigns->getCollection()->modelKeys();
        $latestSendRuns = $campaignIds === []
            ? collect()
            : CampaignSendRun::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereIn('campaign_id', $campaignIds)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
                ->groupBy('campaign_id')
                ->map(fn ($runs) => $runs->first());
        $activeSendCampaignIds = $campaignIds === []
            ? collect()
            : CampaignSendRun::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereIn('campaign_id', $campaignIds)
                ->whereIn('state', ['planned', 'running'])
                ->distinct()
                ->pluck('campaign_id')
                ->map(fn ($id): string => (string) $id);

        $sendSetups = $campaigns->getCollection()->mapWithKeys(fn (Campaign $campaign): array => [
            (string) $campaign->getKey() => $campaign->latestRevision?->payload['send_setup'] ?? [],
        ]);
        $ispIds = $sendSetups->pluck('isp_id')->filter()->unique()->values();
        $offerIds = $sendSetups->pluck('offer_id')->filter()->unique()->values();
        $ispNames = $ispIds->isEmpty()
            ? collect()
            : Isp::query()->where('workspace_id', $workspace->getKey())->whereIn('id', $ispIds)->pluck('name', 'id');
        $offers = $offerIds->isEmpty()
            ? collect()
            : WorkspaceOffer::withTrashed()->where('workspace_id', $workspace->getKey())->whereIn('id', $offerIds)
                ->get(['id', 'offer_id', 'name'])->keyBy('id');

        $campaigns->getCollection()->transform(function (Campaign $campaign) use ($sendSetups, $latestSendRuns, $activeSendCampaignIds, $ispNames, $offers): Campaign {
            $setup = $sendSetups->get((string) $campaign->getKey(), []);
            $campaign->setAttribute('logs_isp', $ispNames->get($setup['isp_id'] ?? null));
            $campaign->setAttribute('logs_offer', $offers->get($setup['offer_id'] ?? null));
            $campaign->setAttribute('logs_send_run', $latestSendRuns->get((string) $campaign->getKey()));
            $campaign->setAttribute('logs_has_active_send', $activeSendCampaignIds->contains((string) $campaign->getKey()));
            $campaign->setAttribute('tests_count', (int) $campaign->manual_tests_count + (int) $campaign->saved_tests_count);

            return $campaign;
        });

        return view('console.campaign-logs.index', compact('workspace', 'campaigns', 'from', 'to', 'search', 'perPage'));
    }

    public function history(Request $request, string $workspaceId, string $campaignId, string $history): View
    {
        $workspace = $this->authorizedWorkspace($request, $workspaceId);
        abort_unless(in_array($history, ['tests', 'updates', 'sends'], true), 404);
        $campaign = $this->campaignForUser($request, $workspace, $campaignId);

        $historyRows = match ($history) {
            'tests' => $this->testHistory($workspace, $campaign),
            'updates' => CampaignRevision::query()
                ->where('campaign_id', $campaign->getKey())
                ->where('revision_number', '>', 1)
                ->orderByDesc('revision_number')
                ->paginate(25),
            'sends' => CampaignSendRun::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('campaign_id', $campaign->getKey())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(25),
        };

        $actorColumn = match ($history) {
            'tests' => 'actor_user_id',
            'updates' => 'created_by',
            'sends' => 'requested_by',
        };
        $actorIds = $historyRows->getCollection()->pluck($actorColumn)->filter()->unique()->values();
        $actors = $actorIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $actorIds)->get(['id', 'name', 'username'])->keyBy('id');
        $historyRows->getCollection()->transform(function (object $row) use ($actors, $actorColumn): object {
            $actor = $actors->get($row->{$actorColumn} ?? null);
            $row->actor_label = $actor?->name ?: ($actor?->username ?: 'Removed user');

            return $row;
        });

        $counts = [
            'tests' => (int) DB::table('campaign_manual_test_attempts')
                ->where('workspace_id', $workspace->getKey())->where('campaign_id', $campaign->getKey())->count()
                + (int) DB::table('campaign_test_mail_attempts')
                    ->where('workspace_id', $workspace->getKey())->where('campaign_id', $campaign->getKey())->count(),
            'updates' => $campaign->revisions()->where('revision_number', '>', 1)->count(),
            'sends' => CampaignSendRun::query()->where('workspace_id', $workspace->getKey())->where('campaign_id', $campaign->getKey())->count(),
        ];

        return view('console.campaign-logs.history', compact('workspace', 'campaign', 'history', 'historyRows', 'counts'));
    }

    public function setRestriction(Request $request, string $workspaceId, string $campaignId): RedirectResponse
    {
        $workspace = $this->authorizedWorkspace($request, $workspaceId);
        $validated = $request->validate(['is_restricted' => ['required', 'boolean']]);
        $isRestricted = (bool) $validated['is_restricted'];

        $result = DB::transaction(function () use ($request, $workspace, $campaignId, $isRestricted): string {
            $campaign = $workspace->campaigns()
                ->where('created_by', $request->user()->getKey())
                ->whereKey($campaignId)
                ->lockForUpdate()
                ->firstOrFail();

            $wasRestricted = (bool) $campaign->is_restricted;
            if ($wasRestricted === $isRestricted) {
                return 'unchanged';
            }

            if ($isRestricted && CampaignSendRun::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('campaign_id', $campaign->getKey())
                ->whereIn('state', ['planned', 'running'])
                ->exists()) {
                return 'active_send';
            }

            $campaign->forceFill(['is_restricted' => $isRestricted])->save();
            AuditEvent::query()->create([
                'workspace_id' => $workspace->getKey(),
                'actor_user_id' => $request->user()->getKey(),
                'action' => 'campaign.restriction_changed',
                'subject_type' => 'campaign',
                'subject_id' => $campaign->getKey(),
                'request_id' => $request->attributes->get('request_id'),
                'properties' => ['from_restricted' => $wasRestricted, 'to_restricted' => $isRestricted],
                'occurred_at' => now(),
            ]);

            return 'changed';
        }, attempts: 3);

        if ($result === 'active_send') {
            return back(303)->withErrors(['is_restricted' => 'Stop the active send before restricting this campaign.']);
        }

        return back(303)->with('status', match ($result) {
            'unchanged' => 'Campaign restriction is unchanged.',
            default => $isRestricted ? 'Campaign restricted. New sends are blocked.' : 'Campaign unrestricted.',
        });
    }

    private function authorizedWorkspace(Request $request, string $workspaceId): Workspace
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $workspace = $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, self::ALLOWED_ROLES, true), 403);

        return $workspace;
    }

    private function campaignForUser(Request $request, Workspace $workspace, string $campaignId): Campaign
    {
        return $workspace->campaigns()
            ->with(['latestRevision', 'creator'])
            ->where('created_by', $request->user()->getKey())
            ->whereIn('kind', ['marketing', 'transactional'])
            ->whereKey($campaignId)
            ->firstOrFail();
    }

    /** @return LengthAwarePaginator<object> */
    private function testHistory(Workspace $workspace, Campaign $campaign): LengthAwarePaginator
    {
        $manual = DB::table('campaign_manual_test_attempts')
            ->where('workspace_id', $workspace->getKey())
            ->where('campaign_id', $campaign->getKey())
            ->select(['id', 'status', 'actor_user_id', 'created_at', 'exception_type'])
            ->selectRaw("'Manual test' AS test_source");
        $saved = DB::table('campaign_test_mail_attempts')
            ->where('workspace_id', $workspace->getKey())
            ->where('campaign_id', $campaign->getKey())
            ->select(['id', 'status', 'actor_user_id', 'created_at', 'exception_type'])
            ->selectRaw("'Saved campaign test' AS test_source");

        return DB::query()
            ->fromSub($manual->unionAll($saved), 'campaign_tests')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);
    }
}
