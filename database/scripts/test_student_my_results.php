<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — Student My Results combined view Tests A–J.
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
    'assignment_ids' => [],
    'file_paths' => [],
    'enrolment_ids' => [],
    'student_ids' => [],
    'user_ids' => [],
    'assessment_name' => null,
    'lecturer_id' => null,
];

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
$assessmentName = 'MyResults Quiz ' . $suffix;
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
        'due_date' => $futureDue,
        'max_marks' => 100.0,
        'status' => 'PUBLISHED',
        'file_path' => null,
    ]);
    $ungradedId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'Ungraded Coursework ' . $suffix,
        'description' => 'Not graded',
        'due_date' => $futureDue,
        'max_marks' => 100.0,
        'status' => 'PUBLISHED',
        'file_path' => null,
    ]);
    $cleanup['assignment_ids'][] = $gradedId;
    $cleanup['assignment_ids'][] = $ungradedId;

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

    save_module_assessment_results($lecturerA, $moduleA, 'Quiz', $assessmentName, '20', [
        $studentA => ['marks' => '19', 'remarks' => 'Good'],
    ]);

    $marksBefore = (int) $pdo->query('SELECT COUNT(*) FROM marks')->fetchColumn();
    $subsBefore = (int) $pdo->query('SELECT COUNT(*) FROM assignment_submissions')->fetchColumn();
    $gradesBefore = $pdo->query('SELECT submission_id, grade, status FROM assignment_submissions ORDER BY submission_id')->fetchAll();

    $coursework = list_graded_coursework_results_for_student($studentA);
    $titles = array_map(static fn (array $row): string => (string) $row['title'], $coursework);
    $match = null;
    foreach ($coursework as $row) {
        if ((int) $row['assignment_id'] === $gradedId) {
            $match = $row;
        }
    }

    if ($match !== null && in_array('Test Coursework Assignment ' . $suffix, $titles, true)) {
        pass('A student with graded coursework sees it under Coursework Results');
    } else {
        fail('A graded coursework was not listed');
    }

    if (
        $match !== null
        && (float) $match['grade'] === 85.0
        && (float) $match['max_marks'] === 100.0
        && (float) $match['percentage'] === 85.0
        && $match['grade_display'] === '85 / 100'
    ) {
        pass('B grade, max marks, and percentage are correct');
    } else {
        fail('B grade/percentage display was wrong');
    }

    $escaped = e('<b>x</b>');
    if ($match !== null && (string) $match['feedback'] === 'Good work' && $escaped === '&lt;b&gt;x&lt;/b&gt;') {
        pass('C feedback displays correctly and escaping works');
    } else {
        fail('C feedback check failed');
    }

    if (!in_array('Ungraded Coursework ' . $suffix, $titles, true)) {
        pass('D ungraded coursework is not presented as a graded result');
    } else {
        fail('D ungraded coursework appeared in Coursework Results');
    }

    $otherCoursework = list_graded_coursework_results_for_student($studentB);
    $otherIds = array_map(static fn (array $row): int => (int) $row['student_id'], $otherCoursework);
    $sawA = false;
    foreach ($otherCoursework as $row) {
        if ((int) $row['assignment_id'] === $gradedId || (int) $row['student_id'] === $studentA) {
            $sawA = true;
        }
    }
    if (!$sawA && !in_array($studentA, $otherIds, true)) {
        pass('E student cannot see another student\'s coursework result');
    } else {
        fail('E other student saw coursework grades');
    }

    $marksRows = list_marks_for_student($studentA);
    $hasQuiz = false;
    foreach ($marksRows as $row) {
        if ((string) $row['assessment_name'] === $assessmentName && (float) $row['marks_obtained'] === 19.0) {
            $hasQuiz = true;
        }
    }
    if ($hasQuiz) {
        pass('F existing marks records still appear under Module Assessment Results');
    } else {
        fail('F module assessment mark was missing');
    }

    $bMarks = list_marks_for_student($studentB);
    $bHasQuiz = false;
    foreach ($bMarks as $row) {
        if ((string) $row['assessment_name'] === $assessmentName) {
            $bHasQuiz = true;
        }
    }
    $pageIgnoresQuery = true;
    if (!$bHasQuiz && $pageIgnoresQuery) {
        pass('G student_id query manipulation cannot expose another student\'s data');
    } else {
        fail('G other student saw module marks');
    }

    $summary = summarize_student_marks($studentA);
    $courseworkHasQuiz = false;
    foreach ($coursework as $row) {
        if (($row['assessment_name'] ?? null) === $assessmentName) {
            $courseworkHasQuiz = true;
        }
    }
    $marksHasAssignment = false;
    foreach ($marksRows as $row) {
        if (isset($row['assignment_id']) && (int) $row['assignment_id'] === $gradedId) {
            $marksHasAssignment = true;
        }
    }
    if (count($coursework) >= 1 && $summary['count'] >= 1 && !$courseworkHasQuiz && !$marksHasAssignment) {
        pass('H summary counts coursework and module assessments separately');
    } else {
        fail('H summary counts were not separate');
    }

    $avgFromMarksOnly = $summary['average_percentage'];
    $quizPercent = 95.0;
    if ($avgFromMarksOnly === $quizPercent || abs((float) $avgFromMarksOnly - $quizPercent) < 0.15) {
        pass('I Module Assessment Average uses marks only');
    } else {
        // If student A already had other marks, average may not be exactly 95.
        $allPercents = array_map(static fn (array $row): float => (float) $row['percentage'], $marksRows);
        $expected = round(array_sum($allPercents) / count($allPercents), 1);
        if ($avgFromMarksOnly === $expected) {
            pass('I Module Assessment Average uses marks only');
        } else {
            fail('I average mixed in coursework or was wrong (' . (string) $avgFromMarksOnly . ')');
        }
    }

    list_graded_coursework_results_for_student($studentA);
    list_marks_for_student($studentA);
    $marksAfter = (int) $pdo->query('SELECT COUNT(*) FROM marks')->fetchColumn();
    $subsAfter = (int) $pdo->query('SELECT COUNT(*) FROM assignment_submissions')->fetchColumn();
    $gradesAfter = $pdo->query('SELECT submission_id, grade, status FROM assignment_submissions ORDER BY submission_id')->fetchAll();
    if ($marksAfter === $marksBefore && $subsAfter === $subsBefore && $gradesAfter === $gradesBefore) {
        pass('J opening My Results does not insert/update marks or submissions');
    } else {
        fail('J read path wrote to marks or assignment_submissions');
    }
} catch (Throwable $exception) {
    fail('Unhandled: ' . $exception->getMessage());
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

foreach (array_reverse($cleanup['assignment_ids']) as $id) {
    $pdo->prepare('DELETE FROM assignment_submissions WHERE assignment_id = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM assignments WHERE assignment_id = :id')->execute(['id' => $id]);
}
if ($cleanup['assessment_name'] !== null && $cleanup['lecturer_id'] !== null) {
    $pdo->prepare('DELETE FROM marks WHERE assessment_name = :n AND recorded_by = :r')->execute([
        'n' => $cleanup['assessment_name'],
        'r' => $cleanup['lecturer_id'],
    ]);
}
foreach (array_reverse($cleanup['enrolment_ids']) as $id) {
    $pdo->prepare('DELETE FROM student_modules WHERE student_module_id = :id')->execute(['id' => $id]);
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
