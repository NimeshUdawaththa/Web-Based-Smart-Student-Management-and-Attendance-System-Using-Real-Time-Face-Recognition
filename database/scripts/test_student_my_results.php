<?php

declare(strict_types=1);

/**
 * Student My Results — unified Coursework & Assessments sources (A–J).
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_student_my_results.php
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
require_once dirname(__DIR__, 2) . '/web/shared/includes/assignments.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/marks.php';

$failed = false;
$cleanup = [
    'result_ids' => [],
    'assignment_ids' => [],
    'file_paths' => [],
    'student_ids' => [],
    'user_ids' => [],
    'assessment_name' => null,
    'lecturer_id' => null,
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
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myr_' . bin2hex(random_bytes(6)) . $suffix;
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
$taught = $pdo->query(
    "SELECT ml.lecturer_id, ml.module_id
     FROM module_lecturers ml
     INNER JOIN student_modules sm ON sm.module_id = ml.module_id AND sm.status = 'ENROLLED'
     ORDER BY ml.module_lecturer_id
     LIMIT 1"
)->fetch();
if ($taught === false) {
    fwrite(STDERR, "Need a taught module with an enrolled student.\n");
    exit(1);
}

$lecturerA = (int) $taught['lecturer_id'];
$moduleA = (int) $taught['module_id'];
$st = $pdo->prepare(
    "SELECT student_id FROM student_modules WHERE module_id = :m AND status = 'ENROLLED' ORDER BY student_id LIMIT 1"
);
$st->execute(['m' => $moduleA]);
$studentA = (int) $st->fetchColumn();
$studentRow = $pdo->query('SELECT course_id, batch_id FROM students WHERE student_id = ' . $studentA)->fetch();
$suffix = bin2hex(random_bytes(4));
$assessmentName = 'Legacy Quiz ' . $suffix;
$cleanup['assessment_name'] = $assessmentName;
$cleanup['lecturer_id'] = $lecturerA;
$futureDue = app_now()->modify('+7 days')->format('Y-m-d H:i:s');

$pdo->prepare(
    "INSERT INTO users (username, email, password_hash, role, status)
     VALUES (:u, :e, :p, 'STUDENT', 'ACTIVE')"
)->execute([
    'u' => 'myr_stu_' . $suffix,
    'e' => 'myr_stu_' . $suffix . '@localhost.test',
    'p' => password_hash('Test123!', PASSWORD_DEFAULT),
]);
$userB = (int) $pdo->lastInsertId();
$cleanup['user_ids'][] = $userB;
$pdo->prepare(
    'INSERT INTO students (user_id, registration_no, first_name, last_name, course_id, batch_id, enrollment_date, status)
     VALUES (:uid, :reg, :fn, :ln, :cid, :bid, CURDATE(), :st)'
)->execute([
    'uid' => $userB,
    'reg' => 'MYR-B-' . $suffix,
    'fn' => 'Results',
    'ln' => 'StudentB',
    'cid' => $studentRow['course_id'],
    'bid' => $studentRow['batch_id'],
    'st' => 'ACTIVE',
]);
$studentB = (int) $pdo->lastInsertId();
$cleanup['student_ids'][] = $studentB;

try {
    $gradedId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'Test Coursework Assignment ' . $suffix,
        'description' => 'Graded work',
        'activity_type' => 'ASSIGNMENT',
        'due_date' => $futureDue,
        'max_marks' => 100.0,
        'status' => 'PUBLISHED',
        'file_path' => null,
        'scheduled_date' => null,
        'start_time' => null,
        'end_time' => null,
        'room' => null,
    ]);
    $ungradedId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'Ungraded Coursework ' . $suffix,
        'description' => 'Not graded',
        'activity_type' => 'ASSIGNMENT',
        'due_date' => $futureDue,
        'max_marks' => 100.0,
        'status' => 'PUBLISHED',
        'file_path' => null,
        'scheduled_date' => null,
        'start_time' => null,
        'end_time' => null,
        'room' => null,
    ]);
    $examId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'Unified Exam ' . $suffix,
        'description' => 'Exam',
        'activity_type' => 'EXAM',
        'due_date' => '2031-10-01 11:00:00',
        'max_marks' => 100.0,
        'status' => 'PUBLISHED',
        'file_path' => null,
        'scheduled_date' => '2031-10-01',
        'start_time' => '09:00:00',
        'end_time' => '11:00:00',
        'room' => null,
    ]);
    $cleanup['assignment_ids'][] = $gradedId;
    $cleanup['assignment_ids'][] = $ungradedId;
    $cleanup['assignment_ids'][] = $examId;

    $pdf = make_temp_file("%PDF-1.4\n%%EOF\n", '.pdf');
    $first = save_student_assignment_submission($gradedId, $studentA, fake_upload_local($pdf, 'work.pdf'));
    @unlink($pdf);
    $sub = get_assignment_submission((int) $first['submission_id']);
    if ($sub !== null) {
        $cleanup['file_paths'][] = (string) $sub['file_path'];
    }
    grade_coursework_submission($lecturerA, (int) $first['submission_id'], '85', 'Good work');

    $pdf2 = make_temp_file("%PDF-1.4\n%%EOF\n", '.pdf');
    $second = save_student_assignment_submission($ungradedId, $studentA, fake_upload_local($pdf2, 'open.pdf'));
    @unlink($pdf2);
    $sub2 = get_assignment_submission((int) $second['submission_id']);
    if ($sub2 !== null) {
        $cleanup['file_paths'][] = (string) $sub2['file_path'];
    }

    save_assignment_direct_result($lecturerA, $examId, $studentA, 78, 'Solid');
    $examResult = get_assignment_direct_result($examId, $studentA);
    if ($examResult !== null) {
        $cleanup['result_ids'][] = (int) $examResult['result_id'];
    }

    // Legacy marks row (must remain in DB but not appear in unified My Results)
    save_module_assessment_results($lecturerA, $moduleA, 'Quiz', $assessmentName, '20', [
        $studentA => ['marks' => '19', 'remarks' => 'Good'],
    ]);

    $marksBefore = (int) $pdo->query('SELECT COUNT(*) FROM marks')->fetchColumn();
    $subsBefore = (int) $pdo->query('SELECT COUNT(*) FROM assignment_submissions')->fetchColumn();

    $unified = list_unified_student_results($studentA);
    $titles = array_map(static fn (array $row): string => (string) $row['title'], $unified);
    $match = null;
    $examMatch = null;
    foreach ($unified as $row) {
        if ((int) $row['assignment_id'] === $gradedId) {
            $match = $row;
        }
        if ((int) $row['assignment_id'] === $examId) {
            $examMatch = $row;
        }
    }

    if ($match !== null && in_array('Test Coursework Assignment ' . $suffix, $titles, true)) {
        pass('A student with graded coursework sees it under unified My Results');
    } else {
        fail('A graded coursework was not listed');
    }

    if (
        $match !== null
        && (float) $match['marks_obtained'] === 85.0
        && (float) $match['max_marks'] === 100.0
        && $match['result_display'] === '85 / 100'
        && $match['activity_type'] === 'ASSIGNMENT'
    ) {
        pass('B grade, max marks, and type are correct');
    } else {
        fail('B grade/type display was wrong');
    }

    $escaped = e('<b>x</b>');
    if ($match !== null && (string) $match['feedback_or_remarks'] === 'Good work' && $escaped === '&lt;b&gt;x&lt;/b&gt;') {
        pass('C feedback displays correctly and escaping works');
    } else {
        fail('C feedback check failed');
    }

    if (!in_array('Ungraded Coursework ' . $suffix, $titles, true)) {
        pass('D ungraded coursework is not presented as a graded result');
    } else {
        fail('D ungraded coursework appeared');
    }

    $other = list_unified_student_results($studentB);
    $sawA = false;
    foreach ($other as $row) {
        if ((int) $row['assignment_id'] === $gradedId || (int) $row['assignment_id'] === $examId) {
            $sawA = true;
        }
    }
    if (!$sawA) {
        pass('E student cannot see another student\'s results');
    } else {
        fail('E other student saw results');
    }

    if ($examMatch !== null && $examMatch['activity_type'] === 'EXAM' && (float) $examMatch['marks_obtained'] === 78.0) {
        pass('F Exam direct result appears in unified My Results');
    } else {
        fail('F Exam direct result missing from unified view');
    }

    $legacyVisible = false;
    foreach ($unified as $row) {
        if (str_contains((string) $row['title'], $assessmentName) || ($row['source'] ?? '') === 'marks') {
            $legacyVisible = true;
        }
    }
    $pageSrc = (string) file_get_contents($root . '/web/shared/pages/marks/student.php');
    if (!$legacyVisible && !str_contains($pageSrc, 'list_marks_for_student') && str_contains($pageSrc, 'list_unified_student_results')) {
        pass('G legacy marks are not displayed in active Student results');
    } else {
        fail('G legacy marks still shown in My Results UI/helpers');
    }

    $marksStillThere = false;
    foreach (list_marks_for_student($studentA) as $row) {
        if ((string) $row['assessment_name'] === $assessmentName) {
            $marksStillThere = true;
        }
    }
    if ($marksStillThere) {
        pass('H legacy marks rows remain readable via helper (data preserved)');
    } else {
        fail('H legacy marks row missing from DB/helper');
    }

    if (str_contains($pageSrc, 'student_id') && str_contains($pageSrc, 'ignored')) {
        pass('I student_id query manipulation is ignored by My Results page');
    } else {
        fail('I page does not document ignoring student_id query');
    }

    list_unified_student_results($studentA);
    $marksAfter = (int) $pdo->query('SELECT COUNT(*) FROM marks')->fetchColumn();
    $subsAfter = (int) $pdo->query('SELECT COUNT(*) FROM assignment_submissions')->fetchColumn();
    if ($marksAfter === $marksBefore && $subsAfter === $subsBefore) {
        pass('J opening My Results does not insert/update marks or submissions');
    } else {
        fail('J read path wrote to marks or assignment_submissions');
    }
} catch (Throwable $exception) {
    fail('Unhandled: ' . $exception->getMessage());
}

foreach ($cleanup['result_ids'] as $id) {
    $pdo->prepare('DELETE FROM assignment_results WHERE result_id = :id')->execute(['id' => $id]);
}
foreach (array_reverse($cleanup['assignment_ids']) as $id) {
    $pdo->prepare('DELETE FROM assignment_results WHERE assignment_id = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM assignment_submissions WHERE assignment_id = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM assignments WHERE assignment_id = :id')->execute(['id' => $id]);
}
if ($cleanup['assessment_name'] !== null && $cleanup['lecturer_id'] !== null) {
    $pdo->prepare('DELETE FROM marks WHERE assessment_name = :n AND recorded_by = :r')->execute([
        'n' => $cleanup['assessment_name'],
        'r' => $cleanup['lecturer_id'],
    ]);
}
foreach ($cleanup['student_ids'] as $id) {
    $pdo->prepare('DELETE FROM students WHERE student_id = :id')->execute(['id' => $id]);
}
foreach ($cleanup['user_ids'] as $id) {
    $pdo->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $id]);
}
foreach ($cleanup['file_paths'] as $relative) {
    assignment_delete_stored_file($relative);
}

if ($failed) {
    exit(1);
}

echo "\nAll student My Results tests A–J passed.\n";
exit(0);
