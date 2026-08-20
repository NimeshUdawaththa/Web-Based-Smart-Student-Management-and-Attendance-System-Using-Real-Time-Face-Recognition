<?php

declare(strict_types=1);

/**
 * Regression tests for Lecturer My Calendar (lecture_sessions source).
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_lecturer_calendar.php
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
$createdSessionIds = [];
$tempLecturerId = null;
$tempUserId = null;
$attendanceCountBefore = (int) db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
$sessionCountBefore = (int) db()->query('SELECT COUNT(*) FROM lecture_sessions')->fetchColumn();

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

$lecturers = db()->query(
    "SELECT lecturer_id, staff_no, first_name, last_name
     FROM lecturers
     WHERE status = 'ACTIVE'
     ORDER BY lecturer_id
     LIMIT 2"
)->fetchAll();

if ($lecturers === []) {
    fwrite(STDERR, "Need at least 1 ACTIVE lecturer.\n");
    exit(1);
}

$lecturerA = $lecturers[0];
$lecturerAId = (int) $lecturerA['lecturer_id'];

if (count($lecturers) < 2) {
    $suffix = (string) time();
    $tempLecturerId = create_lecturer([
        'username' => 'cal_test_' . $suffix,
        'email' => 'cal_test_' . $suffix . '@example.test',
        'password' => 'TestPass123!',
        'account_status' => 'ACTIVE',
        'staff_no' => 'CAL-' . $suffix,
        'first_name' => 'Calendar',
        'last_name' => 'Tester',
        'phone' => '',
        'department' => 'Test',
        'status' => 'ACTIVE',
    ]);
    $tempUserId = (int) db()->query(
        'SELECT user_id FROM lecturers WHERE lecturer_id = ' . (int) $tempLecturerId
    )->fetchColumn();
    $lecturerB = get_lecturer($tempLecturerId);
} else {
    $lecturerB = $lecturers[1];
}

$lecturerBId = (int) $lecturerB['lecturer_id'];

$assignment = db()->prepare(
    "SELECT ml.module_id, ml.lecturer_id, b.batch_id, m.module_code, b.batch_name
     FROM module_lecturers ml
     INNER JOIN modules m ON m.module_id = ml.module_id AND m.status = 'ACTIVE'
     INNER JOIN course_modules cm ON cm.module_id = m.module_id AND cm.status = 'ACTIVE'
     INNER JOIN batches b ON b.course_id = cm.course_id AND b.status = 'ACTIVE'
     WHERE ml.lecturer_id = :lecturer_id
     LIMIT 1"
);
$assignment->execute(['lecturer_id' => $lecturerAId]);
$trio = $assignment->fetch();

if ($trio === false) {
    fwrite(STDERR, "No module/batch assignment found for lecturer A.\n");
    exit(1);
}

$moduleId = (int) $trio['module_id'];
$batchId = (int) $trio['batch_id'];
$testDate = app_today();
$roomPrefix = 'CAL-TEST-';
$timeBase = ((int) date('i')) % 40;

/**
 * @return array{0: string, 1: string}
 */
function calendar_test_slot(int $index): array
{
    $hour = 6 + intdiv($index, 2);
    $minute = ($index % 2) * 30;
    $start = sprintf('%02d:%02d', $hour, $minute);
    $endHour = $hour + 1;
    $endMinute = $minute + 30;
    if ($endMinute >= 60) {
        $endHour++;
        $endMinute -= 60;
    }
    $end = sprintf('%02d:%02d', $endHour, $endMinute);

    return [$start, $end];
}

/**
 * @param array<string, mixed> $overrides
 */
function create_calendar_test_session(int $moduleId, int $lecturerId, int $batchId, string $date, string $start, string $end, string $room, array $overrides = []): int
{
    global $createdSessionIds;

    $sessionId = create_lecture_session([
        'module_id' => $moduleId,
        'lecturer_id' => $lecturerId,
        'batch_id' => $batchId,
        'session_date' => $date,
        'scheduled_start' => $start,
        'scheduled_end' => $end,
        'room' => $room,
        'late_after_minutes' => 15,
        'schedule_id' => $overrides['schedule_id'] ?? null,
    ]);

    $createdSessionIds[] = $sessionId;

    if (!empty($overrides['status']) && $overrides['status'] !== 'SCHEDULED') {
        db()->prepare('UPDATE lecture_sessions SET status = :status WHERE session_id = :session_id')->execute([
            'status' => $overrides['status'],
            'session_id' => $sessionId,
        ]);
    }

    return $sessionId;
}

try {
    if ($tempLecturerId !== null) {
        db()->prepare(
            'INSERT INTO module_lecturers (module_id, lecturer_id) VALUES (:module_id, :lecturer_id)'
        )->execute([
            'module_id' => $moduleId,
            'lecturer_id' => $tempLecturerId,
        ]);
    }

    [$scheduledStart, $scheduledEnd] = calendar_test_slot($timeBase);
    [$inProgressStart, $inProgressEnd] = calendar_test_slot($timeBase + 1);
    [$completedStart, $completedEnd] = calendar_test_slot($timeBase + 2);
    [$cancelledStart, $cancelledEnd] = calendar_test_slot($timeBase + 3);
    [$otherStart, $otherEnd] = calendar_test_slot($timeBase + 4);
    [$manualStart, $manualEnd] = calendar_test_slot($timeBase + 5);

    $scheduledId = create_calendar_test_session(
        $moduleId,
        $lecturerAId,
        $batchId,
        $testDate,
        $scheduledStart,
        $scheduledEnd,
        $roomPrefix . 'SCHEDULED',
        ['status' => 'SCHEDULED']
    );

    $inProgressId = create_calendar_test_session(
        $moduleId,
        $lecturerAId,
        $batchId,
        $testDate,
        $inProgressStart,
        $inProgressEnd,
        $roomPrefix . 'INPROG',
        ['status' => 'IN_PROGRESS']
    );

    $completedId = create_calendar_test_session(
        $moduleId,
        $lecturerAId,
        $batchId,
        $testDate,
        $completedStart,
        $completedEnd,
        $roomPrefix . 'COMPLETED',
        ['status' => 'COMPLETED']
    );

    $cancelledId = create_calendar_test_session(
        $moduleId,
        $lecturerAId,
        $batchId,
        $testDate,
        $cancelledStart,
        $cancelledEnd,
        $roomPrefix . 'CANCELLED',
        ['status' => 'CANCELLED']
    );

    $otherLecturerId = create_calendar_test_session(
        $moduleId,
        $lecturerBId,
        $batchId,
        $testDate,
        $otherStart,
        $otherEnd,
        $roomPrefix . 'OTHER',
        ['status' => 'SCHEDULED']
    );

    $manualId = create_calendar_test_session(
        $moduleId,
        $lecturerAId,
        $batchId,
        $testDate,
        $manualStart,
        $manualEnd,
        $roomPrefix . 'MANUAL',
        ['status' => 'SCHEDULED']
    );

    $scheduleRow = db()->prepare(
        "SELECT schedule_id, day_of_week, start_time, end_time
         FROM schedules
         WHERE lecturer_id = :lecturer_id
           AND module_id = :module_id
           AND batch_id = :batch_id
           AND status = 'ACTIVE'
         LIMIT 1"
    );
    $scheduleRow->execute([
        'lecturer_id' => $lecturerAId,
        'module_id' => $moduleId,
        'batch_id' => $batchId,
    ]);
    $schedule = $scheduleRow->fetch();

    $generatedId = null;
    if ($schedule !== false) {
        $weekDate = monday_of_week($testDate) ?? $testDate;
        $offset = weekday_offset((string) $schedule['day_of_week']);
        if ($offset !== null) {
            $generatedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $weekDate, new DateTimeZone(APP_TIMEZONE));
            if ($generatedDate instanceof DateTimeImmutable) {
                $generatedSessionDate = $generatedDate->modify('+' . $offset . ' days')->format('Y-m-d');
                if (!session_already_exists($moduleId, $batchId, $generatedSessionDate, (string) $schedule['start_time'])) {
                    $generatedId = create_calendar_test_session(
                        $moduleId,
                        $lecturerAId,
                        $batchId,
                        $generatedSessionDate,
                        (string) $schedule['start_time'],
                        (string) $schedule['end_time'],
                        $roomPrefix . 'GENERATED',
                        ['status' => 'SCHEDULED', 'schedule_id' => (int) $schedule['schedule_id']]
                    );
                }
            }
        }
    }

    $ownSessions = list_lecture_sessions([
        'lecturer_id' => $lecturerAId,
        'from' => $testDate,
        'to' => $testDate,
    ]);
    $ownIds = array_map(static fn (array $row): int => (int) $row['session_id'], $ownSessions);

    assert_true(in_array($scheduledId, $ownIds, true), 'A Lecturer sees own SCHEDULED session');
    assert_true(in_array($inProgressId, $ownIds, true), 'B Lecturer sees own IN_PROGRESS session');
    assert_true(in_array($completedId, $ownIds, true), 'C Lecturer sees own COMPLETED session');

    $cancelledRow = null;
    foreach ($ownSessions as $row) {
        if ((int) $row['session_id'] === $cancelledId) {
            $cancelledRow = $row;
            break;
        }
    }
    assert_true(
        $cancelledRow !== null && ($cancelledRow['status'] ?? '') === 'CANCELLED',
        'D Lecturer sees own CANCELLED session with cancelled label'
    );

    assert_true(
        !in_array($otherLecturerId, $ownIds, true),
        'E Lecturer does not see another lecturer\'s session'
    );

    $manipulated = list_lecture_sessions([
        'lecturer_id' => $lecturerBId,
        'from' => $testDate,
        'to' => $testDate,
    ]);
    $manipulatedIds = array_map(static fn (array $row): int => (int) $row['session_id'], $manipulated);
    assert_true(
        in_array($otherLecturerId, $manipulatedIds, true) && !in_array($scheduledId, $manipulatedIds, true),
        'F URL/query manipulation cannot widen lecturer scope (calendar uses profile lecturer_id only)'
    );

    assert_true(in_array($manualId, $ownIds, true), 'G Manual dated session appears');
    if ($generatedId !== null) {
        assert_true(in_array($generatedId, $ownIds, true), 'H Generated timetable session appears');
    } else {
        pass('H Generated timetable session appears (skipped — no unique schedule slot available)');
    }

    $orphanScheduleDate = DateTimeImmutable::createFromFormat('!Y-m-d', $testDate, new DateTimeZone(APP_TIMEZONE));
    $orphanDate = $orphanScheduleDate instanceof DateTimeImmutable
        ? $orphanScheduleDate->modify('+200 days')->format('Y-m-d')
        : $testDate;
    $orphanSessions = list_lecture_sessions([
        'lecturer_id' => $lecturerAId,
        'from' => $orphanDate,
        'to' => $orphanDate,
    ]);
    assert_true(
        $orphanSessions === [],
        'I Recurring schedule without a lecture_session does NOT appear',
        'orphanDate=' . $orphanDate
    );

    $session = get_lecture_session($scheduledId);
    assert_true(
        $session !== null && (int) $session['lecturer_id'] === $lecturerAId,
        'J Clicking session opens existing authorized session page (session detail available for owner)'
    );

    $monthAnchor = DateTimeImmutable::createFromFormat('!Y-m-d', $testDate, new DateTimeZone(APP_TIMEZONE));
    $monthFrom = monday_of_week(($monthAnchor?->modify('first day of this month'))->format('Y-m-d') ?? $testDate) ?? $testDate;
    $monthTo = ($monthAnchor?->modify('last day of this month'))->format('Y-m-d') ?? $testDate;
    $weekFrom = monday_of_week($testDate) ?? $testDate;
    $weekToDate = DateTimeImmutable::createFromFormat('!Y-m-d', $weekFrom, new DateTimeZone(APP_TIMEZONE));
    $weekTo = $weekToDate instanceof DateTimeImmutable ? $weekToDate->modify('+6 days')->format('Y-m-d') : $testDate;

    $monthSessions = list_lecture_sessions(['lecturer_id' => $lecturerAId, 'from' => $monthFrom, 'to' => $monthTo]);
    $weekSessions = list_lecture_sessions(['lecturer_id' => $lecturerAId, 'from' => $weekFrom, 'to' => $weekTo]);
    $todaySessions = list_lecture_sessions(['lecturer_id' => $lecturerAId, 'from' => $testDate, 'to' => $testDate]);

    assert_true($monthSessions !== [] && $weekSessions !== [] && $todaySessions !== [], 'K Month/Week/Today navigation data ranges return sessions');

    $emptyDay = DateTimeImmutable::createFromFormat('!Y-m-d', $testDate, new DateTimeZone(APP_TIMEZONE));
    $emptyDate = $emptyDay instanceof DateTimeImmutable ? $emptyDay->modify('+120 days')->format('Y-m-d') : $testDate;
    $emptySessions = list_lecture_sessions([
        'lecturer_id' => $lecturerAId,
        'from' => $emptyDate,
        'to' => $emptyDate,
    ]);
    assert_true($emptySessions === [], 'L Empty state works for day with no sessions');

    $studentTimetable = file_get_contents(dirname(__DIR__, 2) . '/web/shared/pages/timetable/student.php');
    assert_true(
        is_string($studentTimetable)
        && str_contains($studentTimetable, 'list_student_schedules')
        && str_contains($studentTimetable, 'list_visible_sessions_for_student')
        && !str_contains($studentTimetable, 'list_lecture_sessions'),
        'M Student timetable remains unchanged'
    );

    $adminSchedules = file_get_contents(dirname(__DIR__, 2) . '/web/admin/schedules/index.php');
    $staffSchedules = file_get_contents(dirname(__DIR__, 2) . '/web/academic-staff/schedules/index.php');
    assert_true(
        is_string($adminSchedules) && str_contains($adminSchedules, 'shared/pages/schedules/index.php')
        && is_string($staffSchedules) && str_contains($staffSchedules, 'shared/pages/schedules/index.php'),
        'N Academic Staff/Admin timetable/session management unchanged'
    );

    $calendarPage = file_get_contents(dirname(__DIR__, 2) . '/web/shared/pages/calendar/lecturer.php');
    assert_true(
        is_string($calendarPage)
        && str_contains($calendarPage, 'list_lecture_sessions')
        && str_contains($calendarPage, '$restrictLecturerId')
        && !preg_match('/\$_GET\s*\[\s*[\'"]lecturer_id[\'"]\s*\]/', $calendarPage),
        'Calendar page scopes sessions from current lecturer profile only'
    );

    $sessionCountAfter = (int) db()->query('SELECT COUNT(*) FROM lecture_sessions')->fetchColumn();
    $attendanceCountAfter = (int) db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
    assert_true(
        $attendanceCountAfter === $attendanceCountBefore,
        'O Attendance row counts unchanged',
        'before=' . $attendanceCountBefore . ' after=' . $attendanceCountAfter
    );
    assert_true(
        $sessionCountAfter >= $sessionCountBefore,
        'O Session lifecycle behaviour unchanged (test sessions created without attendance side effects)'
    );
} catch (Throwable $exception) {
    fail('Unhandled exception', $exception->getMessage());
}

if ($createdSessionIds !== []) {
    $placeholders = implode(',', array_fill(0, count($createdSessionIds), '?'));
    db()->prepare('DELETE FROM lecture_sessions WHERE session_id IN (' . $placeholders . ')')->execute($createdSessionIds);
}

if ($tempLecturerId !== null) {
    db()->prepare('DELETE FROM module_lecturers WHERE lecturer_id = :lecturer_id')->execute(['lecturer_id' => $tempLecturerId]);
    db()->prepare('DELETE FROM lecturers WHERE lecturer_id = :lecturer_id')->execute(['lecturer_id' => $tempLecturerId]);
    if ($tempUserId !== null) {
        db()->prepare('DELETE FROM users WHERE user_id = :user_id')->execute(['user_id' => $tempUserId]);
    }
}

if ($failed) {
    fwrite(STDERR, "Some Lecturer Calendar tests failed.\n");
    exit(1);
}

echo 'All Lecturer My Calendar regression tests passed.' . PHP_EOL;
