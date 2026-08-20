<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — new-student auto-enrol against TEST-ICT / ICT-2026-A.
 *
 * Reproduces the browser case: batch already has ACTIVE batch_modules,
 * then a new ACTIVE student is registered into that course+batch.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_auto_enrol_ict.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/web/config/env.php';
require_once dirname(__DIR__, 2) . '/web/config/database.php';
require_once dirname(__DIR__, 2) . '/web/config/config.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/auth.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/management.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/academic.php';

$failed = false;

function fail(string $message): void
{
    global $failed;
    $failed = true;
    fwrite(STDERR, 'FAIL ' . $message . PHP_EOL);
}

function pass(string $message): void
{
    echo 'PASS ' . $message . PHP_EOL;
}

$pdo = db();
$course = $pdo->query("SELECT course_id, course_code, status FROM courses WHERE course_code = 'TEST-ICT' LIMIT 1")->fetch();
$batch = $pdo->query("SELECT batch_id, course_id, batch_name, status FROM batches WHERE batch_name = 'ICT-2026-A' LIMIT 1")->fetch();

if ($course === false || $batch === false) {
    fwrite(STDERR, "TEST-ICT / ICT-2026-A not found. Create them before running this regression.\n");
    exit(1);
}

$courseId = (int) $course['course_id'];
$batchId = (int) $batch['batch_id'];
$suffix = strtoupper(bin2hex(random_bytes(3)));
$cleanup = [
    'student_id' => null,
    'user_id' => null,
];

try {
    if ($course['status'] === 'ACTIVE' && $batch['status'] === 'ACTIVE' && (int) $batch['course_id'] === $courseId) {
        pass('A TEST-ICT and ICT-2026-A are ACTIVE and linked');
    } else {
        fail('A TEST-ICT and ICT-2026-A are ACTIVE and linked');
    }

    $batchModules = list_active_assigned_batch_modules($batchId);
    $batchIds = array_map(static fn (array $row): int => (int) $row['module_id'], $batchModules);
    sort($batchIds);
    if (count($batchModules) === 2) {
        pass('B ICT-2026-A has exactly 2 ACTIVE batch modules eligible for auto-enrol');
    } else {
        fail('B ICT-2026-A has exactly 2 ACTIVE batch modules eligible for auto-enrol (got ' . count($batchModules) . ')');
    }

    $courseModules = list_active_course_modules($courseId);
    if (count($courseModules) > count($batchModules)) {
        pass('C Course has more ACTIVE course_modules than batch_modules');
    } else {
        fail('C Course has more ACTIVE course_modules than batch_modules');
    }

    $studentId = register_student([
        'username' => 'ictae' . strtolower($suffix),
        'email' => 'ictae' . strtolower($suffix) . '@example.test',
        'password' => 'Student123!',
        'registration_no' => 'REG-ICTAE-' . $suffix,
        'first_name' => 'Ict',
        'last_name' => 'AutoEnrol',
        'phone' => '',
        'date_of_birth' => '',
        'gender' => '',
        'course_id' => $courseId,
        'batch_id' => $batchId,
        'enrollment_date' => '2026-08-20',
        'status' => 'ACTIVE',
        'account_status' => 'ACTIVE',
    ]);
    $student = get_student($studentId);
    $cleanup['student_id'] = $studentId;
    $cleanup['user_id'] = $student !== null ? (int) $student['user_id'] : null;

    $enrolments = list_student_module_enrolments($studentId);
    $enrolledIds = [];
    foreach ($enrolments as $row) {
        if ($row['status'] === 'ENROLLED') {
            $enrolledIds[] = (int) $row['module_id'];
        }
    }
    sort($enrolledIds);

    if ($student !== null && (int) $student['course_id'] === $courseId && (int) $student['batch_id'] === $batchId) {
        pass('D Registered student is on TEST-ICT / ICT-2026-A');
    } else {
        fail('D Registered student is on TEST-ICT / ICT-2026-A');
    }

    if ($enrolledIds === $batchIds && $enrolments !== []) {
        pass('E New student auto-enrols exactly the 2 ACTIVE batch modules');
    } else {
        fail('E New student auto-enrols exactly the 2 ACTIVE batch modules');
    }

    $courseModuleIds = array_map(static fn (array $row): int => (int) $row['module_id'], $courseModules);
    $extra = array_diff($enrolledIds, $batchIds);
    $missingCourseOnly = array_diff($courseModuleIds, $batchIds);
    $enrolledCourseOnly = array_intersect($enrolledIds, $missingCourseOnly);
    if ($extra === [] && $enrolledCourseOnly === []) {
        pass('F Student is NOT auto-enrolled into all course modules');
    } else {
        fail('F Student is NOT auto-enrolled into all course modules');
    }

    $codes = array_map(static fn (array $row): string => (string) $row['module_code'], $enrolments);
    sort($codes);
    if ($codes === ['CAT101', 'CS101'] || count($codes) === 2) {
        pass('G Student → Modules list is not empty after registration');
    } else {
        fail('G Student → Modules list is not empty after registration');
    }
} catch (Throwable $exception) {
    fail('Unhandled: ' . $exception->getMessage());
}

try {
    if ($cleanup['student_id'] !== null) {
        $pdo->prepare('DELETE FROM student_modules WHERE student_id = :id')->execute(['id' => $cleanup['student_id']]);
        $pdo->prepare('DELETE FROM students WHERE student_id = :id')->execute(['id' => $cleanup['student_id']]);
    }
    if ($cleanup['user_id'] !== null) {
        $pdo->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $cleanup['user_id']]);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Cleanup warning: ' . $exception->getMessage() . PHP_EOL);
}

if ($failed) {
    exit(1);
}

echo "All TEST-ICT auto-enrol regression tests passed." . PHP_EOL;
