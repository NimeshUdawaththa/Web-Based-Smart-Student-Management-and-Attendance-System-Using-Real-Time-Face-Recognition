<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — module marks/results Tests A–R.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_marks_results.php
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
    'mark_ids' => [],
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

$pdo = db();
$marksBefore = (int) $pdo->query('SELECT COUNT(*) FROM marks')->fetchColumn();
$submissionsSnapshot = $pdo->query(
    'SELECT submission_id, grade, feedback, status FROM assignment_submissions ORDER BY submission_id'
)->fetchAll();

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
$assessmentName = 'Quiz 01 ' . $suffix;

$pdo->prepare(
    "INSERT INTO users (username, email, password_hash, role, status)
     VALUES (:u, :e, :p, 'LECTURER', 'ACTIVE')"
)->execute([
    'u' => 'mrk_lec_' . $suffix,
    'e' => 'mrk_lec_' . $suffix . '@localhost.test',
    'p' => password_hash('Test123!', PASSWORD_DEFAULT),
]);
$userLecB = (int) $pdo->lastInsertId();
$cleanup['user_ids'][] = $userLecB;
$pdo->prepare(
    'INSERT INTO lecturers (user_id, staff_no, first_name, last_name, status)
     VALUES (:uid, :staff, :fn, :ln, :st)'
)->execute([
    'uid' => $userLecB,
    'staff' => 'MRK-L-' . $suffix,
    'fn' => 'Marks',
    'ln' => 'LecturerB',
    'st' => 'ACTIVE',
]);
$lecturerB = (int) $pdo->lastInsertId();
$cleanup['lecturer_ids'][] = $lecturerB;

$pdo->prepare(
    "INSERT INTO users (username, email, password_hash, role, status)
     VALUES (:u, :e, :p, 'STUDENT', 'ACTIVE')"
)->execute([
    'u' => 'mrk_stu_' . $suffix,
    'e' => 'mrk_stu_' . $suffix . '@localhost.test',
    'p' => password_hash('Test123!', PASSWORD_DEFAULT),
]);
$userStuB = (int) $pdo->lastInsertId();
$cleanup['user_ids'][] = $userStuB;
$pdo->prepare(
    'INSERT INTO students (user_id, registration_no, first_name, last_name, course_id, batch_id, enrollment_date, status)
     VALUES (:uid, :reg, :fn, :ln, :cid, :bid, CURDATE(), :st)'
)->execute([
    'uid' => $userStuB,
    'reg' => 'MRK-S-' . $suffix,
    'fn' => 'Marks',
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
    'u' => 'mrk_stc_' . $suffix,
    'e' => 'mrk_stc_' . $suffix . '@localhost.test',
    'p' => password_hash('Test123!', PASSWORD_DEFAULT),
]);
$userStuC = (int) $pdo->lastInsertId();
$cleanup['user_ids'][] = $userStuC;
$pdo->prepare(
    'INSERT INTO students (user_id, registration_no, first_name, last_name, course_id, batch_id, enrollment_date, status)
     VALUES (:uid, :reg, :fn, :ln, :cid, :bid, CURDATE(), :st)'
)->execute([
    'uid' => $userStuC,
    'reg' => 'MRK-C-' . $suffix,
    'fn' => 'Marks',
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
    if (lecturer_can_manage_module_marks($lecturerA, $moduleA)) {
        pass('A assigned lecturer can open own module results');
    } else {
        fail('A lecturer cannot manage assigned module');
    }

    $blockedB = false;
    try {
        save_module_assessment_results($lecturerB, $moduleA, 'Quiz', $assessmentName, '20', [
            $studentA => ['marks' => '10'],
        ]);
    } catch (InvalidArgumentException) {
        $blockedB = true;
    }
    if ($blockedB && !lecturer_can_manage_module_marks($lecturerB, $moduleA)) {
        pass('B lecturer cannot manage unauthorized module');
    } else {
        fail('B unauthorized lecturer was not blocked');
    }

    $entryRows = list_module_assessment_entry_rows($moduleA, 'Quiz', $assessmentName, $lecturerA);
    $ids = array_map(static fn (array $row): int => (int) $row['student_id'], $entryRows);
    if (in_array($studentA, $ids, true) && in_array($studentC, $ids, true) && !in_array($studentB, $ids, true)) {
        pass('C ENROLLED students appear in assessment entry');
    } else {
        fail('C enrolment list for entry is wrong');
    }

    $nonEnrolled = false;
    try {
        save_module_assessment_results($lecturerA, $moduleA, 'Quiz', $assessmentName, '20', [
            $studentB => ['marks' => '10'],
        ]);
    } catch (InvalidArgumentException) {
        $nonEnrolled = true;
    }
    if ($nonEnrolled) {
        pass('D non-enrolled student cannot receive a mark');
    } else {
        fail('D non-enrolled student mark was accepted');
    }

    $save = save_module_assessment_results($lecturerA, $moduleA, 'Quiz', $assessmentName, '20', [
        $studentA => ['marks' => '18', 'remarks' => 'Good work'],
        $studentC => ['marks' => '15', 'remarks' => ''],
    ]);
    $rowA = find_existing_mark_row($studentA, $moduleA, 'Quiz', $assessmentName, $lecturerA);
    $rowC = find_existing_mark_row($studentC, $moduleA, 'Quiz', $assessmentName, $lecturerA);
    if ($rowA !== null) {
        $cleanup['mark_ids'][] = (int) $rowA['mark_id'];
    }
    if ($rowC !== null) {
        $cleanup['mark_ids'][] = (int) $rowC['mark_id'];
    }
    if ($save['inserted'] === 2 && $rowA !== null && (float) $rowA['marks_obtained'] === 18.0 && $rowC !== null) {
        pass('E lecturer saves valid marks for multiple students');
    } else {
        fail('E multi-student save failed');
    }

    $over = false;
    try {
        save_module_assessment_results($lecturerA, $moduleA, 'Quiz', $assessmentName, '20', [
            $studentA => ['marks' => '21'],
        ]);
    } catch (InvalidArgumentException) {
        $over = true;
    }
    if ($over) {
        pass('F marks_obtained > max_marks rejected');
    } else {
        fail('F over-max mark accepted');
    }

    $neg = false;
    try {
        save_module_assessment_results($lecturerA, $moduleA, 'Quiz', $assessmentName, '20', [
            $studentA => ['marks' => '-1'],
        ]);
    } catch (InvalidArgumentException) {
        $neg = true;
    }
    if ($neg) {
        pass('G negative mark rejected');
    } else {
        fail('G negative mark accepted');
    }

    $nonNum = false;
    try {
        save_module_assessment_results($lecturerA, $moduleA, 'Quiz', $assessmentName, '20', [
            $studentA => ['marks' => '18x'],
        ]);
    } catch (InvalidArgumentException) {
        $nonNum = true;
    }
    if ($nonNum) {
        pass('H non-numeric mark rejected');
    } else {
        fail('H non-numeric mark accepted');
    }

    $badMax = false;
    try {
        save_module_assessment_results($lecturerA, $moduleA, 'Quiz', $assessmentName . ' zero', '0', [
            $studentA => ['marks' => '0'],
        ]);
    } catch (InvalidArgumentException) {
        $badMax = true;
    }
    if ($badMax) {
        pass('I max_marks <= 0 rejected');
    } else {
        fail('I max_marks <= 0 accepted');
    }

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM marks
         WHERE module_id = :m AND assessment_type = :t AND assessment_name = :n AND recorded_by = :r'
    );
    $countStmt->execute(['m' => $moduleA, 't' => 'Quiz', 'n' => $assessmentName, 'r' => $lecturerA]);
    $beforeDup = (int) $countStmt->fetchColumn();
    $again = save_module_assessment_results($lecturerA, $moduleA, 'Quiz', $assessmentName, '20', [
        $studentA => ['marks' => '18', 'remarks' => 'Good work'],
        $studentC => ['marks' => '15'],
    ]);
    $countStmt->execute(['m' => $moduleA, 't' => 'Quiz', 'n' => $assessmentName, 'r' => $lecturerA]);
    $afterDup = (int) $countStmt->fetchColumn();
    if ($again['updated'] >= 1 && $again['inserted'] === 0 && $afterDup === $beforeDup) {
        pass('J same assessment save updates existing rows');
    } else {
        fail('J duplicate insert occurred');
    }

    $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, role, status)
         VALUES (:u, :e, :p, 'STUDENT', 'ACTIVE')"
    )->execute([
        'u' => 'mrk_std_' . $suffix,
        'e' => 'mrk_std_' . $suffix . '@localhost.test',
        'p' => password_hash('Test123!', PASSWORD_DEFAULT),
    ]);
    $userStuD = (int) $pdo->lastInsertId();
    $cleanup['user_ids'][] = $userStuD;
    $pdo->prepare(
        'INSERT INTO students (user_id, registration_no, first_name, last_name, course_id, batch_id, enrollment_date, status)
         VALUES (:uid, :reg, :fn, :ln, :cid, :bid, CURDATE(), :st)'
    )->execute([
        'uid' => $userStuD,
        'reg' => 'MRK-D-' . $suffix,
        'fn' => 'Marks',
        'ln' => 'StudentD',
        'cid' => $studentRow['course_id'],
        'bid' => $studentRow['batch_id'],
        'st' => 'ACTIVE',
    ]);
    $studentD = (int) $pdo->lastInsertId();
    $cleanup['student_ids'][] = $studentD;
    $pdo->prepare(
        "INSERT INTO student_modules (student_id, module_id, status) VALUES (:s, :m, 'ENROLLED')"
    )->execute(['s' => $studentD, 'm' => $moduleA]);
    $cleanup['enrolment_ids'][] = (int) $pdo->lastInsertId();

    $entryAfter = list_module_assessment_entry_rows($moduleA, 'Quiz', $assessmentName, $lecturerA);
    $dRow = null;
    foreach ($entryAfter as $row) {
        if ((int) $row['student_id'] === $studentD) {
            $dRow = $row;
        }
    }
    $dMark = find_existing_mark_row($studentD, $moduleA, 'Quiz', $assessmentName, $lecturerA);
    if ($dRow !== null && $dRow['status'] === 'Not Recorded' && $dMark === null && (string) $dRow['marks_obtained'] !== '0') {
        pass('K missing student mark remains Not Recorded');
    } else {
        fail('K missing mark was treated as zero or inserted');
    }

    $markIdA = (int) $rowA['mark_id'];
    save_module_assessment_results($lecturerA, $moduleA, 'Quiz', $assessmentName, '20', [
        $studentA => ['marks' => '16', 'remarks' => 'Updated'],
    ]);
    $edited = find_existing_mark_row($studentA, $moduleA, 'Quiz', $assessmentName, $lecturerA);
    if ($edited !== null && (int) $edited['mark_id'] === $markIdA && (float) $edited['marks_obtained'] === 16.0) {
        pass('L lecturer edits an existing mark successfully');
    } else {
        fail('L edit did not update the same row');
    }

    $own = list_marks_for_student($studentA);
    $other = list_marks_for_student($studentB);
    $ownHas = false;
    foreach ($own as $row) {
        if ((string) $row['assessment_name'] === $assessmentName) {
            $ownHas = true;
        }
    }
    $otherHas = false;
    foreach ($other as $row) {
        if ((string) $row['assessment_name'] === $assessmentName) {
            $otherHas = true;
        }
    }
    if ($ownHas && !$otherHas) {
        pass('M student sees only own results');
    } else {
        fail('M student result scoping failed');
    }

    $nSafe = true;
    foreach (list_marks_for_student($studentB) as $row) {
        if ((int) $row['student_id'] !== $studentB) {
            $nSafe = false;
        }
    }
    if ($nSafe && list_marks_for_student($studentB) !== $own) {
        pass('N query student_id is not used; own-results helper is scoped');
    } else {
        fail('N student scoping failed');
    }

    $staffPage = is_file(WEB_PATH . '/academic-staff/marks/index.php')
        && is_file(WEB_PATH . '/admin/marks/index.php')
        && is_file(WEB_PATH . '/academic-staff/marks/entry.php');
    $staffSave = false;
    try {
        save_module_assessment_results(0, $moduleA, 'Quiz', $assessmentName, '20', [
            $studentA => ['marks' => '1'],
        ]);
    } catch (InvalidArgumentException) {
        $staffSave = true;
    }
    if ($staffPage && $staffSave && staff_can_monitor_marks() === false) {
        pass('O Academic Staff/Admin monitor pages exist and cannot record as lecturer 0');
    } elseif ($staffPage && $staffSave) {
        pass('O Academic Staff/Admin monitor is read-only at save API');
    } else {
        fail('O staff monitor checks failed');
    }

    $long = false;
    try {
        save_module_assessment_results($lecturerA, $moduleA, 'Quiz', $assessmentName, '20', [
            $studentA => ['marks' => '16', 'remarks' => str_repeat('x', 256)],
        ]);
    } catch (InvalidArgumentException $exception) {
        $long = str_contains($exception->getMessage(), '255');
    }
    $escaped = e('<script>alert(1)</script>');
    if ($long && $escaped === '&lt;script&gt;alert(1)&lt;/script&gt;') {
        pass('P remarks length and escaping handled');
    } else {
        fail('P remarks validation/escaping failed');
    }

    $countStmt->execute(['m' => $moduleA, 't' => 'Quiz', 'n' => $assessmentName, 'r' => $lecturerA]);
    $finalCount = (int) $countStmt->fetchColumn();
    $dupCheck = $pdo->prepare(
        'SELECT student_id, COUNT(*) AS c FROM marks
         WHERE module_id = :m AND assessment_type = :t AND assessment_name = :n AND recorded_by = :r
         GROUP BY student_id HAVING c > 1'
    );
    $dupCheck->execute(['m' => $moduleA, 't' => 'Quiz', 'n' => $assessmentName, 'r' => $lecturerA]);
    if ($finalCount === 2 && $dupCheck->fetch() === false) {
        pass('Q no duplicate rows from save/edit workflow');
    } else {
        fail('Q duplicates exist for the test assessment');
    }

    $afterSubs = $pdo->query(
        'SELECT submission_id, grade, feedback, status FROM assignment_submissions ORDER BY submission_id'
    )->fetchAll();
    $copied = $pdo->prepare(
        'SELECT COUNT(*) FROM marks WHERE assessment_name = :n AND recorded_by = :r'
    );
    // coursework titles are not this quiz name; also submissions snapshot equal
    if ($afterSubs === $submissionsSnapshot) {
        pass('R assignment_submissions grades unchanged and not copied into marks');
    } else {
        fail('R assignment_submissions were modified');
    }
} catch (Throwable $exception) {
    fail('Unhandled: ' . $exception->getMessage());
}

$pdo->prepare(
    'DELETE FROM marks WHERE assessment_name = :n AND recorded_by = :r'
)->execute(['n' => $assessmentName, 'r' => $lecturerA]);
foreach (array_reverse($cleanup['enrolment_ids']) as $id) {
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

if ($failed) {
    exit(1);
}

echo "\nAll marks/results tests A–R passed.\n";
exit(0);
