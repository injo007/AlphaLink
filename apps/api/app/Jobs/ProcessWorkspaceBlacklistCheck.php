<?php

namespace App\Jobs;

use App\Services\Audiences\ProcessWorkspaceBlacklistFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessWorkspaceBlacklistCheck implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 300;

    public function __construct(public string $processId) {}

    public function tags(): array
    {
        return ['blacklist-check', 'blacklist-check:'.$this->processId];
    }

    public function backoff(): array
    {
        return [15, 60, 180, 300];
    }

    public function handle(ProcessWorkspaceBlacklistFile $processor): void
    {
        $processor->processNextChunk($this->processId);
    }

    public function failed(?Throwable $exception): void
    {
        app(ProcessWorkspaceBlacklistFile::class)->fail($this->processId);
    }
}
