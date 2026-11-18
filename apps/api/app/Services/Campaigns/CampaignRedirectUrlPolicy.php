<?php

namespace App\Services\Campaigns;

use DateTimeInterface;
use Illuminate\Support\Facades\URL;
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

    public function campaignTrackingClickUrl(string $trackingHost, string $token, bool $httpsReady = false, ?string $preferredHost = null): string
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

        $linkHost = $preferredHost === null ? $normalizedHost : $this->normalizeCampaignLinkHost($preferredHost);
        if ($linkHost === null) {
            throw ValidationException::withMessages([
                'domain' => 'Choose a valid campaign Domain or use the selected sending IP for tracked links.',
            ]);
        }

        $sameHost = hash_equals(strtolower(rtrim($normalizedHost, '.')), strtolower(rtrim($linkHost, '.')));
        $useHttps = $preferredHost === null || $sameHost
            ? $httpsReady
            : filter_var($linkHost, FILTER_VALIDATE_IP) === false;
        $urlHost = $this->formatCampaignLinkHost($linkHost);
        $tokenSegments = str_split(str_replace('/', '', $token), 149);
        $trackingPath = Str::upper(Str::random(10)).'r'.array_shift($tokenSegments);
        if ($tokenSegments !== []) {
            $trackingPath .= '/'.implode('/', $tokenSegments);
        }

        return ($useHttps ? 'https://' : 'http://').$urlHost.'/'.$trackingPath.'?t=1';
    }

    public function campaignTrackingUnsubscribeUrl(string $trackingHost, string $token, bool $httpsReady = false, ?string $preferredHost = null): string
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

        $linkHost = $preferredHost === null ? $normalizedHost : $this->normalizeCampaignLinkHost($preferredHost);
        if ($linkHost === null) {
            throw ValidationException::withMessages([
                'domain' => 'Choose a valid campaign Domain or use the selected sending IP for unsubscribe links.',
            ]);
        }

        $sameHost = hash_equals(strtolower(rtrim($normalizedHost, '.')), strtolower(rtrim($linkHost, '.')));
        $useHttps = $preferredHost === null || $sameHost
            ? $httpsReady
            : filter_var($linkHost, FILTER_VALIDATE_IP) === false;
        $urlHost = $this->formatCampaignLinkHost($linkHost);
        $tokenSegments = str_split(str_replace('/', '', $token), 149);
        $trackingPath = Str::upper(Str::random(10)).'u'.array_shift($tokenSegments);
        if ($tokenSegments !== []) {
            $trackingPath .= '/'.implode('/', $tokenSegments);
        }

        return ($useHttps ? 'https://' : 'http://').$urlHost.'/'.$trackingPath.'?t=1';
    }

    public function campaignLinkHost(string $selectedDomain, string $sendingIp): string
    {
        $candidate = trim($selectedDomain) !== '' ? $selectedDomain : $sendingIp;
        $host = $this->normalizeCampaignLinkHost($candidate);

        if ($host === null) {
            throw ValidationException::withMessages([
                'domain' => 'Choose a valid campaign Domain or use the selected sending IP for tracking links.',
            ]);
        }

        return $host;
    }

    /** @param array<string, mixed> $parameters */
    public function signedCampaignUrl(string $routeName, DateTimeInterface $expiration, array $parameters, string $host): string
    {
        $normalizedHost = $this->normalizeCampaignLinkHost($host);

        if ($normalizedHost === null) {
            throw ValidationException::withMessages([
                'domain' => 'Choose a valid campaign Domain or use the selected sending IP for tracking links.',
            ]);
        }

        $scheme = filter_var($normalizedHost, FILTER_VALIDATE_IP) === false ? 'https' : 'http';
        $relativeUrl = URL::temporarySignedRoute($routeName, $expiration, $parameters, absolute: false);

        return $scheme.'://'.$this->formatCampaignLinkHost($normalizedHost).$relativeUrl;
    }

    private function normalizeCampaignLinkHost(string $host): ?string
    {
        $host = trim($host);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return strtolower($host);
        }

        $host = strtolower(rtrim($host, '.'));

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false ? $host : null;
    }

    private function formatCampaignLinkHost(string $host): string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '['.$host.']' : $host;
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
