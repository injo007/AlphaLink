<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Discovers every PMTA relay egress address observed by independent endpoints. */
class PmtaProxyEgressResolver
{
    /**
     * @param  array{host: string, port: int, username: string, password: string, url: string}  $proxy
     * @return list<string>
     */
    public function resolve(array $proxy): array
    {
        $endpoints = [
            'http://api4.ipify.org',
            'http://checkip.amazonaws.com',
            'http://ipv4.icanhazip.com',
            'http://v4.ident.me',
        ];
        $observed = [];
        $validResponses = 0;

        foreach ($endpoints as $endpoint) {
            try {
                $response = Http::connectTimeout(3)
                    ->timeout(6)
                    ->withOptions(['allow_redirects' => false, 'proxy' => $proxy['url']])
                    ->get($endpoint);
            } catch (Throwable) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $address = trim($response->body());
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                continue;
            }

            $validResponses++;
            $observed[$address] = $address;
        }

        if ($validResponses < 2) {
            return [];
        }

        $addresses = array_values($observed);
        sort($addresses, SORT_STRING);

        return $addresses;
    }
}
