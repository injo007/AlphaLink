<?php

namespace App\Console\Commands;

use App\Services\Infrastructure\DmsDomainHistoryCollector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('domain:check_history')]
#[Description('Collect DMS-compatible DNS and reputation history for active domains')]
class CollectSendingDomainHistory extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DmsDomainHistoryCollector $collector): int
    {
        if (! config('infrastructure.domain_history_enabled')) {
            $this->error('Domain history checks are disabled. Enable INFRASTRUCTURE_DOMAIN_HISTORY_ENABLED to send domain names to DNS and reputation resolvers.');

            return self::FAILURE;
        }

        $this->info('Checking domains');
        $result = $collector->collect();

        foreach ($result['listed_domains'] as $domain) {
            $this->error("\nDOMAIN LISTED : {$domain}");
        }

        return self::SUCCESS;
    }
}
