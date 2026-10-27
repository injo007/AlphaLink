<?php

namespace App\Services\Campaigns;

class CampaignRecipientSourceIdentity
{
    public static function key(?string $membershipId, string $listId, string $emailType): string
    {
        $identity = $membershipId === null
            ? 'list:'.$listId.'|type:'.$emailType
            : 'membership:'.$membershipId;

        return hash('sha256', $identity);
    }
}
