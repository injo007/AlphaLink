<?php

declare(strict_types=1);

$requiredTestEnvironment = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'APP_CONFIG_CACHE' => '/tmp/alphalink-phpunit-config-cache-disabled.php',
];

foreach ($requiredTestEnvironment as $name => $expected) {
    $actual = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

    if ($actual !== $expected) {
        throw new RuntimeException("PHPUnit refused to start: {$name} must be set to its isolated test value.");
    }
}

if (is_file($requiredTestEnvironment['APP_CONFIG_CACHE'])) {
    throw new RuntimeException('PHPUnit refused to start because its isolated config-cache path already exists.');
}

require dirname(__DIR__).'/vendor/autoload.php';
