<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CampaignEngagementEvent;
use App\Models\CampaignLeadConversion;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\ContactListMembershipEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDropVisitor;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WorkspaceEmailManagementController extends Controller
{
    private const TYPES = [
        'fresh', 'delivery', 'open', 'click', 'lead', 'unsubscribe',
        'opt_out', 'proxy', 'bot', 'blacklist', 'hard_bounce',
    ];

    public function index(Request $request, string $workspace): View
    {
        $workspace = $this->adminWorkspace($request->user(), $workspace);

        return view('console.infrastructure.emails.index', compact('workspace'));
    }

    public function search(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->adminWorkspace($request->user(), $workspace);
        $validated = $request->validate([
            'data' => ['required', 'array'],
            'data.*' => ['required', 'string', 'max:512'],
            'type' => ['required', 'string', Rule::in(['id', 'email', 'hash', 'token'])],
        ]);

        $values = array_values(array_unique(array_map(
            static fn (string $value): string => trim($value),
            $validated['data'],
        )));
        $lookupKey = (string) config('alphalink.contact_lookup_key');
        abort_unless(strlen($lookupKey) >= 32, 503, 'Contact lookup is not configured for this workspace.');

        $contacts = Contact::query()->where('workspace_id', $workspace->getKey());
        $type = $validated['type'];

        if ($type === 'id') {
            $ids = array_values(array_filter($values, fn (string $value): bool => $this->isUuid($value)));
            $contacts->whereIn('id', $ids);
        } elseif ($type === 'email') {
            $hashes = [];
            foreach ($values as $value) {
                $email = mb_strtolower($value);
                if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                    $hashes[] = hash_hmac('sha256', $email, $lookupKey);
                }
            }
            $contacts->whereIn('email_lookup_hash', array_values(array_unique($hashes)));
        } elseif ($type === 'hash') {
            $hashes = array_values(array_filter($values, static fn (string $value): bool => preg_match('/^[a-f0-9]{64}$/iD', $value) === 1));
            $contacts->whereIn('email_lookup_hash', array_map('strtolower', $hashes));
        } else {
            $tokenHashes = array_map(static fn (string $value): string => hash('sha256', $value), $values);
            $campaignContactIds = CampaignEngagementEvent::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereIn('token_sha256', $tokenHashes)
                ->distinct()
                ->pluck('contact_id');
            $dropContactIds = WorkspaceDropVisitor::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereIn('token_sha256', $tokenHashes)
                ->where('email_id', '!=', '0')
                ->distinct()
                ->pluck('email_id')
                ->filter(fn (mixed $id): bool => is_string($id) && $this->isUuid($id));
            $contactIds = $campaignContactIds->merge($dropContactIds)->unique()->values();
            $contacts->whereIn('id', $contactIds);
        }

        $contactRows = $contacts->orderBy('created_at')->get();
        $contactIds = $contactRows->modelKeys();
        $memberships = ContactListMembership::query()
            ->with(['audienceList.isp'])
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('contact_id', $contactIds)
            ->get()
            ->keyBy('contact_id');
        $engagementCounts = CampaignEngagementEvent::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('contact_id', $contactIds)
            ->selectRaw('contact_id, COUNT(*) AS aggregate')
            ->groupBy('contact_id')
            ->pluck('aggregate', 'contact_id');
        $leadCounts = CampaignLeadConversion::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('contact_id', $contactIds)
            ->selectRaw('contact_id, COUNT(*) AS aggregate')
            ->groupBy('contact_id')
            ->pluck('aggregate', 'contact_id');
        $dropVisitorCounts = WorkspaceDropVisitor::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('email_id', $contactIds)
            ->selectRaw('email_id, COUNT(*) AS aggregate')
            ->groupBy('email_id')
            ->pluck('aggregate', 'email_id');

        return response()->json([
            'data' => $contactRows->map(function (Contact $contact) use ($memberships, $engagementCounts, $leadCounts, $dropVisitorCounts): array {
                $membership = $memberships->get($contact->getKey());
                $profile = is_array($contact->profile) ? $contact->profile : [];

                return [
                    'id' => (string) $contact->getKey(),
                    'isp' => $membership?->audienceList?->isp?->name,
                    'list' => $membership?->audienceList?->name,
                    'email' => $contact->email,
                    'type' => $membership?->email_type,
                    'first_name' => $profile['first_name'] ?? null,
                    'last_name' => $profile['last_name'] ?? null,
                    'country' => $profile['country'] ?? null,
                    'age' => $profile['age'] ?? null,
                    'sex' => $profile['sex'] ?? null,
                    'vertical' => $profile['vertical'] ?? null,
                    'logs_count' => (int) $engagementCounts->get($contact->getKey(), 0)
                        + (int) $leadCounts->get($contact->getKey(), 0)
                        + (int) $dropVisitorCounts->get($contact->getKey(), 0),
                ];
            })->values(),
        ]);
    }

    public function action(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->adminWorkspace($request->user(), $workspace);
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['required', 'string', 'max:64'],
            'action' => ['required', 'string', Rule::in(['proxy', 'bot', 'blacklist', 'hard_bounce', 'restore'])],
        ]);

        $contactIds = array_values(array_unique(array_filter(
            $validated['ids'],
            fn (string $value): bool => $this->isUuid($value),
        )));
        $action = $validated['action'];
        $changed = DB::transaction(function () use ($request, $workspace, $contactIds, $action): int {
            Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
            $updated = 0;

            $memberships = ContactListMembership::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereIn('contact_id', $contactIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($memberships as $membership) {
                $previousType = (string) $membership->email_type;
                $restoreType = $this->restoreType($workspace, $membership);
                $nextType = $action === 'restore' ? $restoreType : $action;

                if ($previousType === $nextType) {
                    continue;
                }

                $now = now();
                $event = ContactListMembershipEvent::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'audience_list_id' => $membership->audience_list_id,
                    'contact_id' => $membership->contact_id,
                    'action' => 'type_changed',
                    'source' => 'admin_email_action',
                    'idempotency_key' => (string) Str::uuid(),
                    'request_fingerprint' => hash('sha256', implode('|', [
                        $membership->getKey(), $action, $previousType, $nextType,
                    ])),
                    'actor_user_id' => $request->user()->getKey(),
                    'occurred_at' => $now,
                    'metadata' => [
                        'requested_action' => $action,
                        'from_type' => $previousType,
                        'to_type' => $nextType,
                        'restore_type' => $restoreType,
                    ],
                    'created_at' => $now,
                ]);

                $membership->forceFill([
                    'email_type' => $nextType,
                    'last_event_id' => $event->getKey(),
                ])->save();

                AuditEvent::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'actor_user_id' => $request->user()->getKey(),
                    'action' => 'contact_membership.type_changed',
                    'subject_type' => 'contact_list_membership',
                    'subject_id' => (string) $membership->getKey(),
                    'request_id' => $request->attributes->get('request_id'),
                    'properties' => [
                        'from_type' => $previousType,
                        'to_type' => $nextType,
                        'requested_action' => $action,
                        'email_redacted' => true,
                    ],
                    'occurred_at' => $now,
                ]);

                $updated++;
            }

            return $updated;
        });

        return response()->json([
            'data' => sprintf('Updated %s Records', $changed),
            'updated_count' => $changed,
        ]);
    }

    public function logs(Request $request, string $workspace, string $contact): View|JsonResponse
    {
        $workspace = $this->adminWorkspace($request->user(), $workspace);
        abort_unless($this->isUuid($contact), 404);
        $contact = Contact::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereKey($contact)
            ->firstOrFail();

        if ($request->boolean('json') || $request->wantsJson()) {
            return $this->logsDataTable($request, $workspace, $contact);
        }

        return view('console.infrastructure.emails.logs', compact('workspace', 'contact'));
    }

    private function logsDataTable(Request $request, Workspace $workspace, Contact $contact): JsonResponse
    {
        $engagements = DB::table('campaign_engagement_events as event')
            ->where('event.workspace_id', $workspace->getKey())
            ->where('event.contact_id', $contact->getKey())
            ->select([
                DB::raw('CAST(event.id AS TEXT) AS id'), 'event.action', DB::raw('NULL AS offer_id'), 'event.client_ip AS ip',
                'event.country', 'event.state', 'event.city',
                DB::raw('NULL AS vertical'), 'event.device', 'event.platform',
                'event.browser', 'event.is_mobile', 'event.created_at',
            ]);
        $leads = DB::table('campaign_lead_conversions as lead')
            ->where('lead.workspace_id', $workspace->getKey())
            ->where('lead.contact_id', $contact->getKey())
            ->select([
                DB::raw('CAST(lead.id AS TEXT) AS id'), DB::raw("'lead' AS action"), 'lead.offer_id', 'lead.conversion_ip AS ip',
                DB::raw('NULL AS country'), DB::raw('NULL AS state'), DB::raw('NULL AS city'),
                DB::raw('NULL AS vertical'), DB::raw('NULL AS device'), DB::raw('NULL AS platform'),
                DB::raw('NULL AS browser'), DB::raw('NULL AS is_mobile'), 'lead.converted_at AS created_at',
            ]);
        $dropVisitors = DB::table('workspace_drop_visitors as visitor')
            ->leftJoin('workspace_drops as drop', 'drop.id', '=', 'visitor.workspace_drop_id')
            ->leftJoin('workspace_offers as offer', 'offer.id', '=', 'drop.offer_id')
            ->where('visitor.workspace_id', $workspace->getKey())
            ->where('visitor.email_id', (string) $contact->getKey())
            ->select([
                DB::raw('CAST(visitor.id AS TEXT) AS id'), 'visitor.type AS action', 'drop.offer_id AS offer_id', 'visitor.ip',
                'visitor.country', 'visitor.state', 'visitor.city', 'offer.vertical', 'visitor.device', 'visitor.platform',
                'visitor.browser', 'visitor.is_mobile', 'visitor.created_at',
            ]);

        $events = DB::query()->fromSub($engagements->unionAll($leads)->unionAll($dropVisitors), 'email_logs');
        $recordsTotal = (clone $events)->count();
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $events->where(function (Builder $query) use ($search): void {
                foreach (['id', 'action', 'offer_id', 'ip', 'country', 'state', 'city', 'vertical', 'device', 'platform', 'browser', 'created_at'] as $column) {
                    $query->orWhereRaw('CAST('.$column.' AS TEXT) LIKE ?', ['%'.$search.'%']);
                }
            });
        }

        $recordsFiltered = (clone $events)->count();
        $columns = ['id', 'action', 'offer_id', 'ip', 'country', 'state', 'city', 'vertical', 'device', 'platform', 'browser', 'is_mobile', 'created_at'];
        $orderIndex = max(0, (int) $request->input('order.0.column', 12));
        $orderColumn = $columns[$orderIndex] ?? 'created_at';
        $direction = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $start = max(0, (int) $request->input('start', 0));
        $length = min(100, max(1, (int) $request->input('length', 25)));

        return response()->json([
            'draw' => max(0, (int) $request->input('draw', 0)),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $events->orderBy($orderColumn, $direction)
                ->orderBy('id', 'desc')
                ->offset($start)
                ->limit($length)
                ->get(),
        ]);
    }

    private function restoreType(Workspace $workspace, ContactListMembership $membership): string
    {
        $latestAutomaticTransition = ContactListMembershipEvent::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('audience_list_id', $membership->audience_list_id)
            ->where('contact_id', $membership->contact_id)
            ->where('source', '!=', 'admin_email_action')
            ->whereNotNull('metadata->from_type')
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->first();
        $previousType = $latestAutomaticTransition?->metadata['from_type'] ?? 'fresh';

        return in_array($previousType, self::TYPES, true) ? $previousType : 'fresh';
    }

    private function adminWorkspace(User $user, string $workspaceId): Workspace
    {
        $workspace = $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin'], true), 403);

        return $workspace;
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value) === 1;
    }
}
