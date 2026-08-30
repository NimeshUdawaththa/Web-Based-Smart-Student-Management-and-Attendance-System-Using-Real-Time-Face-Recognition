<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — Coursework & Assessments unification Phase 1 schema.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/migrate_coursework_assessments_unification.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/web/config/env.php';
require_once dirname(__DIR__, 2) . '/web/config/config.php';
require_once dirname(__DIR__, 2) . '/web/config/database.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/auth.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/management.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/academic.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/marks.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/assignments.php';

$pdo = db();
$beforeAssignments = (int) $pdo->query('SELECT COUNT(*) FROM assignments')->fetchColumn();
$beforeSubmissions = (int) $pdo->query('SELECT COUNT(*) FROM assignment_submissions')->fetchColumn();

$notes = migrate_coursework_assessments_unification_schema();
if ($notes === []) {
    echo "coursework assessments unification schema already present\n";
} else {
    foreach ($notes as $note) {
        echo $note . PHP_EOL;
    }
}

$afterAssignments = (int) $pdo->query('SELECT COUNT(*) FROM assignments')->fetchColumn();
$afterSubmissions = (int) $pdo->query('SELECT COUNT(*) FROM assignment_submissions')->fetchColumn();
echo "assignments preserved: {$beforeAssignments} -> {$afterAssignments}\n";
echo "submissions preserved: {$beforeSubmissions} -> {$afterSubmissions}\n";

$types = $pdo->query('SELECT activity_type, COUNT(*) AS c FROM assignments GROUP BY activity_type')->fetchAll();
foreach ($types as $row) {
    echo 'activity_type ' . $row['activity_type'] . ': ' . $row['c'] . PHP_EOL;
}

echo "migration complete\n";
