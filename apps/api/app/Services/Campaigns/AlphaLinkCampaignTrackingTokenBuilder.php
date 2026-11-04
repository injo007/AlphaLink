<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Builds campaign tokens consumed by the installed AlphaLink tracking app. */
class AlphaLinkCampaignTrackingTokenBuilder
{
    /**
     * @param  list<string>  $fields  Campaign, send, contact, type, list, ISP, server, IP, domain, From, Subject and creative IDs.
     */
    public function build(array $fields, string $applicationName): string
    {
        if (count($fields) !== 12) {
            throw new InvalidArgumentException('Campaign tracking tokens require exactly twelve attribution fields.');
        }

        $fields[] = Str::random(9).'_'.hash('adler32', $applicationName);
        $fields[] = hash('adler32', implode('_', array_slice($fields, 0, 3)));

        // Apache resolves each URL segment against the filesystem before
        // mod_rewrite runs, so long Base64Url values must span route segments.
        // The installed tracker joins the optional path tail before decoding.
        $encoded = rtrim(strtr(base64_encode(implode('-', $fields)), '+/', '-_'), '=');

        return implode('/', str_split($encoded, 160));
    }
}
