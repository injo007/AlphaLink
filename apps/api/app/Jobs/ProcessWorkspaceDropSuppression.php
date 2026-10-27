<?php

namespace App\Jobs;

use App\Services\Campaigns\DmsDropSuppressionProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWorkspaceDropSuppression implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public int $suppressionId)
    {
        $this->onConnection('drop_suppression');
        $this->onQueue('drop-suppression');
    }

    /**
     * Execute the job.
     */
    public function handle(DmsDropSuppressionProcessor $processor): void
    {
        $processor->process($this->suppressionId);
    }
}
