<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — final attendance processing tests A–N.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_final_attendance.php
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

$failed = false;
$tz = new DateTimeZone(APP_TIMEZONE);

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

function ids_for_final_test(): array
{
    $row = db()->query(
        "SELECT s.student_id, m.module_id, ml.lecturer_id, s.batch_id
         FROM students s
         INNER JOIN student_modules sm
            ON sm.student_id = s.student_id AND sm.status = 'ENROLLED'
         INNER JOIN modules m
            ON m.module_id = sm.module_id AND m.status = 'ACTIVE'
         INNER JOIN module_lecturers ml ON ml.module_id = m.module_id
         INNER JOIN batches b ON b.batch_id = s.batch_id AND b.course_id = m.course_id
         WHERE s.status = 'ACTIVE'
         LIMIT 1"
    )->fetch();
    if ($row === false) {
        fwrite(STDERR, "No enrolled student found.\n");
        exit(1);
    }

    return $row;
}

function ensure_final_session(array $ids): int
{
    $date = app_today();
    try {
        return create_lecture_session([
            'module_id' => (int) $ids['module_id'],
            'lecturer_id' => (int) $ids['lecturer_id'],
            'batch_id' => (int) $ids['batch_id'],
            'session_date' => $date,
            'scheduled_start' => '09:00',
            'scheduled_end' => '11:00',
            'room' => 'LIFE-FINAL',
            'late_after_minutes' => 15,
        ]);
    } catch (Throwable $exception) {
        $stmt = db()->prepare(
            'SELECT session_id FROM lecture_sessions
             WHERE module_id = :m AND batch_id = :b AND session_date = :d AND scheduled_start = :s'
        );
        $stmt->execute([
            'm' => $ids['module_id'],
            'b' => $ids['batch_id'],
            'd' => $date,
            's' => '09:00:00',
        ]);
        $id = (int) $stmt->fetchColumn();
        if ($id <= 0) {
            throw $exception;
        }

        return $id;
    }
}

function configure_final_session(
    int $sessionId,
    string $endHm,
    ?string $actualEndHm,
    ?string $breakStart = null,
    ?string $breakEnd = null,
    string $status = 'COMPLETED'
): void {
    $date = app_today();
    $actualStart = $date . ' 09:00:00';
    $actualEnd = $date . ' ' . ($actualEndHm ?? $endHm) . ':00';
    db()->prepare(
        "UPDATE lecture_sessions
         SET scheduled_start = '09:00:00',
             scheduled_end = :scheduled_end,
             break_start = :break_start,
             break_end = :break_end,
             actual_start = :actual_start,
             actual_end = :actual_end,
             late_after_minutes = 15,
             status = :status
         WHERE session_id = :session_id"
    )->execute([
        'scheduled_end' => $endHm . ':00',
        'break_start' => $breakStart,
        'break_end' => $breakEnd,
        'actual_start' => $actualStart,
        'actual_end' => $status === 'CANCELLED' ? null : $actualEnd,
        'status' => $status,
        'session_id' => $sessionId,
    ]);
}

function wipe_session_attendance(int $sessionId): void
{
    db()->prepare('DELETE FROM attendance_records WHERE session_id = :session_id')
        ->execute(['session_id' => $sessionId]);
    db()->prepare(
        'UPDATE attendance_early_pending SET promoted_event_id = NULL WHERE session_id = :session_id'
    )->execute(['session_id' => $sessionId]);
    db()->prepare('DELETE FROM attendance_early_pending WHERE session_id = :session_id')
        ->execute(['session_id' => $sessionId]);
    db()->prepare('DELETE FROM attendance_events WHERE session_id = :session_id')
        ->execute(['session_id' => $sessionId]);
}

function add_event(int $studentId, int $sessionId, string $type, string $timeHm): void
{
    db()->prepare(
        "INSERT INTO attendance_events
            (student_id, session_id, event_type, recognized_at, confidence, camera_id)
         VALUES
            (:student_id, :session_id, :event_type, :recognized_at, 90.00, 'webcam-0')"
    )->execute([
        'student_id' => $studentId,
        'session_id' => $sessionId,
        'event_type' => $type,
        'recognized_at' => app_today() . ' ' . $timeHm . ':00',
    ]);
}

function record_for(int $sessionId, int $studentId): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM attendance_records WHERE session_id = :session_id AND student_id = :student_id LIMIT 1'
    );
    $stmt->execute([
        'session_id' => $sessionId,
        'student_id' => $studentId,
    ]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function event_count(int $sessionId, int $studentId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM attendance_events WHERE session_id = :session_id AND student_id = :student_id'
    );
    $stmt->execute([
        'session_id' => $sessionId,
        'student_id' => $studentId,
    ]);

    return (int) $stmt->fetchColumn();
}

function run_case(int $sessionId, int $studentId, string $label, callable $setup, callable $assert): void
{
    wipe_session_attendance($sessionId);
    $setup();
    finalize_session_attendance($sessionId);
    $row = record_for($sessionId, $studentId);
    $assert($row);
}

$ids = ids_for_final_test();
$studentId = (int) $ids['student_id'];
$sessionId = ensure_final_session($ids);

echo 'timezone=' . APP_TIMEZONE . PHP_EOL;
echo 'student_id=' . $studentId . PHP_EOL;
echo 'session_id=' . $sessionId . PHP_EOL;
echo 'min_percent=' . min_attendance_percent() . PHP_EOL;
echo 'left_early_tolerance=' . left_early_tolerance_minutes() . PHP_EOL;

run_case($sessionId, $studentId, 'A', static function () use ($sessionId, $studentId): void {
    configure_final_session($sessionId, '11:00', '11:00');
    add_event($studentId, $sessionId, 'IN', '09:00');
}, static function (?array $row) use (&$failed): void {
    if (
        $row !== null
        && (int) $row['total_present_minutes'] === 120
        && (int) $row['teaching_minutes'] === 120
        && (float) $row['attendance_percent'] === 100.0
        && $row['status'] === 'PRESENT'
        && (int) $row['left_early'] === 0
        && $row['last_exit'] === null
    ) {
        pass('A full attendance 100% PRESENT, not left early, no fake OUT');
    } else {
        fail('A expected 120/120 100% PRESENT last_exit NULL');
        echo 'A row=' . json_encode($row) . PHP_EOL;
    }
});

run_case($sessionId, $studentId, 'A2', static function () use ($sessionId, $studentId): void {
    configure_final_session($sessionId, '11:00', '11:00');
    add_event($studentId, $sessionId, 'IN', '09:00');
    add_event($studentId, $sessionId, 'OUT', '11:04');
}, static function (?array $row) use ($sessionId, $studentId): void {
    global $failed;
    $raw = event_count($sessionId, $studentId);
    if (
        $row !== null
        && (int) $row['total_present_minutes'] === 120
        && (float) $row['attendance_percent'] === 100.0
        && ($row['last_exit'] ?? '') === app_today() . ' 11:04:00'
        && $raw === 2
    ) {
        pass('A2 post-session Last Out stored, minutes clipped at 11:00');
    } else {
        fail('A2 expected last_exit=11:04 with 120/120 100% and raw OUT preserved');
        echo 'A2 row=' . json_encode($row) . ' raw=' . $raw . PHP_EOL;
    }
});

run_case($sessionId, $studentId, 'B', static function () use ($sessionId, $studentId): void {
    configure_final_session($sessionId, '11:00', '11:00');
    add_event($studentId, $sessionId, 'IN', '09:20');
}, static function (?array $row): void {
    global $failed;
    if ($row !== null && $row['status'] === 'LATE' && (float) $row['attendance_percent'] >= 75.0) {
        pass('B 09:20 IN LATE with enough attendance');
    } else {
        fail('B expected LATE');
        echo 'B row=' . json_encode($row) . PHP_EOL;
    }
});

run_case($sessionId, $studentId, 'C', static function () use ($sessionId, $studentId): void {
    configure_final_session($sessionId, '11:00', '11:00');
    add_event($studentId, $sessionId, 'IN', '09:40');
}, static function (?array $row): void {
    global $failed;
    if ($row !== null && $row['status'] === 'ABSENT' && (float) $row['attendance_percent'] < 75.0) {
        pass('C 09:40 IN ABSENT under 75%');
    } else {
        fail('C expected ABSENT under minimum percent');
        echo 'C row=' . json_encode($row) . PHP_EOL;
    }
});

run_case($sessionId, $studentId, 'D', static function () use ($sessionId): void {
    configure_final_session($sessionId, '11:00', '11:00');
}, static function (?array $row): void {
    global $failed;
    if (
        $row !== null
        && $row['status'] === 'ABSENT'
        && (float) $row['attendance_percent'] === 0.0
        && $row['first_entry'] === null
    ) {
        pass('D never appears ABSENT 0%');
    } else {
        fail('D expected ABSENT 0%');
        echo 'D row=' . json_encode($row) . PHP_EOL;
    }
});

run_case($sessionId, $studentId, 'E', static function () use ($sessionId, $studentId): void {
    configure_final_session($sessionId, '11:00', '11:00');
    add_event($studentId, $sessionId, 'IN', '09:00');
    add_event($studentId, $sessionId, 'OUT', '09:30');
    add_event($studentId, $sessionId, 'IN', '09:40');
}, static function (?array $row): void {
    global $failed;
    if ($row !== null && (int) $row['total_present_minutes'] === 110 && (int) $row['teaching_minutes'] === 120) {
        pass('E leave/return deducts only 10 minutes');
    } else {
        fail('E expected 110/120');
        echo 'E row=' . json_encode($row) . PHP_EOL;
    }
});

run_case($sessionId, $studentId, 'F', static function () use ($sessionId, $studentId): void {
    configure_final_session($sessionId, '12:00', '12:00', '10:30:00', '10:45:00');
    add_event($studentId, $sessionId, 'IN', '09:00');
    add_event($studentId, $sessionId, 'OUT', '10:30');
    add_event($studentId, $sessionId, 'IN', '10:44');
}, static function (?array $row): void {
    global $failed;
    if (
        $row !== null
        && (int) $row['teaching_minutes'] === 165
        && (int) $row['total_present_minutes'] === 165
        && (int) $row['left_early'] === 0
    ) {
        pass('F official break 10:30–10:44 no penalty, not left early');
    } else {
        fail('F expected 165/165 no left early');
        echo 'F row=' . json_encode($row) . PHP_EOL;
    }
});

run_case($sessionId, $studentId, 'G', static function () use ($sessionId, $studentId): void {
    configure_final_session($sessionId, '12:00', '12:00', '10:30:00', '10:45:00');
    add_event($studentId, $sessionId, 'IN', '09:00');
    add_event($studentId, $sessionId, 'OUT', '10:30');
    add_event($studentId, $sessionId, 'IN', '11:10');
}, static function (?array $row): void {
    global $failed;
    if ($row !== null && (int) $row['total_present_minutes'] === 140 && (int) $row['teaching_minutes'] === 165) {
        pass('G late return from break deducts 25 minutes');
    } else {
        fail('G expected 140/165');
        echo 'G row=' . json_encode($row) . PHP_EOL;
    }
});

run_case($sessionId, $studentId, 'H', static function () use ($sessionId, $studentId): void {
    configure_final_session($sessionId, '11:00', '11:00');
    add_event($studentId, $sessionId, 'IN', '09:00');
    add_event($studentId, $sessionId, 'OUT', '10:20');
}, static function (?array $row): void {
    global $failed;
    if ($row !== null && (int) $row['left_early'] === 1) {
        pass('H left at 10:20 Left Early=true (status=' . $row['status'] . ')');
    } else {
        fail('H expected left_early=1');
        echo 'H row=' . json_encode($row) . PHP_EOL;
    }
});

run_case($sessionId, $studentId, 'I', static function () use ($sessionId, $studentId): void {
    configure_final_session($sessionId, '11:00', '11:00');
    add_event($studentId, $sessionId, 'IN', '09:00');
    add_event($studentId, $sessionId, 'OUT', '10:58');
}, static function (?array $row): void {
    global $failed;
    if ($row !== null && (int) $row['left_early'] === 0 && $row['status'] === 'PRESENT') {
        pass('I 10:58 OUT within 5-minute tolerance, not left early');
    } else {
        fail('I expected not left early PRESENT');
        echo 'I row=' . json_encode($row) . PHP_EOL;
    }
});

run_case($sessionId, $studentId, 'J', static function () use ($sessionId, $studentId): void {
    configure_final_session($sessionId, '11:00', '11:00');
    add_event($studentId, $sessionId, 'IN', '09:00');
    add_event($studentId, $sessionId, 'OUT', '09:30');
    add_event($studentId, $sessionId, 'IN', '09:45');
}, static function (?array $row): void {
    global $failed;
    if ($row !== null && (int) $row['total_present_minutes'] === 105) {
        pass('J re-entry uses both inside intervals (105/120)');
    } else {
        fail('J expected 105 attended minutes');
        echo 'J row=' . json_encode($row) . PHP_EOL;
    }
});

run_case($sessionId, $studentId, 'K', static function () use ($sessionId, $studentId): void {
    configure_final_session($sessionId, '11:00', '11:00');
    add_event($studentId, $sessionId, 'IN', '09:00');
}, static function (?array $row): void {
    global $failed;
    if ($row !== null && $row['status'] === 'PRESENT' && ($row['first_entry'] ?? '') === app_today() . ' 09:00:00') {
        pass('K promoted/on-time IN at scheduled start is PRESENT');
    } else {
        fail('K expected PRESENT at 09:00');
        echo 'K row=' . json_encode($row) . PHP_EOL;
    }
});

wipe_session_attendance($sessionId);
configure_final_session($sessionId, '11:00', null, null, null, 'CANCELLED');
$beforeL = (int) db()->query('SELECT COUNT(*) FROM attendance_records WHERE session_id = ' . (int) $sessionId)->fetchColumn();
$lResult = finalize_session_attendance($sessionId);
$afterL = (int) db()->query('SELECT COUNT(*) FROM attendance_records WHERE session_id = ' . (int) $sessionId)->fetchColumn();
echo 'testL skipped=' . $lResult['skipped'] . ' finalized=' . $lResult['finalized'] . ' rows=' . $afterL . PHP_EOL;
if ($lResult['skipped'] === 1 && $lResult['finalized'] === 0 && $afterL === $beforeL && $afterL === 0) {
    pass('L cancelled session creates no final attendance records');
} else {
    fail('L expected no attendance_records for CANCELLED');
}

wipe_session_attendance($sessionId);
configure_final_session($sessionId, '11:00', '11:00');
add_event($studentId, $sessionId, 'IN', '09:00');
finalize_session_attendance($sessionId);
finalize_session_attendance($sessionId);
$stmtM = db()->prepare('SELECT COUNT(*) FROM attendance_records WHERE session_id = :s AND student_id = :st');
$stmtM->execute(['s' => $sessionId, 'st' => $studentId]);
$countM = (int) $stmtM->fetchColumn();
if ($countM === 1) {
    pass('M finalize twice keeps exactly one row per student/session');
} else {
    fail('M expected 1 row, got ' . $countM);
}

wipe_session_attendance($sessionId);
configure_final_session($sessionId, '11:00', '10:45');
add_event($studentId, $sessionId, 'IN', '09:00');
finalize_session_attendance($sessionId);
$rowN = record_for($sessionId, $studentId);
echo 'testN attended=' . ($rowN['total_present_minutes'] ?? '') . ' teaching=' . ($rowN['teaching_minutes'] ?? '')
    . ' percent=' . ($rowN['attendance_percent'] ?? '') . PHP_EOL;
if (
    $rowN !== null
    && (int) $rowN['teaching_minutes'] === 105
    && (int) $rowN['total_present_minutes'] === 105
    && (float) $rowN['attendance_percent'] === 100.0
    && $rowN['status'] === 'PRESENT'
) {
    pass('N manual stop at 10:45 does not count unused 10:45–11:00 as missed');
} else {
    fail('N expected 105/105 100% PRESENT');
    echo 'N row=' . json_encode($rowN) . PHP_EOL;
}

configure_final_session($sessionId, '11:00', '11:00', null, null, 'COMPLETED');

exit($failed ? 1 : 0);
