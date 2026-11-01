<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Builds the campaign token shape consumed by DMS's installed tracking mini-app. */
class DmsCampaignTrackingTokenBuilder
{
    /**
     * @param  list<string>  $fields  Campaign, send, contact, type, list, ISP, server, IP, domain, From, Subject and creative IDs.
     */
    public function build(array $fields, string $applicationName): string
    {
        if (count($fields) !== 12) {
            throw new InvalidArgumentException('DMS campaign tracking tokens require exactly twelve attribution fields.');
        }

        $fields[] = Str::random(9).'_'.hash('adler32', $applicationName);
        $fields[] = hash('adler32', implode('_', array_slice($fields, 0, 3)));

        // The tracking token is embedded in one URL path segment. The installed
        // mini-app maps Base64Url characters back before strict decoding.
        return rtrim(strtr(base64_encode(implode('-', $fields)), '+/', '-_'), '=');
    }
}
