<?php

declare(strict_types=1);

/**
 * Regression: Student Account Status Consistency Fix v1.
 *
 * Ensures Admin User Management cannot independently change STUDENT login status;
 * Student Deactivate/Reactivate remains the authoritative ACTIVE/INACTIVE path.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_student_account_status_consistency.php
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
$tempStudentId = null;
$tempUserId = null;
$tempFaceProfileId = null;
$tempLecturerId = null;
$tempLecturerUserId = null;
$tempStaffId = null;
$tempStaffUserId = null;
$tempAdminUserId = null;
$orphanUserId = null;
$createdSessionIds = [];

$attendanceEventsBefore = (int) db()->query('SELECT COUNT(*) FROM attendance_events')->fetchColumn();
$attendanceRecordsBefore = (int) db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
$enrolmentsBefore = (int) db()->query('SELECT COUNT(*) FROM student_modules')->fetchColumn();
$faceProfilesBefore = (int) db()->query('SELECT COUNT(*) FROM face_profiles')->fetchColumn();

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

/**
 * Mirror Python gallery eligibility: ACTIVE face_profiles for ACTIVE students.
 *
 * @return list<int>
 */
function face_gallery_student_ids(): array
{
    $rows = db()->query(
        "SELECT fp.student_id
         FROM face_profiles fp
         INNER JOIN students s ON s.student_id = fp.student_id
         WHERE fp.status = 'ACTIVE' AND s.status = 'ACTIVE'"
    )->fetchAll(PDO::FETCH_COLUMN);

    return array_map('intval', $rows);
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
    $password = 'StatusConsist123!';

    $tempStudentId = register_student([
        'username' => 'stcons_' . $suffix,
        'email' => 'stcons_' . $suffix . '@example.test',
        'password' => $password,
        'account_status' => 'ACTIVE',
        'registration_no' => 'STCONS-' . $suffix,
        'first_name' => 'Status',
        'last_name' => 'Consistency',
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

    db()->prepare(
        "INSERT INTO face_profiles (student_id, encoding_path, sample_count, status)
         VALUES (:student_id, :encoding_path, 3, 'ACTIVE')"
    )->execute([
        'student_id' => $tempStudentId,
        'encoding_path' => 'encodings/stcons_' . $suffix . '.pkl',
    ]);
    $tempFaceProfileId = (int) db()->lastInsertId();

    $module = db()->prepare(
        "SELECT m.module_id FROM modules m
         INNER JOIN course_modules cm ON cm.module_id = m.module_id AND cm.course_id = :course_id AND cm.status = 'ACTIVE'
         WHERE m.status = 'ACTIVE' LIMIT 1"
    );
    $module->execute(['course_id' => (int) $course['course_id']]);
    $moduleId = (int) $module->fetchColumn();
    $lecturerIdForDir = 0;
    if ($moduleId > 0) {
        $lecturerIdForDir = (int) db()->query(
            'SELECT lecturer_id FROM module_lecturers WHERE module_id = ' . $moduleId . ' LIMIT 1'
        )->fetchColumn();
        if (!get_student_module_enrolment($tempStudentId, $moduleId)) {
            db()->prepare(
                "INSERT INTO student_modules (student_id, module_id, status) VALUES (:s, :m, 'ENROLLED')"
            )->execute(['s' => $tempStudentId, 'm' => $moduleId]);
        }
    }

    $enrolStmt = db()->prepare('SELECT COUNT(*) FROM student_modules WHERE student_id = :id');
    $enrolStmt->execute(['id' => $tempStudentId]);
    $enrolmentCountForStudent = (int) $enrolStmt->fetchColumn();

    $indexSource = (string) file_get_contents(dirname(__DIR__, 2) . '/web/admin/users/index.php');
    $editSource = (string) file_get_contents(dirname(__DIR__, 2) . '/web/admin/users/edit.php');

    assert_true(
        str_contains($indexSource, 'user_management_roles')
        && !str_contains($indexSource, 'Manage Student')
        && !str_contains($indexSource, 'AUTH_ROLES'),
        'User Management list excludes STUDENT UI paths'
    );
    assert_true(
        str_contains($editSource, '$accountUser')
        && str_contains($editSource, 'student_accounts_managed_elsewhere_message')
        && str_contains($editSource, "role'] === 'STUDENT'"),
        'A User Edit denies STUDENT via accountUser + redirect policy'
    );
    assert_true(
        !preg_match('/\$user\[[\'"]status[\'"]\]/', $editSource),
        'A Linked/orphan User Edit does not use overwritten $user[status]'
    );

    // Render-safe status badge helper still works (layout overwrite shape).
    $previousHandler = set_error_handler(static function (int $severity, string $message): bool {
        throw new ErrorException($message, 0, $severity);
    });
    try {
        $accountStatus = 'ACTIVE';
        ob_start();
        ?>
        <span class="badge <?= e(status_badge_class($accountStatus)) ?>"><?= e($accountStatus) ?></span>
        <?php
        $linkedRender = (string) ob_get_clean();
        assert_true(
            str_contains($linkedRender, 'ACTIVE') && !str_contains($linkedRender, 'Undefined'),
            'A Status badge renders without warning'
        );
    } finally {
        if ($previousHandler !== null) {
            set_error_handler($previousHandler);
        } else {
            restore_error_handler();
        }
    }

    // Orphan STUDENT login (role STUDENT, no students row) — legacy data, not via create_user.
    db()->prepare(
        "INSERT INTO users (username, email, password_hash, role, status)
         VALUES (:username, :email, :password_hash, 'STUDENT', 'ACTIVE')"
    )->execute([
        'username' => 'orphan_' . $suffix,
        'email' => 'orphan_' . $suffix . '@example.test',
        'password_hash' => password_hash('OrphanConsist1!', PASSWORD_DEFAULT),
    ]);
    $orphanUserId = (int) db()->lastInsertId();
    assert_true(get_student_id_by_user_id($orphanUserId) === null, 'C Orphan has no linked student profile');
    assert_true(
        !in_array($orphanUserId, array_map(static fn (array $row): int => (int) $row['user_id'], list_users([])), true),
        'A Orphan STUDENT excluded from User Management list'
    );
    $orphanRejected = false;
    try {
        set_user_status($orphanUserId, 'INACTIVE');
    } catch (InvalidArgumentException $exception) {
        $orphanRejected = true;
    }
    assert_true($orphanRejected, 'E Generic status mutation remains blocked for orphan STUDENT');
    assert_true((string) get_user($orphanUserId)['status'] === 'ACTIVE', 'E Orphan users.status unchanged after rejected mutation');

    // --- Existing: generic User Management cannot change linked STUDENT status ---
    $beforeA = get_student($tempStudentId);
    $rejectedDeactivate = false;
    $rejectMessage = '';
    try {
        set_user_status($tempUserId, 'INACTIVE');
    } catch (InvalidArgumentException $exception) {
        $rejectedDeactivate = true;
        $rejectMessage = $exception->getMessage();
    }
    $afterA = get_student($tempStudentId);
    assert_true($rejectedDeactivate, 'E Generic User Management cannot deactivate linked STUDENT via set_user_status');
    assert_true(
        str_contains($rejectMessage, 'Student Management'),
        'E rejection message points to Student Management',
        $rejectMessage
    );
    assert_true(
        (string) $afterA['account_status'] === (string) $beforeA['account_status']
        && (string) $afterA['status'] === (string) $beforeA['status'],
        'D/E Rejection does not modify users.status or students.status (deactivate attempt)'
    );

    $rejectedActivate = false;
    try {
        // Force mismatched attempt toward ACTIVE while already ACTIVE still rejected if we first
        // temporarily only change users via raw SQL... For activate path: start ACTIVE, reject INACTIVE above,
        // then reject another activate call when already ACTIVE is no-op (same status allowed).
        // Test activate-from-INACTIVE rejection using update_user with forged status after raw desync restore.
        set_user_status($tempUserId, 'ACTIVE');
        // same status — allowed; confirm no throw for identical status
        pass('A/B same-status set_user_status for STUDENT is a no-op allow');
    } catch (InvalidArgumentException $exception) {
        fail('A/B same-status set_user_status for STUDENT should be allowed', $exception->getMessage());
    }

    // Simulate forged activate while student is ACTIVE: create temporary users.status INACTIVE via raw SQL
    // then prove set_user_status(ACTIVE) is rejected and leaves desync untouched (no silent half-fix).
    db()->prepare("UPDATE users SET status = 'INACTIVE' WHERE user_id = :id")->execute(['id' => $tempUserId]);
    $desyncBefore = get_student($tempStudentId);
    assert_true(
        student_account_status_is_mismatched($desyncBefore),
        'Precondition desync detect: profile ACTIVE / account INACTIVE'
    );

    $rejectedActivate = false;
    $activateMessage = '';
    try {
        set_user_status($tempUserId, 'ACTIVE');
    } catch (InvalidArgumentException $exception) {
        $rejectedActivate = true;
        $activateMessage = $exception->getMessage();
    }
    $afterDesyncActivate = get_student($tempStudentId);
    assert_true($rejectedActivate, 'B Generic User Management cannot activate STUDENT via set_user_status');
    assert_true(
        (string) $afterDesyncActivate['account_status'] === 'INACTIVE'
        && (string) $afterDesyncActivate['status'] === 'ACTIVE',
        'D/E Rejection leaves both statuses unchanged (activate attempt on desynced student)'
    );

    $rejectedUpdate = false;
    try {
        update_user($tempUserId, [
            'username' => (string) $desyncBefore['username'],
            'email' => (string) $desyncBefore['email'],
            'role' => 'STUDENT',
            'status' => 'ACTIVE',
        ]);
    } catch (InvalidArgumentException $exception) {
        $rejectedUpdate = true;
        assert_true(
            str_contains($exception->getMessage(), 'Student Management'),
            'C Forged/direct update_user status POST is rejected',
            $exception->getMessage()
        );
    }
    assert_true($rejectedUpdate, 'C Forged/direct student status update_user is rejected');
    $afterUpdateReject = get_student($tempStudentId);
    assert_true(
        (string) $afterUpdateReject['account_status'] === 'INACTIVE'
        && (string) $afterUpdateReject['status'] === 'ACTIVE',
        'D/E Rejection does not modify users/students on forged update_user'
    );

    // Repair desync via authoritative Student Management path (deactivate then reactivate).
    // Profile is ACTIVE → deactivate_student aligns both to INACTIVE.
    deactivate_student($tempStudentId);
    $afterDeact = get_student($tempStudentId);
    assert_true(
        (string) $afterDeact['status'] === 'INACTIVE' && (string) $afterDeact['account_status'] === 'INACTIVE',
        'F Student Management deactivate updates BOTH statuses to INACTIVE'
    );

    assert_true(attempt_login('stcons_' . $suffix, $password) === false, 'K Inactive student login remains blocked');

    $galleryInactive = face_gallery_student_ids();
    assert_true(
        !in_array($tempStudentId, $galleryInactive, true),
        'M Inactive student remains excluded from face gallery'
    );

    $inactiveAttendance = record_face_attendance_event($tempStudentId, 91.0, 'webcam-0', 'ENTRY');
    assert_true(
        ($inactiveAttendance['result'] ?? '') === 'STUDENT_INACTIVE',
        'N Inactive student attendance remains blocked',
        json_encode($inactiveAttendance) ?: ''
    );

    if ($lecturerIdForDir > 0) {
        $dirInactive = list_students_for_lecturer($lecturerIdForDir, []);
        $idsInactive = array_map(static fn (array $row): int => (int) $row['student_id'], $dirInactive);
        assert_true(
            !in_array($tempStudentId, $idsInactive, true),
            'Q Lecturer Student Directory excludes inactive student'
        );
    } else {
        pass('Q Lecturer directory check skipped (no module lecturer available)');
    }

    admin_reset_student_password($tempStudentId, 'StatusReset999!', 'StatusReset999!');
    $afterReset = get_student($tempStudentId);
    assert_true(
        (string) $afterReset['status'] === 'INACTIVE' && (string) $afterReset['account_status'] === 'INACTIVE',
        'P Password reset does not reactivate inactive student'
    );
    assert_true(
        attempt_login('stcons_' . $suffix, 'StatusReset999!') === false,
        'P Inactive still cannot login after password reset'
    );

    $faceAfterReset = (int) db()->query(
        'SELECT COUNT(*) FROM face_profiles WHERE student_id = ' . (int) $tempStudentId
    )->fetchColumn();
    assert_true($faceAfterReset === 1, 'S No face profile/encoding deleted');

    $enrolStmt->execute(['id' => $tempStudentId]);
    assert_true(
        (int) $enrolStmt->fetchColumn() === $enrolmentCountForStudent,
        'T No enrolment rows deleted'
    );

    reactivate_student($tempStudentId);
    $afterReact = get_student($tempStudentId);
    assert_true(
        (string) $afterReact['status'] === 'ACTIVE' && (string) $afterReact['account_status'] === 'ACTIVE',
        'G Student Management reactivate updates BOTH statuses to ACTIVE'
    );
    assert_true(attempt_login('stcons_' . $suffix, 'StatusReset999!') === true, 'L Reactivated student login works');
    // Avoid logout_user() header warnings under CLI output.

    $galleryActive = face_gallery_student_ids();
    assert_true(
        in_array($tempStudentId, $galleryActive, true),
        'O Reactivated student becomes face gallery eligible again'
    );

    // Attendance eligibility again (may be NO_MATCHING_SESSION outside a live window — not STUDENT_INACTIVE).
    $activeAttendance = record_face_attendance_event($tempStudentId, 91.0, 'webcam-0', 'ENTRY');
    assert_true(
        ($activeAttendance['result'] ?? '') !== 'STUDENT_INACTIVE',
        'O Reactivated student is attendance-eligible again (not STUDENT_INACTIVE)',
        json_encode($activeAttendance) ?: ''
    );

    if ($lecturerIdForDir > 0) {
        $dirActive = list_students_for_lecturer($lecturerIdForDir, []);
        $idsActive = array_map(static fn (array $row): int => (int) $row['student_id'], $dirActive);
        assert_true(
            in_array($tempStudentId, $idsActive, true),
            'Q Reactivated student appears in Lecturer Student Directory again'
        );
    }

    // --- H/I/J non-student User Management still works ---
    $tempLecturerId = create_lecturer([
        'username' => 'leccons_' . $suffix,
        'email' => 'leccons_' . $suffix . '@example.test',
        'password' => 'LecturerConsist1!',
        'account_status' => 'ACTIVE',
        'staff_no' => 'LEC-CONS-' . $suffix,
        'first_name' => 'Lec',
        'last_name' => 'Consist',
        'phone' => '',
        'department' => 'Test',
        'status' => 'ACTIVE',
    ]);
    $tempLecturerUserId = (int) get_lecturer($tempLecturerId)['user_id'];
    set_user_status($tempLecturerUserId, 'INACTIVE');
    assert_true(
        (string) get_user($tempLecturerUserId)['status'] === 'INACTIVE',
        'G Lecturer User Edit/status still works (deactivate)'
    );
    set_user_status($tempLecturerUserId, 'ACTIVE');
    assert_true(
        (string) get_user($tempLecturerUserId)['status'] === 'ACTIVE',
        'G Lecturer User Edit/status still works (activate)'
    );

    $tempStaffId = create_academic_staff_member([
        'username' => 'staffcons_' . $suffix,
        'email' => 'staffcons_' . $suffix . '@example.test',
        'password' => 'StaffConsist1!',
        'account_status' => 'ACTIVE',
        'staff_no' => 'STAFF-CONS-' . $suffix,
        'first_name' => 'Staff',
        'last_name' => 'Consist',
        'phone' => '',
        'position' => 'Tester',
        'status' => 'ACTIVE',
    ]);
    $tempStaffUserId = (int) get_academic_staff_member($tempStaffId)['user_id'];
    set_user_status($tempStaffUserId, 'INACTIVE');
    assert_true(
        (string) get_user($tempStaffUserId)['status'] === 'INACTIVE',
        'H Academic Staff User Edit/status still works'
    );
    set_user_status($tempStaffUserId, 'ACTIVE');

    $tempAdminUserId = create_user([
        'username' => 'admincons_' . $suffix,
        'email' => 'admincons_' . $suffix . '@example.test',
        'password' => 'AdminConsist1!',
        'role' => 'ADMIN',
        'status' => 'ACTIVE',
    ]);
    set_user_status($tempAdminUserId, 'INACTIVE');
    assert_true(
        (string) get_user($tempAdminUserId)['status'] === 'INACTIVE',
        'I Admin User Edit/status still works (deactivate via set_user_status)'
    );
    set_user_status($tempAdminUserId, 'ACTIVE');
    assert_true(
        (string) get_user($tempAdminUserId)['status'] === 'ACTIVE',
        'I Admin User Edit/status still works (activate via set_user_status)'
    );

    // R — attendance history counts must not drop; this test may add zero events (inactive blocked).
    $attendanceEventsAfter = (int) db()->query('SELECT COUNT(*) FROM attendance_events')->fetchColumn();
    $attendanceRecordsAfter = (int) db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
    assert_true(
        $attendanceEventsAfter >= $attendanceEventsBefore
        && $attendanceRecordsAfter >= $attendanceRecordsBefore,
        'R No attendance history deleted (counts did not decrease)'
    );

    $faceProfilesAfter = (int) db()->query('SELECT COUNT(*) FROM face_profiles')->fetchColumn();
    assert_true($faceProfilesAfter >= $faceProfilesBefore, 'S Face profile rows preserved (count did not decrease)');

    $enrolmentsAfter = (int) db()->query('SELECT COUNT(*) FROM student_modules')->fetchColumn();
    assert_true($enrolmentsAfter >= $enrolmentsBefore, 'T Enrolment rows preserved (count did not decrease)');

    assert_true(
        function_exists('assert_user_management_may_change_status')
        && function_exists('student_status_managed_elsewhere_message'),
        'Server-side STUDENT status guard helpers exist'
    );
} catch (Throwable $exception) {
    fail('FATAL', $exception->getMessage());
} finally {
    set_app_now_override(null);
    try {
        if ($tempFaceProfileId) {
            db()->prepare('DELETE FROM face_profiles WHERE face_profile_id = :id')->execute(['id' => $tempFaceProfileId]);
        }
        if ($tempStudentId) {
            db()->prepare('DELETE FROM student_modules WHERE student_id = :id')->execute(['id' => $tempStudentId]);
            db()->prepare('DELETE FROM attendance_events WHERE student_id = :id')->execute(['id' => $tempStudentId]);
            db()->prepare('DELETE FROM attendance_records WHERE student_id = :id')->execute(['id' => $tempStudentId]);
            db()->prepare('DELETE FROM students WHERE student_id = :id')->execute(['id' => $tempStudentId]);
        }
        if ($tempUserId) {
            db()->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $tempUserId]);
        }
        if ($tempLecturerId) {
            db()->prepare('DELETE FROM lecturers WHERE lecturer_id = :id')->execute(['id' => $tempLecturerId]);
        }
        if ($tempLecturerUserId) {
            db()->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $tempLecturerUserId]);
        }
        if ($tempStaffId) {
            db()->prepare('DELETE FROM academic_staff WHERE academic_staff_id = :id')->execute(['id' => $tempStaffId]);
        }
        if ($tempStaffUserId) {
            db()->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $tempStaffUserId]);
        }
        if ($tempAdminUserId) {
            db()->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $tempAdminUserId]);
        }
        if ($orphanUserId) {
            db()->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $orphanUserId]);
        }
        foreach ($createdSessionIds as $sid) {
            db()->prepare('DELETE FROM lecture_sessions WHERE session_id = :id')->execute(['id' => $sid]);
        }
    } catch (Throwable $cleanupException) {
        echo 'CLEANUP WARNING: ' . $cleanupException->getMessage() . PHP_EOL;
    }
}

if ($failed) {
    echo PHP_EOL . 'RESULT: FAILED' . PHP_EOL;
    exit(1);
}

echo PHP_EOL . 'RESULT: PASSED' . PHP_EOL;
exit(0);
