<?php

declare(strict_types=1);

/**
 * DEVELOPMENT ONLY — seed sample courses and batches for student registration testing.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/seed_courses_batches.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This development script can only be run from the command line.');
}

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/web/config/env.php';
require_once dirname(__DIR__, 2) . '/web/config/config.php';
require_once dirname(__DIR__, 2) . '/web/config/database.php';

if (APP_ENV === 'production' || !APP_DEBUG) {
    fwrite(STDERR, "Refusing to seed development data outside a local debug environment.\n");
    exit(1);
}

$pdo = db();

$courses = [
    ['BSC-CS', 'BSc Computer Science', 3],
    ['BBA', 'Bachelor of Business Administration', 3],
];

foreach ($courses as [$code, $name, $years]) {
    $pdo->prepare(
        "INSERT INTO courses (course_code, course_name, duration_years, status)
         VALUES (:course_code, :course_name, :duration_years, 'ACTIVE')
         ON DUPLICATE KEY UPDATE course_name = VALUES(course_name), duration_years = VALUES(duration_years), status = 'ACTIVE'"
    )->execute([
        'course_code' => $code,
        'course_name' => $name,
        'duration_years' => $years,
    ]);
}

$courseIds = [];
foreach ($courses as [$code]) {
    $statement = $pdo->prepare('SELECT course_id FROM courses WHERE course_code = :course_code LIMIT 1');
    $statement->execute(['course_code' => $code]);
    $courseIds[$code] = (int) $statement->fetchColumn();
}

$batches = [
    [$courseIds['BSC-CS'], 'CS-2024-A', 2024, '2024-01-15', '2027-12-31'],
    [$courseIds['BSC-CS'], 'CS-2025-A', 2025, '2025-01-15', '2028-12-31'],
    [$courseIds['BBA'], 'BBA-2024-A', 2024, '2024-02-01', '2027-12-31'],
];

foreach ($batches as [$courseId, $name, $year, $start, $end]) {
    $pdo->prepare(
        "INSERT INTO batches (course_id, batch_name, intake_year, start_date, end_date, status)
         VALUES (:course_id, :batch_name, :intake_year, :start_date, :end_date, 'ACTIVE')
         ON DUPLICATE KEY UPDATE intake_year = VALUES(intake_year), start_date = VALUES(start_date), end_date = VALUES(end_date), status = 'ACTIVE'"
    )->execute([
        'course_id' => $courseId,
        'batch_name' => $name,
        'intake_year' => $year,
        'start_date' => $start,
        'end_date' => $end,
    ]);
}

echo "Seeded sample courses and batches.\n";
