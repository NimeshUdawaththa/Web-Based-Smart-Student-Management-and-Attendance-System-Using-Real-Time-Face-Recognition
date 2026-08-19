<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — attendance reports authorization and filters (Tests A–N).
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_attendance_reports.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/web/config/env.php';
require_once dirname(__DIR__, 2) . '/web/config/config.php';
require_once dirname(__DIR__, 2) . '/web/config/database.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/management.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/academic.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/attendance-reports.php';

$failed = false;

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

$pdo = db();
$sample = $pdo->query(
    "SELECT r.attendance_id, r.student_id, r.session_id, r.status, r.left_early, r.attendance_percent,
            ls.lecturer_id, ls.module_id, ls.session_date, ls.status AS session_status
     FROM attendance_records r
     INNER JOIN lecture_sessions ls ON ls.session_id = r.session_id
     WHERE ls.status = 'COMPLETED'
     ORDER BY r.attendance_id
     LIMIT 1"
)->fetch();

if ($sample === false) {
    fwrite(STDERR, "No finalized attendance_records found. Complete a lecture first.\n");
    exit(1);
}

$lecturerA = (int) $sample['lecturer_id'];
$sessionA = (int) $sample['session_id'];
$studentA = (int) $sample['student_id'];
$moduleA = (int) $sample['module_id'];
$dateA = (string) $sample['session_date'];
$attendanceA = (int) $sample['attendance_id'];

$lecturerB = (int) $pdo->query(
    'SELECT lecturer_id FROM lecturers WHERE lecturer_id <> ' . $lecturerA . ' ORDER BY lecturer_id LIMIT 1'
)->fetchColumn();
$studentB = (int) $pdo->query(
    'SELECT student_id FROM students WHERE student_id <> ' . $studentA . ' ORDER BY student_id LIMIT 1'
)->fetchColumn();
$openSession = $pdo->query(
    "SELECT session_id, lecturer_id, status FROM lecture_sessions
     WHERE status IN ('SCHEDULED', 'IN_PROGRESS')
     ORDER BY session_id DESC LIMIT 1"
)->fetch();

$rowsA = list_attendance_report_rows(['lecturer_id' => $lecturerA]);
$idsA = array_map(static fn (array $row): int => (int) $row['lecturer_id'], $rowsA);
if ($rowsA !== [] && !in_array($lecturerB, $idsA, true) && min($idsA) === max($idsA) && $idsA[0] === $lecturerA) {
    pass('A lecturer own sessions are returned');
} elseif ($rowsA !== [] && count(array_unique($idsA)) === 1 && $idsA[0] === $lecturerA) {
    pass('A lecturer own sessions are returned');
} else {
    fail('A expected only lecturer ' . $lecturerA);
}

$otherSession = $pdo->query(
    'SELECT session_id FROM lecture_sessions WHERE lecturer_id <> ' . $lecturerA . ' AND status = \'COMPLETED\' LIMIT 1'
)->fetchColumn();
if ($otherSession) {
    $cross = list_attendance_report_rows([
        'lecturer_id' => $lecturerA,
        'session_id' => (int) $otherSession,
    ]);
    $denied = get_attendance_report_record((int) $sample['attendance_id'], $lecturerB > 0 ? $lecturerB : 999999);
    if ($cross === [] && $denied === null) {
        pass('B other lecturer session/record is not returned');
    } else {
        fail('B expected empty/denied for other lecturer');
    }
} else {
    $denied = get_attendance_report_record($attendanceA, $lecturerB > 0 ? $lecturerB : 999999);
    if ($denied === null) {
        pass('B other lecturer record is denied (no second completed session in DB)');
    } else {
        fail('B expected denied record for other lecturer id');
    }
}

$all = list_attendance_report_rows([]);
if (count($all) >= count($rowsA)) {
    pass('C staff/admin unscoped report includes lecturer rows');
} else {
    fail('C expected broader report');
}

$ownStudent = list_attendance_report_rows(['student_id' => $studentA]);
$ownIds = array_unique(array_map(static fn (array $row): int => (int) $row['student_id'], $ownStudent));
if ($ownStudent !== [] && $ownIds === [$studentA]) {
    pass('D student own attendance is visible');
} else {
    fail('D expected only student ' . $studentA);
}

$foreign = get_attendance_report_record($attendanceA, null, $studentB > 0 ? $studentB : 999999);
$ignored = attendance_report_sanitize_filters(['student_id' => $studentB], null, $studentA);
if ($foreign === null && (int) $ignored['student_id'] === $studentA) {
    pass('E other student record/URL student_id is denied or ignored');
} else {
    fail('E expected other student denied');
}

$present = list_attendance_report_rows(['status' => 'PRESENT', 'lecturer_id' => $lecturerA]);
$late = list_attendance_report_rows(['status' => 'LATE', 'lecturer_id' => $lecturerA]);
$absent = list_attendance_report_rows(['status' => 'ABSENT', 'lecturer_id' => $lecturerA]);
$okStatus = true;
foreach ([['PRESENT', $present], ['LATE', $late], ['ABSENT', $absent]] as [$wanted, $set]) {
    foreach ($set as $row) {
        if ($row['status'] !== $wanted) {
            $okStatus = false;
        }
    }
}
if ($okStatus) {
    pass('F PRESENT/LATE/ABSENT filters return only that status');
} else {
    fail('F status filter leaked another status');
}

$leftYes = list_attendance_report_rows(['left_early' => 1, 'lecturer_id' => $lecturerA]);
$leftNo = list_attendance_report_rows(['left_early' => 0, 'lecturer_id' => $lecturerA]);
$okLeft = true;
foreach ($leftYes as $row) {
    if ((int) $row['left_early'] !== 1) {
        $okLeft = false;
    }
}
foreach ($leftNo as $row) {
    if ((int) $row['left_early'] !== 0) {
        $okLeft = false;
    }
}
if ($okLeft) {
    pass('G Left Early filter is correct');
} else {
    fail('G Left Early filter leaked opposite rows');
}

$byModule = list_attendance_report_rows(['module_id' => $moduleA, 'lecturer_id' => $lecturerA]);
$byDate = list_attendance_report_rows(['from' => $dateA, 'to' => $dateA, 'lecturer_id' => $lecturerA]);
$okModule = true;
foreach ($byModule as $row) {
    if ((int) $row['module_id'] !== $moduleA) {
        $okModule = false;
    }
}
$okDate = true;
foreach ($byDate as $row) {
    if ((string) $row['session_date'] !== $dateA) {
        $okDate = false;
    }
}
if ($okModule && $okDate && $byModule !== []) {
    pass('H module and date filters are correct');
} else {
    fail('H expected module/date scoped rows');
}

$summary = summarize_attendance_report(['lecturer_id' => $lecturerA]);
$tablePresent = count(array_filter($rowsA, static fn (array $row): bool => $row['status'] === 'PRESENT'));
$tableLate = count(array_filter($rowsA, static fn (array $row): bool => $row['status'] === 'LATE'));
$tableAbsent = count(array_filter($rowsA, static fn (array $row): bool => $row['status'] === 'ABSENT'));
$tableLeft = count(array_filter($rowsA, static fn (array $row): bool => (int) $row['left_early'] === 1));
if (
    $summary['records'] === count($rowsA)
    && $summary['present'] === $tablePresent
    && $summary['late'] === $tableLate
    && $summary['absent'] === $tableAbsent
    && $summary['left_early'] === $tableLeft
) {
    pass('I summary counts match table');
} else {
    fail('I summary mismatch ' . json_encode($summary) . ' rows=' . count($rowsA));
}

$avg = 0.0;
if ($rowsA !== []) {
    $sum = 0.0;
    foreach ($rowsA as $row) {
        $sum += (float) $row['attendance_percent'];
    }
    $avg = round($sum / count($rowsA), 2);
}
if (abs($avg - (float) $summary['average_percent']) < 0.011) {
    pass('J average attendance % matches rows');
} else {
    fail('J expected avg ' . $avg . ' got ' . $summary['average_percent']);
}

if (is_array($openSession)) {
    $notice = attendance_report_session_notice((int) $openSession['session_id'], (int) $openSession['lecturer_id']);
    $openRows = list_attendance_report_rows([
        'session_id' => (int) $openSession['session_id'],
        'lecturer_id' => (int) $openSession['lecturer_id'],
    ]);
    if (is_string($notice) && str_contains($notice, 'not finalized') && $openRows === []) {
        pass('K active/non-finalized session is not shown as final');
    } else {
        fail('K expected not-finalized notice and no rows');
    }
} else {
    $notice = attendance_report_session_notice(999999999);
    if ($notice === 'Lecture session not found.') {
        pass('K no in-progress session in DB; missing session is not faked as final');
    } else {
        fail('K expected missing-session notice');
    }
}

$detail = get_attendance_report_record($attendanceA, $lecturerA);
$events = $detail !== null
    ? list_attendance_events_for_student_session((int) $detail['student_id'], (int) $detail['session_id'])
    : [];
$dbCountStmt = $pdo->prepare('SELECT COUNT(*) FROM attendance_events WHERE student_id = :s AND session_id = :sess');
$dbCountStmt->execute(['s' => $studentA, 'sess' => $sessionA]);
$dbCount = (int) $dbCountStmt->fetchColumn();
if ($detail !== null && count($events) === $dbCount) {
    pass('L audit details match raw IN/OUT events');
} else {
    fail('L audit count ' . count($events) . ' db=' . $dbCount);
}

$stream = fopen('php://memory', 'r+');
if ($stream === false) {
    fail('M could not open memory stream');
} else {
    attendance_report_write_csv($stream, $byModule);
    rewind($stream);
    $csv = stream_get_contents($stream) ?: '';
    fclose($stream);
    $csvOk = str_starts_with($csv, "\xEF\xBB\xBF")
        && str_contains($csv, 'Registration No')
        && !str_contains($csv, '<html')
        && !str_contains($csv, '<td');
    $csvLines = preg_split('/\r\n|\n/', trim(str_replace("\xEF\xBB\xBF", '', $csv))) ?: [];
    $dataLines = max(0, count($csvLines) - 1);
    if ($csvOk && $dataLines === count($byModule)) {
        pass('M CSV export has filtered rows only and no HTML');
    } else {
        fail('M CSV rows ' . $dataLines . ' expected ' . count($byModule));
    }
}

$empty = list_attendance_report_rows(['from' => '2099-01-01', 'to' => '2099-01-01', 'lecturer_id' => $lecturerA]);
$emptySummary = summarize_attendance_report(['from' => '2099-01-01', 'to' => '2099-01-01', 'lecturer_id' => $lecturerA]);
if ($empty === [] && $emptySummary['records'] === 0 && $emptySummary['average_percent'] === 0.0) {
    pass('N empty result is clean');
} else {
    fail('N expected empty rows and zero summary');
}

exit($failed ? 1 : 0);
