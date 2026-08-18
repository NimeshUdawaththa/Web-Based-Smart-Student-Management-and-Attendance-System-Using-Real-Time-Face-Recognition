<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — early door-camera pending promotion (Tests 1–7).
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_early_pending.php
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

function ids_for_test(): array
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
        fwrite(STDERR, "No enrolled student with an assigned lecturer found.\n");
        exit(1);
    }

    return $row;
}

function ensure_session(array $ids, DateTimeImmutable $start, DateTimeImmutable $end): int
{
    try {
        return create_lecture_session([
            'module_id' => (int) $ids['module_id'],
            'lecturer_id' => (int) $ids['lecturer_id'],
            'batch_id' => (int) $ids['batch_id'],
            'session_date' => $start->format('Y-m-d'),
            'scheduled_start' => $start->format('H:i'),
            'scheduled_end' => $end->format('H:i'),
            'room' => 'LIFE-PENDING',
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
            'd' => $start->format('Y-m-d'),
            's' => $start->format('H:i:s'),
        ]);
        $sessionId = (int) $stmt->fetchColumn();
        if ($sessionId <= 0) {
            throw $exception;
        }

        return $sessionId;
    }
}

function reset_session_state(int $sessionId, int $studentId): void
{
    db()->prepare(
        'UPDATE attendance_early_pending SET promoted_event_id = NULL WHERE session_id = :session_id'
    )->execute(['session_id' => $sessionId]);
    db()->prepare('DELETE FROM attendance_early_pending WHERE session_id = :session_id')
        ->execute(['session_id' => $sessionId]);
    db()->prepare(
        'DELETE FROM attendance_events WHERE session_id = :session_id AND student_id = :student_id'
    )->execute([
        'session_id' => $sessionId,
        'student_id' => $studentId,
    ]);
    db()->prepare(
        "UPDATE lecture_sessions
         SET status = 'SCHEDULED', actual_start = NULL, actual_end = NULL
         WHERE session_id = :session_id"
    )->execute(['session_id' => $sessionId]);
}

function pending_row(int $sessionId, int $studentId): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM attendance_early_pending
         WHERE session_id = :session_id AND student_id = :student_id
         LIMIT 1'
    );
    $stmt->execute([
        'session_id' => $sessionId,
        'student_id' => $studentId,
    ]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function event_rows(int $sessionId, int $studentId): array
{
    $stmt = db()->prepare(
        'SELECT event_id, event_type, recognized_at
         FROM attendance_events
         WHERE session_id = :session_id AND student_id = :student_id
         ORDER BY recognized_at ASC, event_id ASC'
    );
    $stmt->execute([
        'session_id' => $sessionId,
        'student_id' => $studentId,
    ]);

    return $stmt->fetchAll();
}

function recognize(int $studentId, string $mode): array
{
    return record_face_attendance_event($studentId, 88.5, 'webcam-0', $mode);
}

sync_scheduled_session_states(true);

$ids = ids_for_test();
$studentId = (int) $ids['student_id'];
$start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', app_today() . ' 09:00', $tz);
$end = DateTimeImmutable::createFromFormat('!Y-m-d H:i', app_today() . ' 11:00', $tz);
if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
    fwrite(STDERR, "Could not build 09:00–11:00 session times.\n");
    exit(1);
}

$sessionId = ensure_session($ids, $start, $end);
reset_session_state($sessionId, $studentId);

echo 'timezone=' . APP_TIMEZONE . PHP_EOL;
echo 'student_id=' . $studentId . PHP_EOL;
echo 'session_id=' . $sessionId . PHP_EOL;
echo 'lecture=09:00-11:00 early_window=' . early_arrival_minutes() . ' min' . PHP_EOL;

$at0840 = $start->modify('-20 minutes');
$at0850 = $start->modify('-10 minutes');
$at0855 = $start->modify('-5 minutes');
$at0858 = $start->modify('-2 minutes');

freeze_now($at0840);
$t1 = recognize($studentId, 'ENTRY');
$p1 = pending_row($sessionId, $studentId);
$e1 = event_rows($sessionId, $studentId);
echo 'test1 result=' . ($t1['result'] ?? '') . ' pending=' . ($p1 === null ? 'none' : 'yes') . ' events=' . count($e1) . PHP_EOL;
if (($t1['result'] ?? '') === 'TOO_EARLY' && $p1 === null && $e1 === []) {
    pass('Test 1 TOO EARLY, no pending, no event');
} else {
    fail('Test 1 expected TOO_EARLY with no pending/event');
}

freeze_now($at0850);
$t2 = recognize($studentId, 'ENTRY');
$p2 = pending_row($sessionId, $studentId);
$e2 = event_rows($sessionId, $studentId);
echo 'test2 result=' . ($t2['result'] ?? '') . ' status=' . ($p2['status'] ?? '') . ' inside=' . ($p2['inside'] ?? '') . PHP_EOL;
if (
    ($t2['result'] ?? '') === 'EARLY_PENDING'
    && ($p2['status'] ?? '') === 'OPEN'
    && (int) ($p2['inside'] ?? 0) === 1
    && $e2 === []
) {
    pass('Test 2 EARLY ARRIVAL / PENDING, OPEN inside=1, no official IN');
} else {
    fail('Test 2 expected EARLY_PENDING OPEN inside=1 and no events');
}

freeze_now($start);
sync_scheduled_session_states(true);
$p2b = pending_row($sessionId, $studentId);
$e2b = event_rows($sessionId, $studentId);
$ins = array_values(array_filter($e2b, static fn (array $row): bool => $row['event_type'] === 'IN'));
echo 'test2b status=' . ($p2b['status'] ?? '') . ' in_count=' . count($ins) . ' recognized_at=' . ($ins[0]['recognized_at'] ?? '') . PHP_EOL;
if (
    count($ins) === 1
    && ($ins[0]['recognized_at'] ?? '') === $start->format('Y-m-d H:i:s')
    && ($p2b['status'] ?? '') === 'PROMOTED'
) {
    pass('Test 2 promotion: exactly one IN at 09:00, pending PROMOTED');
} else {
    fail('Test 2 promotion expected one IN at scheduled start and PROMOTED');
}

sync_scheduled_session_states(true);
$e2c = event_rows($sessionId, $studentId);
if (count(array_filter($e2c, static fn (array $row): bool => $row['event_type'] === 'IN')) === 1) {
    pass('Test 2 duplicate sync did not create a second IN');
} else {
    fail('Test 2 duplicate promotion created extra IN events');
}

freeze_now($start->modify('+1 minute'));
$t5 = recognize($studentId, 'ENTRY');
$e5 = event_rows($sessionId, $studentId);
echo 'test5 result=' . ($t5['result'] ?? '') . ' events=' . count($e5) . PHP_EOL;
if (($t5['result'] ?? '') === 'ALREADY_INSIDE' && count($ins) === 1 && count($e5) === 1) {
    pass('Test 5 ALREADY INSIDE, no duplicate IN');
} else {
    fail('Test 5 expected ALREADY_INSIDE with still one event');
}

$t6 = recognize($studentId, 'EXIT');
$e6 = event_rows($sessionId, $studentId);
echo 'test6 result=' . ($t6['result'] ?? '') . PHP_EOL;
if (($t6['result'] ?? '') === 'CHECKED_OUT' && count($e6) === 2 && $e6[1]['event_type'] === 'OUT') {
    pass('Test 6 CHECKED OUT');
} else {
    fail('Test 6 expected CHECKED_OUT');
}

$t7 = recognize($studentId, 'ENTRY');
$e7 = event_rows($sessionId, $studentId);
echo 'test7 result=' . ($t7['result'] ?? '') . PHP_EOL;
if (($t7['result'] ?? '') === 'RE_ENTERED' && count($e7) === 3 && $e7[2]['event_type'] === 'IN') {
    pass('Test 7 RE-ENTERED');
} else {
    fail('Test 7 expected RE_ENTERED');
}

reset_session_state($sessionId, $studentId);
freeze_now($at0850);
recognize($studentId, 'ENTRY');
freeze_now($at0855);
$t3exit = recognize($studentId, 'EXIT');
$p3mid = pending_row($sessionId, $studentId);
freeze_now($start);
sync_scheduled_session_states(true);
$p3 = pending_row($sessionId, $studentId);
$e3 = event_rows($sessionId, $studentId);
echo 'test3 exit_result=' . ($t3exit['result'] ?? '') . ' mid_inside=' . ($p3mid['inside'] ?? '')
    . ' final_status=' . ($p3['status'] ?? '') . ' events=' . count($e3) . PHP_EOL;
if (
    ($t3exit['result'] ?? '') === 'EARLY_EXIT'
    && (int) ($p3mid['inside'] ?? 1) === 0
    && $e3 === []
    && ($p3['status'] ?? '') === 'CANCELLED'
) {
    pass('Test 3 EXIT cancelled pending; no IN at 09:00');
} else {
    fail('Test 3 expected EARLY_EXIT, inside=0, CANCELLED, no events');
}

reset_session_state($sessionId, $studentId);
freeze_now($at0850);
recognize($studentId, 'ENTRY');
freeze_now($at0855);
recognize($studentId, 'EXIT');
freeze_now($at0858);
$t4entry = recognize($studentId, 'ENTRY');
$p4mid = pending_row($sessionId, $studentId);
freeze_now($start);
sync_scheduled_session_states(true);
$p4 = pending_row($sessionId, $studentId);
$e4 = event_rows($sessionId, $studentId);
$ins4 = array_values(array_filter($e4, static fn (array $row): bool => $row['event_type'] === 'IN'));
echo 'test4 reentry=' . ($t4entry['result'] ?? '') . ' inside=' . ($p4mid['inside'] ?? '')
    . ' status=' . ($p4['status'] ?? '') . ' in_count=' . count($ins4) . PHP_EOL;
if (
    ($t4entry['result'] ?? '') === 'EARLY_PENDING'
    && (int) ($p4mid['inside'] ?? 0) === 1
    && count($ins4) === 1
    && ($ins4[0]['recognized_at'] ?? '') === $start->format('Y-m-d H:i:s')
    && ($p4['status'] ?? '') === 'PROMOTED'
) {
    pass('Test 4 re-entry before start promoted to exactly one IN at 09:00');
} else {
    fail('Test 4 expected one promoted IN at scheduled start');
}

set_app_now_override(null);
db()->prepare(
    "UPDATE lecture_sessions
     SET status = 'COMPLETED', actual_end = :actual_end
     WHERE session_id = :session_id AND status IN ('SCHEDULED', 'IN_PROGRESS')"
)->execute([
    'actual_end' => $end->format('Y-m-d H:i:s'),
    'session_id' => $sessionId,
]);
cancel_open_early_pending_for_session(db(), $sessionId);

exit($failed ? 1 : 0);
