<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — Course & Batch Management v1 Tests A–V.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_courses_batches.php
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
$root = dirname(__DIR__, 2);
$cleanup = [
    'student_ids' => [],
    'user_ids' => [],
    'batch_ids' => [],
    'course_ids' => [],
];

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

function expect_invalid(callable $callback): ?string
{
    try {
        $callback();
        return null;
    } catch (InvalidArgumentException $exception) {
        return $exception->getMessage();
    }
}

function table_count(PDO $pdo, string $table): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}

$pdo = db();
$unrelatedBefore = [
    'announcements' => table_count($pdo, 'announcements'),
    'campus_events' => table_count($pdo, 'campus_events'),
    'assignments' => table_count($pdo, 'assignments'),
    'marks' => table_count($pdo, 'marks'),
    'attendance_events' => table_count($pdo, 'attendance_events'),
    'schedules' => table_count($pdo, 'schedules'),
    'lecture_sessions' => table_count($pdo, 'lecture_sessions'),
];

$suffix = strtoupper(bin2hex(random_bytes(3)));
$codeA = 'T' . $suffix . 'A';
$codeB = 'T' . $suffix . 'B';
$codeC = 'T' . $suffix . 'C';
$sharedName = 'Shared Programme ' . $suffix;

try {
    $adminCreate = (string) file_get_contents($root . '/web/admin/courses/create.php');
    $adminBatch = (string) file_get_contents($root . '/web/admin/batches/create.php');
    if (str_contains($adminCreate, 'require_admin()') && str_contains($adminBatch, 'require_admin()')) {
        $courseA = create_course([
            'course_code' => $codeA,
            'course_name' => $sharedName,
            'duration_years' => 3,
            'status' => 'ACTIVE',
        ]);
        $cleanup['course_ids'][] = $courseA;
        pass('A Admin can create course');
    } else {
        fail('A Admin wrappers missing require_admin()');
    }

    $staffCreate = (string) file_get_contents($root . '/web/academic-staff/courses/create.php');
    $staffBatch = (string) file_get_contents($root . '/web/academic-staff/batches/create.php');
    if (str_contains($staffCreate, 'require_student_manager()') && str_contains($staffBatch, 'require_student_manager()')) {
        $courseB = create_course([
            'course_code' => $codeB,
            'course_name' => 'Staff Course ' . $suffix,
            'duration_years' => 4,
            'status' => 'ACTIVE',
        ]);
        $cleanup['course_ids'][] = $courseB;
        pass('B Academic Staff can create course');
    } else {
        fail('B Academic Staff wrappers missing require_student_manager()');
    }

    $dupCode = expect_invalid(static function () use ($codeA): void {
        create_course([
            'course_code' => $codeA,
            'course_name' => 'Other name',
            'duration_years' => 3,
            'status' => 'ACTIVE',
        ]);
    });
    if ($dupCode !== null) {
        pass('C Duplicate course_code rejected');
    } else {
        fail('C Duplicate course_code rejected');
    }

    $courseC = create_course([
        'course_code' => $codeC,
        'course_name' => $sharedName,
        'duration_years' => 3,
        'status' => 'ACTIVE',
    ]);
    $cleanup['course_ids'][] = $courseC;
    if (get_course($courseC)['course_name'] === $sharedName && $codeC !== $codeA) {
        pass('D Duplicate course_name allowed if course_code differs');
    } else {
        fail('D Duplicate course_name allowed if course_code differs');
    }

    $badDuration = expect_invalid(static function () use ($suffix): void {
        create_course([
            'course_code' => 'X' . $suffix,
            'course_name' => 'Bad duration',
            'duration_years' => 0,
            'status' => 'ACTIVE',
        ]);
    });
    $badDurationHigh = expect_invalid(static function () use ($suffix): void {
        create_course([
            'course_code' => 'Y' . $suffix,
            'course_name' => 'Bad duration',
            'duration_years' => 11,
            'status' => 'ACTIVE',
        ]);
    });
    if ($badDuration !== null && $badDurationHigh !== null) {
        pass('E Invalid duration rejected');
    } else {
        fail('E Invalid duration rejected');
    }

    $badStatus = expect_invalid(static function () use ($suffix): void {
        create_course([
            'course_code' => 'Z' . $suffix,
            'course_name' => 'Bad status',
            'duration_years' => 3,
            'status' => 'DELETED',
        ]);
    });
    if ($badStatus !== null) {
        pass('F Invalid course status rejected');
    } else {
        fail('F Invalid course status rejected');
    }

    update_course($courseA, [
        'course_code' => $codeA,
        'course_name' => $sharedName,
        'duration_years' => 3,
        'status' => 'INACTIVE',
    ]);
    update_course($courseA, [
        'course_code' => $codeA,
        'course_name' => $sharedName,
        'duration_years' => 3,
        'status' => 'ARCHIVED',
    ]);
    $afterArchive = get_course($courseA);
    update_course($courseA, [
        'course_code' => $codeA,
        'course_name' => $sharedName,
        'duration_years' => 3,
        'status' => 'ACTIVE',
    ]);
    if ($afterArchive !== null && $afterArchive['status'] === 'ARCHIVED' && get_course($courseA)['status'] === 'ACTIVE') {
        pass('G Course can be changed ACTIVE → INACTIVE → ARCHIVED');
    } else {
        fail('G Course can be changed ACTIVE → INACTIVE → ARCHIVED');
    }

    $batchA = create_batch([
        'course_id' => $courseA,
        'batch_name' => 'INTAKE-' . $suffix,
        'intake_year' => 2026,
        'start_date' => '2026-01-15',
        'end_date' => '2029-12-31',
        'status' => 'ACTIVE',
    ]);
    $cleanup['batch_ids'][] = $batchA;
    if (get_batch($batchA)['course_id'] == $courseA) {
        pass('H Create batch under ACTIVE course');
    } else {
        fail('H Create batch under ACTIVE course');
    }

    $dupBatch = expect_invalid(static function () use ($courseA, $suffix): void {
        create_batch([
            'course_id' => $courseA,
            'batch_name' => 'INTAKE-' . $suffix,
            'intake_year' => 2027,
            'start_date' => '2027-01-15',
            'end_date' => '',
            'status' => 'ACTIVE',
        ]);
    });
    if ($dupBatch !== null) {
        pass('I Duplicate batch_name under SAME course rejected');
    } else {
        fail('I Duplicate batch_name under SAME course rejected');
    }

    $batchB = create_batch([
        'course_id' => $courseB,
        'batch_name' => 'INTAKE-' . $suffix,
        'intake_year' => 2026,
        'start_date' => '2026-02-01',
        'end_date' => '',
        'status' => 'ACTIVE',
    ]);
    $cleanup['batch_ids'][] = $batchB;
    if (get_batch($batchB)['batch_name'] === 'INTAKE-' . $suffix && (int) get_batch($batchB)['course_id'] === $courseB) {
        pass('J Same batch_name under DIFFERENT course allowed');
    } else {
        fail('J Same batch_name under DIFFERENT course allowed');
    }

    $badYear = expect_invalid(static function () use ($courseB): void {
        create_batch([
            'course_id' => $courseB,
            'batch_name' => 'YEAR-BAD',
            'intake_year' => 1990,
            'start_date' => '2026-01-01',
            'end_date' => '',
            'status' => 'ACTIVE',
        ]);
    });
    if ($badYear !== null) {
        pass('K Invalid intake year rejected');
    } else {
        fail('K Invalid intake year rejected');
    }

    $badDates = expect_invalid(static function () use ($courseB): void {
        create_batch([
            'course_id' => $courseB,
            'batch_name' => 'DATE-BAD',
            'intake_year' => 2026,
            'start_date' => '2026-06-01',
            'end_date' => '2026-01-01',
            'status' => 'ACTIVE',
        ]);
    });
    if ($badDates !== null) {
        pass('L end_date < start_date rejected');
    } else {
        fail('L end_date < start_date rejected');
    }

    $badBatchStatus = expect_invalid(static function () use ($courseB): void {
        create_batch([
            'course_id' => $courseB,
            'batch_name' => 'STATUS-BAD',
            'intake_year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '',
            'status' => 'ARCHIVED',
        ]);
    });
    if ($badBatchStatus !== null) {
        pass('M Invalid batch status rejected');
    } else {
        fail('M Invalid batch status rejected');
    }

    $originalCourse = (int) get_batch($batchA)['course_id'];
    update_batch($batchA, [
        'course_id' => $courseB,
        'batch_name' => 'INTAKE-' . $suffix,
        'intake_year' => 2026,
        'start_date' => '2026-01-15',
        'end_date' => '2029-12-31',
        'status' => 'ACTIVE',
    ]);
    $afterIgnored = get_batch($batchA);
    if ((int) $afterIgnored['course_id'] === $originalCourse && (int) $afterIgnored['course_id'] !== $courseB) {
        pass('N Batch course_id cannot be changed through Edit POST');
    } else {
        fail('N Batch course_id cannot be changed through Edit POST');
    }

    $studentUser = 'cbstu' . strtolower($suffix);
    $studentId = register_student([
        'username' => $studentUser,
        'email' => $studentUser . '@example.test',
        'password' => 'Student123!',
        'registration_no' => 'REG-CB-' . $suffix,
        'first_name' => 'Course',
        'last_name' => 'Batch',
        'phone' => '',
        'date_of_birth' => '',
        'gender' => '',
        'course_id' => $courseA,
        'batch_id' => $batchA,
        'enrollment_date' => '2026-01-20',
        'status' => 'ACTIVE',
        'account_status' => 'ACTIVE',
    ]);
    $student = get_student($studentId);
    $cleanup['student_ids'][] = $studentId;
    $cleanup['user_ids'][] = (int) $student['user_id'];

    $historyBefore = [
        'students' => table_count($pdo, 'students'),
        'schedules' => table_count($pdo, 'schedules'),
        'lecture_sessions' => table_count($pdo, 'lecture_sessions'),
        'attendance_events' => table_count($pdo, 'attendance_events'),
    ];
    update_batch($batchA, [
        'batch_name' => 'INTAKE-' . $suffix,
        'intake_year' => 2026,
        'start_date' => '2026-01-15',
        'end_date' => '2029-12-31',
        'status' => 'COMPLETED',
    ]);
    $studentAfterComplete = get_student($studentId);
    update_batch($batchA, [
        'batch_name' => 'INTAKE-' . $suffix,
        'intake_year' => 2026,
        'start_date' => '2026-01-15',
        'end_date' => '2029-12-31',
        'status' => 'INACTIVE',
    ]);
    $historyAfter = [
        'students' => table_count($pdo, 'students'),
        'schedules' => table_count($pdo, 'schedules'),
        'lecture_sessions' => table_count($pdo, 'lecture_sessions'),
        'attendance_events' => table_count($pdo, 'attendance_events'),
    ];
    if (
        $studentAfterComplete !== null
        && $historyBefore === $historyAfter
        && get_batch($batchA)['status'] === 'INACTIVE'
    ) {
        pass('O Batch can become COMPLETED/INACTIVE without deleting students/history');
    } else {
        fail('O Batch can become COMPLETED/INACTIVE without deleting students/history');
    }

    $deletePaths = [
        $root . '/web/admin/courses/delete.php',
        $root . '/web/admin/batches/delete.php',
        $root . '/web/academic-staff/courses/delete.php',
        $root . '/web/academic-staff/batches/delete.php',
        $root . '/web/public/admin/courses/delete.php',
        $root . '/web/public/admin/batches/delete.php',
        $root . '/web/public/academic-staff/courses/delete.php',
        $root . '/web/public/academic-staff/batches/delete.php',
    ];
    $deleteFiles = array_filter($deletePaths, 'is_file');
    $pageBlob = (string) file_get_contents($root . '/web/shared/pages/courses/index.php')
        . (string) file_get_contents($root . '/web/shared/pages/courses/view.php')
        . (string) file_get_contents($root . '/web/shared/pages/courses/form.php')
        . (string) file_get_contents($root . '/web/shared/pages/batches/index.php')
        . (string) file_get_contents($root . '/web/shared/pages/batches/view.php')
        . (string) file_get_contents($root . '/web/shared/pages/batches/form.php');
    $hasDeleteButton = str_contains($pageBlob, '>Delete<') || str_contains($pageBlob, 'Delete Course') || str_contains($pageBlob, 'Delete Batch');
    if ($deleteFiles === [] && !$hasDeleteButton && !function_exists('delete_course') && !function_exists('delete_batch')) {
        pass('P No hard-delete route/button exists');
    } else {
        fail('P No hard-delete route/button exists');
    }

    $lecturerNav = array_column(management_nav_items('LECTURER'), 'label');
    $noLecturerDir = !is_dir($root . '/web/lecturer/courses') && !is_dir($root . '/web/lecturer/batches')
        && !is_dir($root . '/web/public/lecturer/courses') && !is_dir($root . '/web/public/lecturer/batches');
    if ($noLecturerDir && !in_array('Courses', $lecturerNav, true) && !in_array('Batches', $lecturerNav, true)) {
        pass('Q Lecturer cannot access management pages');
    } else {
        fail('Q Lecturer cannot access management pages');
    }

    $studentNav = array_column(management_nav_items('STUDENT'), 'label');
    $noStudentDir = !is_dir($root . '/web/student/courses') && !is_dir($root . '/web/student/batches')
        && !is_dir($root . '/web/public/student/courses') && !is_dir($root . '/web/public/student/batches');
    if ($noStudentDir && !in_array('Courses', $studentNav, true) && !in_array('Batches', $studentNav, true)) {
        pass('R Student cannot access management pages');
    } else {
        fail('R Student cannot access management pages');
    }

    $batchC = create_batch([
        'course_id' => $courseC,
        'batch_name' => 'CLOSED-C-' . $suffix,
        'intake_year' => 2026,
        'start_date' => '2026-03-01',
        'end_date' => '',
        'status' => 'ACTIVE',
    ]);
    $cleanup['batch_ids'][] = $batchC;
    update_course($courseC, [
        'course_code' => $codeC,
        'course_name' => $sharedName,
        'duration_years' => 3,
        'status' => 'INACTIVE',
    ]);
    $inactiveBatch = create_batch([
        'course_id' => $courseB,
        'batch_name' => 'CLOSED-' . $suffix,
        'intake_year' => 2026,
        'start_date' => '2026-03-01',
        'end_date' => '',
        'status' => 'ACTIVE',
    ]);
    $cleanup['batch_ids'][] = $inactiveBatch;
    update_batch($inactiveBatch, [
        'batch_name' => 'CLOSED-' . $suffix,
        'intake_year' => 2026,
        'start_date' => '2026-03-01',
        'end_date' => '',
        'status' => 'INACTIVE',
    ]);

    $regInactiveCourse = expect_invalid(static function () use ($courseC, $batchC): void {
        register_student([
            'username' => 'x' . bin2hex(random_bytes(2)),
            'email' => 'x' . bin2hex(random_bytes(2)) . '@example.test',
            'password' => 'Student123!',
            'registration_no' => 'x' . bin2hex(random_bytes(2)),
            'first_name' => 'X',
            'last_name' => 'Y',
            'phone' => '',
            'date_of_birth' => '',
            'gender' => '',
            'course_id' => $courseC,
            'batch_id' => $batchC,
            'enrollment_date' => '2026-01-20',
            'status' => 'ACTIVE',
            'account_status' => 'ACTIVE',
        ]);
    });
    $regInactiveBatch = expect_invalid(static function () use ($courseB, $inactiveBatch): void {
        register_student([
            'username' => 'y',
            'email' => 'y@example.test',
            'password' => 'Student123!',
            'registration_no' => 'y',
            'first_name' => 'Y',
            'last_name' => 'Z',
            'phone' => '',
            'date_of_birth' => '',
            'gender' => '',
            'course_id' => $courseB,
            'batch_id' => $inactiveBatch,
            'enrollment_date' => '2026-01-20',
            'status' => 'ACTIVE',
            'account_status' => 'ACTIVE',
        ]);
    });
    if ($regInactiveCourse !== null && $regInactiveBatch !== null) {
        pass('S Student registration does not accept inactive course/batch by POSTed IDs');
    } else {
        fail('S Student registration does not accept inactive course/batch by POSTed IDs');
    }

    update_course($courseA, [
        'course_code' => $codeA,
        'course_name' => $sharedName,
        'duration_years' => 3,
        'status' => 'INACTIVE',
    ]);
    $editCourses = courses_for_selection($courseA);
    $editBatches = batches_for_selection($courseA, $batchA);
    $courseLabels = array_map('course_choice_label', $editCourses);
    $batchLabels = array_column($editBatches, 'label');
    $kept = expect_invalid(static function () use ($studentId, $student, $courseA, $batchA): void {
        update_student($studentId, [
            'email' => $student['email'],
            'registration_no' => $student['registration_no'],
            'first_name' => $student['first_name'],
            'last_name' => $student['last_name'],
            'phone' => '',
            'date_of_birth' => '',
            'gender' => '',
            'course_id' => $courseA,
            'batch_id' => $batchA,
            'enrollment_date' => $student['enrollment_date'],
            'status' => 'ACTIVE',
            'account_status' => 'ACTIVE',
        ]);
    });
    $stillThere = get_student($studentId);
    $hasInactiveCourse = false;
    foreach ($editCourses as $row) {
        if ((int) $row['course_id'] === $courseA) {
            $hasInactiveCourse = true;
        }
    }
    $hasCompletedBatch = false;
    foreach ($editBatches as $row) {
        if ((int) $row['batch_id'] === $batchA) {
            $hasCompletedBatch = true;
        }
    }
    $labelOk = false;
    foreach ($courseLabels as $label) {
        if (str_contains($label, 'Inactive') || str_contains($label, 'Archived')) {
            $labelOk = true;
        }
    }
    foreach ($batchLabels as $label) {
        if (is_string($label) && (str_contains($label, 'Inactive') || str_contains($label, 'Completed'))) {
            $labelOk = true;
        }
    }
    if ($kept === null && $stillThere !== null && $hasInactiveCourse && $hasCompletedBatch && $labelOk) {
        pass('T Existing student on inactive/completed records can still open Student Edit and retain/display current course/batch');
    } else {
        fail('T Existing student on inactive/completed records can still open Student Edit and retain/display current course/batch');
    }

    $seedBatch = $pdo->query("SELECT batch_id, status, course_id, batch_name, intake_year, start_date, end_date FROM batches WHERE batch_name = 'CS-2025-A' LIMIT 1")->fetch();
    $seedCourse = $pdo->query("SELECT course_id, status, course_code, course_name, duration_years FROM courses WHERE course_code = 'BSC-CS' LIMIT 1")->fetch();
    if ($seedBatch !== false && $seedCourse !== false) {
        $uBefore = [
            'schedules' => table_count($pdo, 'schedules'),
            'lecture_sessions' => table_count($pdo, 'lecture_sessions'),
            'attendance_events' => table_count($pdo, 'attendance_events'),
        ];
        try {
            update_course((int) $seedCourse['course_id'], [
                'course_code' => $seedCourse['course_code'],
                'course_name' => $seedCourse['course_name'],
                'duration_years' => (int) $seedCourse['duration_years'],
                'status' => 'INACTIVE',
            ]);
            update_batch((int) $seedBatch['batch_id'], [
                'batch_name' => $seedBatch['batch_name'],
                'intake_year' => (int) $seedBatch['intake_year'],
                'start_date' => (string) $seedBatch['start_date'],
                'end_date' => (string) ($seedBatch['end_date'] ?? ''),
                'status' => 'COMPLETED',
            ]);
            $uMid = [
                'schedules' => table_count($pdo, 'schedules'),
                'lecture_sessions' => table_count($pdo, 'lecture_sessions'),
                'attendance_events' => table_count($pdo, 'attendance_events'),
            ];
        } finally {
            update_course((int) $seedCourse['course_id'], [
                'course_code' => $seedCourse['course_code'],
                'course_name' => $seedCourse['course_name'],
                'duration_years' => (int) $seedCourse['duration_years'],
                'status' => $seedCourse['status'],
            ]);
            update_batch((int) $seedBatch['batch_id'], [
                'batch_name' => $seedBatch['batch_name'],
                'intake_year' => (int) $seedBatch['intake_year'],
                'start_date' => (string) $seedBatch['start_date'],
                'end_date' => (string) ($seedBatch['end_date'] ?? ''),
                'status' => $seedBatch['status'],
            ]);
        }
        if ($uBefore === $uMid) {
            pass('U Existing timetable/session/attendance records survive status changes');
        } else {
            fail('U Existing timetable/session/attendance records survive status changes');
        }
    } else {
        fail('U Existing timetable/session/attendance records survive status changes');
    }

    $unrelatedAfter = [
        'announcements' => table_count($pdo, 'announcements'),
        'campus_events' => table_count($pdo, 'campus_events'),
        'assignments' => table_count($pdo, 'assignments'),
        'marks' => table_count($pdo, 'marks'),
        'attendance_events' => table_count($pdo, 'attendance_events'),
        'schedules' => table_count($pdo, 'schedules'),
        'lecture_sessions' => table_count($pdo, 'lecture_sessions'),
    ];
    if ($unrelatedBefore === $unrelatedAfter) {
        pass('V Coursework/marks/announcements/events unaffected');
    } else {
        fail('V Coursework/marks/announcements/events unaffected');
    }
} catch (Throwable $exception) {
    fail('Unhandled: ' . $exception->getMessage());
}

try {
    foreach ($cleanup['student_ids'] as $id) {
        $pdo->prepare('DELETE FROM students WHERE student_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['user_ids'] as $id) {
        $pdo->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['batch_ids'] as $id) {
        $pdo->prepare('DELETE FROM batches WHERE batch_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['course_ids'] as $id) {
        $pdo->prepare('DELETE FROM courses WHERE course_id = :id')->execute(['id' => $id]);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Cleanup warning: ' . $exception->getMessage() . PHP_EOL);
}

if ($failed) {
    exit(1);
}

echo "All Course/Batch tests passed." . PHP_EOL;
