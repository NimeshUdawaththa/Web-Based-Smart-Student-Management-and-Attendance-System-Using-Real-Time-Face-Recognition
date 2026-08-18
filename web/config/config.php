<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

define('CONFIG_PATH', WEB_PATH . '/config');
define('PUBLIC_PATH', WEB_PATH . '/public');
define('INCLUDES_PATH', WEB_PATH . '/shared/includes');
define('UPLOADS_PATH', WEB_PATH . '/uploads');

define('APP_NAME', env('APP_NAME', 'Smart Student Management') ?? 'Smart Student Management');
define('APP_ENV', env('APP_ENV', 'local') ?? 'local');
define('APP_DEBUG', env_flag('APP_DEBUG', APP_ENV !== 'production'));
define('APP_URL', rtrim(env('APP_URL', 'http://localhost') ?? 'http://localhost', '/'));
define('APP_TIMEZONE', env('APP_TIMEZONE', 'Asia/Colombo') ?? 'Asia/Colombo');

date_default_timezone_set(APP_TIMEZONE);

/**
 * Escape a value for safe HTML output.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Public URL path to web/public (no trailing slash), detected from the filesystem.
 */
function public_base_url(): string
{
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $publicPath = realpath(PUBLIC_PATH) ?: '';

    if ($documentRoot !== '' && $publicPath !== '' && str_starts_with($publicPath, $documentRoot)) {
        $relative = substr($publicPath, strlen($documentRoot));
        $base = rtrim(str_replace('\\', '/', $relative), '/');
        return $base;
    }

    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $base = ($scriptDir === '/' || $scriptDir === '\\' || $scriptDir === '.') ? '' : rtrim($scriptDir, '/');

    return $base;
}

function asset_url(string $path): string
{
    return public_base_url() . '/assets/' . ltrim($path, '/');
}
