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
 * Persist the boundary before and after one transport operation without doing transport I/O.
 */
class CampaignDeliveryAttemptLifecycle
{
    private const TERMINAL_OUTCOMES = ['accepted', 'rejected', 'uncertain'];

    private const TERMINAL_CLAIM_STATES = ['accepted', 'rejected', 'suppressed', 'uncertain'];

    public function __construct(private readonly FinalizeCampaignSendRun $finalizer) {}

    public function start(
        string $sendRunId,
        string $batchId,
        string $snapshotItemId,
        string $workerId,
        string $leaseToken,
        string $idempotencyKey,
    ): CampaignDeliveryAttempt {
        if (! Str::isUuid($sendRunId) || ! Str::isUuid($batchId) || ! Str::isUuid($snapshotItemId)) {
            throw ValidationException::withMessages(['send_attempt' => 'The send run, batch, and snapshot item identifiers must be UUIDs.']);
        }
        if (trim($workerId) === '' || strlen($workerId) > 128) {
            throw ValidationException::withMessages(['worker_id' => 'A worker identifier from 1 to 128 characters is required.']);
        }
        if (! Str::isUuid($leaseToken)) {
            throw ValidationException::withMessages(['lease_token' => 'A valid batch lease token is required.']);
        }
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 160) {
            throw ValidationException::withMessages(['idempotency_key' => 'An attempt idempotency key from 1 to 160 characters is required.']);
        }

        $leaseTokenHash = hash('sha256', $leaseToken);

        return DB::transaction(function () use ($sendRunId, $batchId, $snapshotItemId, $workerId, $leaseToken, $leaseTokenHash, $idempotencyKey): CampaignDeliveryAttempt {
            $run = CampaignSendRun::query()->whereKey($sendRunId)->lockForUpdate()->firstOrFail();
            $existingAttempt = CampaignDeliveryAttempt::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingAttempt !== null) {
                $sameAttempt = (string) $existingAttempt->send_run_id === $sendRunId
                    && (string) $existingAttempt->batch_id === $batchId
                    && (string) $existingAttempt->snapshot_item_id === $snapshotItemId
                    && $existingAttempt->worker_id === $workerId
                    && is_string($existingAttempt->lease_token_sha256)
                    && hash_equals($existingAttempt->lease_token_sha256, $leaseTokenHash);

                if (! $sameAttempt) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This attempt key was already used for different send data.']);
                }

                if ($existingAttempt->state === 'started') {
                    throw ValidationException::withMessages(['send_attempt' => 'This attempt already crossed its durable start boundary; do not submit it to the transport again.']);
                }

                return $existingAttempt;
            }

            if ($run->state !== 'running') {
                throw ValidationException::withMessages(['send_run' => 'The send run is not active for delivery attempts.']);
            }

            $batch = CampaignSendBatch::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('send_run_id', $run->getKey())
                ->whereKey($batchId)
                ->lockForUpdate()
                ->firstOrFail();
            $claim = CampaignSendRecipientClaim::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('send_run_id', $run->getKey())
                ->where('snapshot_item_id', $snapshotItemId)
                ->where('batch_id', $batch->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $now = now();

            if ($batch->state !== 'leased'
                || $batch->lease_owner !== $workerId
                || ! is_string($batch->lease_token)
                || ! hash_equals($batch->lease_token, $leaseToken)
                || $batch->lease_expires_at === null
                || $batch->lease_expires_at->lessThanOrEqualTo($now)) {
                throw ValidationException::withMessages(['lease' => 'The worker does not hold an active lease for this batch.']);
            }
            if ($claim->state !== 'claimed'
                || ! is_string($claim->lease_token)
                || ! hash_equals($claim->lease_token, $leaseToken)
                || $claim->lease_expires_at === null
                || $claim->lease_expires_at->lessThanOrEqualTo($now)) {
                throw ValidationException::withMessages(['recipient_claim' => 'The recipient is not available under this active batch lease.']);
            }

            $attemptNumber = (int) (CampaignDeliveryAttempt::query()
                ->where('send_run_id', $run->getKey())
                ->where('snapshot_item_id', $snapshotItemId)
                ->max('attempt_number') ?? 0) + 1;
            if ($attemptNumber > 65535) {
                throw ValidationException::withMessages(['attempt_number' => 'The delivery attempt sequence is exhausted.']);
            }

            $attempt = CampaignDeliveryAttempt::query()->create([
                'workspace_id' => $run->workspace_id,
                'send_run_id' => $run->getKey(),
                'snapshot_item_id' => $claim->snapshot_item_id,
                'batch_id' => $batch->getKey(),
                'attempt_number' => $attemptNumber,
                'state' => 'started',
                'idempotency_key' => $idempotencyKey,
                'worker_id' => $workerId,
                'lease_token_sha256' => $leaseTokenHash,
                'started_at' => $now,
            ]);

            $claim->state = 'sending';
            $claim->last_transition_at = $now;
            $claim->save();

            return $attempt;
        }, attempts: 3);
    }

    public function recordOutcome(
        string $sendRunId,
        string $attemptId,
        string $workerId,
        string $leaseToken,
        string $outcome,
        ?string $transportCode = null,
        ?string $outcomeReference = null,
        ?string $response = null,
        ?string $errorCode = null,
    ): CampaignDeliveryAttempt {
        if (! Str::isUuid($sendRunId) || ! Str::isUuid($attemptId)) {
            throw ValidationException::withMessages(['send_attempt' => 'The send run and attempt identifiers must be UUIDs.']);
        }
        if (trim($workerId) === '' || strlen($workerId) > 128) {
            throw ValidationException::withMessages(['worker_id' => 'A worker identifier from 1 to 128 characters is required.']);
        }
        if (! Str::isUuid($leaseToken)) {
            throw ValidationException::withMessages(['lease_token' => 'A valid batch lease token is required.']);
        }
        if (! in_array($outcome, self::TERMINAL_OUTCOMES, true)) {
            throw ValidationException::withMessages(['outcome' => 'The delivery outcome must be accepted, rejected, or uncertain.']);
        }

        $transportCode = $this->normalizeCode($transportCode, 48, 'transport_code');
        $errorCode = $this->normalizeCode($errorCode, 96, 'error_code');
        $outcomeReferenceHash = $this->hashOptionalValue($outcomeReference, 2048, 'outcome_reference');
        $responseHash = $this->hashOptionalValue($response, 16384, 'response');
        $leaseTokenHash = hash('sha256', $leaseToken);

        return DB::transaction(function () use ($sendRunId, $attemptId, $workerId, $leaseToken, $leaseTokenHash, $outcome, $transportCode, $outcomeReferenceHash, $responseHash, $errorCode): CampaignDeliveryAttempt {
            $run = CampaignSendRun::query()->whereKey($sendRunId)->lockForUpdate()->firstOrFail();
            $attempt = CampaignDeliveryAttempt::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('send_run_id', $run->getKey())
                ->whereKey($attemptId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! is_string($attempt->lease_token_sha256)
                || ! hash_equals($attempt->lease_token_sha256, $leaseTokenHash)) {
                throw ValidationException::withMessages(['lease_token' => 'The lease token does not match this attempt.']);
            }
            if ($attempt->worker_id !== $workerId) {
                throw ValidationException::withMessages(['worker_id' => 'Only the worker that started this attempt can record its outcome.']);
            }

            if (in_array($attempt->state, self::TERMINAL_OUTCOMES, true)) {
                if ($this->matchesOutcome($attempt, $outcome, $transportCode, $outcomeReferenceHash, $responseHash, $errorCode)) {
                    return $attempt;
                }

                throw ValidationException::withMessages(['outcome' => 'A terminal delivery outcome cannot be replaced with a different result.']);
            }
            if ($attempt->state !== 'started' || $run->state !== 'running') {
                throw ValidationException::withMessages(['send_attempt' => 'Only a started attempt in an active send run can receive an outcome.']);
            }

            $batch = CampaignSendBatch::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('send_run_id', $run->getKey())
                ->whereKey($attempt->batch_id)
                ->lockForUpdate()
                ->firstOrFail();
            $claim = CampaignSendRecipientClaim::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('send_run_id', $run->getKey())
                ->where('snapshot_item_id', $attempt->snapshot_item_id)
                ->where('batch_id', $batch->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($batch->state !== 'leased'
                || $batch->lease_owner !== $workerId
                || ! is_string($batch->lease_token)
                || ! hash_equals($batch->lease_token, $leaseToken)
                || $claim->state !== 'sending'
                || ! is_string($claim->lease_token)
                || ! hash_equals($claim->lease_token, $leaseToken)) {
                throw ValidationException::withMessages(['lease' => 'This worker no longer owns the batch and recipient claim.']);
            }

            $now = now();
            $attempt->state = $outcome;
            $attempt->transport_code = $transportCode;
            $attempt->outcome_reference_sha256 = $outcomeReferenceHash;
            $attempt->response_sha256 = $responseHash;
            $attempt->error_code = $errorCode;
            $attempt->finished_at = $now;
            $attempt->save();

            $claim->state = $outcome;
            $claim->accepted_at = $outcome === 'accepted' ? $now : null;
            $claim->lease_token = null;
            $claim->lease_expires_at = null;
            $claim->last_transition_at = $now;
            $claim->save();

            $counter = match ($outcome) {
                'accepted' => 'accepted_count',
                'rejected' => 'rejected_count',
                'uncertain' => 'uncertain_count',
            };
            $batch->{$counter} = (int) $batch->{$counter} + 1;
            $run->{$counter} = (int) $run->{$counter} + 1;

            $hasPendingBatchClaims = DB::table('campaign_send_recipient_claims')
                ->where('batch_id', $batch->getKey())
                ->whereNotIn('state', self::TERMINAL_CLAIM_STATES)
                ->exists();
            if (! $hasPendingBatchClaims) {
                $batch->state = (int) $batch->uncertain_count > 0 ? 'uncertain' : 'completed';
                $batch->completed_at = $now;
                $batch->lease_owner = null;
                $batch->lease_token = null;
                $batch->lease_expires_at = null;
            }

            $batch->save();
            $run->save();

            $snapshot = CampaignRecipientSnapshot::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('campaign_id', $run->campaign_id)
                ->whereKey($run->recipient_snapshot_id)
                ->firstOrFail();
            $this->finalizer->reconcile($run, $snapshot, $now);

            return $attempt;
        }, attempts: 3);
    }

    private function matchesOutcome(
        CampaignDeliveryAttempt $attempt,
        string $outcome,
        ?string $transportCode,
        ?string $outcomeReferenceHash,
        ?string $responseHash,
        ?string $errorCode,
    ): bool {
        return $attempt->state === $outcome
            && $attempt->transport_code === $transportCode
            && $attempt->outcome_reference_sha256 === $outcomeReferenceHash
            && $attempt->response_sha256 === $responseHash
            && $attempt->error_code === $errorCode;
    }

    private function normalizeCode(?string $value, int $maxLength, string $field): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (strlen($value) > $maxLength || preg_match('/^[A-Za-z0-9._:-]+$/', $value) !== 1) {
            throw ValidationException::withMessages([$field => 'Use a short stable code without spaces or message content.']);
        }

        return $value;
    }

    private function hashOptionalValue(?string $value, int $maxLength, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (strlen($value) > $maxLength) {
            throw ValidationException::withMessages([$field => 'The retained diagnostic value exceeds its safe input size.']);
        }

        return hash('sha256', $value);
    }
}
