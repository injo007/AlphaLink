<?php

namespace App\Services\Campaigns;

/** Enforces the campaign tracking-link destination and transport policy. */
class CampaignRedirectUrlPolicy
{
    public const UNKNOWN_REDIRECT_URL = 'https://www.google.com';

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
