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
        // Only use a standalone tracker after its HTTPS route passed the
        // installer's public health and redirect checks.
        if (! (bool) ($installParameters['tracking'] ?? false)
            || ! (bool) ($installParameters['tracking_https_ready'] ?? false)) {
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

    public function campaignTrackingClickUrl(string $trackingHost, string $token, bool $httpsReady = true): string
    {
        return $this->campaignTrackingUrl($trackingHost, $token, 'r', $httpsReady);
    }

    public function campaignTrackingOpenUrl(string $trackingHost, string $token, bool $httpsReady = true): string
    {
        return $this->campaignTrackingUrl($trackingHost, $token, 'o', $httpsReady);
    }

    public function campaignTrackingUnsubscribeUrl(string $trackingHost, string $token, bool $httpsReady = true): string
    {
        return $this->campaignTrackingUrl($trackingHost, $token, 'u', $httpsReady);
    }

    /** @param array<string, mixed> $parameters */
    public function signedApplicationCampaignUrl(string $routeName, DateTimeInterface $expiration, array $parameters): string
    {
        $applicationUrl = trim((string) config('app.url'));
        $applicationHost = parse_url($applicationUrl, PHP_URL_HOST);
        $normalizedHost = is_string($applicationHost) ? $this->normalizeCampaignLinkHost($applicationHost) : null;

        if ($normalizedHost === null) {
            throw ValidationException::withMessages([
                'domain' => 'Campaign tracking requires a valid AlphaLink application URL.',
            ]);
        }

        $relativeUrl = URL::temporarySignedRoute($routeName, $expiration, $parameters, absolute: false);
        $path = trim((string) parse_url($applicationUrl, PHP_URL_PATH), '/');
        $port = parse_url($applicationUrl, PHP_URL_PORT);
        $portSuffix = $port !== null && ! in_array((int) $port, [80, 443], true) ? ':'.$port : '';

        return 'https://'.$this->formatCampaignLinkHost($normalizedHost).$portSuffix.($path === '' ? '' : '/'.$path).$relativeUrl;
    }

    private function campaignTrackingUrl(string $trackingHost, string $token, string $action, bool $httpsReady): string
    {
        $trackingHost = trim($trackingHost);
        $trackingHost = preg_replace('/\Ahttps?:\/\//i', '', $trackingHost) ?? $trackingHost;
        $trackingHost = rtrim($trackingHost, '/');
        $parsed = parse_url('https://'.$trackingHost);
        $host = is_array($parsed) ? ($parsed['host'] ?? null) : null;
        $port = is_array($parsed) ? ($parsed['port'] ?? null) : null;
        $normalizedHost = is_string($host) ? strtolower(rtrim(trim($host, '[]'), '.')) : '';
        $isHostname = $normalizedHost !== '' && filter_var($normalizedHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        $encodedToken = str_replace('/', '', $token);

        if (
            ! $httpsReady
            || ! $isHostname
            || isset($parsed['user'])
            || isset($parsed['pass'])
            || (isset($parsed['path']) && $parsed['path'] !== '')
            || isset($parsed['query'])
            || isset($parsed['fragment'])
            || ($port !== null && $port !== 443)
            || preg_match('/\A[A-Za-z0-9_-]+\z/', $encodedToken) !== 1
            || ! in_array($action, ['r', 'o', 'u'], true)
        ) {
            throw ValidationException::withMessages([
                'domain' => 'Tracked campaign links require a DNS hostname with verified HTTPS.',
            ]);
        }

        $tokenSegments = str_split($encodedToken, 149);
        $trackingPath = Str::upper(Str::random(10)).$action.array_shift($tokenSegments);
        if ($tokenSegments !== []) {
            $trackingPath .= '/'.implode('/', $tokenSegments);
        }

        return 'https://'.$this->formatCampaignLinkHost($normalizedHost).'/'.$trackingPath.'?t=1';
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
