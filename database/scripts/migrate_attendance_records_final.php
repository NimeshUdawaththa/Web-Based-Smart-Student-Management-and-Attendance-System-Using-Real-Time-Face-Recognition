<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — add final attendance snapshot columns if missing.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/migrate_attendance_records_final.php
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

if (!table_has_column($pdo, 'attendance_records', 'teaching_minutes')) {
    $pdo->exec(
        'ALTER TABLE attendance_records
         ADD COLUMN teaching_minutes INT UNSIGNED NOT NULL DEFAULT 0
         AFTER total_present_minutes'
    );
    $changes++;
    echo "added attendance_records.teaching_minutes\n";
}
if (!table_has_column($pdo, 'attendance_records', 'attendance_percent')) {
    $pdo->exec(
        'ALTER TABLE attendance_records
         ADD COLUMN attendance_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00
         AFTER teaching_minutes'
    );
    $changes++;
    echo "added attendance_records.attendance_percent\n";
}
if (!table_has_column($pdo, 'attendance_records', 'finalized_at')) {
    $pdo->exec(
        'ALTER TABLE attendance_records
         ADD COLUMN finalized_at DATETIME DEFAULT NULL
         AFTER left_early'
    );
    $changes++;
    echo "added attendance_records.finalized_at\n";
}

echo $changes === 0
    ? "attendance_records final columns already present\n"
    : "migration complete ($changes column(s))\n";
