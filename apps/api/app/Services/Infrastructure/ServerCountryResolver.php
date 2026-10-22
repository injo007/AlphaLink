<?php

namespace App\Services\Infrastructure;

use GeoIp2\Database\Reader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class ServerCountryResolver
{
    private const IP_API_URL = 'https://pro.ip-api.com/json/';

    public function countryCode(string $ip): ?string
    {
        try {
            $apiKey = config('infrastructure.ip_api_key');
            if (is_string($apiKey) && $apiKey !== '') {
                return Cache::remember($this->cacheKey($ip), now()->endOfDay(), function () use ($apiKey, $ip): string {
                    $response = Http::timeout(5)->get(self::IP_API_URL.$ip, [
                        'key' => $apiKey,
                        'fields' => '197914623',
                    ]);

                    if ($response->failed()) {
                        throw new \RuntimeException('IP geolocation request failed.');
                    }

                    $data = $response->json();
                    if (! is_array($data) || ($data['status'] ?? null) !== 'success') {
                        throw new \RuntimeException('IP geolocation returned an unsuccessful response.');
                    }

                    return (string) ($data['countryCode'] ?? 'NA');
                });
            }
        } catch (Throwable) {
            return $this->maxMindCountryCode($ip);
        }

        return $this->maxMindCountryCode($ip);
    }

    private function maxMindCountryCode(string $ip): ?string
    {
        $database = (string) config('infrastructure.maxmind_city_database', '');
        if ($database === '' || ! is_file($database) || ! class_exists(Reader::class)) {
            return null;
        }

        try {
            $reader = new Reader($database);

            return $reader->city($ip)->country?->isoCode;
        } catch (Throwable) {
            return null;
        }
    }

    private function cacheKey(string $ip): string
    {
        return 'ip_api:'.str_replace([':', '.'], '_', $ip);
    }
}
