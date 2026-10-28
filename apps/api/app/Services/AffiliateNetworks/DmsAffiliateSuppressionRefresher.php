<?php

namespace App\Services\AffiliateNetworks;

use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use App\Services\Campaigns\DmsOfferSuppressionFile;
use Closure;

class DmsAffiliateSuppressionRefresher
{
    public function __construct(
        private readonly DmsAffiliateProviderHttp $http,
        private readonly DmsOfferSuppressionFile $files,
    ) {}

    public function refresh(Workspace $workspace, WorkspaceOffer $offer): bool
    {
        return $this->refreshWithProgress($workspace, $offer) !== null;
    }

    /**
     * @param  Closure(int, ?int, string): void|null  $onProgress
     * @return array{bytes: int, sha256: string, format: 'email'|'md5', count: int}|null
     */
    public function refreshWithProgress(Workspace $workspace, WorkspaceOffer $offer, ?Closure $onProgress = null): ?array
    {
        $sponsor = $workspace->sponsors()->whereKey($offer->sponsor_id)->first();
        if (! $sponsor instanceof WorkspaceSponsor || ! $sponsor->is_active) {
            return null;
        }

        $link = $this->suppressionLink($sponsor, $offer);
        if (! is_string($link) || trim($link) === '') {
            return null;
        }

        $downloadUrl = $this->downloadUrl($link);
        if ($downloadUrl === null) {
            return null;
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'alphalink-suppression-source-');
        if (! is_string($temporaryPath)) {
            return null;
        }

        try {
            $bytes = $this->http->downloadToFileFollowingPublicRedirects(
                $downloadUrl,
                $temporaryPath,
                [],
                $onProgress,
            );
            if (! is_int($bytes) || $bytes < 1) {
                return null;
            }

            if ($onProgress !== null) {
                $onProgress($bytes, $bytes, 'validating');
            }
            $stored = $this->files->storeDownloadedFile($workspace, $offer, $temporaryPath);
            $offer->forceFill([
                'suppression_path' => $stored['path'],
                'suppression_sha256' => $stored['sha256'],
                'suppression_format' => $stored['format'],
                'suppression_updated_at' => now()->toDateString(),
            ])->save();

            return [...$stored, 'bytes' => $bytes];
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function suppressionLink(WorkspaceSponsor $sponsor, WorkspaceOffer $offer): ?string
    {
        if (! is_numeric($offer->offer_id) || blank($sponsor->api_key) || blank($sponsor->url)) {
            return null;
        }

        $host = $this->sponsorHost($sponsor);
        $offerId = (int) $offer->offer_id;

        return match ($sponsor->type) {
            'hitpath' => $this->hitPathSuppressionLink($sponsor, $host, $offerId),
            'newhitpath' => $this->newHitPathSuppressionLink($sponsor, $host, $offerId),
            'cake' => $this->cakeSuppressionLink($sponsor, $host, $offerId),
            'hasoffers' => $this->hasOffersSuppressionLink($sponsor, $host, $offerId),
            'everflow' => $this->everFlowSuppressionLink($sponsor, $offerId),
            default => null,
        };
    }

    private function hitPathSuppressionLink(WorkspaceSponsor $sponsor, string $host, int $offerId): ?string
    {
        $host = preg_replace('/^affiliate\./i', '', $host) ?? $host;
        $url = sprintf('https://api.%s/pubapi.php', $host);
        $response = $this->http->postForm($url, [
            'apikey' => (string) $sponsor->api_key,
            'apifunc' => 'getsuppression',
            'campaignid' => $offerId,
        ]);
        if (! $response->successful()) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($response->body(), \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $link = $xml instanceof \SimpleXMLElement ? trim((string) ($xml->data->suppurl ?? '')) : '';

        return $link !== '' ? $link : null;
    }

    private function newHitPathSuppressionLink(WorkspaceSponsor $sponsor, string $host, int $offerId): ?string
    {
        $response = $this->http->get(
            sprintf('https://%s/api/campaigns/%d/suppression', $host, $offerId),
            ['Authorization' => 'Bearer '.(string) $sponsor->api_key],
        );

        $link = $response->successful() ? $response->json('data.download_url') : null;

        return is_string($link) ? $link : null;
    }

    private function cakeSuppressionLink(WorkspaceSponsor $sponsor, string $host, int $offerId): ?string
    {
        if (blank($sponsor->affiliate_id)) {
            return null;
        }

        $campaignUrl = sprintf('https://%s/affiliates/api/Offers/Campaign', $host);
        $campaign = $this->http->get($campaignUrl.'?'.http_build_query([
            'api_key' => (string) $sponsor->api_key,
            'affiliate_id' => (string) $sponsor->affiliate_id,
            'campaign_id' => $offerId,
        ], '', '&', PHP_QUERY_RFC3986));
        $rows = $campaign->successful() ? $campaign->json('data') : null;
        $row = is_array($rows) ? current($rows) : null;
        $suppressionOfferId = is_array($row) ? ($row['offer_id'] ?? null) : null;
        if (! is_scalar($suppressionOfferId)) {
            return null;
        }

        $url = sprintf('https://%s/affiliates/api/Offers/SuppressionList', $host);
        $response = $this->http->get($url.'?'.http_build_query([
            'api_key' => (string) $sponsor->api_key,
            'affiliate_id' => (string) $sponsor->affiliate_id,
            'offer_id' => $suppressionOfferId,
        ], '', '&', PHP_QUERY_RFC3986));
        $link = $response->successful() ? $response->json('download_url') : null;

        return is_string($link) ? $link : null;
    }

    private function hasOffersSuppressionLink(WorkspaceSponsor $sponsor, string $host, int $offerId): ?string
    {
        $affiliateId = trim((string) $sponsor->affiliate_id);
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $affiliateId)) {
            return null;
        }

        $apiUrl = sprintf('https://%s.api.hasoffers.com/Apiv3/json', strtolower($affiliateId));
        $query = http_build_query([
            'api_key' => (string) $sponsor->api_key,
            'Target' => 'Affiliate_Offer',
            'Method' => 'findById',
            'id' => $offerId,
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $this->http->get($apiUrl.'?'.$query);
        $link = $response->successful() && $response->json('response.status') == 1
            ? $response->json('response.data.Offer.dne_download_url')
            : null;
        if (is_string($link) && trim($link) !== '') {
            return $link;
        }

        $labels = explode('.', $host);
        $baseDomain = implode('.', array_slice($labels, -2));
        $crmUrl = sprintf('https://crm3-java.%s/api/v1/proxy/offers/%d?%s', $baseDomain, $offerId, http_build_query(
            ['apiKey' => (string) $sponsor->api_key],
            '',
            '&',
            PHP_QUERY_RFC3986,
        ));
        $response = $this->http->get($crmUrl);
        $link = $response->successful() ? $response->json('suppressionFile') : null;

        return is_string($link) ? $link : null;
    }

    private function everFlowSuppressionLink(WorkspaceSponsor $sponsor, int $offerId): ?string
    {
        $response = $this->http->get(
            sprintf('https://api.eflow.team/v1/affiliates/offers/%d', $offerId),
            ['x-eflow-api-key' => (string) $sponsor->api_key],
        );
        $offer = $response->successful() ? $response->json() : null;
        $relationship = is_array($offer) ? ($offer['relationship'] ?? null) : null;
        if (! is_array($relationship)) {
            return null;
        }

        if (array_key_exists('integrations', $relationship)) {
            $link = data_get($relationship, 'integrations.optizmo.mailer_access_key');
        } else {
            $link = data_get($relationship, 'email_optout.suppression_file_link');
        }

        return is_string($link) ? $link : null;
    }

    private function downloadUrl(string $link): ?string
    {
        $parts = parse_url(trim($link));
        if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
            return null;
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if (str_contains($host, 'dropbox.com')) {
            $query = [];
            parse_str((string) ($parts['query'] ?? ''), $query);
            $query['dl'] = '1';
            $parts['query'] = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

            return $this->urlFromParts($parts);
        }

        if (str_contains($host, 'docs.google.com') && preg_match('~/spreadsheets/d/([^/]+)~', (string) ($parts['path'] ?? ''), $match) === 1) {
            return 'https://docs.google.com/spreadsheets/d/'.rawurlencode($match[1]).'/export?format=csv&gid=0';
        }

        if (str_contains($host, 'optizmo') || str_contains($host, 'affiliateaccesskey')) {
            $token = null;
            if (str_contains($host, 'app.optizmo')) {
                parse_str((string) ($parts['query'] ?? ''), $query);
                $token = $query['mak'] ?? null;
            } else {
                $token = explode('&', ltrim((string) ($parts['path'] ?? ''), '/'))[0] ?? null;
            }
            $apiToken = config('services.optizmo.token');
            if (! is_string($token) || $token === '' || ! is_string($apiToken) || $apiToken === '') {
                return null;
            }

            return 'https://mailer-api.optizmo.net/accesskey/download/'.rawurlencode($token).'?'.http_build_query([
                'token' => $apiToken,
                'deltas' => 0,
            ], '', '&', PHP_QUERY_RFC3986);
        }

        return $this->urlFromParts($parts);
    }

    /** @param array<string, mixed> $parts */
    private function urlFromParts(array $parts): ?string
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if ($scheme !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return 'https://'.strtolower((string) $parts['host']).$port.(string) ($parts['path'] ?? '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function sponsorHost(WorkspaceSponsor $sponsor): string
    {
        $url = trim((string) $sponsor->url);
        if (! str_contains($url, '://')) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower(rtrim((string) ($parts['host'] ?? ''), '.')) : '';
        $port = is_array($parts) ? (int) ($parts['port'] ?? 443) : 0;
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || $port !== 443
            || isset($parts['user'])
            || isset($parts['pass'])
            || filter_var($host, FILTER_VALIDATE_IP)
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new DmsAffiliateProviderException('The configured affiliate provider endpoint is not a valid public HTTPS host.');
        }

        return $host;
    }
}
