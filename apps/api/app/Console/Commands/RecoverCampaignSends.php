<?php

namespace App\Console\Commands;

use App\Jobs\ProcessCampaignSendRun;
use App\Models\CampaignSendRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Signature('campaigns:recover-sends')]
#[Description('Requeue overdue campaign sends without bypassing their pacing delay.')]
class RecoverCampaignSends extends Command
{
    public function handle(): int
    {
        $requeued = 0;
        CampaignSendRun::query()
            ->whereIn('state', ['planned', 'running'])
            ->select(['id'])
            ->chunkById(100, function ($runs) use (&$requeued): void {
                foreach ($runs as $candidate) {
                    $queued = DB::transaction(function () use ($candidate): bool {
                        $run = CampaignSendRun::query()->whereKey($candidate->getKey())->lockForUpdate()->first();
                        if (! $run || ! in_array($run->state, ['planned', 'running'], true)) {
                            return false;
                        }

                        $options = $run->execution_options ?? [];
                        $lastRecovery = $options['last_recovery_queued_at'] ?? null;
                        if (is_string($lastRecovery) && Carbon::parse($lastRecovery)->greaterThan(now()->subMinutes(5))) {
                            return false;
                        }
                        if (! $this->isOverdue($run, $options)) {
                            return false;
                        }

                        $options['last_recovery_queued_at'] = now()->toIso8601String();
                        $run->execution_options = $options;
                        $run->save();
                        ProcessCampaignSendRun::dispatch((string) $run->getKey())
                            ->onQueue('campaign-sends')
                            ->afterCommit();

                        return true;
                    }, attempts: 3);
                    if ($queued) {
                        $requeued++;
                    }
                }
            });

        $this->info("Requeued {$requeued} overdue campaign send(s).");

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $options */
    private function isOverdue(CampaignSendRun $run, array $options): bool
    {
        $grace = now()->subMinutes(2);
        if ($run->state === 'planned') {
            return $run->created_at->lessThanOrEqualTo($grace);
        }

        $latestBatch = $run->batches()->orderByDesc('batch_number')->first();
        if ($latestBatch?->state === 'leased') {
            return $latestBatch->lease_expires_at?->lessThanOrEqualTo($grace) ?? false;
        }

        $nextBatchAt = $options['next_batch_at'] ?? null;
        if (is_string($nextBatchAt) && $nextBatchAt !== '') {
            return Carbon::parse($nextBatchAt)->lessThanOrEqualTo($grace);
        }

        if ($latestBatch?->completed_at) {
            $setup = data_get($run->revision?->payload, 'send_setup', []);
            $parameters = [...$setup, ...(array) ($setup['parameters'] ?? [])];
            $delay = max(0, (int) ($parameters['sleep_email'] ?? 0));
            $fraction = max(1, (int) ($parameters['fraction_ip'] ?? 1));
            if ((int) ($parameters['sleep_ip'] ?? 0) > 0
                && ((int) $latestBatch->last_position - (int) $run->start_position + 1) % $fraction === 0) {
                $delay += (int) $parameters['sleep_ip'];
            }

            return $latestBatch->completed_at->addSeconds($delay)->lessThanOrEqualTo($grace);
        }

        return $run->updated_at->lessThanOrEqualTo($grace);
    }
}
