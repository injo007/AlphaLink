<?php

namespace App\Console\Commands;

use App\Models\WorkspaceSponsor;
use App\Services\AffiliateNetworks\DmsSponsorLeadImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('workspace:sponsor-leads {--sponsor= : Import one AlphaLink sponsor ID only} {--days=1 : Import a rolling number of calendar days}')]
#[Description('Reconcile campaign and Drop lead conversions from active workspace sponsor reports')]
class SyncWorkspaceSponsorLeads extends Command
{
    public function handle(DmsSponsorLeadImporter $importer): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 90]]);
        if ($days === false) {
            $this->error('Choose a day range between 1 and 90.');

            return self::FAILURE;
        }

        $sponsorId = $this->option('sponsor');
        $from = now()->subDays($days)->startOfDay();
        $to = now()->endOfDay();
        $failed = false;
        $processed = 0;

        WorkspaceSponsor::query()
            ->where('is_active', true)
            ->whereIn('type', WorkspaceSponsor::IMPORT_TYPES)
            ->when(is_string($sponsorId) && $sponsorId !== '', fn ($query) => $query->whereKey($sponsorId))
            ->orderBy('id')
            ->chunkById(50, function ($sponsors) use ($importer, $from, $to, &$failed, &$processed): void {
                foreach ($sponsors as $sponsor) {
                    try {
                        $result = $importer->import($sponsor, $from->copy(), $to->copy());
                        $this->line(sprintf(
                            'Sponsor %s: read %d, created %d, updated %d, skipped %d.',
                            $sponsor->getKey(), $result['read'], $result['created'], $result['updated'], $result['skipped'],
                        ));
                    } catch (Throwable) {
                        $failed = true;
                        $this->error(sprintf('Sponsor %s conversion report failed. Check the local provider configuration and application log.', $sponsor->getKey()));
                    }

                    $processed++;
                }
            });

        if ($processed === 0) {
            $this->info('No active sponsor providers were selected.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
