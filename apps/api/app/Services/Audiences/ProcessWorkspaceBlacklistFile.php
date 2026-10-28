<?php

namespace App\Services\Audiences;

use App\Jobs\ProcessWorkspaceBlacklistCheck;
use App\Models\AudienceList;
use App\Models\ContactListMembership;
use App\Models\WorkspaceBlacklistProcess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ProcessWorkspaceBlacklistFile
{
    private const INPUTS_PER_JOB = 500;

    private const MEMBERSHIPS_PER_UPDATE = 200;

    public function __construct(private readonly AudienceListHistoryCalculator $historyCalculator) {}

    public function processNextChunk(string $processId): void
    {
        $result = [
            'next' => false,
            'delete_path' => null,
            'recalculate' => false,
        ];

        DB::transaction(function () use ($processId, &$result): void {
            $process = WorkspaceBlacklistProcess::query()->whereKey($processId)->lockForUpdate()->first();
            if ($process === null) {
                return;
            }

            if ($process->status === 'cancelling') {
                $process->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
                $result['delete_path'] = $process->storage_path;
                $result['recalculate'] = $process->history_recalculated_at === null;

                return;
            }

            if (in_array($process->status, ['completed', 'cancelled', 'failed'], true)) {
                $result['delete_path'] = $process->storage_path;
                $result['recalculate'] = $process->history_recalculated_at === null;

                return;
            }

            $absolutePath = Storage::disk('local')->path($process->storage_path);
            $stream = fopen($absolutePath, 'rb');
            if ($stream === false) {
                throw new RuntimeException('The staged blacklist file is unavailable.');
            }

            try {
                if (fseek($stream, (int) $process->byte_offset) !== 0) {
                    throw new RuntimeException('Unable to resume the staged blacklist file.');
                }

                $processed = 0;
                $invalid = 0;
                $hashes = [];
                while ($processed < self::INPUTS_PER_JOB && ($line = fgets($stream)) !== false) {
                    $process->byte_offset = (int) ftell($stream);
                    $value = $this->emailValue($line, $process->original_filename);
                    if ($value === '') {
                        continue;
                    }

                    $processed++;
                    if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                        $invalid++;

                        continue;
                    }

                    $hashes[] = hash_hmac('sha256', $value, $this->lookupKey());
                }

                $lookupHashes = array_values(array_unique($hashes));
                $contactIds = $lookupHashes === [] ? [] : DB::table('contacts')
                    ->where('workspace_id', $process->workspace_id)
                    ->whereIn('email_lookup_hash', $lookupHashes)
                    ->pluck('id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->all();

                $updatedMemberships = $this->updateMembershipTypes($process, $contactIds);
                if ($updatedMemberships['list_ids'] !== []) {
                    DB::table('workspace_blacklist_process_lists')->insertOrIgnore(array_map(
                        static fn (string $listId): array => [
                            'workspace_id' => $process->workspace_id,
                            'process_id' => $process->getKey(),
                            'audience_list_id' => $listId,
                        ],
                        $updatedMemberships['list_ids'],
                    ));
                }

                $process->forceFill([
                    'status' => feof($stream) ? 'completed' : 'running',
                    'processed_count' => (int) $process->processed_count + $processed,
                    'matched_count' => (int) $process->matched_count + count($contactIds),
                    'updated_count' => (int) $process->updated_count + $updatedMemberships['count'],
                    'invalid_count' => (int) $process->invalid_count + $invalid,
                    'started_at' => $process->started_at ?? now(),
                    'finished_at' => feof($stream) ? now() : null,
                ])->save();

                if ($process->status === 'completed') {
                    $result['delete_path'] = $process->storage_path;
                    $result['recalculate'] = true;
                } else {
                    $result['next'] = true;
                }
            } finally {
                fclose($stream);
            }
        }, 3);

        if (is_string($result['delete_path'])) {
            Storage::disk('local')->delete($result['delete_path']);
        }

        if ($result['recalculate']) {
            $this->recalculateAffectedLists($processId);
        }

        if ($result['next']) {
            ProcessWorkspaceBlacklistCheck::dispatch($processId);
        }
    }

    public function requestCancellation(string $processId, string $workspaceId): string
    {
        $deletePath = null;
        $status = DB::transaction(function () use ($processId, $workspaceId, &$deletePath): string {
            $process = WorkspaceBlacklistProcess::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($processId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($process->status === 'queued') {
                $process->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
                $deletePath = $process->storage_path;
            } elseif ($process->status === 'running') {
                $process->forceFill(['status' => 'cancelling'])->save();
            }

            return $process->status;
        });

        if (is_string($deletePath)) {
            Storage::disk('local')->delete($deletePath);
        }

        return $status;
    }

    public function fail(string $processId): void
    {
        $deletePath = null;
        DB::transaction(function () use ($processId, &$deletePath): void {
            $process = WorkspaceBlacklistProcess::query()->whereKey($processId)->lockForUpdate()->first();
            if ($process === null || in_array($process->status, ['completed', 'cancelled'], true)) {
                return;
            }

            $process->forceFill([
                'status' => 'failed',
                'failure_message' => 'The file check could not be completed. Upload the file again or contact support with the process ID.',
                'finished_at' => now(),
            ])->save();
            $deletePath = $process->storage_path;
        });

        if (is_string($deletePath)) {
            Storage::disk('local')->delete($deletePath);
        }

        $this->recalculateAffectedLists($processId);
    }

    /** @param list<string> $contactIds
     * @return array{count: int, list_ids: list<string>}
     */
    private function updateMembershipTypes(WorkspaceBlacklistProcess $process, array $contactIds): array
    {
        if ($contactIds === []) {
            return ['count' => 0, 'list_ids' => []];
        }

        $updated = 0;
        $listIds = [];
        ContactListMembership::query()
            ->where('workspace_id', $process->workspace_id)
            ->whereIn('contact_id', $contactIds)
            ->where('email_type', '!=', 'lead')
            ->where('email_type', '!=', $process->email_type)
            ->orderBy('id')
            ->lockForUpdate()
            ->chunkById(self::MEMBERSHIPS_PER_UPDATE, function (Collection $memberships) use ($process, &$updated, &$listIds): void {
                $now = now()->toDateTimeString();
                $events = [];
                $when = [];
                $whereIds = [];
                $caseBindings = [];

                foreach ($memberships as $membership) {
                    $eventId = (string) Str::uuid();
                    $membershipId = (string) $membership->getKey();
                    $fromType = (string) $membership->email_type;
                    $fingerprint = hash('sha256', implode('|', [$process->getKey(), $membershipId, $fromType, $process->email_type]));
                    $events[] = [
                        'id' => $eventId,
                        'workspace_id' => $process->workspace_id,
                        'audience_list_id' => $membership->audience_list_id,
                        'contact_id' => $membership->contact_id,
                        'action' => 'type_changed',
                        'source' => 'admin_blacklist_check',
                        'idempotency_key' => 'blacklist-check:'.$process->getKey().':'.$membershipId,
                        'request_fingerprint' => $fingerprint,
                        'actor_user_id' => $process->created_by,
                        'occurred_at' => $now,
                        'metadata' => json_encode([
                            'process_id' => (string) $process->getKey(),
                            'from_type' => $fromType,
                            'to_type' => $process->email_type,
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                    ];
                    $when[] = 'WHEN ? THEN ?';
                    $whereIds[] = $membershipId;
                    $caseBindings[] = $membershipId;
                    $caseBindings[] = $eventId;
                    $listIds[] = (string) $membership->audience_list_id;
                }

                DB::table('contact_list_membership_events')->insert($events);
                $placeholders = implode(', ', array_fill(0, count($whereIds), '?'));
                $sql = 'UPDATE contact_list_memberships '
                    .'SET email_type = ?, last_event_id = CASE id '.implode(' ', $when).' ELSE last_event_id END, updated_at = ? '
                    .'WHERE workspace_id = ? AND id IN ('.$placeholders.')';
                $bindings = [$process->email_type, ...$caseBindings, $now, $process->workspace_id, ...$whereIds];
                $updated += DB::update($sql, $bindings);
            }, 'id');

        return ['count' => $updated, 'list_ids' => array_values(array_unique($listIds))];
    }

    private function recalculateAffectedLists(string $processId): void
    {
        $process = WorkspaceBlacklistProcess::query()->whereKey($processId)->first();
        if ($process === null || $process->history_recalculated_at !== null) {
            return;
        }

        DB::table('workspace_blacklist_process_lists')
            ->where('workspace_id', $process->workspace_id)
            ->where('process_id', $processId)
            ->orderBy('audience_list_id')
            ->chunkById(100, function ($rows) use ($process): void {
                $listIds = $rows->pluck('audience_list_id')->map(static fn (mixed $id): string => (string) $id)->all();
                AudienceList::query()
                    ->where('workspace_id', $process->workspace_id)
                    ->whereIn('id', $listIds)
                    ->orderBy('id')
                    ->get(['id', 'workspace_id'])
                    ->each(fn (AudienceList $list) => $this->historyCalculator->calculate($list));
            }, 'audience_list_id');

        WorkspaceBlacklistProcess::query()
            ->whereKey($processId)
            ->whereNull('history_recalculated_at')
            ->update(['history_recalculated_at' => now()]);
    }

    private function emailValue(string $line, string $filename): string
    {
        $line = trim($line);
        if ($line === '') {
            return '';
        }

        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'csv') {
            $columns = str_getcsv($line);
            $line = (string) ($columns[0] ?? '');
        }

        return mb_strtolower(trim($line));
    }

    private function lookupKey(): string
    {
        $key = (string) config('alphalink.contact_lookup_key');
        if (strlen($key) < 32) {
            throw new RuntimeException('The workspace contact lookup key is not configured.');
        }

        return $key;
    }
}
