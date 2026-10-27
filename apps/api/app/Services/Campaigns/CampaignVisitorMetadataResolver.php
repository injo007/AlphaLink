<?php

namespace App\Services\Campaigns;

use App\Models\Workspace;
use Illuminate\Support\Str;

class CampaignVisitorMetadataResolver
{
    public function __construct(private readonly CampaignIpGeoResolver $geoResolver) {}

    /** @return array{country: ?string, country_code: ?string, state: ?string, city: ?string, device: ?string, platform: ?string, browser: ?string, is_mobile: ?bool, blocked_open: bool} */
    public function resolve(Workspace $workspace, string $ip, ?string $userAgent, string $action): array
    {
        $location = $this->geoResolver->resolve($workspace, $ip);
        $agent = $this->parseUserAgent($userAgent ?? '');
        $blockedOrganizations = $workspace->appSettings?->settings['blocked_open_traffic'] ?? [];
        $organization = (string) ($location['org'] ?? '');
        $blockedOpen = $action === 'open'
            && $organization !== ''
            && is_array($blockedOrganizations)
            && Str::contains($organization, array_values(array_filter(
                array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', $blockedOrganizations),
                static fn (string $value): bool => $value !== '',
            )), true);

        return [
            'country' => $location['country'] ?? null,
            'country_code' => $location['code'] ?? null,
            'state' => $location['state'] ?? null,
            'city' => $location['city'] ?? null,
            ...$agent,
            'blocked_open' => $blockedOpen,
        ];
    }

    /** @return array{device: ?string, platform: ?string, browser: ?string, is_mobile: ?bool} */
    public function parseUserAgent(string $userAgent): array
    {
        if (trim($userAgent) === '') {
            return ['device' => null, 'platform' => null, 'browser' => null, 'is_mobile' => null];
        }

        $device = match (true) {
            preg_match('/\biPhone\b/i', $userAgent) === 1 => 'iPhone',
            preg_match('/\biPad\b/i', $userAgent) === 1 => 'iPad',
            preg_match('/\biPod\b/i', $userAgent) === 1 => 'iPod',
            preg_match('/\bAndroid\b/i', $userAgent) === 1 => 'Android',
            preg_match('/\bWindows Phone\b/i', $userAgent) === 1 => 'Windows Phone',
            default => null,
        };
        $platform = match (true) {
            preg_match('/\bAndroid\b/i', $userAgent) === 1 => 'Android',
            preg_match('/\biPhone|iPad|iPod\b/i', $userAgent) === 1 => 'iOS',
            preg_match('/\bWindows Phone\b/i', $userAgent) === 1 => 'Windows Phone',
            preg_match('/\bWindows NT\b/i', $userAgent) === 1 => 'Windows',
            preg_match('/\bMac OS X\b/i', $userAgent) === 1 => 'macOS',
            preg_match('/\bCrOS\b/i', $userAgent) === 1 => 'Chrome OS',
            preg_match('/\bLinux\b/i', $userAgent) === 1 => 'Linux',
            default => null,
        };
        $browser = match (true) {
            preg_match('/\bEdg(?:e|A|iOS)?\//i', $userAgent) === 1 => 'Edge',
            preg_match('/\b(?:OPR|Opera)\//i', $userAgent) === 1 => 'Opera',
            preg_match('/\bSamsungBrowser\//i', $userAgent) === 1 => 'Samsung Internet',
            preg_match('/\bFirefox\//i', $userAgent) === 1 => 'Firefox',
            preg_match('/\b(?:Chrome|CriOS)\//i', $userAgent) === 1 => 'Chrome',
            preg_match('/\b(?:MSIE\s|Trident\/)/i', $userAgent) === 1 => 'Internet Explorer',
            preg_match('/\bSafari\//i', $userAgent) === 1 => 'Safari',
            default => null,
        };
        $isMobile = $device !== null;

        return [
            'device' => $device,
            'platform' => $platform,
            'browser' => $browser,
            'is_mobile' => $isMobile,
        ];
    }
}
