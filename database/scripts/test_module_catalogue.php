<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — Module Catalogue → Course Modules Tests A–W.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_module_catalogue.php
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
require_once dirname(__DIR__, 2) . '/web/shared/includes/assignments.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/marks.php';

$failed = false;
$root = dirname(__DIR__, 2);
$cleanup = [
    'mark_ids' => [],
    'assignment_ids' => [],
    'session_ids' => [],
    'schedule_ids' => [],
    'module_lecturer_ids' => [],
    'student_module_ids' => [],
    'student_ids' => [],
    'lecturer_ids' => [],
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

$pdo = db();
$cisBefore = $pdo->query(
    "SELECT m.module_id, m.module_code, m.module_name
     FROM modules m
     WHERE m.module_code = 'CIS6008'
     LIMIT 1"
)->fetch();
$cisEnrolmentsBefore = (int) $pdo->query(
    "SELECT COUNT(*) FROM student_modules sm
     INNER JOIN modules m ON m.module_id = sm.module_id
     WHERE m.module_code = 'CIS6008'"
)->fetchColumn();
$unrelatedBefore = [
    'attendance_events' => table_count($pdo, 'attendance_events'),
    'announcements' => table_count($pdo, 'announcements'),
    'campus_events' => table_count($pdo, 'campus_events'),
];

$suffix = strtoupper(bin2hex(random_bytes(3)));

try {
    $moduleCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM modules')->fetchColumn();
    $createdFirst = ensure_course_modules_table();
    $backfillFirst = backfill_course_modules_from_legacy_course_id();
    $moduleCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM modules')->fetchColumn();
    $linked = (int) $pdo->query('SELECT COUNT(*) FROM course_modules')->fetchColumn();
    $orphans = (int) $pdo->query(
        "SELECT COUNT(*) FROM batch_modules bm
         INNER JOIN batches b ON b.batch_id = bm.batch_id
         WHERE NOT EXISTS (
           SELECT 1 FROM course_modules cm
           WHERE cm.module_id = bm.module_id AND cm.course_id = b.course_id
         )"
    )->fetchColumn();
    if ($moduleCountBefore === $moduleCountAfter && $linked >= $moduleCountAfter && $orphans === 0) {
        pass('A migration/backfill preserves all modules');
        pass('C no orphan batch_modules');
    } else {
        fail('A migration/backfill preserves all modules');
        fail('C no orphan batch_modules');
    }
    unset($createdFirst, $backfillFirst);

    $createdSecond = ensure_course_modules_table();
    $backfillSecond = backfill_course_modules_from_legacy_course_id();
    $linkedSecond = (int) $pdo->query('SELECT COUNT(*) FROM course_modules')->fetchColumn();
    if ($createdSecond === false && $backfillSecond === 0 && $linkedSecond === $linked) {
        pass('B migration is idempotent');
    } else {
        fail('B migration is idempotent');
    }

    ensure_modules_catalogue_ready();
    $dupes = duplicate_module_codes();
    $uniqueExists = schema_index_exists('modules', 'uq_modules_code');
    $dupCreate = expect_invalid(static function () use ($suffix): void {
        create_module([
            'module_code' => 'CIS6008',
            'module_name' => 'Duplicate code',
            'credits' => 15,
            'semester' => 1,
            'status' => 'ACTIVE',
        ]);
    });
    if ($dupes === [] && $uniqueExists && $dupCreate !== null) {
        pass('D module code globally unique');
    } else {
        fail('D module code globally unique');
    }

    $courseSe = create_course([
        'course_code' => 'SE' . $suffix,
        'course_name' => 'BSc Software Engineering ' . $suffix,
        'duration_years' => 3,
        'status' => 'ACTIVE',
    ]);
    $courseCs = create_course([
        'course_code' => 'CS' . $suffix,
        'course_name' => 'BSc Computer Science ' . $suffix,
        'duration_years' => 3,
        'status' => 'ACTIVE',
    ]);
    $cleanup['course_ids'][] = $courseSe;
    $cleanup['course_ids'][] = $courseCs;

    $se101 = create_module([
        'module_code' => 'SE1' . $suffix,
        'module_name' => 'Programming Fundamentals',
        'credits' => 15,
        'semester' => 1,
        'status' => 'ACTIVE',
    ]);
    $se102 = create_module([
        'module_code' => 'SE2' . $suffix,
        'module_name' => 'Database Systems',
        'credits' => 15,
        'semester' => 1,
        'status' => 'ACTIVE',
    ]);
    $se103 = create_module([
        'module_code' => 'SE3' . $suffix,
        'module_name' => 'Web Development',
        'credits' => 15,
        'semester' => 1,
        'status' => 'ACTIVE',
    ]);
    $cleanup['module_ids'][] = $se101;
    $cleanup['module_ids'][] = $se102;
    $cleanup['module_ids'][] = $se103;

    save_course_module_selection($courseSe, [$se101, $se102, $se103]);
    save_course_module_selection($courseCs, [$se101]);
    $codeCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM modules WHERE module_code = 'SE1{$suffix}'"
    )->fetchColumn();
    $seLinks = (int) $pdo->query(
        'SELECT COUNT(*) FROM course_modules WHERE module_id = ' . (int) $se101 . " AND status = 'ACTIVE'"
    )->fetchColumn();
    if ($codeCount === 1 && $seLinks === 2 && course_has_module($courseSe, $se101) && course_has_module($courseCs, $se101)) {
        pass('E one module can belong to two courses');
        pass('H no duplicate module row is created by course assignment');
    } else {
        fail('E one module can belong to two courses');
        fail('H no duplicate module row is created by course assignment');
    }

    $setupPage = (string) file_get_contents($root . '/web/shared/pages/courses/modules.php');
    $formPage = (string) file_get_contents($root . '/web/shared/pages/courses/form.php');
    $viewPage = (string) file_get_contents($root . '/web/shared/pages/courses/view.php');
    if (
        str_contains($formPage, 'create_course_with_modules')
        && str_contains($formPage, 'module_ids[]')
        && str_contains($formPage, 'courses/view.php?id=')
        && str_contains($formPage, 'Manage Modules')
        && str_contains($viewPage, 'courses/modules.php?id=')
        && str_contains($setupPage, 'module_ids[]')
        && str_contains($setupPage, 'list_modules_for_course_assignment')
    ) {
        pass('F Course Create/View can reach existing catalogue module assignment');
    } else {
        fail('F Course Create/View can reach existing catalogue module assignment');
    }

    save_course_module_selection($courseCs, []);
    $inactiveLink = null;
    foreach (list_course_module_rows($courseCs) as $row) {
        if ((int) $row['module_id'] === $se101) {
            $inactiveLink = $row;
        }
    }
    save_course_module_selection($courseCs, [$se101]);
    $reactivated = null;
    foreach (list_course_module_rows($courseCs) as $row) {
        if ((int) $row['module_id'] === $se101) {
            $reactivated = $row;
        }
    }
    if (
        $inactiveLink !== null
        && $inactiveLink['status'] === 'INACTIVE'
        && $reactivated !== null
        && $reactivated['status'] === 'ACTIVE'
        && (int) $inactiveLink['course_module_id'] === (int) $reactivated['course_module_id']
    ) {
        pass('G Course Edit can activate/inactivate module relationships');
    } else {
        fail('G Course Edit can activate/inactivate module relationships');
    }

    $batchSe = create_batch([
        'course_id' => $courseSe,
        'batch_name' => 'SE-2026-' . $suffix,
        'intake_year' => 2026,
        'start_date' => '2026-01-15',
        'end_date' => '',
        'status' => 'ACTIVE',
    ]);
    $batchCs = create_batch([
        'course_id' => $courseCs,
        'batch_name' => 'CS-2026-' . $suffix,
        'intake_year' => 2026,
        'start_date' => '2026-01-15',
        'end_date' => '',
        'status' => 'ACTIVE',
    ]);
    $cleanup['batch_ids'][] = $batchSe;
    $cleanup['batch_ids'][] = $batchCs;

    $visible = list_modules_for_batch_assignment($batchSe);
    $visibleIds = array_map(static fn (array $row): int => (int) $row['module_id'], $visible);
    sort($visibleIds);
    $expectedVisible = [$se101, $se102, $se103];
    sort($expectedVisible);
    if ($visibleIds === $expectedVisible) {
        pass('I Batch View shows only its course_modules');
    } else {
        fail('I Batch View shows only its course_modules');
    }

    $cross = expect_invalid(static function () use ($batchCs, $se102): void {
        save_batch_module_selection($batchCs, [$se102]);
    });
    if ($cross !== null && list_active_assigned_batch_modules($batchCs) === []) {
        pass('J cross-course Batch Module POST rejected');
    } else {
        fail('J cross-course Batch Module POST rejected');
    }

    save_batch_module_selection($batchSe, [$se101, $se102]);
    $username = 'mcat' . strtolower($suffix);
    $studentId = register_student([
        'username' => $username,
        'email' => $username . '@example.test',
        'password' => 'Student123!',
        'registration_no' => 'REG-MC-' . $suffix,
        'first_name' => 'Catalogue',
        'last_name' => 'Student',
        'phone' => '',
        'date_of_birth' => '',
        'gender' => '',
        'course_id' => $courseSe,
        'batch_id' => $batchSe,
        'enrollment_date' => '2026-01-20',
        'status' => 'ACTIVE',
        'account_status' => 'ACTIVE',
    ]);
    $student = get_student($studentId);
    $cleanup['student_ids'][] = $studentId;
    $cleanup['user_ids'][] = (int) $student['user_id'];
    $enrolledIds = [];
    foreach (list_student_module_enrolments($studentId) as $row) {
        if ($row['status'] === 'ENROLLED') {
            $enrolledIds[] = (int) $row['module_id'];
        }
    }
    sort($enrolledIds);
    if ($enrolledIds === [$se101, $se102]) {
        pass('K new student auto-enrols Batch Modules only');
    } else {
        fail('K new student auto-enrols Batch Modules only');
    }
    if (!in_array($se103, $enrolledIds, true)) {
        pass('L student does NOT auto-enrol all Course Modules');
    } else {
        fail('L student does NOT auto-enrol all Course Modules');
    }

    save_batch_module_selection($batchSe, [$se101, $se102, $se103]);
    $sync = sync_batch_module_enrolments($batchSe);
    $afterSync = [];
    foreach (list_student_module_enrolments($studentId) as $row) {
        if ($row['status'] === 'ENROLLED') {
            $afterSync[] = (int) $row['module_id'];
        }
    }
    if (in_array($se103, $afterSync, true) && $sync['enrolments_added'] > 0) {
        pass('M Sync Batch Modules still works');
    } else {
        fail('M Sync Batch Modules still works');
    }

    $enrolBeforeUnassign = get_student_module_enrolment($studentId, $se103);
    save_course_module_selection($courseSe, [$se101, $se102]);
    $enrolAfterUnassign = get_student_module_enrolment($studentId, $se103);
    $batchStill = get_student_module_enrolment($studentId, $se103);
    $historyAfterUnassign = [
        'attendance_events' => table_count($pdo, 'attendance_events'),
        'announcements' => table_count($pdo, 'announcements'),
        'campus_events' => table_count($pdo, 'campus_events'),
    ];
    $se103Course = null;
    foreach (list_course_module_rows($courseSe) as $row) {
        if ((int) $row['module_id'] === $se103) {
            $se103Course = $row;
        }
    }
    if (
        $enrolBeforeUnassign !== null
        && $enrolAfterUnassign !== null
        && $enrolAfterUnassign['status'] === 'ENROLLED'
        && $se103Course !== null
        && $se103Course['status'] === 'INACTIVE'
        && $historyAfterUnassign === $unrelatedBefore
    ) {
        pass('N removing Course Module does not destroy existing student enrolment/history');
    } else {
        fail('N removing Course Module does not destroy existing student enrolment/history');
    }

    $lecturerId = create_lecturer([
        'username' => 'lcat' . strtolower($suffix),
        'email' => 'lcat' . strtolower($suffix) . '@example.test',
        'password' => 'Lecturer123!',
        'staff_no' => 'LC' . $suffix,
        'first_name' => 'Cat',
        'last_name' => 'Lecturer',
        'phone' => '',
        'department' => 'Computing',
        'status' => 'ACTIVE',
        'account_status' => 'ACTIVE',
    ]);
    $lecturer = get_lecturer($lecturerId);
    $cleanup['lecturer_ids'][] = $lecturerId;
    $cleanup['user_ids'][] = (int) $lecturer['user_id'];
    assign_lecturer_to_module($se101, $lecturerId);
    $assignmentRow = $pdo->query(
        'SELECT module_lecturer_id FROM module_lecturers
         WHERE module_id = ' . (int) $se101 . ' AND lecturer_id = ' . (int) $lecturerId
    )->fetch();
    $cleanup['module_lecturer_ids'][] = (int) $assignmentRow['module_lecturer_id'];
    if (lecturer_is_assigned_to_module($lecturerId, $se101)) {
        pass('Q lecturer assignment still works');
    } else {
        fail('Q lecturer assignment still works');
    }

    save_course_module_selection($courseSe, [$se101, $se102, $se103]);
    $timetableReject = validate_schedule_payload([
        'module_id' => $se103,
        'lecturer_id' => $lecturerId,
        'batch_id' => $batchCs,
        'day_of_week' => 'MONDAY',
        'start_time' => '09:00',
        'end_time' => '11:00',
        'status' => 'ACTIVE',
        'room' => 'R-' . $suffix,
    ]);
    $sessionReject = expect_invalid(static function () use ($se102, $lecturerId, $batchCs): void {
        create_lecture_session([
            'module_id' => $se102,
            'lecturer_id' => $lecturerId,
            'batch_id' => $batchCs,
            'session_date' => app_today(),
            'scheduled_start' => '09:00',
            'scheduled_end' => '11:00',
            'late_after_minutes' => 15,
            'room' => 'X',
        ]);
    });
    if ($timetableReject !== [] && $sessionReject !== null) {
        pass('O timetable rejects module not assigned to batch\'s course');
        pass('P lecture session same integrity rule');
    } else {
        fail('O timetable rejects module not assigned to batch\'s course');
        fail('P lecture session same integrity rule');
    }

    $scheduleId = create_schedule([
        'module_id' => $se101,
        'lecturer_id' => $lecturerId,
        'batch_id' => $batchSe,
        'day_of_week' => 'SUNDAY',
        'start_time' => '06:00',
        'end_time' => '07:00',
        'status' => 'ACTIVE',
        'room' => 'LAB-' . $suffix,
    ]);
    $cleanup['schedule_ids'][] = $scheduleId;
    $sessionId = create_lecture_session([
        'module_id' => $se101,
        'lecturer_id' => $lecturerId,
        'batch_id' => $batchSe,
        'session_date' => app_today(),
        'scheduled_start' => '14:00',
        'scheduled_end' => '16:00',
        'late_after_minutes' => 15,
        'room' => 'LAB-' . $suffix,
    ]);
    $cleanup['session_ids'][] = $sessionId;
    if (get_schedule($scheduleId) !== null && get_lecture_session($sessionId) !== null) {
        pass('T attendance/session regression still works');
    } else {
        fail('T attendance/session regression still works');
    }

    $assignmentId = create_coursework_assignment($lecturerId, [
        'module_id' => $se101,
        'title' => 'Catalogue coursework ' . $suffix,
        'description' => 'Shared module assignment',
        'file_path' => null,
        'due_date' => app_today() . ' 23:59:00',
        'max_marks' => 100,
        'status' => 'PUBLISHED',
    ]);
    $cleanup['assignment_ids'][] = $assignmentId;
    $assignment = get_coursework_assignment($assignmentId);
    if ($assignment !== null && (int) $assignment['module_id'] === $se101) {
        pass('R coursework/assignments still work');
    } else {
        fail('R coursework/assignments still work');
    }

    $markResult = save_module_assessment_results(
        $lecturerId,
        $se101,
        'Quiz',
        'Catalogue Quiz ' . $suffix,
        20,
        [$studentId => ['marks' => 18, 'remarks' => '']]
    );
    $studentMarks = list_marks_for_student($studentId);
    $hasMark = false;
    foreach ($studentMarks as $row) {
        if ((int) $row['module_id'] === $se101) {
            $hasMark = true;
            $cleanup['mark_ids'][] = (int) $row['mark_id'];
        }
    }
    if ($hasMark && ($markResult['inserted'] ?? 0) > 0) {
        pass('S marks/My Results still work');
    } else {
        fail('S marks/My Results still work');
    }

    if ($cisBefore !== false) {
        $cisNow = get_module((int) $cisBefore['module_id']);
        $cisLink = (int) $pdo->query(
            'SELECT COUNT(*) FROM course_modules WHERE module_id = ' . (int) $cisBefore['module_id']
        )->fetchColumn();
        $cisEnrolmentsNow = (int) $pdo->query(
            'SELECT COUNT(*) FROM student_modules WHERE module_id = ' . (int) $cisBefore['module_id']
        )->fetchColumn();
        if ($cisNow !== null && $cisLink > 0 && $cisEnrolmentsNow === $cisEnrolmentsBefore) {
            pass('U existing CIS6008 data still works');
        } else {
            fail('U existing CIS6008 data still works');
        }
    } else {
        pass('U existing CIS6008 data still works');
    }

    $idorCourse = expect_invalid(static function () use ($courseCs): void {
        save_course_module_selection($courseCs, [99999999]);
    });
    $csOnly = create_module([
        'module_code' => 'CSO' . $suffix,
        'module_name' => 'CS Only Module',
        'credits' => 15,
        'semester' => 1,
        'status' => 'ACTIVE',
    ]);
    $cleanup['module_ids'][] = $csOnly;
    save_course_module_selection($courseCs, [$se101, $csOnly]);
    $idorEnrol = expect_invalid(static function () use ($studentId, $csOnly): void {
        enroll_student_in_module($studentId, $csOnly);
    });
    $victim = register_student([
        'username' => 'mcatv' . strtolower($suffix),
        'email' => 'mcatv' . strtolower($suffix) . '@example.test',
        'password' => 'Student123!',
        'registration_no' => 'REG-MCV-' . $suffix,
        'first_name' => 'Victim',
        'last_name' => 'Student',
        'phone' => '',
        'date_of_birth' => '',
        'gender' => '',
        'course_id' => $courseSe,
        'batch_id' => $batchSe,
        'enrollment_date' => '2026-01-20',
        'status' => 'ACTIVE',
        'account_status' => 'ACTIVE',
    ]);
    $victimRow = get_student($victim);
    $cleanup['student_ids'][] = $victim;
    $cleanup['user_ids'][] = (int) $victimRow['user_id'];
    $victimEnrol = get_student_module_enrolment($victim, $se101);
    $idorDrop = expect_invalid(static function () use ($victimEnrol, $studentId): void {
        drop_student_module((int) $victimEnrol['student_module_id'], $studentId);
    });
    $moduleForm = (string) file_get_contents($root . '/web/shared/pages/modules/form.php');
    $courseModulesPage = (string) file_get_contents($root . '/web/shared/pages/courses/modules.php');
    if (
        $idorCourse !== null
        && $idorEnrol !== null
        && $idorDrop !== null
        && !str_contains($moduleForm, 'name="course_id"')
        && !preg_match('/name=["\']course_id["\']/', $courseModulesPage)
    ) {
        pass('V authorization/IDOR tests');
    } else {
        fail('V authorization/IDOR tests');
    }

    drop_modules_course_id_column();
    $meta = modules_legacy_course_id_meta(true);
    $runtimeHits = [];
    foreach ([
        $root . '/web/shared/includes/academic.php',
        $root . '/web/shared/includes/management.php',
        $root . '/web/shared/includes/assignments.php',
        $root . '/web/shared/pages/modules/form.php',
        $root . '/web/shared/pages/modules/index.php',
        $root . '/web/shared/pages/courses/modules.php',
        $root . '/web/shared/pages/schedules/form.php',
        $root . '/web/shared/pages/sessions/create.php',
        $root . '/web/public/assets/js/schedule-form.js',
    ] as $path) {
        $contents = (string) file_get_contents($path);
        if (preg_match('/\bm\.course_id\b|\bmodules\.course_id\b|\bmodule\[.course_id.\]/', $contents)) {
            if (!str_contains($path, 'academic.php')) {
                $runtimeHits[] = $path;
            } elseif (preg_match('/JOIN courses c ON c\.course_id = m\.course_id|AND m\.course_id =|module\[.course_id.\]/', $contents)) {
                $runtimeHits[] = $path;
            }
        }
    }
    $stillWorks = get_module($se101) !== null && course_has_module($courseSe, $se101);
    if ($meta['exists'] === false && $runtimeHits === [] && $stillWorks) {
        pass('W no remaining runtime dependency on modules.course_id after final migration');
    } else {
        fail('W no remaining runtime dependency on modules.course_id after final migration');
        if ($runtimeHits !== []) {
            fwrite(STDERR, 'Remaining hits: ' . implode(', ', $runtimeHits) . PHP_EOL);
        }
    }
} catch (Throwable $exception) {
    fail('Unhandled: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());
}

try {
    foreach ($cleanup['mark_ids'] as $id) {
        $pdo->prepare('DELETE FROM marks WHERE mark_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['assignment_ids'] as $id) {
        $pdo->prepare('DELETE FROM assignments WHERE assignment_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['session_ids'] as $id) {
        $pdo->prepare('DELETE FROM lecture_sessions WHERE session_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['schedule_ids'] as $id) {
        $pdo->prepare('DELETE FROM schedules WHERE schedule_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['module_lecturer_ids'] as $id) {
        $pdo->prepare('DELETE FROM module_lecturers WHERE module_lecturer_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['student_ids'] as $id) {
        $pdo->prepare('DELETE FROM student_modules WHERE student_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM students WHERE student_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['lecturer_ids'] as $id) {
        $pdo->prepare('DELETE FROM lecturers WHERE lecturer_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['user_ids'] as $id) {
        $pdo->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['batch_ids'] as $id) {
        $pdo->prepare('DELETE FROM batch_modules WHERE batch_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['course_ids'] as $id) {
        $pdo->prepare('DELETE FROM course_modules WHERE course_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['module_ids'] as $id) {
        $pdo->prepare('DELETE FROM course_modules WHERE module_id = :id')->execute(['id' => $id]);
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

echo "All Module Catalogue tests passed." . PHP_EOL;
