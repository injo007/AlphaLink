<?php

declare(strict_types=1);

/** Handles AlphaLink's public tracking routes without a framework dependency. */
final class AlphaLinkTrackerRequestHandler
{
    public const UNKNOWN_REDIRECT = 'https://www.google.com';

    private Closure $postJson;

    /** @param array{api_url: string, ingest_key: string, application_name: string} $config */
    public function __construct(private readonly array $config, callable $postJson)
    {
        $this->postJson = Closure::fromCallable($postJson);
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(
        string $method,
        string $requestUri,
        string $remoteAddress,
        string $userAgent,
        string $referer = '',
    ): array {
        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';

        if ($path === '/healthz') {
            return $this->response(200, 'text/plain; charset=utf-8', 'ALPHALINK_TRACKING_READY');
        }

        if ($path === '/') {
            $appName = htmlspecialchars((string) ($this->config['application_name'] ?? 'AlphaLink'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$appName} Network Endpoint</title>
    <style>
        :root { --bg: #0b0f19; --card: #111827; --border: #1f2937; --text: #f3f4f6; --muted: #9ca3af; --accent: #6366f1; }
        @media (prefers-color-scheme: light) { :root { --bg: #f8fafc; --card: #ffffff; --border: #e2e8f0; --text: #0f172a; --muted: #64748b; --accent: #4f46e5; } }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 1rem; max-width: 480px; width: 100%; padding: 2.5rem 2rem; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); text-align: center; }
        .badge { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.35rem 0.85rem; border-radius: 9999px; font-size: 0.8rem; font-weight: 600; background: rgba(99,102,241,0.12); color: var(--accent); margin-bottom: 1.25rem; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: #10b981; }
        h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.75rem; letter-spacing: -0.02em; }
        p { color: var(--muted); font-size: 0.925rem; line-height: 1.6; margin-bottom: 1.5rem; }
        .footer { font-size: 0.75rem; color: var(--muted); border-top: 1px solid var(--border); padding-top: 1rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge"><span class="dot"></span> Active &amp; Verified</div>
        <h1>{$appName} Secure Edge</h1>
        <p>This domain serves verified delivery and engagement telemetry endpoints. High-availability routing is operating normally.</p>
        <div class="footer">&copy; 2026 {$appName}. All rights reserved.</div>
    </div>
</body>
</html>
HTML;
            return $this->response(200, 'text/html; charset=utf-8', $html);
        }

        $route = $this->parseRoute($path);
        if ($route === null) {
            return $this->redirect(self::UNKNOWN_REDIRECT);
        }

        if ($route['action'] === 'o') {
            $this->recordEvent($route['token'], 'open', $remoteAddress, $userAgent, $referer);

            return $this->pixel($method);
        }

        if ($route['action'] === 'b') {
            $this->recordEvent($route['token'], 'bot', $remoteAddress, $userAgent, $referer);

            return $this->response(204, 'text/plain; charset=utf-8', '');
        }

        $token = $this->decodeToken($route['token']);
        if ($token === null) {
            return $this->redirect(self::UNKNOWN_REDIRECT);
        }

        $action = $route['action'] === 'u' ? 'unsubscribe' : 'click';
        $redirect = $this->lookupDestination($token, $route['action'] === 'u' ? 'unsub' : 'red', $remoteAddress, $userAgent);

        if ($redirect === null) {
            return $this->redirect(self::UNKNOWN_REDIRECT);
        }

        $this->recordEvent($route['token'], $action, $remoteAddress, $userAgent, $referer, $token);

        if ($route['action'] === 'u' && ! $this->isAbsoluteHttpUrl($redirect)) {
            $message = $token[2] === '0'
                ? 'This test link has no recipient and cannot unsubscribe anyone. The selected offer has no external unsubscribe URL configured.'
                : 'Your unsubscribe request was submitted to AlphaLink. This offer has no external unsubscribe URL configured.';

            return $this->response(200, 'text/html; charset=utf-8', '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Unsubscribe</title><body style="font:16px system-ui;max-width:36rem;margin:10vh auto;padding:1rem"><h1>Unsubscribe</h1><p>'.htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p></body></html>');
        }

        return $this->redirect($redirect);
    }

    /** @return array{action: string, token: string}|null */
    private function parseRoute(string $path): ?array
    {
        $segments = explode('/', trim($path, '/'));
        $first = array_shift($segments);
        if (! is_string($first) || preg_match('/\A[A-Za-z0-9]{10}([roub])([A-Za-z0-9_-]+)\z/i', $first, $matches) !== 1) {
            return null;
        }

        foreach ($segments as $segment) {
            if ($segment === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $segment) !== 1) {
                return null;
            }
        }

        $token = $matches[2].($segments === [] ? '' : '/'.implode('/', $segments));

        return ['action' => strtolower($matches[1]), 'token' => $token];
    }

    /** @return list<string>|null */
    private function decodeToken(string $encoded): ?array
    {
        $encoded = str_replace('/', '', $encoded);
        if ($encoded === '' || strlen($encoded) > 4096 || preg_match('/\A[A-Za-z0-9_-]+\z/', $encoded) !== 1) {
            return null;
        }

        $base64 = strtr($encoded, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $decoded = base64_decode($base64, true);
        if (! is_string($decoded)) {
            return null;
        }

        $fields = explode('-', $decoded);
        if (count($fields) !== 14
            || ! preg_match('/\A[A-Za-z0-9]{9,13}_[a-f0-9]{8}\z/i', $fields[12])
            || ! preg_match('/\A[a-f0-9]{8}\z/i', $fields[13])) {
            return null;
        }

        $applicationHash = substr($fields[12], -8);
        if (! hash_equals(strtolower(hash('adler32', (string) ($this->config['application_name'] ?? 'AlphaLink'))), strtolower($applicationHash))) {
            return null;
        }

        $expectedChecksum = hash('adler32', implode('_', array_slice($fields, 0, 3)));
        if (! hash_equals(strtolower($expectedChecksum), strtolower($fields[13]))) {
            return null;
        }

        return $fields;
    }

    /** @param list<string> $fields */
    private function lookupDestination(array $fields, string $type, string $remoteAddress, string $userAgent): ?string
    {
        if ($this->isDropToken($fields)) {
            $response = ($this->postJson)('/api/v1/drop', [
                'key' => (string) $this->config['ingest_key'],
                'drop_id' => (int) $fields[0],
                'email_id' => $this->uuidOrZero($fields[2]),
                'isp_id' => $this->positiveInteger($fields[5]),
                'token' => $fields[13],
                'ip_address' => $this->requestIp($remoteAddress),
                'user_agent' => $this->trim($userAgent, 4096),
                'type' => $type,
            ]);
        } else {
            $response = ($this->postJson)('/api/v1/campaign', [
                'key' => (string) $this->config['ingest_key'],
                'campaign_id' => $this->uuid($fields[0]),
                'send_id' => $this->uuidOrZero($fields[1]),
                'email_id' => $this->uuidOrZero($fields[2]),
                'isp_id' => $this->positiveInteger($fields[5]),
                'token' => $fields[13],
                'ip_address' => $this->requestIp($remoteAddress),
                'user_agent' => $this->trim($userAgent, 4096),
                'type' => $type,
            ]);
        }

        if (! is_array($response) || ($response['status'] ?? 500) < 200 || ($response['status'] ?? 500) >= 300) {
            return null;
        }

        $body = $response['body'] ?? null;
        $destination = is_array($body) ? ($body['link'] ?? null) : null;

        if (! is_string($destination)) {
            return null;
        }

        // Unsubscribe destinations must be absolute HTTP(S) URLs. Route the API's
        // legacy relative sentinel and any other unsafe/malformed values to the
        // explicit fallback page instead of the generic unknown-link redirect.
        if ($type === 'unsub') {
            return $this->isAbsoluteHttpUrl($destination) ? $destination : 'unsubscribe?u=1';
        }

        return $this->isAbsoluteHttpUrl($destination) ? $destination : null;
    }

    /** @param list<string>|null $fields */
    private function recordEvent(
        string $encodedToken,
        string $action,
        string $remoteAddress,
        string $userAgent,
        string $referer,
        ?array $fields = null,
    ): void {
        $fields ??= $this->decodeToken($encodedToken);
        if ($fields === null || $fields[2] === '0') {
            return;
        }

        $payload = [
            'key' => (string) $this->config['ingest_key'],
            'action' => $action,
            'ip_address' => $this->requestIp($remoteAddress),
            'user_agent' => $this->trim($userAgent, 4096),
            'type' => $fields[3],
            'list_id' => $this->uuidOrZero($fields[4]),
            'isp_id' => $this->positiveInteger($fields[5]),
            'server_id' => $this->uuidOrZero($fields[6]),
            'ip_id' => $this->uuidOrZero($fields[7]),
            'domain_id' => $this->uuidOrZero($fields[8]),
            'from_id' => $fields[9],
            'subject_id' => $fields[10],
            'creative_id' => $fields[11],
            'token' => $encodedToken,
        ];

        if ($this->isDropToken($fields)) {
            $payload['drop_id'] = (int) $fields[0];
            $payload['email_id'] = $this->uuidOrZero($fields[2]);
            $payload['referral'] = $this->trim($referer, 4096);
            ($this->postJson)('/api/v1/stats-drop', $payload);

            return;
        }

        $payload['campaign_id'] = $this->uuid($fields[0]);
        $payload['send_id'] = $this->uuidOrZero($fields[1]);
        $payload['email_id'] = $this->uuidOrZero($fields[2]);
        ($this->postJson)('/api/v1/tracking/campaign-events', $payload);
    }

    /** @param list<string> $fields */
    private function isDropToken(array $fields): bool
    {
        return preg_match('/\A[1-9][0-9]{0,17}\z/', $fields[0]) === 1;
    }

    private function uuid(string $value): string
    {
        if (preg_match('/\A[a-f0-9]{32}\z/i', $value) !== 1) {
            return '';
        }

        return strtolower(substr($value, 0, 8).'-'.substr($value, 8, 4).'-'.substr($value, 12, 4).'-'.substr($value, 16, 4).'-'.substr($value, 20));
    }

    private function uuidOrZero(string $value): string
    {
        return $value === '0' ? '0' : $this->uuid($value);
    }

    private function positiveInteger(string $value): int
    {
        return preg_match('/\A[0-9]{1,18}\z/', $value) === 1 ? (int) $value : 0;
    }

    private function requestIp(string $address): string
    {
        return filter_var($address, FILTER_VALIDATE_IP) !== false ? $address : '127.0.0.1';
    }

    private function isAbsoluteHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    private function trim(string $value, int $maxLength): string
    {
        return substr($value, 0, $maxLength);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function redirect(string $destination): array
    {
        if (! $this->isAbsoluteHttpUrl($destination)) {
            $destination = self::UNKNOWN_REDIRECT;
        }

        return [
            'status' => 302,
            'headers' => [
                'Location' => $destination,
                'Cache-Control' => 'no-store, no-cache, max-age=0',
                'X-Robots-Tag' => 'noindex, nofollow',
                'X-AlphaLink-Tracking' => 'active',
                'Content-Length' => '0',
            ],
            'body' => '',
        ];
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function pixel(string $method): array
    {
        $body = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/aV8AAAAASUVORK5CYII=', true) ?: '';

        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'no-store, no-cache, max-age=0',
                'X-Robots-Tag' => 'noindex, nofollow',
                'X-AlphaLink-Tracking' => 'active',
                'Content-Length' => (string) strlen($body),
            ],
            'body' => strtoupper($method) === 'HEAD' ? '' : $body,
        ];
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function response(int $status, string $contentType, string $body): array
    {
        return [
            'status' => $status,
            'headers' => [
                'Content-Type' => $contentType,
                'Cache-Control' => 'no-store, no-cache, max-age=0',
                'X-AlphaLink-Tracking' => 'active',
                'Content-Length' => (string) strlen($body),
            ],
            'body' => $body,
        ];
    }
}
