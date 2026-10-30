<?php

namespace App\Services\Campaigns;

use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignSendBatch;
use App\Models\CampaignSendRun;
use App\Models\WorkspaceOffer;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Reserve a bounded, suppression-checked slice of a planned send run.
 *
 * This creates database claims only. It does not dispatch a job or send email.
 */
class ClaimCampaignSendBatch
{
    public function __construct(
        private readonly DmsOfferSuppressionFile $suppressionFiles,
        private readonly FinalizeCampaignSendRun $finalizer,
    ) {}

    /**
     * @throws JsonException
     */
    public function claim(
        string $sendRunId,
        string $workerId,
        string $claimRequestKey,
        int $batchSize = 500,
        int $leaseSeconds = 300,
    ): ?CampaignSendBatch {
        if (trim($workerId) === '' || strlen($workerId) > 128) {
            throw ValidationException::withMessages(['worker_id' => 'A worker identifier from 1 to 128 characters is required.']);
        }
        if (! Str::isUuid($claimRequestKey)) {
            throw ValidationException::withMessages(['claim_request_key' => 'A UUID claim request key is required for safe retries.']);
        }
        if ($batchSize < 1 || $batchSize > 1000) {
            throw ValidationException::withMessages(['batch_size' => 'A batch must contain between 1 and 1,000 snapshot positions.']);
        }
        if ($leaseSeconds < 30 || $leaseSeconds > 3600) {
            throw ValidationException::withMessages(['lease_seconds' => 'A claim lease must last between 30 and 3,600 seconds.']);
        }

        $requestFingerprint = hash('sha256', json_encode([
            'send_run_id' => $sendRunId,
            'worker_id' => $workerId,
            'batch_size' => $batchSize,
            'lease_seconds' => $leaseSeconds,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($sendRunId, $workerId, $claimRequestKey, $batchSize, $leaseSeconds, $requestFingerprint): ?CampaignSendBatch {
            $run = CampaignSendRun::query()->whereKey($sendRunId)->lockForUpdate()->firstOrFail();

            $previousRequest = CampaignSendBatch::query()
                ->where('send_run_id', $run->getKey())
                ->where('claim_request_key', $claimRequestKey)
                ->with('recipientClaims')
                ->first();
            if ($previousRequest !== null) {
                if (! hash_equals((string) $previousRequest->claim_request_fingerprint, $requestFingerprint)) {
                    throw ValidationException::withMessages(['claim_request_key' => 'This claim request key was already used with different worker or batch settings.']);
                }

                return $previousRequest;
            }

            if (! in_array($run->state, ['planned', 'running'], true)) {
                throw ValidationException::withMessages(['send_run' => 'This send run is not open for recipient claims.']);
            }

            $snapshot = CampaignRecipientSnapshot::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('campaign_id', $run->campaign_id)
                ->whereKey($run->recipient_snapshot_id)
                ->firstOrFail();
            $offer = WorkspaceOffer::query()
                ->where('workspace_id', $run->workspace_id)
                ->whereKey($snapshot->offer_id)
                ->firstOrFail();

            if (! hash_equals((string) $snapshot->suppression_sha256, (string) $offer->suppression_sha256)) {
                throw ValidationException::withMessages(['suppression' => 'The offer suppression asset changed after this recipient snapshot was created. Create a fresh campaign revision before planning another run.']);
            }

            $executionOptions = $run->execution_options ?? [];
            if (! isset($executionOptions['verified_suppression_sha256'])) {
                if (! $this->suppressionFiles->isEligible($offer)) {
                    throw ValidationException::withMessages(['suppression' => 'The saved offer suppression asset is missing, stale, or does not match its reviewed digest.']);
                }

                $executionOptions['verified_suppression_sha256'] = (string) $snapshot->suppression_sha256;
                $executionOptions['verified_suppression_at'] = now()->toIso8601String();
                $run->execution_options = $executionOptions;
            } elseif (! hash_equals((string) $executionOptions['verified_suppression_sha256'], (string) $snapshot->suppression_sha256)) {
                throw ValidationException::withMessages(['suppression' => 'The send run suppression verification does not match its immutable snapshot.']);
            }

            $firstPosition = (int) $run->next_position;
            $lastSnapshotPosition = min(
                (int) $snapshot->recipient_count,
                (int) ($executionOptions['end_position'] ?? $snapshot->recipient_count),
            );
            if ($firstPosition > $lastSnapshotPosition) {
                return null;
            }
            $lastPosition = min($lastSnapshotPosition, $firstPosition + $batchSize - 1);
            $items = DB::table('campaign_recipient_snapshot_items')
                ->where('workspace_id', $run->workspace_id)
                ->where('snapshot_id', $snapshot->getKey())
                ->whereBetween('position', [$firstPosition, $lastPosition])
                ->orderBy('position')
                ->get(['id', 'position']);

            if ($items->count() !== $lastPosition - $firstPosition + 1) {
                throw ValidationException::withMessages(['recipient_snapshot' => 'The immutable recipient snapshot has a position gap; the send run was not advanced.']);
            }

            $now = now();
            $suppressedIds = $this->suppressedItemIds($run, $snapshot, $offer, $firstPosition, $lastPosition, $now)->flip();
            $claimedCount = $items->count() - $suppressedIds->count();
            $leaseToken = $claimedCount > 0 ? (string) Str::uuid() : null;
            $leaseExpiresAt = $claimedCount > 0 ? $now->copy()->addSeconds($leaseSeconds) : null;
            $batch = CampaignSendBatch::query()->create([
                'workspace_id' => $run->workspace_id,
                'send_run_id' => $run->getKey(),
                'batch_number' => (int) ($run->batches()->max('batch_number') ?? 0) + 1,
                'claim_request_key' => $claimRequestKey,
                'claim_request_fingerprint' => $requestFingerprint,
                'first_position' => $firstPosition,
                'last_position' => $lastPosition,
                'state' => $claimedCount > 0 ? 'leased' : 'completed',
                'lease_owner' => $claimedCount > 0 ? $workerId : null,
                'lease_token' => $leaseToken,
                'lease_expires_at' => $leaseExpiresAt,
                'claim_count' => 1,
                'suppressed_count' => $suppressedIds->count(),
                'started_at' => $now,
                'completed_at' => $claimedCount > 0 ? null : $now,
            ]);

            $claimRows = [];
            foreach ($items as $item) {
                $suppressed = $suppressedIds->has((string) $item->id);
                $claimRows[] = [
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $run->workspace_id,
                    'send_run_id' => $run->getKey(),
                    'recipient_snapshot_id' => $snapshot->getKey(),
                    'snapshot_item_id' => $item->id,
                    'batch_id' => $batch->getKey(),
                    'position' => $item->position,
                    'state' => $suppressed ? 'suppressed' : 'claimed',
                    'lease_token' => $suppressed ? null : $leaseToken,
                    'lease_expires_at' => $suppressed ? null : $leaseExpiresAt,
                    'claim_count' => $suppressed ? 0 : 1,
                    'accepted_at' => null,
                    'last_transition_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('campaign_send_recipient_claims')->insert($claimRows);

            $run->state = 'running';
            $run->next_position = $lastPosition + 1;
            $run->suppressed_count = (int) $run->suppressed_count + $suppressedIds->count();
            $run->started_at ??= $now;
            $run->save();
            $this->finalizer->reconcile($run, $snapshot, $now);

            return $batch->load('recipientClaims');
        }, attempts: 3);
    }

    /** @return Collection<int, string> */
    private function suppressedItemIds(
        CampaignSendRun $run,
        CampaignRecipientSnapshot $snapshot,
        WorkspaceOffer $offer,
        int $firstPosition,
        int $lastPosition,
        Carbon $now,
    ): Collection {
        $workspaceId = (string) $run->workspace_id;
        $offerId = $offer->getKey();

        return DB::table('campaign_recipient_snapshot_items as item')
            ->join('contacts as contact', function (JoinClause $join): void {
                $join->on('contact.id', '=', 'item.contact_id')
                    ->on('contact.workspace_id', '=', 'item.workspace_id');
            })
            ->where('item.workspace_id', $workspaceId)
            ->where('item.snapshot_id', $snapshot->getKey())
            ->whereBetween('item.position', [$firstPosition, $lastPosition])
            ->where(function ($blocked) use ($offerId, $now): void {
                $blocked->where('contact.status', '!=', 'active')
                    ->orWhereNotExists(function ($grant) use ($now): void {
                        $grant->selectRaw('1')
                            ->from('consent_records as latest_email_consent')
                            ->whereColumn('latest_email_consent.workspace_id', 'item.workspace_id')
                            ->whereColumn('latest_email_consent.contact_id', 'item.contact_id')
                            ->where('latest_email_consent.channel', 'email')
                            ->where('latest_email_consent.purpose', 'marketing')
                            ->where('latest_email_consent.action', 'granted')
                            ->whereNotNull('latest_email_consent.evidence_reference')
                            ->where('latest_email_consent.evidence_reference', '<>', '')
                            ->where(function ($unexpired) use ($now): void {
                                $unexpired->whereNull('latest_email_consent.expires_at')
                                    ->orWhere('latest_email_consent.expires_at', '>', $now);
                            })
                            ->whereNotExists(function ($newer) use ($now): void {
                                $newer->selectRaw('1')
                                    ->from('consent_records as newer_email_consent')
                                    ->whereColumn('newer_email_consent.workspace_id', 'latest_email_consent.workspace_id')
                                    ->whereColumn('newer_email_consent.contact_id', 'latest_email_consent.contact_id')
                                    ->where('newer_email_consent.channel', 'email')
                                    ->where('newer_email_consent.purpose', 'marketing')
                                    ->where(function ($ordered): void {
                                        $ordered->whereColumn('newer_email_consent.occurred_at', '>', 'latest_email_consent.occurred_at')
                                            ->orWhere(function ($sameTime): void {
                                                $sameTime->whereColumn('newer_email_consent.occurred_at', '=', 'latest_email_consent.occurred_at')
                                                    ->whereColumn('newer_email_consent.id', '>', 'latest_email_consent.id');
                                            });
                                    })
                                    ->where('newer_email_consent.occurred_at', '<=', $now);
                            });
                    })
                    ->orWhereExists(function ($query) use ($now): void {
                        $query->selectRaw('1')
                            ->from('suppression_events as workspace_suppression')
                            ->whereColumn('workspace_suppression.workspace_id', 'item.workspace_id')
                            ->whereColumn('workspace_suppression.contact_id', 'item.contact_id')
                            ->where('workspace_suppression.event_type', 'added')
                            ->where('workspace_suppression.scope_type', 'workspace')
                            ->where('workspace_suppression.effective_at', '<=', $now);
                    })
                    ->orWhereExists(function ($query) use ($offerId, $now): void {
                        $query->selectRaw('1')
                            ->from('suppression_events as offer_suppression')
                            ->whereColumn('offer_suppression.workspace_id', 'item.workspace_id')
                            ->whereColumn('offer_suppression.contact_id', 'item.contact_id')
                            ->where('offer_suppression.event_type', 'added')
                            ->where('offer_suppression.scope_type', 'offer')
                            ->where('offer_suppression.scope_key', (string) $offerId)
                            ->where('offer_suppression.effective_at', '<=', $now);
                    })
                    ->orWhere(function ($listSuppressed) use ($offerId): void {
                        $listSuppressed
                            ->where(function ($sourceSet) use ($offerId): void {
                                $sourceSet->whereExists(function ($source): void {
                                    $source->selectRaw('1')
                                        ->from('campaign_recipient_snapshot_item_sources as item_source')
                                        ->whereColumn('item_source.workspace_id', 'item.workspace_id')
                                        ->whereColumn('item_source.snapshot_item_id', 'item.id');
                                })->whereNotExists(function ($eligibleSource) use ($offerId): void {
                                    $eligibleSource->selectRaw('1')
                                        ->from('campaign_recipient_snapshot_item_sources as item_source')
                                        ->whereColumn('item_source.workspace_id', 'item.workspace_id')
                                        ->whereColumn('item_source.snapshot_item_id', 'item.id')
                                        ->whereNotExists(function ($suppression) use ($offerId): void {
                                            $suppression->selectRaw('1')
                                                ->from('workspace_offer_list_suppressions as list_suppression')
                                                ->whereColumn('list_suppression.workspace_id', 'item_source.workspace_id')
                                                ->whereColumn('list_suppression.audience_list_id', 'item_source.source_list_id')
                                                ->whereColumn('list_suppression.contact_id', 'item.contact_id')
                                                ->where('list_suppression.offer_id', $offerId);
                                        });
                                });
                            })
                            ->orWhere(function ($legacySource) use ($offerId): void {
                                $legacySource->whereNotExists(function ($source): void {
                                    $source->selectRaw('1')
                                        ->from('campaign_recipient_snapshot_item_sources as item_source')
                                        ->whereColumn('item_source.workspace_id', 'item.workspace_id')
                                        ->whereColumn('item_source.snapshot_item_id', 'item.id');
                                })->whereExists(function ($suppression) use ($offerId): void {
                                    $suppression->selectRaw('1')
                                        ->from('workspace_offer_list_suppressions as list_suppression')
                                        ->whereColumn('list_suppression.workspace_id', 'item.workspace_id')
                                        ->whereColumn('list_suppression.audience_list_id', 'item.source_list_id')
                                        ->whereColumn('list_suppression.contact_id', 'item.contact_id')
                                        ->where('list_suppression.offer_id', $offerId);
                                });
                            });
                    });
            })
            ->pluck('item.id')
            ->map(fn ($id): string => (string) $id);
    }
}
