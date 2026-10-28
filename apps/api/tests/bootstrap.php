<?php

declare(strict_types=1);

$commonTestEnvironment = [
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'DB_URL' => '',
];

$databaseConnection = $_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? getenv('DB_CONNECTION');
$databaseEnvironment = match ($databaseConnection) {
    'sqlite' => [
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'APP_CONFIG_CACHE' => '/tmp/alphalink-phpunit-config-cache-disabled.php',
    ],
    'pgsql' => [
        'ALPHALINK_TEST_DB_MODE' => 'disposable-postgres',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '5432',
        'DB_DATABASE' => 'alphalink_disposable_test',
        'DB_USERNAME' => 'alphalink_test',
        'DB_PASSWORD' => 'alphalink_test_only',
        'APP_CONFIG_CACHE' => '/tmp/alphalink-phpunit-postgres-config-cache-disabled.php',
    ],
    default => throw new RuntimeException('PHPUnit refused to start: only isolated SQLite or the explicitly configured disposable PostgreSQL test database is allowed.'),
};

foreach (array_merge($commonTestEnvironment, $databaseEnvironment) as $name => $expected) {
    $actual = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

    if ($actual !== $expected) {
        throw new RuntimeException("PHPUnit refused to start: {$name} must be set to its isolated test value.");
    }
}

if (is_file($databaseEnvironment['APP_CONFIG_CACHE'])) {
    throw new RuntimeException('PHPUnit refused to start because its isolated config-cache path already exists.');
}

require dirname(__DIR__).'/vendor/autoload.php';
