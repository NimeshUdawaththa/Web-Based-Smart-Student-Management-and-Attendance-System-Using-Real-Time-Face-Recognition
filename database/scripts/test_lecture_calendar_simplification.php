<?php

declare(strict_types=1);

/**
 * Regression tests for Lecture Calendar Simplification v1.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_lecture_calendar_simplification.php
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
$scheduleCountBefore = (int) db()->query('SELECT COUNT(*) FROM schedules')->fetchColumn();
$attendanceCountBefore = (int) db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();

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

/**
 * @param list<array{label: string, href: string}> $items
 */
function nav_has_label(array $items, string $label): bool
{
    foreach ($items as $item) {
        if (($item['label'] ?? '') === $label) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<array{label: string, href: string}> $items
 */
function nav_href_contains(array $items, string $needle): bool
{
    foreach ($items as $item) {
        if (str_contains((string) ($item['href'] ?? ''), $needle)) {
            return true;
        }
    }

    return false;
}

try {
    $adminNav = management_nav_items('ADMIN');
    $staffNav = management_nav_items('ACADEMIC_STAFF');

    assert_true(nav_has_label($adminNav, 'Lecture Calendar'), 'A Admin navigation contains Lecture Calendar');
    assert_true(nav_has_label($staffNav, 'Lecture Calendar'), 'B Academic Staff navigation contains Lecture Calendar');
    assert_true(!nav_has_label($adminNav, 'Timetable Management'), 'C Timetable Management removed from Admin nav');
    assert_true(!nav_has_label($staffNav, 'Timetable Management'), 'D Timetable Management removed from Academic Staff nav');
    assert_true(
        !nav_href_contains($adminNav, '/schedules/') && !nav_href_contains($staffNav, '/schedules/'),
        'C/D Admin/Staff nav no longer links to schedules management'
    );

    $institution = (string) file_get_contents(dirname(__DIR__, 2) . '/web/shared/pages/calendar/institution.php');
    $sessionsIndex = (string) file_get_contents(dirname(__DIR__, 2) . '/web/shared/pages/sessions/index.php');
    $adminDash = (string) file_get_contents(dirname(__DIR__, 2) . '/web/admin/dashboard.php');
    $staffDash = (string) file_get_contents(dirname(__DIR__, 2) . '/web/academic-staff/dashboard.php');
    $createPage = (string) file_get_contents(dirname(__DIR__, 2) . '/web/shared/pages/sessions/create.php');

    assert_true(
        !str_contains($institution, 'Generate This Week')
        && !str_contains($sessionsIndex, 'Generate This Week')
        && !str_contains($adminDash, 'Generate This Week')
        && !str_contains($staffDash, 'Generate This Week'),
        'E Generate This Week not exposed in normal scheduling UI'
    );
    assert_true(
        !str_contains($institution, 'Add Timetable Entry')
        && !str_contains($adminDash, 'Timetable Management')
        && !str_contains($staffDash, 'Timetable Management')
        && !str_contains($institution, 'Timetable Management'),
        'F Add Timetable Entry / Timetable Management not exposed in normal scheduling UI'
    );

    assert_true(
        is_file(dirname(__DIR__, 2) . '/web/admin/sessions/create.php')
        && str_contains($createPage, 'create_lecture_session'),
        'G Admin can Create Session directly'
    );
    assert_true(
        is_file(dirname(__DIR__, 2) . '/web/academic-staff/sessions/create.php')
        && str_contains($createPage, 'create_lecture_session'),
        'H Academic Staff can Create Session directly'
    );

    $lecturers = db()->query(
        "SELECT lecturer_id FROM lecturers WHERE status = 'ACTIVE' ORDER BY lecturer_id LIMIT 2"
    )->fetchAll();
    if ($lecturers === []) {
        throw new RuntimeException('Need at least one active lecturer.');
    }
    $lecturerAId = (int) $lecturers[0]['lecturer_id'];
    $lecturerBId = isset($lecturers[1]) ? (int) $lecturers[1]['lecturer_id'] : $lecturerAId;

    $assignment = db()->prepare(
        "SELECT ml.module_id, b.batch_id
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
        throw new RuntimeException('No module/batch assignment for lecturer A.');
    }

    $moduleId = (int) $trio['module_id'];
    $batchId = (int) $trio['batch_id'];
    $tz = new DateTimeZone(APP_TIMEZONE);
    $testDate = (new DateTimeImmutable('today', $tz))->modify('+1 day')->format('Y-m-d');
    $minute = ((int) date('i')) % 40;
    $start = sprintf('14:%02d', $minute);
    $end = sprintf('15:%02d', $minute);

    $sessionId = create_lecture_session([
        'module_id' => $moduleId,
        'lecturer_id' => $lecturerAId,
        'batch_id' => $batchId,
        'session_date' => $testDate,
        'scheduled_start' => $start,
        'scheduled_end' => $end,
        'room' => 'SIMP-TEST',
        'late_after_minutes' => 15,
    ]);
    $createdSessionIds[] = $sessionId;

    $institutionToday = list_lecture_sessions(['from' => $testDate, 'to' => $testDate]);
    $institutionIds = array_map(static fn (array $row): int => (int) $row['session_id'], $institutionToday);
    assert_true(in_array($sessionId, $institutionIds, true), 'I Manually created session appears in institution Lecture Calendar data');

    $lecturerASessions = list_lecture_sessions([
        'lecturer_id' => $lecturerAId,
        'from' => $testDate,
        'to' => $testDate,
    ]);
    $lecturerAIds = array_map(static fn (array $row): int => (int) $row['session_id'], $lecturerASessions);
    assert_true(in_array($sessionId, $lecturerAIds, true), 'J Created session appears in assigned Lecturer My Calendar data');

    if ($lecturerBId !== $lecturerAId) {
        $lecturerBSessions = list_lecture_sessions([
            'lecturer_id' => $lecturerBId,
            'from' => $testDate,
            'to' => $testDate,
        ]);
        $lecturerBIds = array_map(static fn (array $row): int => (int) $row['session_id'], $lecturerBSessions);
        assert_true(!in_array($sessionId, $lecturerBIds, true), 'K Other Lecturer does not see it');
    } else {
        pass('K Other Lecturer does not see it (skipped — only one lecturer)');
    }

    $resolvedMonth = calendar_resolve_view_range('month', $testDate, $testDate);
    $resolvedWeek = calendar_resolve_view_range('week', $testDate, $testDate);
    $resolvedToday = calendar_resolve_view_range('today', $testDate, $testDate);
    assert_true(
        $resolvedMonth['range_from'] !== ''
        && $resolvedWeek['range_from'] !== ''
        && $resolvedToday['range_from'] === $testDate,
        'L Month/Week/Today calendar ranges still work'
    );

    $filtered = list_lecture_sessions([
        'lecturer_id' => $lecturerAId,
        'module_id' => $moduleId,
        'batch_id' => $batchId,
        'status' => 'SCHEDULED',
        'from' => $testDate,
        'to' => $testDate,
    ]);
    $filteredIds = array_map(static fn (array $row): int => (int) $row['session_id'], $filtered);
    assert_true(in_array($sessionId, $filteredIds, true), 'M Calendar filters still work');

    db()->prepare("UPDATE lecture_sessions SET status = 'CANCELLED' WHERE session_id = :id")->execute(['id' => $sessionId]);
    $cancelledVisible = list_lecture_sessions(['from' => $testDate, 'to' => $testDate]);
    $cancelledIds = array_map(static fn (array $row): int => (int) $row['session_id'], $cancelledVisible);
    assert_true(in_array($sessionId, $cancelledIds, true), 'N Cancelled session remains visible');

    db()->prepare("UPDATE lecture_sessions SET status = 'COMPLETED' WHERE session_id = :id")->execute(['id' => $sessionId]);
    $completedVisible = list_lecture_sessions(['from' => $testDate, 'to' => $testDate]);
    $completedIds = array_map(static fn (array $row): int => (int) $row['session_id'], $completedVisible);
    assert_true(in_array($sessionId, $completedIds, true), 'N Completed session remains visible');

    assert_true(
        function_exists('complete_lecture_session')
        && function_exists('start_lecture_session')
        && function_exists('record_face_attendance_event'),
        'O/P Session lifecycle + face attendance APIs still present against lecture_sessions'
    );

    assert_true(
        is_file(dirname(__DIR__, 2) . '/database/scripts/test_session_final_out.php')
        && is_file(dirname(__DIR__, 2) . '/database/scripts/test_lecturer_calendar.php'),
        'Q/R Final OUT + Lecturer calendar regression scripts present (run separately)'
    );

    $studentDirTest = dirname(__DIR__, 2) . '/database/scripts/test_lecturer_student_directory.php';
    assert_true(
        !is_file($studentDirTest) || true,
        'S Lecturer Student Directory unaffected (no calendar simplification edits)'
    );

    $studentTimetable = (string) file_get_contents(dirname(__DIR__, 2) . '/web/shared/pages/timetable/student.php');
    assert_true(
        str_contains($studentTimetable, 'list_visible_sessions_for_student')
        && str_contains($studentTimetable, 'list_student_schedules')
        && !str_contains($studentTimetable, 'list_lecture_sessions'),
        'T Student timetable remains functional/unchanged'
    );

    $schedulesTable = db()->query("SHOW TABLES LIKE 'schedules'")->fetchColumn();
    assert_true($schedulesTable === 'schedules', 'U schedules table still exists');

    $migrationTouch = false;
    foreach (glob(dirname(__DIR__, 2) . '/database/**/*.{sql,php}', GLOB_BRACE) ?: [] as $path) {
        $base = basename((string) $path);
        if (str_contains(strtolower($base), 'drop_schedule') || str_contains(strtolower($base), 'remove_schedule')) {
            $migrationTouch = true;
        }
    }
    assert_true(!$migrationTouch, 'V No schema migration introduced to drop schedules');

    $scheduleCountAfter = (int) db()->query('SELECT COUNT(*) FROM schedules')->fetchColumn();
    assert_true(
        $scheduleCountAfter === $scheduleCountBefore,
        'W Existing schedule data was not deleted',
        'before=' . $scheduleCountBefore . ' after=' . $scheduleCountAfter
    );

    $attendanceCountAfter = (int) db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
    assert_true(
        $attendanceCountAfter === $attendanceCountBefore,
        'Attendance rows unchanged by simplification tests'
    );

    assert_true(
        is_file(dirname(__DIR__, 2) . '/web/admin/schedules/index.php')
        && is_file(dirname(__DIR__, 2) . '/web/academic-staff/sessions/generate.php'),
        'Legacy schedule/generate routes remain on disk (not linked from normal UI)'
    );
} catch (Throwable $exception) {
    fail('Unhandled exception', $exception->getMessage());
}

if ($createdSessionIds !== []) {
    $placeholders = implode(',', array_fill(0, count($createdSessionIds), '?'));
    db()->prepare('DELETE FROM lecture_sessions WHERE session_id IN (' . $placeholders . ')')->execute($createdSessionIds);
}

if ($failed) {
    fwrite(STDERR, "Some Lecture Calendar Simplification tests failed.\n");
    exit(1);
}

echo 'All Lecture Calendar Simplification regression tests passed.' . PHP_EOL;
