<?php

namespace App\Services\AffiliateNetworks;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSponsor;
use Illuminate\Validation\ValidationException;

/** @deprecated Use AlphaLinkSponsorOfferImporter for all supported networks. */
class AlphaLinkEverFlowOfferImporter
{
    public function __construct(private readonly AlphaLinkSponsorOfferImporter $importer) {}

    /** @param list<string> $offerIds */
    public function import(Workspace $workspace, WorkspaceSponsor $sponsor, User $user, array $offerIds): int
    {
        if ($sponsor->type !== 'everflow') {
            throw ValidationException::withMessages([
                'sponsor' => 'This importer accepts EverFlow sponsors only.',
            ]);
        }

        return $this->importer->import($workspace, $sponsor, $user, $offerIds);
    }
}
