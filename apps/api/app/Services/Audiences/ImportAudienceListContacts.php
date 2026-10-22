<?php

namespace App\Services\Audiences;

use App\Models\AudienceList;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\ContactListMembershipEvent;
use App\Models\OutboxMessage;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ImportAudienceListContacts
{
    private const MAX_CANDIDATES = 5000;

    /**
     * @return array{imported_count: int, duplicate_count: int, invalid_count: int}
     */
    public function handle(Request $request, Workspace $workspace, AudienceList $list, string $rawAddresses, string $lookupKey): array
    {
        if (strlen($lookupKey) < 32) {
            throw new RuntimeException('The contact lookup key must contain at least 32 characters.');
        }

        $counts = ['imported_count' => 0, 'duplicate_count' => 0, 'invalid_count' => 0];
        $seen = [];
        $lines = preg_split('/\r\n|\r|\n/', $rawAddresses) ?: [];
        $candidateCount = 0;

        foreach ($lines as $line) {
            foreach (explode(',', $line) as $candidate) {
                if (trim($candidate) === '') {
                    continue;
                }

                $candidateCount++;
                if ($candidateCount > self::MAX_CANDIDATES) {
                    throw ValidationException::withMessages([
                        'addresses' => ['Import at most 5,000 addresses in one request.'],
                    ]);
                }
            }
        }

        foreach ($lines as $line) {
            foreach (explode(',', $line) as $candidate) {
                $email = mb_strtolower(trim($candidate));
                if ($email === '') {
                    continue;
                }

                if (strlen($email) > 320 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $counts['invalid_count']++;

                    continue;
                }

                if (isset($seen[$email])) {
                    $counts['duplicate_count']++;

                    continue;
                }

                $seen[$email] = true;
                $result = $this->importOne($request, $workspace, $list, $email, hash_hmac('sha256', $email, $lookupKey));
                $counts[$result === 'imported' ? 'imported_count' : 'duplicate_count']++;
            }
        }

        return $counts;
    }

    private function importOne(Request $request, Workspace $workspace, AudienceList $list, string $email, string $emailHash): string
    {
        try {
            return DB::transaction(fn (): string => $this->importWithinTransaction(
                $request,
                $workspace,
                $list,
                $email,
                $emailHash,
            ));
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $contact = $workspace->contacts()->where('email_lookup_hash', $emailHash)->first();
            if (! $contact) {
                throw $exception;
            }

            try {
                return DB::transaction(fn (): string => $this->attachIfUnassigned($request, $workspace, $list, $contact));
            } catch (QueryException $retryException) {
                if (! $this->isUniqueViolation($retryException)) {
                    throw $retryException;
                }

                $alreadyAssigned = ContactListMembership::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->where('contact_id', $contact->getKey())
                    ->exists();

                if ($alreadyAssigned) {
                    return 'duplicate';
                }

                throw $retryException;
            }
        }
    }

    private function importWithinTransaction(Request $request, Workspace $workspace, AudienceList $list, string $email, string $emailHash): string
    {
        $contact = $workspace->contacts()
            ->where('email_lookup_hash', $emailHash)
            ->lockForUpdate()
            ->first();
        $wasCreated = $contact === null;

        if ($wasCreated) {
            $contact = $workspace->contacts()->create([
                'email' => $email,
                'email_lookup_hash' => $emailHash,
                'lookup_key_version' => config('alphalink.contact_lookup_key_version'),
                'status' => 'active',
                'idempotency_key' => (string) Str::uuid(),
            ]);

            $this->recordContactCreated($request, $workspace, $contact);
        }

        return $this->attachIfUnassigned($request, $workspace, $list, $contact);
    }

    private function attachIfUnassigned(Request $request, Workspace $workspace, AudienceList $list, Contact $contact): string
    {
        $existingMembership = ContactListMembership::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('contact_id', $contact->getKey())
            ->lockForUpdate()
            ->first();

        if ($existingMembership) {
            return 'duplicate';
        }

        $now = now();
        $event = ContactListMembershipEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(),
            'action' => 'added',
            'source' => 'import',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', implode(':', [$list->getKey(), $contact->getKey(), 'added', 'import'])),
            'actor_user_id' => $request->user()?->getKey(),
            'occurred_at' => $now,
            'metadata' => null,
            'created_at' => $now,
        ]);

        ContactListMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(),
            'status' => 'active',
            'joined_at' => $now,
            'left_at' => null,
            'last_event_id' => $event->getKey(),
        ]);

        $this->recordMembershipAdded($request, $workspace, $list, $contact, $event);

        return 'imported';
    }

    private function recordContactCreated(Request $request, Workspace $workspace, Contact $contact): void
    {
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()?->getKey(),
            'action' => 'contact.created',
            'subject_type' => 'contact',
            'subject_id' => $contact->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => ['email_redacted' => true, 'source' => 'audience_list_import'],
            'occurred_at' => now(),
        ]);

        OutboxMessage::query()->create([
            'workspace_id' => $workspace->getKey(),
            'event_type' => 'contact.created',
            'payload' => ['contact_id' => $contact->getKey()],
            'idempotency_key' => (string) Str::uuid(),
            'available_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function recordMembershipAdded(Request $request, Workspace $workspace, AudienceList $list, Contact $contact, ContactListMembershipEvent $event): void
    {
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()?->getKey(),
            'action' => 'audience_list.member_added',
            'subject_type' => 'contact',
            'subject_id' => $contact->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => [
                'audience_list_id' => $list->getKey(),
                'membership_event_id' => $event->getKey(),
                'source' => 'import',
            ],
            'occurred_at' => now(),
        ]);

        OutboxMessage::query()->create([
            'workspace_id' => $workspace->getKey(),
            'event_type' => 'audience_list.member_added',
            'payload' => [
                'audience_list_id' => $list->getKey(),
                'contact_id' => $contact->getKey(),
                'membership_event_id' => $event->getKey(),
            ],
            'idempotency_key' => (string) Str::uuid(),
            'available_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }
}
