<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/TrackerRequestHandler.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$fields = [
    '01a1a282009b720081c13a225d4316fc',
    '01a1a282009b720081c13a225d4316fd',
    '01a1a282009b720081c13a225d4316fe',
    'f',
    '01a1a282009b720081c13a225d4316ff',
    '7',
    '01a1a282009b720081c13a225d4316f1',
    '01a1a282009b720081c13a225d4316f2',
    '01a1a282009b720081c13a225d4316f3',
    '10',
    '11',
    '12',
    'R4nd0mXYZ_'.hash('adler32', 'AlphaLink'),
];
$fields[] = hash('adler32', implode('_', array_slice($fields, 0, 3)));
$encodedToken = rtrim(strtr(base64_encode(implode('-', $fields)), '+/', '-_'), '=');
$routeUrl = static function (string $action, string $token): string {
    $segments = str_split($token, 149);
    $first = array_shift($segments);

    return '/ABCDE12345'.$action.$first.($segments === [] ? '' : '/'.implode('/', $segments)).'?t=1';
};

$calls = [];
$unsubscribeDestination = 'https://offer.example.test/unsubscribe';
$poster = static function (string $path, array $payload) use (&$calls, &$unsubscribeDestination): array {
    $calls[] = [$path, $payload];
    if ($path === '/api/v1/campaign' || $path === '/api/v1/drop') {
        $destination = ($payload['type'] ?? '') === 'unsub'
            ? $unsubscribeDestination
            : 'https://offer.example.test/landing';

        return ['status' => 200, 'body' => ['link' => $destination]];
    }

    return ['status' => 204, 'body' => null];
};
$handler = new AlphaLinkTrackerRequestHandler([
    'api_url' => 'https://baseheavy.example.test',
    'ingest_key' => 'synthetic-ingest-key',
    'application_name' => 'AlphaLink',
], $poster);

$click = $handler->handle('GET', $routeUrl('r', $encodedToken), '198.51.100.23', 'Synthetic Browser/1.0');
$assert($click['status'] === 302, 'Campaign click did not redirect.');
$assert($click['headers']['Location'] === 'https://offer.example.test/landing', 'Campaign click used the wrong destination.');
$assert($click['headers']['X-AlphaLink-Tracking'] === 'active', 'Campaign route marker was missing.');
$assert($calls[0][0] === '/api/v1/campaign', 'Campaign click did not resolve through AlphaLink.');
$assert($calls[0][1]['campaign_id'] === '01a1a282-009b-7200-81c1-3a225d4316fc', 'Campaign ID was not normalized.');
$assert($calls[0][1]['send_id'] === '01a1a282-009b-7200-81c1-3a225d4316fd', 'Send ID was not normalized.');
$assert($calls[1][0] === '/api/v1/tracking/campaign-events', 'Campaign click analytics were not submitted.');
$assert($calls[1][1]['action'] === 'click', 'Campaign click analytics used the wrong action.');
$assert($calls[1][1]['ip_address'] === '198.51.100.23', 'Campaign event lost the visitor IP.');

$calls = [];
$open = $handler->handle('GET', $routeUrl('o', $encodedToken), '198.51.100.23', 'Synthetic Browser/1.0');
$assert($open['status'] === 200 && $open['headers']['Content-Type'] === 'image/png', 'Open route did not return a tracking pixel.');
$assert(str_starts_with($open['body'], "\x89PNG\r\n\x1a\n"), 'Open response is not a PNG.');
$assert(count($calls) === 1 && $calls[0][1]['action'] === 'open', 'Open was not recorded exactly once.');
$head = $handler->handle('HEAD', $routeUrl('o', $encodedToken), '198.51.100.23', 'Synthetic Browser/1.0');
$assert($head['status'] === 200 && $head['body'] === '', 'HEAD open response included a body.');

$calls = [];
$unsubscribe = $handler->handle('GET', $routeUrl('u', $encodedToken), '198.51.100.23', 'Synthetic Browser/1.0');
$assert($unsubscribe['status'] === 302, 'Unsubscribe route did not redirect.');
$assert($unsubscribe['headers']['Location'] === 'https://offer.example.test/unsubscribe', 'Unsubscribe destination was not honored.');
$assert($calls[0][1]['type'] === 'unsub', 'Unsubscribe lookup used the wrong API action.');
$assert($calls[1][1]['action'] === 'unsubscribe', 'Unsubscribe analytics used the wrong event action.');

$unsubscribeDestination = 'unsubscribe?u=1';
$calls = [];
$unsubscribeFallback = $handler->handle('GET', $routeUrl('u', $encodedToken), '198.51.100.23', 'Synthetic Browser/1.0');
$assert($unsubscribeFallback['status'] === 200, 'Missing unsubscribe URL did not show a confirmation page.');
$assert(str_contains($unsubscribeFallback['body'], 'Your unsubscribe request was submitted to AlphaLink.'), 'Missing unsubscribe URL did not show the AlphaLink confirmation.');
$assert(! isset($unsubscribeFallback['headers']['Location']), 'Missing unsubscribe URL redirected to an unrelated destination.');
$assert($calls[0][1]['type'] === 'unsub' && $calls[1][1]['action'] === 'unsubscribe', 'Missing unsubscribe URL did not submit the unsubscribe event.');

$testFields = $fields;
$testFields[2] = '0';
$testFields[13] = hash('adler32', implode('_', array_slice($testFields, 0, 3)));
$testToken = rtrim(strtr(base64_encode(implode('-', $testFields)), '+/', '-_'), '=');
$calls = [];
$testUnsubscribeFallback = $handler->handle('GET', $routeUrl('u', $testToken), '198.51.100.23', 'Synthetic Browser/1.0');
$assert($testUnsubscribeFallback['status'] === 200, 'Test unsubscribe did not show its no-recipient notice.');
$assert(str_contains($testUnsubscribeFallback['body'], 'This test link has no recipient and cannot unsubscribe anyone.'), 'Test unsubscribe did not explain that no recipient can be unsubscribed.');
$assert(count($calls) === 1 && $calls[0][1]['email_id'] === '0', 'Test unsubscribe unexpectedly submitted a recipient event.');

$dropFields = $fields;
$dropFields[0] = '42';
$dropFields[1] = '0';
$dropFields[12] = 'R4nd0mXYZ_'.hash('adler32', 'AlphaLink');
$dropFields[13] = hash('adler32', implode('_', array_slice($dropFields, 0, 3)));
$dropToken = rtrim(strtr(base64_encode(implode('-', $dropFields)), '+/', '-_'), '=');
$calls = [];
$dropClick = $handler->handle('GET', $routeUrl('r', $dropToken), '198.51.100.24', 'Synthetic Drop Browser/1.0');
$assert($dropClick['headers']['Location'] === 'https://offer.example.test/landing', 'Drop click did not redirect.');
$assert($calls[0][0] === '/api/v1/drop' && $calls[0][1]['drop_id'] === 42, 'Drop redirect did not resolve through AlphaLink.');
$assert($calls[1][0] === '/api/v1/stats-drop' && $calls[1][1]['action'] === 'click', 'Drop click analytics were not submitted.');

$calls = [];
$invalidFields = $fields;
$invalidFields[13] = '00000000';
$invalidToken = rtrim(strtr(base64_encode(implode('-', $invalidFields)), '+/', '-_'), '=');
$invalid = $handler->handle('GET', $routeUrl('r', $invalidToken), '198.51.100.23', 'Synthetic Browser/1.0');
$assert($invalid['status'] === 302 && $invalid['headers']['Location'] === AlphaLinkTrackerRequestHandler::UNKNOWN_REDIRECT, 'Invalid token did not use the HTTPS unknown destination.');
$assert($calls === [], 'Invalid token unexpectedly reached the callback.');

$unknown = $handler->handle('GET', '/not-a-tracking-route', '198.51.100.23', 'Synthetic Browser/1.0');
$assert($unknown['headers']['Location'] === 'https://www.google.com', 'Unknown route did not use the HTTPS Google fallback.');

echo "AlphaLink tracker request tests passed (8 checks).\n";
