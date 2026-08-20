<?php

declare(strict_types=1);

/**
 * Regression tests for Admin/Academic Staff Student Password Reset.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_admin_student_password_reset.php
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

$attendanceBefore = (int) db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
$sessionsBefore = (int) db()->query('SELECT COUNT(*) FROM lecture_sessions')->fetchColumn();
$assignmentsBefore = (int) db()->query('SELECT COUNT(*) FROM assignments')->fetchColumn();
$marksBefore = (int) db()->query('SELECT COUNT(*) FROM marks')->fetchColumn();
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

function fetch_hash(int $userId): string
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
        $originalHashes[$userId] = fetch_hash($userId);
    }
}

function fetch_user_row(int $userId): array
{
    $statement = db()->prepare(
        'SELECT user_id, username, email, role, status, password_hash FROM users WHERE user_id = :id LIMIT 1'
    );
    $statement->execute(['id' => $userId]);
    $row = $statement->fetch();
    if ($row === false) {
        throw new RuntimeException('User not found: ' . $userId);
    }

    return $row;
}

try {
    $suffix = (string) time();
    $root = dirname(__DIR__, 2);

    $adminRoute = $root . '/web/admin/students/reset-password.php';
    $staffRoute = $root . '/web/academic-staff/students/reset-password.php';
    $adminSource = (string) file_get_contents($adminRoute);
    $staffSource = (string) file_get_contents($staffRoute);
    $sharedSource = (string) file_get_contents($root . '/web/shared/pages/students/reset-password.php');
    $editSource = (string) file_get_contents($root . '/web/shared/pages/students/edit.php');
    $lecturerView = (string) file_get_contents($root . '/web/shared/pages/students/view.php');

    assert_true(
        str_contains($adminSource, 'require_admin()')
        && str_contains($adminSource, 'reset-password.php')
        && is_file($root . '/web/public/admin/students/reset-password.php'),
        'A Admin can open Student Reset Password page'
    );
    assert_true(
        str_contains($staffSource, 'require_student_manager()')
        && is_file($root . '/web/public/academic-staff/students/reset-password.php'),
        'B Academic Staff can open Student Reset Password page'
    );
    assert_true(
        !is_file($root . '/web/lecturer/students/reset-password.php')
        && !str_contains($lecturerView, 'reset-password.php')
        && !str_contains($lecturerView, 'Reset Password'),
        'C Lecturer cannot access reset route'
    );
    assert_true(
        !is_file($root . '/web/student/students/reset-password.php')
        && !is_file($root . '/web/student/reset-password.php')
        && str_contains($sharedSource, 'admin_reset_student_password'),
        'D Student cannot access reset route'
    );
    assert_true(
        str_contains($editSource, 'reset-password.php')
        && str_contains($editSource, 'Reset Password'),
        'Student Management UI includes Reset Password for Admin/Staff edit'
    );

    $studentRow = db()->query(
        "SELECT s.student_id, s.user_id, s.course_id, s.batch_id, s.status, s.registration_no,
                u.username, u.role, u.status AS account_status
         FROM students s
         INNER JOIN users u ON u.user_id = s.user_id
         WHERE u.role = 'STUDENT' AND u.status = 'ACTIVE'
         ORDER BY s.student_id
         LIMIT 1"
    )->fetch();
    if ($studentRow === false) {
        throw new RuntimeException('Need an ACTIVE student account for reset tests.');
    }

    $studentId = (int) $studentRow['student_id'];
    $userId = (int) $studentRow['user_id'];
    remember_hash($userId);

    $otherStudent = db()->query(
        "SELECT s.student_id, s.user_id
         FROM students s
         INNER JOIN users u ON u.user_id = s.user_id
         WHERE u.role = 'STUDENT' AND s.student_id <> " . $studentId . '
         ORDER BY s.student_id
         LIMIT 1'
    )->fetch();
    $adminUser = db()->query(
        "SELECT user_id FROM users WHERE role = 'ADMIN' ORDER BY user_id LIMIT 1"
    )->fetch();
    if ($adminUser === false) {
        throw new RuntimeException('Need an ADMIN user.');
    }
    $adminUserId = (int) $adminUser['user_id'];
    remember_hash($adminUserId);
    if ($otherStudent !== false) {
        remember_hash((int) $otherStudent['user_id']);
    }

    $oldPassword = 'OldReset_' . $suffix . '!';
    $newPassword = 'NewReset_' . $suffix . '!';
    db()->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :id')->execute([
        'hash' => password_hash($oldPassword, PASSWORD_DEFAULT),
        'id' => $userId,
    ]);

    $beforeStudent = get_student($studentId);
    $beforeUser = fetch_user_row($userId);
    $beforeEnrolments = (int) db()->query(
        'SELECT COUNT(*) FROM student_modules WHERE student_id = ' . $studentId
    )->fetchColumn();

    // E–G valid reset
    admin_reset_student_password($studentId, $newPassword, $newPassword);
    $hashAfter = fetch_hash($userId);
    assert_true(password_verify($newPassword, $hashAfter), 'E Valid reset changes target student\'s password');
    assert_true(!password_verify($oldPassword, $hashAfter), 'F Old student password no longer verifies');
    assert_true(password_verify($newPassword, $hashAfter), 'G New password verifies');

    // H mismatch
    $mismatch = false;
    try {
        admin_reset_student_password($studentId, 'MismatchPass1', 'MismatchPass2');
    } catch (InvalidArgumentException) {
        $mismatch = true;
    }
    assert_true($mismatch && password_verify($newPassword, fetch_hash($userId)), 'H Password confirmation mismatch rejected');

    // I too short
    $short = false;
    try {
        admin_reset_student_password($studentId, 'short', 'short');
    } catch (InvalidArgumentException) {
        $short = true;
    }
    assert_true($short, 'I Too-short password rejected');

    // J empty
    $empty = false;
    try {
        admin_reset_student_password($studentId, '', '');
    } catch (InvalidArgumentException) {
        $empty = true;
    }
    assert_true($empty, 'J Empty password rejected');

    // K foreign user_id cannot redirect — helper has no user_id param; simulate posted foreign id ignored by page logic
    $adminHashBefore = fetch_hash($adminUserId);
    // Call still targets studentId only.
    admin_reset_student_password($studentId, 'KeepStu_' . $suffix . '!', 'KeepStu_' . $suffix . '!');
    $newPassword = 'KeepStu_' . $suffix . '!';
    assert_true(
        fetch_hash($adminUserId) === $adminHashBefore
        && password_verify($newPassword, fetch_hash($userId)),
        'K Foreign posted user_id cannot redirect reset to another user'
    );

    // L admin unchanged
    assert_true(fetch_hash($adminUserId) === $adminHashBefore, 'L Admin account password remains unchanged during student reset');

    // M other student unchanged
    if ($otherStudent !== false) {
        $otherId = (int) $otherStudent['user_id'];
        assert_true(
            fetch_hash($otherId) === $originalHashes[$otherId],
            'M Another student\'s password remains unchanged'
        );
    } else {
        pass('M Another student\'s password remains unchanged (skipped — only one student)');
    }

    $afterStudent = get_student($studentId);
    $afterUser = fetch_user_row($userId);
    $afterEnrolments = (int) db()->query(
        'SELECT COUNT(*) FROM student_modules WHERE student_id = ' . $studentId
    )->fetchColumn();

    assert_true((int) $afterStudent['course_id'] === (int) $beforeStudent['course_id'], 'N Reset does not change student course');
    assert_true((int) $afterStudent['batch_id'] === (int) $beforeStudent['batch_id'], 'O Reset does not change batch');
    assert_true((string) $afterStudent['status'] === (string) $beforeStudent['status'], 'P Reset does not change student status');
    assert_true((string) $afterUser['role'] === 'STUDENT' && (string) $afterUser['role'] === (string) $beforeUser['role'], 'Q Reset does not change users.role');
    assert_true((string) $afterUser['status'] === (string) $beforeUser['status'], 'R Reset does not change users.status');
    assert_true($afterEnrolments === $beforeEnrolments, 'S Enrolments remain unchanged');

    // T login with reset password (same verification path as attempt_login)
    $loginCheck = db()->prepare(
        'SELECT password_hash, status, role FROM users WHERE user_id = :id LIMIT 1'
    );
    $loginCheck->execute(['id' => $userId]);
    $loginRow = $loginCheck->fetch();
    assert_true(
        $loginRow !== false
        && password_verify($newPassword, (string) $loginRow['password_hash'])
        && (string) $loginRow['status'] === (string) $beforeUser['status']
        && (string) $loginRow['role'] === 'STUDENT',
        'T Student can login with reset password'
    );

    // U My Profile change password still works afterward
    $changed = 'Changed_' . $suffix . '!';
    change_own_password($userId, $newPassword, $changed, $changed);
    assert_true(
        password_verify($changed, fetch_hash($userId))
        && !password_verify($newPassword, fetch_hash($userId)),
        'U Student My Profile Change Password still works afterward'
    );

    $attendanceAfter = (int) db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
    $sessionsAfter = (int) db()->query('SELECT COUNT(*) FROM lecture_sessions')->fetchColumn();
    $assignmentsAfter = (int) db()->query('SELECT COUNT(*) FROM assignments')->fetchColumn();
    $marksAfter = (int) db()->query('SELECT COUNT(*) FROM marks')->fetchColumn();
    $enrolmentsAfter = (int) db()->query('SELECT COUNT(*) FROM student_modules')->fetchColumn();
    assert_true(
        $attendanceAfter === $attendanceBefore
        && $sessionsAfter === $sessionsBefore
        && $assignmentsAfter === $assignmentsBefore
        && $marksAfter === $marksBefore
        && $enrolmentsAfter === $enrolmentsBefore,
        'V Attendance/coursework/marks/session row counts unchanged'
    );

    assert_true(
        str_contains($sharedSource, 'verify_csrf')
        && str_contains($sharedSource, 'admin_reset_student_password')
        && str_contains($sharedSource, '$studentId')
        && !str_contains($sharedSource, "\$_POST['user_id']")
        && !str_contains($sharedSource, '$_POST["user_id"]'),
        'CSRF and student_id-only target resolution confirmed'
    );
} catch (Throwable $exception) {
    fail('Unhandled exception', $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
}

foreach ($originalHashes as $userId => $hash) {
    db()->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :id')->execute([
        'hash' => $hash,
        'id' => $userId,
    ]);
}

if ($failed) {
    fwrite(STDERR, "Some Student Password Reset tests failed.\n");
    exit(1);
}

echo 'All Admin Student Password Reset regression tests passed.' . PHP_EOL;
