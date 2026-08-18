<?php

declare(strict_types=1);

/**
 * Bootstrap for localhost machine-to-machine PHP calls (Flask attendance bridge).
 * Does not start a browser session and does not require a logged-in user.
 */

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/config/env.php';
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/shared/includes/management.php';
require_once dirname(__DIR__, 2) . '/shared/includes/academic.php';
require_once dirname(__DIR__, 2) . '/shared/includes/attendance.php';

function internal_api_token(): string
{
    return trim((string) (env('INTERNAL_API_TOKEN') ?? ''));
}

function request_is_loopback(): bool
{
    $addr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    return in_array($addr, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
}

function provided_internal_token(): string
{
    $header = (string) ($_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? '');
    if ($header !== '') {
        return $header;
    }

    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (str_starts_with($auth, 'Bearer ')) {
        return substr($auth, 7);
    }

    return '';
}

function require_internal_access(): void
{
    $token = internal_api_token();
    if ($token === '') {
        http_response_code(503);
        echo json_encode(['result' => 'ERROR', 'error' => 'Internal API token is not configured']);
        exit;
    }

    if (PHP_SAPI !== 'cli' && !request_is_loopback()) {
        http_response_code(403);
        echo json_encode(['result' => 'ERROR', 'error' => 'Forbidden']);
        exit;
    }

    if (!hash_equals($token, provided_internal_token())) {
        http_response_code(403);
        echo json_encode(['result' => 'ERROR', 'error' => 'Forbidden']);
        exit;
    }
}
