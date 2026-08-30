<?php

declare(strict_types=1);

/**
 * User Management ↔ Lecturer / Academic Staff profile synchronization.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_user_staff_profile_sync.php
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
$password = 'SyncTestPass1!';

$attendanceEventsBefore = (int) $pdo->query('SELECT COUNT(*) FROM attendance_events')->fetchColumn();
$attendanceRecordsBefore = (int) $pdo->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
$faceBefore = (int) $pdo->query('SELECT COUNT(*) FROM face_profiles')->fetchColumn();
$assignmentsBefore = (int) $pdo->query('SELECT COUNT(*) FROM assignments')->fetchColumn();

try {
    // A Admin / normal user
    $adminId = create_user([
        'username' => 'adm_sync_' . $suffix,
        'email' => 'adm_sync_' . $suffix . '@example.test',
        'password' => $password,
        'role' => 'ADMIN',
        'status' => 'ACTIVE',
    ]);
    $cleanup['user_ids'][] = $adminId;
    if ((string) get_user($adminId)['role'] === 'ADMIN' && get_lecturer_id_by_user_id($adminId) === null) {
        pass('A Admin creates normal user successfully');
    } else {
        fail('A Admin creates normal user successfully');
    }

    // B–F Lecturer via User Management
    $beforeUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $beforeLecturers = (int) $pdo->query('SELECT COUNT(*) FROM lecturers')->fetchColumn();
    $lecUserId = create_user([
        'username' => 'lec_sync_' . $suffix,
        'email' => 'lec_sync_' . $suffix . '@example.test',
        'password' => $password,
        'role' => 'LECTURER',
        'status' => 'ACTIVE',
        'staff_no' => 'LEC-SYNC-' . $suffix,
        'first_name' => 'Sync',
        'last_name' => 'Lecturer',
        'phone' => '',
        'department' => 'Computing',
    ]);
    $cleanup['user_ids'][] = $lecUserId;
    $lecId = get_lecturer_id_by_user_id($lecUserId);
    if ($lecId !== null) {
        $cleanup['lecturer_ids'][] = $lecId;
    }
    $afterUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $afterLecturers = (int) $pdo->query('SELECT COUNT(*) FROM lecturers')->fetchColumn();

    $listedUsers = list_users(['search' => 'lec_sync_' . $suffix]);
    $listedLecs = list_lecturers(['search' => 'LEC-SYNC-' . $suffix]);
    $userIds = array_map(static fn(array $r): int => (int) $r['user_id'], $listedUsers);
    $lecUserIds = array_map(static fn(array $r): int => (int) $r['user_id'], $listedLecs);

    if ((string) get_user($lecUserId)['role'] === 'LECTURER') {
        pass('B Admin creates LECTURER through User Management');
    } else {
        fail('B Admin creates LECTURER through User Management');
    }
    ($afterUsers === $beforeUsers + 1) ? pass('C exactly one users row created') : fail('C exactly one users row created');
    ($afterLecturers === $beforeLecturers + 1 && $lecId !== null)
        ? pass('D exactly one lecturers profile created')
        : fail('D exactly one lecturers profile created');
    in_array($lecUserId, $lecUserIds, true) ? pass('E lecturer appears in Lecturer Management') : fail('E lecturer appears in Lecturer Management');
    in_array($lecUserId, $userIds, true) ? pass('F same account appears in User Management') : fail('F same account appears in User Management');

    // G–K Academic Staff via User Management
    $beforeUsers2 = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $beforeStaff = (int) $pdo->query('SELECT COUNT(*) FROM academic_staff')->fetchColumn();
    $staffUserId = create_user([
        'username' => 'stf_sync_' . $suffix,
        'email' => 'stf_sync_' . $suffix . '@example.test',
        'password' => $password,
        'role' => 'ACADEMIC_STAFF',
        'status' => 'ACTIVE',
        'staff_no' => 'STF-SYNC-' . $suffix,
        'first_name' => 'Sync',
        'last_name' => 'Staff',
        'phone' => '',
        'position' => 'Coordinator',
    ]);
    $cleanup['user_ids'][] = $staffUserId;
    $staffId = get_academic_staff_id_by_user_id($staffUserId);
    if ($staffId !== null) {
        $cleanup['staff_ids'][] = $staffId;
    }
    $afterUsers2 = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $afterStaff = (int) $pdo->query('SELECT COUNT(*) FROM academic_staff')->fetchColumn();
    $listedStaff = list_academic_staff(['search' => 'STF-SYNC-' . $suffix]);
    $staffUserIds = array_map(static fn(array $r): int => (int) $r['user_id'], $listedStaff);
    $listedUsers2 = list_users(['search' => 'stf_sync_' . $suffix]);
    $userIds2 = array_map(static fn(array $r): int => (int) $r['user_id'], $listedUsers2);

    ((string) get_user($staffUserId)['role'] === 'ACADEMIC_STAFF')
        ? pass('G Admin creates ACADEMIC_STAFF through User Management')
        : fail('G Admin creates ACADEMIC_STAFF through User Management');
    ($afterUsers2 === $beforeUsers2 + 1) ? pass('H exactly one users row created') : fail('H exactly one users row created');
    ($afterStaff === $beforeStaff + 1 && $staffId !== null)
        ? pass('I exactly one academic_staff profile created')
        : fail('I exactly one academic_staff profile created');
    in_array($staffUserId, $staffUserIds, true)
        ? pass('J staff appears in Academic Staff Management')
        : fail('J staff appears in Academic Staff Management');
    in_array($staffUserId, $userIds2, true)
        ? pass('K same account appears in User Management')
        : fail('K same account appears in User Management');

    // L rollback lecturer profile failure
    $beforeL = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $msgL = expect_invalid(static function () use ($suffix, $password): void {
        create_user([
            'username' => 'lec_fail_' . $suffix,
            'email' => 'lec_fail_' . $suffix . '@example.test',
            'password' => $password,
            'role' => 'LECTURER',
            'status' => 'ACTIVE',
            'staff_no' => '',
            'first_name' => 'X',
            'last_name' => 'Y',
        ]);
    });
    $afterL = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $orphanL = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE username = 'lec_fail_{$suffix}'"
    )->fetchColumn();
    ($msgL !== null && $afterL === $beforeL && $orphanL === 0)
        ? pass('L failure creating lecturer profile rolls back user')
        : fail('L failure creating lecturer profile rolls back user', (string) $msgL);

    // M rollback academic staff profile failure
    $beforeM = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $msgM = expect_invalid(static function () use ($suffix, $password): void {
        create_user([
            'username' => 'stf_fail_' . $suffix,
            'email' => 'stf_fail_' . $suffix . '@example.test',
            'password' => $password,
            'role' => 'ACADEMIC_STAFF',
            'status' => 'ACTIVE',
            'staff_no' => '',
            'first_name' => 'X',
            'last_name' => 'Y',
        ]);
    });
    $afterM = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    ($msgM !== null && $afterM === $beforeM)
        ? pass('M failure creating academic staff profile rolls back user')
        : fail('M failure creating academic staff profile rolls back user');

    // N duplicate lecturer profile prevented (same staff_no)
    $msgN = expect_invalid(static function () use ($suffix, $password): void {
        create_user([
            'username' => 'lec_dup_' . $suffix,
            'email' => 'lec_dup_' . $suffix . '@example.test',
            'password' => $password,
            'role' => 'LECTURER',
            'status' => 'ACTIVE',
            'staff_no' => 'LEC-SYNC-' . $suffix,
            'first_name' => 'Dup',
            'last_name' => 'Lec',
        ]);
    });
    ($msgN !== null && str_contains(strtolower($msgN), 'staff number'))
        ? pass('N duplicate lecturer profile prevented')
        : fail('N duplicate lecturer profile prevented', (string) $msgN);

    // O duplicate staff profile prevented
    $msgO = expect_invalid(static function () use ($suffix, $password): void {
        create_user([
            'username' => 'stf_dup_' . $suffix,
            'email' => 'stf_dup_' . $suffix . '@example.test',
            'password' => $password,
            'role' => 'ACADEMIC_STAFF',
            'status' => 'ACTIVE',
            'staff_no' => 'STF-SYNC-' . $suffix,
            'first_name' => 'Dup',
            'last_name' => 'Staff',
        ]);
    });
    ($msgO !== null && str_contains(strtolower($msgO), 'staff number'))
        ? pass('O duplicate staff profile prevented')
        : fail('O duplicate staff profile prevented', (string) $msgO);

    // P existing Lecturer Management Create
    $directLecId = create_lecturer([
        'username' => 'lec_direct_' . $suffix,
        'email' => 'lec_direct_' . $suffix . '@example.test',
        'password' => $password,
        'account_status' => 'ACTIVE',
        'staff_no' => 'LEC-DIR-' . $suffix,
        'first_name' => 'Direct',
        'last_name' => 'Lec',
        'phone' => '',
        'department' => '',
        'status' => 'ACTIVE',
    ]);
    $cleanup['lecturer_ids'][] = $directLecId;
    $directLecUser = (int) get_lecturer($directLecId)['user_id'];
    $cleanup['user_ids'][] = $directLecUser;
    ($directLecId > 0 && get_lecturer_id_by_user_id($directLecUser) === $directLecId)
        ? pass('P existing Lecturer Management Create still works')
        : fail('P existing Lecturer Management Create still works');

    // Q existing Academic Staff Management Create
    $directStaffId = create_academic_staff_member([
        'username' => 'stf_direct_' . $suffix,
        'email' => 'stf_direct_' . $suffix . '@example.test',
        'password' => $password,
        'account_status' => 'ACTIVE',
        'staff_no' => 'STF-DIR-' . $suffix,
        'first_name' => 'Direct',
        'last_name' => 'Staff',
        'phone' => '',
        'position' => '',
        'status' => 'ACTIVE',
    ]);
    $cleanup['staff_ids'][] = $directStaffId;
    $directStaffUser = (int) get_academic_staff_member($directStaffId)['user_id'];
    $cleanup['user_ids'][] = $directStaffUser;
    ($directStaffId > 0 && get_academic_staff_id_by_user_id($directStaffUser) === $directStaffId)
        ? pass('Q existing Academic Staff Management Create still works')
        : fail('Q existing Academic Staff Management Create still works');

    // R status/deactivation consistent
    set_user_status($lecUserId, 'INACTIVE');
    $lecAfter = get_lecturer($lecId);
    $userAfter = get_user($lecUserId);
    ((string) $userAfter['status'] === 'INACTIVE' && (string) $lecAfter['status'] === 'INACTIVE')
        ? pass('R status/deactivation remains consistent')
        : fail('R status/deactivation remains consistent');
    set_user_status($lecUserId, 'ACTIVE');
    $lecAfter2 = get_lecturer($lecId);
    ((string) get_user($lecUserId)['status'] === 'ACTIVE' && (string) $lecAfter2['status'] === 'ACTIVE')
        ? pass('R reactivation syncs profile ACTIVE')
        : fail('R reactivation syncs profile ACTIVE');

    // S dangerous role conversion rejected
    $msgS = expect_invalid(static function () use ($lecUserId, $suffix): void {
        update_user($lecUserId, [
            'username' => 'lec_sync_' . $suffix,
            'email' => 'lec_sync_' . $suffix . '@example.test',
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
        ]);
    });
    ((string) get_user($lecUserId)['role'] === 'LECTURER' && $msgS !== null && str_contains($msgS, 'Role cannot be changed'))
        ? pass('S dangerous role conversion is rejected safely')
        : fail('S dangerous role conversion is rejected safely', (string) $msgS);

    // T historical/dependent lecturer data not deleted (counts stable; no DELETE from sync helpers)
    $mgmtSrc = (string) file_get_contents($root . '/web/shared/includes/management.php');
    $createSrc = (string) file_get_contents($root . '/web/admin/users/create.php');
    $noCascadeDelete = !preg_match(
        '/function sync_staff_profile_status_for_user[\s\S]*?DELETE FROM/i',
        $mgmtSrc
    );
    $noCascadeDelete ? pass('T historical/dependent lecturer data is not deleted') : fail('T historical/dependent lecturer data is not deleted');

    // U anomaly audit works
    $audit = audit_user_staff_profile_anomalies();
    (is_array($audit)
        && array_key_exists('lecturer_users_without_profile', $audit)
        && array_key_exists('status_mismatches_lecturer', $audit))
        ? pass('U existing account/profile anomaly audit works')
        : fail('U existing account/profile anomaly audit works');

    // V student management unaffected (helpers still reject STUDENT create)
    $msgV = expect_invalid(static function () use ($suffix, $password): void {
        create_user([
            'username' => 'stu_sync_' . $suffix,
            'email' => 'stu_sync_' . $suffix . '@example.test',
            'password' => $password,
            'role' => 'STUDENT',
            'status' => 'ACTIVE',
        ]);
    });
    ($msgV !== null && str_contains($msgV, 'Student Management'))
        ? pass('V Student management unaffected')
        : fail('V Student management unaffected');

    $attendanceEventsAfter = (int) $pdo->query('SELECT COUNT(*) FROM attendance_events')->fetchColumn();
    $attendanceRecordsAfter = (int) $pdo->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
    $faceAfter = (int) $pdo->query('SELECT COUNT(*) FROM face_profiles')->fetchColumn();
    $assignmentsAfter = (int) $pdo->query('SELECT COUNT(*) FROM assignments')->fetchColumn();

    ($assignmentsAfter === $assignmentsBefore)
        ? pass('W Coursework & Assessments unaffected')
        : fail('W Coursework & Assessments unaffected');
    ($attendanceEventsAfter === $attendanceEventsBefore && $attendanceRecordsAfter === $attendanceRecordsBefore)
        ? pass('X attendance unaffected')
        : fail('X attendance unaffected');
    ($faceAfter === $faceBefore)
        ? pass('Y face recognition unaffected')
        : fail('Y face recognition unaffected');

    // UI checks
    (str_contains($createSrc, 'staff-profile-fields')
        && str_contains($createSrc, 'Creating a lecturer account also creates')
        && str_contains($createSrc, 'Creating an academic staff account also creates'))
        ? pass('UI conditional profile fields present')
        : fail('UI conditional profile fields present');
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
