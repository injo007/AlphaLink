<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AudienceList;
use App\Models\AudienceListImportBatch;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\ContactListMembershipEvent;
use App\Models\Isp;
use App\Models\OutboxMessage;
use App\Models\Workspace;
use App\Services\Audiences\ImportAudienceListContacts;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AudienceListController extends Controller
{
    public function __construct(private ImportAudienceListContacts $contactImporter) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $lists = $workspace->audienceLists()
            ->withCount(['memberships as active_member_count' => fn ($query) => $query->where('status', 'active')])
            ->where('status', 'active')
            ->orderBy('name')
            ->paginate(25);

        return response()->json([
            'data' => $lists->getCollection()->map(fn (AudienceList $list) => $this->present($list)),
            'meta' => [
                'current_page' => $lists->currentPage(),
                'last_page' => $lists->lastPage(),
                'per_page' => $lists->perPage(),
                'total' => $lists->total(),
            ],
        ]);
    }

    public function show(Request $request, string $workspaceId, string $list): JsonResponse
    {
        $audienceList = $this->findList($request, $list);
        $audienceList->loadCount(['memberships as active_member_count' => fn ($query) => $query->where('status', 'active')]);

        return response()->json(['data' => $this->present($audienceList)]);
    }

    public function members(Request $request, string $workspaceId, string $list): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $audienceList = $this->findList($request, $list);
        $members = ContactListMembership::query()
            ->with('contact')
            ->where('workspace_id', $workspace->getKey())
            ->where('audience_list_id', $audienceList->getKey())
            ->where('status', 'active')
            ->orderBy('joined_at')
            ->orderBy('id')
            ->cursorPaginate(50);

        return response()->json([
            'data' => $members->getCollection()->map(fn (ContactListMembership $membership) => [
                'contact_id' => $membership->contact->id,
                'email_masked' => $this->maskEmail($membership->contact->email),
                'joined_at' => $membership->joined_at?->toISOString(),
            ]),
            'next_cursor' => $members->nextCursor()?->encode(),
        ]);
    }

    public function membershipEvents(Request $request, string $workspaceId, string $list, string $contact): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $audienceList = $this->findList($request, $list);
        $contact = $workspace->contacts()->whereKey($contact)->firstOrFail();
        $events = ContactListMembershipEvent::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('audience_list_id', $audienceList->getKey())
            ->where('contact_id', $contact->getKey())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate(50);

        return response()->json([
            'data' => $events->getCollection()->map(fn (ContactListMembershipEvent $event) => [
                'id' => $event->id,
                'action' => $event->action,
                'source' => $event->source,
                'occurred_at' => $event->occurred_at?->toISOString(),
                'actor_user_id' => $event->actor_user_id,
            ]),
            'next_cursor' => $events->nextCursor()?->encode(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'alpha_dash', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'isp_id' => ['nullable', 'integer', Rule::exists('isps', 'id')->where('workspace_id', $workspace->getKey())],
            'country' => ['nullable', 'string', 'size:2'],
        ]);
        $idempotencyKey = $this->idempotencyKey($request);
        $name = trim($data['name']);
        $slug = Str::slug($data['slug'] ?? $name);
        $slug = $slug !== '' ? $slug : 'audience';
        $description = isset($data['description']) ? trim($data['description']) : null;
        $ispId = isset($data['isp_id']) ? (int) $data['isp_id'] : null;
        $country = isset($data['country']) ? strtoupper(trim($data['country'])) : null;
        if ($ispId !== null && ! in_array($request->attributes->get('alphalink.workspace_role'), ['owner', 'admin'], true)) {
            abort(403);
        }
        $fingerprintPayload = [
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
        ];
        if (array_key_exists('isp_id', $data) || array_key_exists('country', $data)) {
            $fingerprintPayload['isp_id'] = $ispId;
            $fingerprintPayload['country'] = $country;
        }
        $fingerprint = $this->fingerprint($fingerprintPayload);

        $existing = $workspace->audienceLists()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            abort_unless(hash_equals($existing->request_fingerprint, $fingerprint), 409, 'Idempotency key was already used for a different list request.');
            $existing->loadCount(['memberships as active_member_count' => fn ($query) => $query->where('status', 'active')]);

            return response()->json(['data' => $this->present($existing)], 200);
        }

        if ($workspace->audienceLists()->where('slug', $slug)->exists()) {
            return response()->json(['message' => 'This list address is already in use.', 'code' => 'list_slug_conflict'], 409);
        }

        try {
            $audienceList = DB::transaction(function () use ($request, $workspace, $name, $slug, $description, $ispId, $country, $idempotencyKey, $fingerprint): AudienceList {
                $isp = $ispId === null
                    ? null
                    : Isp::query()->where('workspace_id', $workspace->getKey())->whereKey($ispId)->lockForUpdate()->firstOrFail();
                if ($country !== null && (! $isp || ! in_array($country, $isp->tracked_countries ?? [], true))) {
                    throw ValidationException::withMessages([
                        'country' => ['The country must be included in the selected ISP tracked countries.'],
                    ]);
                }
                $audienceList = $workspace->audienceLists()->create([
                    'created_by' => $request->user()->getKey(),
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description,
                    'status' => 'active',
                    'isp_id' => $isp?->getKey(),
                    'country' => $country,
                    'allowed_users' => $isp?->allowed_users ?? [],
                    'allowed_sponsors' => $isp?->allowed_sponsors ?? [],
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                ]);

                $this->writeAuditAndOutbox($request, $workspace, 'audience_list.created', $audienceList->getKey(), [
                    'list_slug' => $slug,
                ], ['audience_list_id' => $audienceList->getKey()]);

                return $audienceList;
            });
        } catch (QueryException $exception) {
            $existing = $workspace->audienceLists()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_fingerprint, $fingerprint), 409, 'Idempotency key was already used for a different list request.');
                $existing->loadCount(['memberships as active_member_count' => fn ($query) => $query->where('status', 'active')]);

                return response()->json(['data' => $this->present($existing)], 200);
            }

            if ($workspace->audienceLists()->where('slug', $slug)->exists()) {
                return response()->json(['message' => 'This list address is already in use.', 'code' => 'list_slug_conflict'], 409);
            }

            throw $exception;
        }

        $audienceList->setAttribute('active_member_count', 0);

        return response()->json(['data' => $this->present($audienceList)], 201);
    }

    public function addMember(Request $request, string $workspaceId, string $list): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $audienceList = $this->findList($request, $list);
        $data = $request->validate(['contact_id' => ['required', 'uuid']]);
        $contact = $workspace->contacts()->whereKey($data['contact_id'])->firstOrFail();

        return $this->changeMembership($request, $workspace, $audienceList, $contact, 'added');
    }

    public function importMembers(Request $request, string $workspaceId, string $list): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $audienceList = $this->findList($request, $list);
        $data = $request->validate([
            'addresses' => ['required_without:file', 'prohibits:file', 'nullable', 'string'],
            'file' => ['required_without:addresses', 'prohibits:addresses', 'file', 'mimes:txt,csv'],
        ]);

        $lookupKey = config('alphalink.contact_lookup_key');
        if (! is_string($lookupKey) || strlen($lookupKey) < 32) {
            return response()->json([
                'message' => 'Contact storage is not configured. Set a dedicated lookup key before importing personal data.',
                'code' => 'contact_storage_unavailable',
            ], 503);
        }

        $batch = isset($data['file'])
            ? $this->contactImporter->handleAddressFile($request, $workspace, $audienceList, $data['file'], $lookupKey)
            : $this->contactImporter->handle($request, $workspace, $audienceList, $data['addresses'], $lookupKey);

        return response()->json(['data' => $batch->statusPayload()], 202);
    }

    public function importStatus(Request $request, string $workspaceId, string $list, string $batchId): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $audienceList = $this->findList($request, $list);
        $batch = AudienceListImportBatch::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('audience_list_id', $audienceList->getKey())
            ->whereKey($batchId)
            ->firstOrFail();

        return response()->json(['data' => $batch->statusPayload()]);
    }

    public function removeMember(Request $request, string $workspaceId, string $list, string $contact): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $audienceList = $this->findList($request, $list);
        $contact = $workspace->contacts()->whereKey($contact)->firstOrFail();

        return $this->changeMembership($request, $workspace, $audienceList, $contact, 'removed');
    }

    private function changeMembership(Request $request, Workspace $workspace, AudienceList $list, Contact $contact, string $action): JsonResponse
    {
        $idempotencyKey = $this->idempotencyKey($request);
        $fingerprint = $this->fingerprint([
            'list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(),
            'action' => $action,
        ]);
        $existingEvent = ContactListMembershipEvent::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingEvent) {
            abort_unless(hash_equals($existingEvent->request_fingerprint, $fingerprint), 409, 'Idempotency key was already used for a different membership request.');

            return response()->json(['data' => [
                'list_id' => $list->getKey(),
                'contact_id' => $contact->getKey(),
                'status' => $action === 'added' ? 'active' : 'removed',
                'event_id' => $existingEvent->getKey(),
            ]], 200);
        }

        [$membership, $event, $changed] = DB::transaction(function () use ($request, $workspace, $list, $contact, $action, $idempotencyKey, $fingerprint): array {
            // Serializes tenant-scoped idempotency checks and first membership inserts.
            Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();

            $membership = ContactListMembership::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('audience_list_id', $list->getKey())
                ->where('contact_id', $contact->getKey())
                ->lockForUpdate()
                ->first();

            $existingEvent = ContactListMembershipEvent::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existingEvent) {
                abort_unless(hash_equals($existingEvent->request_fingerprint, $fingerprint), 409, 'Idempotency key was already used for a different membership request.');

                return [$membership, $existingEvent, false];
            }

            $changed = ! (($action === 'added' && $membership?->status === 'active')
                || ($action === 'removed' && $membership?->status !== 'active'));

            $now = now();
            $event = ContactListMembershipEvent::query()->create([
                'workspace_id' => $workspace->getKey(),
                'audience_list_id' => $list->getKey(),
                'contact_id' => $contact->getKey(),
                'action' => $action,
                'source' => 'operator',
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'actor_user_id' => $request->user()->getKey(),
                'occurred_at' => $now,
                'created_at' => $now,
            ]);

            if ($changed) {
                $membership ??= new ContactListMembership;
                $membership->fill([
                    'workspace_id' => $workspace->getKey(),
                    'audience_list_id' => $list->getKey(),
                    'contact_id' => $contact->getKey(),
                    'status' => $action === 'added' ? 'active' : 'removed',
                    'joined_at' => $action === 'added' ? $now : ($membership->joined_at ?? $now),
                    'left_at' => $action === 'removed' ? $now : null,
                    'last_event_id' => $event->getKey(),
                ])->save();
            }

            $this->writeAuditAndOutbox($request, $workspace, 'audience_list.member_'.$action, $contact->getKey(), [
                'audience_list_id' => $list->getKey(),
                'membership_event_id' => $event->getKey(),
            ], [
                'audience_list_id' => $list->getKey(),
                'contact_id' => $contact->getKey(),
                'membership_event_id' => $event->getKey(),
            ], $changed);

            return [$membership, $event, $changed];
        });

        return response()->json(['data' => [
            'list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(),
            'status' => $action === 'added' ? 'active' : 'removed',
            'event_id' => $event?->getKey(),
        ]], $changed ? 201 : 200);
    }

    private function writeAuditAndOutbox(Request $request, Workspace $workspace, string $action, string $subjectId, array $properties, array $payload, bool $enqueue = true): void
    {
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => $action,
            'subject_type' => str_starts_with($action, 'audience_list.member_') ? 'contact' : 'audience_list',
            'subject_id' => $subjectId,
            'request_id' => $request->attributes->get('request_id'),
            'properties' => $properties,
            'occurred_at' => now(),
        ]);

        if (! $enqueue) {
            return;
        }

        OutboxMessage::query()->create([
            'workspace_id' => $workspace->getKey(),
            'event_type' => $action,
            'payload' => $payload,
            'idempotency_key' => (string) Str::uuid(),
            'available_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function findList(Request $request, string $list): AudienceList
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');

        return $workspace->audienceLists()->where('status', 'active')->whereKey($list)->firstOrFail();
    }

    private function present(AudienceList $list): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'slug' => $list->slug,
            'description' => $list->description,
            'status' => $list->status,
            'isp_id' => $list->isp_id,
            'country' => $list->country,
            'active_member_count' => (int) ($list->active_member_count ?? 0),
            'created_at' => $list->created_at?->toISOString(),
        ];
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return $local === '' || $domain === '' ? '[redacted]' : substr($local, 0, 1).'***@'.$domain;
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || strlen($key) < 16 || strlen($key) > 128) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['Provide a unique key between 16 and 128 characters.'],
            ]);
        }

        return $key;
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
