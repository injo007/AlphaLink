<?php

namespace App\Services\AffiliateNetworks;

use App\Models\WorkspaceSponsor;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class DmsSponsorLoginSession
{
    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/97.0.4692.99 Safari/537.36 Edg/97.0.1072.69';

    private const MAX_REDIRECTS = 5;

    private const MAX_RESPONSE_BYTES = 2_097_152;

    public function __construct(private readonly DmsAffiliateProviderHttp $http) {}

    /** @return array<string, mixed> */
    public function createSession(WorkspaceSponsor $sponsor): array
    {
        $type = strtolower((string) $sponsor->type);
        if ($type === 'hitpath') {
            return $this->failed('HITPATH DEPRECATED');
        }
        if ($type === 'everflow') {
            return $this->failed('EverFlow login requires the browser automation used by DMS, which is not installed in AlphaLink.');
        }

        $flow = match ($type) {
            'cake' => [
                'path' => '/',
                'button' => 'Log In',
                'fields' => ['u' => $sponsor->username, 'p' => $sponsor->password],
                'success' => 'affiliate',
                'url' => '/affiliates/',
            ],
            'hasoffers' => [
                'path' => '/',
                'button' => 'Login',
                'fields' => ['data[User][email]' => $sponsor->username, 'data[User][password]' => $sponsor->password],
                'success' => 'publisher',
                'url' => '/',
            ],
            'newhitpath' => [
                'path' => '/login',
                'button' => 'Login',
                'fields' => ['login' => $sponsor->username, 'password' => $sponsor->password],
                'success' => 'login_absent',
                'url' => '/',
            ],
            default => null,
        };
        if ($flow === null) {
            return $this->failed('Unsupported sponsor type: '.$type);
        }

        if (blank($sponsor->api_key)) {
            return $this->failed('Sponsor api key is not set');
        }
        if (blank($sponsor->url)) {
            return $this->failed('SPONSOR URL IS EMPTY');
        }
        if (blank($sponsor->username) || blank($sponsor->password)) {
            return $this->failed('Sponsor login credentials are incomplete.');
        }
        if ($type === 'cake' && blank($sponsor->affiliate_id)) {
            return $this->failed('SPONSOR AFFILIATE ID NOT SET');
        }
        if ($type === 'hasoffers' && blank($sponsor->affiliate_id)) {
            return $this->failed('SPONSOR AFFILIATE ID IS EMPTY');
        }

        $cacheKey = $this->cacheKey($sponsor);
        if ($type === 'cake' && ($cached = $this->cachedSession($cacheKey)) !== null) {
            return $cached;
        }

        try {
            $origin = $this->originFor($sponsor->url);
            $startUrl = $origin.$flow['path'];
            $cookies = [];
            [$page, $pageUrl] = $this->requestFollowingRedirects('GET', $startUrl, [], $origin, $cookies);
            $this->assertResponseSize($page);

            $form = $this->loginForm($page->body(), $pageUrl, $flow['button']);
            $form['data'] = array_replace($form['data'], $flow['fields']);
            $method = $form['method'];
            $action = $form['action'];
            $headers = ['User-Agent' => self::USER_AGENT];
            [$result, $resultUrl] = $this->requestFollowingRedirects(
                $method,
                $action,
                $form['data'],
                $origin,
                $cookies,
                $headers,
            );
            $this->assertResponseSize($result);
            if (! $result->successful() || ! $this->loginSucceeded($flow['success'], $resultUrl)) {
                return $this->failed('Invalid username or password');
            }

            $session = array_values($cookies);
            if ($type === 'newhitpath') {
                $session = array_map(fn (array $cookie): array => $cookie + [
                    'date' => $cookie['expires_at'] ?? null,
                ], $session);
            }
            foreach ($session as &$cookie) {
                unset($cookie['expires_at']);
            }
            unset($cookie);

            $result = [
                'status' => true,
                'message' => 'Session Created',
                'session' => $session,
                'url' => $origin.$flow['url'],
                'redirect_to' => '',
            ];
            if ($type === 'cake') {
                $this->cacheSession($cacheKey, $result);
            }

            return $result;
        } catch (DmsAffiliateProviderException $exception) {
            return $this->failed($exception->getMessage());
        } catch (ConnectionException) {
            return $this->failed('Could not connect to the affiliate provider over verified HTTPS.');
        } catch (Throwable) {
            return $this->failed('Affiliate login could not be completed. Check the sponsor settings and provider login page.');
        }
    }

    private function originFor(string $url): string
    {
        $parts = parse_url(str_contains($url, '://') ? $url : 'https://'.$url);
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (! is_array($parts)
            || $host === ''
            || filter_var($host, FILTER_VALIDATE_IP)
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new DmsAffiliateProviderException('The configured affiliate provider endpoint is not a valid public HTTPS host.');
        }

        return 'https://'.$host;
    }

    /** @param array<string, scalar|null> $data
     * @param  array<string, array{name: string, value: string, domain: string, path: string, httpOnly: bool, secure: bool, expires_at?: int}>  $cookies
     * @param  array<string, string>  $headers
     * @return array{0: Response, 1: string}
     */
    private function requestFollowingRedirects(
        string $method,
        string $url,
        array $data,
        string $origin,
        array &$cookies,
        array $headers = [],
    ): array {
        $method = strtoupper($method);
        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $url = $this->sameOriginUrl($url, $origin);
            $requestHeaders = ['User-Agent' => self::USER_AGENT, ...$headers];
            $cookieHeader = $this->cookieHeader($url, $cookies);
            if ($cookieHeader !== '') {
                $requestHeaders['Cookie'] = $cookieHeader;
            }

            $response = match ($method) {
                'GET' => $this->http->get($this->appendQuery($url, $data), $requestHeaders),
                'POST' => $this->http->postForm($url, $data, $requestHeaders),
                default => throw new DmsAffiliateProviderException('The provider login form uses an unsupported submission method.'),
            };
            $this->saveResponseCookies($response, $url, $origin, $cookies);

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return [$response, $url];
            }
            if ($redirects === self::MAX_REDIRECTS) {
                throw new DmsAffiliateProviderException('The affiliate provider returned too many login redirects.');
            }

            $location = $response->header('Location');
            if (! is_string($location) || trim($location) === '') {
                throw new DmsAffiliateProviderException('The affiliate provider returned an invalid login redirect.');
            }
            $url = $this->resolveUrl($url, $location, $origin);
            if (in_array($response->status(), [301, 302, 303], true)) {
                $method = 'GET';
                $data = [];
            }
        }

        throw new DmsAffiliateProviderException('The affiliate provider login could not be completed.');
    }

    /** @return array{method: string, action: string, data: array<string, scalar|null>} */
    private function loginForm(string $html, string $currentUrl, string $buttonLabel): array
    {
        $previousErrorState = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
            $xpath = new DOMXPath($document);
            foreach ($xpath->query('//form') ?: [] as $form) {
                if (! $form instanceof DOMElement) {
                    continue;
                }
                $submit = $this->matchingSubmitControl($xpath, $form, $buttonLabel);
                if ($submit === null) {
                    continue;
                }

                $data = [];
                foreach ($xpath->query('.//input|.//textarea|.//select', $form) ?: [] as $control) {
                    if (! $control instanceof DOMElement || $control->hasAttribute('disabled')) {
                        continue;
                    }
                    $name = trim($control->getAttribute('name'));
                    if ($name === '') {
                        continue;
                    }
                    $tag = strtolower($control->tagName);
                    $type = strtolower($control->getAttribute('type'));
                    if ($tag === 'input' && in_array($type, ['submit', 'button', 'image', 'reset', 'file'], true)) {
                        continue;
                    }
                    if ($tag === 'input' && in_array($type, ['checkbox', 'radio'], true) && ! $control->hasAttribute('checked')) {
                        continue;
                    }
                    $value = match ($tag) {
                        'textarea' => $control->textContent,
                        'select' => $this->selectedOptionValue($xpath, $control),
                        default => $control->getAttribute('value'),
                    };
                    $data[$name] = $value;
                }

                if ($submit->hasAttribute('name')) {
                    $data[$submit->getAttribute('name')] = $submit->getAttribute('value');
                }

                return [
                    'method' => strtoupper($form->getAttribute('method') ?: 'GET'),
                    'action' => $this->resolveUrl($currentUrl, $form->getAttribute('action') ?: $currentUrl, $this->originFor($currentUrl)),
                    'data' => $data,
                ];
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorState);
        }

        throw new DmsAffiliateProviderException('The provider login form could not be found.');
    }

    private function matchingSubmitControl(DOMXPath $xpath, DOMElement $form, string $buttonLabel): ?DOMElement
    {
        foreach ($xpath->query('.//button|.//input[@type="submit"]', $form) ?: [] as $control) {
            if (! $control instanceof DOMElement) {
                continue;
            }
            $label = trim($control->textContent ?: $control->getAttribute('value'));
            if (mb_strtolower($label) === mb_strtolower($buttonLabel)) {
                return $control;
            }
        }

        return null;
    }

    private function selectedOptionValue(DOMXPath $xpath, DOMElement $select): string
    {
        foreach ($xpath->query('.//option', $select) ?: [] as $option) {
            if ($option instanceof DOMElement && $option->hasAttribute('selected')) {
                return $option->getAttribute('value') ?: trim($option->textContent);
            }
        }
        $first = $xpath->query('.//option[1]', $select)?->item(0);

        return $first instanceof DOMElement ? ($first->getAttribute('value') ?: trim($first->textContent)) : '';
    }

    private function sameOriginUrl(string $url, string $origin): string
    {
        $parts = parse_url($url);
        $originParts = parse_url($origin);
        if (! is_array($parts)
            || ! is_array($originParts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) $originParts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new DmsAffiliateProviderException('Affiliate login redirects must stay on the configured HTTPS provider host.');
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $origin.$path.$query;
    }

    private function resolveUrl(string $baseUrl, string $target, string $origin): string
    {
        if (str_starts_with($target, '//')) {
            $target = 'https:'.$target;
        } elseif (! preg_match('#^https?://#i', $target)) {
            $base = parse_url($baseUrl);
            if (str_starts_with($target, '/')) {
                $target = $origin.$target;
            } elseif (str_starts_with($target, '?')) {
                $target = $origin.($base['path'] ?? '/').$target;
            } else {
                $directory = rtrim(dirname($base['path'] ?? '/'), '/');
                $target = $origin.($directory === '' ? '' : $directory).'/'.$target;
            }
        }

        return $this->sameOriginUrl($target, $origin);
    }

    /** @param array<string, array{name: string, value: string, domain: string, path: string, httpOnly: bool, secure: bool, expires_at?: int}> $cookies */
    private function saveResponseCookies(Response $response, string $url, string $origin, array &$cookies): void
    {
        $host = (string) parse_url($origin, PHP_URL_HOST);
        $headers = $response->toPsrResponse()->getHeader('Set-Cookie');
        foreach ($headers as $header) {
            $segments = explode(';', $header);
            $pair = array_shift($segments);
            if (! is_string($pair) || ! str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2);
            $name = trim($name);
            if ($name === '' || preg_match('~[\x00-\x20\x7f(),/:;<=>?@\[\]{}]~', $name)) {
                continue;
            }

            $attributes = [];
            foreach ($segments as $segment) {
                [$key, $attributeValue] = array_pad(explode('=', trim($segment), 2), 2, null);
                $attributes[mb_strtolower($key)] = $attributeValue;
            }
            $domain = strtolower(ltrim((string) ($attributes['domain'] ?? $host), '.'));
            if ($domain !== $host && ! str_ends_with($host, '.'.$domain)) {
                continue;
            }
            $path = (string) ($attributes['path'] ?? '/');
            if (! str_starts_with($path, '/')) {
                $path = '/';
            }
            $expiresAt = isset($attributes['max-age']) && is_numeric($attributes['max-age'])
                ? now()->timestamp + max(0, (int) $attributes['max-age'])
                : (isset($attributes['expires']) ? strtotime((string) $attributes['expires']) : null);
            if ($expiresAt !== null && $expiresAt <= now()->timestamp) {
                unset($cookies[$this->cookieKey($name, $domain, $path)]);

                continue;
            }

            $cookie = [
                'name' => $name,
                'value' => trim($value),
                'domain' => $domain,
                'path' => $path,
                'httpOnly' => array_key_exists('httponly', $attributes),
                'secure' => array_key_exists('secure', $attributes),
            ];
            if ($expiresAt !== null) {
                $cookie['expires_at'] = (int) $expiresAt;
            }
            $cookies[$this->cookieKey($name, $domain, $path)] = $cookie;
        }
    }

    /** @param array<string, array{name: string, value: string, domain: string, path: string, httpOnly: bool, secure: bool, expires_at?: int}> $cookies */
    private function cookieHeader(string $url, array $cookies): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $values = [];
        foreach ($cookies as $cookie) {
            $domainMatches = $host === $cookie['domain'] || str_ends_with($host, '.'.$cookie['domain']);
            $pathMatches = $path === $cookie['path'] || str_starts_with($path, rtrim($cookie['path'], '/').'/');
            if ($domainMatches && $pathMatches && (! isset($cookie['expires_at']) || $cookie['expires_at'] > now()->timestamp)) {
                $values[] = $cookie['name'].'='.$cookie['value'];
            }
        }

        return implode('; ', $values);
    }

    private function cookieKey(string $name, string $domain, string $path): string
    {
        return $name.'|'.$domain.'|'.$path;
    }

    /** @param array<string, scalar|null> $data */
    private function appendQuery(string $url, array $data): string
    {
        if ($data === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($data);
    }

    private function assertResponseSize(Response $response): void
    {
        $length = $response->header('Content-Length');
        if ((is_numeric($length) && (int) $length > self::MAX_RESPONSE_BYTES) || strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
            throw new DmsAffiliateProviderException('The affiliate provider login page exceeded the supported response size.');
        }
    }

    private function loginSucceeded(string $rule, string $url): bool
    {
        $lowercaseUrl = mb_strtolower($url);

        return match ($rule) {
            'affiliate' => str_contains($lowercaseUrl, 'affiliate'),
            'publisher' => str_contains($lowercaseUrl, 'publisher'),
            'login_absent' => ! str_contains($lowercaseUrl, 'login'),
            default => false,
        };
    }

    /** @return array<string, mixed> */
    private function failed(string $message): array
    {
        return ['status' => false, 'message' => $message];
    }

    private function cacheKey(WorkspaceSponsor $sponsor): string
    {
        return 'sponsor_session:'.$sponsor->workspace_id.':'.$sponsor->getKey();
    }

    /** @return array<string, mixed>|null */
    private function cachedSession(string $cacheKey): ?array
    {
        $encrypted = Cache::get($cacheKey);
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $session = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($session) && ($session['status'] ?? false) === true && is_array($session['session'] ?? null)) {
                return $session;
            }
        } catch (Throwable) {
            Cache::forget($cacheKey);
        }

        return null;
    }

    /** @param array<string, mixed> $session */
    private function cacheSession(string $cacheKey, array $session): void
    {
        Cache::put(
            $cacheKey,
            Crypt::encryptString(json_encode($session, JSON_THROW_ON_ERROR)),
            now()->addHours(3),
        );
    }
}
