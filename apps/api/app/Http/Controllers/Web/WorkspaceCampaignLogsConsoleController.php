<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\CampaignRevision;
use App\Models\CampaignSendRun;
use App\Models\Isp;
use App\Models\ManagedServer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
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

        if ($request->boolean('fragment')) {
            return view('console.campaign-logs.partials.history-list', compact('workspace', 'campaign', 'history', 'historyRows', 'counts'));
        }

        return view('console.campaign-logs.history', compact('workspace', 'campaign', 'history', 'historyRows', 'counts'));
    }

    public function historyDetail(Request $request, string $workspaceId, string $campaignId, string $history, string $entry): View
    {
        $workspace = $this->authorizedWorkspace($request, $workspaceId);
        abort_unless(in_array($history, ['tests', 'updates', 'sends'], true), 404);
        $campaign = $this->campaignForUser($request, $workspace, $campaignId);
        $payload = [];
        $serverLabels = [];
        $event = ['type' => $history, 'revision' => null, 'status' => null, 'actor' => '—', 'recorded_at' => null];

        if ($history === 'tests') {
            $source = $request->validate(['source' => ['required', 'in:manual,saved']])['source'];
            if ($source === 'manual') {
                $attempt = DB::table('campaign_manual_test_attempts')
                    ->where('workspace_id', $workspace->getKey())
                    ->where('campaign_id', $campaign->getKey())
                    ->where('id', $entry)
                    ->first();
                abort_unless($attempt, 404);
                $event = ['type' => 'Manual test', 'revision' => null, 'status' => $attempt->status, 'actor' => $this->actorLabel($attempt->actor_user_id), 'recorded_at' => $attempt->created_at];
                $server = ManagedServer::query()->where('workspace_id', $workspace->getKey())->whereKey($attempt->managed_server_id)->first(['id', 'name']);
                $serverLabels = $server ? [$server->name] : [];

                if (filled($attempt->test_snapshot)) {
                    try {
                        $decoded = json_decode(Crypt::decryptString($attempt->test_snapshot), true, flags: JSON_THROW_ON_ERROR);
                        $payload = is_array($decoded) ? $decoded : [];
                    } catch (DecryptException|\JsonException) {
                        $payload = [];
                    }
                }
            } else {
                $attempt = DB::table('campaign_test_mail_attempts')
                    ->where('workspace_id', $workspace->getKey())
                    ->where('campaign_id', $campaign->getKey())
                    ->where('id', $entry)
                    ->first();
                abort_unless($attempt, 404);
                $revision = CampaignRevision::query()->where('campaign_id', $campaign->getKey())->whereKey($attempt->revision_id)->first();
                abort_unless($revision, 404);
                $payload = $revision->payload;
                $event = ['type' => 'Saved campaign test', 'revision' => $revision->revision_number, 'status' => $attempt->status, 'actor' => $this->actorLabel($attempt->actor_user_id), 'recorded_at' => $attempt->created_at];
                $server = ManagedServer::query()->where('workspace_id', $workspace->getKey())->whereKey($attempt->managed_server_id)->first(['id', 'name']);
                $serverLabels = $server ? [$server->name] : [];
            }
        } elseif ($history === 'updates') {
            $revision = CampaignRevision::query()->where('campaign_id', $campaign->getKey())->where('revision_number', '>', 1)->whereKey($entry)->firstOrFail();
            $payload = $revision->payload;
            $event = ['type' => 'Campaign update', 'revision' => $revision->revision_number, 'status' => 'Saved', 'actor' => $this->actorLabel($revision->created_by), 'recorded_at' => $revision->created_at];
        } else {
            $run = CampaignSendRun::query()->where('workspace_id', $workspace->getKey())->where('campaign_id', $campaign->getKey())->whereKey($entry)->firstOrFail();
            $revision = CampaignRevision::query()->where('campaign_id', $campaign->getKey())->whereKey($run->revision_id)->first();
            $payload = $revision?->payload ?? [];
            $event = ['type' => 'Campaign send', 'revision' => $revision?->revision_number, 'status' => $run->state, 'actor' => $this->actorLabel($run->requested_by), 'recorded_at' => $run->started_at ?? $run->created_at, 'send_type' => $run->send_type];
        }

        $configuration = $this->campaignConfiguration($workspace, $payload, $serverLabels);

        if ($request->boolean('fragment')) {
            return view('console.campaign-logs.partials.history-detail', compact('campaign', 'event', 'configuration'));
        }

        return view('console.campaign-logs.detail', compact('workspace', 'campaign', 'history', 'event', 'configuration'));
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
            ->selectRaw('NULL AS revision_id, NULL AS managed_server_id, test_snapshot')
            ->selectRaw("'Manual test' AS test_source, 'manual' AS test_source_key");
        $saved = DB::table('campaign_test_mail_attempts')
            ->where('workspace_id', $workspace->getKey())
            ->where('campaign_id', $campaign->getKey())
            ->select(['id', 'status', 'actor_user_id', 'created_at', 'exception_type', 'revision_id', 'managed_server_id'])
            ->selectRaw('NULL AS test_snapshot')
            ->selectRaw("'Saved campaign test' AS test_source, 'saved' AS test_source_key");

        return DB::query()
            ->fromSub($manual->unionAll($saved), 'campaign_tests')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /** @param array<string, mixed> $payload @param list<string> $extraServerLabels @return array<string, mixed> */
    private function campaignConfiguration(Workspace $workspace, array $payload, array $extraServerLabels = []): array
    {
        $setup = is_array($payload['send_setup'] ?? null) ? $payload['send_setup'] : [];
        $snapshotServers = collect(is_array($payload['servers'] ?? null) ? $payload['servers'] : []);
        $serverIds = collect($setup['server_ids'] ?? [])
            ->merge($snapshotServers->pluck('id'))
            ->filter()->map(fn ($id): string => (string) $id)->unique()->values();
        $serverRecords = $serverIds->isEmpty() ? collect() : ManagedServer::query()
            ->where('workspace_id', $workspace->getKey())->whereIn('id', $serverIds)->get(['id', 'name'])->keyBy('id');
        $snapshotServerNames = $snapshotServers->pluck('name')->map(fn ($name): string => trim((string) $name))->filter();
        $serverNames = collect($extraServerLabels)->merge($snapshotServerNames);
        foreach ($serverIds as $id) {
            $snapshotName = $snapshotServers->first(fn ($server): bool => (string) ($server['id'] ?? '') === $id)['name'] ?? null;
            $serverNames->push($serverRecords->get($id)?->name ?: ($snapshotName ?: 'Unavailable server ('.substr($id, 0, 8).')'));
        }
        $serverNames = $serverNames->filter()->unique()->values();

        $settings = [];
        foreach ([
            'route_option' => 'Route mode', 'ip_type' => 'IP type', 'country' => 'Country',
            'offer_country' => 'Offer country', 'order_by' => 'Order by', 'start_from' => 'Start from',
            'total_send' => 'Total send', 'fraction_ip' => 'Fraction IP', 'sleep_email' => 'Sleep between emails (ms)',
            'sleep_ip' => 'Sleep between IPs (ms)', 'seed_every' => 'Seed every', 'bounce_limit' => 'Bounce limit',
            'duplicate_data' => 'Duplicate data',
        ] as $key => $label) {
            $value = $setup[$key] ?? $payload[$key] ?? null;
            if ($value !== null && $value !== '') {
                $settings[$label] = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        $routeTargets = collect($payload['virtual_mtas'] ?? [])->map(fn ($target): array => is_array($target) ? [
            'name' => (string) ($target['name'] ?? '—'),
            'pmta_name' => (string) ($target['pmta_name'] ?? '—'),
            'domain' => (string) ($target['domain'] ?? '—'),
        ] : [])->filter(fn (array $target): bool => $target !== [])->values();

        return [
            'return_path' => (string) ($payload['resolved_return_path'] ?? $payload['return_path'] ?? '—'),
            'header' => $this->hideHeaderRecipients((string) ($payload['resolved_header'] ?? $payload['header'] ?? '')),
            'body' => (string) ($payload['resolved_body'] ?? $payload['body'] ?? $payload['html_body'] ?? ''),
            'route' => strtoupper(str_replace('_', ' ', (string) ($payload['outbound_route'] ?? $payload['mta'] ?? $setup['route_option'] ?? '—'))),
            'send_type' => strtoupper(str_replace('_', ' ', (string) ($payload['send_type'] ?? $setup['send_type'] ?? '—'))),
            'servers' => $serverNames,
            'server_note' => $snapshotServers->isNotEmpty() ? 'Server and route names captured with this test.' : 'The revision stores server IDs; displayed names come from the current workspace catalog.',
            'ip_ids' => collect($payload['ips'] ?? $setup['ip_ids'] ?? [])->map(fn ($id): string => (string) $id)->values(),
            'route_targets' => $routeTargets,
            'settings' => $settings,
            'available' => $payload !== [],
        ];
    }

    private function hideHeaderRecipients(string $header): string
    {
        return preg_replace('/^\s*(to|cc|bcc)\s*:\s*.*$/im', '$1: [recipient hidden]', $header) ?? $header;
    }

    private function actorLabel(?int $userId): string
    {
        if ($userId === null) {
            return 'Removed user';
        }

        $actor = User::query()->whereKey($userId)->first(['id', 'name', 'username']);

        return $actor?->name ?: ($actor?->username ?: 'Removed user');
    }
}
