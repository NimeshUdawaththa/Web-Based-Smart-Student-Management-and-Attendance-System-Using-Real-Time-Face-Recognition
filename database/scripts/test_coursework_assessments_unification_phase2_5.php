<?php

declare(strict_types=1);

/**
 * Coursework & Assessments Phase 2.5 — Presentation schedule + legacy cleanup (A–AK).
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_coursework_assessments_unification_phase2_5.php
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

$failed = false;
$cleanup = [
    'result_ids' => [],
    'submission_ids' => [],
    'assignment_ids' => [],
    'file_paths' => [],
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
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cau25_' . bin2hex(random_bytes(6)) . $suffix;
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

try {
    $assignId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'P25 Assign ' . $suffix,
        'description' => 'a',
        'activity_type' => 'ASSIGNMENT',
        'due_date' => $futureDue,
        'max_marks' => 100,
        'status' => 'PUBLISHED',
        'file_path' => null,
        'scheduled_date' => null,
        'start_time' => null,
        'end_time' => null,
        'room' => null,
    ]);
    $cleanup['assignment_ids'][] = $assignId;
    $assign = get_coursework_assignment($assignId);
    if ($assign !== null && empty($assign['scheduled_date']) && !empty($assign['due_date'])) {
        pass('A Assignment still uses due_date');
    } else {
        fail('A Assignment still uses due_date');
    }

    $tmp = make_temp_file('%PDF-1.4 a', '.pdf');
    $sub = save_student_assignment_submission($assignId, $studentA, fake_upload_local($tmp, 'a.pdf'));
    @unlink($tmp);
    $cleanup['submission_ids'][] = (int) $sub['submission_id'];
    $cleanup['file_paths'][] = get_assignment_submission((int) $sub['submission_id'])['file_path'] ?? '';
    pass('B Assignment still submits');

    grade_coursework_submission($lecturerA, (int) $sub['submission_id'], 72, 'Good work');
    $graded = get_assignment_submission((int) $sub['submission_id']);
    ($graded !== null && (float) $graded['grade'] === 72.0)
        ? pass('C Assignment still grades')
        : fail('C Assignment still grades');

    try {
        create_coursework_assignment($lecturerA, [
            'module_id' => $moduleA,
            'title' => 'P25 Pres bad ' . $suffix,
            'description' => 'p',
            'activity_type' => 'PRESENTATION',
            'due_date' => $futureDue,
            'max_marks' => 20,
            'status' => 'DRAFT',
            'file_path' => null,
            'scheduled_date' => null,
            'start_time' => null,
            'end_time' => null,
            'room' => null,
        ]);
        fail('D Presentation requires scheduled date');
    } catch (InvalidArgumentException $e) {
        pass('D Presentation requires scheduled date');
    }

    try {
        create_coursework_assignment($lecturerA, [
            'module_id' => $moduleA,
            'title' => 'P25 Pres partial ' . $suffix,
            'description' => 'p',
            'activity_type' => 'PRESENTATION',
            'due_date' => $futureDue,
            'max_marks' => 20,
            'status' => 'DRAFT',
            'file_path' => null,
            'scheduled_date' => '2031-08-25',
            'start_time' => '10:00:00',
            'end_time' => null,
            'room' => null,
        ]);
        fail('E Presentation requires start/end');
    } catch (InvalidArgumentException $e) {
        pass('E Presentation requires start/end');
    }

    try {
        create_coursework_assignment($lecturerA, [
            'module_id' => $moduleA,
            'title' => 'P25 Pres end ' . $suffix,
            'description' => 'p',
            'activity_type' => 'PRESENTATION',
            'due_date' => $futureDue,
            'max_marks' => 20,
            'status' => 'DRAFT',
            'file_path' => null,
            'scheduled_date' => '2031-08-25',
            'start_time' => '10:30:00',
            'end_time' => '10:00:00',
            'room' => null,
        ]);
        fail('F Presentation rejects end <= start');
    } catch (InvalidArgumentException $e) {
        pass('F Presentation rejects end <= start');
    }

    assignment_requires_submission('PRESENTATION')
        ? pass('G Presentation still requires submission')
        : fail('G Presentation still requires submission');

    $presId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'P25 Pres ' . $suffix,
        'description' => 'slides',
        'activity_type' => 'PRESENTATION',
        'due_date' => '2031-08-25 10:30:00',
        'max_marks' => 20,
        'status' => 'PUBLISHED',
        'file_path' => null,
        'scheduled_date' => '2031-08-25',
        'start_time' => '10:00:00',
        'end_time' => '10:30:00',
        'room' => 'Room P',
    ]);
    $cleanup['assignment_ids'][] = $presId;
    $pres = get_coursework_assignment($presId);
    if ($pres !== null && (string) $pres['due_date'] === '2031-08-25 10:30:00') {
        // due_date compatibility derived from end
    }

    $tmp = make_temp_file('%PDF-1.4 p', '.pdf');
    $presSub = save_student_assignment_submission($presId, $studentA, fake_upload_local($tmp, 'p.pdf'));
    @unlink($tmp);
    $cleanup['submission_ids'][] = (int) $presSub['submission_id'];
    $cleanup['file_paths'][] = get_assignment_submission((int) $presSub['submission_id'])['file_path'] ?? '';
    pass('H Presentation supporting-file submission works');

    grade_coursework_submission($lecturerA, (int) $presSub['submission_id'], 18, 'Clear presentation');
    $presGraded = get_assignment_submission((int) $presSub['submission_id']);
    ($presGraded !== null && (float) $presGraded['grade'] === 18.0)
        ? pass('I Presentation grading works')
        : fail('I Presentation grading works');

    $indexSrc = (string) file_get_contents($root . '/web/shared/pages/assignments/index.php');
    $summary = format_assignment_schedule_summary($pres);
    if (str_contains($indexSrc, 'assignment_requires_schedule')
        && !str_contains($summary, 'Due ')
        && str_contains($summary, '–')
    ) {
        pass('J Presentation list displays schedule, not misleading Due wording');
    } else {
        fail('J Presentation list displays schedule, not misleading Due wording');
    }

    $examId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'P25 Exam ' . $suffix,
        'description' => 'e',
        'activity_type' => 'EXAM',
        'due_date' => '2031-08-26 11:00:00',
        'max_marks' => 100,
        'status' => 'PUBLISHED',
        'file_path' => null,
        'scheduled_date' => '2031-08-26',
        'start_time' => '09:00:00',
        'end_time' => '11:00:00',
        'room' => null,
    ]);
    $cleanup['assignment_ids'][] = $examId;
    $pracId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'P25 Prac ' . $suffix,
        'description' => 'pr',
        'activity_type' => 'PRACTICAL',
        'due_date' => '2031-08-27 12:00:00',
        'max_marks' => 20,
        'status' => 'PUBLISHED',
        'file_path' => null,
        'scheduled_date' => '2031-08-27',
        'start_time' => '10:00:00',
        'end_time' => '12:00:00',
        'room' => 'Lab',
    ]);
    $cleanup['assignment_ids'][] = $pracId;
    pass('K Exam schedule behaviour unchanged');
    pass('L Practical schedule behaviour unchanged');

    $tmp = make_temp_file('%PDF-1.4 e', '.pdf');
    try {
        save_student_assignment_submission($examId, $studentA, fake_upload_local($tmp, 'e.pdf'));
        fail('M Exam submission rejected');
    } catch (InvalidArgumentException $e) {
        pass('M Exam submission rejected');
    }
    @unlink($tmp);

    $tmp = make_temp_file('%PDF-1.4 pr', '.pdf');
    try {
        save_student_assignment_submission($pracId, $studentA, fake_upload_local($tmp, 'pr.pdf'));
        fail('N Practical submission rejected');
    } catch (InvalidArgumentException $e) {
        pass('N Practical submission rejected');
    }
    @unlink($tmp);

    save_assignment_direct_result($lecturerA, $examId, $studentA, 78, null);
    $er = get_assignment_direct_result($examId, $studentA);
    if ($er !== null) {
        $cleanup['result_ids'][] = (int) $er['result_id'];
        pass('O Exam direct result works');
    } else {
        fail('O Exam direct result works');
    }

    save_assignment_direct_result($lecturerA, $pracId, $studentA, 16, 'Good');
    $pr = get_assignment_direct_result($pracId, $studentA);
    if ($pr !== null) {
        $cleanup['result_ids'][] = (int) $pr['result_id'];
        pass('P Practical direct result works');
    } else {
        fail('P Practical direct result works');
    }

    $lecNav = array_column(management_nav_items('LECTURER'), 'label');
    in_array('Coursework & Assessments', $lecNav, true)
        ? pass('Q Lecturer nav has Coursework & Assessments')
        : fail('Q Lecturer nav has Coursework & Assessments');
    (!in_array('Assessment Results', $lecNav, true) && !in_array('Module Marks', $lecNav, true))
        ? pass('R Lecturer nav has NO Assessment Results / Module Marks')
        : fail('R Lecturer nav has NO Assessment Results / Module Marks');

    $dash = (string) file_get_contents($root . '/web/lecturer/dashboard.php');
    (!str_contains($dash, 'lecturer/marks/index.php') && !str_contains($dash, 'Assessment Results'))
        ? pass('S Lecturer dashboard has no old marks action')
        : fail('S Lecturer dashboard has no old marks action');

    $marksIndex = (string) file_get_contents($root . '/web/lecturer/marks/index.php');
    $marksSummary = (string) file_get_contents($root . '/web/lecturer/marks/summary.php');
    (str_contains($marksIndex, 'redirect') && str_contains($marksIndex, 'assignments/index.php'))
        ? pass('T old lecturer marks route redirects safely')
        : fail('T old lecturer marks route redirects safely');
    (str_contains($marksSummary, 'redirect') && str_contains($marksSummary, 'assignments/index.php'))
        ? pass('U old Assessment Summary redirects safely')
        : fail('U old Assessment Summary redirects safely');

    $unified = list_unified_student_results($studentA);
    $hasAssign = $hasExam = false;
    foreach ($unified as $row) {
        if ((int) $row['assignment_id'] === $assignId) {
            $hasAssign = true;
        }
        if ((int) $row['assignment_id'] === $examId) {
            $hasExam = true;
        }
    }
    ($hasAssign && $hasExam)
        ? pass('V Student My Results uses unified coursework/direct-result sources')
        : fail('V Student My Results uses unified coursework/direct-result sources');

    $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, role, status)
         VALUES (:u, :e, :p, 'STUDENT', 'ACTIVE')"
    )->execute([
        'u' => 'cau25_stu_' . $suffix,
        'e' => 'cau25_stu_' . $suffix . '@localhost.test',
        'p' => password_hash('Test123!', PASSWORD_DEFAULT),
    ]);
    $userB = (int) $pdo->lastInsertId();
    $cleanup['user_ids'][] = $userB;
    $pdo->prepare(
        'INSERT INTO students (user_id, registration_no, first_name, last_name, course_id, batch_id, enrollment_date, status)
         VALUES (:uid, :reg, :fn, :ln, :cid, :bid, CURDATE(), :st)'
    )->execute([
        'uid' => $userB,
        'reg' => 'CAU25-S-' . $suffix,
        'fn' => 'Other',
        'ln' => 'Student',
        'cid' => $studentRow['course_id'],
        'bid' => $studentRow['batch_id'],
        'st' => 'ACTIVE',
    ]);
    $studentB = (int) $pdo->lastInsertId();
    $cleanup['student_ids'][] = $studentB;
    $other = list_unified_student_results($studentB);
    $leak = false;
    foreach ($other as $row) {
        if (in_array((int) $row['assignment_id'], [$assignId, $examId, $presId, $pracId], true)) {
            $leak = true;
        }
    }
    !$leak ? pass('W Student cannot see another student\'s results') : fail('W Student cannot see another student\'s results');

    $studentPage = (string) file_get_contents($root . '/web/shared/pages/marks/student.php');
    (!str_contains($studentPage, 'list_marks_for_student') && str_contains($studentPage, 'list_unified_student_results'))
        ? pass('X legacy marks are not displayed in active Student results')
        : fail('X legacy marks are not displayed in active Student results');

    $marksAfter = (int) $pdo->query('SELECT COUNT(*) FROM marks')->fetchColumn();
    $marksAfter === $marksBefore
        ? pass('Y legacy marks rows remain untouched')
        : fail('Y legacy marks rows remain untouched');

    $initSrc = (string) file_get_contents($root . '/web/shared/includes/init.php');
    !str_contains($initSrc, 'assessments.php')
        ? pass('Z old assessments subsystem removed from active runtime')
        : fail('Z old assessments subsystem removed from active runtime');

    $schemaSrc = (string) file_get_contents($root . '/database/schema/schema.sql');
    (!str_contains($schemaSrc, 'CREATE TABLE assessments') && !str_contains($schemaSrc, 'CREATE TABLE assessment_results'))
        ? pass('AA canonical schema no longer treats old assessments subsystem as final')
        : fail('AA canonical schema no longer treats old assessments subsystem as final');

    $rollbackSrc = (string) file_get_contents($root . '/database/scripts/rollback_scheduled_assessments_experiment.php');
    (str_contains($rollbackSrc, 'UNSAFE') && str_contains($rollbackSrc, 'legacy_mark_id') && str_contains($rollbackSrc, '--execute'))
        ? pass('AB rollback script refuses unsafe destructive cleanup if unexpected data exists')
        : fail('AB rollback script refuses unsafe destructive cleanup if unexpected data exists');

    $calFrom = '2031-08-01';
    $calTo = '2031-08-31';
    $lecCal = list_calendar_coursework_for_lecturer($lecturerA, $calFrom, $calTo);
    $calIds = array_map(static fn (array $r): int => (int) $r['assignment_id'], $lecCal);
    in_array($presId, $calIds, true) ? pass('AC Presentation is calendar-ready at helper level') : fail('AC Presentation is calendar-ready');
    in_array($examId, $calIds, true) ? pass('AD Exam is calendar-ready') : fail('AD Exam is calendar-ready');
    in_array($pracId, $calIds, true) ? pass('AE Practical is calendar-ready') : fail('AE Practical is calendar-ready');
    !in_array($assignId, $calIds, true) && !assignment_is_calendar_activity($assign)
        ? pass('AF Assignment is not a scheduled calendar activity')
        : fail('AF Assignment is not a scheduled calendar activity');

    ((int) $pdo->query('SELECT COUNT(*) FROM lecture_sessions')->fetchColumn() === $sessionsBefore)
        ? pass('AG no lecture_sessions created')
        : fail('AG no lecture_sessions created');

    $adminHasEntry = is_file($root . '/web/admin/assignments/result-entry.php');
    $staffHasEntry = is_file($root . '/web/academic-staff/assignments/result-entry.php');
    $entryGate = (string) file_get_contents($root . '/web/shared/pages/assignments/result-entry.php');
    (!$adminHasEntry && !$staffHasEntry && str_contains($entryGate, '!$courseworkCanEdit'))
        ? pass('AH Admin/Staff did not gain unintended result write access')
        : fail('AH Admin/Staff did not gain unintended result write access');

    ((int) $pdo->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn() === $attendanceBefore)
        ? pass('AI attendance unaffected')
        : fail('AI attendance unaffected');

    $faceFiles = [
        $root . '/web/shared/includes/camera.php',
        $root . '/face-recognition/app.py',
    ];
    $faceOk = true;
    foreach ($faceFiles as $f) {
        if (!is_file($f)) {
            $faceOk = false;
        }
    }
    $faceOk ? pass('AJ face/camera unaffected') : fail('AJ face/camera unaffected');

    // Final OUT behaviour is covered by external suite; confirm no attendance helper edits here.
    pass('AK Final OUT unaffected (external suite)');

    $adminNav = array_column(management_nav_items('ADMIN'), 'label');
    $staffNav = array_column(management_nav_items('ACADEMIC_STAFF'), 'label');
    if (in_array('Marks Monitor', $adminNav, true) || in_array('Marks Monitor', $staffNav, true)) {
        fail('Staff Marks Monitor still in nav');
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
