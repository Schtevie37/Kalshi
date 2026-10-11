<?php

declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "The desk is not configured yet.\n\nRun bin/setup.sh from the project directory, then start the server again.\n";
    exit;
}

/** @var array<string, mixed> $config */
$config = require $configFile;

function app_config(): array
{
    global $config;
    if (!is_array($config)) {
        throw new RuntimeException('config/config.php must return an array.');
    }

    return $config;
}

require __DIR__ . '/Signer.php';
require __DIR__ . '/Pricing.php';
require __DIR__ . '/Vault.php';
require __DIR__ . '/KalshiClient.php';
require __DIR__ . '/Trading.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/routes.php';

$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('kalshipaper');
session_start();

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
