<?php

namespace App\Jobs;

use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRevision;
use App\Models\CampaignSendRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Campaigns\AlphaLinkCampaignRecipientSnapshotBuilder;
use App\Services\Campaigns\CampaignSendMessageBuilder;
use App\Services\Campaigns\PrepareCampaignSendRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PrepareAndStartDropSend implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(
        public string $workspaceId,
        public string $campaignId,
        public string $revisionId,
        public int $actorUserId,
        public string $idempotencyKey,
    ) {
        $this->onConnection('drop_suppression');
        $this->onQueue('drop-suppression');
    }

    public function handle(
        AlphaLinkCampaignRecipientSnapshotBuilder $snapshotBuilder,
        PrepareCampaignSendRun $planner,
        CampaignSendMessageBuilder $messageBuilder,
    ): void {
        $campaign = Campaign::query()->where('workspace_id', $this->workspaceId)->whereKey($this->campaignId)->first();
        $revision = CampaignRevision::query()->where('campaign_id', $this->campaignId)->whereKey($this->revisionId)->first();
        if (! $campaign || ! $revision || $campaign->kind !== 'drop' || ! in_array($campaign->status, ['preparing_snapshot', 'snapshot_failed'], true)) {
            return;
        }

        try {
            $workspace = Workspace::query()->whereKey($this->workspaceId)->where('status', 'active')->firstOrFail();
            $actor = User::query()->findOrFail($this->actorUserId);
            $setup = (array) ($revision->payload['send_setup'] ?? []);
            $snapshot = CampaignRecipientSnapshot::query()->where('revision_id', $revision->getKey())->first();
            if (! $snapshot) {
                $snapshot = $snapshotBuilder->build(
                    $workspace,
                    $campaign,
                    (string) $revision->getKey(),
                    $setup,
                    fn (array $progress) => $this->reportProgress($progress),
                );
            }

            $activeRun = CampaignSendRun::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('campaign_id', $campaign->getKey())
                ->whereIn('state', ['planned', 'running'])
                ->latest('created_at')
                ->first();
            if ($activeRun) {
                $this->markCampaignDraft($snapshot->recipient_count);

                return;
            }

            $run = $planner->create($campaign, $revision, $snapshot, $actor, $this->idempotencyKey);
            if (in_array($run->state, ['completed', 'needs_review', 'stopped'], true)
                || ($run->state === 'failed' && ((int) $run->accepted_count > 0 || (int) $run->rejected_count > 0 || (int) $run->uncertain_count > 0 || (int) $run->suppressed_count > 0))) {
                throw ValidationException::withMessages(['campaign' => 'This Drop revision already has a send history. Save a new revision before sending again.']);
            }
            $messageBuilder->assertReady($run);
            $this->markCampaignDraft($snapshot->recipient_count);
            AuditEvent::query()->create([
                'workspace_id' => $workspace->getKey(),
                'actor_user_id' => $actor->getKey(),
                'action' => 'campaign.send_started',
                'subject_type' => CampaignSendRun::class,
                'subject_id' => $run->getKey(),
                'properties' => [
                    'campaign_id' => (string) $campaign->getKey(),
                    'revision_id' => (string) $revision->getKey(),
                    'recipient_snapshot_id' => (string) $snapshot->getKey(),
                    'recipient_count' => (int) $run->recipient_count,
                    'send_type' => (string) $run->send_type,
                    'campaign_kind' => 'drop',
                ],
                'occurred_at' => now(),
            ]);
            ProcessCampaignSendRun::dispatch((string) $run->getKey())->onQueue('campaign-sends')->afterCommit();
        } catch (Throwable $exception) {
            report($exception);
            $safeMessage = $exception instanceof ValidationException
                ? collect($exception->errors())->flatten()->first()
                : null;
            $safeMessage = is_string($safeMessage) && $safeMessage !== ''
                ? mb_substr($safeMessage, 0, 500)
                : 'Drop audience preparation or sender validation failed. Review the saved setup and retry.';
            $run = CampaignSendRun::query()->where('workspace_id', $this->workspaceId)->where('idempotency_key', $this->idempotencyKey)->first();
            if ($run && in_array($run->state, ['planned', 'running'], true)) {
                $run->state = 'failed';
                $run->completed_at = now();
                $run->execution_options = [...(array) ($run->execution_options ?? []), 'failure_code' => 'DROP_SEND_NOT_READY'];
                $run->save();
            }
            Campaign::query()
                ->where('workspace_id', $this->workspaceId)
                ->whereKey($this->campaignId)
                ->where('status', 'preparing_snapshot')
                ->update([
                    'status' => 'snapshot_failed',
                    'snapshot_build_stage' => 'failed',
                    'snapshot_build_error' => $safeMessage,
                    'snapshot_build_updated_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    /** @param array{stage: string, processed: int, total: int, percent: int} $progress */
    private function reportProgress(array $progress): void
    {
        Campaign::query()
            ->where('workspace_id', $this->workspaceId)
            ->whereKey($this->campaignId)
            ->where('status', 'preparing_snapshot')
            ->update([
                'snapshot_build_stage' => (string) ($progress['stage'] ?? 'audience'),
                'snapshot_build_percent' => max(0, min(100, (int) ($progress['percent'] ?? 0))),
                'snapshot_build_processed' => max(0, (int) ($progress['processed'] ?? 0)),
                'snapshot_build_total' => max(0, (int) ($progress['total'] ?? 0)),
                'snapshot_build_updated_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function markCampaignDraft(int $recipientCount): void
    {
        DB::transaction(function () use ($recipientCount): void {
            Campaign::query()
                ->where('workspace_id', $this->workspaceId)
                ->whereKey($this->campaignId)
                ->where('status', 'preparing_snapshot')
                ->update([
                    'status' => 'draft',
                    'snapshot_build_stage' => 'complete',
                    'snapshot_build_percent' => 100,
                    'snapshot_build_processed' => $recipientCount,
                    'snapshot_build_total' => $recipientCount,
                    'snapshot_build_error' => null,
                    'snapshot_build_updated_at' => now(),
                    'updated_at' => now(),
                ]);
        }, attempts: 3);
    }
}
