<?php

declare(strict_types=1);

/**
 * Regression tests for My Profile + Change Own Password.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_my_profile.php
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

$failed = false;
$originalHashes = [];
$tempUserIds = [];
$tempStudentIds = [];
$tempLecturerIds = [];
$tempStaffIds = [];

$attendanceBefore = (int) db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
$sessionsBefore = (int) db()->query('SELECT COUNT(*) FROM lecture_sessions')->fetchColumn();
$assignmentsBefore = (int) db()->query('SELECT COUNT(*) FROM assignments')->fetchColumn();
$marksBefore = (int) db()->query('SELECT COUNT(*) FROM marks')->fetchColumn();

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

function fetch_password_hash(int $userId): string
{
    $statement = db()->prepare('SELECT password_hash FROM users WHERE user_id = :id LIMIT 1');
    $statement->execute(['id' => $userId]);
    $hash = $statement->fetchColumn();
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('Missing password hash for user ' . $userId);
    }

    return $hash;
}

function remember_hash(int $userId): void
{
    global $originalHashes;
    if (!isset($originalHashes[$userId])) {
        $originalHashes[$userId] = fetch_password_hash($userId);
    }
}

/**
 * @return array{user_id:int,role:string,username:string,email:string}
 */
function pick_or_create_user_by_role(string $role, string $suffix): array
{
    global $tempUserIds, $tempStudentIds, $tempLecturerIds, $tempStaffIds, $originalHashes;

    if ($role === 'STUDENT') {
        $sql = "SELECT u.user_id, u.role, u.username, u.email
                FROM users u
                INNER JOIN students s ON s.user_id = u.user_id
                WHERE u.role = 'STUDENT' AND u.status = 'ACTIVE'
                ORDER BY u.user_id LIMIT 1";
    } elseif ($role === 'LECTURER') {
        $sql = "SELECT u.user_id, u.role, u.username, u.email
                FROM users u
                INNER JOIN lecturers l ON l.user_id = u.user_id
                WHERE u.role = 'LECTURER' AND u.status = 'ACTIVE'
                ORDER BY u.user_id LIMIT 1";
    } elseif ($role === 'ACADEMIC_STAFF') {
        $sql = "SELECT u.user_id, u.role, u.username, u.email
                FROM users u
                INNER JOIN academic_staff a ON a.user_id = u.user_id
                WHERE u.role = 'ACADEMIC_STAFF' AND u.status = 'ACTIVE'
                ORDER BY u.user_id LIMIT 1";
    } else {
        $sql = "SELECT user_id, role, username, email
                FROM users
                WHERE role = 'ADMIN' AND status = 'ACTIVE'
                ORDER BY user_id LIMIT 1";
    }

    $row = db()->query($sql)->fetch();
    if ($row !== false) {
        return [
            'user_id' => (int) $row['user_id'],
            'role' => (string) $row['role'],
            'username' => (string) $row['username'],
            'email' => (string) $row['email'],
        ];
    }

    if ($role === 'ACADEMIC_STAFF') {
        $staffId = create_academic_staff_member([
            'username' => 'prof_staff_' . $suffix,
            'email' => 'prof_staff_' . $suffix . '@example.test',
            'password' => 'StaffPass123!',
            'account_status' => 'ACTIVE',
            'staff_no' => 'AS-' . $suffix,
            'first_name' => 'Temp',
            'last_name' => 'Staff',
            'phone' => '',
            'position' => 'Tester',
            'status' => 'ACTIVE',
        ]);
        $tempStaffIds[] = $staffId;
        $userId = (int) db()->query(
            'SELECT user_id FROM academic_staff WHERE academic_staff_id = ' . $staffId
        )->fetchColumn();
        $tempUserIds[] = $userId;
        $originalHashes[$userId] = fetch_password_hash($userId);

        return [
            'user_id' => $userId,
            'role' => 'ACADEMIC_STAFF',
            'username' => 'prof_staff_' . $suffix,
            'email' => 'prof_staff_' . $suffix . '@example.test',
        ];
    }

    if ($role === 'LECTURER') {
        $lecturerId = create_lecturer([
            'username' => 'prof_lec_' . $suffix,
            'email' => 'prof_lec_' . $suffix . '@example.test',
            'password' => 'LecPass123!',
            'account_status' => 'ACTIVE',
            'staff_no' => 'L-' . $suffix,
            'first_name' => 'Temp',
            'last_name' => 'Lecturer',
            'phone' => '',
            'department' => 'Test',
            'status' => 'ACTIVE',
        ]);
        $tempLecturerIds[] = $lecturerId;
        $userId = (int) db()->query(
            'SELECT user_id FROM lecturers WHERE lecturer_id = ' . $lecturerId
        )->fetchColumn();
        $tempUserIds[] = $userId;
        $originalHashes[$userId] = fetch_password_hash($userId);

        return [
            'user_id' => $userId,
            'role' => 'LECTURER',
            'username' => 'prof_lec_' . $suffix,
            'email' => 'prof_lec_' . $suffix . '@example.test',
        ];
    }

    if ($role === 'STUDENT') {
        $courseBatch = db()->query(
            "SELECT c.course_id, b.batch_id
             FROM courses c
             INNER JOIN batches b ON b.course_id = c.course_id AND b.status = 'ACTIVE'
             WHERE c.status = 'ACTIVE'
             ORDER BY c.course_id, b.batch_id
             LIMIT 1"
        )->fetch();
        if ($courseBatch === false) {
            throw new RuntimeException('Need an ACTIVE course/batch to create a test student.');
        }
        $studentId = register_student([
            'username' => 'prof_stu_' . $suffix,
            'email' => 'prof_stu_' . $suffix . '@example.test',
            'password' => 'StuPass123!',
            'account_status' => 'ACTIVE',
            'registration_no' => 'PROF-' . $suffix,
            'first_name' => 'Temp',
            'last_name' => 'Student',
            'phone' => '',
            'date_of_birth' => '',
            'gender' => '',
            'course_id' => (int) $courseBatch['course_id'],
            'batch_id' => (int) $courseBatch['batch_id'],
            'enrollment_date' => app_today(),
            'status' => 'ACTIVE',
        ]);
        $tempStudentIds[] = $studentId;
        $userId = (int) db()->query(
            'SELECT user_id FROM students WHERE student_id = ' . $studentId
        )->fetchColumn();
        $tempUserIds[] = $userId;
        $originalHashes[$userId] = fetch_password_hash($userId);

        return [
            'user_id' => $userId,
            'role' => 'STUDENT',
            'username' => 'prof_stu_' . $suffix,
            'email' => 'prof_stu_' . $suffix . '@example.test',
        ];
    }

    throw new RuntimeException('No ACTIVE linked user for role ' . $role);
}

try {
    $suffix = (string) time();

    $admin = pick_or_create_user_by_role('ADMIN', $suffix);
    $staff = pick_or_create_user_by_role('ACADEMIC_STAFF', $suffix);
    $lecturer = pick_or_create_user_by_role('LECTURER', $suffix);
    $student = pick_or_create_user_by_role('STUDENT', $suffix);

    remember_hash($admin['user_id']);
    remember_hash($staff['user_id']);
    remember_hash($lecturer['user_id']);
    remember_hash($student['user_id']);

    // A–D open own profile
    $studentProfile = load_own_profile($student['user_id']);
    assert_true(
        (string) $studentProfile['account']['role'] === 'STUDENT'
        && (int) $studentProfile['account']['user_id'] === $student['user_id']
        && is_array($studentProfile['person']),
        'A Student can open own profile'
    );

    $lecturerProfile = load_own_profile($lecturer['user_id']);
    assert_true(
        (string) $lecturerProfile['account']['role'] === 'LECTURER'
        && (int) $lecturerProfile['account']['user_id'] === $lecturer['user_id']
        && is_array($lecturerProfile['person']),
        'B Lecturer can open own profile'
    );

    $staffProfile = load_own_profile($staff['user_id']);
    assert_true(
        (string) $staffProfile['account']['role'] === 'ACADEMIC_STAFF'
        && (int) $staffProfile['account']['user_id'] === $staff['user_id']
        && is_array($staffProfile['person']),
        'C Academic Staff can open own profile'
    );

    $adminProfile = load_own_profile($admin['user_id']);
    assert_true(
        (string) $adminProfile['account']['role'] === 'ADMIN'
        && (int) $adminProfile['account']['user_id'] === $admin['user_id'],
        'D Admin can open own profile'
    );

    // E Student cannot alter course/batch through profile POST
    $studentPersonBefore = $studentProfile['person'];
    $courseBefore = (int) $studentPersonBefore['course_id'];
    $batchBefore = (int) $studentPersonBefore['batch_id'];
    $regBefore = (string) $studentPersonBefore['registration_no'];
    $statusBefore = (string) $studentPersonBefore['status'];

    $foreignCourse = (int) db()->query(
        'SELECT course_id FROM courses WHERE course_id <> ' . $courseBefore . ' ORDER BY course_id DESC LIMIT 1'
    )->fetchColumn();
    $foreignBatch = (int) db()->query(
        'SELECT batch_id FROM batches WHERE batch_id <> ' . $batchBefore . ' ORDER BY batch_id DESC LIMIT 1'
    )->fetchColumn();

    update_own_profile($student['user_id'], [
        'email' => (string) $studentProfile['account']['email'],
        'first_name' => (string) $studentPersonBefore['first_name'],
        'last_name' => (string) $studentPersonBefore['last_name'],
        'phone' => (string) ($studentPersonBefore['phone'] ?? ''),
        'course_id' => $foreignCourse > 0 ? $foreignCourse : 999999,
        'batch_id' => $foreignBatch > 0 ? $foreignBatch : 999999,
        'registration_no' => 'HACKED-' . $suffix,
        'status' => 'GRADUATED',
        'user_id' => $admin['user_id'],
        'student_id' => 999999,
        'role' => 'ADMIN',
    ]);

    $studentAfter = load_own_profile($student['user_id']);
    assert_true(
        (int) $studentAfter['person']['course_id'] === $courseBefore
        && (int) $studentAfter['person']['batch_id'] === $batchBefore
        && (string) $studentAfter['person']['registration_no'] === $regBefore
        && (string) $studentAfter['person']['status'] === $statusBefore
        && (string) $studentAfter['account']['role'] === 'STUDENT',
        'E Student cannot alter course/batch through profile POST'
    );

    // F Lecturer cannot alter module assignments through profile POST
    $mlBeforeStmt = db()->prepare('SELECT COUNT(*) FROM module_lecturers WHERE lecturer_id = :id');
    $mlBeforeStmt->execute(['id' => (int) $lecturerProfile['person']['lecturer_id']]);
    $moduleCountBefore = (int) $mlBeforeStmt->fetchColumn();

    update_own_profile($lecturer['user_id'], [
        'email' => (string) $lecturerProfile['account']['email'],
        'first_name' => (string) $lecturerProfile['person']['first_name'],
        'last_name' => (string) $lecturerProfile['person']['last_name'],
        'phone' => (string) ($lecturerProfile['person']['phone'] ?? ''),
        'staff_no' => 'HACK-' . $suffix,
        'department' => 'Hacked Dept',
        'lecturer_id' => 999999,
        'module_id' => 1,
        'role' => 'ADMIN',
    ]);

    $mlAfterStmt = db()->prepare('SELECT COUNT(*) FROM module_lecturers WHERE lecturer_id = :id');
    $mlAfterStmt->execute(['id' => (int) $lecturerProfile['person']['lecturer_id']]);
    $moduleCountAfter = (int) $mlAfterStmt->fetchColumn();
    $lecturerAfter = load_own_profile($lecturer['user_id']);
    assert_true(
        $moduleCountAfter === $moduleCountBefore
        && (string) $lecturerAfter['person']['staff_no'] === (string) $lecturerProfile['person']['staff_no']
        && (string) ($lecturerAfter['person']['department'] ?? '') === (string) ($lecturerProfile['person']['department'] ?? ''),
        'F Lecturer cannot alter module assignments through profile POST'
    );

    // G User cannot alter role through profile POST
    update_own_profile($admin['user_id'], [
        'email' => (string) $adminProfile['account']['email'],
        'role' => 'STUDENT',
        'status' => 'SUSPENDED',
        'user_id' => $student['user_id'],
    ]);
    $adminAfterRole = load_own_profile($admin['user_id']);
    assert_true(
        (string) $adminAfterRole['account']['role'] === 'ADMIN'
        && (string) $adminAfterRole['account']['status'] === (string) $adminProfile['account']['status'],
        'G User cannot alter role through profile POST'
    );

    // H Valid personal profile update succeeds
    $originalStudentEmail = (string) $studentAfter['account']['email'];
    $originalFirst = (string) $studentAfter['person']['first_name'];
    $originalLast = (string) $studentAfter['person']['last_name'];
    $originalPhone = (string) ($studentAfter['person']['phone'] ?? '');
    $newEmail = 'profile_test_' . $suffix . '@example.test';
    update_own_profile($student['user_id'], [
        'email' => $newEmail,
        'first_name' => 'Profile',
        'last_name' => 'Tester',
        'phone' => '0770000000',
    ]);
    $updated = load_own_profile($student['user_id']);
    assert_true(
        (string) $updated['account']['email'] === $newEmail
        && (string) $updated['person']['first_name'] === 'Profile'
        && (string) $updated['person']['last_name'] === 'Tester'
        && (string) $updated['person']['phone'] === '0770000000',
        'H Valid personal profile update succeeds'
    );

    // I Invalid email rejected
    $invalidRejected = false;
    try {
        update_own_profile($student['user_id'], [
            'email' => 'not-an-email',
            'first_name' => 'Profile',
            'last_name' => 'Tester',
            'phone' => '0770000000',
        ]);
    } catch (InvalidArgumentException) {
        $invalidRejected = true;
    }
    assert_true($invalidRejected, 'I Invalid email rejected if email editing is supported');

    // J Duplicate email rejected
    $dupRejected = false;
    try {
        update_own_profile($student['user_id'], [
            'email' => (string) $admin['email'],
            'first_name' => 'Profile',
            'last_name' => 'Tester',
            'phone' => '0770000000',
        ]);
    } catch (InvalidArgumentException $exception) {
        $dupRejected = str_contains($exception->getMessage(), 'already in use');
    }
    assert_true($dupRejected, 'J Duplicate email rejected if DB requires uniqueness');

    // Restore student profile fields
    update_own_profile($student['user_id'], [
        'email' => $originalStudentEmail,
        'first_name' => $originalFirst,
        'last_name' => $originalLast,
        'phone' => $originalPhone,
    ]);

    // Password tests on student account
    $tempPassword = 'TempPass_' . $suffix;
    $newPassword = 'NewPass_' . $suffix . '!';
    db()->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :id')->execute([
        'hash' => password_hash($tempPassword, PASSWORD_DEFAULT),
        'id' => $student['user_id'],
    ]);

    // K Valid password change
    change_own_password($student['user_id'], $tempPassword, $newPassword, $newPassword);
    $hashAfter = fetch_password_hash($student['user_id']);
    assert_true(password_verify($newPassword, $hashAfter), 'K Correct current password + valid new password succeeds');

    // L Wrong current password
    $wrongRejected = false;
    try {
        change_own_password($student['user_id'], 'DefinitelyWrongPass1', 'AnotherPass99', 'AnotherPass99');
    } catch (InvalidArgumentException) {
        $wrongRejected = true;
    }
    assert_true($wrongRejected, 'L Wrong current password rejected');

    // M Mismatch
    $mismatchRejected = false;
    try {
        change_own_password($student['user_id'], $newPassword, 'MismatchPass1', 'MismatchPass2');
    } catch (InvalidArgumentException) {
        $mismatchRejected = true;
    }
    assert_true($mismatchRejected, 'M New/confirm mismatch rejected');

    // N Policy violation
    $policyRejected = false;
    try {
        change_own_password($student['user_id'], $newPassword, 'short', 'short');
    } catch (InvalidArgumentException) {
        $policyRejected = true;
    }
    assert_true($policyRejected, 'N Password policy violation rejected');

    // O Same current/new
    $sameRejected = false;
    try {
        change_own_password($student['user_id'], $newPassword, $newPassword, $newPassword);
    } catch (InvalidArgumentException) {
        $sameRejected = true;
    }
    assert_true($sameRejected, 'O Same current/new password rejected');

    // P / Q
    $hashNow = fetch_password_hash($student['user_id']);
    assert_true(password_verify($newPassword, $hashNow), 'P New password hash verifies with password_verify()');
    assert_true(!password_verify($tempPassword, $hashNow), 'Q Old password no longer verifies after successful change');

    // R Posted foreign user_id cannot change another user's password
    $lecturerHashBefore = fetch_password_hash($lecturer['user_id']);
    $foreignIgnored = false;
    try {
        // Function signature ignores POST — calling with student's id must not touch lecturer.
        change_own_password($student['user_id'], $newPassword, 'StudentOnlyPass1', 'StudentOnlyPass1');
        $foreignIgnored = fetch_password_hash($lecturer['user_id']) === $lecturerHashBefore
            && password_verify('StudentOnlyPass1', fetch_password_hash($student['user_id']));
    } catch (Throwable $exception) {
        fail('R Posted foreign user_id cannot change another user\'s password', $exception->getMessage());
        $foreignIgnored = false;
    }
    if ($foreignIgnored) {
        pass('R Posted foreign user_id cannot change another user\'s password');
    } elseif (!$failed) {
        fail('R Posted foreign user_id cannot change another user\'s password');
    }
    $newPassword = 'StudentOnlyPass1';

    // S Foreign profile ids cannot widen update scope
    $otherStudent = db()->query(
        'SELECT student_id, user_id, first_name FROM students WHERE user_id <> ' . $student['user_id'] . ' ORDER BY student_id LIMIT 1'
    )->fetch();
    if ($otherStudent !== false) {
        $otherNameBefore = (string) $otherStudent['first_name'];
        update_own_profile($student['user_id'], [
            'email' => (string) load_own_profile($student['user_id'])['account']['email'],
            'first_name' => (string) load_own_profile($student['user_id'])['person']['first_name'],
            'last_name' => (string) load_own_profile($student['user_id'])['person']['last_name'],
            'phone' => (string) (load_own_profile($student['user_id'])['person']['phone'] ?? ''),
            'student_id' => (int) $otherStudent['student_id'],
            'user_id' => (int) $otherStudent['user_id'],
        ]);
        $otherNameAfter = (string) db()->query(
            'SELECT first_name FROM students WHERE student_id = ' . (int) $otherStudent['student_id']
        )->fetchColumn();
        assert_true($otherNameAfter === $otherNameBefore, 'S Posted foreign profile/student/lecturer id cannot widen update scope');
    } else {
        pass('S Posted foreign profile/student/lecturer id cannot widen update scope (skipped — only one student)');
    }

    // T Existing login still works with changed password (login verification path without CLI session noise)
    $loginCheck = db()->prepare(
        'SELECT password_hash, status, role FROM users WHERE username = :username LIMIT 1'
    );
    $loginCheck->execute(['username' => (string) $student['username']]);
    $loginRow = $loginCheck->fetch();
    assert_true(
        $loginRow !== false
        && password_verify($newPassword, (string) $loginRow['password_hash'])
        && (string) $loginRow['status'] === 'ACTIVE'
        && (string) $loginRow['role'] === 'STUDENT',
        'T Existing login still works with changed password'
    );

    // Restore student password hash
    db()->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :id')->execute([
        'hash' => $originalHashes[$student['user_id']],
        'id' => $student['user_id'],
    ]);

    $attendanceAfter = (int) db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
    $sessionsAfter = (int) db()->query('SELECT COUNT(*) FROM lecture_sessions')->fetchColumn();
    $assignmentsAfter = (int) db()->query('SELECT COUNT(*) FROM assignments')->fetchColumn();
    $marksAfter = (int) db()->query('SELECT COUNT(*) FROM marks')->fetchColumn();
    assert_true(
        $attendanceAfter === $attendanceBefore
        && $sessionsAfter === $sessionsBefore
        && $assignmentsAfter === $assignmentsBefore
        && $marksAfter === $marksBefore,
        'U Unrelated academic/attendance/coursework row counts unchanged'
    );

    // Route/nav sanity
    foreach (['ADMIN', 'ACADEMIC_STAFF', 'LECTURER', 'STUDENT'] as $role) {
        $path = role_profile_path($role);
        assert_true(str_ends_with($path, '/profile.php'), 'Profile route exists for ' . $role, $path);
    }

    $layout = file_get_contents(dirname(__DIR__, 2) . '/web/shared/includes/dashboard-layout-start.php');
    assert_true(
        is_string($layout) && str_contains($layout, 'My Profile') && str_contains($layout, 'role_profile_path'),
        'Navigation includes My Profile near account area'
    );

    $page = file_get_contents(dirname(__DIR__, 2) . '/web/shared/pages/profile/index.php');
    assert_true(
        is_string($page)
        && str_contains($page, 'change_own_password')
        && str_contains($page, 'update_own_profile')
        && str_contains($page, '$authenticatedUserId')
        && !preg_match('/\$_GET\s*\[\s*[\'"]id[\'"]\s*\]/', $page),
        'Profile page uses session user only (no profile?id=)'
    );
} catch (Throwable $exception) {
    fail('Unhandled exception', $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
}

// Restore any remembered password hashes for non-temp accounts
foreach ($originalHashes as $userId => $hash) {
    if (in_array($userId, $tempUserIds, true)) {
        continue;
    }
    db()->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :id')->execute([
        'hash' => $hash,
        'id' => $userId,
    ]);
}

foreach ($tempStudentIds as $studentId) {
    db()->prepare('DELETE FROM student_modules WHERE student_id = :id')->execute(['id' => $studentId]);
    db()->prepare('DELETE FROM students WHERE student_id = :id')->execute(['id' => $studentId]);
}
foreach ($tempLecturerIds as $lecturerId) {
    db()->prepare('DELETE FROM module_lecturers WHERE lecturer_id = :id')->execute(['id' => $lecturerId]);
    db()->prepare('DELETE FROM lecturers WHERE lecturer_id = :id')->execute(['id' => $lecturerId]);
}
foreach ($tempStaffIds as $staffId) {
    db()->prepare('DELETE FROM academic_staff WHERE academic_staff_id = :id')->execute(['id' => $staffId]);
}
foreach ($tempUserIds as $userId) {
    db()->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $userId]);
}

if ($failed) {
    fwrite(STDERR, "Some My Profile tests failed.\n");
    exit(1);
}

echo 'All My Profile regression tests passed.' . PHP_EOL;
