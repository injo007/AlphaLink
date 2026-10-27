<?php

namespace App\Services\Campaigns;

use App\Models\Workspace;
use App\Services\Infrastructure\ServerCountryResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class CampaignIpGeoResolver
{
    private const IP_API_URL = 'https://pro.ip-api.com/json/';

    /** @return array{code: string, country: ?string, state: ?string, city: ?string, proxy: bool, hosting: bool, org: string}|null */
    public function resolve(Workspace $workspace, string $ip): ?array
    {
        $settings = $workspace->appSettings?->settings ?? [];
        $apiKey = $settings['ip_api_key'] ?? config('infrastructure.ip_api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            $code = app(ServerCountryResolver::class)->countryCode($ip);

            return $code === null ? null : $this->countryOnly($code);
        }

        try {
            $location = Cache::remember($this->cacheKey((string) $workspace->getKey(), $ip), now()->endOfDay(), function () use ($apiKey, $ip): ?array {
                $response = Http::timeout(5)->get(self::IP_API_URL.$ip, [
                    'key' => $apiKey,
                    'fields' => '197914623',
                ]);
                $data = $response->json();
                if ($response->failed() || ! is_array($data) || ($data['status'] ?? null) !== 'success') {
                    return null;
                }

                return [
                    'code' => (string) ($data['countryCode'] ?? 'NA'),
                    'country' => is_string($data['country'] ?? null) ? $data['country'] : null,
                    'state' => is_string($data['regionName'] ?? null) ? $data['regionName'] : null,
                    'city' => is_string($data['city'] ?? null) ? $data['city'] : null,
                    'proxy' => (bool) ($data['proxy'] ?? false),
                    'hosting' => (bool) ($data['hosting'] ?? false),
                    'org' => strtolower((string) ($data['org'] ?? '')),
                ];
            });

            if (is_array($location)) {
                return $location;
            }
        } catch (Throwable) {
            // The source falls back to its local country database when the API fails.
        }

        $code = app(ServerCountryResolver::class)->countryCode($ip);

        return $code === null ? null : $this->countryOnly($code);
    }

    /** @return array{code: string, country: ?string, state: ?string, city: ?string, proxy: bool, hosting: bool, org: string} */
    private function countryOnly(string $code): array
    {
        return [
            'code' => strtoupper($code),
            'country' => null,
            'state' => null,
            'city' => null,
            'proxy' => false,
            'hosting' => false,
            'org' => '',
        ];
    }

    private function cacheKey(string $workspaceId, string $ip): string
    {
        return 'campaign_ip_geo:'.$workspaceId.':'.str_replace([':', '.'], '_', $ip);
    }
}
