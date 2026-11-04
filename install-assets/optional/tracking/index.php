<?php

declare(strict_types=1);

// ALPHALINK_TRACKING_READY
require_once '/var/alphalink-tracker/TrackerRequestHandler.php';

$configPath = '/etc/alphalink-tracker.php';
if (! is_file($configPath) || ! is_readable($configPath)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, max-age=0');
    echo 'Tracking is not configured.';
    exit;
}

$config = require $configPath;
if (! is_array($config)
    || ! is_string($config['api_url'] ?? null)
    || ! is_string($config['ingest_key'] ?? null)
    || ! is_string($config['application_name'] ?? null)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, max-age=0');
    echo 'Tracking is not configured.';
    exit;
}

$postJson = static function (string $path, array $payload) use ($config): ?array {
    $base = rtrim($config['api_url'], '/');
    $baseParts = parse_url($base);
    if (! is_array($baseParts)
        || strtolower((string) ($baseParts['scheme'] ?? '')) !== 'https'
        || ! is_string($baseParts['host'] ?? null)
        || $baseParts['host'] === ''
        || isset($baseParts['user'])
        || isset($baseParts['pass'])
        || ! str_starts_with($path, '/api/v1/')) {
        return null;
    }

    try {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\nConnection: close\r\n",
            'content' => $body,
            'timeout' => 4,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
        ],
    ]);

    $responseBody = @file_get_contents($base.$path, false, $context);
    $headers = $http_response_header ?? [];
    $status = 0;
    foreach ($headers as $header) {
        if (preg_match('/\AHTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
            $status = (int) $matches[1];
        }
    }

    if (! is_string($responseBody)) {
        return null;
    }

    $decoded = json_decode($responseBody, true);

    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null];
};

$handler = new AlphaLinkTrackerRequestHandler($config, $postJson);
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
if (isset($_GET['__alphalink_long_uri']) && is_string($_GET['__alphalink_long_uri'])) {
    $requestUri = '/'.ltrim($_GET['__alphalink_long_uri'], '/');
}

$response = $handler->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $requestUri,
    (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    (string) ($_SERVER['HTTP_REFERER'] ?? ''),
);

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name.': '.$value);
}
echo $response['body'];
