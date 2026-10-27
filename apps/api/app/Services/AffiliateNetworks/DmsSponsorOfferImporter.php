<?php

namespace App\Services\AffiliateNetworks;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DmsSponsorOfferImporter
{
    public function __construct(
        private readonly DmsAffiliateProviderOfferReader $offerReader,
        private readonly DmsEverFlowCreativeBundleImporter $creativeBundleImporter,
    ) {}

    /**
     * Fetch selected offers using the DMS network adapter, then save each new offer and its assets atomically.
     *
     * @param  list<string>  $offerIds
     */
    public function import(Workspace $workspace, WorkspaceSponsor $sponsor, User $user, array $offerIds): int
    {
        abort_unless($sponsor->workspace_id === $workspace->getKey(), 404);

        if (! in_array($sponsor->type, WorkspaceSponsor::IMPORT_TYPES, true)) {
            throw ValidationException::withMessages([
                'sponsor' => 'Automatic offer import is not available for this DMS network yet.',
            ]);
        }

        if (blank($sponsor->api_key)) {
            throw ValidationException::withMessages([
                'sponsor' => 'Save the sponsor API key before importing offers.',
            ]);
        }

        if (blank($sponsor->url)) {
            throw ValidationException::withMessages([
                'sponsor' => 'Save the sponsor URL before importing offers.',
            ]);
        }

        if ($sponsor->type === 'cake' && blank($sponsor->affiliate_id)) {
            throw ValidationException::withMessages([
                'sponsor' => 'Save the Cake affiliate ID before importing offers.',
            ]);
        }

        if ($sponsor->type === 'hasoffers' && blank($sponsor->affiliate_id)) {
            throw ValidationException::withMessages([
                'sponsor' => 'Save the HasOffers affiliate network ID before importing offers.',
            ]);
        }

        $offers = $this->offerReader->read($sponsor, array_values(array_unique($offerIds)));
        foreach ($offers as &$offer) {
            $offer['creatives'] = filled($offer['creative_bundle_url'] ?? null)
                ? $this->creativeBundleImporter->fetch($offer['creative_bundle_url'])
                : ($offer['creatives'] ?? []);
        }
        unset($offer);

        $imported = 0;
        foreach ($offers as $offerData) {
            if (WorkspaceOffer::withTrashed()
                ->where('workspace_id', $workspace->getKey())
                ->where('sponsor_id', $sponsor->getKey())
                ->where('offer_id', $offerData['offer_id'])
                ->exists()) {
                continue;
            }

            DB::transaction(function () use ($workspace, $sponsor, $user, $offerData, &$imported): void {
                $offer = $workspace->offers()->create([
                    'sponsor_id' => $sponsor->getKey(),
                    'offer_id' => $offerData['offer_id'],
                    'name' => $offerData['name'],
                    'status' => str_contains(strtolower($offerData['name']), 'paused') ? 'paused' : 'live',
                    'vertical' => strtolower($offerData['vertical']),
                    'geo_target' => $offerData['geo_target'],
                    'payout' => $offerData['price'],
                    'type' => strtoupper($offerData['type']),
                    'allowed_days' => $offerData['allowed_days'],
                    'redirect' => $offerData['redirect'],
                    'unsubscribe' => $offerData['unsubscribe'] ?: null,
                    'note' => $offerData['note'],
                    'allowed_users' => $sponsor->allowed_users ?? [],
                    'is_active' => false,
                ]);

                $assets = [];
                foreach ($offerData['from'] as $value) {
                    $assets[] = [
                        'workspace_id' => $workspace->getKey(),
                        'type' => 'from',
                        'value' => $value,
                        'created_by' => $user->getKey(),
                    ];
                }
                foreach ($offerData['subjects'] as $value) {
                    $assets[] = [
                        'workspace_id' => $workspace->getKey(),
                        'type' => 'subject',
                        'value' => $value,
                        'created_by' => $user->getKey(),
                    ];
                }

                if ($assets !== []) {
                    $offer->assets()->createMany($assets);
                }

                $this->creativeBundleImporter->persist(
                    $workspace,
                    $offer,
                    $user,
                    $offerData['creatives'] ?? [],
                );

                $imported++;
            });
        }

        return $imported;
    }
}
