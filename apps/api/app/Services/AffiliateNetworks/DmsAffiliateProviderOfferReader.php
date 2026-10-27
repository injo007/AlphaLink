<?php

namespace App\Services\AffiliateNetworks;

use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use SimpleXMLElement;

class DmsAffiliateProviderOfferReader
{
    public function __construct(
        private readonly DmsAffiliateProviderHttp $http,
        private readonly DmsNewHitPathCreativeImporter $newHitPathCreativeImporter,
    ) {}

    /**
     * Fetch and map only the selected offers using the DMS provider contracts.
     *
     * @param  list<string>  $offerIds
     * @return list<array<string, mixed>>
     */
    public function read(WorkspaceSponsor $sponsor, array $offerIds, bool $throwOnProviderFailure = false): array
    {
        return match ($sponsor->type) {
            'hitpath' => $this->readHitPath($sponsor, $offerIds),
            'newhitpath' => $this->readNewHitPath($sponsor, $offerIds, $throwOnProviderFailure),
            'cake' => $this->readCake($sponsor, $offerIds),
            'hasoffers' => $this->readHasOffers($sponsor, $offerIds, $throwOnProviderFailure),
            'everflow' => $this->readEverFlow($sponsor, $offerIds),
            default => throw new DmsAffiliateProviderException('Automatic import is not available for this affiliate network.'),
        };
    }

    /** @param list<string> $offerIds
     * @return list<array<string, mixed>>
     */
    private function readHitPath(WorkspaceSponsor $sponsor, array $offerIds): array
    {
        $host = $this->sponsorHost($sponsor);
        $host = preg_replace('/^affiliate\./i', '', $host) ?? $host;
        $apiUrl = sprintf('https://api.%s/pubapi.php', $host);
        $response = $this->postHitPath($apiUrl, ['apifunc' => 'getcampaigns'], $sponsor->api_key);
        $requestedIds = array_fill_keys(array_filter($offerIds, 'is_numeric'), true);
        $offers = [];

        foreach ($this->rows($this->xmlData($response->body()), ['campaign', 'campaigns', 'item']) as $campaign) {
            $campaignId = $campaign['campaignid'] ?? null;
            if ((! is_string($campaignId) && ! is_int($campaignId)) || ! isset($requestedIds[(string) $campaignId])) {
                continue;
            }

            $assets = $this->readHitPathAssets($sponsor, $apiUrl, (string) $campaignId);
            $category = is_string($campaign['category'] ?? null) ? strtolower($campaign['category']) : '';
            $vertical = trim(explode(',', $category)[0] ?? '');
            $name = is_string($campaign['name'] ?? null) ? $campaign['name'] : '';
            $description = is_string($campaign['description'] ?? null) ? $campaign['description'] : '';
            $geoTarget = is_string($campaign['geotargeting'] ?? null) ? $this->matchCountries($campaign['geotargeting']) : [];
            $payout = is_scalar($campaign['payout'] ?? null) ? (string) $campaign['payout'] : '';

            $offers[] = [
                'offer_id' => (string) $campaignId,
                'name' => $name,
                'vertical' => $vertical,
                'geo_target' => $geoTarget,
                'price' => $this->formatMoney($payout),
                'type' => $this->matchType($name),
                'allowed_days' => $this->matchDays($name),
                'from' => $assets['from'],
                'subjects' => $assets['subjects'],
                'creatives' => [],
                'redirect' => $assets['redirect'],
                'unsubscribe' => $assets['unsubscribe'],
                'note' => $description,
            ];
        }

        return $offers;
    }

    /** @param array<string, scalar|null> $parameters */
    private function postHitPath(string $url, array $parameters, ?string $apiKey): Response
    {
        $response = $this->http->postForm($url, ['apikey' => (string) $apiKey] + $parameters);
        if (! $response->successful()) {
            throw new DmsAffiliateProviderException('The HitPath offer request failed. Check the sponsor API settings and try again.');
        }
        if (str_contains($response->body(), 'PLEASE WAIT')) {
            throw new DmsAffiliateProviderException('HitPath is temporarily delaying requests. Retry the selected offer import later.');
        }

        return $response;
    }

    /** @return array{from: list<string>, subjects: list<string>, redirect: string, unsubscribe: string} */
    private function readHitPathAssets(WorkspaceSponsor $sponsor, string $apiUrl, string $campaignId): array
    {
        $list = $this->postHitPath($apiUrl, ['apifunc' => 'getemaillist', 'campaignid' => $campaignId], $sponsor->api_key);
        $emailIds = [];
        foreach ($this->rows($this->xmlData($list->body()), ['email', 'emails', 'item']) as $email) {
            $emailId = $email['id'] ?? null;
            if (is_string($emailId) || is_int($emailId)) {
                $emailIds[] = (string) $emailId;
            }
        }

        $from = [];
        $subjects = [];
        $redirect = '';
        $unsubscribe = '';
        foreach (array_unique($emailIds) as $emailId) {
            $raw = $this->postHitPath($apiUrl, ['apifunc' => 'getemailraw', 'emailid' => $emailId], $sponsor->api_key);
            $email = $this->record($this->xmlData($raw->body()), ['email', 'item']);
            $from = array_values(array_unique([...$from, ...$this->lines($email['fromlines'] ?? null)]));
            $subjects = array_values(array_unique([...$subjects, ...$this->lines($email['subjects'] ?? null)]));

            if ($redirect !== '' || ! is_string($email['body'] ?? null) || $email['body'] === '') {
                continue;
            }

            $links = $this->anchorLinks($email['body']);
            if ($links !== []) {
                $redirect = $links[0];
                $unsubscribe = $links[count($links) - 1] === $redirect ? '' : $links[count($links) - 1];
            }
        }

        return compact('from', 'subjects', 'redirect', 'unsubscribe');
    }

    /** @param list<string> $names */
    private function xmlData(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $document instanceof SimpleXMLElement) {
            throw new DmsAffiliateProviderException('The HitPath response was not valid XML. No offer was saved.');
        }

        $decoded = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true);
        if (! is_array($decoded)) {
            return [];
        }

        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    /** @param list<string> $wrappers
     * @return list<array<string, mixed>>
     */
    private function rows(array $data, array $wrappers): array
    {
        foreach ($wrappers as $wrapper) {
            if (array_key_exists($wrapper, $data)) {
                $value = $data[$wrapper];
                if (! is_array($value)) {
                    return [];
                }

                return array_is_list($value) ? array_values(array_filter($value, 'is_array')) : [$value];
            }
        }

        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        return array_intersect(['campaignid', 'id', 'fromlines', 'subjects', 'body'], array_keys($data)) !== [] ? [$data] : [];
    }

    /** @param list<string> $wrappers
     * @return array<string, mixed>
     */
    private function record(array $data, array $wrappers): array
    {
        $rows = $this->rows($data, $wrappers);

        return $rows[0] ?? $data;
    }

    /** @return list<string> */
    private function lines(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $value) ?: []), static fn (string $line): bool => $line !== ''));
    }

    /** @return list<string> */
    private function anchorLinks(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            return [];
        }

        $nodes = (new DOMXPath($document))->query('//a[@href]');
        if ($nodes === false) {
            return [];
        }

        $links = [];
        foreach ($nodes as $node) {
            $href = trim($node->attributes?->getNamedItem('href')?->nodeValue ?? '');
            if ($this->safeHttpUrl($href)) {
                $links[] = $href;
            }
        }

        return $links;
    }

    private function sponsorHost(WorkspaceSponsor $sponsor): string
    {
        $url = trim((string) $sponsor->url);
        if (! str_contains($url, '://')) {
            $url = 'https://'.$url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new DmsAffiliateProviderException('The sponsor URL must contain a public provider hostname.');
        }

        return strtolower(rtrim($host, '.'));
    }

    private function safeHttpUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function matchCountries(string $value): array
    {
        preg_match_all('/\b[A-Z]{2}\b/', $value, $matches);
        $countryCodes = array_keys(config('dms_install_countries', []));

        return array_values(array_unique(array_intersect($matches[0] ?? [], $countryCodes)));
    }

    private function formatMoney(string $value): float
    {
        return (float) filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }

    private function matchType(string $value): string
    {
        preg_match('/(CPA|CPC|CPL|CPS|REVSHARE)/i', $value, $match);

        return strtoupper($match[0] ?? 'CPA');
    }

    /** @return list<string> */
    private function matchDays(string $value): array
    {
        $value = strtolower($value);
        if (str_contains($value, 'noweekenddrops')
            || str_contains($value, 'no weekend')
            || str_contains($value, 'monday-friday')
            || str_contains($value, 'mon-fri')
            || str_contains($value, 'mon-friday')) {
            return ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
        }
        if (str_contains($value, 'monday-thursday')
            || str_contains($value, 'mon-thur')
            || str_contains($value, 'mon-thurs')
            || str_contains($value, 'mon-thursday')) {
            return ['Mon', 'Tue', 'Wed', 'Thu'];
        }

        return WorkspaceOffer::ALLOWED_DAYS;
    }

    /** @param list<string> $offerIds
     * @return list<array<string, mixed>>
     */
    private function readEverFlow(WorkspaceSponsor $sponsor, array $offerIds): array
    {
        $offers = [];
        foreach (array_values(array_unique($offerIds)) as $offerId) {
            if (! is_numeric($offerId)) {
                continue;
            }

            $response = $this->http->get(
                'https://api.eflow.team/v1/affiliates/offers/'.(int) $offerId,
                ['x-eflow-api-key' => (string) $sponsor->api_key],
            );
            if (! $response->successful() || blank($response->json('tracking_url'))) {
                continue;
            }

            $offer = $response->json();
            if (! is_array($offer) || ! is_array($offer['relationship'] ?? null)) {
                continue;
            }

            $mapped = $this->mapEverFlowOffer($offer);
            if ($mapped !== null) {
                $offers[] = $mapped;
            }
        }

        return $offers;
    }

    /** @param list<string> $offerIds
     * @return list<array<string, mixed>>
     */
    private function readNewHitPath(WorkspaceSponsor $sponsor, array $offerIds, bool $throwOnProviderFailure = false): array
    {
        $host = $this->sponsorHost($sponsor);
        $apiUrl = sprintf('https://%s/api', $host);
        $headers = $this->newHitPathCreativeImporter->apiHeaders((string) $sponsor->api_key);
        $offers = [];

        foreach (array_values(array_unique($offerIds)) as $offerId) {
            if (! is_numeric($offerId)) {
                continue;
            }

            try {
                $response = $this->http->get($apiUrl.'/campaigns/'.(int) $offerId, $headers);
                if (! $response->successful()) {
                    continue;
                }

                $offer = $response->json('data');
                $redirects = is_array($offer) && is_array($offer['redirect'] ?? null) ? $offer['redirect'] : [];
                $redirect = is_array($redirects[0] ?? null) ? ($redirects[0]['url'] ?? null) : null;
                if (! is_array($offer) || ! is_string($redirect) || trim($redirect) === '') {
                    continue;
                }

                $emailCount = is_numeric($offer['email_count'] ?? null) ? (int) $offer['email_count'] : 0;
                $assets = $emailCount > 0
                    ? $this->readNewHitPathEmailAssets($apiUrl, (int) $offerId, $headers)
                    : ['from' => [], 'subjects' => []];
                $name = is_string($offer['name'] ?? null) ? $offer['name'] : '';
                $description = is_string($offer['description'] ?? null) ? $offer['description'] : '';
                $geo = is_array($offer['geo'] ?? null) && is_string($offer['geo']['from'] ?? null)
                    ? $offer['geo']['from']
                    : '';
                $vertical = $this->newHitPathVertical($name);
                $creatives = $this->newHitPathCreativeImporter->read(
                    $apiUrl,
                    (int) $offerId,
                    (string) $sponsor->api_key,
                    $host,
                );
                $redirect = str_ends_with($redirect, '/') ? $redirect : $redirect.'/';
                $unsubscribe = is_string($offer['optout'] ?? null) ? $offer['optout'] : '';
                $payout = $offer['payout'] ?? 0;

                $offers[] = [
                    'offer_id' => is_string($offer['id'] ?? null) || is_int($offer['id'] ?? null)
                        ? (string) $offer['id']
                        : (string) $offerId,
                    'name' => $name,
                    'vertical' => $vertical,
                    'geo_target' => $this->matchCountries($name.' '.$geo),
                    'price' => is_numeric($payout) ? (float) $payout : 0.0,
                    'type' => $this->matchType($name.' '.$description),
                    'allowed_days' => $this->matchDays($description),
                    'from' => $assets['from'],
                    'subjects' => $assets['subjects'],
                    'creatives' => $creatives,
                    'redirect' => $redirect,
                    'unsubscribe' => $unsubscribe,
                    'note' => $description,
                ];
            } catch (ConnectionException|DmsAffiliateProviderException $exception) {
                if ($throwOnProviderFailure) {
                    throw $exception;
                }

                continue;
            }
        }

        return $offers;
    }

    /** @param array<string, string> $headers
     * @return array{from: list<string>, subjects: list<string>}
     */
    private function readNewHitPathEmailAssets(string $apiUrl, int $offerId, array $headers): array
    {
        $response = $this->http->get(sprintf('%s/campaigns/%d/emails', $apiUrl, $offerId), $headers);
        $emails = $response->successful() ? $response->json('data') : null;
        if (! is_array($emails)) {
            return ['from' => [], 'subjects' => []];
        }

        $from = [];
        $subjects = [];
        foreach ($emails as $email) {
            if (! is_array($email)) {
                continue;
            }

            foreach (is_array($email['from_lines'] ?? null) ? $email['from_lines'] : [] as $line) {
                if (is_string($line)) {
                    $from[] = $line;
                }
            }
            foreach (is_array($email['subjects'] ?? null) ? $email['subjects'] : [] as $line) {
                if (is_string($line)) {
                    $subjects[] = $line;
                }
            }
        }

        return [
            'from' => $this->normalizeNewHitPathLines($from),
            'subjects' => $this->normalizeNewHitPathLines($subjects),
        ];
    }

    /** @param list<string> $lines
     * @return list<string>
     */
    private function normalizeNewHitPathLines(array $lines): array
    {
        $normalized = [];
        foreach (array_unique($lines) as $line) {
            $value = preg_replace('/\s+/', ' ', trim($line)) ?? '';
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    private function newHitPathVertical(string $name): string
    {
        $normalizedName = strtolower(trim($name));
        if (str_contains($normalizedName, '-')) {
            return trim(explode('-', $normalizedName, 2)[0]);
        }
        if (str_contains($normalizedName, ']')) {
            return trim(explode(']', trim($normalizedName, '['), 2)[0]);
        }

        return 'NA';
    }

    /** @param list<string> $offerIds
     * @return list<array<string, mixed>>
     */
    private function readCake(WorkspaceSponsor $sponsor, array $offerIds): array
    {
        $host = $this->sponsorHost($sponsor);
        $endpoint = sprintf('https://%s/affiliates/api/Offers/Campaign', $host);
        $offers = [];

        foreach (array_values(array_unique($offerIds)) as $offerId) {
            if (! is_numeric($offerId)) {
                continue;
            }

            $url = $endpoint.'?'.http_build_query([
                'api_key' => (string) $sponsor->api_key,
                'affiliate_id' => (string) $sponsor->affiliate_id,
                'campaign_id' => (int) $offerId,
            ], '', '&', PHP_QUERY_RFC3986);
            $response = $this->http->get($url);
            if (! $response->successful()) {
                continue;
            }

            $rows = $response->json('data');
            if (! is_array($rows) || $rows === []) {
                continue;
            }

            $offer = current($rows);
            if (! is_array($offer)) {
                continue;
            }

            $mapped = $this->mapCakeOffer($offer);
            if ($mapped !== null) {
                $offers[] = $mapped;
            }
        }

        return $offers;
    }

    /** @param list<string> $offerIds
     * @return list<array<string, mixed>>
     */
    private function readHasOffers(WorkspaceSponsor $sponsor, array $offerIds, bool $throwOnProviderFailure = false): array
    {
        $affiliateId = trim((string) $sponsor->affiliate_id);
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $affiliateId)) {
            throw new DmsAffiliateProviderException('Save the HasOffers affiliate network ID before importing offers.');
        }

        $apiUrl = sprintf('https://%s.api.hasoffers.com/Apiv3/json', strtolower($affiliateId));
        $hostLabels = explode('.', $this->sponsorHost($sponsor));
        $baseDomain = implode('.', array_slice($hostLabels, -2));
        $crmUrl = sprintf('https://crm3-java.%s/api/v1/proxy/offers/', $baseDomain);
        $offers = [];

        foreach (array_values(array_unique($offerIds)) as $offerId) {
            if (! is_numeric($offerId)) {
                continue;
            }

            $id = (int) $offerId;
            try {
                $response = $this->hasOffersRequest($apiUrl, (string) $sponsor->api_key, 'Affiliate_Offer', 'findById', ['id' => $id]);
                if ($response === null || $response->json('response.status') != 1) {
                    continue;
                }

                $offer = $response->json('response.data.Offer');
                if (! is_array($offer)) {
                    continue;
                }

                $categoryResponse = $this->hasOffersRequest($apiUrl, (string) $sponsor->api_key, 'Affiliate_Offer', 'getCategories', ['ids' => [$id]]);
                $categories = $categoryResponse?->json('response.data.0.categories');
                $vertical = 'NA';
                if (is_array($categories) && is_array(reset($categories))) {
                    $categoryName = reset($categories)['name'] ?? null;
                    if (is_string($categoryName) && trim($categoryName) !== '') {
                        $vertical = strtolower($categoryName);
                    }
                }

                $geoResponse = $this->hasOffersRequest($apiUrl, (string) $sponsor->api_key, 'Affiliate_Offer', 'getGeoTargeting', ['id' => $id]);
                $countries = $geoResponse?->json('response.data.Countries');
                $geoTarget = [];
                if (is_array($countries)) {
                    foreach ($countries as $country) {
                        $code = is_array($country) ? ($country['code'] ?? null) : null;
                        if (is_string($code) && trim($code) !== '') {
                            $geoTarget[] = strtolower($code) === 'uk' ? 'GB' : $code;
                        }
                    }
                }
                if ($geoTarget === []) {
                    $geoTarget = ['ALL'];
                }

                $trackingResponse = $this->hasOffersRequest($apiUrl, (string) $sponsor->api_key, 'Affiliate_Offer', 'generateTrackingLink', ['offer_id' => $id]);
                $redirect = $trackingResponse?->json('response.data.click_url', '');
                $redirect = is_string($redirect) && $this->safeHttpUrl($redirect) ? $redirect : '';
                $unsubscribe = is_string($offer['dne_unsubscribe_url'] ?? null) ? $offer['dne_unsubscribe_url'] : '';
                if (! $this->safeHttpUrl($unsubscribe)) {
                    $unsubscribe = '';
                }
                if ($unsubscribe === '') {
                    $crmResponse = $this->http->get($crmUrl.$id.'?'.http_build_query(['apiKey' => (string) $sponsor->api_key], '', '&', PHP_QUERY_RFC3986));
                    if ($crmResponse->successful()) {
                        $candidate = $crmResponse->json('optOutLink', '');
                        $unsubscribe = is_string($candidate) && $this->safeHttpUrl($candidate) ? $candidate : '';
                    }
                }

                $name = is_string($offer['name'] ?? null) ? $offer['name'] : '';
                $description = is_string($offer['description'] ?? null) ? $offer['description'] : '';
                $payout = $offer['default_payout'] ?? 0;
                $from = is_string($offer['email_instructions_from'] ?? null)
                    ? preg_split('/\R/', $offer['email_instructions_from']) ?: []
                    : [];
                $subjects = is_string($offer['email_instructions_subject'] ?? null)
                    ? preg_split('/\R/', $offer['email_instructions_subject']) ?: []
                    : [];

                $offers[] = [
                    'offer_id' => is_string($offer['id'] ?? null) || is_int($offer['id'] ?? null) ? (string) $offer['id'] : (string) $id,
                    'name' => $name,
                    'vertical' => $vertical,
                    'geo_target' => array_values($geoTarget),
                    'price' => is_numeric($payout) ? (float) $payout : 0.0,
                    'type' => $this->matchType($name.' '.$description),
                    'allowed_days' => $this->matchDays($description),
                    'from' => $this->normalizeHasOffersLines(is_array($from) ? $from : []),
                    'subjects' => $this->normalizeHasOffersLines(is_array($subjects) ? $subjects : []),
                    'creatives' => [],
                    'redirect' => $redirect,
                    'unsubscribe' => $unsubscribe,
                    'note' => $description,
                ];
            } catch (ConnectionException|DmsAffiliateProviderException $exception) {
                if ($throwOnProviderFailure) {
                    throw $exception;
                }

                continue;
            }
        }

        return $offers;
    }

    /** @param array<string, mixed> $parameters */
    private function hasOffersRequest(string $apiUrl, string $apiKey, string $target, string $method, array $parameters): ?Response
    {
        $query = http_build_query([
            'api_key' => $apiKey,
            'Target' => $target,
            'Method' => $method,
            ...$parameters,
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $this->http->get($apiUrl.'?'.$query);

        return $response->successful() ? $response : null;
    }

    /** @param list<mixed> $lines
     * @return list<string>
     */
    private function normalizeHasOffersLines(array $lines): array
    {
        $normalized = [];
        foreach ($lines as $line) {
            if (! is_string($line)) {
                continue;
            }

            $value = preg_replace('/\s+/', ' ', trim($line)) ?? '';
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /** @param array<string, mixed> $offer
     * @return array<string, mixed>|null
     */
    private function mapCakeOffer(array $offer): ?array
    {
        $offerId = $offer['campaign_id'] ?? null;
        $name = $offer['offer_name'] ?? null;
        $vertical = $offer['vertical_name'] ?? null;
        if ((! is_string($offerId) && ! is_int($offerId))
            || ! is_numeric($offerId)
            || ! is_string($name)
            || trim($name) === ''
            || ! is_string($vertical)
            || trim($vertical) === '') {
            return null;
        }

        $countries = $offer['allowed_countries'] ?? [];
        $geoTarget = [];
        if (is_array($countries)) {
            foreach ($countries as $country) {
                $countryCode = is_array($country) ? ($country['country_code'] ?? null) : null;
                if (! is_string($countryCode) || trim($countryCode) === '') {
                    continue;
                }

                $geoTarget[] = strtolower($countryCode) === 'uk' ? 'GB' : $countryCode;
            }
        }
        if ($geoTarget === []) {
            $geoTarget = ['US'];
        }

        $creatives = $offer['creatives'] ?? [];
        $firstCreative = is_array($creatives) ? (reset($creatives) ?: []) : [];
        $redirect = is_array($firstCreative) ? ($firstCreative['unique_link'] ?? '') : '';
        $unsubscribe = $offer['unsubscribe_link'] ?? '';
        $restrictions = $offer['restrictions'] ?? '';
        $from = $offer['from_lines'] ?? [];
        $subjects = $offer['subject_lines'] ?? [];
        $price = $offer['price'] ?? 0;
        $priceFormat = $offer['price_format'] ?? '';
        $creativeBundleUrl = $offer['creatives_download_url'] ?? '';

        return [
            'offer_id' => (string) $offerId,
            'name' => trim($name),
            'vertical' => trim(strtolower($vertical)),
            'geo_target' => array_values(array_unique($geoTarget)),
            'price' => is_numeric($price) ? (float) $price : 0.0,
            'type' => $this->matchType(is_string($priceFormat) ? $priceFormat : ''),
            'allowed_days' => $this->matchDays($name),
            'from' => $this->normalizeLines(is_array($from) ? $from : []),
            'subjects' => $this->normalizeLines(is_array($subjects) ? $subjects : []),
            'redirect' => is_string($redirect) ? $redirect : '',
            'unsubscribe' => is_string($unsubscribe) ? $unsubscribe : '',
            'note' => is_string($restrictions) ? $restrictions : '',
            'creative_bundle_url' => is_string($creativeBundleUrl) ? $creativeBundleUrl : '',
        ];
    }

    /** @param array<string, mixed> $offer
     * @return array<string, mixed>|null
     */
    private function mapEverFlowOffer(array $offer): ?array
    {
        $relationship = $offer['relationship'];
        $countries = data_get($relationship, 'ruleset.countries', []);
        if (! is_array($countries)) {
            return null;
        }

        $geoTarget = [];
        foreach ($countries as $country) {
            $countryCode = is_array($country) ? ($country['country_code'] ?? null) : null;
            if (is_string($countryCode) && $countryCode !== '') {
                $geoTarget[] = strtolower($countryCode) === 'uk' ? 'GB' : $countryCode;
            }
        }

        $from = $this->normalizeLines(explode("\n", (string) data_get($relationship, 'email.from_lines', '')));
        $subjects = $this->normalizeLines(explode("\n", (string) data_get($relationship, 'email.subject_lines', '')));
        $payout = data_get($relationship, 'payouts.entries.0', []);
        $payout = is_array($payout) ? $payout : [];
        $name = is_string($offer['name'] ?? null) ? $offer['name'] : '';
        $description = is_string($offer['html_description'] ?? null) ? $offer['html_description'] : '';
        $offerId = $offer['network_offer_id'] ?? null;
        $redirect = $offer['tracking_url'] ?? null;
        $vertical = data_get($relationship, 'category.name');

        if ((! is_string($offerId) && ! is_int($offerId))
            || $name === ''
            || ! is_string($redirect)
            || ! $this->safeHttpUrl($redirect)
            || ! is_string($vertical)
            || trim($vertical) === '') {
            return null;
        }

        $unsubscribe = data_get($relationship, 'email_optout.unsub_link', '');
        if (! is_string($unsubscribe) || $unsubscribe === '') {
            $unsubscribe = data_get($relationship, 'integrations.optizmo.optout_link', '');
        }

        $payoutType = is_string($payout['payout_type'] ?? null) ? $payout['payout_type'] : '';
        preg_match('/(CPA|CPC|CPL|CPS|REVSHARE)/i', $payoutType, $typeMatch);
        $creativeBundleUrl = data_get($relationship, 'creative_bundle.url');

        return [
            'offer_id' => (string) $offerId,
            'name' => $name,
            'vertical' => trim(strtolower($vertical)),
            'geo_target' => array_values(array_unique($geoTarget)),
            'price' => is_numeric($payout['payout_amount'] ?? null) ? (float) $payout['payout_amount'] : 0.0,
            'type' => strtoupper($typeMatch[0] ?? 'CPA'),
            'allowed_days' => $this->matchDays($name),
            'from' => $from,
            'subjects' => $subjects,
            'redirect' => $redirect,
            'unsubscribe' => is_string($unsubscribe) && $this->safeHttpUrl($unsubscribe) ? $unsubscribe : '',
            'note' => preg_replace('!\s+!', ' ', trim(strip_tags($description))) ?? '',
            'creative_bundle_url' => is_string($creativeBundleUrl) ? $creativeBundleUrl : '',
        ];
    }

    /** @param list<string> $lines
     * @return list<string>
     */
    private function normalizeLines(array $lines): array
    {
        $normalized = [];
        foreach ($lines as $line) {
            if (is_string($line)) {
                $normalized[] = preg_replace('/\s+/', ' ', trim($line)) ?? '';
            }
        }

        return array_values(array_unique(array_filter($normalized, static fn (string $line): bool => $line !== '')));
    }
}
