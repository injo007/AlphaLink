<?php

namespace App\Services\AffiliateNetworks;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class DmsAffiliateProviderHttp
{
    /**
     * @param  (Closure(string): list<string>)|null  $addressResolver
     */
    public function __construct(private readonly ?Closure $addressResolver = null) {}

    /**
     * @param  array<string, scalar|null>  $data
     * @param  array<string, string>  $headers
     */
    public function postForm(string $url, array $data, array $headers = []): Response
    {
        return $this->pendingRequest($url)->withHeaders($headers)->asForm()->post($url, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function postJson(string $url, array $data, array $headers = []): Response
    {
        return $this->pendingRequest($url)->withHeaders($headers)->asJson()->post($url, $data);
    }

    /** @param array<string, string> $headers */
    public function get(string $url, array $headers = []): Response
    {
        return $this->pendingRequest($url)->withHeaders($headers)->get($url);
    }

    /** @param array<string, string> $headers */
    public function getBytes(string $url, array $headers = [], int $maximumBytes = 2_097_152): ?string
    {
        try {
            $response = $this->pendingRequest($url)
                ->withHeaders($headers)
                ->get($url);
            $contentLength = $response->header('Content-Length');
            if (! $response->successful() || (is_numeric($contentLength) && (int) $contentLength > $maximumBytes)) {
                return null;
            }

            return $this->boundedBody($response, $maximumBytes);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Download a bounded response while revalidating and pinning every public HTTPS redirect target.
     * Authorization headers are discarded when a redirect changes origin.
     *
     * @param  array<string, string>  $headers
     */
    public function getBytesFollowingPublicRedirects(string $url, array $headers = [], int $maximumBytes = 10_000_000): ?string
    {
        $initial = parse_url($url);
        if (! is_array($initial)) {
            return null;
        }

        $initialOrigin = strtolower((string) ($initial['host'] ?? '')).':'.(int) ($initial['port'] ?? 443);
        $currentUrl = $url;
        $currentHeaders = $headers;

        try {
            for ($redirect = 0; $redirect <= 4; $redirect++) {
                $response = $this->pendingRequest($currentUrl)
                    ->withHeaders($currentHeaders)
                    ->get($currentUrl);

                if ($response->status() >= 300 && $response->status() < 400) {
                    $location = $response->header('Location');
                    if (! is_string($location) || trim($location) === '' || $redirect === 4) {
                        return null;
                    }

                    $nextUrl = $this->resolveRedirectUrl($currentUrl, $location);
                    if ($nextUrl === null) {
                        return null;
                    }

                    $next = parse_url($nextUrl);
                    $nextOrigin = strtolower((string) ($next['host'] ?? '')).':'.(int) ($next['port'] ?? 443);
                    if ($nextOrigin !== $initialOrigin) {
                        $currentHeaders = array_filter(
                            $currentHeaders,
                            static fn (string $name): bool => ! in_array(strtolower($name), ['authorization', 'cookie', 'proxy-authorization'], true),
                            ARRAY_FILTER_USE_KEY,
                        );
                    }
                    $currentUrl = $nextUrl;

                    continue;
                }

                $contentLength = $response->header('Content-Length');
                if (! $response->successful() || (is_numeric($contentLength) && (int) $contentLength > $maximumBytes)) {
                    return null;
                }

                return $this->boundedBody($response, $maximumBytes);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Stream a response to a private temporary file while reporting progress.
     * Each redirect is validated and DNS-pinned independently; credentials are removed
     * whenever the origin changes.
     *
     * @param  array<string, string>  $headers
     * @param  Closure(int, ?int): void|null  $onProgress
     */
    public function downloadToFileFollowingPublicRedirects(
        string $url,
        string $destination,
        array $headers = [],
        ?Closure $onProgress = null,
    ): ?int {
        $initial = parse_url($url);
        if (! is_array($initial)) {
            return null;
        }

        $initialOrigin = strtolower((string) ($initial['host'] ?? '')).':'.(int) ($initial['port'] ?? 443);
        $currentUrl = $url;
        $currentHeaders = $headers;
        @unlink($destination);

        try {
            for ($redirect = 0; $redirect <= 4; $redirect++) {
                $request = $this->pendingRequest($currentUrl, timeoutSeconds: 0)
                    ->withHeaders($currentHeaders)
                    ->withOptions([
                        'sink' => $destination,
                        'progress' => static function (float $downloadTotal, float $downloaded) use ($onProgress): void {
                            if ($onProgress !== null) {
                                $onProgress((int) $downloaded, $downloadTotal > 0 ? (int) $downloadTotal : null);
                            }
                        },
                    ]);
                $response = $request->get($currentUrl);

                if ($response->status() >= 300 && $response->status() < 400) {
                    $location = $response->header('Location');
                    if (! is_string($location) || trim($location) === '' || $redirect === 4) {
                        return null;
                    }

                    $nextUrl = $this->resolveRedirectUrl($currentUrl, $location);
                    if ($nextUrl === null) {
                        return null;
                    }
                    $next = parse_url($nextUrl);
                    $nextOrigin = strtolower((string) ($next['host'] ?? '')).':'.(int) ($next['port'] ?? 443);
                    if ($nextOrigin !== $initialOrigin) {
                        $currentHeaders = array_filter(
                            $currentHeaders,
                            static fn (string $name): bool => ! in_array(strtolower($name), ['authorization', 'cookie', 'proxy-authorization'], true),
                            ARRAY_FILTER_USE_KEY,
                        );
                    }
                    $currentUrl = $nextUrl;
                    @unlink($destination);

                    continue;
                }

                clearstatcache(true, $destination);
                $bytes = is_file($destination) ? filesize($destination) : false;
                $contentLength = $response->header('Content-Length');
                if (! $response->successful() || ! is_int($bytes) || $bytes < 1) {
                    return null;
                }

                if ($onProgress !== null) {
                    $onProgress($bytes, is_numeric($contentLength) ? (int) $contentLength : $bytes);
                }

                return $bytes;
            }
        } catch (DmsAffiliateProviderException $exception) {
            @unlink($destination);

            throw $exception;
        } catch (Throwable $exception) {
            @unlink($destination);

            throw new DmsAffiliateSuppressionDownloadException(
                'The sponsor returned a download link, but AlphaLink could not finish saving the file. Check free space in app storage and outbound HTTPS from the app server, then retry.',
                previous: $exception,
            );
        }

        @unlink($destination);

        return null;
    }

    private function boundedBody(Response $response, int $maximumBytes): ?string
    {
        $body = $response->body();

        return $body !== '' && strlen($body) <= $maximumBytes ? $body : null;
    }

    private function resolveRedirectUrl(string $currentUrl, string $location): ?string
    {
        $location = trim($location);
        if (filter_var($location, FILTER_VALIDATE_URL) !== false) {
            return $location;
        }

        $current = parse_url($currentUrl);
        if (! is_array($current) || empty($current['host'])) {
            return null;
        }

        $origin = 'https://'.$current['host'].(isset($current['port']) ? ':'.$current['port'] : '');
        if (str_starts_with($location, '//')) {
            return 'https:'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = (string) ($current['path'] ?? '/');
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin.$directory.$location;
    }

    private function pendingRequest(string $url, int $timeoutSeconds = 25): PendingRequest
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower(rtrim((string) ($parts['host'] ?? ''), '.')) : '';
        $port = is_array($parts) ? ($parts['port'] ?? 443) : null;

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || $port !== 443
            || filter_var($host, FILTER_VALIDATE_IP)
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new DmsAffiliateProviderException('The configured affiliate provider endpoint is not a valid public HTTPS host.');
        }

        $addresses = $this->addressResolver !== null
            ? ($this->addressResolver)($host)
            : $this->resolveAddresses($host);
        $addresses = array_values(array_unique(array_filter($addresses, 'is_string')));

        // A public host may return mixed A/AAAA answers. Pin to a public answer
        // instead of rejecting the entire host because one unusable address was
        // included; this still prevents private/link-local SSRF destinations.
        $addresses = array_values(array_filter(
            $addresses,
            static fn (string $address): bool => filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false,
        ));

        if ($addresses === []) {
            throw new DmsAffiliateProviderException('The affiliate provider host did not resolve to a public address.');
        }

        usort($addresses, static fn (string $left, string $right): int => (int) (filter_var($right, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
            <=> (int) (filter_var($left, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
        );

        return Http::timeout($timeoutSeconds)
            ->connectTimeout(7)
            ->withoutRedirecting()
            ->withOptions(['resolve' => [$host.':443' => $addresses[0]]]);
    }

    /** @return list<string> */
    private function resolveAddresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
