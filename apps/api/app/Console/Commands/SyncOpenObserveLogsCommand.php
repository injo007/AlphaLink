<?php

namespace App\Console\Commands;

use App\Services\OpenObserve\ErrorIntelligenceService;
use Illuminate\Console\Command;

class SyncOpenObserveLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openobserve:sync {--limit=200 : Maximum number of local log entries and failed jobs to ingest}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ingest recent local Laravel error logs and failed queue jobs into OpenObserve';

    /**
     * Execute the console command.
     */
    public function handle(ErrorIntelligenceService $service): int
    {
        $limit = max(10, (int) $this->option('limit'));

        $this->info("Checking OpenObserve status...");
        $status = $service->getStatus();

        if (! $status['configured']) {
            $this->warn("OpenObserve is not configured (OPENOBSERVE_ENABLED or OPENOBSERVE_URL not set).");
            return self::FAILURE;
        }

        if (! $status['healthy']) {
            $this->error("OpenObserve endpoint is unreachable or returning errors: " . ($status['last_error'] ?? 'Unknown error'));
            return self::FAILURE;
        }

        $this->info("OpenObserve is connected at {$status['url']} (Stream: {$status['stream']}).");
        $this->line("Starting ingestion of up to {$limit} recent errors and failed jobs...");

        $result = $service->syncToOpenObserve($limit);

        if ($result['success']) {
            $this->info("Successfully synced {$result['synced']} record(s) to OpenObserve.");
            return self::SUCCESS;
        }

        $this->error("Failed to sync records: " . ($result['error'] ?? 'Unknown error'));
        return self::FAILURE;
    }
}
