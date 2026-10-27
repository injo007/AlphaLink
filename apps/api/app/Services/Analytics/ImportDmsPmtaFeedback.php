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

class ImportDmsPmtaFeedback
{
    public function __construct(
        private readonly DmsPmtaFeedbackCsvParser $parser,
        private readonly WorkspaceDailyAnalyticsRebuilder $dailyAnalytics,
        private readonly TrackedCountryListManager $membershipManager,
        private readonly AudienceListHistoryCalculator $listHistory,
    ) {}

    /**
     * @param  resource  $stream
     * @return array{imported: int, duplicate: int, unmatched: int}
     */
    public function import(ManagedServer $server, $stream, string $kind, string $sourceFileId, string $date): array
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

        foreach ($this->parser->parseStream($stream, $kind, $sourceFileId) as $record) {
            if ($this->uuid($record['server_id']) !== $serverId) {
                $counts['unmatched']++;

                continue;
            }

            $source = $this->source($workspaceId, $record);
            if ($source === null) {
                $counts['unmatched']++;

                continue;
            }

            $written = DB::transaction(function () use ($workspaceId, $serverId, $date, $record, $source, &$changedListIds): int {
                $written = DB::table('workspace_pmta_feedback_events')->insertOrIgnore([
                    'workspace_id' => $workspaceId,
                    'managed_server_id' => $serverId,
                    'record_key' => $record['record_key'],
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
                ]);
                if ($written === 1) {
                    $changedListId = $this->transitionRecipient($workspaceId, $source, $record);
                    if ($changedListId !== null) {
                        $changedListIds[$changedListId] = true;
                    }
                }

                return $written;
            });
            $counts[$written === 1 ? 'imported' : 'duplicate']++;
        }

        if ($counts['imported'] > 0) {
            $day = Carbon::parse($date);
            $this->dailyAnalytics->rebuildRange($day, $day, $workspaceId);
            foreach (array_keys($changedListIds) as $listId) {
                $list = AudienceList::query()->where('workspace_id', $workspaceId)->whereKey($listId)->first();
                if ($list !== null) {
                    $this->listHistory->calculate($list);
                }
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, string|null>  $record
     * @return array{type: string, id: string, snapshot_id: ?string, contact_id: ?string, list_id: ?string}|null
     */
    private function source(string $workspaceId, array $record): ?array
    {
        if ($record['type'] === 'c') {
            $campaignId = $this->uuid($record['source_id']);
            $snapshotId = $this->uuid($record['snapshot_id']);
            $contactId = $this->uuid($record['recipient_id']);
            $listId = $this->uuid($record['list_id']);
            if ($campaignId === null || $snapshotId === null || $contactId === null || $listId === null
                || ! Campaign::query()->where('workspace_id', $workspaceId)->whereKey($campaignId)->exists()
                || ! CampaignRecipientSnapshot::query()->where('workspace_id', $workspaceId)->whereKey($snapshotId)->where('campaign_id', $campaignId)->exists()
                || ! CampaignRecipientSnapshotItem::query()->where('workspace_id', $workspaceId)
                    ->where('snapshot_id', $snapshotId)->where('contact_id', $contactId)
                    ->where('source_list_id', $listId)->where('source_email_type', $this->emailType($record['list_type']))->exists()) {
                return null;
            }

            return ['type' => 'campaign', 'id' => $campaignId, 'snapshot_id' => $snapshotId, 'contact_id' => $contactId, 'list_id' => $listId];
        }

        $dropId = filter_var($record['source_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($record['type'] === 'd' && $dropId !== false
            && WorkspaceDrop::query()->where('workspace_id', $workspaceId)->whereKey($dropId)->exists()) {
            $contactId = $this->uuid($record['recipient_id']);
            $listId = $this->uuid($record['list_id']);
            if ($contactId !== null && ! Contact::query()->where('workspace_id', $workspaceId)->whereKey($contactId)->exists()) {
                $contactId = null;
            }

            return ['type' => 'drop', 'id' => $record['source_id'], 'snapshot_id' => null, 'contact_id' => $contactId, 'list_id' => $listId ?? $record['list_id']];
        }

        return null;
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
