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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SplFileObject;

class ImportAudienceListContacts
{
    private const MAX_CANDIDATES = 5000;

    public function __construct(private readonly AudienceListHistoryCalculator $historyCalculator) {}

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
                $result = $this->importOne(
                    $request,
                    $workspace,
                    $list,
                    $email,
                    hash_hmac('sha256', $email, $lookupKey),
                    null,
                    'fresh',
                );
                $counts[$result === 'imported' ? 'imported_count' : 'duplicate_count']++;
            }
        }

        $this->historyCalculator->calculate($list);

        return $counts;
    }

    /**
     * Import DMS-format TXT/CSV rows: comma-separated columns with the address
     * first, followed by first name, last name, country, age, sex, and vertical.
     * Invalid rows are ignored and addresses are normalized before lookup, as
     * in DMS ListsAction::process_list().
     *
     * @return array{processed: int, valid: int, filtered: int, imported_count: int, duplicate_count: int, invalid_count: int}
     */
    public function handleDmsFile(
        Request $request,
        Workspace $workspace,
        AudienceList $list,
        UploadedFile $file,
        string $type,
        ?string $filter,
        string $lookupKey,
    ): array {
        if (strlen($lookupKey) < 32) {
            throw new RuntimeException('The contact lookup key must contain at least 32 characters.');
        }

        if (! in_array($type, ContactListMembership::CAMPAIGN_TYPES, true)) {
            throw new RuntimeException('Unsupported DMS list type.');
        }

        $counts = [
            'processed' => 0,
            'valid' => 0,
            'filtered' => 0,
            'imported_count' => 0,
            'duplicate_count' => 0,
            'invalid_count' => 0,
        ];
        $rows = [];
        $candidates = 0;
        $lines = new SplFileObject($file->getRealPath(), 'r');

        while (! $lines->eof()) {
            $line = trim($lines->fgets());
            $counts['processed']++;
            $fields = explode(',', $line);
            $rawEmail = (string) ($fields[0] ?? '');

            if ($rawEmail === '') {
                continue;
            }

            $candidates++;
            if ($candidates > self::MAX_CANDIDATES) {
                throw ValidationException::withMessages([
                    'file' => ['Import at most 5,000 address rows in one upload.'],
                ]);
            }

            if (str_contains($rawEmail, '|')) {
                $counts['invalid_count']++;

                continue;
            }

            $email = mb_strtolower($rawEmail);
            if (strlen($email) > 320 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $counts['invalid_count']++;

                continue;
            }

            // DMS uses Str::contains($filter, $email) here. Preserve the
            // observed argument order so an imported file is interpreted the
            // same way by the selected original version.
            if ($filter && ! Str::contains($filter, $email)) {
                $counts['filtered']++;

                continue;
            }

            $age = trim((string) ($fields[4] ?? ''));
            if ($age !== '' && (! ctype_digit($age) || (int) $age > 32767)) {
                throw ValidationException::withMessages([
                    'file' => ['The age column must be a whole number between 0 and 32767. No rows were imported.'],
                ]);
            }

            $profile = array_filter([
                'first_name' => trim((string) ($fields[1] ?? '')),
                'last_name' => trim((string) ($fields[2] ?? '')),
                'country' => trim((string) ($fields[3] ?? '')),
                'age' => $age === '' ? null : (int) $age,
                'sex' => trim((string) ($fields[5] ?? '')),
                'vertical' => trim((string) ($fields[6] ?? '')),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            $rows[] = [$email, $profile];
            $counts['valid']++;
        }

        foreach ($rows as [$email, $profile]) {
            $result = $this->importOne(
                $request,
                $workspace,
                $list,
                $email,
                hash_hmac('sha256', $email, $lookupKey),
                $profile,
                $type,
            );
            $counts[$result === 'imported' ? 'imported_count' : 'duplicate_count']++;
        }

        $this->historyCalculator->calculate($list);

        return $counts;
    }

    private function importOne(
        Request $request,
        Workspace $workspace,
        AudienceList $list,
        string $email,
        string $emailHash,
        ?array $profile,
        string $type,
    ): string {
        try {
            return DB::transaction(fn (): string => $this->importWithinTransaction(
                $request, $workspace, $list, $email, $emailHash, $profile, $type,
            ));
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return DB::transaction(function () use ($request, $workspace, $list, $emailHash, $profile, $type, $exception): string {
                Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
                $contact = $workspace->contacts()
                    ->where('email_lookup_hash', $emailHash)
                    ->lockForUpdate()
                    ->first();

                if (! $contact) {
                    throw $exception;
                }

                return $this->attachToList($request, $workspace, $list, $contact, $type, $profile);
            });
        }
    }

    private function importWithinTransaction(
        Request $request,
        Workspace $workspace,
        AudienceList $list,
        string $email,
        string $emailHash,
        ?array $profile,
        string $type,
    ): string {
        // Serialize tenant-scoped identity creation without revealing whether another workspace has a person.
        Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();

        $contact = $workspace->contacts()
            ->where('email_lookup_hash', $emailHash)
            ->lockForUpdate()
            ->first();

        if (! $contact) {
            $contact = $workspace->contacts()->create([
                'email' => $email,
                'email_lookup_hash' => $emailHash,
                'lookup_key_version' => config('alphalink.contact_lookup_key_version'),
                'profile' => $profile,
                'status' => 'active',
                'idempotency_key' => (string) Str::uuid(),
            ]);

            $this->recordContactCreated($request, $workspace, $contact);
        }

        return $this->attachToList($request, $workspace, $list, $contact, $type, $profile);
    }

    private function attachToList(
        Request $request,
        Workspace $workspace,
        AudienceList $list,
        Contact $contact,
        string $type,
        ?array $profile,
    ): string {
        $membership = ContactListMembership::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('audience_list_id', $list->getKey())
            ->where('contact_id', $contact->getKey())
            ->lockForUpdate()
            ->first();

        if ($membership?->status === 'active' || $membership?->email_type === 'opt_out') {
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

        $membership ??= new ContactListMembership;
        $membership->fill([
            'workspace_id' => $workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(),
            'email_type' => $type,
            'status' => 'active',
            'joined_at' => $now,
            'left_at' => null,
            'last_event_id' => $event->getKey(),
            'profile' => $profile ?? ($membership->exists ? $membership->profile : []),
        ])->save();

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
