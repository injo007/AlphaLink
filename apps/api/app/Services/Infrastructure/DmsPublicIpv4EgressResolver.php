<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Resolves the public IPv4 address AlphaLink uses for outbound connections. */
class DmsPublicIpv4EgressResolver
{
    private const METADATA_IP = 'http://169.254.169.254';

    /** @var list<string> */
    private const ENDPOINTS = [
        'https://api4.ipify.org',
        'https://checkip.amazonaws.com',
        'https://ipv4.icanhazip.com',
        'https://v4.ident.me',
    ];

    /**
     * Resolve provider-assigned addresses first so a blocked public-IP service
     * cannot prevent installs on supported cloud hosts. Public HTTPS checks are
     * the portable fallback for hosts without an accessible metadata service.
     *
     * @return list<string> Verified public IPv4 addresses, or an empty list.
     */
    public function resolve(): array
    {
        $metadataAddresses = $this->providerMetadataAddresses();
        if ($metadataAddresses !== []) {
            return $metadataAddresses;
        }

        $observed = [];
        foreach (self::ENDPOINTS as $endpoint) {
            try {
                $response = Http::accept('text/plain')
                    ->connectTimeout(2)
                    ->timeout(4)
                    ->withoutRedirecting()
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

        $counts = array_count_values($observed);
        $confirmed = array_keys(array_filter($counts, static fn (int $count): bool => $count >= 2));
        sort($confirmed, SORT_STRING);

        return array_values($confirmed);
    }

    /** @return list<string> */
    private function providerMetadataAddresses(): array
    {
        $digitalOceanAddress = $this->metadataText('/metadata/v1/interfaces/public/0/ipv4/address');
        if ($this->isPublicIpv4($digitalOceanAddress)) {
            return [$digitalOceanAddress];
        }

        // Linode/Akamai requires a short-lived metadata token before /v1/network.
        try {
            $tokenResponse = Http::connectTimeout(1)
                ->timeout(2)
                ->withoutRedirecting()
                ->withOptions(['proxy' => ''])
                ->withHeader('Metadata-Token-Expiry-Seconds', '60')
                ->put(self::METADATA_IP.'/v1/token');
            $token = trim($tokenResponse->body());
            if (! $tokenResponse->successful() || $token === '') {
                return [];
            }

            $networkResponse = Http::acceptJson()
                ->connectTimeout(1)
                ->timeout(2)
                ->withoutRedirecting()
                ->withOptions(['proxy' => ''])
                ->withHeader('Metadata-Token', $token)
                ->get(self::METADATA_IP.'/v1/network');
            if (! $networkResponse->successful()) {
                return [];
            }

            $publicAddresses = $networkResponse->json('ipv4.public', []);
            if (! is_array($publicAddresses)) {
                return [];
            }

            $addresses = [];
            foreach ($publicAddresses as $value) {
                if (! is_string($value)) {
                    continue;
                }
                $address = trim($value);
                if (str_ends_with($address, '/32')) {
                    $address = substr($address, 0, -3);
                }
                if ($this->isPublicIpv4($address)) {
                    $addresses[] = $address;
                }
            }

            $addresses = array_values(array_unique($addresses));
            sort($addresses, SORT_STRING);

            return $addresses;
        } catch (Throwable) {
            return [];
        }
    }

    private function metadataText(string $path): string
    {
        try {
            $response = Http::accept('text/plain')
                ->connectTimeout(1)
                ->timeout(2)
                ->withoutRedirecting()
                ->withOptions(['proxy' => ''])
                ->get(self::METADATA_IP.$path);

            return $response->successful() ? trim($response->body()) : '';
        } catch (Throwable) {
            return '';
        }
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
