<?php

namespace App\Console\Commands;

use App\Services\AppHealthReport;
use Illuminate\Console\Command;

class RunAppHealthChecks extends Command
{
    protected $signature = 'alphalink:health-check';

    protected $description = 'Run and cache AlphaLink application health checks';

    public function handle(AppHealthReport $report): int
    {
        $result = $report->run();
        foreach ($result['stored_check_results'] as $check) {
            $this->line(sprintf('%s: %s — %s', $check['label'], strtoupper($check['status']), $check['shortSummary']));
        }

        return self::SUCCESS;
    }
}
