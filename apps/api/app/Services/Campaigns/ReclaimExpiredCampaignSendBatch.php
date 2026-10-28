<?php

namespace App\Services\Campaigns;

use App\Models\CampaignDeliveryAttempt;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignSendBatch;
use App\Models\CampaignSendRecipientClaim;
use App\Models\CampaignSendRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reassign only unsent recipients from an expired batch and quarantine in-flight attempts.
 */
class ReclaimExpiredCampaignSendBatch
{
    private const TERMINAL_CLAIM_STATES = ['accepted', 'rejected', 'suppressed', 'uncertain'];

    public function __construct(private readonly FinalizeCampaignSendRun $finalizer) {}

    public function reclaim(string $batchId, string $workerId, int $leaseSeconds = 300): ?CampaignSendBatch
    {
        if (! Str::isUuid($batchId)) {
            throw ValidationException::withMessages(['batch_id' => 'A valid batch identifier is required.']);
        }
        if (trim($workerId) === '' || strlen($workerId) > 128) {
            throw ValidationException::withMessages(['worker_id' => 'A worker identifier from 1 to 128 characters is required.']);
        }
        if ($leaseSeconds < 30 || $leaseSeconds > 3600) {
            throw ValidationException::withMessages(['lease_seconds' => 'A claim lease must last between 30 and 3,600 seconds.']);
        }

        return DB::transaction(function () use ($batchId, $workerId, $leaseSeconds): ?CampaignSendBatch {
            $candidate = CampaignSendBatch::query()->whereKey($batchId)->firstOrFail();
            $run = CampaignSendRun::query()
                ->where('workspace_id', $candidate->workspace_id)
                ->whereKey($candidate->send_run_id)
                ->lockForUpdate()
                ->firstOrFail();

            $startedAttempts = CampaignDeliveryAttempt::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('send_run_id', $run->getKey())
                ->where('batch_id', $batchId)
                ->where('state', 'started')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $batch = CampaignSendBatch::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('send_run_id', $run->getKey())
                ->whereKey($batchId)
                ->lockForUpdate()
                ->firstOrFail();
            $now = now();

            if ($run->state !== 'running'
                || $batch->state !== 'leased'
                || $batch->lease_expires_at === null
                || $batch->lease_expires_at->greaterThan($now)) {
                return null;
            }
            if (! is_string($batch->lease_token) || ! is_string($batch->lease_owner)) {
                throw ValidationException::withMessages(['lease' => 'The expired batch has incomplete lease ownership data.']);
            }

            $claims = CampaignSendRecipientClaim::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('send_run_id', $run->getKey())
                ->where('batch_id', $batch->getKey())
                ->orderBy('position')
                ->lockForUpdate()
                ->get()
                ->keyBy('snapshot_item_id');
            $expectedClaimCount = (int) $batch->last_position - (int) $batch->first_position + 1;
            if ($claims->count() !== $expectedClaimCount) {
                throw ValidationException::withMessages(['recipient_claim' => 'The expired batch has a missing or duplicate recipient claim.']);
            }

            $sendingItemIds = $claims->where('state', 'sending')->keys()->map(fn ($itemId): string => (string) $itemId)->sort()->values()->all();
            $attemptItemIds = $startedAttempts->pluck('snapshot_item_id')->map(fn ($itemId): string => (string) $itemId)->sort()->values()->all();

            if ($sendingItemIds !== $attemptItemIds) {
                throw ValidationException::withMessages(['send_attempt' => 'The expired batch has an in-flight recipient without exactly one started delivery attempt.']);
            }

            $oldLeaseTokenHash = hash('sha256', $batch->lease_token);
            foreach ($startedAttempts as $attempt) {
                $claim = $claims->get((string) $attempt->snapshot_item_id);
                if ($claim === null
                    || $attempt->worker_id !== $batch->lease_owner
                    || ! is_string($attempt->lease_token_sha256)
                    || ! hash_equals($attempt->lease_token_sha256, $oldLeaseTokenHash)
                    || ! is_string($claim->lease_token)
                    || ! hash_equals($claim->lease_token, $batch->lease_token)) {
                    throw ValidationException::withMessages(['lease' => 'The expired attempt and recipient claim do not match the batch lease.']);
                }
            }

            $pendingClaims = $claims->where('state', 'claimed');
            foreach ($claims as $claim) {
                if (! in_array($claim->state, [...self::TERMINAL_CLAIM_STATES, 'claimed', 'sending'], true)) {
                    throw ValidationException::withMessages(['recipient_claim' => 'The expired batch contains an unsupported recipient-claim state.']);
                }
                if ($claim->state === 'claimed'
                    && (! is_string($claim->lease_token)
                        || ! hash_equals($claim->lease_token, $batch->lease_token)
                        || (int) $claim->claim_count >= 65535)) {
                    throw ValidationException::withMessages(['lease' => 'An unstarted recipient claim cannot be safely reassigned.']);
                }
            }

            foreach ($startedAttempts as $attempt) {
                $claim = $claims->get((string) $attempt->snapshot_item_id);
                $attempt->state = 'uncertain';
                $attempt->error_code = 'lease_expired_before_outcome';
                $attempt->finished_at = $now;
                $attempt->save();

                $claim->state = 'uncertain';
                $claim->lease_token = null;
                $claim->lease_expires_at = null;
                $claim->last_transition_at = $now;
                $claim->save();
            }

            $uncertainCount = $startedAttempts->count();
            $batch->uncertain_count = (int) $batch->uncertain_count + $uncertainCount;
            $run->uncertain_count = (int) $run->uncertain_count + $uncertainCount;

            if ($pendingClaims->isNotEmpty()) {
                $newLeaseToken = (string) Str::uuid();
                $newLeaseExpiresAt = $now->copy()->addSeconds($leaseSeconds);
                CampaignSendRecipientClaim::query()
                    ->where('workspace_id', $run->workspace_id)
                    ->where('send_run_id', $run->getKey())
                    ->where('batch_id', $batch->getKey())
                    ->where('state', 'claimed')
                    ->update([
                        'lease_token' => $newLeaseToken,
                        'lease_expires_at' => $newLeaseExpiresAt,
                        'claim_count' => DB::raw('claim_count + 1'),
                        'last_transition_at' => $now,
                        'updated_at' => $now,
                    ]);
                $batch->state = 'leased';
                $batch->lease_owner = $workerId;
                $batch->lease_token = $newLeaseToken;
                $batch->lease_expires_at = $newLeaseExpiresAt;
                $batch->completed_at = null;
            } else {
                $batch->state = (int) $batch->uncertain_count > 0 ? 'uncertain' : 'completed';
                $batch->lease_owner = null;
                $batch->lease_token = null;
                $batch->lease_expires_at = null;
                $batch->completed_at = $now;
            }

            $batch->claim_count = (int) $batch->claim_count + 1;
            $batch->save();
            $run->save();

            $snapshot = CampaignRecipientSnapshot::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('campaign_id', $run->campaign_id)
                ->whereKey($run->recipient_snapshot_id)
                ->firstOrFail();
            $this->finalizer->reconcile($run, $snapshot, $now);

            return $batch->load('recipientClaims');
        }, attempts: 3);
    }
}
