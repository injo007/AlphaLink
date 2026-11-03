<?php

namespace App\Services\Campaigns;

class CampaignAutomatedTrafficClassifier
{
    private const AUTOMATION_USER_AGENT_PATTERN = '/(?:googleimageproxy|googlebot|bingbot|yahoomailproxy|yahoo!\s+slurp|proofpoint|mimecast|barracuda|safelinks|urlscan|headlesschrome|phantomjs|facebookexternalhit|twitterbot|slackbot|discordbot|telegrambot|whatsapp|applebot|python-requests|go-http-client|libwww-perl|\bcurl(?:\/|\s|$)|\bwget(?:\/|\s|$)|\b(?:bot|crawler|spider|scanner|link.?check(?:er)?|preview|fetcher)\b)/i';

    /**
     * Verified Google fetcher /27s observed in this campaign's visitor log.
     * They are published in Google's special-crawlers.json and
     * user-triggered-fetchers-google.json, respectively.
     *
     * @var list<string>
     */
    private const GOOGLE_PROXY_IPV4_PREFIXES = [
        '108.177.2.',
        '192.178.15.',
    ];

    public function normalizeAction(string $action, string $userAgent, ?string $ipAddress = null): string
    {
        if (! in_array($action, ['open', 'click'], true)) {
            return $action;
        }

        if (trim($userAgent) !== '' && preg_match(self::AUTOMATION_USER_AGENT_PATTERN, $userAgent) === 1) {
            return 'bot';
        }

        return $action === 'open' && $this->isGoogleProxyAddress($ipAddress)
            ? 'bot'
            : $action;
    }

    private function isGoogleProxyAddress(?string $ipAddress): bool
    {
        if ($ipAddress === null || filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        foreach (self::GOOGLE_PROXY_IPV4_PREFIXES as $prefix) {
            if (! str_starts_with($ipAddress, $prefix)) {
                continue;
            }

            $lastOctet = (int) substr($ipAddress, strlen($prefix));

            return $lastOctet >= 0 && $lastOctet < 32;
        }

        return false;
    }
}
