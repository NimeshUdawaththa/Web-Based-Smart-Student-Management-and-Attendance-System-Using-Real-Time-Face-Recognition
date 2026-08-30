<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — coursework grading Tests A–M.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_coursework_grading.php
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

$failed = false;
$cleanup = [
    'assignment_ids' => [],
    'file_paths' => [],
    'enrolment_ids' => [],
    'student_ids' => [],
    'lecturer_ids' => [],
    'user_ids' => [],
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
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cwg_' . bin2hex(random_bytes(6)) . $suffix;
    file_put_contents($path, $contents);

    return $path;
}

function fake_upload(string $path, string $clientName): array
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
$marksCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM marks')->fetchColumn();

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
$futureDue = app_now()->modify('+7 days')->format('Y-m-d H:i:s');

$pdo->prepare(
    "INSERT INTO users (username, email, password_hash, role, status)
     VALUES (:u, :e, :p, 'LECTURER', 'ACTIVE')"
)->execute([
    'u' => 'cwg_lec_' . $suffix,
    'e' => 'cwg_lec_' . $suffix . '@localhost.test',
    'p' => password_hash('Test123!', PASSWORD_DEFAULT),
]);
$userLecB = (int) $pdo->lastInsertId();
$cleanup['user_ids'][] = $userLecB;
$pdo->prepare(
    'INSERT INTO lecturers (user_id, staff_no, first_name, last_name, status)
     VALUES (:uid, :staff, :fn, :ln, :st)'
)->execute([
    'uid' => $userLecB,
    'staff' => 'CWG-L-' . $suffix,
    'fn' => 'Grade',
    'ln' => 'LecturerB',
    'st' => 'ACTIVE',
]);
$lecturerB = (int) $pdo->lastInsertId();
$cleanup['lecturer_ids'][] = $lecturerB;

$pdo->prepare(
    "INSERT INTO users (username, email, password_hash, role, status)
     VALUES (:u, :e, :p, 'STUDENT', 'ACTIVE')"
)->execute([
    'u' => 'cwg_stu_' . $suffix,
    'e' => 'cwg_stu_' . $suffix . '@localhost.test',
    'p' => password_hash('Test123!', PASSWORD_DEFAULT),
]);
$userStuB = (int) $pdo->lastInsertId();
$cleanup['user_ids'][] = $userStuB;
$pdo->prepare(
    'INSERT INTO students (user_id, registration_no, first_name, last_name, course_id, batch_id, enrollment_date, status)
     VALUES (:uid, :reg, :fn, :ln, :cid, :bid, CURDATE(), :st)'
)->execute([
    'uid' => $userStuB,
    'reg' => 'CWG-S-' . $suffix,
    'fn' => 'Grade',
    'ln' => 'StudentB',
    'cid' => $studentRow['course_id'],
    'bid' => $studentRow['batch_id'],
    'st' => 'ACTIVE',
]);
$studentB = (int) $pdo->lastInsertId();
$cleanup['student_ids'][] = $studentB;

$pdo->prepare(
    "INSERT INTO users (username, email, password_hash, role, status)
     VALUES (:u, :e, :p, 'STUDENT', 'ACTIVE')"
)->execute([
    'u' => 'cwg_stc_' . $suffix,
    'e' => 'cwg_stc_' . $suffix . '@localhost.test',
    'p' => password_hash('Test123!', PASSWORD_DEFAULT),
]);
$userStuC = (int) $pdo->lastInsertId();
$cleanup['user_ids'][] = $userStuC;
$pdo->prepare(
    'INSERT INTO students (user_id, registration_no, first_name, last_name, course_id, batch_id, enrollment_date, status)
     VALUES (:uid, :reg, :fn, :ln, :cid, :bid, CURDATE(), :st)'
)->execute([
    'uid' => $userStuC,
    'reg' => 'CWG-C-' . $suffix,
    'fn' => 'Grade',
    'ln' => 'StudentC',
    'cid' => $studentRow['course_id'],
    'bid' => $studentRow['batch_id'],
    'st' => 'ACTIVE',
]);
$studentC = (int) $pdo->lastInsertId();
$cleanup['student_ids'][] = $studentC;
$pdo->prepare(
    "INSERT INTO student_modules (student_id, module_id, status) VALUES (:s, :m, 'ENROLLED')"
)->execute(['s' => $studentC, 'm' => $moduleA]);
$cleanup['enrolment_ids'][] = (int) $pdo->lastInsertId();

try {
    $assignmentId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'Grading Test ' . $suffix,
        'description' => 'Grade this',
        'due_date' => $futureDue,
        'max_marks' => 100.0,
        'status' => 'PUBLISHED',
        'file_path' => null,
    ]);
    $cleanup['assignment_ids'][] = $assignmentId;

    $pdf = make_temp_file("%PDF-1.4\n%%EOF\n", '.pdf');
    $first = save_student_assignment_submission($assignmentId, $studentA, fake_upload($pdf, 'work.pdf'));
    @unlink($pdf);
    $submissionId = (int) $first['submission_id'];
    $sub = get_assignment_submission($submissionId);
    if ($sub !== null) {
        $cleanup['file_paths'][] = (string) $sub['file_path'];
    }

    $graded = grade_coursework_submission($lecturerA, $submissionId, '82', 'Good analysis. Improve the conclusion.');
    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = :a AND student_id = :s'
    );
    $countStmt->execute(['a' => $assignmentId, 's' => $studentA]);
    $rowCount = (int) $countStmt->fetchColumn();
    if (
        ($graded['status'] ?? '') === 'GRADED'
        && (float) $graded['grade'] === 82.0
        && (string) $graded['feedback'] === 'Good analysis. Improve the conclusion.'
        && $rowCount === 1
        && (int) $graded['submission_id'] === $submissionId
    ) {
        pass('A lecturer grades submitted assignment to GRADED 82/100');
    } else {
        fail('A grade save did not update the existing submission row');
    }

    $assignment = get_coursework_assignment($assignmentId);
    $own = get_assignment_submission_for_student($assignmentId, $studentA);
    $display = student_coursework_display_status($assignment ?? [], $own);
    $visible = $own !== null && student_can_view_submission_result($studentA, $assignment ?? [], $own);
    if ($visible && $display === 'GRADED' && format_assignment_grade_display($own['grade'], 100) === '82 / 100') {
        pass('B student sees own 82/100 and GRADED status');
    } else {
        fail('B student grade display failed');
    }

    $regraded = grade_coursework_submission($lecturerA, $submissionId, '85', 'Updated feedback');
    $countStmt->execute(['a' => $assignmentId, 's' => $studentA]);
    if (
        (int) $regraded['submission_id'] === $submissionId
        && (float) $regraded['grade'] === 85.0
        && ($regraded['status'] ?? '') === 'GRADED'
        && (int) $countStmt->fetchColumn() === 1
    ) {
        pass('C lecturer re-grade updates the same row');
    } else {
        fail('C re-grade duplicated or failed');
    }

    $overMax = false;
    try {
        grade_coursework_submission($lecturerA, $submissionId, '100.01', 'too high');
    } catch (InvalidArgumentException) {
        $overMax = true;
    }
    if ($overMax) {
        pass('D grade greater than max marks is rejected');
    } else {
        fail('D over-max grade was accepted');
    }

    $negative = false;
    try {
        grade_coursework_submission($lecturerA, $submissionId, '-1', 'neg');
    } catch (InvalidArgumentException) {
        $negative = true;
    }
    if ($negative) {
        pass('E negative grade is rejected');
    } else {
        fail('E negative grade was accepted');
    }

    $nonNumeric = false;
    try {
        grade_coursework_submission($lecturerA, $submissionId, '82abc', 'bad');
    } catch (InvalidArgumentException) {
        $nonNumeric = true;
    }
    if ($nonNumeric) {
        pass('F non-numeric grade is rejected');
    } else {
        fail('F non-numeric grade was accepted');
    }

    $matrix = list_assignment_submission_matrix($assignmentId);
    $cRow = null;
    foreach ($matrix as $row) {
        if ((int) $row['student_id'] === $studentC) {
            $cRow = $row;
        }
    }
    $subCountC = (int) $pdo->query(
        'SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = ' . $assignmentId
        . ' AND student_id = ' . $studentC
    )->fetchColumn();
    if (
        $cRow !== null
        && $cRow['matrix_status'] === 'NOT SUBMITTED'
        && $cRow['grade_display'] === '—'
        && $cRow['submission_id'] === null
        && $subCountC === 0
    ) {
        pass('G NOT SUBMITTED has no grade action and no dummy row');
    } else {
        fail('G NOT SUBMITTED handling failed');
    }

    $otherLecturer = false;
    try {
        grade_coursework_submission($lecturerB, $submissionId, '50', 'nope');
    } catch (InvalidArgumentException) {
        $otherLecturer = true;
    }
    $after = get_assignment_submission($submissionId);
    if ($otherLecturer && $after !== null && (float) $after['grade'] === 85.0) {
        pass('H other lecturer grading is denied');
    } else {
        fail('H other lecturer was able to grade');
    }

    $studentGradePage = !is_file(WEB_PATH . '/student/assignments/grade.php')
        && !is_file(WEB_PATH . '/public/student/assignments/grade.php');
    if ($studentGradePage) {
        pass('I student grading URL/page does not exist');
    } else {
        fail('I student grading page should not exist');
    }

    $staffCanView = assignment_authorized_download('submission', $submissionId, 'ACADEMIC_STAFF', null, null) !== null;
    $staffGradeBlocked = false;
    try {
        grade_coursework_submission(0, $submissionId, '10', 'staff');
    } catch (InvalidArgumentException) {
        $staffGradeBlocked = true;
    }
    $staffPage = is_file(WEB_PATH . '/academic-staff/assignments/grade.php')
        && is_file(WEB_PATH . '/admin/assignments/grade.php');
    if ($staffCanView && $staffGradeBlocked && $staffPage) {
        pass('J staff can view result pages but cannot modify grades');
    } else {
        fail('J staff monitor grade permissions failed');
    }

    $replaceBlocked = false;
    $pdf2 = make_temp_file("%PDF-1.4\n%%EOF\n", '.pdf');
    try {
        save_student_assignment_submission($assignmentId, $studentA, fake_upload($pdf2, 'again.pdf'));
    } catch (InvalidArgumentException $exception) {
        $replaceBlocked = str_contains($exception->getMessage(), 'Graded submissions cannot be replaced');
    }
    @unlink($pdf2);
    $afterReplace = get_assignment_submission($submissionId);
    if (
        $replaceBlocked
        && !student_can_submit_coursework_assignment($studentA, $assignment ?? [])
        && $afterReplace !== null
        && ($afterReplace['status'] ?? '') === 'GRADED'
        && (float) $afterReplace['grade'] === 85.0
    ) {
        pass('K GRADED submission replacement is blocked');
    } else {
        fail('K GRADED replacement was not blocked');
    }

    $pdo->prepare('UPDATE assignment_submissions SET submitted_at = :t WHERE submission_id = :id')->execute([
        't' => app_now()->modify('+8 days')->format('Y-m-d H:i:s'),
        'id' => $submissionId,
    ]);
    $lateSub = get_assignment_submission($submissionId);
    $matrix2 = list_assignment_submission_matrix($assignmentId);
    $aRow = null;
    foreach ($matrix2 as $row) {
        if ((int) $row['student_id'] === $studentA) {
            $aRow = $row;
        }
    }
    if (
        $lateSub !== null
        && ($lateSub['status'] ?? '') === 'GRADED'
        && assignment_submission_is_late($assignment ?? [], $lateSub)
        && ($aRow['timing'] ?? '') === 'Late'
        && ($aRow['matrix_status'] ?? '') === 'GRADED'
    ) {
        pass('L On Time/Late remains correct after GRADED');
    } else {
        fail('L late timing was lost after GRADED');
    }

    $otherOwn = get_assignment_submission_for_student($assignmentId, $studentB);
    $cannotSee = $otherOwn === null
        && !student_can_view_submission_result($studentB, $assignment ?? [], $lateSub ?? []);
    if ($cannotSee) {
        pass('M other student cannot see this grade');
    } else {
        fail('M other student could access the grade');
    }
} catch (Throwable $exception) {
    fail('Unhandled: ' . $exception->getMessage());
}

foreach (array_reverse($cleanup['assignment_ids']) as $id) {
    $pdo->prepare('DELETE FROM assignment_submissions WHERE assignment_id = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM assignments WHERE assignment_id = :id')->execute(['id' => $id]);
}
foreach ($cleanup['enrolment_ids'] as $id) {
    $pdo->prepare('DELETE FROM student_modules WHERE student_module_id = :id')->execute(['id' => $id]);
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
foreach ($cleanup['file_paths'] as $relative) {
    assignment_delete_stored_file($relative);
}

$marksCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM marks')->fetchColumn();
if ($marksCountAfter !== $marksCountBefore) {
    fail('marks table was modified');
}

if ($failed) {
    exit(1);
}

echo "\nAll coursework grading tests A–M passed. marks table untouched.\n";
exit(0);
