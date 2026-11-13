<?php

namespace App\Console\Commands;

use App\Models\WorkspaceSpfSearchRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spf:prune-search-runs')]
#[Description('Remove expired SPF IP comparison runs and their saved results.')]
class PruneWorkspaceSpfSearchRuns extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $retentionDays = max(1, (int) config('spf.search_retention_days', 7));
        $deleted = WorkspaceSpfSearchRun::query()
            ->where('created_at', '<', now()->subDays($retentionDays))
            ->delete();

        $this->info('Removed '.$deleted.' expired SPF comparison run(s).');

        return self::SUCCESS;
    }
}
