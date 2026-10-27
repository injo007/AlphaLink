<?php

namespace App\Services\AffiliateNetworks;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSponsor;
use Illuminate\Validation\ValidationException;

/** @deprecated Use DmsSponsorOfferImporter for all supported networks. */
class DmsEverFlowOfferImporter
{
    public function __construct(private readonly DmsSponsorOfferImporter $importer) {}

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
