<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAudienceListImportBatch;
use App\Models\AudienceListImportBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchPendingAudienceListImportBatches extends Command
{
    protected $signature = 'audience-lists:dispatch-pending-imports';

    protected $description = 'Resume queued audience-list import batches whose queue dispatch was interrupted.';

    public function handle(): int
    {
        $dispatched = 0;
        AudienceListImportBatch::query()
            ->whereIn('status', ['queued', 'validating', 'importing'])
            ->whereNotNull('next_dispatch_at')
            ->where('next_dispatch_at', '<=', now())
            ->chunkById(500, function ($batches) use (&$dispatched): void {
                foreach ($batches as $candidate) {
                    $id = (string) $candidate->getKey();
                    $eligible = DB::transaction(function () use ($id): bool {
                        $batch = AudienceListImportBatch::query()->whereKey($id)->lockForUpdate()->first();
                        if (! $batch || ! in_array($batch->status, ['queued', 'validating', 'importing'], true)
                            || ! $batch->next_dispatch_at || $batch->next_dispatch_at->isFuture()) {
                            return false;
                        }

                        $batch->next_dispatch_at = now()->addMinutes(10);
                        $batch->save();

                        return true;
                    });

                    if ($eligible) {
                        ProcessAudienceListImportBatch::dispatch($id)->onQueue('audience-imports');
                        $dispatched++;
                    }
                }
            });

        $this->info("Dispatched {$dispatched} pending audience import batch(es).");

        return self::SUCCESS;
    }
}
