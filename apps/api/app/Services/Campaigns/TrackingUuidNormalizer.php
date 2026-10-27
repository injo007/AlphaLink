<?php

namespace App\Services\Campaigns;

class TrackingUuidNormalizer
{
    public function canonical(mixed $value): mixed
    {
        if (! is_string($value) || preg_match('/\A[0-9a-f]{32}\z/i', $value) !== 1) {
            return $value;
        }

        $hex = strtolower($value);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
