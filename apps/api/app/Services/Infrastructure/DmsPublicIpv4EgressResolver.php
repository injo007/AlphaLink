<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Resolves the public IPv4 address AlphaLink uses for outbound connections. */
class DmsPublicIpv4EgressResolver
{
    /** @var list<string> */
    private const ENDPOINTS = [
        'https://api.ipify.org',
        'https://checkip.amazonaws.com',
        'https://ipv4.icanhazip.com',
    ];

    /**
     * Require independent IPv4 checks to agree before granting PMTA relay access.
     *
     * @return list<string> A single verified public IPv4 address, or an empty list.
     */
    public function resolve(): array
    {
        $observed = [];

        foreach (self::ENDPOINTS as $endpoint) {
            try {
                $response = Http::accept('text/plain')
                    ->timeout(4)
                    ->withOptions([
                        'allow_redirects' => false,
                        'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                    ])
                    ->get($endpoint);
            } catch (Throwable) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $address = trim($response->body());
            if ($this->isPublicIpv4($address)) {
                $observed[] = $address;
            }
        }

        if (count($observed) < 2 || count(array_unique($observed)) !== 1) {
            return [];
        }

        return [$observed[0]];
    }

    public function isPublicIpv4(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
