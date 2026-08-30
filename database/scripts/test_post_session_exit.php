<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — session-specific final OUT after COMPLETED (legacy script name).
 *
 * Tests:
 *   A EXIT +4m after end → CHECKED_OUT + last_exit
 *   B second EXIT → ALREADY_OUTSIDE, last_exit unchanged
 *   C ENTRY after complete → no IN
 *   D EXIT +11m after already checked out → ALREADY_OUTSIDE (no rewrite)
 *   E already OUT before end → no post-session OUT
 *   F recalculate keeps teaching math + physical last_exit
 *   G first EXIT +11m while still inside → CHECKED_OUT (no 10-minute gate)
 *   H first EXIT +45m while still inside → CHECKED_OUT
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_post_session_exit.php
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

$studentId = (int) $ids['student_id'];
$date = app_today();
$start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' 07:00', $tz);
$end = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' 09:00', $tz);
if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
    fwrite(STDERR, "Could not build 07:00–09:00 session times.\n");
    exit(1);
}

try {
    $sessionId = create_lecture_session([
        'module_id' => (int) $ids['module_id'],
        'lecturer_id' => (int) $ids['lecturer_id'],
        'batch_id' => (int) $ids['batch_id'],
        'session_date' => $date,
        'scheduled_start' => '07:00',
        'scheduled_end' => '09:00',
        'room' => 'LIFE-POSTEXIT',
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
        's' => '07:00:00',
    ]);
    $sessionId = (int) $stmt->fetchColumn();
    if ($sessionId <= 0) {
        throw $exception;
    }
}

function wipe_post(int $sessionId): void
{
    db()->prepare('DELETE FROM attendance_records WHERE session_id = :id')->execute(['id' => $sessionId]);
    db()->prepare('UPDATE attendance_early_pending SET promoted_event_id = NULL WHERE session_id = :id')->execute(['id' => $sessionId]);
    db()->prepare('DELETE FROM attendance_early_pending WHERE session_id = :id')->execute(['id' => $sessionId]);
    db()->prepare('DELETE FROM attendance_events WHERE session_id = :id')->execute(['id' => $sessionId]);
}

function complete_post_session(int $sessionId, DateTimeImmutable $start, DateTimeImmutable $end): void
{
    db()->prepare(
        "UPDATE lecture_sessions
         SET status = 'COMPLETED', actual_start = :actual_start, actual_end = :actual_end
         WHERE session_id = :session_id"
    )->execute([
        'actual_start' => $start->format('Y-m-d H:i:s'),
        'actual_end' => $end->format('Y-m-d H:i:s'),
        'session_id' => $sessionId,
    ]);
}

function add_post_event(int $studentId, int $sessionId, string $type, DateTimeImmutable $at): void
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

function record_for_post(int $sessionId, int $studentId): ?array
{
    $stmt = db()->prepare(
        'SELECT total_present_minutes, teaching_minutes, attendance_percent, status, left_early, first_entry, last_exit
         FROM attendance_records WHERE session_id = :s AND student_id = :st LIMIT 1'
    );
    $stmt->execute(['s' => $sessionId, 'st' => $studentId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function snapshot_record(?array $row): array
{
    return [
        'attended' => (int) ($row['total_present_minutes'] ?? -1),
        'teaching' => (int) ($row['teaching_minutes'] ?? -1),
        'percent' => (string) ($row['attendance_percent'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'left_early' => (int) ($row['left_early'] ?? -1),
        'last_exit' => $row['last_exit'] ?? null,
    ];
}

function snapshot_calc(?array $row): array
{
    $all = snapshot_record($row);
    unset($all['last_exit']);

    return $all;
}

echo 'timezone=' . APP_TIMEZONE . PHP_EOL;
echo 'student_id=' . $studentId . PHP_EOL;
echo 'session_id=' . $sessionId . PHP_EOL;
echo 'legacy_capture_minutes=' . post_session_exit_capture_minutes() . ' (unused by recognition path)' . PHP_EOL;

wipe_post($sessionId);
complete_post_session($sessionId, $start, $end);
add_post_event($studentId, $sessionId, 'IN', $start->modify('+2 minutes'));
add_post_event($studentId, $sessionId, 'OUT', $start->modify('+90 minutes'));
add_post_event($studentId, $sessionId, 'IN', $start->modify('+95 minutes'));
finalize_session_attendance($sessionId);
$beforeRow = record_for_post($sessionId, $studentId);
$calcBefore = snapshot_calc($beforeRow);
$eventsBefore = count(events_for($sessionId, $studentId));
$expectedOut = $end->modify('+4 minutes')->format('Y-m-d H:i:s');

freeze_now($end->modify('+4 minutes'));
$a = record_face_attendance_event($studentId, 88.0, 'webcam-0', 'EXIT');
$eventsA = events_for($sessionId, $studentId);
$outsA = array_values(array_filter($eventsA, static fn (array $row): bool => $row['event_type'] === 'OUT'));
$lastOut = $outsA !== [] ? $outsA[array_key_last($outsA)] : null;
$rowA = record_for_post($sessionId, $studentId);
echo 'testA result=' . ($a['result'] ?? '') . ' recognized_at=' . ($a['recognized_at'] ?? '')
    . ' last_exit=' . ($rowA['last_exit'] ?? '') . PHP_EOL;
if (
    ($a['result'] ?? '') === 'CHECKED_OUT'
    && ($lastOut['recognized_at'] ?? '') === $expectedOut
    && ($rowA['last_exit'] ?? '') === $expectedOut
    && count($eventsA) === $eventsBefore + 1
) {
    pass('A EXIT 09:04 stored as OUT and last_exit');
} else {
    fail('A expected CHECKED_OUT and last_exit at 09:04');
}

echo 'testA calc before=' . json_encode($calcBefore) . ' after=' . json_encode(snapshot_calc($rowA)) . PHP_EOL;
if (snapshot_calc($rowA) === $calcBefore) {
    pass('A attended/teaching/%/status/left_early unchanged');
} else {
    fail('A calculation fields changed after post-session EXIT');
}

freeze_now($end->modify('+5 minutes'));
$b = record_face_attendance_event($studentId, 88.0, 'webcam-0', 'EXIT');
$eventsB = events_for($sessionId, $studentId);
$rowB = record_for_post($sessionId, $studentId);
echo 'testB result=' . ($b['result'] ?? '') . ' events=' . count($eventsB) . ' last_exit=' . ($rowB['last_exit'] ?? '') . PHP_EOL;
if (
    ($b['result'] ?? '') === 'ALREADY_OUTSIDE'
    && count($eventsB) === count($eventsA)
    && ($rowB['last_exit'] ?? '') === $expectedOut
) {
    pass('B second EXIT does not insert duplicate OUT or change last_exit');
} else {
    fail('B expected ALREADY_OUTSIDE and unchanged last_exit');
}

freeze_now($end->modify('+4 minutes'));
$c = record_face_attendance_event($studentId, 88.0, 'webcam-0', 'ENTRY');
$insC = array_values(array_filter(events_for($sessionId, $studentId), static fn (array $row): bool => $row['event_type'] === 'IN'));
echo 'testC result=' . ($c['result'] ?? '') . ' in_count=' . count($insC) . PHP_EOL;
if (($c['result'] ?? '') === 'NO_ACTIVE_SESSION' && count($insC) === 2) {
    pass('C post-session ENTRY creates no event');
} else {
    fail('C expected NO_ACTIVE_SESSION and no extra IN');
}

freeze_now($end->modify('+11 minutes'));
$d = record_face_attendance_event($studentId, 88.0, 'webcam-0', 'EXIT');
$eventsD = events_for($sessionId, $studentId);
$rowD = record_for_post($sessionId, $studentId);
echo 'testD result=' . ($d['result'] ?? '') . ' events=' . count($eventsD) . ' last_exit=' . ($rowD['last_exit'] ?? '') . PHP_EOL;
if (
    ($d['result'] ?? '') === 'ALREADY_OUTSIDE'
    && count($eventsD) === count($eventsB)
    && ($rowD['last_exit'] ?? '') === $expectedOut
) {
    pass('D EXIT after checkout (even past former 10m window) does not rewrite last_exit');
} else {
    fail('D expected ALREADY_OUTSIDE with unchanged last_exit');
}

finalize_session_attendance($sessionId);
$afterF = record_for_post($sessionId, $studentId);
echo 'testF calc before=' . json_encode($calcBefore) . ' after=' . json_encode(snapshot_calc($afterF))
    . ' last_exit=' . ($afterF['last_exit'] ?? '') . PHP_EOL;
if (snapshot_calc($afterF) === $calcBefore && ($afterF['last_exit'] ?? '') === $expectedOut) {
    pass('F recalculate keeps calculations and post-session Last Out');
} else {
    fail('F expected same calculations with last_exit still 09:04');
}

wipe_post($sessionId);
complete_post_session($sessionId, $start, $end);
add_post_event($studentId, $sessionId, 'IN', $start->modify('+2 minutes'));
add_post_event($studentId, $sessionId, 'OUT', $start->modify('+90 minutes'));
finalize_session_attendance($sessionId);
$eventsEBefore = events_for($sessionId, $studentId);
freeze_now($end->modify('+4 minutes'));
$e = record_face_attendance_event($studentId, 88.0, 'webcam-0', 'EXIT');
$eventsE = events_for($sessionId, $studentId);
echo 'testE result=' . ($e['result'] ?? '') . ' events=' . count($eventsE) . PHP_EOL;
if (($e['result'] ?? '') === 'ALREADY_OUTSIDE' && count($eventsE) === count($eventsEBefore)) {
    pass('E already OUT before 09:00 gets no post-session OUT');
} else {
    fail('E expected ALREADY_OUTSIDE and no extra OUT');
}

wipe_post($sessionId);
complete_post_session($sessionId, $start, $end);
add_post_event($studentId, $sessionId, 'IN', $start->modify('+2 minutes'));
finalize_session_attendance($sessionId);
$calcG = snapshot_calc(record_for_post($sessionId, $studentId));
$expectedG = $end->modify('+11 minutes')->format('Y-m-d H:i:s');
freeze_now($end->modify('+11 minutes'));
$g = record_face_attendance_event($studentId, 88.0, 'webcam-0', 'EXIT');
$rowG = record_for_post($sessionId, $studentId);
echo 'testG result=' . ($g['result'] ?? '') . ' last_exit=' . ($rowG['last_exit'] ?? '') . PHP_EOL;
if (
    ($g['result'] ?? '') === 'CHECKED_OUT'
    && ($rowG['last_exit'] ?? '') === $expectedG
    && snapshot_calc($rowG) === $calcG
) {
    pass('G EXIT +11m while still inside records final OUT (no 10-minute gate)');
} else {
    fail('G expected CHECKED_OUT at +11m with calc unchanged');
}

wipe_post($sessionId);
complete_post_session($sessionId, $start, $end);
add_post_event($studentId, $sessionId, 'IN', $start->modify('+2 minutes'));
finalize_session_attendance($sessionId);
$calcH = snapshot_calc(record_for_post($sessionId, $studentId));
$expectedH = $end->modify('+45 minutes')->format('Y-m-d H:i:s');
freeze_now($end->modify('+45 minutes'));
$h = record_face_attendance_event($studentId, 88.0, 'webcam-0', 'EXIT');
$rowH = record_for_post($sessionId, $studentId);
echo 'testH result=' . ($h['result'] ?? '') . ' last_exit=' . ($rowH['last_exit'] ?? '') . PHP_EOL;
if (
    ($h['result'] ?? '') === 'CHECKED_OUT'
    && ($rowH['last_exit'] ?? '') === $expectedH
    && snapshot_calc($rowH) === $calcH
) {
    pass('H EXIT +45m while still inside records final OUT');
} else {
    fail('H expected CHECKED_OUT at +45m with calc unchanged');
}

set_app_now_override(null);
exit($failed ? 1 : 0);
