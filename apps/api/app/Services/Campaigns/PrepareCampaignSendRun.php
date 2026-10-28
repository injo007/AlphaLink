<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRevision;
use App\Models\CampaignSendRun;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Persist an idempotent, non-dispatching campaign send plan.
 *
 * This intentionally does not start a worker or contact a mail transport. The
 * native sender contract, consent policy, and transport readiness still gate
 * execution; this record gives that later path a durable, immutable input.
 */
class PrepareCampaignSendRun
{
    private const SEND_TYPES = ['delay', 'delayp', 'delayps', 'hs', 'hsp', 'hsps', 'pickup', 'pickups'];

    /**
     * @throws JsonException
     */
    public function create(
        Campaign $campaign,
        CampaignRevision $revision,
        CampaignRecipientSnapshot $snapshot,
        User $actor,
        string $idempotencyKey,
    ): CampaignSendRun {
        if ($revision->campaign_id !== $campaign->getKey()
            || $snapshot->campaign_id !== $campaign->getKey()
            || $revision->getKey() !== $snapshot->revision_id
            || $campaign->workspace_id !== $snapshot->workspace_id) {
            throw ValidationException::withMessages(['campaign' => 'The selected campaign revision and recipient snapshot do not match.']);
        }

        $missingSnapshotAddress = DB::table('campaign_recipient_snapshot_items as item')
            ->leftJoin('campaign_recipient_snapshot_item_addresses as address', function ($join): void {
                $join->on('address.snapshot_item_id', '=', 'item.id')
                    ->on('address.workspace_id', '=', 'item.workspace_id');
            })
            ->where('item.workspace_id', $campaign->workspace_id)
            ->where('item.snapshot_id', $snapshot->getKey())
            ->where(function ($query): void {
                $query->whereNull('address.snapshot_item_id')
                    ->orWhereColumn('address.email_lookup_hash', '!=', 'item.email_lookup_hash');
            })
            ->exists();
        if ($missingSnapshotAddress) {
            throw ValidationException::withMessages(['recipient_snapshot' => 'This recipient snapshot does not contain a complete, verified point-in-time address set. Rebuild the campaign revision before sending.']);
        }

        $sendType = data_get($revision->payload, 'send_setup.send_type');
        if (! is_string($sendType) || ! in_array($sendType, self::SEND_TYPES, true)) {
            throw ValidationException::withMessages(['send_type' => 'The saved campaign revision does not contain a supported DMS send type.']);
        }

        $recipientCount = (int) $snapshot->recipient_count;
        $startFrom = (int) $snapshot->start_from;
        if ($recipientCount < 1 || $startFrom >= $recipientCount) {
            throw ValidationException::withMessages(['recipients' => 'The immutable recipient snapshot has no recipients remaining to plan.']);
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid send-plan idempotency key is required.']);
        }

        $fingerprint = hash('sha256', json_encode([
            'campaign_id' => (string) $campaign->getKey(),
            'revision_id' => (string) $revision->getKey(),
            'snapshot_id' => (string) $snapshot->getKey(),
            'send_type' => $sendType,
            'recipient_count' => $recipientCount,
            'start_from' => $startFrom,
            'recipients_sha256' => (string) $snapshot->recipients_sha256,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($campaign, $revision, $snapshot, $actor, $idempotencyKey, $fingerprint, $sendType, $recipientCount, $startFrom): CampaignSendRun {
            $runId = (string) Str::uuid();
            $now = now();
            $inserted = DB::table('campaign_send_runs')->insertOrIgnore([
                'id' => $runId,
                'workspace_id' => $campaign->workspace_id,
                'campaign_id' => $campaign->getKey(),
                'revision_id' => $revision->getKey(),
                'recipient_snapshot_id' => $snapshot->getKey(),
                'requested_by' => $actor->getKey(),
                'send_type' => $sendType,
                'state' => 'planned',
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'execution_options' => json_encode(['start_from' => $startFrom], JSON_THROW_ON_ERROR),
                'recipient_count' => $recipientCount - $startFrom,
                'start_position' => $startFrom + 1,
                'next_position' => $startFrom + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $run = CampaignSendRun::query()
                ->where('workspace_id', $campaign->workspace_id)
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            if (! hash_equals((string) $run->request_fingerprint, $fingerprint)) {
                throw ValidationException::withMessages(['idempotency_key' => 'This send-plan key was already used with different campaign data.']);
            }

            if ($inserted === 1) {
                OutboxMessage::query()->create([
                    'workspace_id' => $campaign->workspace_id,
                    'event_type' => 'campaign.send.plan_created',
                    'payload' => [
                        'send_run_id' => $run->getKey(),
                        'campaign_id' => $campaign->getKey(),
                        'revision_id' => $revision->getKey(),
                        'recipient_snapshot_id' => $snapshot->getKey(),
                        'recipient_count' => $recipientCount - $startFrom,
                    ],
                    'idempotency_key' => 'campaign-send-plan-created:'.$run->getKey(),
                    'created_at' => $now,
                ]);
            }

            return $run;
        }, attempts: 3);
    }
}
