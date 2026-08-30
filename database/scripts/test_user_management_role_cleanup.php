<?php

declare(strict_types=1);

/**
 * Regression: User Management Role Cleanup v1.
 *
 * User Management manages ADMIN / ACADEMIC_STAFF / LECTURER only.
 * STUDENT accounts remain in users but are owned by Student Management.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_user_management_role_cleanup.php
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
$tempStudentId = null;
$tempUserId = null;
$tempLecturerId = null;
$tempLecturerUserId = null;
$tempStaffId = null;
$tempStaffUserId = null;
$tempAdminUserId = null;
$orphanUserId = null;
$createdUserIds = [];

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
    $password = 'RoleCleanup123!';

    $indexSource = (string) file_get_contents(dirname(__DIR__, 2) . '/web/admin/users/index.php');
    $createSource = (string) file_get_contents(dirname(__DIR__, 2) . '/web/admin/users/create.php');
    $editSource = (string) file_get_contents(dirname(__DIR__, 2) . '/web/admin/users/edit.php');

    assert_true(
        user_management_roles() === ['ADMIN', 'ACADEMIC_STAFF', 'LECTURER'],
        'B user_management_roles excludes STUDENT'
    );
    assert_true(
        str_contains($indexSource, 'user_management_roles')
        && !str_contains($indexSource, 'AUTH_ROLES')
        && !str_contains($indexSource, 'Manage Student'),
        'A/B User Management list/filter uses staff roles only'
    );
    assert_true(
        str_contains($createSource, 'user_management_roles')
        && !str_contains($createSource, 'AUTH_ROLES')
        && str_contains($createSource, 'Register Student'),
        'C Create User form does not offer STUDENT'
    );
    assert_true(
        str_contains($editSource, "role'] === 'STUDENT'")
        && str_contains($editSource, 'student_accounts_managed_elsewhere_message')
        && str_contains($editSource, 'admin/students/edit.php'),
        'E Edit User denies STUDENT and can redirect to Student Edit'
    );

    $tempStudentId = register_student([
        'username' => 'rolecl_' . $suffix,
        'email' => 'rolecl_' . $suffix . '@example.test',
        'password' => $password,
        'account_status' => 'ACTIVE',
        'registration_no' => 'ROLECL-' . $suffix,
        'first_name' => 'Role',
        'last_name' => 'Cleanup',
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

    assert_true(
        (string) get_user($tempUserId)['role'] === 'STUDENT',
        'G Student Registration still creates STUDENT user correctly'
    );

    $listed = list_users([]);
    $listedIds = array_map(static fn (array $row): int => (int) $row['user_id'], $listed);
    $listedRoles = array_unique(array_map(static fn (array $row): string => (string) $row['role'], $listed));
    assert_true(!in_array($tempUserId, $listedIds, true), 'A User Management list excludes STUDENT');
    assert_true(
        empty(array_diff($listedRoles, user_management_roles())),
        'A list_users returns only management roles',
        implode(',', $listedRoles)
    );

    $studentFilter = list_users(['role' => 'STUDENT']);
    assert_true($studentFilter === [], 'B Role filter STUDENT yields empty (ignored / excluded)');

    $forgedCreateRejected = false;
    $forgedCreateMessage = '';
    try {
        create_user([
            'username' => 'forged_stu_' . $suffix,
            'email' => 'forged_stu_' . $suffix . '@example.test',
            'password' => 'ForgedStudent1!',
            'role' => 'STUDENT',
            'status' => 'ACTIVE',
        ]);
    } catch (InvalidArgumentException $exception) {
        $forgedCreateRejected = true;
        $forgedCreateMessage = $exception->getMessage();
    }
    assert_true($forgedCreateRejected, 'D Forged create with role STUDENT rejected');
    assert_true(
        str_contains($forgedCreateMessage, 'Student Management'),
        'D forged create message points to Student Management',
        $forgedCreateMessage
    );
    assert_true(
        (int) db()->query(
            "SELECT COUNT(*) FROM users WHERE username = 'forged_stu_{$suffix}'"
        )->fetchColumn() === 0,
        'D forged STUDENT create did not insert a users row'
    );

    // E — direct edit denial (helpers + redirect policy in edit.php source above)
    $editDenied = false;
    $editMessage = '';
    try {
        update_user($tempUserId, [
            'username' => (string) $student['username'],
            'email' => (string) $student['email'],
            'role' => 'STUDENT',
            'status' => 'ACTIVE',
        ]);
    } catch (InvalidArgumentException $exception) {
        $editDenied = true;
        $editMessage = $exception->getMessage();
    }
    assert_true($editDenied, 'E Direct update_user for STUDENT denied');
    assert_true(
        str_contains($editMessage, 'Student Management'),
        'E denial message is clear',
        $editMessage
    );

    $statusRejected = false;
    try {
        set_user_status($tempUserId, 'INACTIVE');
    } catch (InvalidArgumentException $exception) {
        $statusRejected = true;
    }
    assert_true($statusRejected, 'F Generic status mutation for STUDENT still rejected');
    assert_true(
        (string) get_student($tempStudentId)['status'] === 'ACTIVE'
        && (string) get_student($tempStudentId)['account_status'] === 'ACTIVE',
        'F statuses unchanged after rejected mutation'
    );

    assert_true(attempt_login('rolecl_' . $suffix, $password) === true, 'H Student login still works');

    deactivate_student($tempStudentId);
    $afterDeact = get_student($tempStudentId);
    assert_true(
        (string) $afterDeact['status'] === 'INACTIVE' && (string) $afterDeact['account_status'] === 'INACTIVE',
        'I Student Deactivate still works'
    );
    assert_true(attempt_login('rolecl_' . $suffix, $password) === false, 'I Inactive login blocked');

    admin_reset_student_password($tempStudentId, 'RoleReset999!', 'RoleReset999!');
    assert_true(
        (string) get_student($tempStudentId)['status'] === 'INACTIVE'
        && (string) get_student($tempStudentId)['account_status'] === 'INACTIVE',
        'J Student Password Reset does not reactivate'
    );
    assert_true(attempt_login('rolecl_' . $suffix, 'RoleReset999!') === false, 'J Inactive still cannot login after reset');

    reactivate_student($tempStudentId);
    assert_true(
        (string) get_student($tempStudentId)['status'] === 'ACTIVE'
        && (string) get_student($tempStudentId)['account_status'] === 'ACTIVE',
        'I Student Reactivate still works'
    );
    assert_true(attempt_login('rolecl_' . $suffix, 'RoleReset999!') === true, 'H/I Reactivated student login works');

    // Orphan STUDENT remains in DB but excluded from list.
    db()->prepare(
        "INSERT INTO users (username, email, password_hash, role, status)
         VALUES (:username, :email, :password_hash, 'STUDENT', 'ACTIVE')"
    )->execute([
        'username' => 'orphan_rc_' . $suffix,
        'email' => 'orphan_rc_' . $suffix . '@example.test',
        'password_hash' => password_hash('OrphanRoleCl1!', PASSWORD_DEFAULT),
    ]);
    $orphanUserId = (int) db()->lastInsertId();
    assert_true(get_student_id_by_user_id($orphanUserId) === null, 'Orphan STUDENT has no profile');
    $listedAfterOrphan = list_users([]);
    $listedAfterIds = array_map(static fn (array $row): int => (int) $row['user_id'], $listedAfterOrphan);
    assert_true(!in_array($orphanUserId, $listedAfterIds, true), 'A Orphan STUDENT excluded from User Management list');

    // K/L/M non-student management
    $tempAdminUserId = create_user([
        'username' => 'admin_rc_' . $suffix,
        'email' => 'admin_rc_' . $suffix . '@example.test',
        'password' => 'AdminRoleCl1!',
        'role' => 'ADMIN',
        'status' => 'ACTIVE',
    ]);
    $createdUserIds[] = $tempAdminUserId;
    set_user_status($tempAdminUserId, 'INACTIVE');
    update_user($tempAdminUserId, [
        'username' => 'admin_rc_' . $suffix,
        'email' => 'admin_rc_' . $suffix . '@example.test',
        'role' => 'ADMIN',
        'status' => 'ACTIVE',
    ]);
    assert_true((string) get_user($tempAdminUserId)['status'] === 'ACTIVE', 'K Admin user management still works');

    $tempStaffId = create_academic_staff_member([
        'username' => 'staff_rc_' . $suffix,
        'email' => 'staff_rc_' . $suffix . '@example.test',
        'password' => 'StaffRoleCl1!',
        'account_status' => 'ACTIVE',
        'staff_no' => 'STAFF-RC-' . $suffix,
        'first_name' => 'Staff',
        'last_name' => 'Cleanup',
        'phone' => '',
        'position' => 'Tester',
        'status' => 'ACTIVE',
    ]);
    $tempStaffUserId = (int) get_academic_staff_member($tempStaffId)['user_id'];
    set_user_status($tempStaffUserId, 'INACTIVE');
    set_user_status($tempStaffUserId, 'ACTIVE');
    assert_true((string) get_user($tempStaffUserId)['status'] === 'ACTIVE', 'L Academic Staff user management still works');

    $tempLecturerId = create_lecturer([
        'username' => 'lec_rc_' . $suffix,
        'email' => 'lec_rc_' . $suffix . '@example.test',
        'password' => 'LecRoleCl1!',
        'account_status' => 'ACTIVE',
        'staff_no' => 'LEC-RC-' . $suffix,
        'first_name' => 'Lec',
        'last_name' => 'Cleanup',
        'phone' => '',
        'department' => 'Test',
        'status' => 'ACTIVE',
    ]);
    $tempLecturerUserId = (int) get_lecturer($tempLecturerId)['user_id'];
    set_user_status($tempLecturerUserId, 'INACTIVE');
    set_user_status($tempLecturerUserId, 'ACTIVE');
    assert_true((string) get_user($tempLecturerUserId)['status'] === 'ACTIVE', 'M Lecturer user management still works');

    // Cannot convert staff role to STUDENT via update_user.
    $toStudentRejected = false;
    try {
        update_user($tempLecturerUserId, [
            'username' => 'lec_rc_' . $suffix,
            'email' => 'lec_rc_' . $suffix . '@example.test',
            'role' => 'STUDENT',
            'status' => 'ACTIVE',
        ]);
    } catch (InvalidArgumentException $exception) {
        $toStudentRejected = true;
    }
    assert_true($toStudentRejected, 'D/M Cannot change non-student role to STUDENT via User Management');
    assert_true((string) get_user($tempLecturerUserId)['role'] === 'LECTURER', 'M Lecturer role unchanged');
} catch (Throwable $exception) {
    fail('FATAL', $exception->getMessage());
} finally {
    try {
        if ($tempStudentId) {
            db()->prepare('DELETE FROM student_modules WHERE student_id = :id')->execute(['id' => $tempStudentId]);
            db()->prepare('DELETE FROM students WHERE student_id = :id')->execute(['id' => $tempStudentId]);
        }
        if ($tempUserId) {
            db()->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $tempUserId]);
        }
        if ($orphanUserId) {
            db()->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $orphanUserId]);
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
        foreach ($createdUserIds as $uid) {
            db()->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $uid]);
        }
        if ($tempAdminUserId && !in_array($tempAdminUserId, $createdUserIds, true)) {
            db()->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $tempAdminUserId]);
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
