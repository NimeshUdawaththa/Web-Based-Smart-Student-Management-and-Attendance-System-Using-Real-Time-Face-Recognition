<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — Session-Specific Final OUT After Lecture Completion v1.
 *
 * Covers cases A–Z from the implementation brief (active IN/OUT, post-completion
 * outstanding EXIT without fixed window, Session A/B collision safety, integrity).
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_session_final_out.php
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

function freeze_now(DateTimeImmutable $now): void
{
    set_app_now_override($now);
}

function wipe_session(int $sessionId): void
{
    db()->prepare('DELETE FROM attendance_records WHERE session_id = :id')->execute(['id' => $sessionId]);
    db()->prepare('UPDATE attendance_early_pending SET promoted_event_id = NULL WHERE session_id = :id')->execute(['id' => $sessionId]);
    db()->prepare('DELETE FROM attendance_early_pending WHERE session_id = :id')->execute(['id' => $sessionId]);
    db()->prepare('DELETE FROM attendance_events WHERE session_id = :id')->execute(['id' => $sessionId]);
}

function set_session_status(
    int $sessionId,
    string $status,
    DateTimeImmutable $actualStart,
    ?DateTimeImmutable $actualEnd
): void {
    db()->prepare(
        "UPDATE lecture_sessions
         SET status = :status, actual_start = :actual_start, actual_end = :actual_end
         WHERE session_id = :session_id"
    )->execute([
        'status' => $status,
        'actual_start' => $actualStart->format('Y-m-d H:i:s'),
        'actual_end' => $actualEnd?->format('Y-m-d H:i:s'),
        'session_id' => $sessionId,
    ]);
}

function add_event_at(int $studentId, int $sessionId, string $type, DateTimeImmutable $at): void
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
        'recognized_at' => $at->format('Y-m-d H:i:s'),
    ]);
}

function events_for(int $sessionId, int $studentId): array
{
    $stmt = db()->prepare(
        'SELECT event_id, event_type, recognized_at FROM attendance_events
         WHERE session_id = :session_id AND student_id = :student_id
         ORDER BY recognized_at ASC, event_id ASC'
    );
    $stmt->execute(['session_id' => $sessionId, 'student_id' => $studentId]);

    return $stmt->fetchAll();
}

function record_for(int $sessionId, int $studentId): ?array
{
    $stmt = db()->prepare(
        'SELECT total_present_minutes, teaching_minutes, attendance_percent, status, left_early,
                first_entry, last_exit
         FROM attendance_records WHERE session_id = :s AND student_id = :st LIMIT 1'
    );
    $stmt->execute(['s' => $sessionId, 'st' => $studentId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function snapshot_calc(?array $row): array
{
    return [
        'attended' => (int) ($row['total_present_minutes'] ?? -1),
        'teaching' => (int) ($row['teaching_minutes'] ?? -1),
        'percent' => (string) ($row['attendance_percent'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'left_early' => (int) ($row['left_early'] ?? -1),
        'first_entry' => $row['first_entry'] ?? null,
    ];
}

function ensure_session(array $ids, string $startHm, string $endHm, string $room): int
{
    $date = app_today();
    try {
        return create_lecture_session([
            'module_id' => (int) $ids['module_id'],
            'lecturer_id' => (int) $ids['lecturer_id'],
            'batch_id' => (int) $ids['batch_id'],
            'session_date' => $date,
            'scheduled_start' => $startHm,
            'scheduled_end' => $endHm,
            'room' => $room,
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
            's' => strlen($startHm) === 5 ? $startHm . ':00' : $startHm,
        ]);
        $id = (int) $stmt->fetchColumn();
        if ($id <= 0) {
            throw $exception;
        }

        return $id;
    }
}

$ids = db()->query(
    "SELECT s.student_id, m.module_id, ml.lecturer_id, s.batch_id
     FROM students s
     INNER JOIN student_modules sm
        ON sm.student_id = s.student_id AND sm.status = 'ENROLLED'
     INNER JOIN modules m
        ON m.module_id = sm.module_id AND m.status = 'ACTIVE'
     INNER JOIN module_lecturers ml ON ml.module_id = m.module_id
     INNER JOIN batches b ON b.batch_id = s.batch_id
     INNER JOIN course_modules cm ON cm.module_id = m.module_id AND cm.course_id = b.course_id AND cm.status = 'ACTIVE'
     WHERE s.status = 'ACTIVE'
     LIMIT 1"
)->fetch();
if ($ids === false) {
    fwrite(STDERR, "No enrolled student found.\n");
    exit(1);
}

$studentX = (int) $ids['student_id'];
$studentYRow = db()->prepare(
    "SELECT s.student_id
     FROM students s
     INNER JOIN student_modules sm
        ON sm.student_id = s.student_id AND sm.status = 'ENROLLED' AND sm.module_id = :module_id
     WHERE s.status = 'ACTIVE' AND s.batch_id = :batch_id AND s.student_id <> :student_id
     LIMIT 1"
);
$studentYRow->execute([
    'module_id' => (int) $ids['module_id'],
    'batch_id' => (int) $ids['batch_id'],
    'student_id' => $studentX,
]);
$studentY = (int) $studentYRow->fetchColumn();

$date = app_today();
$sessionA = ensure_session($ids, '06:00', '08:00', 'LIFE-FINALOUT-A');
$sessionB = ensure_session($ids, '08:15', '10:00', 'LIFE-FINALOUT-B');

$aStart = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' 06:00', $tz);
$aEnd = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' 08:00', $tz);
$bStart = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' 08:15', $tz);
$bEnd = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' 10:00', $tz);
if (
    !$aStart instanceof DateTimeImmutable
    || !$aEnd instanceof DateTimeImmutable
    || !$bStart instanceof DateTimeImmutable
    || !$bEnd instanceof DateTimeImmutable
) {
    fwrite(STDERR, "Could not build session datetimes.\n");
    exit(1);
}

echo 'timezone=' . APP_TIMEZONE . PHP_EOL;
echo 'student_x=' . $studentX . PHP_EOL;
echo 'student_y=' . ($studentY > 0 ? (string) $studentY : 'NONE') . PHP_EOL;
echo 'session_a=' . $sessionA . PHP_EOL;
echo 'session_b=' . $sessionB . PHP_EOL;

wipe_session($sessionA);
wipe_session($sessionB);

// ---------------------------------------------------------------------------
// A–C: active session IN/OUT + re-entry
// ---------------------------------------------------------------------------
set_session_status($sessionA, 'IN_PROGRESS', $aStart, null);
freeze_now($aStart->modify('+10 minutes'));
$rA = record_face_attendance_event($studentX, 91.0, 'webcam-0', 'ENTRY');
echo 'A result=' . ($rA['result'] ?? '') . ' session=' . ($rA['session_id'] ?? '') . PHP_EOL;
if (($rA['result'] ?? '') === 'CHECKED_IN' && (int) ($rA['session_id'] ?? 0) === $sessionA) {
    pass('A active ENTRY → IN');
} else {
    fail('A expected CHECKED_IN on Session A');
}

freeze_now($aStart->modify('+40 minutes'));
$rB = record_face_attendance_event($studentX, 91.0, 'webcam-0', 'EXIT');
if (($rB['result'] ?? '') === 'CHECKED_OUT' && (int) ($rB['session_id'] ?? 0) === $sessionA) {
    pass('B active EXIT → OUT');
} else {
    fail('B expected CHECKED_OUT on Session A');
}

freeze_now($aStart->modify('+50 minutes'));
$rC1 = record_face_attendance_event($studentX, 91.0, 'webcam-0', 'ENTRY');
freeze_now($aStart->modify('+70 minutes'));
$rC2 = record_face_attendance_event($studentX, 91.0, 'webcam-0', 'EXIT');
freeze_now($aStart->modify('+80 minutes'));
$rC3 = record_face_attendance_event($studentX, 91.0, 'webcam-0', 'ENTRY');
$eventsC = events_for($sessionA, $studentX);
if (
    ($rC1['result'] ?? '') === 'RE_ENTERED'
    && ($rC2['result'] ?? '') === 'CHECKED_OUT'
    && ($rC3['result'] ?? '') === 'RE_ENTERED'
    && count($eventsC) === 5
) {
    pass('C repeated IN/OUT during active session');
} else {
    fail('C expected re-entry cycle with 5 events, got ' . count($eventsC)
        . ' results=' . ($rC1['result'] ?? '') . '/' . ($rC2['result'] ?? '') . '/' . ($rC3['result'] ?? ''));
}

// ---------------------------------------------------------------------------
// D–F / P–T: complete with outstanding IN, late final OUT
// ---------------------------------------------------------------------------
set_session_status($sessionA, 'COMPLETED', $aStart, $aEnd);
finalize_session_attendance($sessionA);
$rowBefore = record_for($sessionA, $studentX);
$calcBefore = snapshot_calc($rowBefore);
$firstEntryBefore = $rowBefore['first_entry'] ?? null;

freeze_now($aEnd->modify('+4 minutes'));
$rD = record_face_attendance_event($studentX, 88.0, 'webcam-0', 'EXIT');
$rowD = record_for($sessionA, $studentX);
$expectedD = $aEnd->modify('+4 minutes')->format('Y-m-d H:i:s');
if (
    ($rD['result'] ?? '') === 'CHECKED_OUT'
    && (int) ($rD['session_id'] ?? 0) === $sessionA
    && ($rowD['last_exit'] ?? '') === $expectedD
) {
    pass('D EXIT +4m after complete → final OUT');
} else {
    fail('D expected CHECKED_OUT at +4m');
}

wipe_session($sessionA);
set_session_status($sessionA, 'IN_PROGRESS', $aStart, null);
freeze_now($aStart->modify('+10 minutes'));
record_face_attendance_event($studentX, 91.0, 'webcam-0', 'ENTRY');
set_session_status($sessionA, 'COMPLETED', $aStart, $aEnd);
finalize_session_attendance($sessionA);
$calcE = snapshot_calc(record_for($sessionA, $studentX));
$expectedE = $aEnd->modify('+11 minutes')->format('Y-m-d H:i:s');
freeze_now($aEnd->modify('+11 minutes'));
$rE = record_face_attendance_event($studentX, 88.0, 'webcam-0', 'EXIT');
$rowE = record_for($sessionA, $studentX);
if (
    ($rE['result'] ?? '') === 'CHECKED_OUT'
    && ($rowE['last_exit'] ?? '') === $expectedE
    && snapshot_calc($rowE) === $calcE
) {
    pass('E EXIT +11m after complete → final OUT (no 10m gate)');
} else {
    fail('E expected CHECKED_OUT at +11m with calc unchanged');
}

wipe_session($sessionA);
set_session_status($sessionA, 'IN_PROGRESS', $aStart, null);
freeze_now($aStart->modify('+10 minutes'));
record_face_attendance_event($studentX, 91.0, 'webcam-0', 'ENTRY');
set_session_status($sessionA, 'COMPLETED', $aStart, $aEnd);
finalize_session_attendance($sessionA);
$calcF = snapshot_calc(record_for($sessionA, $studentX));
$firstF = record_for($sessionA, $studentX)['first_entry'] ?? null;
$expectedF = $aEnd->modify('+90 minutes')->format('Y-m-d H:i:s');
freeze_now($aEnd->modify('+90 minutes'));
$rF = record_face_attendance_event($studentX, 88.0, 'webcam-0', 'EXIT');
$rowF = record_for($sessionA, $studentX);
if (
    ($rF['result'] ?? '') === 'CHECKED_OUT'
    && ($rowF['last_exit'] ?? '') === $expectedF
    && snapshot_calc($rowF) === $calcF
    && ($rowF['first_entry'] ?? null) === $firstF
) {
    pass('F EXIT much later → final OUT; P–T calc/first_entry intact');
} else {
    fail('F expected late CHECKED_OUT with unchanged academic fields');
}
if ((int) ($rowF['total_present_minutes'] ?? -1) === (int) ($calcF['attended'] ?? -2)) {
    pass('Q teaching/attended minutes unchanged after late final OUT');
} else {
    fail('Q minutes changed after late final OUT');
}
if ((string) ($rowF['attendance_percent'] ?? '') === (string) ($calcF['percent'] ?? '')) {
    pass('R attendance percentage unchanged');
} else {
    fail('R percentage changed');
}
if ((string) ($rowF['status'] ?? '') === (string) ($calcF['status'] ?? '')) {
    pass('S PRESENT/LATE/ABSENT unchanged');
} else {
    fail('S status changed');
}
if (($rowF['first_entry'] ?? null) === $firstF) {
    pass('T first_entry unchanged');
} else {
    fail('T first_entry changed');
}
if (($rowF['last_exit'] ?? '') === $expectedF) {
    pass('P attendance_records.last_exit = physical final exit');
} else {
    fail('P last_exit mismatch');
}

// ---------------------------------------------------------------------------
// G ENTRY after completed rejected
// ---------------------------------------------------------------------------
set_session_status($sessionB, 'COMPLETED', $bStart, $bEnd);
freeze_now($aEnd->modify('+91 minutes'));
$eventsGBefore = count(events_for($sessionA, $studentX));
$rG = record_face_attendance_event($studentX, 88.0, 'webcam-0', 'ENTRY');
if (($rG['result'] ?? '') === 'NO_ACTIVE_SESSION' && count(events_for($sessionA, $studentX)) === $eventsGBefore) {
    pass('G ENTRY after completed session rejected');
} else {
    fail('G expected NO_ACTIVE_SESSION for ENTRY after complete');
}

// ---------------------------------------------------------------------------
// H never entered → no OUT / no record mutation
// ---------------------------------------------------------------------------
wipe_session($sessionA);
wipe_session($sessionB);
set_session_status($sessionA, 'COMPLETED', $aStart, $aEnd);
set_session_status($sessionB, 'COMPLETED', $bStart, $bEnd);
finalize_session_attendance($sessionA);
$rowHBefore = record_for($sessionA, $studentX);
$eventsHBefore = count(events_for($sessionA, $studentX));
freeze_now($aEnd->modify('+5 minutes'));
$rH = record_face_attendance_event($studentX, 88.0, 'webcam-0', 'EXIT');
$rowH = record_for($sessionA, $studentX);
if (
    in_array(($rH['result'] ?? ''), ['ALREADY_OUTSIDE', 'NO_ACTIVE_SESSION'], true)
    && count(events_for($sessionA, $studentX)) === $eventsHBefore
    && ($rowH['last_exit'] ?? null) === ($rowHBefore['last_exit'] ?? null)
    && ($rowH['status'] ?? null) === ($rowHBefore['status'] ?? null)
) {
    pass('H never-entered student cannot create post-completion OUT');
} else {
    fail('H expected no OUT / no record change for never-entered student result=' . ($rH['result'] ?? ''));
}
if (($rowH['status'] ?? '') === 'ABSENT') {
    pass('U ABSENT student remains ABSENT after EXIT-only recognition');
} else {
    // finalize may not create ABSENT rows for all students depending on engine — soft check
    if ($rowHBefore === null && $rowH === null) {
        pass('U no attendance row created for never-entered (ABSENT path)');
    } elseif (($rowH['status'] ?? '') === ($rowHBefore['status'] ?? '')) {
        pass('U attendance status unchanged for never-entered EXIT');
    } else {
        fail('U status unexpectedly changed');
    }
}
$cntStmt = db()->prepare('SELECT COUNT(*) FROM attendance_records WHERE session_id = :s AND student_id = :st');
$cntStmt->execute(['s' => $sessionA, 'st' => $studentX]);
$cntAfterH = (int) $cntStmt->fetchColumn();
$cntBeforeH = $rowHBefore === null ? 0 : 1;
if ($cntAfterH === $cntBeforeH) {
    pass('V no new attendance record created after completion for never-entered EXIT');
} else {
    fail('V unexpected new attendance_records row');
}

// ---------------------------------------------------------------------------
// I already OUT before completion
// ---------------------------------------------------------------------------
wipe_session($sessionA);
wipe_session($sessionB);
set_session_status($sessionB, 'COMPLETED', $bStart, $bEnd);
set_session_status($sessionA, 'IN_PROGRESS', $aStart, null);
freeze_now($aStart->modify('+10 minutes'));
record_face_attendance_event($studentX, 91.0, 'webcam-0', 'ENTRY');
freeze_now($aStart->modify('+90 minutes'));
record_face_attendance_event($studentX, 91.0, 'webcam-0', 'EXIT');
set_session_status($sessionA, 'COMPLETED', $aStart, $aEnd);
finalize_session_attendance($sessionA);
$eventsIBefore = count(events_for($sessionA, $studentX));
$rowIBefore = record_for($sessionA, $studentX);
freeze_now($aEnd->modify('+5 minutes'));
$rI = record_face_attendance_event($studentX, 88.0, 'webcam-0', 'EXIT');
if (
    ($rI['result'] ?? '') === 'ALREADY_OUTSIDE'
    && count(events_for($sessionA, $studentX)) === $eventsIBefore
    && ($rowIBefore['last_exit'] ?? null) === (record_for($sessionA, $studentX)['last_exit'] ?? null)
) {
    pass('I already OUT before completion → no final OUT');
} else {
    fail('I expected ALREADY_OUTSIDE with no extra event result=' . ($rI['result'] ?? ''));
}

// ---------------------------------------------------------------------------
// J / W repeated EXIT after final OUT
// ---------------------------------------------------------------------------
wipe_session($sessionA);
wipe_session($sessionB);
set_session_status($sessionB, 'COMPLETED', $bStart, $bEnd);
set_session_status($sessionA, 'IN_PROGRESS', $aStart, null);
freeze_now($aStart->modify('+10 minutes'));
record_face_attendance_event($studentX, 91.0, 'webcam-0', 'ENTRY');
set_session_status($sessionA, 'COMPLETED', $aStart, $aEnd);
finalize_session_attendance($sessionA);
freeze_now($aEnd->modify('+6 minutes'));
$rJ1 = record_face_attendance_event($studentX, 88.0, 'webcam-0', 'EXIT');
$rowJ1 = record_for($sessionA, $studentX);
$eventsJ1 = count(events_for($sessionA, $studentX));
freeze_now($aEnd->modify('+7 minutes'));
$rJ2 = record_face_attendance_event($studentX, 88.0, 'webcam-0', 'EXIT');
$rJ3 = record_face_attendance_event($studentX, 88.0, 'webcam-0', 'EXIT');
$rowJ3 = record_for($sessionA, $studentX);
if (
    ($rJ1['result'] ?? '') === 'CHECKED_OUT'
    && ($rJ2['result'] ?? '') === 'ALREADY_OUTSIDE'
    && ($rJ3['result'] ?? '') === 'ALREADY_OUTSIDE'
    && count(events_for($sessionA, $studentX)) === $eventsJ1
    && ($rowJ3['last_exit'] ?? '') === ($rowJ1['last_exit'] ?? '')
) {
    pass('J/W repeated EXIT after final OUT does not rewrite last_exit or duplicate');
} else {
    fail('J/W expected ALREADY_OUTSIDE with stable last_exit j1=' . ($rJ1['result'] ?? '')
        . ' j2=' . ($rJ2['result'] ?? '') . ' j3=' . ($rJ3['result'] ?? ''));
}

// ---------------------------------------------------------------------------
// K–O Session A outstanding + Session B live
// ---------------------------------------------------------------------------
wipe_session($sessionA);
wipe_session($sessionB);
set_session_status($sessionA, 'IN_PROGRESS', $aStart, null);
freeze_now($aStart->modify('+10 minutes'));
record_face_attendance_event($studentX, 91.0, 'webcam-0', 'ENTRY');
set_session_status($sessionA, 'COMPLETED', $aStart, $aEnd);
finalize_session_attendance($sessionA);
set_session_status($sessionB, 'IN_PROGRESS', $bStart, null);

if ($studentY > 0) {
    freeze_now($bStart->modify('+5 minutes'));
    $rL = record_face_attendance_event($studentY, 90.0, 'webcam-0', 'ENTRY');
    if (($rL['result'] ?? '') === 'CHECKED_IN' && (int) ($rL['session_id'] ?? 0) === $sessionB) {
        pass('L Session B ENTRY still works while A has unresolved checkout');
    } else {
        fail('L expected Student Y CHECKED_IN on Session B result=' . ($rL['result'] ?? ''));
    }
} else {
    pass('L skipped (no second enrolled student for Session B ENTRY)');
}

$expectedK = $bStart->modify('+20 minutes')->format('Y-m-d H:i:s');
freeze_now($bStart->modify('+20 minutes'));
$rK = record_face_attendance_event($studentX, 88.0, 'webcam-0', 'EXIT');
$eventsKA = events_for($sessionA, $studentX);
$eventsKB = events_for($sessionB, $studentX);
$rowK = record_for($sessionA, $studentX);
$outsKA = array_values(array_filter($eventsKA, static fn (array $e): bool => $e['event_type'] === 'OUT'));
$lastOutKA = $outsKA !== [] ? $outsKA[array_key_last($outsKA)] : null;
if (
    ($rK['result'] ?? '') === 'CHECKED_OUT'
    && (int) ($rK['session_id'] ?? 0) === $sessionA
    && ($lastOutKA['recognized_at'] ?? '') === $expectedK
    && ($rowK['last_exit'] ?? '') === $expectedK
    && $eventsKB === []
) {
    pass('K Session A student EXIT attaches to A while B is live');
    pass('O no Session A OUT written into Session B');
} else {
    fail('K/O expected EXIT on A only; result=' . ($rK['result'] ?? '')
        . ' session=' . ($rK['session_id'] ?? '')
        . ' b_events=' . count($eventsKB));
}

// ---------------------------------------------------------------------------
// M–N eligible for both: unresolved A + B active + EXIT closes A; then ENTRY → B
// ---------------------------------------------------------------------------
wipe_session($sessionA);
wipe_session($sessionB);
set_session_status($sessionA, 'IN_PROGRESS', $aStart, null);
freeze_now($aStart->modify('+10 minutes'));
record_face_attendance_event($studentX, 91.0, 'webcam-0', 'ENTRY');
set_session_status($sessionA, 'COMPLETED', $aStart, $aEnd);
finalize_session_attendance($sessionA);
set_session_status($sessionB, 'IN_PROGRESS', $bStart, null);
freeze_now($bStart->modify('+10 minutes'));
$rM = record_face_attendance_event($studentX, 88.0, 'webcam-0', 'EXIT');
if (($rM['result'] ?? '') === 'CHECKED_OUT' && (int) ($rM['session_id'] ?? 0) === $sessionA) {
    pass('M unresolved A IN + B active + EXIT → closes A');
} else {
    fail('M expected EXIT to close Session A result=' . ($rM['result'] ?? '')
        . ' session=' . ($rM['session_id'] ?? ''));
}

freeze_now($bStart->modify('+12 minutes'));
$rN = record_face_attendance_event($studentX, 91.0, 'webcam-0', 'ENTRY');
if (($rN['result'] ?? '') === 'CHECKED_IN' && (int) ($rN['session_id'] ?? 0) === $sessionB) {
    pass('N after closing A, ENTRY may enter B normally');
} else {
    fail('N expected CHECKED_IN on Session B result=' . ($rN['result'] ?? '')
        . ' session=' . ($rN['session_id'] ?? ''));
}

// ---------------------------------------------------------------------------
// X early-pending smoke (must not break)
// ---------------------------------------------------------------------------
wipe_session($sessionB);
set_session_status($sessionB, 'SCHEDULED', $bStart, null);
db()->prepare(
    "UPDATE lecture_sessions SET status = 'SCHEDULED', actual_start = NULL, actual_end = NULL WHERE session_id = :id"
)->execute(['id' => $sessionB]);
freeze_now($bStart->modify('-5 minutes'));
$rX = record_face_attendance_event($studentX, 90.0, 'webcam-0', 'ENTRY');
if (in_array(($rX['result'] ?? ''), ['EARLY_PENDING', 'TOO_EARLY', 'CHECKED_IN'], true)) {
    pass('X early-pending / early-window behaviour still responds (' . ($rX['result'] ?? '') . ')');
} else {
    fail('X unexpected early-window result=' . ($rX['result'] ?? ''));
}

set_app_now_override(null);
db()->prepare(
    "UPDATE lecture_sessions SET status = 'COMPLETED', actual_start = :s, actual_end = :e WHERE session_id = :id"
)->execute([
    's' => $aStart->format('Y-m-d H:i:s'),
    'e' => $aEnd->format('Y-m-d H:i:s'),
    'id' => $sessionA,
]);
db()->prepare(
    "UPDATE lecture_sessions SET status = 'COMPLETED', actual_start = :s, actual_end = :e WHERE session_id = :id"
)->execute([
    's' => $bStart->format('Y-m-d H:i:s'),
    'e' => $bEnd->format('Y-m-d H:i:s'),
    'id' => $sessionB,
]);

echo ($failed ? 'FAILED' : 'ALL PASS') . PHP_EOL;
exit($failed ? 1 : 0);
