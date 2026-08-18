<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — verify timetable auto-start / auto-complete.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_session_lifecycle.php
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

$ids = db()->query(
    'SELECT m.module_id, ml.lecturer_id, b.batch_id
     FROM modules m
     INNER JOIN module_lecturers ml ON ml.module_id = m.module_id
     INNER JOIN batches b ON b.course_id = m.course_id
     WHERE m.status = \'ACTIVE\'
     LIMIT 1'
)->fetch();

if ($ids === false) {
    fwrite(STDERR, "No module/lecturer/batch trio found.\n");
    exit(1);
}

$failed = false;
$tz = new DateTimeZone(APP_TIMEZONE);
$now = app_now();

echo 'timezone=' . APP_TIMEZONE . PHP_EOL;
echo 'now=' . $now->format('Y-m-d H:i:s') . PHP_EOL;

$busy = db()->prepare(
    "SELECT session_id, status, room, scheduled_start, scheduled_end
     FROM lecture_sessions
     WHERE status = 'IN_PROGRESS'
       AND (lecturer_id = :lecturer_id OR batch_id = :batch_id)"
);
$busy->execute([
    'lecturer_id' => $ids['lecturer_id'],
    'batch_id' => $ids['batch_id'],
]);
$blocking = $busy->fetchAll();
foreach ($blocking as $row) {
    $room = (string) ($row['room'] ?? '');
    if (str_starts_with($room, 'LIFE-')) {
        db()->prepare(
            "UPDATE lecture_sessions
             SET status = 'COMPLETED', actual_end = :actual_end
             WHERE session_id = :session_id AND status = 'IN_PROGRESS'"
        )->execute([
            'actual_end' => $now->format('Y-m-d H:i:s'),
            'session_id' => $row['session_id'],
        ]);
        echo 'cleared leftover test session_id=' . $row['session_id'] . PHP_EOL;
        continue;
    }
    echo 'blocking_in_progress session_id=' . $row['session_id']
        . ' room=' . $room
        . ' ' . $row['scheduled_start'] . '-' . $row['scheduled_end']
        . PHP_EOL;
}

$start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $now->modify('+1 minute')->format('Y-m-d H:i'), $tz);
if (!$start instanceof DateTimeImmutable) {
    fwrite(STDERR, "Could not build scheduled start.\n");
    exit(1);
}
$end = $start->modify('+1 minute');
if ($end->format('Y-m-d') !== $start->format('Y-m-d')) {
    fwrite(STDERR, "Test would cross midnight. Re-run shortly.\n");
    exit(1);
}

function lifecycle_create(array $ids, DateTimeImmutable $start, DateTimeImmutable $end, string $room): int
{
    try {
        return create_lecture_session([
            'module_id' => (int) $ids['module_id'],
            'lecturer_id' => (int) $ids['lecturer_id'],
            'batch_id' => (int) $ids['batch_id'],
            'session_date' => $start->format('Y-m-d'),
            'scheduled_start' => $start->format('H:i'),
            'scheduled_end' => $end->format('H:i'),
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
            'd' => $start->format('Y-m-d'),
            's' => $start->format('H:i:s'),
        ]);
        $existing = (int) $stmt->fetchColumn();
        if ($existing <= 0) {
            throw $exception;
        }
        db()->prepare(
            "UPDATE lecture_sessions
             SET status = 'SCHEDULED', actual_start = NULL, actual_end = NULL,
                 scheduled_end = :scheduled_end, room = :room
             WHERE session_id = :session_id"
        )->execute([
            'scheduled_end' => $end->format('H:i:s'),
            'room' => $room,
            'session_id' => $existing,
        ]);

        return $existing;
    }
}

$sessionId = lifecycle_create($ids, $start, $end, 'LIFE-WAIT');
echo 'test_session_id=' . $sessionId . PHP_EOL;
echo 'scheduled=' . $start->format('Y-m-d H:i') . ' to ' . $end->format('H:i') . PHP_EOL;

$pdo = db();
$pdo->beginTransaction();
try {
    $nested = get_lecture_session($sessionId);
    $pdo->rollBack();
    echo 'nested_transaction_status=' . ($nested['status'] ?? '') . PHP_EOL;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo 'nested_transaction_error=' . $exception->getMessage() . PHP_EOL;
    $failed = true;
}

$before = get_lecture_session($sessionId);
echo 'before_start status=' . ($before['status'] ?? '') . ' actual_start=' . ($before['actual_start'] ?? '') . PHP_EOL;
if (($before['status'] ?? '') !== 'SCHEDULED') {
    fwrite(STDERR, "Expected SCHEDULED before scheduled start.\n");
    $failed = true;
}

$waitStart = max(0, $start->getTimestamp() - app_now()->getTimestamp() + 5);
echo 'waiting_seconds_until_start=' . $waitStart . PHP_EOL;
if ($waitStart > 0) {
    sleep($waitStart);
}

sync_scheduled_session_states(true);
$opened = get_lecture_session($sessionId);
echo 'after_start now=' . app_now()->format('Y-m-d H:i:s')
    . ' status=' . ($opened['status'] ?? '')
    . ' actual_start=' . ($opened['actual_start'] ?? '')
    . PHP_EOL;
if (($opened['status'] ?? '') !== 'IN_PROGRESS') {
    fwrite(STDERR, "Expected IN_PROGRESS after scheduled start without pressing Start.\n");
    $failed = true;
} elseif (($opened['actual_start'] ?? '') !== $start->format('Y-m-d H:i:s')) {
    fwrite(STDERR, "Expected actual_start to equal scheduled start.\n");
    $failed = true;
}

$waitEnd = max(0, $end->getTimestamp() - app_now()->getTimestamp() + 5);
echo 'waiting_seconds_until_end=' . $waitEnd . PHP_EOL;
if ($waitEnd > 0) {
    sleep($waitEnd);
}

sync_scheduled_session_states(true);
$closed = get_lecture_session($sessionId);
echo 'after_end now=' . app_now()->format('Y-m-d H:i:s')
    . ' status=' . ($closed['status'] ?? '')
    . ' actual_end=' . ($closed['actual_end'] ?? '')
    . PHP_EOL;
if (($closed['status'] ?? '') !== 'COMPLETED') {
    fwrite(STDERR, "Expected COMPLETED after scheduled end.\n");
    $failed = true;
}

exit($failed ? 1 : 0);
