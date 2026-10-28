<?php

namespace App\Services\Audiences;

use App\Jobs\ProcessAudienceListImportBatch;
use App\Models\AudienceList;
use App\Models\AudienceListImportBatch;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\ContactListMembershipEvent;
use App\Models\OutboxMessage;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImportAudienceListContacts
{
    private const CANDIDATES_PER_JOB = 500;

    public function __construct(
        private readonly AudienceListHistoryCalculator $historyCalculator,
        private readonly AudienceMembershipFilterIndexer $filterIndexer,
    ) {}

    public function handle(Request $request, Workspace $workspace, AudienceList $list, string $rawAddresses, string $lookupKey): AudienceListImportBatch
    {
        $this->assertLookupKey($lookupKey);
        $path = $this->newImportPath($workspace, $list, 'txt');
        $absolutePath = Storage::disk('local')->path($path);
        (new Filesystem)->ensureDirectoryExists(dirname($absolutePath), 0700, true);
        $stream = fopen($absolutePath, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Unable to stage the audience import.');
        }

        try {
            $this->writeAddressTokens($stream, $rawAddresses);
        } catch (RuntimeException $exception) {
            fclose($stream);
            @unlink($absolutePath);
            throw $exception;
        }
        fclose($stream);

        return $this->createBatch($request, $workspace, $list, $path, 'addresses', 'fresh', null);
    }

    public function handleAddressFile(Request $request, Workspace $workspace, AudienceList $list, UploadedFile $file, string $lookupKey): AudienceListImportBatch
    {
        $this->assertLookupKey($lookupKey);
        $path = $this->newImportPath($workspace, $list, 'txt');
        $absolutePath = Storage::disk('local')->path($path);
        (new Filesystem)->ensureDirectoryExists(dirname($absolutePath), 0700, true);
        $input = fopen($file->getRealPath(), 'rb');
        $output = fopen($absolutePath, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($absolutePath);
            throw new RuntimeException('Unable to stage the audience import.');
        }

        try {
            $this->writeAddressStream($input, $output);
        } catch (RuntimeException $exception) {
            fclose($input);
            fclose($output);
            @unlink($absolutePath);
            throw $exception;
        }
        fclose($input);
        fclose($output);

        return $this->createBatch($request, $workspace, $list, $path, 'addresses', 'fresh', null);
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
    ): AudienceListImportBatch {
        $this->assertLookupKey($lookupKey);
        if (! in_array($type, ContactListMembership::CAMPAIGN_TYPES, true)) {
            throw new RuntimeException('Unsupported DMS list type.');
        }

        $path = $file->storeAs(
            'audience-imports/'.$workspace->getKey().'/'.$list->getKey(),
            (string) Str::uuid().'.'.$file->getClientOriginalExtension(),
            'local',
        );
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Unable to stage the audience import.');
        }

        return $this->createBatch($request, $workspace, $list, $path, 'dms_csv', $type, $filter);
    }

    public function processNextBatchChunk(string $batchId): void
    {
        $batch = AudienceListImportBatch::query()->find($batchId);
        if (! $batch || in_array($batch->status, ['completed', 'failed'], true)) {
            return;
        }

        $absolutePath = Storage::disk('local')->path($batch->storage_path);
        if (! $batch->checksum_verified) {
            $digest = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : false;
            if (! is_string($digest)) {
                $this->failBatch($batchId, 'The staged import file could not be verified. Upload it again.');

                return;
            }
        } else {
            $digest = $batch->file_sha256;
        }

        $finished = false;
        $continue = false;
        try {
            DB::transaction(function () use ($batchId, $absolutePath, $digest, &$finished, &$continue): void {
                $batch = AudienceListImportBatch::query()->whereKey($batchId)->lockForUpdate()->first();
                if (! $batch || in_array($batch->status, ['completed', 'failed'], true)) {
                    return;
                }
                if (! is_file($absolutePath) || filesize($absolutePath) !== $batch->file_size_bytes) {
                    throw new RuntimeException('The staged audience import file is missing.');
                }

                $batch->checksum_verified = true;
                $batch->file_sha256 ??= $digest;
                $batch->started_at ??= now();
                $batch->next_dispatch_at = now()->addMinutes(10);
                $batch->status = $batch->phase === 'validation' ? 'validating' : 'importing';
                $stream = fopen($absolutePath, 'rb');
                if ($stream === false) {
                    throw new RuntimeException('Unable to read the staged audience import.');
                }

                $offsetField = $batch->phase === 'validation' ? 'validation_offset' : 'import_offset';
                $offset = (int) $batch->{$offsetField};
                if (fseek($stream, $offset) !== 0) {
                    fclose($stream);
                    throw new RuntimeException('Unable to resume the staged audience import.');
                }

                $workspace = Workspace::query()->findOrFail($batch->workspace_id);
                $list = AudienceList::query()->where('workspace_id', $workspace->getKey())->findOrFail($batch->audience_list_id);
                $request = $this->batchRequest($batch);
                $lookupKey = config('alphalink.contact_lookup_key');
                $this->assertLookupKey(is_string($lookupKey) ? $lookupKey : '');
                $seen = [];
                $rows = 0;
                $atEnd = false;

                while ($rows < self::CANDIDATES_PER_JOB && ! feof($stream)) {
                    $line = fgets($stream);
                    if ($line === false) {
                        $atEnd = true;
                        break;
                    }

                    $rows++;
                    $offset = ftell($stream);
                    $parsed = $this->parseCandidate($batch, $line);
                    if (($batch->source_format === 'dms_csv' && $batch->phase === 'validation')
                        || ($batch->source_format === 'addresses' && $batch->phase === 'import')) {
                        $batch->processed_count++;
                    }

                    if ($parsed['status'] === 'ignored') {
                        continue;
                    }
                    if ($parsed['status'] === 'invalid') {
                        if ($batch->phase === 'validation' || $batch->source_format === 'addresses') {
                            $batch->invalid_count++;
                        }

                        continue;
                    }
                    if ($parsed['status'] === 'filtered') {
                        if ($batch->phase === 'validation' || $batch->source_format === 'addresses') {
                            $batch->filtered_count++;
                        }

                        continue;
                    }

                    if ($batch->phase === 'validation') {
                        $batch->valid_count++;

                        continue;
                    }

                    $email = $parsed['email'];
                    if (isset($seen[$email])) {
                        $batch->duplicate_count++;

                        continue;
                    }
                    $seen[$email] = true;
                    $result = $this->importOne(
                        $request,
                        $workspace,
                        $list,
                        $email,
                        hash_hmac('sha256', $email, $lookupKey),
                        $parsed['profile'],
                        $batch->membership_type,
                    );
                    $batch->{$result === 'imported' ? 'imported_count' : 'duplicate_count'}++;
                    if ($batch->source_format === 'addresses') {
                        $batch->valid_count++;
                    }
                }

                $atEnd = $atEnd || feof($stream);
                fclose($stream);
                $batch->{$offsetField} = $offset;

                if ($atEnd && $batch->phase === 'validation') {
                    if ($batch->valid_count === 0) {
                        $batch->status = 'completed';
                        $batch->finished_at = now();
                        $batch->next_dispatch_at = null;
                        $finished = true;
                    } else {
                        $batch->phase = 'import';
                        $batch->status = 'importing';
                        $batch->import_offset = 0;
                        $batch->next_dispatch_at = now();
                        $continue = true;
                    }
                } elseif ($atEnd) {
                    $batch->status = 'completed';
                    $batch->finished_at = now();
                    $batch->next_dispatch_at = null;
                    $finished = true;
                } else {
                    $batch->next_dispatch_at = now()->addMinutes(10);
                    $continue = true;
                }

                $batch->save();
            }, attempts: 3);
        } catch (\InvalidArgumentException $exception) {
            $this->failBatch($batchId, $exception->getMessage());

            return;
        }

        if ($continue) {
            ProcessAudienceListImportBatch::dispatch($batchId)->onQueue('audience-imports');
        }
        if ($finished) {
            $freshBatch = AudienceListImportBatch::query()->find($batchId);
            if ($freshBatch) {
                $this->historyCalculator->calculate($freshBatch->audienceList);
                Storage::disk('local')->delete($freshBatch->storage_path);
            }
        }
    }

    public function failBatch(string $batchId, string $message): void
    {
        $storagePath = DB::transaction(function () use ($batchId, $message): ?string {
            $batch = AudienceListImportBatch::query()->whereKey($batchId)->lockForUpdate()->first();
            if (! $batch || in_array($batch->status, ['completed', 'failed'], true)) {
                return null;
            }

            $batch->forceFill([
                'status' => 'failed',
                'failure_message' => Str::limit($message, 255),
                'finished_at' => now(),
                'next_dispatch_at' => null,
            ])->save();

            return $batch->storage_path;
        });

        if ($storagePath !== null) {
            Storage::disk('local')->delete($storagePath);
        }
    }

    private function createBatch(
        Request $request,
        Workspace $workspace,
        AudienceList $list,
        string $path,
        string $sourceFormat,
        string $type,
        ?string $filter,
    ): AudienceListImportBatch {
        $absolutePath = Storage::disk('local')->path($path);
        $size = filesize($absolutePath);
        if (! is_int($size)) {
            Storage::disk('local')->delete($path);
            throw new RuntimeException('Unable to verify the staged audience import.');
        }

        try {
            $batch = AudienceListImportBatch::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->getKey(),
                'audience_list_id' => $list->getKey(),
                'created_by' => $request->user()?->getKey(),
                'status' => 'queued',
                'phase' => $sourceFormat === 'dms_csv' ? 'validation' : 'import',
                'source_format' => $sourceFormat,
                'membership_type' => $type,
                'filter' => $filter,
                'storage_path' => $path,
                'file_sha256' => null,
                'file_size_bytes' => $size,
                'request_id' => $request->attributes->get('request_id'),
                'next_dispatch_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        ProcessAudienceListImportBatch::dispatch($batch->getKey())->onQueue('audience-imports');

        return $batch->fresh();
    }

    /** @param resource $stream */
    private function writeAddressTokens($stream, string $addresses): void
    {
        $length = strlen($addresses);
        $offset = 0;
        while ($offset < $length) {
            $tokenLength = strcspn($addresses, ",\r\n", $offset);
            if ($tokenLength > 0) {
                $token = $tokenLength > 320 ? str_repeat('x', 321) : substr($addresses, $offset, $tokenLength);
                $this->writeAll($stream, $token."\n");
            }

            $offset += $tokenLength;
            if ($offset < $length && $addresses[$offset] === "\r" && $offset + 1 < $length && $addresses[$offset + 1] === "\n") {
                $offset++;
            }
            $offset++;
        }
    }

    /** @param resource $input @param resource $output */
    private function writeAddressStream($input, $output): void
    {
        $carry = '';
        $discardingLongToken = false;
        while (! feof($input)) {
            $chunk = fread($input, 65_536);
            if (! is_string($chunk)) {
                throw new RuntimeException('Unable to read the audience import.');
            }
            if ($chunk === '') {
                break;
            }

            $parts = preg_split('/[,\r\n]+/', $carry.$chunk) ?: [];
            $carry = array_pop($parts) ?? '';
            if ($discardingLongToken) {
                if ($parts === []) {
                    $carry = '';

                    continue;
                }
                array_shift($parts);
                $discardingLongToken = false;
            }
            foreach ($parts as $token) {
                $this->writeAddressToken($output, $token);
            }
            if (strlen($carry) > 320) {
                $this->writeAddressToken($output, str_repeat('x', 321));
                $carry = '';
                $discardingLongToken = true;
            }
        }

        if (! $discardingLongToken) {
            $this->writeAddressToken($output, $carry);
        }
    }

    /** @param resource $stream */
    private function writeAddressToken($stream, string $token): void
    {
        if ($token !== '') {
            $this->writeAll($stream, $token."\n");
        }
    }

    /** @param resource $stream */
    private function writeAll($stream, string $value): void
    {
        $written = 0;
        $length = strlen($value);
        while ($written < $length) {
            $result = fwrite($stream, substr($value, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('Unable to stage the audience import.');
            }
            $written += $result;
        }
    }

    private function newImportPath(Workspace $workspace, AudienceList $list, string $extension): string
    {
        return 'audience-imports/'.$workspace->getKey().'/'.$list->getKey().'/'.Str::uuid().'.'.$extension;
    }

    private function parseCandidate(AudienceListImportBatch $batch, string $line): array
    {
        if ($batch->source_format === 'addresses') {
            $email = mb_strtolower(trim($line));
            if ($email === '') {
                return ['status' => 'ignored'];
            }

            return strlen($email) > 320 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
                ? ['status' => 'invalid']
                : ['status' => 'valid', 'email' => $email, 'profile' => null];
        }

        $fields = explode(',', trim($line));
        $rawEmail = (string) ($fields[0] ?? '');
        if ($rawEmail === '') {
            return ['status' => 'ignored'];
        }
        if (str_contains($rawEmail, '|')) {
            return ['status' => 'invalid'];
        }

        $email = mb_strtolower($rawEmail);
        if (strlen($email) > 320 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['status' => 'invalid'];
        }

        // Preserve the observed DMS argument order for this substring filter.
        if ($batch->filter && ! Str::contains($batch->filter, $email)) {
            return ['status' => 'filtered'];
        }

        $age = trim((string) ($fields[4] ?? ''));
        if ($age !== '' && (! ctype_digit($age) || (int) $age > 32767)) {
            throw new \InvalidArgumentException('The age column must be a whole number between 0 and 32767. No rows were imported.');
        }

        $profile = array_filter([
            'first_name' => trim((string) ($fields[1] ?? '')),
            'last_name' => trim((string) ($fields[2] ?? '')),
            'country' => trim((string) ($fields[3] ?? '')),
            'age' => $age === '' ? null : (int) $age,
            'sex' => trim((string) ($fields[5] ?? '')),
            'vertical' => trim((string) ($fields[6] ?? '')),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return ['status' => 'valid', 'email' => $email, 'profile' => $profile];
    }

    private function batchRequest(AudienceListImportBatch $batch): Request
    {
        $request = Request::create('/audience-list-imports/'.$batch->getKey(), 'POST');
        $request->attributes->set('request_id', $batch->request_id);
        $actor = $batch->createdBy;
        $request->setUserResolver(static fn () => $actor);

        return $request;
    }

    private function assertLookupKey(string $lookupKey): void
    {
        if (strlen($lookupKey) < 32) {
            throw new RuntimeException('The contact lookup key must contain at least 32 characters.');
        }
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

        $membershipProfile = $profile ?? ($membership?->exists ? $membership->profile : []);
        $indexProfile = $membershipProfile ?? $contact->profile ?? [];
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
            'profile' => $membershipProfile,
            ...$this->filterIndexer->values(
                $workspace->getKey(),
                $list->getKey(),
                $indexProfile,
                (string) $contact->email,
            ),
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
