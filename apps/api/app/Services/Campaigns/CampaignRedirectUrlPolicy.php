<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Enforces the campaign tracking-link destination and transport policy. */
class CampaignRedirectUrlPolicy
{
    public const UNKNOWN_REDIRECT_URL = 'https://www.google.com';

    /** @param array<string, mixed> $installParameters */
    public function readyTrackingHost(array $installParameters): ?string
    {
        // HTTP and HTTPS readiness are separate: a working HTTP tracker remains
        // usable while HTTPS is being fixed or verified.
        if (! (bool) ($installParameters['tracking'] ?? false)) {
            return null;
        }

        $trackingHost = trim((string) ($installParameters['tracking_host'] ?? ''));
        $trackingHost = preg_replace('/\Ahttps?:\/\//i', '', $trackingHost) ?? $trackingHost;
        $trackingHost = rtrim($trackingHost, '/');
        $parsed = parse_url('https://'.$trackingHost);
        $host = is_array($parsed) ? ($parsed['host'] ?? null) : null;
        $port = is_array($parsed) ? ($parsed['port'] ?? null) : null;
        $normalizedHost = is_string($host) ? trim($host, '[]') : '';

        if (
            $normalizedHost === ''
            || filter_var($normalizedHost, FILTER_VALIDATE_IP) !== false
            || filter_var($normalizedHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || isset($parsed['user'])
            || isset($parsed['pass'])
            || (isset($parsed['path']) && $parsed['path'] !== '')
            || isset($parsed['query'])
            || isset($parsed['fragment'])
            || ($port !== null && $port !== 443)
        ) {
            return null;
        }

        return strtolower(rtrim($normalizedHost, '.'));
    }

    public function campaignTrackingClickUrl(string $trackingHost, string $token, bool $httpsReady = false): string
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

        return ($httpsReady ? 'https://' : 'http://').$urlHost.'/'.$trackingPath.'?t=1';
    }

    public function isTrackingHostOrSubdomain(string $host, string $trackingHost): bool
    {
        $normalize = static function (string $value): string {
            $value = trim($value);
            $value = preg_replace('/\Ahttps?:\/\//i', '', $value) ?? $value;
            $value = rtrim($value, '/');
            $parsedHost = parse_url('https://'.$value, PHP_URL_HOST);

            return is_string($parsedHost) ? strtolower(rtrim($parsedHost, '.')) : '';
        };

        $candidate = $normalize($host);
        $tracking = $normalize($trackingHost);

        return $candidate !== '' && $tracking !== ''
            && ($candidate === $tracking || str_ends_with($candidate, '.'.$tracking));
    }

    public function forceSchemeForHost(string $content, string $host, string $scheme): string
    {
        if ($content === '' || trim($host) === '') {
            return $content;
        }

        $scheme = strtolower($scheme);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return $content;
        }

        $hostWithScheme = str_contains($host, '://') ? $host : 'https://'.$host;
        $targetHost = parse_url($hostWithScheme, PHP_URL_HOST);
        if (! is_string($targetHost) || $targetHost === '') {
            return $content;
        }

        $pattern = '~\bhttps?://'.preg_quote(strtolower(rtrim($targetHost, '.')), '~').'(?:\:(?:80|443))?(?=[/:?#\s"\'<>]|$)~i';

        return preg_replace($pattern, $scheme.'://'.strtolower(rtrim($targetHost, '.')), $content) ?? $content;
    }

    public function forceHttpsForHost(string $content, string $host): string
    {
        return $this->forceSchemeForHost($content, $host, 'https');
    }
}
