<?php

namespace App\Services\Infrastructure;

/** Selects the PMTA monitor topology supported by each PMTA version. */
final class DmsPmtaMonitorMode
{
    public static function usesDirectListener(int $version, bool $providerDomainAvailable, bool $pmtaSelected = true): bool
    {
        return $pmtaSelected && ! $providerDomainAvailable && $version === 40;
    }
}
