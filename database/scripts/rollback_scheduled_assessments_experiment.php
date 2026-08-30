<?php

declare(strict_types=1);

/**
 * Safe rollback for the retired scheduled-assessments experiment tables.
 *
 * Drops (in order):
 *   1. assessment_results
 *   2. assessments
 *
 * Safety:
 * - Does NOT run drops automatically when unexpected/new data is present.
 * - Detects rows that are not simply previous legacy-marks migrations
 *   (legacy_group_key / legacy_mark_id NULL or missing).
 * - Never touches `marks`, `assignments`, `assignment_submissions`,
 *   or `assignment_results`.
 *
 * Usage (report only — default):
 *   C:\xampp\php\php.exe database/scripts/rollback_scheduled_assessments_experiment.php
 *
 * Usage (perform drops only when safe):
 *   C:\xampp\php\php.exe database/scripts/rollback_scheduled_assessments_experiment.php --execute
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/web/config/env.php';
require_once dirname(__DIR__, 2) . '/web/config/database.php';
require_once dirname(__DIR__, 2) . '/web/config/config.php';

$execute = in_array('--execute', $argv, true);
$pdo = db();

function table_exists(PDO $pdo, string $table): bool
{
    $statement = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));

    return $statement !== false && $statement->fetchColumn() !== false;
}

function column_exists(PDO $pdo, string $table, string $column): bool
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

echo "Scheduled-assessments experiment rollback report\n";
echo str_repeat('-', 52) . PHP_EOL;

$hasAssessments = table_exists($pdo, 'assessments');
$hasResults = table_exists($pdo, 'assessment_results');

if (!$hasAssessments && !$hasResults) {
    echo "Neither assessments nor assessment_results exists. Nothing to drop.\n";
    exit(0);
}

$assessmentCount = $hasAssessments
    ? (int) $pdo->query('SELECT COUNT(*) FROM assessments')->fetchColumn()
    : 0;
$resultCount = $hasResults
    ? (int) $pdo->query('SELECT COUNT(*) FROM assessment_results')->fetchColumn()
    : 0;

echo "assessments rows: {$assessmentCount}\n";
echo "assessment_results rows: {$resultCount}\n";

$unexpected = [];

if ($hasAssessments && column_exists($pdo, 'assessments', 'legacy_group_key')) {
    $unexpectedAssessments = (int) $pdo->query(
        'SELECT COUNT(*) FROM assessments WHERE legacy_group_key IS NULL OR legacy_group_key = \'\''
    )->fetchColumn();
    if ($unexpectedAssessments > 0) {
        $unexpected[] = "{$unexpectedAssessments} assessments row(s) without legacy_group_key (not pure migrated marks)";
    }
} elseif ($hasAssessments && $assessmentCount > 0) {
    $unexpected[] = 'assessments table has rows but no legacy_group_key column — refusing automatic drop';
}

if ($hasResults && column_exists($pdo, 'assessment_results', 'legacy_mark_id')) {
    $unexpectedResults = (int) $pdo->query(
        'SELECT COUNT(*) FROM assessment_results WHERE legacy_mark_id IS NULL'
    )->fetchColumn();
    if ($unexpectedResults > 0) {
        $unexpected[] = "{$unexpectedResults} assessment_results row(s) without legacy_mark_id (not pure migrated marks)";
    }
} elseif ($hasResults && $resultCount > 0) {
    $unexpected[] = 'assessment_results table has rows but no legacy_mark_id column — refusing automatic drop';
}

if ($unexpected !== []) {
    echo "UNSAFE — unexpected data detected:\n";
    foreach ($unexpected as $line) {
        echo '  - ' . $line . PHP_EOL;
    }
    echo "Refusing to drop. Inspect data manually before removing tables.\n";
    exit(2);
}

if ($assessmentCount === 0 && $resultCount === 0) {
    echo "SAFE — tables empty (or only absent).\n";
} else {
    echo "SAFE — remaining rows look like legacy-marks migration data only.\n";
}

if (!$execute) {
    echo "Dry run only. Re-run with --execute to drop assessment_results then assessments.\n";
    exit(0);
}

try {
    if ($hasResults) {
        $pdo->exec('DROP TABLE assessment_results');
        echo "Dropped assessment_results.\n";
    }
    if ($hasAssessments) {
        $pdo->exec('DROP TABLE assessments');
        echo "Dropped assessments.\n";
    }
    echo "Rollback complete.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'DROP failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
