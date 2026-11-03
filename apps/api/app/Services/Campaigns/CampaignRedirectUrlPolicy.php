<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Enforces the campaign tracking-link destination and transport policy. */
class CampaignRedirectUrlPolicy
{
    public const UNKNOWN_REDIRECT_URL = 'https://www.google.com';

    public function campaignTrackingClickUrl(string $trackingHost, string $token): string
    {
        $trackingHost = trim($trackingHost);
        $trackingHost = preg_replace('/\Ahttps?:\/\//i', '', $trackingHost) ?? $trackingHost;
        $trackingHost = rtrim($trackingHost, '/');
        $parsed = parse_url('https://'.$trackingHost);
        $host = is_array($parsed) ? ($parsed['host'] ?? null) : null;
        $port = is_array($parsed) ? ($parsed['port'] ?? null) : null;
        $normalizedHost = is_string($host) ? trim($host, '[]') : '';
        $isIpAddress = $normalizedHost !== '' && filter_var($normalizedHost, FILTER_VALIDATE_IP) !== false;
        $isHostname = $normalizedHost !== '' && filter_var($normalizedHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;

        if (
            (! $isIpAddress && ! $isHostname)
            || (isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['path']) && $parsed['path'] !== '' || isset($parsed['query']) || isset($parsed['fragment']))
            || ($port !== null && $port !== 443)
        ) {
            throw ValidationException::withMessages([
                'domain' => 'Choose an active tracking domain with a valid HTTPS hostname before sending tracked links.',
            ]);
        }

        $urlHost = filter_var($normalizedHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '['.$normalizedHost.']'
            : strtolower(rtrim($normalizedHost, '.'));
        $tokenSegments = str_split(str_replace('/', '', $token), 149);
        $trackingPath = Str::upper(Str::random(10)).'r'.array_shift($tokenSegments);
        if ($tokenSegments !== []) {
            $trackingPath .= '/'.implode('/', $tokenSegments);
        }

        return 'https://'.$urlHost.'/'.$trackingPath.'?t=1';
    }

    public function forceHttpsForHost(string $content, string $host): string
    {
        if ($content === '' || trim($host) === '') {
            return $content;
        }

        $hostWithScheme = str_contains($host, '://') ? $host : 'https://'.$host;
        $targetHost = parse_url($hostWithScheme, PHP_URL_HOST);
        if (! is_string($targetHost) || $targetHost === '') {
            return $content;
        }

        $pattern = '~\bhttp://'.preg_quote(strtolower(rtrim($targetHost, '.')), '~').'(?:\:80)?(?=[/:?#\s"\'<>]|$)~i';

        return preg_replace($pattern, 'https://'.strtolower(rtrim($targetHost, '.')), $content) ?? $content;
    }
}
