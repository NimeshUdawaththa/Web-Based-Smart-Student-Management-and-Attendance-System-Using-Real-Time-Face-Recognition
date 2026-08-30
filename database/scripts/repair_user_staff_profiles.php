<?php

declare(strict_types=1);

/**
 * Audit / safe repair for User ↔ Lecturer / Academic Staff profile anomalies.
 *
 * Default: dry-run (report only).
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/repair_user_staff_profiles.php
 *   C:\xampp\php\php.exe database/scripts/repair_user_staff_profiles.php --dry-run
 *   C:\xampp\php\php.exe database/scripts/repair_user_staff_profiles.php --execute
 *
 * --execute only syncs unambiguous ACTIVE/INACTIVE profile status mismatches
 * when the login role matches the profile type. It never invents missing profiles
 * or deletes historical records.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$root = dirname(__DIR__, 2);
require_once $root . '/web/shared/includes/init.php';

$execute = in_array('--execute', $argv, true);
$dryRun = !$execute || in_array('--dry-run', $argv, true);
if ($execute && in_array('--dry-run', $argv, true)) {
    fwrite(STDERR, "Use either --dry-run or --execute, not both.\n");
    exit(1);
}
if (!$execute) {
    $dryRun = true;
}

$audit = audit_user_staff_profile_anomalies();

echo $dryRun ? "MODE: dry-run (no writes)\n" : "MODE: execute (status sync only)\n";
echo str_repeat('-', 60) . "\n";

$sections = [
    'lecturer_users_without_profile' => 'LECTURER users without lecturer profile',
    'academic_staff_users_without_profile' => 'ACADEMIC_STAFF users without academic_staff profile',
    'lecturer_profiles_without_valid_user' => 'lecturer profiles without valid user',
    'academic_staff_profiles_without_valid_user' => 'academic_staff profiles without valid user',
    'lecturer_profiles_wrong_role' => 'lecturer profiles linked to non-LECTURER user',
    'academic_staff_profiles_wrong_role' => 'academic_staff profiles linked to non-ACADEMIC_STAFF user',
    'duplicate_lecturer_user_links' => 'duplicate lecturer profiles per user',
    'duplicate_academic_staff_user_links' => 'duplicate academic_staff profiles per user',
    'status_mismatches_lecturer' => 'lecturer profile/login status mismatches',
    'status_mismatches_academic_staff' => 'academic_staff profile/login status mismatches',
];

$totalAnomalies = 0;
foreach ($sections as $key => $label) {
    $rows = $audit[$key] ?? [];
    $count = count($rows);
    $totalAnomalies += $count;
    echo sprintf("[%d] %s\n", $count, $label);
    foreach ($rows as $row) {
        echo '  - ' . json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
    }
}

echo str_repeat('-', 60) . "\n";
echo "Total anomaly rows: {$totalAnomalies}\n";

if ($dryRun) {
    echo "No changes applied. Re-run with --execute to sync safe status mismatches only.\n";
    echo "Missing profiles / wrong roles / duplicates are reported only (manual review).\n";
    exit(0);
}

$pdo = db();
$fixed = 0;

foreach ($audit['status_mismatches_lecturer'] as $row) {
    $userId = (int) $row['user_id'];
    $accountStatus = (string) $row['account_status'];
    $desired = staff_profile_status_from_account_status($accountStatus);
    $pdo->prepare('UPDATE lecturers SET status = :status WHERE user_id = :user_id')->execute([
        'status' => $desired,
        'user_id' => $userId,
    ]);
    $fixed++;
    echo "Synced lecturer profile status for user_id={$userId} → {$desired}\n";
}

foreach ($audit['status_mismatches_academic_staff'] as $row) {
    $userId = (int) $row['user_id'];
    $accountStatus = (string) $row['account_status'];
    $desired = staff_profile_status_from_account_status($accountStatus);
    $pdo->prepare('UPDATE academic_staff SET status = :status WHERE user_id = :user_id')->execute([
        'status' => $desired,
        'user_id' => $userId,
    ]);
    $fixed++;
    echo "Synced academic_staff profile status for user_id={$userId} → {$desired}\n";
}

echo "Status mismatches fixed: {$fixed}\n";
echo "Ambiguous anomalies left untouched.\n";
exit(0);
