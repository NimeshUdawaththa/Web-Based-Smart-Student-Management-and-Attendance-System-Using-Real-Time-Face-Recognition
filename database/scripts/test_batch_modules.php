<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — Batch Modules + auto-enrol Tests A–X.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_batch_modules.php
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
    'student_module_ids' => [],
    'student_ids' => [],
    'user_ids' => [],
    'module_ids' => [],
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

function make_module(int $courseId, string $code, string $name, string $status = 'ACTIVE'): int
{
    global $cleanup;
    $id = create_module([
        'course_id' => $courseId,
        'module_code' => $code,
        'module_name' => $name,
        'credits' => 15.0,
        'semester' => 1,
        'status' => $status,
    ]);
    $cleanup['module_ids'][] = $id;

    return $id;
}

function make_student(int $courseId, int $batchId, string $suffix): int
{
    global $cleanup;
    $username = 'bms' . strtolower($suffix);
    $id = register_student([
        'username' => $username,
        'email' => $username . '@example.test',
        'password' => 'Student123!',
        'registration_no' => 'REG-BM-' . $suffix,
        'first_name' => 'Batch',
        'last_name' => 'Module',
        'phone' => '',
        'date_of_birth' => '',
        'gender' => '',
        'course_id' => $courseId,
        'batch_id' => $batchId,
        'enrollment_date' => '2026-01-20',
        'status' => 'ACTIVE',
        'account_status' => 'ACTIVE',
    ]);
    $student = get_student($id);
    $cleanup['student_ids'][] = $id;
    $cleanup['user_ids'][] = (int) $student['user_id'];

    return $id;
}

$pdo = db();
$unrelatedBefore = [
    'attendance_events' => table_count($pdo, 'attendance_events'),
    'assignments' => table_count($pdo, 'assignments'),
    'marks' => table_count($pdo, 'marks'),
    'announcements' => table_count($pdo, 'announcements'),
    'campus_events' => table_count($pdo, 'campus_events'),
];
$enrolmentsBeforeMigrate = table_count($pdo, 'student_modules');

$suffix = strtoupper(bin2hex(random_bytes(3)));

try {
    $createdFirst = ensure_batch_modules_table();
    $tableExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_modules'"
    )->fetchColumn() === 1;
    if ($tableExists) {
        pass('A batch_modules migration succeeds');
    } else {
        fail('A batch_modules migration succeeds');
    }

    $createdSecond = ensure_batch_modules_table();
    $enrolmentsAfterMigrate = table_count($pdo, 'student_modules');
    if ($createdSecond === false && $enrolmentsAfterMigrate === $enrolmentsBeforeMigrate) {
        pass('B migration is idempotent');
        pass('N Existing students are NOT silently backfilled by migration');
    } else {
        fail('B migration is idempotent');
        fail('N Existing students are NOT silently backfilled by migration');
    }

    $courseA = create_course([
        'course_code' => 'TCA' . $suffix,
        'course_name' => 'Test Computer Science ' . $suffix,
        'duration_years' => 3,
        'status' => 'ACTIVE',
    ]);
    $courseB = create_course([
        'course_code' => 'TCB' . $suffix,
        'course_name' => 'Other Course ' . $suffix,
        'duration_years' => 3,
        'status' => 'ACTIVE',
    ]);
    $cleanup['course_ids'][] = $courseA;
    $cleanup['course_ids'][] = $courseB;

    $programming = make_module($courseA, 'PRG' . $suffix, 'Programming');
    $database = make_module($courseA, 'DBS' . $suffix, 'Database Systems');
    $networking = make_module($courseA, 'NET' . $suffix, 'Networking');
    $web = make_module($courseA, 'WEB' . $suffix, 'Web Development');
    $otherModule = make_module($courseB, 'OTH' . $suffix, 'Other Module');
    $inactiveModule = make_module($courseA, 'INA' . $suffix, 'Inactive Module', 'INACTIVE');

    $batchA = create_batch([
        'course_id' => $courseA,
        'batch_name' => 'TEST-2026-A-' . $suffix,
        'intake_year' => 2026,
        'start_date' => '2026-01-15',
        'end_date' => '',
        'status' => 'ACTIVE',
    ]);
    $cleanup['batch_ids'][] = $batchA;

    save_batch_module_selection($batchA, [$programming, $database, $networking]);
    $assigned = list_batch_module_rows($batchA);
    $activeIds = array_map(static fn (array $row): int => (int) $row['module_id'], list_active_assigned_batch_modules($batchA));
    sort($activeIds);
    $expected = [$programming, $database, $networking];
    sort($expected);
    if ($activeIds === $expected) {
        pass('C Assign ACTIVE same-course module to batch');
    } else {
        fail('C Assign ACTIVE same-course module to batch');
    }

    $cross = expect_invalid(static function () use ($batchA, $otherModule, $programming): void {
        save_batch_module_selection($batchA, [$programming, $otherModule]);
    });
    $still = list_active_assigned_batch_modules($batchA);
    if ($cross !== null && count($still) === 3) {
        pass('D Cross-course module assignment rejected');
    } else {
        fail('D Cross-course module assignment rejected');
    }

    $inactiveAssign = expect_invalid(static function () use ($batchA, $programming, $inactiveModule): void {
        save_batch_module_selection($batchA, [$programming, $inactiveModule]);
    });
    if ($inactiveAssign !== null) {
        pass('E Inactive module cannot be newly assigned');
    } else {
        fail('E Inactive module cannot be newly assigned');
    }

    save_batch_module_selection($batchA, [$programming, $database, $networking]);
    $countStatement = $pdo->prepare('SELECT COUNT(*) FROM batch_modules WHERE batch_id = :id');
    $countStatement->execute(['id' => $batchA]);
    $rowCount = (int) $countStatement->fetchColumn();
    if ($rowCount === 3) {
        pass('F Duplicate batch/module row not created');
    } else {
        fail('F Duplicate batch/module row not created');
    }

    $netRow = null;
    foreach (list_batch_module_rows($batchA) as $row) {
        if ((int) $row['module_id'] === $networking) {
            $netRow = $row;
        }
    }
    save_batch_module_selection($batchA, [$programming, $database]);
    $netAfter = null;
    foreach (list_batch_module_rows($batchA) as $row) {
        if ((int) $row['module_id'] === $networking) {
            $netAfter = $row;
        }
    }
    $countStatement->execute(['id' => $batchA]);
    if (
        $netRow !== null
        && $netAfter !== null
        && $netAfter['status'] === 'INACTIVE'
        && (int) $countStatement->fetchColumn() === 3
        && (int) $netAfter['batch_module_id'] === (int) $netRow['batch_module_id']
    ) {
        pass('G Unselect sets batch_module INACTIVE, not DELETE');
    } else {
        fail('G Unselect sets batch_module INACTIVE, not DELETE');
    }

    save_batch_module_selection($batchA, [$programming, $database, $networking]);
    $netRe = null;
    foreach (list_batch_module_rows($batchA) as $row) {
        if ((int) $row['module_id'] === $networking) {
            $netRe = $row;
        }
    }
    if ($netRe !== null && $netRe['status'] === 'ACTIVE' && (int) $netRe['batch_module_id'] === (int) $netRow['batch_module_id']) {
        pass('H Reactivation uses same batch_module row');
    } else {
        fail('H Reactivation uses same batch_module row');
    }

    $studentNew = make_student($courseA, $batchA, $suffix . '1');
    $enrolNew = list_student_module_enrolments($studentNew);
    $enrolledIds = [];
    foreach ($enrolNew as $row) {
        if ($row['status'] === 'ENROLLED') {
            $enrolledIds[] = (int) $row['module_id'];
        }
    }
    sort($enrolledIds);
    if ($enrolledIds === $expected) {
        pass('I New ACTIVE student auto-enrols into ACTIVE batch modules');
    } else {
        fail('I New ACTIVE student auto-enrols into ACTIVE batch modules');
    }

    if (!in_array($web, $enrolledIds, true) && !in_array($otherModule, $enrolledIds, true)) {
        pass('J Student does NOT auto-enrol into other course catalogue modules');
    } else {
        fail('J Student does NOT auto-enrol into other course catalogue modules');
    }

    save_batch_module_selection($batchA, [$programming, $database]);
    $studentK = make_student($courseA, $batchA, $suffix . '2');
    $kIds = [];
    foreach (list_student_module_enrolments($studentK) as $row) {
        if ($row['status'] === 'ENROLLED') {
            $kIds[] = (int) $row['module_id'];
        }
    }
    if (!in_array($networking, $kIds, true) && in_array($programming, $kIds, true)) {
        pass('K Student does NOT auto-enrol into INACTIVE batch_module');
    } else {
        fail('K Student does NOT auto-enrol into INACTIVE batch_module');
    }

    save_batch_module_selection($batchA, [$programming, $database, $web]);
    update_module($web, [
        'course_id' => $courseA,
        'module_code' => 'WEB' . $suffix,
        'module_name' => 'Web Development',
        'credits' => 15.0,
        'semester' => 1,
        'status' => 'INACTIVE',
    ]);
    $studentL = make_student($courseA, $batchA, $suffix . '3');
    $lIds = [];
    foreach (list_student_module_enrolments($studentL) as $row) {
        if ($row['status'] === 'ENROLLED') {
            $lIds[] = (int) $row['module_id'];
        }
    }
    if (!in_array($web, $lIds, true)) {
        pass('L Student does NOT auto-enrol into INACTIVE module');
    } else {
        fail('L Student does NOT auto-enrol into INACTIVE module');
    }
    update_module($web, [
        'course_id' => $courseA,
        'module_code' => 'WEB' . $suffix,
        'module_name' => 'Web Development',
        'credits' => 15.0,
        'semester' => 1,
        'status' => 'ACTIVE',
    ]);

    $completedBatch = create_batch([
        'course_id' => $courseA,
        'batch_name' => 'DONE-' . $suffix,
        'intake_year' => 2024,
        'start_date' => '2024-01-01',
        'end_date' => '2024-12-31',
        'status' => 'ACTIVE',
    ]);
    $cleanup['batch_ids'][] = $completedBatch;
    save_batch_module_selection($completedBatch, [$programming]);
    update_batch($completedBatch, [
        'batch_name' => 'DONE-' . $suffix,
        'intake_year' => 2024,
        'start_date' => '2024-01-01',
        'end_date' => '2024-12-31',
        'status' => 'COMPLETED',
    ]);
    $completedRegister = expect_invalid(static function () use ($courseA, $completedBatch, $suffix): void {
        register_student([
            'username' => 'done' . strtolower($suffix),
            'email' => 'done' . strtolower($suffix) . '@example.test',
            'password' => 'Student123!',
            'registration_no' => 'REG-DONE-' . $suffix,
            'first_name' => 'Done',
            'last_name' => 'Batch',
            'phone' => '',
            'date_of_birth' => '',
            'gender' => '',
            'course_id' => $courseA,
            'batch_id' => $completedBatch,
            'enrollment_date' => '2026-01-20',
            'status' => 'ACTIVE',
            'account_status' => 'ACTIVE',
        ]);
    });
    $syncCompleted = expect_invalid(static function () use ($completedBatch): void {
        sync_batch_module_enrolments($completedBatch);
    });
    if ($completedRegister !== null && $syncCompleted !== null) {
        pass('M Inactive/Completed batch does not auto-enrol');
    } else {
        fail('M Inactive/Completed batch does not auto-enrol');
    }

    save_batch_module_selection($batchA, [$programming, $database]);
    $studentExisting = make_student($courseA, $batchA, $suffix . '4');
    save_batch_module_selection($batchA, [$programming, $database, $web]);
    $beforeSync = [];
    foreach (list_student_module_enrolments($studentExisting) as $row) {
        if ($row['status'] === 'ENROLLED') {
            $beforeSync[] = (int) $row['module_id'];
        }
    }
    $sync = sync_batch_module_enrolments($batchA);
    $afterSync = [];
    foreach (list_student_module_enrolments($studentExisting) as $row) {
        if ($row['status'] === 'ENROLLED') {
            $afterSync[] = (int) $row['module_id'];
        }
    }
    if (!in_array($web, $beforeSync, true) && in_array($web, $afterSync, true) && $sync['enrolments_added'] > 0) {
        pass('O Explicit Sync enrols current ACTIVE batch students');
    } else {
        fail('O Explicit Sync enrols current ACTIVE batch students');
    }

    $countBefore = table_count($pdo, 'student_modules');
    sync_batch_module_enrolments($batchA);
    $countAfter = table_count($pdo, 'student_modules');
    if ($countBefore === $countAfter) {
        pass('P Sync does not create duplicates');
    } else {
        fail('P Sync does not create duplicates');
    }

    $webEnrolment = get_student_module_enrolment($studentExisting, $web);
    drop_student_module((int) $webEnrolment['student_module_id'], $studentExisting);
    sync_batch_module_enrolments($batchA);
    $reactivated = get_student_module_enrolment($studentExisting, $web);
    if ($reactivated !== null && $reactivated['status'] === 'ENROLLED') {
        pass('Q Sync reactivates matching DROPPED/COMPLETED enrolment');
    } else {
        fail('Q Sync reactivates matching DROPPED/COMPLETED enrolment');
    }

    enroll_student_in_module($studentExisting, $networking);
    $extraBefore = get_student_module_enrolment($studentExisting, $networking);
    sync_batch_module_enrolments($batchA);
    $extraAfter = get_student_module_enrolment($studentExisting, $networking);
    if (
        $extraBefore !== null
        && $extraAfter !== null
        && (int) $extraBefore['student_module_id'] === (int) $extraAfter['student_module_id']
        && $extraAfter['status'] === 'ENROLLED'
    ) {
        pass('R Sync does not delete unrelated student_modules');
    } else {
        fail('R Sync does not delete unrelated student_modules');
    }

    save_batch_module_selection($batchA, [$programming, $database, $web]);
    $netEnrolment = get_student_module_enrolment($studentExisting, $networking);
    save_batch_module_selection($batchA, [$programming, $database, $web]);
    save_batch_module_selection($batchA, [$programming, $database]);
    $netStill = get_student_module_enrolment($studentExisting, $networking);
    $netBatch = null;
    foreach (list_batch_module_rows($batchA) as $row) {
        if ((int) $row['module_id'] === $networking) {
            $netBatch = $row;
        }
    }
    if (
        $netEnrolment !== null
        && $netStill !== null
        && $netStill['status'] === 'ENROLLED'
        && $netBatch !== null
        && $netBatch['status'] === 'INACTIVE'
    ) {
        pass('S Removing batch module does not delete/drop existing student_modules');
    } else {
        fail('S Removing batch module does not delete/drop existing student_modules');
    }

    $crossEnrol = expect_invalid(static function () use ($studentExisting, $otherModule): void {
        enroll_student_in_module($studentExisting, $otherModule);
    });
    if ($crossEnrol !== null) {
        pass('T Cross-course student_modules still rejected');
    } else {
        fail('T Cross-course student_modules still rejected');
    }

    $victim = make_student($courseA, $batchA, $suffix . '5');
    $victimEnrol = get_student_module_enrolment($victim, $programming);
    $idor = expect_invalid(static function () use ($victimEnrol, $studentExisting): void {
        drop_student_module((int) $victimEnrol['student_module_id'], $studentExisting);
    });
    $victimStill = get_student_module_enrolment($victim, $programming);
    if ($idor !== null && $victimStill !== null && $victimStill['status'] === 'ENROLLED') {
        pass('U Drop action verifies student_module belongs to current student');
    } else {
        fail('U Drop action verifies student_module belongs to current student');
    }

    $bulkModules = list_active_modules_for_batch($batchA);
    $bulkIds = array_map(static fn (array $row): int => (int) $row['module_id'], $bulkModules);
    $indexPhp = (string) file_get_contents($root . '/web/shared/pages/enrollments/index.php');
    if (
        !in_array($otherModule, $bulkIds, true)
        && in_array($programming, $bulkIds, true)
        && str_contains($indexPhp, 'list_active_modules_for_batch')
    ) {
        pass('V Bulk enrol UI/query only returns modules from selected batch course');
    } else {
        fail('V Bulk enrol UI/query only returns modules from selected batch course');
    }

    $lecturerNav = array_column(management_nav_items('LECTURER'), 'label');
    $studentNav = array_column(management_nav_items('STUDENT'), 'label');
    $batchView = (string) file_get_contents($root . '/web/academic-staff/batches/view.php');
    $adminBatchView = (string) file_get_contents($root . '/web/admin/batches/view.php');
    if (
        !is_dir($root . '/web/lecturer/batches')
        && !is_dir($root . '/web/student/batches')
        && str_contains($batchView, 'require_student_manager()')
        && str_contains($adminBatchView, 'require_admin()')
        && !in_array('Courses', $lecturerNav, true)
    ) {
        pass('W Lecturer/student cannot manage batch modules');
    } else {
        fail('W Lecturer/student cannot manage batch modules');
    }

    $historyAfter = [
        'attendance_events' => table_count($pdo, 'attendance_events'),
        'assignments' => table_count($pdo, 'assignments'),
        'marks' => table_count($pdo, 'marks'),
        'announcements' => table_count($pdo, 'announcements'),
        'campus_events' => table_count($pdo, 'campus_events'),
    ];
    if ($unrelatedBefore === $historyAfter) {
        pass('X Existing attendance/coursework/marks rows remain unchanged');
    } else {
        fail('X Existing attendance/coursework/marks rows remain unchanged');
    }

    unset($createdFirst, $lecturerNav, $studentNav);
} catch (Throwable $exception) {
    fail('Unhandled: ' . $exception->getMessage());
}

try {
    foreach ($cleanup['student_ids'] as $id) {
        $pdo->prepare('DELETE FROM student_modules WHERE student_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['student_ids'] as $id) {
        $pdo->prepare('DELETE FROM students WHERE student_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['user_ids'] as $id) {
        $pdo->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['batch_ids'] as $id) {
        $pdo->prepare('DELETE FROM batch_modules WHERE batch_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['module_ids'] as $id) {
        $pdo->prepare('DELETE FROM course_modules WHERE module_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['course_ids'] as $id) {
        $pdo->prepare('DELETE FROM course_modules WHERE course_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['module_ids'] as $id) {
        $pdo->prepare('DELETE FROM modules WHERE module_id = :id')->execute(['id' => $id]);
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

echo "All Batch Modules tests passed." . PHP_EOL;
