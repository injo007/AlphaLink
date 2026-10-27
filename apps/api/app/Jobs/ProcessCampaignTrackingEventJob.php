<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Workspace;
use App\Services\Campaigns\ProcessCampaignTrackingEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessCampaignTrackingEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @param array<string, mixed> $data */
    public function __construct(
        public Campaign $campaign,
        public Workspace $workspace,
        public array $data,
    ) {}

    public function tags(): array
    {
        return ['campaign-tracking-event'];
    }

    public function handle(ProcessCampaignTrackingEvent $eventProcessor): void
    {
        $eventProcessor->ingest($this->campaign, $this->workspace, $this->data);
    }
}
