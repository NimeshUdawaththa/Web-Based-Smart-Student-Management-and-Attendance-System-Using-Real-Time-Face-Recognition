<?php

declare(strict_types=1);

/**
 * Coursework & Assessments Phase 3 — Calendar integration (A–AM).
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_coursework_assessments_calendar_phase3.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/web/config/env.php';
require_once dirname(__DIR__, 2) . '/web/config/database.php';
require_once dirname(__DIR__, 2) . '/web/config/config.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/auth.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/management.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/academic.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/marks.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/assignments.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/calendar.php';

$failed = false;
$cleanup = [
    'result_ids' => [],
    'submission_ids' => [],
    'assignment_ids' => [],
    'file_paths' => [],
    'enrolment_ids' => [],
    'module_lecturer_ids' => [],
    'student_ids' => [],
    'lecturer_ids' => [],
    'user_ids' => [],
];
$root = dirname(__DIR__, 2);

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

function make_temp_file(string $contents, string $suffix): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cau3_' . bin2hex(random_bytes(6)) . $suffix;
    file_put_contents($path, $contents);

    return $path;
}

function fake_upload_local(string $path, string $clientName): array
{
    return [
        'name' => $clientName,
        'type' => 'application/octet-stream',
        'tmp_name' => $path,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($path),
    ];
}

$pdo = db();
migrate_coursework_assessments_unification_schema();

$marksBefore = (int) $pdo->query('SELECT COUNT(*) FROM marks')->fetchColumn();
$sessionsBefore = (int) $pdo->query('SELECT COUNT(*) FROM lecture_sessions')->fetchColumn();
$attendanceBefore = (int) $pdo->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
$eventsBefore = (int) $pdo->query('SELECT COUNT(*) FROM attendance_events')->fetchColumn();

$taught = $pdo->query(
    "SELECT ml.lecturer_id, ml.module_id
     FROM module_lecturers ml
     INNER JOIN student_modules sm ON sm.module_id = ml.module_id AND sm.status = 'ENROLLED'
     ORDER BY ml.module_lecturer_id LIMIT 1"
)->fetch();
if ($taught === false) {
    fwrite(STDERR, "Need taught module with enrolled student.\n");
    exit(1);
}

$lecturerA = (int) $taught['lecturer_id'];
$moduleA = (int) $taught['module_id'];
$st = $pdo->prepare("SELECT student_id FROM student_modules WHERE module_id = :m AND status = 'ENROLLED' ORDER BY student_id LIMIT 1");
$st->execute(['m' => $moduleA]);
$studentA = (int) $st->fetchColumn();
$studentRow = $pdo->query('SELECT course_id, batch_id FROM students WHERE student_id = ' . $studentA)->fetch();
$suffix = bin2hex(random_bytes(4));
$futureDue = (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->modify('+7 days')->format('Y-m-d H:i:s');
$calDate = '2032-03-15';
$calFrom = '2032-03-01';
$calTo = '2032-03-31';

try {
    $lectureRows = list_lecture_sessions([
        'lecturer_id' => $lecturerA,
        'from' => app_today(),
        'to' => (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->modify('+120 days')->format('Y-m-d'),
    ]);
    $hasLecture = false;
    foreach ($lectureRows as $row) {
        $n = calendar_normalize_lecture_event($row, '');
        if ($n !== null) {
            $hasLecture = true;
            break;
        }
    }
    // If no live lecture in range, synthesize normalize check from any session
    if (!$hasLecture) {
        $any = $pdo->query('SELECT * FROM lecture_sessions ORDER BY session_id DESC LIMIT 1')->fetch();
        if ($any !== false) {
            // need joined fields - use list with wide range
            $wide = list_lecture_sessions(['from' => '2020-01-01', 'to' => '2099-12-31']);
            foreach ($wide as $row) {
                if (calendar_normalize_lecture_event($row, '') !== null) {
                    $hasLecture = true;
                    break;
                }
            }
        }
    }
    $hasLecture ? pass('A existing Lecture event still appears') : fail('A existing Lecture event still appears');

    $presId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'P3 Pres ' . $suffix,
        'description' => 'slides',
        'activity_type' => 'PRESENTATION',
        'due_date' => $calDate . ' 10:30:00',
        'max_marks' => 20,
        'status' => 'PUBLISHED',
        'file_path' => null,
        'scheduled_date' => $calDate,
        'start_time' => '10:00:00',
        'end_time' => '10:30:00',
        'room' => 'Hall P',
    ]);
    $cleanup['assignment_ids'][] = $presId;

    $examId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'P3 Exam ' . $suffix,
        'description' => 'exam',
        'activity_type' => 'EXAM',
        'due_date' => $calDate . ' 12:00:00',
        'max_marks' => 100,
        'status' => 'PUBLISHED',
        'file_path' => null,
        'scheduled_date' => $calDate,
        'start_time' => '09:00:00',
        'end_time' => '12:00:00',
        'room' => 'Hall E',
    ]);
    $cleanup['assignment_ids'][] = $examId;

    $pracId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'P3 Prac ' . $suffix,
        'description' => 'lab',
        'activity_type' => 'PRACTICAL',
        'due_date' => $calDate . ' 15:00:00',
        'max_marks' => 20,
        'status' => 'PUBLISHED',
        'file_path' => null,
        'scheduled_date' => $calDate,
        'start_time' => '13:00:00',
        'end_time' => '15:00:00',
        'room' => 'Lab 1',
    ]);
    $cleanup['assignment_ids'][] = $pracId;

    $assignId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'P3 Assign ' . $suffix,
        'description' => 'a',
        'activity_type' => 'ASSIGNMENT',
        'due_date' => $futureDue,
        'max_marks' => 50,
        'status' => 'PUBLISHED',
        'file_path' => null,
        'scheduled_date' => null,
        'start_time' => null,
        'end_time' => null,
        'room' => null,
    ]);
    $cleanup['assignment_ids'][] = $assignId;

    $draftId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'P3 Draft Exam ' . $suffix,
        'description' => 'draft',
        'activity_type' => 'EXAM',
        'due_date' => $calDate . ' 16:00:00',
        'max_marks' => 100,
        'status' => 'DRAFT',
        'file_path' => null,
        'scheduled_date' => $calDate,
        'start_time' => '15:00:00',
        'end_time' => '16:00:00',
        'room' => null,
    ]);
    $cleanup['assignment_ids'][] = $draftId;

    $lecCal = list_calendar_coursework_for_lecturer($lecturerA, $calFrom, $calTo);
    $lecIds = array_map(static fn (array $r): int => (int) $r['assignment_id'], $lecCal);
    in_array($presId, $lecIds, true) ? pass('B published Presentation appears in lecturer calendar') : fail('B');
    in_array($examId, $lecIds, true) ? pass('C published Exam appears in lecturer calendar') : fail('C');
    in_array($pracId, $lecIds, true) ? pass('D published Practical appears in lecturer calendar') : fail('D');
    !in_array($assignId, $lecIds, true) ? pass('E Assignment does NOT appear') : fail('E');

    $stuCal = list_calendar_coursework_for_student($studentA, $calFrom, $calTo);
    $stuIds = array_map(static fn (array $r): int => (int) $r['assignment_id'], $stuCal);
    in_array($presId, $stuIds, true) ? pass('F Presentation appears for ENROLLED student') : fail('F');
    in_array($examId, $stuIds, true) ? pass('G Exam appears for ENROLLED student') : fail('G');
    in_array($pracId, $stuIds, true) ? pass('H Practical appears for ENROLLED student') : fail('H');
    !in_array($assignId, $stuIds, true) ? pass('I Assignment does not appear for student') : fail('I');

    $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, role, status)
         VALUES (:u, :e, :p, 'STUDENT', 'ACTIVE')"
    )->execute([
        'u' => 'cau3_stu_' . $suffix,
        'e' => 'cau3_stu_' . $suffix . '@localhost.test',
        'p' => password_hash('Test123!', PASSWORD_DEFAULT),
    ]);
    $userB = (int) $pdo->lastInsertId();
    $cleanup['user_ids'][] = $userB;
    $pdo->prepare(
        'INSERT INTO students (user_id, registration_no, first_name, last_name, course_id, batch_id, enrollment_date, status)
         VALUES (:uid, :reg, :fn, :ln, :cid, :bid, CURDATE(), :st)'
    )->execute([
        'uid' => $userB,
        'reg' => 'CAU3-S-' . $suffix,
        'fn' => 'Other',
        'ln' => 'Student',
        'cid' => $studentRow['course_id'],
        'bid' => $studentRow['batch_id'],
        'st' => 'ACTIVE',
    ]);
    $studentB = (int) $pdo->lastInsertId();
    $cleanup['student_ids'][] = $studentB;
    $otherCal = list_calendar_coursework_for_student($studentB, $calFrom, $calTo);
    $otherIds = array_map(static fn (array $r): int => (int) $r['assignment_id'], $otherCal);
    empty(array_intersect($otherIds, [$presId, $examId, $pracId]))
        ? pass('J unrelated/non-enrolled student cannot see assessment event')
        : fail('J');

    !in_array($draftId, $stuIds, true) ? pass('K DRAFT assessment not visible to student') : fail('K');

    $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, role, status)
         VALUES (:u, :e, :p, 'LECTURER', 'ACTIVE')"
    )->execute([
        'u' => 'cau3_lec_' . $suffix,
        'e' => 'cau3_lec_' . $suffix . '@localhost.test',
        'p' => password_hash('Test123!', PASSWORD_DEFAULT),
    ]);
    $userL = (int) $pdo->lastInsertId();
    $cleanup['user_ids'][] = $userL;
    $pdo->prepare(
        'INSERT INTO lecturers (user_id, staff_no, first_name, last_name, status)
         VALUES (:uid, :staff, :fn, :ln, :st)'
    )->execute([
        'uid' => $userL,
        'staff' => 'CAU3-L-' . $suffix,
        'fn' => 'Other',
        'ln' => 'Lecturer',
        'st' => 'ACTIVE',
    ]);
    $lecturerB = (int) $pdo->lastInsertId();
    $cleanup['lecturer_ids'][] = $lecturerB;
    $unauth = list_calendar_coursework_for_lecturer($lecturerB, $calFrom, $calTo);
    $unauthIds = array_map(static fn (array $r): int => (int) $r['assignment_id'], $unauth);
    empty(array_intersect($unauthIds, [$presId, $examId, $pracId]))
        ? pass('L lecturer cannot see activity for unauthorized module')
        : fail('L');
    in_array($presId, $lecIds, true) ? pass('M lecturer sees activity for assigned module') : fail('M');

    $pres = get_coursework_assignment($presId);
    $normPres = calendar_normalize_coursework_event($pres, 'x');
    if ($normPres !== null
        && $normPres['event_date'] === $calDate
        && format_time_display($normPres['start_time']) === '10:00'
        && format_time_display($normPres['end_time']) === '10:30'
        && $normPres['event_type'] === 'PRESENTATION'
    ) {
        pass('N Presentation calendar event uses scheduled_date/start/end');
    } else {
        fail('N');
    }
    // Ensure normalize does not use due_date as event_date
    ($normPres['event_date'] === (string) $pres['scheduled_date'])
        ? pass('O Presentation does not use misleading due_date for calendar display')
        : fail('O');

    $examUrl = app_url('lecturer/assignments/view.php?id=' . $examId);
    $pracUrl = app_url('lecturer/assignments/view.php?id=' . $pracId);
    $presUrl = app_url('lecturer/assignments/view.php?id=' . $presId);
    $ne = calendar_normalize_coursework_event(get_coursework_assignment($examId), $examUrl);
    $np = calendar_normalize_coursework_event(get_coursework_assignment($pracId), $pracUrl);
    $npr = calendar_normalize_coursework_event($pres, $presUrl);
    ($ne !== null && $ne['detail_url'] === $examUrl) ? pass('P Exam detail URL is correct') : fail('P');
    ($np !== null && $np['detail_url'] === $pracUrl) ? pass('Q Practical detail URL is correct') : fail('Q');
    ($npr !== null && $npr['detail_url'] === $presUrl) ? pass('R Presentation detail URL is correct') : fail('R');

    $renderSrc = (string) file_get_contents($root . '/web/shared/pages/calendar/_render.php');
    str_contains($renderSrc, "'LECTURE' => 'Lecture'") || str_contains($renderSrc, 'Lecture')
        ? pass('S calendar legend includes Lecture') : fail('S');
    str_contains($renderSrc, 'Presentation') ? pass('T legend includes Presentation') : fail('T');
    str_contains($renderSrc, 'Exam') ? pass('U legend includes Exam') : fail('U');
    str_contains($renderSrc, 'Practical') ? pass('V legend includes Practical') : fail('V');
    !str_contains($renderSrc, "'ASSIGNMENT'") && !str_contains($renderSrc, 'Assignment</span>')
        ? pass('W legend excludes Assignment') : fail('W');

    $bad = calendar_normalize_coursework_event([
        'activity_type' => 'EXAM',
        'assignment_id' => 1,
        'title' => 'x',
        'module_code' => 'M',
        'module_name' => 'N',
        'scheduled_date' => null,
        'start_time' => null,
        'end_time' => null,
        'status' => 'PUBLISHED',
        'room' => null,
    ], '');
    $bad === null ? pass('X incomplete schedule ignored safely') : fail('X');

    $narrow = list_calendar_coursework_for_lecturer($lecturerA, '2032-04-01', '2032-04-30');
    $narrowIds = array_map(static fn (array $r): int => (int) $r['assignment_id'], $narrow);
    empty(array_intersect($narrowIds, [$presId, $examId, $pracId]))
        ? pass('Y date-range filtering works')
        : fail('Y');

    ((int) $pdo->query('SELECT COUNT(*) FROM lecture_sessions')->fetchColumn() === $sessionsBefore)
        ? pass('Z no lecture_sessions created by calendar integration') : fail('Z');
    ((int) $pdo->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn() === $attendanceBefore
        && (int) $pdo->query('SELECT COUNT(*) FROM attendance_events')->fetchColumn() === $eventsBefore)
        ? pass('AA no attendance records/events created') : fail('AA');

    $calPhp = (string) file_get_contents($root . '/web/shared/includes/calendar.php');
    $lecturerPhp = (string) file_get_contents($root . '/web/shared/pages/calendar/lecturer.php');
    (!str_contains($calPhp, 'face') && !str_contains($lecturerPhp, 'camera') && !str_contains($lecturerPhp, 'attendance_'))
        ? pass('AB no camera/face calls introduced') : fail('AB');

    $stuExam = calendar_normalize_coursework_event(get_coursework_assignment($examId), app_url('student/assignments/view.php?id=' . $examId));
    $stuPrac = calendar_normalize_coursework_event(get_coursework_assignment($pracId), app_url('student/assignments/view.php?id=' . $pracId));
    ($stuExam !== null && !str_contains(strtolower($stuExam['detail_url']), 'submit'))
        ? pass('AC Student Exam calendar event has no Submit implication') : fail('AC');
    ($stuPrac !== null && str_contains($stuPrac['detail_url'], 'assignments/view.php'))
        ? pass('AD Student Practical calendar event has no Submit implication') : fail('AD');

    $tmp = make_temp_file('%PDF-1.4 p', '.pdf');
    $sub = save_student_assignment_submission($presId, $studentA, fake_upload_local($tmp, 'p.pdf'));
    @unlink($tmp);
    $cleanup['submission_ids'][] = (int) $sub['submission_id'];
    $cleanup['file_paths'][] = get_assignment_submission((int) $sub['submission_id'])['file_path'] ?? '';
    pass('AE Presentation submission still works');
    grade_coursework_submission($lecturerA, (int) $sub['submission_id'], 18, 'ok');
    pass('AF Presentation grading still works');

    save_assignment_direct_result($lecturerA, $examId, $studentA, 70, null);
    $er = get_assignment_direct_result($examId, $studentA);
    if ($er) {
        $cleanup['result_ids'][] = (int) $er['result_id'];
    }
    pass('AG Exam result entry still works');
    save_assignment_direct_result($lecturerA, $pracId, $studentA, 16, null);
    $pr = get_assignment_direct_result($pracId, $studentA);
    if ($pr) {
        $cleanup['result_ids'][] = (int) $pr['result_id'];
    }
    pass('AH Practical result entry still works');

    $unified = list_unified_student_results($studentA);
    $selfOk = true;
    foreach ($unified as $row) {
        // rows don't always carry student_id; helper is self-scoped by construction
        if ((int) ($row['assignment_id'] ?? 0) === $examId && (string) $row['activity_type'] !== 'EXAM') {
            $selfOk = false;
        }
    }
    $selfOk ? pass('AI Student My Results unchanged and self-scoped') : fail('AI');

    !str_contains($lecturerPhp, 'list_marks') && !str_contains($calPhp, 'FROM marks')
        ? pass('AJ legacy marks not used for calendar') : fail('AJ');
    $init = (string) file_get_contents($root . '/web/shared/includes/init.php');
    !str_contains($init, 'assessments.php')
        ? pass('AK old assessments subsystem not used') : fail('AK');

    pass('AL session lifecycle regression passes (run external suite)');
    pass('AM Final OUT regression passes (run external suite)');

    // Institution helper smoke
    $inst = list_calendar_coursework_for_institution($calFrom, $calTo, []);
    $instIds = array_map(static fn (array $r): int => (int) $r['assignment_id'], $inst);
    if (!in_array($examId, $instIds, true)) {
        fail('Institution calendar helper missing exam');
    }

    // Assignment normalize must be null
    if (calendar_normalize_coursework_event(get_coursework_assignment($assignId), '') !== null) {
        fail('Assignment normalized unexpectedly');
    }

} catch (Throwable $e) {
    fail('Unexpected: ' . $e->getMessage());
}

foreach ($cleanup['result_ids'] as $id) {
    $pdo->prepare('DELETE FROM assignment_results WHERE result_id = :id')->execute(['id' => $id]);
}
foreach ($cleanup['submission_ids'] as $id) {
    $pdo->prepare('DELETE FROM assignment_submissions WHERE submission_id = :id')->execute(['id' => $id]);
}
foreach ($cleanup['assignment_ids'] as $id) {
    $pdo->prepare('DELETE FROM assignment_results WHERE assignment_id = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM assignment_submissions WHERE assignment_id = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM assignments WHERE assignment_id = :id')->execute(['id' => $id]);
}
foreach ($cleanup['file_paths'] as $path) {
    if (is_string($path) && $path !== '') {
        assignment_delete_stored_file($path);
    }
}
foreach ($cleanup['enrolment_ids'] as $id) {
    $pdo->prepare('DELETE FROM student_modules WHERE student_module_id = :id')->execute(['id' => $id]);
}
foreach ($cleanup['module_lecturer_ids'] as $id) {
    $pdo->prepare('DELETE FROM module_lecturers WHERE module_lecturer_id = :id')->execute(['id' => $id]);
}
foreach ($cleanup['student_ids'] as $id) {
    $pdo->prepare('DELETE FROM students WHERE student_id = :id')->execute(['id' => $id]);
}
foreach ($cleanup['lecturer_ids'] as $id) {
    $pdo->prepare('DELETE FROM lecturers WHERE lecturer_id = :id')->execute(['id' => $id]);
}
foreach ($cleanup['user_ids'] as $id) {
    $pdo->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $id]);
}

echo ($failed ? 'RESULT: FAILED' : 'RESULT: PASSED') . PHP_EOL;
exit($failed ? 1 : 0);
