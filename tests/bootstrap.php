<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

if (file_exists(__DIR__.'/../config/bootstrap.php')) {
    require __DIR__.'/../config/bootstrap.php';
} elseif (file_exists(__DIR__.'/../.env.test')) {
    new Dotenv()->bootEnv(__DIR__.'/../.env.test');
} elseif (file_exists(__DIR__.'/../.env')) {
    new Dotenv()->bootEnv(__DIR__.'/../.env');
} elseif (file_exists(__DIR__.'/../.env.dist')) {
    new Dotenv()->bootEnv(__DIR__.'/../.env.dist');
}

// Ensure build directory and dummy entrypoints exist for Webpack Encore
$buildDir = __DIR__.'/../public/build';
if (!is_dir($buildDir)) {
    @mkdir($buildDir, 0775, true);
}
if (!file_exists($buildDir.'/entrypoints.json')) {
    @file_put_contents($buildDir.'/entrypoints.json', json_encode(['entrypoints' => ['app' => ['js' => [], 'css' => []]]]));
}

// Ensure var directory exists and SQLite test database schema is created
$varDir = __DIR__.'/../var';
if (!is_dir($varDir)) {
    @mkdir($varDir, 0775, true);
}

passthru(sprintf('php "%s/bin/console" doctrine:schema:update --force --complete --env=test --no-interaction 2>/dev/null', dirname(__DIR__)));
