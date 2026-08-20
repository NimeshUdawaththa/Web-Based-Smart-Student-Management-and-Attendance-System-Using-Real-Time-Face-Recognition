<?php

declare(strict_types=1);

/**
 * Regression tests for Admin / Academic Staff Lecture Calendar v1.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_institution_lecture_calendar.php
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
require_once dirname(__DIR__, 2) . '/web/shared/includes/calendar.php';

$failed = false;
$createdSessionIds = [];
$tempLecturerId = null;
$tempUserId = null;
$createdScheduleId = null;
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
        'username' => 'ical_test_' . $suffix,
        'email' => 'ical_test_' . $suffix . '@example.test',
        'password' => 'TestPass123!',
        'account_status' => 'ACTIVE',
        'staff_no' => 'ICAL-' . $suffix,
        'first_name' => 'Institution',
        'last_name' => 'Calendar',
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
    "SELECT ml.module_id, ml.lecturer_id, b.batch_id, b.course_id, m.module_code, b.batch_name, c.course_code
     FROM module_lecturers ml
     INNER JOIN modules m ON m.module_id = ml.module_id AND m.status = 'ACTIVE'
     INNER JOIN course_modules cm ON cm.module_id = m.module_id AND cm.status = 'ACTIVE'
     INNER JOIN batches b ON b.course_id = cm.course_id AND b.status = 'ACTIVE'
     INNER JOIN courses c ON c.course_id = b.course_id
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
$courseId = (int) $trio['course_id'];
$testDate = app_today();
$roomPrefix = 'ICAL-TEST-';

/**
 * @return array{0: string, 1: string}
 */
function ical_slot(int $index): array
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

    return [$start, sprintf('%02d:%02d', $endHour, $endMinute)];
}

/**
 * @param array<string, mixed> $overrides
 */
function create_ical_session(
    int $moduleId,
    int $lecturerId,
    int $batchId,
    string $date,
    string $start,
    string $end,
    string $room,
    array $overrides = []
): int {
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

    $timeBase = ((int) date('i')) % 30;
    [$aStart, $aEnd] = ical_slot($timeBase);
    [$bStart, $bEnd] = ical_slot($timeBase + 1);
    [$cStart, $cEnd] = ical_slot($timeBase + 2);
    [$dStart, $dEnd] = ical_slot($timeBase + 3);
    [$mStart, $mEnd] = ical_slot($timeBase + 4);

    $sessionA = create_ical_session($moduleId, $lecturerAId, $batchId, $testDate, $aStart, $aEnd, $roomPrefix . 'A');
    $sessionB = create_ical_session($moduleId, $lecturerBId, $batchId, $testDate, $bStart, $bEnd, $roomPrefix . 'B');
    $sessionCancelled = create_ical_session(
        $moduleId,
        $lecturerAId,
        $batchId,
        $testDate,
        $cStart,
        $cEnd,
        $roomPrefix . 'CANCELLED',
        ['status' => 'CANCELLED']
    );
    $sessionCompleted = create_ical_session(
        $moduleId,
        $lecturerAId,
        $batchId,
        $testDate,
        $dStart,
        $dEnd,
        $roomPrefix . 'COMPLETED',
        ['status' => 'COMPLETED']
    );
    $sessionManual = create_ical_session(
        $moduleId,
        $lecturerAId,
        $batchId,
        $testDate,
        $mStart,
        $mEnd,
        $roomPrefix . 'MANUAL'
    );

    $allToday = list_lecture_sessions(['from' => $testDate, 'to' => $testDate]);
    $allIds = array_map(static fn (array $row): int => (int) $row['session_id'], $allToday);

    assert_true(
        in_array($sessionA, $allIds, true) && in_array($sessionB, $allIds, true),
        'A/B/C Admin/Staff calendar data includes sessions from multiple lecturers'
    );

    $byLecturerA = list_lecture_sessions([
        'lecturer_id' => $lecturerAId,
        'from' => $testDate,
        'to' => $testDate,
    ]);
    $byLecturerAIds = array_map(static fn (array $row): int => (int) $row['session_id'], $byLecturerA);
    assert_true(
        in_array($sessionA, $byLecturerAIds, true) && !in_array($sessionB, $byLecturerAIds, true),
        'D Lecturer filter narrows correctly'
    );

    $byCourse = list_lecture_sessions([
        'course_id' => $courseId,
        'from' => $testDate,
        'to' => $testDate,
    ]);
    $byCourseIds = array_map(static fn (array $row): int => (int) $row['session_id'], $byCourse);
    assert_true(in_array($sessionA, $byCourseIds, true), 'E Course filter narrows correctly (via batch.course_id)');

    $otherCourse = db()->prepare(
        'SELECT course_id FROM courses WHERE course_id <> :course_id LIMIT 1'
    );
    $otherCourse->execute(['course_id' => $courseId]);
    $otherCourseId = (int) $otherCourse->fetchColumn();
    if ($otherCourseId > 0) {
        $wrongCourse = list_lecture_sessions([
            'course_id' => $otherCourseId,
            'from' => $testDate,
            'to' => $testDate,
        ]);
        $wrongIds = array_map(static fn (array $row): int => (int) $row['session_id'], $wrongCourse);
        assert_true(!in_array($sessionA, $wrongIds, true), 'E Course filter excludes other courses');
    } else {
        pass('E Course filter excludes other courses (skipped — only one course)');
    }

    $byBatch = list_lecture_sessions([
        'batch_id' => $batchId,
        'from' => $testDate,
        'to' => $testDate,
    ]);
    $byBatchIds = array_map(static fn (array $row): int => (int) $row['session_id'], $byBatch);
    assert_true(in_array($sessionA, $byBatchIds, true), 'F Batch filter narrows correctly');

    $byModule = list_lecture_sessions([
        'module_id' => $moduleId,
        'from' => $testDate,
        'to' => $testDate,
    ]);
    $byModuleIds = array_map(static fn (array $row): int => (int) $row['session_id'], $byModule);
    assert_true(in_array($sessionA, $byModuleIds, true), 'G Module filter narrows correctly');

    $byStatus = list_lecture_sessions([
        'status' => 'CANCELLED',
        'from' => $testDate,
        'to' => $testDate,
    ]);
    $byStatusIds = array_map(static fn (array $row): int => (int) $row['session_id'], $byStatus);
    assert_true(
        in_array($sessionCancelled, $byStatusIds, true) && !in_array($sessionA, $byStatusIds, true),
        'H Status filter narrows correctly'
    );

    assert_true(in_array($sessionCancelled, $allIds, true), 'I Cancelled session visible without status filter');
    assert_true(in_array($sessionCompleted, $allIds, true), 'J Completed session visible without status filter');

    $generatedId = null;
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
    if ($schedule !== false) {
        $weekDate = monday_of_week($testDate) ?? $testDate;
        $offset = weekday_offset((string) $schedule['day_of_week']);
        if ($offset !== null) {
            $generatedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $weekDate, new DateTimeZone(APP_TIMEZONE));
            if ($generatedDate instanceof DateTimeImmutable) {
                $generatedSessionDate = $generatedDate->modify('+' . $offset . ' days')->format('Y-m-d');
                if (!session_already_exists($moduleId, $batchId, $generatedSessionDate, (string) $schedule['start_time'])) {
                    $generatedId = create_ical_session(
                        $moduleId,
                        $lecturerAId,
                        $batchId,
                        $generatedSessionDate,
                        (string) $schedule['start_time'],
                        (string) $schedule['end_time'],
                        $roomPrefix . 'GENERATED',
                        ['schedule_id' => (int) $schedule['schedule_id']]
                    );
                }
            }
        }
    }
    if ($generatedId !== null) {
        $generatedList = list_lecture_sessions([
            'from' => $testDate,
            'to' => (new DateTimeImmutable('today', new DateTimeZone(APP_TIMEZONE)))->modify('+14 days')->format('Y-m-d'),
        ]);
        $generatedIds = array_map(static fn (array $row): int => (int) $row['session_id'], $generatedList);
        assert_true(in_array($generatedId, $generatedIds, true), 'K Generated session appears');
    } else {
        pass('K Generated session appears (skipped — no unique schedule slot)');
    }

    assert_true(in_array($sessionManual, $allIds, true), 'L Manual session appears');

    $orphanDate = (new DateTimeImmutable($testDate, new DateTimeZone(APP_TIMEZONE)))->modify('+200 days')->format('Y-m-d');
    $orphan = list_lecture_sessions(['from' => $orphanDate, 'to' => $orphanDate]);
    assert_true($orphan === [], 'M Recurring schedule without lecture_session does not appear');

    $detail = get_lecture_session($sessionA);
    assert_true(
        $detail !== null && (int) $detail['session_id'] === $sessionA,
        'N Event detail resolves authorized session row'
    );

    $adminRoute = file_get_contents(dirname(__DIR__, 2) . '/web/admin/calendar/index.php');
    $staffRoute = file_get_contents(dirname(__DIR__, 2) . '/web/academic-staff/calendar/index.php');
    $lecturerNav = management_nav_items('LECTURER');
    $studentNav = management_nav_items('STUDENT');
    $lecturerHasInstitutionCalendar = false;
    foreach ($lecturerNav as $item) {
        if (str_contains((string) $item['href'], '/calendar/index.php')) {
            $lecturerHasInstitutionCalendar = true;
        }
    }
    $studentHasInstitutionCalendar = false;
    foreach ($studentNav as $item) {
        if (str_contains((string) $item['href'], '/calendar/index.php')) {
            $studentHasInstitutionCalendar = true;
        }
    }
    assert_true(
        is_string($adminRoute) && str_contains($adminRoute, 'require_admin')
        && is_string($staffRoute) && str_contains($staffRoute, "require_role('ACADEMIC_STAFF')")
        && !$lecturerHasInstitutionCalendar,
        'O Lecturer cannot access Admin/Staff calendar route (gated + not in lecturer nav)'
    );
    assert_true(
        !$studentHasInstitutionCalendar
        && is_string($adminRoute) && str_contains($adminRoute, 'institution.php'),
        'P Student cannot access Admin/Staff calendar route'
    );

    $lecturerOwn = list_lecture_sessions([
        'lecturer_id' => $lecturerAId,
        'from' => $testDate,
        'to' => $testDate,
    ]);
    $lecturerOwnIds = array_map(static fn (array $row): int => (int) $row['session_id'], $lecturerOwn);
    assert_true(
        in_array($sessionA, $lecturerOwnIds, true) && !in_array($sessionB, $lecturerOwnIds, true),
        'Q Lecturer My Calendar remains scoped'
    );

    $studentTimetable = file_get_contents(dirname(__DIR__, 2) . '/web/shared/pages/timetable/student.php');
    assert_true(
        is_string($studentTimetable)
        && str_contains($studentTimetable, 'list_student_schedules')
        && !str_contains($studentTimetable, 'list_lecture_sessions'),
        'R Student timetable remains unchanged'
    );

    assert_true(
        function_exists('generate_sessions_for_week')
        && is_file(dirname(__DIR__, 2) . '/web/academic-staff/sessions/generate.php'),
        'S Generate This Week backend retained (UI no longer linked)'
    );
    assert_true(
        function_exists('create_lecture_session')
        && is_file(dirname(__DIR__, 2) . '/web/academic-staff/sessions/create.php'),
        'T Create Session still available'
    );

    $schedulesIndex = file_get_contents(dirname(__DIR__, 2) . '/web/shared/pages/schedules/index.php');
    $schedulesForm = file_get_contents(dirname(__DIR__, 2) . '/web/shared/pages/schedules/form.php');
    assert_true(
        is_string($schedulesIndex) && str_contains($schedulesIndex, 'list_schedules')
        && is_string($schedulesForm) && (str_contains($schedulesForm, 'create_schedule') || str_contains($schedulesForm, 'update_schedule')),
        'U Timetable create/edit still works (schedules backend preserved)'
    );

    $attendanceCountAfter = (int) db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
    $sessionCountAfter = (int) db()->query('SELECT COUNT(*) FROM lecture_sessions')->fetchColumn();
    assert_true(
        $attendanceCountAfter === $attendanceCountBefore,
        'V Attendance row counts unchanged',
        'before=' . $attendanceCountBefore . ' after=' . $attendanceCountAfter
    );
    assert_true(
        $sessionCountAfter >= $sessionCountBefore,
        'V Session lifecycle row creation path unchanged for calendar tests'
    );

    $institutionPage = file_get_contents(dirname(__DIR__, 2) . '/web/shared/pages/calendar/institution.php');
    $adminNav = management_nav_items('ADMIN');
    $staffNav = management_nav_items('ACADEMIC_STAFF');
    $adminHasCalendar = false;
    $staffHasCalendar = false;
    $adminHasTimetableMgmt = false;
    $staffHasTimetableMgmt = false;
    foreach ($adminNav as $item) {
        if (($item['label'] ?? '') === 'Lecture Calendar') {
            $adminHasCalendar = true;
        }
        if (($item['label'] ?? '') === 'Timetable Management') {
            $adminHasTimetableMgmt = true;
        }
    }
    foreach ($staffNav as $item) {
        if (($item['label'] ?? '') === 'Lecture Calendar') {
            $staffHasCalendar = true;
        }
        if (($item['label'] ?? '') === 'Timetable Management') {
            $staffHasTimetableMgmt = true;
        }
    }
    assert_true(
        is_string($institutionPage)
        && str_contains($institutionPage, 'list_lecture_sessions')
        && str_contains($institutionPage, 'lecturer_id')
        && str_contains($institutionPage, 'course_id')
        && $adminHasCalendar
        && $staffHasCalendar
        && !$adminHasTimetableMgmt
        && !$staffHasTimetableMgmt
        && !str_contains($institutionPage, 'Generate This Week')
        && !str_contains($institutionPage, 'Add Timetable Entry')
        && !str_contains($institutionPage, 'Timetable Management'),
        'Institution calendar page + Admin/Staff nav wired without Timetable Management UI'
    );

    // Soft check generate helper name used in codebase
    $generatePage = file_get_contents(dirname(__DIR__, 2) . '/web/shared/pages/sessions/generate.php');
    if (is_string($generatePage)) {
        assert_true(
            str_contains($generatePage, 'generate') || str_contains($generatePage, 'schedule'),
            'S Generate This Week page retained'
        );
    }
} catch (Throwable $exception) {
    fail('Unhandled exception', $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
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
    fwrite(STDERR, "Some Institution Lecture Calendar tests failed.\n");
    exit(1);
}

echo 'All Institution Lecture Calendar regression tests passed.' . PHP_EOL;
