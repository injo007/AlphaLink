<?php

namespace App\Services\Analytics;

use App\Models\AudienceList;
use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRecipientSnapshotItem;
use App\Models\Contact;
use App\Models\ContactListMembershipEvent;
use App\Models\ManagedServer;
use App\Models\WorkspaceDrop;
use App\Services\Audiences\AudienceListHistoryCalculator;
use App\Services\Campaigns\TrackedCountryListManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ImportDmsPmtaFeedback
{
    private const SOURCE_LOOKUP_BATCH_SIZE = 100;

    public function __construct(
        private readonly DmsPmtaFeedbackCsvParser $parser,
        private readonly WorkspaceDailyAnalyticsRebuilder $dailyAnalytics,
        private readonly TrackedCountryListManager $membershipManager,
        private readonly AudienceListHistoryCalculator $listHistory,
    ) {}

    /**
     * @param  resource  $stream
     * @return array{imported: int, duplicate: int, unmatched: int, affected_list_ids?: list<string>}
     */
    public function import(ManagedServer $server, $stream, string $kind, string $sourceFileId, string $date, bool $deferDerivedRebuild = false): array
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException('The PMTA accounting date must be valid.');
        }
        if (! $server->is_active || ! $server->is_installed) {
            throw new InvalidArgumentException('PMTA feedback requires an active, installed AlphaLink server.');
        }

        $counts = ['imported' => 0, 'duplicate' => 0, 'unmatched' => 0];
        $workspaceId = (string) $server->workspace_id;
        $serverId = (string) $server->getKey();
        $changedListIds = [];
        $batch = [];

        foreach ($this->parser->parseStream($stream, $kind, $sourceFileId) as $record) {
            $batch[] = $record;
            if (count($batch) === self::SOURCE_LOOKUP_BATCH_SIZE) {
                $this->importBatch($workspaceId, $serverId, $date, $batch, $counts, $changedListIds);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->importBatch($workspaceId, $serverId, $date, $batch, $counts, $changedListIds);
        }

        if (! $deferDerivedRebuild && $counts['imported'] > 0) {
            $this->rebuildDerivedData($workspaceId, $date, array_keys($changedListIds));
        }

        return $deferDerivedRebuild
            ? [...$counts, 'affected_list_ids' => array_keys($changedListIds)]
            : $counts;
    }

    /** @param list<string> $listIds */
    public function rebuildDerivedData(string $workspaceId, string $date, array $listIds): void
    {
        $day = Carbon::parse($date);
        $this->dailyAnalytics->rebuildRange($day, $day, $workspaceId);
        foreach (array_unique($listIds) as $listId) {
            $list = AudienceList::query()->where('workspace_id', $workspaceId)->whereKey($listId)->first();
            if ($list !== null) {
                $this->listHistory->calculate($list);
            }
        }
    }

    /**
     * @param  list<array<string, string|null>>  $records
     * @param  array{imported: int, duplicate: int, unmatched: int}  $counts
     * @param  array<string, true>  $changedListIds
     */
    private function importBatch(string $workspaceId, string $serverId, string $date, array $records, array &$counts, array &$changedListIds): void
    {
        $sources = $this->resolveSources($workspaceId, $serverId, $records);
        $importable = [];

        foreach ($records as $index => $record) {
            $source = $sources[$index] ?? null;
            if ($this->uuid($record['server_id']) !== $serverId || $source === null) {
                $counts['unmatched']++;

                continue;
            }

            $importable[] = ['record' => $record, 'source' => $source];
            if ($source['list_id'] !== null && in_array($record['metric'], ['deliveries', 'hard_bounces'], true)) {
                // Include duplicate rows so a retry can refresh list history if a
                // worker stopped after the event transaction committed.
                $changedListIds[$source['list_id']] = true;
            }
        }

        if ($importable === []) {
            return;
        }

        $result = DB::transaction(function () use ($workspaceId, $serverId, $date, $importable): array {
            // Serialize ingestion for one PMTA server so a replay cannot race the
            // existence check and apply a recipient transition twice.
            $lockedServer = DB::table('managed_servers')
                ->where('workspace_id', $workspaceId)
                ->where('id', $serverId)
                ->lockForUpdate()
                ->first(['id']);
            if ($lockedServer === null) {
                throw new RuntimeException('The PMTA feedback server is no longer available.');
            }

            $recordKeys = array_values(array_unique(array_map(
                fn (array $entry): string => $entry['record']['record_key'],
                $importable,
            )));
            $existingKeys = DB::table('workspace_pmta_feedback_events')
                ->where('workspace_id', $workspaceId)
                ->where('managed_server_id', $serverId)
                ->whereIn('record_key', $recordKeys)
                ->pluck('record_key')
                ->map(fn ($key): string => (string) $key)
                ->all();
            $existingKeys = array_fill_keys($existingKeys, true);

            $newRecords = [];
            $batchDuplicateCount = 0;
            foreach ($importable as $entry) {
                $record = $entry['record'];
                if (isset($existingKeys[$record['record_key']])) {
                    $batchDuplicateCount++;

                    continue;
                }

                $newRecords[$record['record_key']] = $entry;
                $existingKeys[$record['record_key']] = true;
            }

            $eventRows = [];
            foreach ($newRecords as $recordKey => $entry) {
                $record = $entry['record'];
                $source = $entry['source'];
                $eventRows[] = [
                    'workspace_id' => $workspaceId,
                    'managed_server_id' => $serverId,
                    'record_key' => $recordKey,
                    'at' => $date,
                    'source_type' => $source['type'],
                    'source_id' => $source['id'],
                    'campaign_id' => $source['type'] === 'campaign' ? $source['id'] : null,
                    'workspace_drop_id' => $source['type'] === 'drop' ? (int) $source['id'] : null,
                    'metric' => $record['metric'],
                    'category' => $record['category'],
                    'snapshot_id' => $source['snapshot_id'],
                    'contact_id' => $source['contact_id'],
                    'source_list_id' => $source['list_id'],
                    'list_type' => $this->emailType($record['list_type']),
                    'dimension_ids' => json_encode([
                        'isp_id' => $record['isp_id'],
                        'server_id' => $serverId,
                        'ip_id' => $record['ip_id'],
                        'domain_id' => $record['domain_id'],
                        'from_id' => $record['from_id'],
                        'subject_id' => $record['subject_id'],
                        'creative_id' => $record['creative_id'],
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ];
            }

            $insertedCount = $eventRows === []
                ? 0
                : DB::table('workspace_pmta_feedback_events')->insertOrIgnore($eventRows);
            if ($insertedCount !== count($newRecords)) {
                // A writer outside this importer bypassed the per-server lock.
                // Roll back and retry so newly inserted events are never
                // mistaken for duplicates and skipped for recipient transitions.
                throw new RuntimeException('The PMTA feedback batch changed during import; retry the batch.');
            }

            $transitionedListIds = [];
            foreach ($newRecords as $entry) {
                $record = $entry['record'];
                $source = $entry['source'];
                if ($record['metric'] === 'deliveries' || $record['metric'] === 'hard_bounces') {
                    $listId = $this->transitionRecipient($workspaceId, $source, $record);
                    if ($listId !== null) {
                        $transitionedListIds[$listId] = true;
                    }
                }
            }

            return [
                'imported' => $insertedCount,
                'duplicate' => $batchDuplicateCount,
                'affected_list_ids' => array_keys($transitionedListIds),
            ];
        });

        $counts['imported'] += $result['imported'];
        $counts['duplicate'] += $result['duplicate'];
        foreach ($result['affected_list_ids'] as $listId) {
            $changedListIds[$listId] = true;
        }
    }

    /**
     * Resolve source ownership in bounded, workspace-scoped queries per chunk.
     *
     * @param  list<array<string, string|null>>  $records
     * @return array<int, array{type: string, id: string, snapshot_id: ?string, contact_id: ?string, list_id: ?string}|null>
     */
    private function resolveSources(string $workspaceId, string $serverId, array $records): array
    {
        $sources = array_fill(0, count($records), null);
        $campaignIds = [];
        $snapshotIds = [];
        $dropIds = [];
        $dropContactIds = [];
        $campaignTuples = [];

        foreach ($records as $index => $record) {
            if ($this->uuid($record['server_id']) !== $serverId) {
                continue;
            }

            if ($record['type'] === 'c') {
                $campaignId = $this->uuid($record['source_id']);
                $snapshotId = $this->uuid($record['snapshot_id']);
                $contactId = $this->uuid($record['recipient_id']);
                $listId = $this->uuid($record['list_id']);
                if ($campaignId === null || $snapshotId === null || $contactId === null || $listId === null) {
                    continue;
                }

                $campaignIds[$campaignId] = true;
                $snapshotIds[$snapshotId] = true;
                $campaignTuples[$index] = [$campaignId, $snapshotId, $contactId, $listId, $this->emailType($record['list_type'])];
            } elseif ($record['type'] === 'd') {
                $dropId = filter_var($record['source_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($dropId === false) {
                    continue;
                }

                $dropIds[(string) $dropId] = true;
                $contactId = $this->uuid($record['recipient_id']);
                if ($contactId !== null) {
                    $dropContactIds[$contactId] = true;
                }
            } elseif ($record['type'] === 'w') {
                $warmupId = $this->uuid($record['source_id']);
                if ($warmupId !== null) {
                    $campaignIds[$warmupId] = true;
                }
            }
        }

        $campaigns = $campaignIds === []
            ? []
            : Campaign::query()->where('workspace_id', $workspaceId)->whereIn('id', array_keys($campaignIds))
                ->get(['id', 'kind'])->keyBy(fn (Campaign $campaign): string => (string) $campaign->getKey())->all();
        $snapshots = $snapshotIds === []
            ? []
            : CampaignRecipientSnapshot::query()->where('workspace_id', $workspaceId)->whereIn('id', array_keys($snapshotIds))
                ->get(['id', 'campaign_id'])->keyBy(fn (CampaignRecipientSnapshot $snapshot): string => (string) $snapshot->getKey())->all();

        $validItemTuples = [];
        foreach ($campaignTuples as [$campaignId, $snapshotId, $contactId, $listId, $emailType]) {
            if (($campaigns[$campaignId] ?? null) !== null
                && (string) ($snapshots[$snapshotId]->campaign_id ?? '') === $campaignId) {
                $validItemTuples[$this->snapshotItemKey($snapshotId, $contactId, $listId, $emailType)] = [$snapshotId, $contactId, $listId, $emailType];
            }
        }

        $itemKeys = [];
        if ($validItemTuples !== []) {
            $itemQuery = CampaignRecipientSnapshotItem::query()->where('workspace_id', $workspaceId)
                ->where(function ($query) use ($validItemTuples): void {
                    foreach ($validItemTuples as [$snapshotId, $contactId, $listId, $emailType]) {
                        $query->orWhere(function ($tuple) use ($snapshotId, $contactId, $listId, $emailType): void {
                            $tuple->where('snapshot_id', $snapshotId)
                                ->where('contact_id', $contactId)
                                ->where('source_list_id', $listId)
                                ->where('source_email_type', $emailType);
                        });
                    }
                });
            foreach ($itemQuery->get(['snapshot_id', 'contact_id', 'source_list_id', 'source_email_type']) as $item) {
                $itemKeys[$this->snapshotItemKey((string) $item->snapshot_id, (string) $item->contact_id, (string) $item->source_list_id, (string) $item->source_email_type)] = true;
            }
        }

        $existingDropIds = $dropIds === []
            ? []
            : WorkspaceDrop::query()->where('workspace_id', $workspaceId)->whereIn('id', array_keys($dropIds))->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $existingDropIds = array_fill_keys($existingDropIds, true);
        $existingDropContactIds = $dropContactIds === []
            ? []
            : Contact::query()->where('workspace_id', $workspaceId)->whereIn('id', array_keys($dropContactIds))->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $existingDropContactIds = array_fill_keys($existingDropContactIds, true);

        foreach ($records as $index => $record) {
            if ($this->uuid($record['server_id']) !== $serverId) {
                continue;
            }

            if (isset($campaignTuples[$index])) {
                [$campaignId, $snapshotId, $contactId, $listId, $emailType] = $campaignTuples[$index];
                if (($campaigns[$campaignId] ?? null) !== null
                    && (string) ($snapshots[$snapshotId]->campaign_id ?? '') === $campaignId
                    && isset($itemKeys[$this->snapshotItemKey($snapshotId, $contactId, $listId, $emailType)])) {
                    $sources[$index] = ['type' => 'campaign', 'id' => $campaignId, 'snapshot_id' => $snapshotId, 'contact_id' => $contactId, 'list_id' => $listId];
                }

                continue;
            }

            if ($record['type'] === 'd') {
                $dropId = filter_var($record['source_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($dropId !== false && isset($existingDropIds[(string) $dropId])) {
                    $contactId = $this->uuid($record['recipient_id']);
                    $listId = $this->uuid($record['list_id']);
                    if ($contactId !== null && ! isset($existingDropContactIds[$contactId])) {
                        $contactId = null;
                    }

                    $sources[$index] = ['type' => 'drop', 'id' => (string) $dropId, 'snapshot_id' => null, 'contact_id' => $contactId, 'list_id' => $listId];
                }

                continue;
            }

            if ($record['type'] === 'w') {
                $warmupId = $this->uuid($record['source_id']);
                if ($warmupId !== null && ($campaigns[$warmupId]->kind ?? null) === 'warmup') {
                    $sources[$index] = ['type' => 'warmup', 'id' => $warmupId, 'snapshot_id' => null, 'contact_id' => null, 'list_id' => null];
                }
            }
        }

        return $sources;
    }

    private function snapshotItemKey(string $snapshotId, string $contactId, string $listId, string $emailType): string
    {
        return implode("\0", [$snapshotId, $contactId, $listId, $emailType]);
    }

    private function uuid(string $value): ?string
    {
        $compact = strtolower(str_replace('-', '', $value));
        if (! preg_match('/^[a-f0-9]{32}$/', $compact)) {
            return null;
        }

        return substr($compact, 0, 8).'-'.substr($compact, 8, 4).'-'.substr($compact, 12, 4).'-'.substr($compact, 16, 4).'-'.substr($compact, 20);
    }

    private function emailType(string $value): string
    {
        return match (strtolower(substr($value, 0, 1))) {
            'f' => 'fresh', 'd' => 'delivery', 'o' => 'open', 'c' => 'click',
            'l' => 'lead', 'u' => 'unsubscribe', default => $value,
        };
    }

    /** @param array{type: string, id: string, snapshot_id: ?string, contact_id: ?string, list_id: ?string} $source
     * @param  array<string, string|null>  $record
     */
    private function transitionRecipient(string $workspaceId, array $source, array $record): ?string
    {
        $contactId = $source['contact_id'];
        $listId = $source['list_id'];
        if ($contactId === null || $listId === null || ! in_array($record['metric'], ['deliveries', 'hard_bounces'], true)) {
            return null;
        }

        $membership = $this->membershipManager->currentMembership($workspaceId, $contactId, $listId);
        if ($membership === null) {
            return null;
        }

        $oldType = $membership->email_type;
        $newType = match ($record['metric']) {
            'deliveries' => $oldType === 'fresh' ? 'delivery' : null,
            'hard_bounces' => $oldType !== 'lead' ? 'hard_bounce' : null,
            default => null,
        };
        if ($newType === null || $newType === $oldType) {
            return null;
        }

        $event = ContactListMembershipEvent::query()->create([
            'workspace_id' => $workspaceId,
            'audience_list_id' => $membership->audience_list_id,
            'contact_id' => $contactId,
            'action' => $newType,
            'source' => 'pmta_feedback',
            'idempotency_key' => 'pmta:'.$record['record_key'],
            'request_fingerprint' => hash('sha256', implode('|', [$record['record_key'], $oldType, $newType])),
            'actor_user_id' => null,
            'occurred_at' => now(),
            'metadata' => [
                'source_type' => $source['type'],
                'source_id' => $source['id'],
                'from_type' => $oldType,
                'to_type' => $newType,
                'record_key' => $record['record_key'],
            ],
            'created_at' => now(),
        ]);
        $membership->forceFill(['email_type' => $newType, 'last_event_id' => $event->getKey()])->save();

        return (string) $membership->audience_list_id;
    }
}
