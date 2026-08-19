<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — coursework assignments Tests A–R.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_coursework_assignments.php
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
    'submission_ids' => [],
    'assignment_ids' => [],
    'file_paths' => [],
    'enrolment_ids' => [],
    'module_lecturer_ids' => [],
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
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cwtest_' . bin2hex(random_bytes(6)) . $suffix;
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

$taught = $pdo->query(
    "SELECT ml.lecturer_id, ml.module_id, ml.module_lecturer_id
     FROM module_lecturers ml
     INNER JOIN student_modules sm ON sm.module_id = ml.module_id AND sm.status = 'ENROLLED'
     ORDER BY ml.module_lecturer_id
     LIMIT 1"
)->fetch();

if ($taught === false) {
    fwrite(STDERR, "Need at least one module_lecturers row and an ENROLLED student_modules row.\n");
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
if ($studentRow === false) {
    fwrite(STDERR, "Student profile missing.\n");
    exit(1);
}

$suffix = bin2hex(random_bytes(4));

$pdo->prepare(
    "INSERT INTO users (username, email, password_hash, role, status)
     VALUES (:u, :e, :p, 'LECTURER', 'ACTIVE')"
)->execute([
    'u' => 'cwtest_lec_' . $suffix,
    'e' => 'cwtest_lec_' . $suffix . '@localhost.test',
    'p' => password_hash('Test123!', PASSWORD_DEFAULT),
]);
$userLecB = (int) $pdo->lastInsertId();
$cleanup['user_ids'][] = $userLecB;

$pdo->prepare(
    'INSERT INTO lecturers (user_id, staff_no, first_name, last_name, status)
     VALUES (:uid, :staff, :fn, :ln, :st)'
)->execute([
    'uid' => $userLecB,
    'staff' => 'CWT-L-' . $suffix,
    'fn' => 'Coursework',
    'ln' => 'LecturerB',
    'st' => 'ACTIVE',
]);
$lecturerB = (int) $pdo->lastInsertId();
$cleanup['lecturer_ids'][] = $lecturerB;

$pdo->prepare(
    "INSERT INTO users (username, email, password_hash, role, status)
     VALUES (:u, :e, :p, 'STUDENT', 'ACTIVE')"
)->execute([
    'u' => 'cwtest_stu_' . $suffix,
    'e' => 'cwtest_stu_' . $suffix . '@localhost.test',
    'p' => password_hash('Test123!', PASSWORD_DEFAULT),
]);
$userStuB = (int) $pdo->lastInsertId();
$cleanup['user_ids'][] = $userStuB;

$pdo->prepare(
    'INSERT INTO students (user_id, registration_no, first_name, last_name, course_id, batch_id, enrollment_date, status)
     VALUES (:uid, :reg, :fn, :ln, :cid, :bid, CURDATE(), :st)'
)->execute([
    'uid' => $userStuB,
    'reg' => 'CWT-S-' . $suffix,
    'fn' => 'Coursework',
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
    'u' => 'cwtest_st2_' . $suffix,
    'e' => 'cwtest_st2_' . $suffix . '@localhost.test',
    'p' => password_hash('Test123!', PASSWORD_DEFAULT),
]);
$userStuC = (int) $pdo->lastInsertId();
$cleanup['user_ids'][] = $userStuC;

$pdo->prepare(
    'INSERT INTO students (user_id, registration_no, first_name, last_name, course_id, batch_id, enrollment_date, status)
     VALUES (:uid, :reg, :fn, :ln, :cid, :bid, CURDATE(), :st)'
)->execute([
    'uid' => $userStuC,
    'reg' => 'CWT-C-' . $suffix,
    'fn' => 'Coursework',
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

$futureDue = app_now()->modify('+7 days')->format('Y-m-d H:i:s');
$pastDue = app_now()->modify('-1 day')->format('Y-m-d H:i:s');

try {
    $draftId = create_coursework_assignment($lecturerA, [
        'module_id' => $moduleA,
        'title' => 'CW Test Draft ' . $suffix,
        'description' => 'Draft instructions',
        'due_date' => $futureDue,
        'max_marks' => 100.0,
        'status' => 'DRAFT',
        'file_path' => null,
    ]);
    $cleanup['assignment_ids'][] = $draftId;
    $draft = get_coursework_assignment($draftId);
    if ($draft !== null && $draft['status'] === 'DRAFT' && (int) $draft['lecturer_id'] === $lecturerA) {
        pass('A lecturer creates DRAFT assignment for own module');
    } else {
        fail('A draft assignment was not stored correctly');
    }

    $studentList = list_coursework_assignments_for_student($studentA);
    $ids = array_map(static fn (array $row): int => (int) $row['assignment_id'], $studentList);
    $canViewDraft = student_can_view_coursework_assignment($studentA, $draft ?? []);
    if (!in_array($draftId, $ids, true) && $canViewDraft === false) {
        pass('B student cannot see DRAFT');
    } else {
        fail('B student saw a DRAFT assignment');
    }

    update_coursework_assignment($draftId, $lecturerA, [
        'module_id' => $moduleA,
        'title' => 'CW Test Published ' . $suffix,
        'description' => 'Published instructions',
        'due_date' => $futureDue,
        'max_marks' => 100.0,
        'status' => 'PUBLISHED',
        'file_path' => null,
    ]);
    $published = get_coursework_assignment($draftId);
    if ($published !== null && $published['status'] === 'PUBLISHED') {
        pass('C lecturer publishes assignment');
    } else {
        fail('C publish did not update status');
    }

    $studentList = list_coursework_assignments_for_student($studentA);
    $ids = array_map(static fn (array $row): int => (int) $row['assignment_id'], $studentList);
    if (in_array($draftId, $ids, true) && student_can_view_coursework_assignment($studentA, $published ?? [])) {
        pass('D enrolled student can see published assignment');
    } else {
        fail('D enrolled student cannot see published assignment');
    }

    $bList = list_coursework_assignments_for_student($studentB);
    $bIds = array_map(static fn (array $row): int => (int) $row['assignment_id'], $bList);
    $bSubmitBlocked = false;
    try {
        save_student_assignment_submission($draftId, $studentB, fake_upload(make_temp_file("%PDF-1.4\n%%EOF\n", '.pdf'), 'sneak.pdf'));
    } catch (InvalidArgumentException) {
        $bSubmitBlocked = true;
    }
    if (!in_array($draftId, $bIds, true) && !student_can_view_coursework_assignment($studentB, $published ?? []) && $bSubmitBlocked) {
        pass('E non-enrolled student cannot see/submit');
    } else {
        fail('E non-enrolled student was able to see or submit');
    }

    $createBlocked = false;
    try {
        create_coursework_assignment($lecturerB, [
            'module_id' => $moduleA,
            'title' => 'Should fail',
            'description' => null,
            'due_date' => $futureDue,
            'max_marks' => 10.0,
            'status' => 'DRAFT',
            'file_path' => null,
        ]);
    } catch (InvalidArgumentException) {
        $createBlocked = true;
    }
    $manageBlocked = !lecturer_can_manage_coursework_assignment($lecturerB, $published ?? []);
    if ($createBlocked && $manageBlocked) {
        pass('F lecturer cannot create/open unauthorized assignment');
    } else {
        fail('F unauthorized lecturer was not blocked');
    }

    $uploadOk = true;
    foreach (
        [
            [make_temp_file("%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n", '.pdf'), 'ok.pdf', 'briefs'],
            [make_temp_file("PK\x03\x04" . str_repeat("\0", 30), '.docx'), 'ok.docx', 'briefs'],
            [make_temp_file("PK\x03\x04" . str_repeat("\0", 30), '.zip'), 'ok.zip', 'submissions'],
        ] as [$path, $name, $dir]
    ) {
        try {
            $stored = assignment_store_uploaded_file(fake_upload($path, $name), $dir);
            $cleanup['file_paths'][] = $stored['relative_path'];
            if (assignment_resolve_stored_path($stored['relative_path']) === null) {
                $uploadOk = false;
            }
        } catch (Throwable $exception) {
            $uploadOk = false;
            fwrite(STDERR, '  upload ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
        }
        @unlink($path);
    }
    if ($uploadOk) {
        pass('G student/lecturer allowed PDF/DOCX/ZIP uploads');
    } else {
        fail('G allowed upload types were rejected');
    }

    $rejectOk = true;
    foreach (
        [
            [make_temp_file("<?php echo 1;", '.php'), 'bad.php'],
            [make_temp_file("MZ" . str_repeat("\0", 40), '.exe'), 'bad.exe'],
            [make_temp_file("<script>alert(1)</script>", '.js'), 'bad.js'],
            [make_temp_file("<?php echo 1;", '.phtml'), 'bad.phtml'],
        ] as [$path, $name]
    ) {
        try {
            assignment_store_uploaded_file(fake_upload($path, $name), 'submissions');
            $rejectOk = false;
        } catch (InvalidArgumentException) {
            // expected
        } catch (Throwable) {
            $rejectOk = false;
        }
        @unlink($path);
    }
    if ($rejectOk) {
        pass('H PHP/EXE/script upload rejected');
    } else {
        fail('H dangerous upload was accepted');
    }

    $pdf = make_temp_file("%PDF-1.4\n%%EOF\n", '.pdf');
    $first = save_student_assignment_submission($draftId, $studentA, fake_upload($pdf, 'work.pdf'));
    @unlink($pdf);
    $cleanup['submission_ids'][] = $first['submission_id'];
    $rowCountStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = :a AND student_id = :s'
    );
    $rowCountStmt->execute(['a' => $draftId, 's' => $studentA]);
    $rowCount = (int) $rowCountStmt->fetchColumn();
    $sub = get_assignment_submission_for_student($draftId, $studentA);
    if ($sub !== null) {
        $cleanup['file_paths'][] = (string) $sub['file_path'];
    }
    if ($rowCount === 1 && $first['replaced'] === false) {
        pass('I first submit creates one DB row');
    } else {
        fail('I first submit row count was ' . $rowCount);
    }

    $pdf2 = make_temp_file("%PDF-1.4\n%%EOF\nreplaced\n", '.pdf');
    $second = save_student_assignment_submission($draftId, $studentA, fake_upload($pdf2, 'work2.pdf'));
    @unlink($pdf2);
    $rowCountStmt->execute(['a' => $draftId, 's' => $studentA]);
    $rowCount2 = (int) $rowCountStmt->fetchColumn();
    $sub2 = get_assignment_submission_for_student($draftId, $studentA);
    if ($sub2 !== null) {
        $cleanup['file_paths'][] = (string) $sub2['file_path'];
    }
    if ($rowCount2 === 1 && $second['replaced'] === true && (int) $second['submission_id'] === (int) $first['submission_id']) {
        pass('J replace before deadline updates same row');
    } else {
        fail('J replacement did not update the same unique row');
    }

    $kDenied = assignment_authorized_download('submission', (int) $second['submission_id'], 'STUDENT', null, $studentB) === null;
    if ($kDenied) {
        pass('K other student submission download denied');
    } else {
        fail('K other student was allowed to download a submission');
    }

    $lAllowed = assignment_authorized_download('submission', (int) $second['submission_id'], 'LECTURER', $lecturerA, null);
    if ($lAllowed !== null && is_file($lAllowed['absolute_path'])) {
        pass('L assigned lecturer downloads student submission');
    } else {
        fail('L assigned lecturer could not download submission');
    }

    $mDenied = assignment_authorized_download('submission', (int) $second['submission_id'], 'LECTURER', $lecturerB, null) === null;
    if ($mDenied) {
        pass('M unrelated lecturer submission download denied');
    } else {
        fail('M unrelated lecturer was allowed to download a submission');
    }

    $matrix = list_assignment_submission_matrix($draftId);
    $statuses = [];
    foreach ($matrix as $row) {
        $statuses[(int) $row['student_id']] = $row['matrix_status'];
    }
    if (($statuses[$studentA] ?? '') === 'SUBMITTED' && ($statuses[$studentC] ?? '') === 'NOT SUBMITTED') {
        pass('N submissions matrix includes NOT SUBMITTED students');
    } else {
        fail('N matrix missing NOT SUBMITTED enrolled student');
    }

    set_app_now_override(app_now()->modify('+8 days'));
    $lateBlocked = false;
    $pdf3 = make_temp_file("%PDF-1.4\n%%EOF\n", '.pdf');
    try {
        save_student_assignment_submission($draftId, $studentC, fake_upload($pdf3, 'late.pdf'));
    } catch (InvalidArgumentException) {
        $lateBlocked = true;
    }
    @unlink($pdf3);
    set_app_now_override(null);

    $pdo->prepare('UPDATE assignments SET due_date = :due WHERE assignment_id = :id')->execute([
        'due' => $pastDue,
        'id' => $draftId,
    ]);
    $afterDue = get_coursework_assignment($draftId);
    $subAfter = get_assignment_submission_for_student($draftId, $studentA);
    $displayLate = student_coursework_display_status($afterDue ?? [], $subAfter);
    if ($lateBlocked && $displayLate === 'LATE') {
        pass('O due-date rule blocks late submit and classifies existing late rows');
    } else {
        fail('O due-date rule failed (blocked=' . ($lateBlocked ? 'yes' : 'no') . ', display=' . $displayLate . ')');
    }

    $traversalDenied =
        assignment_normalize_relative_path('../etc/passwd') === null
        && assignment_normalize_relative_path('/etc/passwd') === null
        && assignment_normalize_relative_path('assignments/briefs/../../.env') === null
        && assignment_resolve_stored_path('assignments/briefs/../../.env') === null;
    if ($traversalDenied) {
        pass('P path traversal attempts are denied');
    } else {
        fail('P path traversal was not denied');
    }

    $emptyList = list_coursework_assignments_for_lecturer($lecturerB);
    $emptyHtml = is_file(WEB_PATH . '/shared/pages/assignments/index.php')
        && is_file(WEB_PATH . '/shared/pages/assignments/student-index.php');
    if ($emptyList === [] && $emptyHtml) {
        pass('Q empty assignment lists are supported');
    } else {
        fail('Q empty-state support missing');
    }

    $monitor = list_coursework_assignments_for_monitor();
    $monitorIds = array_map(static fn (array $row): int => (int) $row['assignment_id'], $monitor);
    $staffDownload = assignment_authorized_download('submission', (int) $second['submission_id'], 'ACADEMIC_STAFF', null, null);
    if (in_array($draftId, $monitorIds, true) && $staffDownload !== null) {
        pass('R Academic Staff/Admin monitor can view assignments and files');
    } else {
        fail('R coursework monitor did not return the test assignment or download');
    }
} catch (Throwable $exception) {
    fail('Unhandled: ' . $exception->getMessage());
}

set_app_now_override(null);

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

if ($failed) {
    exit(1);
}

echo "\nAll coursework assignment tests A–R passed.\n";
exit(0);
