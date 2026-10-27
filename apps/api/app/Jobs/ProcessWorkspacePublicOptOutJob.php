<?php

namespace App\Jobs;

use App\Services\Campaigns\ProcessWorkspacePublicOptOut;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessWorkspacePublicOptOutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public string $workspaceId,
        public string $contactId,
        public ?string $ip,
        public string $userAgent,
    ) {}

    public function tags(): array
    {
        return ['public-opt-out'];
    }

    public function handle(ProcessWorkspacePublicOptOut $processor): void
    {
        $processor->process($this->workspaceId, $this->contactId, $this->ip, $this->userAgent);
    }
}
