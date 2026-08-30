<?php

declare(strict_types=1);

/**
 * Orphan Lecturer / Academic Staff profile completion repair.
 *
 * Uses temporary fixtures only — does not invent data for production orphans.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_orphan_staff_profile_completion.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$root = dirname(__DIR__, 2);
require_once $root . '/web/shared/includes/init.php';

$failed = 0;
$cleanup = [
    'user_ids' => [],
    'lecturer_ids' => [],
    'staff_ids' => [],
];

function pass(string $label): void
{
    echo "PASS {$label}\n";
}

function fail(string $label, string $detail = ''): void
{
    global $failed;
    $failed++;
    echo "FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function expect_invalid(callable $fn): ?string
{
    try {
        $fn();
        return null;
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
}

$suffix = strtoupper(bin2hex(random_bytes(3)));
$pdo = db();
$passwordHash = password_hash('OrphanRepairPass1!', PASSWORD_DEFAULT);

try {
    $indexSrc = (string) file_get_contents($root . '/web/admin/users/index.php');
    $lecPage = (string) file_get_contents($root . '/web/admin/users/complete-lecturer-profile.php');
    $staffPage = (string) file_get_contents($root . '/web/admin/users/complete-staff-profile.php');
    if (
        str_contains($indexSrc, 'Complete Lecturer Profile')
        && str_contains($indexSrc, 'Complete Staff Profile')
        && str_contains($lecPage, 'complete_lecturer_profile_for_user')
        && str_contains($staffPage, 'complete_academic_staff_profile_for_user')
        && str_contains($lecPage, 'require_admin()')
        && str_contains($staffPage, 'require_admin()')
        && str_contains($lecPage, 'verify_csrf')
        && str_contains($staffPage, 'verify_csrf')
    ) {
        pass('Repair UI actions and Admin/CSRF pages present');
    } else {
        fail('Repair UI actions and Admin/CSRF pages present');
    }

    // A lecturer1 remains unchanged (snapshot before fixtures)
    $lecturer1 = $pdo->query(
        "SELECT u.user_id, u.username, u.email, u.password_hash, u.status, u.role,
                l.lecturer_id, l.staff_no, l.first_name, l.last_name, l.phone, l.department, l.status AS profile_status
         FROM users u
         INNER JOIN lecturers l ON l.user_id = u.user_id
         WHERE u.username = 'lecturer1'
         LIMIT 1"
    )->fetch();
    if ($lecturer1 === false) {
        fail('A lecturer1 remains unchanged', 'lecturer1 not found with profile');
    } else {
        $lec1Snapshot = $lecturer1;
        pass('A lecturer1 baseline captured (has profile)');
    }

    // Create orphan LECTURER user (users only) — fixture
    $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, role, status)
         VALUES (:u, :e, :p, 'LECTURER', 'ACTIVE')"
    )->execute([
        'u' => 'orp_lec_' . $suffix,
        'e' => 'orp_lec_' . $suffix . '@example.test',
        'p' => $passwordHash,
    ]);
    $orphanLecUserId = (int) $pdo->lastInsertId();
    $cleanup['user_ids'][] = $orphanLecUserId;
    $hashStmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = :id');
    $hashStmt->execute(['id' => $orphanLecUserId]);
    $hashBefore = (string) $hashStmt->fetchColumn();

    // Create orphan ACADEMIC_STAFF users
    $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, role, status)
         VALUES (:u, :e, :p, 'ACADEMIC_STAFF', 'ACTIVE')"
    )->execute([
        'u' => 'orp_stf_' . $suffix,
        'e' => 'orp_stf_' . $suffix . '@example.test',
        'p' => $passwordHash,
    ]);
    $orphanStaffUserId = (int) $pdo->lastInsertId();
    $cleanup['user_ids'][] = $orphanStaffUserId;
    $hashStmt->execute(['id' => $orphanStaffUserId]);
    $staffHashBefore = (string) $hashStmt->fetchColumn();

    $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, role, status)
         VALUES (:u, :e, :p, 'ACADEMIC_STAFF', 'INACTIVE')"
    )->execute([
        'u' => 'orp_stf2_' . $suffix,
        'e' => 'orp_stf2_' . $suffix . '@example.test',
        'p' => $passwordHash,
    ]);
    $orphanStaff2UserId = (int) $pdo->lastInsertId();
    $cleanup['user_ids'][] = $orphanStaff2UserId;

    // B complete lecturer profile without new user
    $usersBefore = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $lecId = complete_lecturer_profile_for_user($orphanLecUserId, [
        'staff_no' => 'ORP-LEC-' . $suffix,
        'first_name' => 'Orphan',
        'last_name' => 'Lecturer',
        'phone' => '',
        'department' => 'QA',
    ]);
    $cleanup['lecturer_ids'][] = $lecId;
    $usersAfter = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $lec = get_lecturer($lecId);
    if (
        $usersAfter === $usersBefore
        && $lec !== null
        && (int) $lec['user_id'] === $orphanLecUserId
        && get_lecturer_id_by_user_id($orphanLecUserId) === $lecId
    ) {
        $hashStmt->execute(['id' => $orphanLecUserId]);
        $hashAfter = (string) $hashStmt->fetchColumn();
        if ($hashAfter === $hashBefore) {
            pass('B lecturer can have its missing profile completed without a new user');
        } else {
            fail('B lecturer can have its missing profile completed without a new user', 'password changed');
        }
    } else {
        fail('B lecturer can have its missing profile completed without a new user');
    }

    // C/D staff completions
    $usersBeforeC = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $staffId = complete_academic_staff_profile_for_user($orphanStaffUserId, [
        'staff_no' => 'ORP-STF-' . $suffix,
        'first_name' => 'Orphan',
        'last_name' => 'Staff',
        'phone' => '',
        'position' => 'Coordinator',
    ]);
    $cleanup['staff_ids'][] = $staffId;
    $staff = get_academic_staff_member($staffId);
    if (
        (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $usersBeforeC
        && $staff !== null
        && (int) $staff['user_id'] === $orphanStaffUserId
    ) {
        $hashStmt->execute(['id' => $orphanStaffUserId]);
        if ((string) $hashStmt->fetchColumn() === $staffHashBefore) {
            pass('C Staff can have its missing profile completed without a new user');
        } else {
            fail('C Staff can have its missing profile completed without a new user', 'password changed');
        }
    } else {
        fail('C Staff can have its missing profile completed without a new user');
    }

    $staff2Id = complete_academic_staff_profile_for_user($orphanStaff2UserId, [
        'staff_no' => 'ORP-STF2-' . $suffix,
        'first_name' => 'Second',
        'last_name' => 'Staff',
        'phone' => '',
        'position' => '',
    ]);
    $cleanup['staff_ids'][] = $staff2Id;
    $staff2 = get_academic_staff_member($staff2Id);
    // I status synchronized — INACTIVE account → INACTIVE profile
    if ($staff2 !== null && (string) $staff2['status'] === 'INACTIVE' && (string) get_user($orphanStaff2UserId)['status'] === 'INACTIVE') {
        pass('D academic_staff can have its missing profile completed without a new user');
        pass('I status remains synchronized');
    } else {
        fail('D academic_staff can have its missing profile completed without a new user');
        fail('I status remains synchronized');
    }

    // E duplicate profiles prevented
    $dupL = expect_invalid(static function () use ($orphanLecUserId, $suffix): void {
        complete_lecturer_profile_for_user($orphanLecUserId, [
            'staff_no' => 'ORP-LEC-DUP-' . $suffix,
            'first_name' => 'Dup',
            'last_name' => 'Lec',
        ]);
    });
    $dupS = expect_invalid(static function () use ($orphanStaffUserId, $suffix): void {
        complete_academic_staff_profile_for_user($orphanStaffUserId, [
            'staff_no' => 'ORP-STF-DUP-' . $suffix,
            'first_name' => 'Dup',
            'last_name' => 'Staff',
        ]);
    });
    ($dupL !== null && $dupS !== null) ? pass('E duplicate profiles are prevented') : fail('E duplicate profiles are prevented');

    // F wrong-role rejected
    $adminId = create_user([
        'username' => 'orp_adm_' . $suffix,
        'email' => 'orp_adm_' . $suffix . '@example.test',
        'password' => 'OrphanRepairPass1!',
        'role' => 'ADMIN',
        'status' => 'ACTIVE',
    ]);
    $cleanup['user_ids'][] = $adminId;
    $wrong = expect_invalid(static function () use ($adminId, $suffix): void {
        complete_lecturer_profile_for_user($adminId, [
            'staff_no' => 'ORP-WRONG-' . $suffix,
            'first_name' => 'Wrong',
            'last_name' => 'Role',
        ]);
    });
    ($wrong !== null && str_contains($wrong, 'Only Lecturer'))
        ? pass('F wrong-role completion is rejected')
        : fail('F wrong-role completion is rejected', (string) $wrong);

    // G invalid user_id
    $invalid = expect_invalid(static function (): void {
        complete_lecturer_profile_for_user(99999999, [
            'staff_no' => 'X',
            'first_name' => 'X',
            'last_name' => 'Y',
        ]);
    });
    ($invalid !== null && str_contains($invalid, 'User not found'))
        ? pass('G invalid user_id is rejected')
        : fail('G invalid user_id is rejected');

    // H passwords unchanged already checked in B/C
    pass('H passwords/accounts are unchanged');

    // J/K new create still works
    $newLecUser = create_user([
        'username' => 'orp_newlec_' . $suffix,
        'email' => 'orp_newlec_' . $suffix . '@example.test',
        'password' => 'OrphanRepairPass1!',
        'role' => 'LECTURER',
        'status' => 'ACTIVE',
        'staff_no' => 'ORP-NEWLEC-' . $suffix,
        'first_name' => 'New',
        'last_name' => 'Lec',
        'department' => '',
        'phone' => '',
    ]);
    $cleanup['user_ids'][] = $newLecUser;
    $newLecId = get_lecturer_id_by_user_id($newLecUser);
    if ($newLecId !== null) {
        $cleanup['lecturer_ids'][] = $newLecId;
    }
    ($newLecId !== null) ? pass('J new User Management Lecturer creation still works') : fail('J new User Management Lecturer creation still works');

    $newStaffUser = create_user([
        'username' => 'orp_newstf_' . $suffix,
        'email' => 'orp_newstf_' . $suffix . '@example.test',
        'password' => 'OrphanRepairPass1!',
        'role' => 'ACADEMIC_STAFF',
        'status' => 'ACTIVE',
        'staff_no' => 'ORP-NEWSTF-' . $suffix,
        'first_name' => 'New',
        'last_name' => 'Staff',
        'position' => '',
        'phone' => '',
    ]);
    $cleanup['user_ids'][] = $newStaffUser;
    $newStaffId = get_academic_staff_id_by_user_id($newStaffUser);
    if ($newStaffId !== null) {
        $cleanup['staff_ids'][] = $newStaffId;
    }
    ($newStaffId !== null) ? pass('K new Academic Staff creation still works') : fail('K new Academic Staff creation still works');

    // L student management
    $stu = expect_invalid(static function () use ($suffix): void {
        create_user([
            'username' => 'orp_stu_' . $suffix,
            'email' => 'orp_stu_' . $suffix . '@example.test',
            'password' => 'OrphanRepairPass1!',
            'role' => 'STUDENT',
            'status' => 'ACTIVE',
        ]);
    });
    ($stu !== null && str_contains($stu, 'Student Management'))
        ? pass('L Student Management unaffected')
        : fail('L Student Management unaffected');

    // M no schema
    $touched = [
        $root . '/web/admin/users/complete-lecturer-profile.php',
        $root . '/web/admin/users/complete-staff-profile.php',
        $root . '/web/admin/users/index.php',
    ];
    $schemaHit = false;
    foreach ($touched as $path) {
        if (preg_match('/\b(CREATE TABLE|ALTER TABLE|DROP TABLE)\b/i', (string) file_get_contents($path))) {
            $schemaHit = true;
        }
    }
    !$schemaHit ? pass('M No schema change') : fail('M No schema change');

    // A verify lecturer1 unchanged after all fixture work
    if (isset($lec1Snapshot)) {
        $after = $pdo->query(
            "SELECT u.user_id, u.username, u.email, u.password_hash, u.status, u.role,
                    l.lecturer_id, l.staff_no, l.first_name, l.last_name, l.phone, l.department, l.status AS profile_status
             FROM users u
             INNER JOIN lecturers l ON l.user_id = u.user_id
             WHERE u.username = 'lecturer1'
             LIMIT 1"
        )->fetch();
        $same = $after !== false
            && (int) $after['user_id'] === (int) $lec1Snapshot['user_id']
            && (int) $after['lecturer_id'] === (int) $lec1Snapshot['lecturer_id']
            && (string) $after['password_hash'] === (string) $lec1Snapshot['password_hash']
            && (string) $after['staff_no'] === (string) $lec1Snapshot['staff_no']
            && (string) $after['first_name'] === (string) $lec1Snapshot['first_name']
            && (string) $after['last_name'] === (string) $lec1Snapshot['last_name']
            && (string) $after['email'] === (string) $lec1Snapshot['email']
            && (string) $after['status'] === (string) $lec1Snapshot['status']
            && (string) $after['profile_status'] === (string) $lec1Snapshot['profile_status'];
        $same ? pass('A lecturer1 remains unchanged') : fail('A lecturer1 remains unchanged');
    }

    // Production orphans still present and still missing profiles (we did not auto-fill them)
    $prodLec = get_user(3);
    $prodStaff = get_user(2);
    $prodStaff2 = get_user(278);
    $stillOrphan = ($prodLec !== null && (string) $prodLec['username'] === 'lecturer' && get_lecturer_id_by_user_id(3) === null)
        && ($prodStaff !== null && get_academic_staff_id_by_user_id(2) === null)
        && ($prodStaff2 !== null && get_academic_staff_id_by_user_id(278) === null);
    $stillOrphan
        ? pass('Production orphans left for Admin UI (not auto-filled)')
        : fail('Production orphans left for Admin UI (not auto-filled)');

    // UI helpers against real orphans
    if (
        $prodLec !== null && user_needs_lecturer_profile($prodLec)
        && $prodStaff !== null && user_needs_academic_staff_profile($prodStaff)
        && $prodStaff2 !== null && user_needs_academic_staff_profile($prodStaff2)
        && $lecturer1 !== false && !user_needs_lecturer_profile([
            'user_id' => $lecturer1['user_id'],
            'role' => 'LECTURER',
        ])
    ) {
        pass('Complete-profile actions target only missing profiles');
    } else {
        fail('Complete-profile actions target only missing profiles');
    }
} catch (Throwable $e) {
    fail('Runtime', $e->getMessage());
} finally {
    foreach ($cleanup['lecturer_ids'] as $id) {
        $pdo->prepare('DELETE FROM lecturers WHERE lecturer_id = :id')->execute(['id' => $id]);
    }
    foreach ($cleanup['staff_ids'] as $id) {
        $pdo->prepare('DELETE FROM academic_staff WHERE academic_staff_id = :id')->execute(['id' => $id]);
    }
    foreach (array_unique($cleanup['user_ids']) as $id) {
        $pdo->prepare('DELETE FROM lecturers WHERE user_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM academic_staff WHERE user_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $id]);
    }
}

echo $failed === 0 ? "RESULT: PASSED\n" : "RESULT: FAILED ({$failed})\n";
exit($failed === 0 ? 0 : 1);
