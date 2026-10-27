<?php

namespace App\Console\Commands;

use App\Models\AudienceList;
use App\Services\Audiences\AudienceListHistoryCalculator;
use Illuminate\Console\Command;

class CalculateAudienceListHistory extends Command
{
    protected $signature = 'audience-lists:calculate-history {listId? : Audience List ID}';

    protected $description = 'Update the daily DMS-shaped Audience List segment count snapshot';

    public function handle(AudienceListHistoryCalculator $calculator): int
    {
        $listId = $this->argument('listId');

        if ($listId !== null) {
            $list = AudienceList::query()->whereKey($listId)->first();
            if (! $list) {
                $this->error('Audience List not found.');

                return self::FAILURE;
            }

            $calculator->calculate($list);
            $this->info('Updated Audience List history.');

            return self::SUCCESS;
        }

        $count = $calculator->calculateAll();
        $this->info("Updated history for {$count} Audience Lists.");

        return self::SUCCESS;
    }
}
