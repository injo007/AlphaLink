<?php

namespace App\Jobs;

use App\Services\Audiences\ImportAudienceListContacts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessAudienceListImportBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 300;

    public function __construct(public string $batchId) {}

    public function tags(): array
    {
        return ['audience-import', 'audience-import:'.$this->batchId];
    }

    public function backoff(): array
    {
        return [30, 120, 300, 600];
    }

    public function handle(ImportAudienceListContacts $importer): void
    {
        $importer->processNextBatchChunk($this->batchId);
    }

    public function failed(?Throwable $exception): void
    {
        app(ImportAudienceListContacts::class)->failBatch(
            $this->batchId,
            'The import could not be completed. Upload the file again; if it continues, contact support with the import ID.',
        );
    }
}
