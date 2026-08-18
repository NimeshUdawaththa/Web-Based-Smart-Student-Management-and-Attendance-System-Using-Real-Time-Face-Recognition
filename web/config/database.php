<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Shared PDO connection. Created once per request.
 *
 * @throws PDOException
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env('DB_HOST', 'localhost') ?? 'localhost';
    $port = env('DB_PORT', '3306') ?? '3306';
    $name = env('DB_NAME', 'smart_student_management') ?? 'smart_student_management';
    $user = env('DB_USER', 'root') ?? 'root';
    $pass = env('DB_PASS', '') ?? '';
    $charset = env('DB_CHARSET', 'utf8mb4') ?? 'utf8mb4';

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

/**
 * Development connectivity check. Never returns credentials or driver error text.
 *
 * @return array{ok: bool, label: string}
 */
function check_database_connection(): array
{
    try {
        db()->query('SELECT 1');

        return [
            'ok' => true,
            'label' => 'Connected',
        ];
    } catch (Throwable $exception) {
        error_log('Database connection check failed: ' . $exception->getMessage());

        return [
            'ok' => false,
            'label' => 'Unavailable',
        ];
    }
}
