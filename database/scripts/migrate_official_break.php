<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — add official break columns if they are missing.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/migrate_official_break.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/web/config/env.php';
require_once dirname(__DIR__, 2) . '/web/config/config.php';
require_once dirname(__DIR__, 2) . '/web/config/database.php';

function table_has_column(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $statement->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

$pdo = db();
$changes = 0;

if (!table_has_column($pdo, 'schedules', 'break_start')) {
    $pdo->exec('ALTER TABLE schedules ADD COLUMN break_start TIME DEFAULT NULL AFTER end_time');
    $changes++;
    echo "added schedules.break_start\n";
}
if (!table_has_column($pdo, 'schedules', 'break_end')) {
    $pdo->exec('ALTER TABLE schedules ADD COLUMN break_end TIME DEFAULT NULL AFTER break_start');
    $changes++;
    echo "added schedules.break_end\n";
}
if (!table_has_column($pdo, 'lecture_sessions', 'break_start')) {
    $pdo->exec('ALTER TABLE lecture_sessions ADD COLUMN break_start TIME DEFAULT NULL AFTER scheduled_end');
    $changes++;
    echo "added lecture_sessions.break_start\n";
}
if (!table_has_column($pdo, 'lecture_sessions', 'break_end')) {
    $pdo->exec('ALTER TABLE lecture_sessions ADD COLUMN break_end TIME DEFAULT NULL AFTER break_start');
    $changes++;
    echo "added lecture_sessions.break_end\n";
}

echo $changes === 0 ? "official break columns already present\n" : "migration complete ($changes column(s))\n";
