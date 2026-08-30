<?php

declare(strict_types=1);

/**
 * Regression tests for Student Timetable Simplification v1.
 *
 * Confirms My Timetable uses lecture_sessions only (Today + Upcoming),
 * and no longer renders legacy Weekly Timetable / schedules data.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_student_timetable_simplification.php
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
$root = dirname(__DIR__, 2);
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

try {
    $pagePath = $root . '/web/shared/pages/timetable/student.php';
    $page = (string) file_get_contents($pagePath);

    assert_true(is_file($pagePath), 'A Student My Timetable page exists');

    assert_true(
        str_contains($page, "Today's Lectures")
        && str_contains($page, 'Upcoming Lectures')
        && str_contains($page, 'list_visible_sessions_for_student')
        && str_contains($page, "'from' => \$today")
        && str_contains($page, "'to' => \$today")
        && str_contains($page, "'upcoming' => true"),
        'B Today + Upcoming still use list_visible_sessions_for_student'
    );

    assert_true(
        !str_contains($page, 'Weekly Timetable')
        && !str_contains($page, 'list_student_schedules')
        && !str_contains($page, '$schedules'),
        'C Weekly Timetable section and schedule call removed'
    );

    assert_true(
        str_contains($page, 'No lectures today.')
        && str_contains($page, 'No upcoming lectures.'),
        'D Empty states for Today/Upcoming retained'
    );

    assert_true(
        !str_contains($page, 'list_lecture_sessions('),
        'E Page does not bypass eligibility via raw list_lecture_sessions'
    );

    $helperSource = (string) file_get_contents($root . '/web/shared/includes/academic.php');
    $fnPos = strpos($helperSource, 'function list_visible_sessions_for_student');
    $fnSlice = $fnPos === false ? '' : substr($helperSource, $fnPos, 1200);
    assert_true(
        str_contains($fnSlice, "'batch_id' => (int) \$student['batch_id']")
        && str_contains($fnSlice, 'get_student_module_enrolment')
        && str_contains($fnSlice, "!== 'ENROLLED'")
        && str_contains($fnSlice, "'CANCELLED'"),
        'F list_visible_sessions_for_student eligibility filters unchanged'
    );

    assert_true(
        function_exists('list_student_schedules'),
        'G Legacy list_student_schedules helper retained globally'
    );

    $schedulesTable = db()->query("SHOW TABLES LIKE 'schedules'")->fetchColumn();
    assert_true($schedulesTable === 'schedules', 'H schedules table still exists');

    $scheduleCountAfter = (int) db()->query('SELECT COUNT(*) FROM schedules')->fetchColumn();
    assert_true(
        $scheduleCountAfter === $scheduleCountBefore,
        'I Existing schedule rows unchanged',
        'before=' . $scheduleCountBefore . ' after=' . $scheduleCountAfter
    );

    assert_true(
        is_file($root . '/web/admin/schedules/index.php')
        && is_file($root . '/web/academic-staff/schedules/index.php')
        && is_file($root . '/web/shared/pages/schedules/index.php'),
        'J Legacy Admin/Staff schedule routes retained on disk'
    );

    assert_true(
        is_file($root . '/web/shared/pages/calendar/institution.php')
        && is_file($root . '/web/shared/pages/calendar/lecturer.php'),
        'K Lecture Calendar pages unchanged (files present)'
    );

    $attendanceCountAfter = (int) db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
    assert_true(
        $attendanceCountAfter === $attendanceCountBefore,
        'L Attendance row counts unchanged',
        'before=' . $attendanceCountBefore . ' after=' . $attendanceCountAfter
    );

    $dashboard = (string) file_get_contents($root . '/web/student/dashboard.php');
    assert_true(
        !str_contains($dashboard, 'Weekly slots')
        && str_contains($dashboard, 'Today\'s and upcoming lectures'),
        'M Student dashboard My Timetable copy no longer mentions weekly slots'
    );

    // Runtime smoke: callable helpers for an existing enrolled student if present.
    $studentRow = db()->query(
        "SELECT s.student_id
         FROM students s
         INNER JOIN student_modules sm ON sm.student_id = s.student_id AND sm.status = 'ENROLLED'
         WHERE s.status = 'ACTIVE'
         LIMIT 1"
    )->fetch();

    if ($studentRow !== false) {
        $studentId = (int) $studentRow['student_id'];
        $today = app_today();
        $todaySessions = list_visible_sessions_for_student($studentId, [
            'from' => $today,
            'to' => $today,
        ]);
        $upcomingSessions = list_visible_sessions_for_student($studentId, [
            'upcoming' => true,
        ]);
        assert_true(
            is_array($todaySessions) && is_array($upcomingSessions),
            'N Runtime list_visible_sessions_for_student returns arrays for enrolled student'
        );

        $widened = false;
        foreach (array_merge($todaySessions, $upcomingSessions) as $session) {
            if (($session['status'] ?? '') === 'CANCELLED') {
                $widened = true;
                break;
            }
            $enrolment = get_student_module_enrolment($studentId, (int) $session['module_id']);
            if ($enrolment === null || ($enrolment['status'] ?? '') !== 'ENROLLED') {
                $widened = true;
                break;
            }
            $student = get_student($studentId);
            if ($student !== null && (int) $session['batch_id'] !== (int) $student['batch_id']) {
                $widened = true;
                break;
            }
        }
        assert_true(!$widened, 'O Returned sessions stay within eligibility rules');
    } else {
        pass('N/O Runtime eligibility smoke skipped (no enrolled ACTIVE student in DB)');
    }

    $lintOutput = [];
    $lintCode = 0;
    exec('php -l ' . escapeshellarg($pagePath) . ' 2>&1', $lintOutput, $lintCode);
    assert_true($lintCode === 0, 'P Student timetable page has no PHP syntax errors', implode("\n", $lintOutput));
} catch (Throwable $e) {
    fail('Unhandled exception', $e->getMessage());
}

echo PHP_EOL . ($failed ? 'RESULT: FAILED' : 'RESULT: PASSED') . PHP_EOL;
exit($failed ? 1 : 0);
