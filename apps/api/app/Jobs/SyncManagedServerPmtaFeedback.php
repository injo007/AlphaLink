<?php

namespace App\Jobs;

use App\Models\ManagedServer;
use App\Services\Analytics\PmtaFeedbackRemoteProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncManagedServerPmtaFeedback implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 50;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [60, 180, 300];

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $serverId, public readonly string $accountingDate)
    {
        $this->onQueue('default');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('pmta-feedback-server:'.$this->serverId))
                ->expireAfter(600)
                ->dontRelease(),
            new LimitPmtaFeedbackConcurrency,
        ];
    }

    public function handle(PmtaFeedbackRemoteProcessor $processor): void
    {
        $server = ManagedServer::query()
            ->whereKey($this->serverId)
            ->where('is_active', true)
            ->where('is_installed', true)
            ->where('state', 'verified')
            ->whereHas('workspace', fn ($query) => $query->where('status', 'active'))
            ->first();

        if ($server === null) {
            return;
        }

        $counts = $processor->process($server, $this->accountingDate);
        Log::info('PMTA feedback synchronization completed.', [
            'managed_server_id' => $this->serverId,
            'accounting_date' => $this->accountingDate,
            ...$counts,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('PMTA feedback synchronization failed.', [
            'managed_server_id' => $this->serverId,
            'accounting_date' => $this->accountingDate,
            'exception_class' => $exception === null ? null : $exception::class,
        ]);
    }
}
