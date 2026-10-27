<?php

namespace App\Console\Commands;

use App\Models\WorkspaceOffer;
use App\Services\AffiliateNetworks\DmsSponsorOfferRefresher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('workspace:offers-refresh {--sleep=1 : Wait between provider requests} {--offer= : Refresh one AlphaLink offer ID only}')]
#[Description('Refresh active live workspace offers from their active DMS-compatible sponsor providers')]
class RefreshWorkspaceOffers extends Command
{
    public function handle(DmsSponsorOfferRefresher $refresher): int
    {
        $sleepBetween = (string) $this->option('sleep') === '1';
        $offerId = $this->option('offer');

        $offers = WorkspaceOffer::query()
            ->when($offerId === null, fn ($query) => $query->where('is_active', true)->where('status', 'live'))
            ->whereHas('sponsor', fn ($query) => $query->where('is_active', true))
            ->when($offerId !== null, fn ($query) => $query->whereKey((int) $offerId))
            ->with(['workspace', 'sponsor'])
            ->orderBy('id');

        $processed = 0;
        $offers->chunkById(100, function ($batch) use ($refresher, $sleepBetween, &$processed): void {
            foreach ($batch as $offer) {
                $result = $refresher->refresh($offer->workspace, $offer, null);
                $this->line(sprintf('Offer %s refresh: %s', $offer->getKey(), $result['outcome']));
                $processed++;

                if ($sleepBetween) {
                    sleep(random_int(5, 10));
                }
            }
        });

        $this->info(sprintf('Offers refresh finished: %d processed.', $processed));

        return self::SUCCESS;
    }
}
