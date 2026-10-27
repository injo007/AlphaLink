<?php

namespace App\Services\AffiliateNetworks;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DmsSponsorOfferRefresher
{
    public function __construct(
        private readonly DmsAffiliateProviderOfferReader $offerReader,
        private readonly DmsEverFlowCreativeBundleImporter $creativeImporter,
    ) {}

    /**
     * Apply DMS OffersUpdateInfo rules to one workspace offer.
     *
     * @return array{outcome: 'updated'|'unchanged'|'paused'|'skipped'|'failed', html_creatives_added: int}
     */
    public function refresh(Workspace $workspace, WorkspaceOffer $offer, ?User $actor): array
    {
        $sponsor = $workspace->sponsors()->whereKey($offer->sponsor_id)->first();
        if (! $sponsor instanceof WorkspaceSponsor || ! $sponsor->is_active) {
            return ['outcome' => 'skipped', 'html_creatives_added' => 0];
        }

        try {
            $this->assertProviderReady($sponsor, $offer);
            $records = $this->offerReader->read($sponsor, [(string) $offer->offer_id], throwOnProviderFailure: true);
        } catch (Throwable $exception) {
            $this->logFailure($workspace, $offer, $sponsor, $exception);

            return ['outcome' => 'failed', 'html_creatives_added' => 0];
        }

        $record = collect($records)->first(
            fn (array $candidate): bool => (string) ($candidate['offer_id'] ?? '') === (string) $offer->offer_id,
        );

        if (! is_array($record)) {
            WorkspaceOffer::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereKey($offer->getKey())
                ->update(['status' => 'paused', 'updated_at' => now()]);

            return ['outcome' => 'paused', 'html_creatives_added' => 0];
        }

        if (! is_string($record['name'] ?? null) || ! is_numeric($record['price'] ?? null) || ! is_string($record['redirect'] ?? null)) {
            $this->logFailure($workspace, $offer, $sponsor, new DmsAffiliateProviderException('The provider returned incomplete offer fields.'));

            return ['outcome' => 'failed', 'html_creatives_added' => 0];
        }

        $creatives = is_array($record['creatives'] ?? null) ? $record['creatives'] : [];
        if (filled($record['creative_bundle_url'] ?? null)) {
            $creatives = $this->creativeImporter->fetch((string) $record['creative_bundle_url']);
        }

        $metadataChanged = DB::transaction(function () use ($workspace, $offer, $record): bool {
            $lockedOffer = WorkspaceOffer::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereKey($offer->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $lockedOffer->forceFill([
                'name' => $record['name'],
                'payout' => sprintf('%0.2f', (float) $record['price']),
                'redirect' => $record['redirect'],
            ]);

            if (str_contains(mb_strtolower($record['name']), 'pause')) {
                $lockedOffer->status = 'paused';
            }
            if (! empty($record['unsubscribe'])) {
                $lockedOffer->unsubscribe = (string) $record['unsubscribe'];
            }

            if (! $lockedOffer->isDirty()) {
                return false;
            }

            $lockedOffer->save();

            return true;
        });

        $htmlCreativesAdded = 0;
        if ($creatives !== []) {
            try {
                $htmlBefore = $offer->assets()->where('type', 'html')->count();
                DB::transaction(function () use ($workspace, $offer, $actor, $creatives): void {
                    $lockedOffer = WorkspaceOffer::query()
                        ->where('workspace_id', $workspace->getKey())
                        ->whereKey($offer->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();
                    $this->creativeImporter->persist($workspace, $lockedOffer, $actor, $creatives);
                });
                $htmlCreativesAdded = max(0, $offer->assets()->where('type', 'html')->count() - $htmlBefore);
            } catch (Throwable $exception) {
                $this->logFailure($workspace, $offer, $sponsor, $exception);
            }
        }

        return [
            'outcome' => $metadataChanged || $htmlCreativesAdded > 0 ? 'updated' : 'unchanged',
            'html_creatives_added' => $htmlCreativesAdded,
        ];
    }

    private function assertProviderReady(WorkspaceSponsor $sponsor, WorkspaceOffer $offer): void
    {
        if (! in_array($sponsor->type, WorkspaceSponsor::IMPORT_TYPES, true)
            || blank($sponsor->api_key)
            || blank($sponsor->url)
            || (in_array($sponsor->type, ['cake', 'hasoffers'], true) && blank($sponsor->affiliate_id))
            || ! is_string($offer->offer_id)
            || ! is_numeric($offer->offer_id)) {
            throw new DmsAffiliateProviderException('Sponsor settings or external offer ID are incomplete.');
        }
    }

    private function logFailure(Workspace $workspace, WorkspaceOffer $offer, WorkspaceSponsor $sponsor, Throwable $exception): void
    {
        Log::warning('Affiliate offer refresh failed.', [
            'workspace_id' => (string) $workspace->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'exception_type' => class_basename($exception),
        ]);
    }
}
