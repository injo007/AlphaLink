<?php

namespace App\Jobs;

use App\Models\Workspace;
use App\Models\WorkspaceDrop;
use App\Services\Campaigns\ProcessDropTrackingEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDropTrackingEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @param array<string, mixed> $data */
    public function __construct(
        public WorkspaceDrop $drop,
        public Workspace $workspace,
        public array $data,
    ) {}

    public function tags(): array
    {
        return ['drop-tracking-event'];
    }

    public function handle(ProcessDropTrackingEvent $eventProcessor): void
    {
        $eventProcessor->ingest($this->drop, $this->workspace, $this->data);
    }
}
