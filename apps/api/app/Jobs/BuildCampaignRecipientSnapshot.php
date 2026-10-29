<?php

namespace App\Jobs;

use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRevision;
use App\Models\Workspace;
use App\Services\Campaigns\DmsCampaignRecipientSnapshotBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class BuildCampaignRecipientSnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    private int $lastReportedPercent = -1;

    private float $lastReportedAt = 0;

    private ?string $lastReportedStage = null;

    public function __construct(
        public string $workspaceId,
        public string $campaignId,
        public string $revisionId,
        public ?int $actorUserId,
    ) {
        $this->onConnection('drop_suppression');
        $this->onQueue('drop-suppression');
    }

    public function handle(DmsCampaignRecipientSnapshotBuilder $builder): void
    {
        $campaign = Campaign::query()
            ->where('workspace_id', $this->workspaceId)
            ->whereKey($this->campaignId)
            ->first();
        $revision = CampaignRevision::query()
            ->where('campaign_id', $this->campaignId)
            ->whereKey($this->revisionId)
            ->first();

        if (! $campaign || ! $revision || $campaign->status !== 'preparing_snapshot') {
            return;
        }

        if ($snapshot = CampaignRecipientSnapshot::query()->where('revision_id', $this->revisionId)->first()) {
            $this->complete($campaign, $snapshot);

            return;
        }

        try {
            $workspace = Workspace::query()->whereKey($this->workspaceId)->firstOrFail();
            $sendSetup = $revision->payload['send_setup'] ?? null;
            if (! is_array($sendSetup)) {
                throw new \RuntimeException('Campaign revision is missing its send setup.');
            }

            $snapshot = $builder->build(
                $workspace,
                $campaign,
                $this->revisionId,
                $sendSetup,
                fn (array $progress) => $this->reportProgress($progress),
            );

            $this->complete($campaign->fresh(), $snapshot);
        } catch (Throwable $exception) {
            report($exception);
            $message = $exception instanceof ValidationException
                ? collect($exception->errors())->flatten()->first()
                : null;
            $safeMessage = is_string($message) && $message !== ''
                ? mb_substr($message, 0, 500)
                : 'The suppression file or selected audience could not be processed. Review the source data and retry.';

            DB::transaction(function () use ($safeMessage): void {
                $campaign = Campaign::query()
                    ->where('workspace_id', $this->workspaceId)
                    ->whereKey($this->campaignId)
                    ->lockForUpdate()
                    ->first();
                if (! $campaign || $campaign->status !== 'preparing_snapshot') {
                    return;
                }

                $campaign->forceFill([
                    'status' => 'snapshot_failed',
                    'snapshot_build_stage' => 'failed',
                    'snapshot_build_error' => $safeMessage,
                    'snapshot_build_updated_at' => now(),
                ])->save();
            });
        }
    }

    /** @param array{stage: string, processed: int, total: int, percent: int} $progress */
    private function reportProgress(array $progress): void
    {
        $stage = (string) ($progress['stage'] ?? 'audience');
        $percent = max(0, min(100, (int) ($progress['percent'] ?? 0)));
        $now = microtime(true);
        $stageChanged = $stage !== $this->lastReportedStage;
        if (! $stageChanged && $percent === $this->lastReportedPercent && $now - $this->lastReportedAt < 2.0) {
            return;
        }
        if (! $stageChanged && $percent < $this->lastReportedPercent) {
            return;
        }

        Campaign::query()
            ->where('workspace_id', $this->workspaceId)
            ->whereKey($this->campaignId)
            ->where('status', 'preparing_snapshot')
            ->update([
                'snapshot_build_stage' => $stage,
                'snapshot_build_percent' => $percent,
                'snapshot_build_processed' => max(0, (int) ($progress['processed'] ?? 0)),
                'snapshot_build_total' => max(0, (int) ($progress['total'] ?? 0)),
                'snapshot_build_updated_at' => now(),
                'updated_at' => now(),
            ]);

        $this->lastReportedPercent = $percent;
        $this->lastReportedAt = $now;
        $this->lastReportedStage = $stage;
    }

    private function complete(Campaign $campaign, CampaignRecipientSnapshot $snapshot): void
    {
        DB::transaction(function () use ($snapshot): void {
            $campaign = Campaign::query()
                ->where('workspace_id', $this->workspaceId)
                ->whereKey($this->campaignId)
                ->lockForUpdate()
                ->first();
            if (! $campaign || $campaign->status !== 'preparing_snapshot') {
                return;
            }

            $campaign->forceFill([
                'status' => 'draft',
                'snapshot_build_stage' => 'complete',
                'snapshot_build_percent' => 100,
                'snapshot_build_processed' => $campaign->snapshot_build_total ?? $snapshot->recipient_count,
                'snapshot_build_total' => $campaign->snapshot_build_total ?? $snapshot->recipient_count,
                'snapshot_build_error' => null,
                'snapshot_build_updated_at' => now(),
            ])->save();

            AuditEvent::query()->create([
                'workspace_id' => $this->workspaceId,
                'actor_user_id' => $this->actorUserId,
                'action' => 'campaign.recipient_snapshot_created',
                'subject_type' => Campaign::class,
                'subject_id' => $campaign->getKey(),
                'properties' => [
                    'revision_id' => $this->revisionId,
                    'recipient_snapshot_id' => $snapshot->getKey(),
                    'recipient_count' => $snapshot->recipient_count,
                    'recipient_manifest_sha256' => $snapshot->recipients_sha256,
                ],
                'occurred_at' => now(),
            ]);
        });
    }
}
