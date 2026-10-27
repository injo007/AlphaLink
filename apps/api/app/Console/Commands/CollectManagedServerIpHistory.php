<?php

namespace App\Console\Commands;

use App\Services\Infrastructure\DmsServerIpHistoryCollector;
use Illuminate\Console\Command;

class CollectManagedServerIpHistory extends Command
{
    protected $signature = 'servers:collect-ip-history';

    protected $description = 'Collect DMS-compatible DNS and IP reputation history';

    public function handle(DmsServerIpHistoryCollector $collector): int
    {
        if (! config('infrastructure.ip_history_enabled')) {
            $this->error('IP history checks are disabled. Enable INFRASTRUCTURE_IP_HISTORY_ENABLED to send DNS lookups.');

            return self::FAILURE;
        }

        $recorded = $collector->collect();
        $this->info(sprintf('Recorded IP history for %d eligible IPv4 address(es).', $recorded));

        return self::SUCCESS;
    }
}
