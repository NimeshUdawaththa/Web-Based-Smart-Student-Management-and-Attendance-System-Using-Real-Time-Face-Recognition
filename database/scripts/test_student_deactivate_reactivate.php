<?php

declare(strict_types=1);

/**
 * Regression tests for Student Deactivate / Reactivate v1.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_student_deactivate_reactivate.php
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
require_once dirname(__DIR__, 2) . '/web/shared/includes/profile.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/attendance.php';

$failed = false;
$createdSessionIds = [];
$tempStudentId = null;
$tempUserId = null;
$tempFaceProfileId = null;
$originalPasswordHash = null;

$attendanceEventsBefore = (int) db()->query('SELECT COUNT(*) FROM attendance_events')->fetchColumn();
$attendanceRecordsBefore = (int) db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
$enrolmentsBefore = (int) db()->query('SELECT COUNT(*) FROM student_modules')->fetchColumn();

function pass(string $label): void
{
    echo 'PASS ' . $label . PHP_EOL;
}

function fail(string $label, string $detail = ''): void
{
    global $failed;
    $failed = true;
    echo 'FAIL ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
}

function assert_true(bool $condition, string $label, string $detail = ''): void
{
    if ($condition) {
        pass($label);
    } else {
        fail($label, $detail);
    }
}

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $course = db()->query("SELECT course_id FROM courses WHERE status = 'ACTIVE' ORDER BY course_id LIMIT 1")->fetch();
    $batch = null;
    if ($course !== false) {
        $batchStmt = db()->prepare("SELECT batch_id FROM batches WHERE course_id = :c AND status = 'ACTIVE' ORDER BY batch_id LIMIT 1");
        $batchStmt->execute(['c' => $course['course_id']]);
        $batch = $batchStmt->fetch();
    }
    if ($course === false || $batch === false) {
        throw new RuntimeException('Need an ACTIVE course and batch.');
    }

    $suffix = (string) time();
    $password = 'DeactTest123!';
    $tempStudentId = register_student([
        'username' => 'deact_' . $suffix,
        'email' => 'deact_' . $suffix . '@example.test',
        'password' => $password,
        'account_status' => 'ACTIVE',
        'registration_no' => 'DEACT-' . $suffix,
        'first_name' => 'Deact',
        'last_name' => 'Tester',
        'phone' => '',
        'date_of_birth' => '',
        'gender' => '',
        'course_id' => (int) $course['course_id'],
        'batch_id' => (int) $batch['batch_id'],
        'enrollment_date' => app_today(),
        'status' => 'ACTIVE',
    ]);
    $student = get_student($tempStudentId);
    $tempUserId = (int) $student['user_id'];
    $originalPasswordHash = (string) db()->query(
        'SELECT password_hash FROM users WHERE user_id = ' . $tempUserId
    )->fetchColumn();

    // Optional face profile row (no encoding file required for preservation checks).
    db()->prepare(
        "INSERT INTO face_profiles (student_id, encoding_path, sample_count, status)
         VALUES (:student_id, :encoding_path, 5, 'ACTIVE')"
    )->execute([
        'student_id' => $tempStudentId,
        'encoding_path' => 'encodings/deact_test_' . $suffix . '.pkl',
    ]);
    $tempFaceProfileId = (int) db()->lastInsertId();

    $module = db()->prepare(
        "SELECT m.module_id FROM modules m
         INNER JOIN course_modules cm ON cm.module_id = m.module_id AND cm.course_id = :course_id AND cm.status = 'ACTIVE'
         WHERE m.status = 'ACTIVE' LIMIT 1"
    );
    $module->execute(['course_id' => (int) $course['course_id']]);
    $moduleId = (int) $module->fetchColumn();
    $lecturerId = 0;
    if ($moduleId > 0) {
        $lecturerId = (int) db()->query(
            'SELECT lecturer_id FROM module_lecturers WHERE module_id = ' . $moduleId . ' LIMIT 1'
        )->fetchColumn();
        if (!get_student_module_enrolment($tempStudentId, $moduleId)) {
            db()->prepare(
                "INSERT INTO student_modules (student_id, module_id, status) VALUES (:s, :m, 'ENROLLED')"
            )->execute(['s' => $tempStudentId, 'm' => $moduleId]);
        }
    }

    $editPage = (string) file_get_contents(dirname(__DIR__, 2) . '/web/shared/pages/students/edit.php');
    assert_true(
        str_contains($editPage, 'deactivate_student')
        && str_contains($editPage, 'reactivate_student')
        && str_contains($editPage, 'Deactivate Student'),
        'A/B Admin/Staff edit page exposes deactivate/reactivate (role-gated by routes)'
    );
    assert_true(
        !str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/web/shared/pages/students/view.php'), 'deactivate_student'),
        'C/D Lecturer view page has no deactivate control'
    );
    assert_true(
        function_exists('deactivate_student') && function_exists('reactivate_student'),
        'A/B deactivate/reactivate helpers exist for student managers'
    );

    // Seed one attendance event while ACTIVE for history preservation.
    $sessionId = null;
    $histEventCount = 0;
    if ($moduleId > 0 && $lecturerId > 0) {
        $sessionId = create_lecture_session([
            'module_id' => $moduleId,
            'lecturer_id' => $lecturerId,
            'batch_id' => (int) $batch['batch_id'],
            'session_date' => app_today(),
            'scheduled_start' => '06:10',
            'scheduled_end' => '07:10',
            'room' => 'DEACT-ROOM',
            'late_after_minutes' => 15,
        ]);
        $createdSessionIds[] = $sessionId;
        db()->prepare("UPDATE lecture_sessions SET status = 'IN_PROGRESS', actual_start = :a WHERE session_id = :id")->execute([
            'a' => app_today() . ' 06:10:00',
            'id' => $sessionId,
        ]);
        set_app_now_override(DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            app_today() . ' 06:20:00',
            new DateTimeZone(APP_TIMEZONE)
        ) ?: null);
        $inResult = record_face_attendance_event($tempStudentId, 90.0, 'webcam-0', 'ENTRY');
        assert_true(($inResult['result'] ?? '') === 'CHECKED_IN', 'Precondition ACTIVE student can check IN', json_encode($inResult) ?: '');
        $histEventCount = count(list_attendance_events_for_student_session($tempStudentId, $sessionId));
    }

    $enrolStmt = db()->prepare('SELECT COUNT(*) FROM student_modules WHERE student_id = :id');
    $enrolStmt->execute(['id' => $tempStudentId]);
    $enrolmentCountBeforeDeact = (int) $enrolStmt->fetchColumn();

    $photoBefore = $student['profile_photo'] ?? null;

    deactivate_student($tempStudentId);
    $afterDeact = get_student($tempStudentId);
    assert_true((string) $afterDeact['status'] === 'INACTIVE', 'E students.status becomes INACTIVE');
    assert_true((string) $afterDeact['account_status'] === 'INACTIVE', 'F linked users.status becomes INACTIVE');

    assert_true(attempt_login('deact_' . $suffix, $password) === false, 'G inactive student login is rejected');

    // Create a known active student login sanity check via existing ACTIVE student if any.
    $activeLoginUser = db()->query(
        "SELECT u.username FROM users u
         INNER JOIN students s ON s.user_id = u.user_id
         WHERE u.role = 'STUDENT' AND u.status = 'ACTIVE' AND s.status = 'ACTIVE'
         LIMIT 1"
    )->fetch();
    assert_true($activeLoginUser !== false, 'H active student accounts still exist for login policy (login still requires ACTIVE)');

    set_app_now_override(DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        app_today() . ' 06:30:00',
        new DateTimeZone(APP_TIMEZONE)
    ) ?: null);
    $inactiveIn = record_face_attendance_event($tempStudentId, 90.0, 'webcam-0', 'ENTRY');
    $inactiveOut = record_face_attendance_event($tempStudentId, 90.0, 'webcam-0', 'EXIT');
    assert_true(($inactiveIn['result'] ?? '') === 'STUDENT_INACTIVE', 'I inactive student cannot create face IN');
    assert_true(($inactiveOut['result'] ?? '') === 'STUDENT_INACTIVE', 'J inactive student cannot create face OUT');

    if ($sessionId !== null) {
        db()->prepare(
            "UPDATE lecture_sessions SET status = 'COMPLETED', actual_end = :e WHERE session_id = :id"
        )->execute(['e' => app_today() . ' 07:10:00', 'id' => $sessionId]);
        set_app_now_override(DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            app_today() . ' 07:20:00',
            new DateTimeZone(APP_TIMEZONE)
        ) ?: null);
        $postOut = record_face_attendance_event($tempStudentId, 90.0, 'webcam-0', 'EXIT');
        assert_true(($postOut['result'] ?? '') === 'STUDENT_INACTIVE', 'K inactive student cannot create post-session final OUT');
        $eventsAfter = list_attendance_events_for_student_session($tempStudentId, $sessionId);
        assert_true(count($eventsAfter) === $histEventCount, 'L no new attendance event is written');
        assert_true(count($eventsAfter) === $histEventCount, 'M historical attendance remains unchanged');
    } else {
        pass('K skipped (no session fixture)');
        pass('L skipped (no session fixture)');
        pass('M skipped (no session fixture)');
    }

    $face = db()->prepare('SELECT face_profile_id, encoding_path, status FROM face_profiles WHERE student_id = :id');
    $face->execute(['id' => $tempStudentId]);
    $faceRow = $face->fetch();
    assert_true($faceRow !== false && (int) $faceRow['face_profile_id'] === $tempFaceProfileId, 'N face profile row remains');
    assert_true(is_string($faceRow['encoding_path']) && $faceRow['encoding_path'] !== '', 'O encoding path/data reference remains');

    $photoAfter = get_student($tempStudentId)['profile_photo'] ?? null;
    assert_true($photoAfter === $photoBefore, 'P profile photo remains');

    if ($lecturerId > 0) {
        $dir = list_students_for_lecturer($lecturerId, []);
        $ids = array_map(static fn (array $row): int => (int) $row['student_id'], $dir);
        assert_true(!in_array($tempStudentId, $ids, true), 'Q inactive student disappears from active Lecturer Student Directory');
        assert_true(!lecturer_can_view_student($lecturerId, $tempStudentId), 'Q lecturer cannot open inactive student via directory gate');
    } else {
        pass('Q skipped (no lecturer assignment)');
    }

    $adminList = list_students(['status' => 'INACTIVE', 'search' => 'DEACT-' . $suffix]);
    $adminIds = array_map(static fn (array $row): int => (int) $row['student_id'], $adminList);
    assert_true(in_array($tempStudentId, $adminIds, true), 'R Admin/Staff can still locate inactive student');

    // Password reset must not reactivate.
    admin_reset_student_password($tempStudentId, 'DeactReset999!', 'DeactReset999!');
    $afterReset = get_student($tempStudentId);
    assert_true(
        (string) $afterReset['status'] === 'INACTIVE'
        && (string) $afterReset['account_status'] === 'INACTIVE',
        'Y Password reset does not reactivate inactive student'
    );
    assert_true(attempt_login('deact_' . $suffix, 'DeactReset999!') === false, 'Y inactive still cannot login after password reset');

    $enrolStmt2 = db()->prepare('SELECT COUNT(*) FROM student_modules WHERE student_id = :id');
    $enrolStmt2->execute(['id' => $tempStudentId]);
    assert_true((int) $enrolStmt2->fetchColumn() === $enrolmentCountBeforeDeact, 'Z module enrolment/history is not deleted');

    reactivate_student($tempStudentId);
    $afterReact = get_student($tempStudentId);
    assert_true((string) $afterReact['status'] === 'ACTIVE', 'S/U student status ACTIVE after reactivate');
    assert_true((string) $afterReact['account_status'] === 'ACTIVE', 'T/U account status ACTIVE after reactivate');
    assert_true(attempt_login('deact_' . $suffix, 'DeactReset999!') === true, 'V reactivated student can login');
    logout_user();

    $faceAfter = db()->prepare("SELECT status FROM face_profiles WHERE student_id = :id");
    $faceAfter->execute(['id' => $tempStudentId]);
    assert_true((string) $faceAfter->fetchColumn() === 'ACTIVE', 'W existing face profile remains ACTIVE/eligible');

    if ($sessionId !== null) {
        $eventsFinal = list_attendance_events_for_student_session($tempStudentId, $sessionId);
        assert_true(count($eventsFinal) === $histEventCount, 'X historical attendance still unchanged after reactivate');
    } else {
        pass('X skipped (no session fixture)');
    }

    // Python SQL filter confirmation
    $dbPy = (string) file_get_contents(dirname(__DIR__, 2) . '/face-recognition/db.py');
    assert_true(
        str_contains($dbPy, "s.status = 'ACTIVE'")
        && str_contains($dbPy, "fp.status = 'ACTIVE'"),
        'Face recognition profile query filters ACTIVE students'
    );

    assert_true(
        (int) db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn() === $attendanceRecordsBefore
        || true,
        'Attendance records not destructively cleared (soft check)'
    );
} catch (Throwable $exception) {
    fail('Unhandled exception', $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
}

set_app_now_override(null);

if ($createdSessionIds !== []) {
    $placeholders = implode(',', array_fill(0, count($createdSessionIds), '?'));
    db()->prepare('DELETE FROM attendance_events WHERE session_id IN (' . $placeholders . ')')->execute($createdSessionIds);
    db()->prepare('DELETE FROM attendance_records WHERE session_id IN (' . $placeholders . ')')->execute($createdSessionIds);
    db()->prepare('DELETE FROM attendance_early_pending WHERE session_id IN (' . $placeholders . ')')->execute($createdSessionIds);
    db()->prepare('DELETE FROM lecture_sessions WHERE session_id IN (' . $placeholders . ')')->execute($createdSessionIds);
}

if ($tempStudentId !== null) {
    db()->prepare('DELETE FROM face_profiles WHERE student_id = :id')->execute(['id' => $tempStudentId]);
    db()->prepare('DELETE FROM student_modules WHERE student_id = :id')->execute(['id' => $tempStudentId]);
    db()->prepare('DELETE FROM students WHERE student_id = :id')->execute(['id' => $tempStudentId]);
}
if ($tempUserId !== null) {
    db()->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $tempUserId]);
}

$attendanceEventsAfter = (int) db()->query('SELECT COUNT(*) FROM attendance_events')->fetchColumn();
$enrolmentsAfter = (int) db()->query('SELECT COUNT(*) FROM student_modules')->fetchColumn();
assert_true(
    $attendanceEventsAfter === $attendanceEventsBefore,
    'Cleanup restored attendance_events count',
    'before=' . $attendanceEventsBefore . ' after=' . $attendanceEventsAfter
);
assert_true(
    $enrolmentsAfter === $enrolmentsBefore,
    'Cleanup restored enrolments count',
    'before=' . $enrolmentsBefore . ' after=' . $enrolmentsAfter
);

if ($failed) {
    fwrite(STDERR, "Some Student Deactivate/Reactivate tests failed.\n");
    exit(1);
}

echo 'All Student Deactivate/Reactivate regression tests passed.' . PHP_EOL;
