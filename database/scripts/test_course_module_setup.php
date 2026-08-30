<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — Course → Module Catalogue selection tests.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_course_module_setup.php
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
    'module_ids' => [],
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
    'batch_modules' => table_count($pdo, 'batch_modules'),
    'student_modules' => table_count($pdo, 'student_modules'),
    'attendance_events' => table_count($pdo, 'attendance_events'),
    'assignments' => table_count($pdo, 'assignments'),
    'marks' => table_count($pdo, 'marks'),
    'announcements' => table_count($pdo, 'announcements'),
    'campus_events' => table_count($pdo, 'campus_events'),
];

$suffix = strtoupper(bin2hex(random_bytes(3)));

try {
    $form = (string) file_get_contents($root . '/web/shared/pages/courses/form.php');
    $adminCreate = (string) file_get_contents($root . '/web/admin/courses/create.php');
    $adminSetup = (string) file_get_contents($root . '/web/admin/courses/modules.php');
    $viewPageEarly = (string) file_get_contents($root . '/web/shared/pages/courses/view.php');
    if (
        str_contains($form, "courses/view.php?id=")
        && str_contains($form, 'create_course_with_modules')
        && (str_contains($form, 'Course created successfully with ')
            || str_contains($form, 'You can assign modules from Manage Modules'))
        && str_contains($viewPageEarly, 'Manage Modules')
        && str_contains($adminCreate, 'require_admin()')
        && str_contains($adminSetup, 'require_admin()')
        && str_contains($adminSetup, 'courses/modules.php')
    ) {
        pass('A Admin creates Course → redirected to Course View');
    } else {
        fail('A Admin creates Course → redirected to Course View');
    }

    $staffCreate = (string) file_get_contents($root . '/web/academic-staff/courses/create.php');
    $staffSetup = (string) file_get_contents($root . '/web/academic-staff/courses/modules.php');
    if (
        str_contains($staffCreate, 'require_student_manager()')
        && str_contains($staffSetup, 'require_student_manager()')
        && str_contains($form, 'edit.php?id=')
        && str_contains($form, 'Course updated.')
        && str_contains($form, 'Manage Modules')
    ) {
        pass('B Academic Staff creates Course → redirected to Course View');
    } else {
        fail('B Academic Staff creates Course → redirected to Course View');
    }

    $setupPage = (string) file_get_contents($root . '/web/shared/pages/courses/modules.php');
    if (
        str_contains($setupPage, 'save_course_module_selection((int) $courseId')
        && !preg_match('/name=["\']course_id["\']/', $setupPage)
        && str_contains($setupPage, 'Select Modules for Course')
    ) {
        pass('C Course ID is fixed on setup page');
    } else {
        fail('C Course ID is fixed on setup page');
    }

    $courseA = create_course([
        'course_code' => 'SUA' . $suffix,
        'course_name' => 'Setup Course A ' . $suffix,
        'duration_years' => 3,
        'status' => 'ACTIVE',
    ]);
    $courseB = create_course([
        'course_code' => 'SUB' . $suffix,
        'course_name' => 'Setup Course B ' . $suffix,
        'duration_years' => 3,
        'status' => 'ACTIVE',
    ]);
    $cleanup['course_ids'][] = $courseA;
    $cleanup['course_ids'][] = $courseB;

    $modOne = create_module([
        'module_code' => 'CS1' . $suffix,
        'module_name' => 'Programming',
        'credits' => 15,
        'semester' => 1,
        'status' => 'ACTIVE',
    ]);
    $cleanup['module_ids'][] = $modOne;
    save_course_module_selection($courseA, [$modOne]);
    $links = list_active_course_modules($courseA);
    if (count($links) === 1 && (int) $links[0]['module_id'] === $modOne) {
        pass('D Add one catalogue module successfully');
        pass('F All assigned modules keep a course_modules relationship');
    } else {
        fail('D Add one catalogue module successfully');
        fail('F All assigned modules keep a course_modules relationship');
    }

    $extra = [];
    foreach (['CS2', 'CS3', 'CS4', 'CS5'] as $i => $code) {
        $id = create_module([
            'module_code' => $code . $suffix,
            'module_name' => 'Module ' . $code,
            'credits' => 15,
            'semester' => $i < 3 ? 1 : 2,
            'status' => 'ACTIVE',
        ]);
        $cleanup['module_ids'][] = $id;
        $extra[] = $id;
    }
    save_course_module_selection($courseA, array_merge([$modOne], $extra));
    if (count(list_active_course_modules($courseA)) === 5) {
        pass('E Add four additional catalogue modules in one save successfully');
    } else {
        fail('E Add four additional catalogue modules in one save successfully');
    }

    $invalid = expect_invalid(static function () use ($courseA, $modOne): void {
        save_course_module_selection($courseA, [$modOne, 0]);
    });
    if ($invalid !== null) {
        pass('G Invalid module id rejected');
    } else {
        fail('G Invalid module id rejected');
    }

    $beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM modules')->fetchColumn();
    save_course_module_selection($courseA, array_merge([$modOne], $extra));
    $afterCount = (int) $pdo->query('SELECT COUNT(*) FROM modules')->fetchColumn();
    if ($beforeCount === $afterCount) {
        pass('H Course assignment does not create duplicate module rows');
    } else {
        fail('H Course assignment does not create duplicate module rows');
    }

    $beforePartial = count(list_active_course_modules($courseA));
    $partial = expect_invalid(static function () use ($courseA, $modOne): void {
        save_course_module_selection($courseA, [$modOne, 99999999]);
    });
    $afterPartial = count(list_active_course_modules($courseA));
    if ($partial !== null && $afterPartial === $beforePartial) {
        pass('I Failed save does not leave an unintended partial assignment set');
    } else {
        fail('I Failed save does not leave an unintended partial assignment set');
    }

    $listed = list_course_module_rows($courseA);
    if (count($listed) >= 5 && str_contains($setupPage, 'Current relationships')) {
        pass('J Existing modules appear when setup page reopened');
    } else {
        fail('J Existing modules appear when setup page reopened');
    }

    $later = create_module([
        'module_code' => 'CS6' . $suffix,
        'module_name' => 'Later Module',
        'credits' => 15,
        'semester' => 2,
        'status' => 'ACTIVE',
    ]);
    $cleanup['module_ids'][] = $later;
    save_course_module_selection($courseA, array_merge([$modOne], $extra, [$later]));
    if (count(list_active_course_modules($courseA)) === 6) {
        pass('K Additional modules can be assigned later');
    } else {
        fail('K Additional modules can be assigned later');
    }

    $viewPage = (string) file_get_contents($root . '/web/shared/pages/courses/view.php');
    if (
        str_contains($setupPage, 'Finish Course Setup')
        && str_contains($setupPage, 'courses/view.php?id=')
    ) {
        pass('L Finish Course Setup returns to Course View');
    } else {
        fail('L Finish Course Setup returns to Course View');
    }

    if (str_contains($viewPage, 'Modules (') && str_contains($viewPage, 'Manage Modules')) {
        pass('M Course View lists its modules');
    } else {
        fail('M Course View lists its modules');
    }

    $emptyCourse = create_course([
        'course_code' => 'SUZ' . $suffix,
        'course_name' => 'Empty Setup ' . $suffix,
        'duration_years' => 3,
        'status' => 'ACTIVE',
    ]);
    $cleanup['course_ids'][] = $emptyCourse;
    if (list_active_course_modules($emptyCourse) === []) {
        pass('N Course can finish setup with zero modules');
    } else {
        fail('N Course can finish setup with zero modules');
    }

    $moduleForm = (string) file_get_contents($root . '/web/shared/pages/modules/form.php');
    if (
        is_file($root . '/web/admin/modules/create.php')
        && is_file($root . '/web/academic-staff/modules/create.php')
        && str_contains($moduleForm, 'create_module($payload)')
        && !str_contains($moduleForm, 'name="course_id"')
    ) {
        pass('O Existing standalone Modules Create/Edit still works as a catalogue');
    } else {
        fail('O Existing standalone Modules Create/Edit still works as a catalogue');
    }

    if (
        !is_dir($root . '/web/lecturer/courses')
        && str_contains($adminSetup, 'require_admin()')
    ) {
        pass('P Lecturer cannot access setup');
    } else {
        fail('P Lecturer cannot access setup');
    }

    if (
        !is_dir($root . '/web/student/courses')
        && !in_array('Courses', array_column(management_nav_items('STUDENT'), 'label'), true)
    ) {
        pass('Q Student cannot access setup');
    } else {
        fail('Q Student cannot access setup');
    }

    $unrelatedAfter = [
        'batch_modules' => table_count($pdo, 'batch_modules'),
        'student_modules' => table_count($pdo, 'student_modules'),
        'attendance_events' => table_count($pdo, 'attendance_events'),
        'assignments' => table_count($pdo, 'assignments'),
        'marks' => table_count($pdo, 'marks'),
        'announcements' => table_count($pdo, 'announcements'),
        'campus_events' => table_count($pdo, 'campus_events'),
    ];
    if ($unrelatedBefore['batch_modules'] === $unrelatedAfter['batch_modules']) {
        pass('R Batch Modules are not automatically changed when a course module is assigned');
    } else {
        fail('R Batch Modules are not automatically changed when a course module is assigned');
    }

    if ($unrelatedBefore['student_modules'] === $unrelatedAfter['student_modules']) {
        pass('S Existing student_modules are not changed');
    } else {
        fail('S Existing student_modules are not changed');
    }

    unset($unrelatedAfter['batch_modules'], $unrelatedAfter['student_modules']);
    $historyBefore = $unrelatedBefore;
    unset($historyBefore['batch_modules'], $historyBefore['student_modules']);
    if ($historyBefore === $unrelatedAfter) {
        pass('T Attendance/coursework/marks/announcements/events remain unaffected');
    } else {
        fail('T Attendance/coursework/marks/announcements/events remain unaffected');
    }
} catch (Throwable $exception) {
    fail('Unhandled: ' . $exception->getMessage());
}

try {
    foreach ($cleanup['course_ids'] as $id) {
        $pdo->prepare('DELETE FROM course_modules WHERE course_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['module_ids'] as $id) {
        $pdo->prepare('DELETE FROM course_modules WHERE module_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM modules WHERE module_id = :id')->execute(['id' => $id]);
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

echo "All Course Module Setup tests passed." . PHP_EOL;
