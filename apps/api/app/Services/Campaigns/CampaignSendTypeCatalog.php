<?php

namespace App\Services\Campaigns;

/** Source-traced Send Type values exposed by each DMS authoring flow. */
final class CampaignSendTypeCatalog
{
    public const CAMPAIGN_AND_WARMUP = ['delay', 'delayp', 'delayps', 'hs', 'hsp', 'hsps', 'pickup', 'pickups'];

    public const DROP = ['delay', 'delayp', 'hs', 'hsp', 'pickup'];

    /** @return list<string> */
    public static function forCampaignKind(string $kind): array
    {
        return $kind === 'drop' ? self::DROP : self::CAMPAIGN_AND_WARMUP;
    }
}
