<?php

namespace App\Console\Commands;

use App\Services\Analytics\WorkspaceDailyAnalyticsRebuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

#[Signature('workspace:analytics-rebuild-daily {days=1 : Number of prior days to rebuild in addition to today} {--all : Backfill all stored source events} {--from= : Inclusive start date (Y-m-d)} {--to= : Inclusive end date (Y-m-d)}')]
#[Description('Rebuild persisted daily campaign and Drop engagement analytics')]
class RebuildWorkspaceDailyAnalytics extends Command
{
    public function handle(WorkspaceDailyAnalyticsRebuilder $rebuilder): int
    {
        $from = $this->option('from');
        $to = $this->option('to');
        if ($this->option('all') && ($from !== null || $to !== null)) {
            $this->error('Choose either --all or an explicit --from/--to date range.');

            return self::FAILURE;
        }

        if ($this->option('all')) {
            $rows = $rebuilder->rebuildAll();
            $this->info("Backfilled {$rows} daily campaign and Drop analytics rows from stored source events.");

            return self::SUCCESS;
        }

        if ($from !== null || $to !== null) {
            $validator = Validator::make(
                ['from' => $from, 'to' => $to],
                [
                    'from' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                    'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
                ],
            );
            if ($validator->fails()) {
                $this->error($validator->errors()->first());

                return self::FAILURE;
            }

            $dates = $validator->validated();
            $rows = $rebuilder->rebuildRange(Carbon::parse($dates['from']), Carbon::parse($dates['to']));
            $this->info("Rebuilt {$rows} daily campaign and Drop analytics rows for {$dates['from']} through {$dates['to']}.");

            return self::SUCCESS;
        }

        $days = (string) $this->argument('days');
        if (! ctype_digit($days)) {
            $this->error('The analytics lookback must be a non-negative whole number of days.');

            return self::FAILURE;
        }

        $rows = $rebuilder->rebuild((int) $days);
        $this->info("Rebuilt {$rows} daily campaign and Drop analytics rows.");

        return self::SUCCESS;
    }
}
