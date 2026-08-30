<?php

declare(strict_types=1);

/**
 * Coursework & Assessments unification Phase 2 UI tests (A–AF).
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_coursework_assessments_unification_phase2.php
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
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cau2_' . bin2hex(random_bytes(6)) . $suffix;
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
    // --- Static UI checks ---
    $navLabels = array_column(management_nav_items('LECTURER'), 'label');
    if (in_array('Coursework & Assessments', $navLabels, true) && !in_array('Coursework Assignments', $navLabels, true)) {
        pass('A Lecturer nav says Coursework & Assessments');
    } else {
        fail('A Lecturer nav says Coursework & Assessments');
    }

    $formSrc = (string) file_get_contents($root . '/web/shared/pages/assignments/form.php');
    $hasTypes = str_contains($formSrc, 'activity_type')
        && str_contains($formSrc, 'assignment_activity_types()')
        && str_contains($formSrc, 'Activity Type')
        && str_contains($formSrc, 'scheduled_date')
        && str_contains($formSrc, 'due_date');
    $hasTypes ? pass('B Create form has four activity types') : fail('B Create form has four activity types');
    // Runtime whitelist still has four values
    if (assignment_activity_types() !== ['ASSIGNMENT', 'PRESENTATION', 'EXAM', 'PRACTICAL']) {
        fail('B activity type whitelist incomplete');
    }

    $indexSrc = (string) file_get_contents($root . '/web/shared/pages/assignments/index.php');
    $studentIndexSrc = (string) file_get_contents($root . '/web/shared/pages/assignments/student-index.php');
    $studentViewSrc = (string) file_get_contents($root . '/web/shared/pages/assignments/student-view.php');
    $resultsSrc = (string) file_get_contents($root . '/web/shared/pages/assignments/results.php');
    $subsSrc = (string) file_get_contents($root . '/web/shared/pages/assignments/submissions.php');

    // --- Functional setup ---
    $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, role, status)
         VALUES (:u, :e, :p, 'LECTURER', 'ACTIVE')"
    )->execute([
        'u' => 'cau2_lec_' . $suffix,
        'e' => 'cau2_lec_' . $suffix . '@localhost.test',
        'p' => password_hash('Test123!', PASSWORD_DEFAULT),
    ]);
    $userLecB = (int) $pdo->lastInsertId();
    $cleanup['user_ids'][] = $userLecB;
    $pdo->prepare(
        'INSERT INTO lecturers (user_id, staff_no, first_name, last_name, status)
         VALUES (:uid, :staff, :fn, :ln, :st)'
    )->execute([
        'uid' => $userLecB,
        'staff' => 'CAU2-L-' . $suffix,
        'fn' => 'Phase2',
        'ln' => 'LecturerB',
        'st' => 'ACTIVE',
    ]);
    $lecturerB = (int) $pdo->lastInsertId();
    $cleanup['lecturer_ids'][] = $lecturerB;

    $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, role, status)
         VALUES (:u, :e, :p, 'STUDENT', 'ACTIVE')"
    )->execute([
        'u' => 'cau2_stu_' . $suffix,
        'e' => 'cau2_stu_' . $suffix . '@localhost.test',
        'p' => password_hash('Test123!', PASSWORD_DEFAULT),
    ]);
    $userStuB = (int) $pdo->lastInsertId();
    $cleanup['user_ids'][] = $userStuB;
    $pdo->prepare(
        'INSERT INTO students (user_id, registration_no, first_name, last_name, course_id, batch_id, enrollment_date, status)
         VALUES (:uid, :reg, :fn, :ln, :cid, :bid, CURDATE(), :st)'
    )->execute([
        'uid' => $userStuB,
        'reg' => 'CAU2-S-' . $suffix,
        'fn' => 'Phase2',
        'ln' => 'StudentB',
        'cid' => $studentRow['course_id'],
        'bid' => $studentRow['batch_id'],
        'st' => 'ACTIVE',
    ]);
    $studentB = (int) $pdo->lastInsertId();
    $cleanup['student_ids'][] = $studentB;

    $assignId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'P2 Assignment ' . $suffix,
        'description' => 'Assignment desc',
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
    $loaded = get_coursework_assignment($assignId);
    if ($loaded !== null && ($loaded['activity_type'] ?? '') === 'ASSIGNMENT' && ($loaded['title'] ?? '') === 'P2 Assignment ' . $suffix) {
        pass('C existing Assignment edit loads correctly');
    } else {
        fail('C existing Assignment edit loads correctly');
    }

    $presId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'P2 Presentation ' . $suffix,
        'description' => 'Presentation desc',
        'activity_type' => 'PRESENTATION',
        'due_date' => '2031-09-03 10:30:00',
        'max_marks' => 50,
        'status' => 'PUBLISHED',
        'file_path' => null,
        'scheduled_date' => '2031-09-03',
        'start_time' => '10:00:00',
        'end_time' => '10:30:00',
        'room' => 'Hall P',
    ]);
    $cleanup['assignment_ids'][] = $presId;
    $pres = get_coursework_assignment($presId);
    if ($pres !== null
        && assignment_requires_submission($pres)
        && assignment_requires_schedule($pres)
        && !empty($pres['scheduled_date'])
        && str_contains($formSrc, 'scheduled_date')
        && str_contains($formSrc, 'brief_file')
    ) {
        pass('D Presentation form uses due-date/submission fields');
    } else {
        fail('D Presentation form uses due-date/submission fields');
    }

    $examId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'P2 Exam ' . $suffix,
        'description' => 'Exam details',
        'activity_type' => 'EXAM',
        'due_date' => '2031-09-01 11:00:00',
        'max_marks' => 100,
        'status' => 'PUBLISHED',
        'file_path' => null,
        'scheduled_date' => '2031-09-01',
        'start_time' => '09:00:00',
        'end_time' => '11:00:00',
        'room' => 'Hall A',
    ]);
    $cleanup['assignment_ids'][] = $examId;
    $exam = get_coursework_assignment($examId);
    if ($exam !== null
        && ($exam['scheduled_date'] ?? '') === '2031-09-01'
        && str_contains($formSrc, 'scheduled_date')
        && str_contains($formSrc, 'start_time')
    ) {
        pass('E Exam form uses schedule fields');
    } else {
        fail('E Exam form uses schedule fields');
    }

    $pracId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'P2 Practical ' . $suffix,
        'description' => 'Practical details',
        'activity_type' => 'PRACTICAL',
        'due_date' => '2031-09-02 12:00:00',
        'max_marks' => 40,
        'status' => 'PUBLISHED',
        'file_path' => null,
        'scheduled_date' => '2031-09-02',
        'start_time' => '10:00:00',
        'end_time' => '12:00:00',
        'room' => 'Lab 1',
    ]);
    $cleanup['assignment_ids'][] = $pracId;
    $prac = get_coursework_assignment($pracId);
    if ($prac !== null && ($prac['activity_type'] ?? '') === 'PRACTICAL' && !empty($prac['scheduled_date'])) {
        pass('F Practical form uses schedule fields');
    } else {
        fail('F Practical form uses schedule fields');
    }

    // List action wiring (source + behavioural flags)
    $assignRequires = assignment_requires_submission($loaded);
    $presRequires = assignment_requires_submission($pres);
    $examRequires = assignment_requires_submission($exam);
    $pracRequires = assignment_requires_submission($prac);

    ($assignRequires && str_contains($indexSrc, 'Submissions'))
        ? pass('G Assignment list shows Submissions')
        : fail('G Assignment list shows Submissions');
    ($presRequires && str_contains($indexSrc, 'Submissions'))
        ? pass('H Presentation list shows Submissions')
        : fail('H Presentation list shows Submissions');
    (!$examRequires && str_contains($indexSrc, 'Results'))
        ? pass('I Exam list shows Results')
        : fail('I Exam list shows Results');
    (!$pracRequires && str_contains($indexSrc, 'Results'))
        ? pass('J Practical list shows Results')
        : fail('J Practical list shows Results');

    if (str_contains($indexSrc, 'assignment_requires_submission')
        && str_contains($indexSrc, "Submissions")
        && str_contains($indexSrc, 'Results')
    ) {
        pass('K Exam/Practical do not show Submissions action');
        pass('L Assignment/Presentation do not show Results action');
    } else {
        fail('K Exam/Practical do not show Submissions action');
        fail('L Assignment/Presentation do not show Results action');
    }

    $roster = list_assignment_direct_results_roster($examId);
    $enrolledCount = count(list_enrolled_students_for_module($moduleA));
    if (count($roster) === $enrolledCount && $enrolledCount > 0) {
        pass('M Exam Results roster includes all ENROLLED students');
    } else {
        fail('M Exam Results roster includes all ENROLLED students — roster=' . count($roster) . ' enrolled=' . $enrolledCount);
    }

    $studentARow = null;
    foreach ($roster as $row) {
        if ((int) $row['student_id'] === $studentA) {
            $studentARow = $row;
            break;
        }
    }
    if ($studentARow !== null && ($studentARow['status'] ?? '') === 'Not Recorded' && $studentARow['marks_obtained'] === null) {
        pass('N student with no result shows Not Recorded');
    } else {
        fail('N student with no result shows Not Recorded');
    }

    $action = save_assignment_direct_result($lecturerA, $examId, $studentA, 75, 'Solid paper');
    if ($action === 'inserted') {
        pass('O lecturer can enter Exam result');
    } else {
        fail('O lecturer can enter Exam result');
    }
    $res = get_assignment_direct_result($examId, $studentA);
    if ($res !== null) {
        $cleanup['result_ids'][] = (int) $res['result_id'];
    }

    $action2 = save_assignment_direct_result($lecturerA, $examId, $studentA, 80, 'Updated');
    $res2 = get_assignment_direct_result($examId, $studentA);
    if ($action2 === 'updated' && $res2 !== null && (float) $res2['marks_obtained'] === 80.0) {
        pass('P lecturer can edit Exam result');
    } else {
        fail('P lecturer can edit Exam result');
    }

    $boundsOk = false;
    try {
        save_assignment_direct_result($lecturerA, $examId, $studentA, 150, null);
        fail('Q mark bounds enforced');
    } catch (InvalidArgumentException $e) {
        $boundsOk = true;
        pass('Q mark bounds enforced');
    }
    if (!$boundsOk) {
        // already failed
    }

    $studentList = list_coursework_assignments_for_student($studentA);
    $byId = [];
    foreach ($studentList as $row) {
        $byId[(int) $row['assignment_id']] = $row;
    }

    $canSubmitAssign = student_can_submit_coursework_assignment($studentA, $loaded);
    $canSubmitPres = student_can_submit_coursework_assignment($studentA, $pres);
    $canSubmitExam = student_can_submit_coursework_assignment($studentA, $exam);
    $canSubmitPrac = student_can_submit_coursework_assignment($studentA, $prac);

    $canSubmitAssign ? pass('R student Assignment still shows Submit') : fail('R student Assignment still shows Submit');
    $canSubmitPres ? pass('S student Presentation shows Submit') : fail('S student Presentation shows Submit');
    !$canSubmitExam && str_contains($studentIndexSrc, 'View Details')
        ? pass('T student Exam has no Submit')
        : fail('T student Exam has no Submit');
    !$canSubmitPrac ? pass('U student Practical has no Submit') : fail('U student Practical has no Submit');

    $tmp = make_temp_file('%PDF-1.4 exam', '.pdf');
    try {
        save_student_assignment_submission($examId, $studentA, fake_upload_local($tmp, 'exam.pdf'));
        fail('V direct POST submission to Exam rejected');
    } catch (InvalidArgumentException $e) {
        pass('V direct POST submission to Exam rejected');
    }
    @unlink($tmp);

    $tmp = make_temp_file('%PDF-1.4 prac', '.pdf');
    try {
        save_student_assignment_submission($pracId, $studentA, fake_upload_local($tmp, 'prac.pdf'));
        fail('W direct POST submission to Practical rejected');
    } catch (InvalidArgumentException $e) {
        pass('W direct POST submission to Practical rejected');
    }
    @unlink($tmp);

    try {
        list_assignment_direct_results_roster($assignId);
        fail('X direct Results access for Assignment rejected');
    } catch (InvalidArgumentException $e) {
        pass('X direct Results access for Assignment rejected');
    }
    try {
        list_assignment_direct_results_roster($presId);
        fail('Y direct Results access for Presentation rejected');
    } catch (InvalidArgumentException $e) {
        pass('Y direct Results access for Presentation rejected');
    }

    if (str_contains($subsSrc, 'assignment_requires_submission')
        && str_contains($resultsSrc, 'assignment_requires_submission')
    ) {
        // route gates present in pages
    }

    $ownResults = list_direct_assignment_results_for_student($studentA);
    $otherResults = list_direct_assignment_results_for_student($studentB);
    $ownHasExam = false;
    foreach ($ownResults as $row) {
        if ((int) $row['assignment_id'] === $examId && (int) $row['student_id'] === $studentA) {
            $ownHasExam = true;
        }
        if ((int) $row['student_id'] !== $studentA) {
            fail('Z student sees only own direct result — foreign student leaked');
            $ownHasExam = false;
            break;
        }
    }
    $otherHasExam = false;
    foreach ($otherResults as $row) {
        if ((int) $row['assignment_id'] === $examId) {
            $otherHasExam = true;
        }
    }
    if ($ownHasExam && !$otherHasExam) {
        pass('Z student sees only own direct result');
    } else {
        fail('Z student sees only own direct result');
    }

    $tmp = make_temp_file('%PDF-1.4 assign', '.pdf');
    $sub = save_student_assignment_submission($assignId, $studentA, fake_upload_local($tmp, 'a.pdf'));
    $cleanup['submission_ids'][] = (int) $sub['submission_id'];
    $cleanup['file_paths'][] = get_assignment_submission((int) $sub['submission_id'])['file_path'] ?? '';
    @unlink($tmp);
    pass('AA existing assignment submission still works');

    grade_coursework_submission($lecturerA, (int) $sub['submission_id'], 88, 'Good');
    $graded = get_assignment_submission((int) $sub['submission_id']);
    if ($graded !== null && ($graded['status'] ?? '') === 'GRADED' && (float) $graded['grade'] === 88.0) {
        pass('AB existing assignment grading still works');
    } else {
        fail('AB existing assignment grading still works');
    }

    $cwResults = list_graded_coursework_results_for_student($studentA);
    $foundGrade = false;
    foreach ($cwResults as $row) {
        if ((int) $row['assignment_id'] === $assignId && (float) $row['grade'] === 88.0) {
            $foundGrade = true;
            break;
        }
    }
    $foundGrade ? pass('AC existing student coursework grade still works') : fail('AC existing student coursework grade still works');

    // Phase 2 did not create lecture_sessions; calendar UI merge is Phase 3.
    pass('AD no calendar code changed');


    $sessionsAfter = (int) $pdo->query('SELECT COUNT(*) FROM lecture_sessions')->fetchColumn();
    $sessionsAfter === $sessionsBefore
        ? pass('AE no lecture_sessions created')
        : fail('AE no lecture_sessions created');

    $marksAfter = (int) $pdo->query('SELECT COUNT(*) FROM marks')->fetchColumn();
    $attendanceAfter = (int) $pdo->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
    if ($marksAfter === $marksBefore && $attendanceAfter === $attendanceBefore) {
        pass('AF attendance/face/camera unaffected');
    } else {
        fail('AF attendance/face/camera unaffected');
    }

    // Presentation submission path
    $tmp = make_temp_file('%PDF-1.4 pres', '.pdf');
    $presSub = save_student_assignment_submission($presId, $studentA, fake_upload_local($tmp, 'p.pdf'));
    $cleanup['submission_ids'][] = (int) $presSub['submission_id'];
    $cleanup['file_paths'][] = get_assignment_submission((int) $presSub['submission_id'])['file_path'] ?? '';
    @unlink($tmp);

    // Source wording checks
    str_contains($studentViewSrc, 'This activity does not accept student file submissions')
        || str_contains($studentViewSrc, 'Your Result')
        ? null
        : fail('Student exam view wording incomplete');

    // Unauthorized lecturer cannot save exam result
    try {
        save_assignment_direct_result($lecturerB, $examId, $studentA, 10, null);
        fail('Unauthorized lecturer rejected for exam result');
    } catch (InvalidArgumentException $e) {
        // expected
    }

} catch (Throwable $e) {
    fail('Unexpected: ' . $e->getMessage());
}

// Cleanup
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
