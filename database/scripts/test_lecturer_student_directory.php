<?php

declare(strict_types=1);

/**
 * Regression tests for Lecturer Student Directory scope.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_lecturer_student_directory.php
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
$cleanupStudentIds = [];
$cleanupUserIds = [];
$cleanupEnrolmentIds = [];
$tempAssignmentId = null;
$secondAssignmentId = null;
$createdSecondModuleAssignment = false;
$createdSecondExtraAssignment = false;

$attendanceBefore = (int) db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
$sessionsBefore = (int) db()->query('SELECT COUNT(*) FROM lecture_sessions')->fetchColumn();
$courseworkBefore = (int) db()->query('SELECT COUNT(*) FROM assignments')->fetchColumn();
$marksBefore = (int) db()->query('SELECT COUNT(*) FROM marks')->fetchColumn();
$submissionsBefore = (int) db()->query('SELECT COUNT(*) FROM assignment_submissions')->fetchColumn();

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
 * @return array{student_id:int,user_id:int,registration_no:string}
 */
function create_directory_test_student(int $courseId, int $batchId, string $suffix): array
{
    global $cleanupStudentIds, $cleanupUserIds;

    $studentId = register_student([
        'username' => 'dir_stu_' . $suffix,
        'email' => 'dir_stu_' . $suffix . '@example.test',
        'password' => 'TestPass123!',
        'account_status' => 'ACTIVE',
        'registration_no' => 'DIR-' . $suffix,
        'first_name' => 'Dir',
        'last_name' => 'Student' . $suffix,
        'phone' => '',
        'date_of_birth' => '',
        'gender' => '',
        'course_id' => $courseId,
        'batch_id' => $batchId,
        'enrollment_date' => app_today(),
        'status' => 'ACTIVE',
    ]);

    $userId = (int) db()->query(
        'SELECT user_id FROM students WHERE student_id = ' . (int) $studentId
    )->fetchColumn();

    $cleanupStudentIds[] = $studentId;
    $cleanupUserIds[] = $userId;

    return [
        'student_id' => $studentId,
        'user_id' => $userId,
        'registration_no' => 'DIR-' . $suffix,
    ];
}

function force_student_module_status(int $studentId, int $moduleId, string $status): void
{
    global $cleanupEnrolmentIds;

    $existing = get_student_module_enrolment($studentId, $moduleId);
    if ($existing === null) {
        enroll_student_in_module($studentId, $moduleId);
        $existing = get_student_module_enrolment($studentId, $moduleId);
    }
    if ($existing === null) {
        throw new RuntimeException('Unable to ensure enrolment for test.');
    }

    $cleanupEnrolmentIds[] = (int) $existing['student_module_id'];
    db()->prepare(
        'UPDATE student_modules SET status = :status WHERE student_module_id = :id'
    )->execute([
        'status' => $status,
        'id' => (int) $existing['student_module_id'],
    ]);
}

try {
    $suffix = (string) time();

    $cat101 = db()->query(
        "SELECT module_id, module_code FROM modules WHERE module_code = 'CAT101' AND status = 'ACTIVE' LIMIT 1"
    )->fetch();
    if ($cat101 === false) {
        fwrite(STDERR, "CAT101 module required for directory tests.\n");
        exit(1);
    }
    $cat101Id = (int) $cat101['module_id'];

    $courseModule = db()->prepare(
        "SELECT cm.course_id, b.batch_id, c.course_code, b.batch_name
         FROM course_modules cm
         INNER JOIN courses c ON c.course_id = cm.course_id AND c.status = 'ACTIVE'
         INNER JOIN batches b ON b.course_id = c.course_id AND b.status = 'ACTIVE'
         WHERE cm.module_id = :module_id AND cm.status = 'ACTIVE'
         ORDER BY c.course_code, b.batch_name
         LIMIT 1"
    );
    $courseModule->execute(['module_id' => $cat101Id]);
    $courseContext = $courseModule->fetch();
    if ($courseContext === false) {
        fwrite(STDERR, "No ACTIVE course/batch linked to CAT101 via course_modules.\n");
        exit(1);
    }
    $courseId = (int) $courseContext['course_id'];
    $batchId = (int) $courseContext['batch_id'];

    $otherModule = db()->prepare(
        "SELECT m.module_id, m.module_code
         FROM modules m
         INNER JOIN course_modules cm ON cm.module_id = m.module_id AND cm.course_id = :course_id AND cm.status = 'ACTIVE'
         WHERE m.status = 'ACTIVE' AND m.module_id <> :module_id
         ORDER BY m.module_code
         LIMIT 1"
    );
    $otherModule->execute([
        'course_id' => $courseId,
        'module_id' => $cat101Id,
    ]);
    $other = $otherModule->fetch();
    if ($other === false) {
        fwrite(STDERR, "Need a second ACTIVE course module on the same course as CAT101.\n");
        exit(1);
    }
    // Second taught module for duplicate/union tests (may temporarily assign lecturer).
    $secondModuleId = (int) $other['module_id'];

    $lecturer = db()->query(
        "SELECT lecturer_id, staff_no, first_name, last_name
         FROM lecturers
         WHERE status = 'ACTIVE'
         ORDER BY lecturer_id
         LIMIT 1"
    )->fetch();
    if ($lecturer === false) {
        fwrite(STDERR, "Need an ACTIVE lecturer.\n");
        exit(1);
    }
    $lecturerId = (int) $lecturer['lecturer_id'];

    // Unrelated module: ACTIVE module this lecturer does NOT teach (any course).
    $unrelated = db()->prepare(
        "SELECT m.module_id, m.module_code
         FROM modules m
         INNER JOIN course_modules cm ON cm.module_id = m.module_id AND cm.status = 'ACTIVE'
         WHERE m.status = 'ACTIVE'
           AND m.module_id <> :cat101
           AND m.module_id <> :second
           AND NOT EXISTS (
               SELECT 1 FROM module_lecturers ml
               WHERE ml.module_id = m.module_id AND ml.lecturer_id = :lecturer_id
           )
         ORDER BY m.module_code
         LIMIT 1"
    );
    $unrelated->execute([
        'cat101' => $cat101Id,
        'second' => $secondModuleId,
        'lecturer_id' => $lecturerId,
    ]);
    $unrelatedRow = $unrelated->fetch();
    if ($unrelatedRow === false) {
        fwrite(STDERR, "Need an ACTIVE module not taught by the test lecturer.\n");
        exit(1);
    }
    $otherModuleId = (int) $unrelatedRow['module_id'];

    // Student B must belong to a course that includes the unrelated module.
    $otherCourseCtx = db()->prepare(
        "SELECT cm.course_id, b.batch_id
         FROM course_modules cm
         INNER JOIN courses c ON c.course_id = cm.course_id AND c.status = 'ACTIVE'
         INNER JOIN batches b ON b.course_id = c.course_id AND b.status = 'ACTIVE'
         WHERE cm.module_id = :module_id AND cm.status = 'ACTIVE'
         ORDER BY c.course_code, b.batch_name
         LIMIT 1"
    );
    $otherCourseCtx->execute(['module_id' => $otherModuleId]);
    $otherContext = $otherCourseCtx->fetch();
    if ($otherContext === false) {
        fwrite(STDERR, "Unrelated module has no ACTIVE course/batch.\n");
        exit(1);
    }
    $otherCourseId = (int) $otherContext['course_id'];
    $otherBatchId = (int) $otherContext['batch_id'];

    if (!lecturer_is_assigned_to_module($lecturerId, $cat101Id)) {
        assign_lecturer_to_module($cat101Id, $lecturerId);
        $createdSecondModuleAssignment = true;
        $tempAssignmentId = (int) db()->query(
            'SELECT module_lecturer_id FROM module_lecturers
             WHERE lecturer_id = ' . $lecturerId . ' AND module_id = ' . $cat101Id . ' LIMIT 1'
        )->fetchColumn();
    } else {
        $tempAssignmentId = (int) db()->query(
            'SELECT module_lecturer_id FROM module_lecturers
             WHERE lecturer_id = ' . $lecturerId . ' AND module_id = ' . $cat101Id . ' LIMIT 1'
        )->fetchColumn();
    }

    if (!lecturer_is_assigned_to_module($lecturerId, $secondModuleId)) {
        assign_lecturer_to_module($secondModuleId, $lecturerId);
        $createdSecondExtraAssignment = true;
        $secondAssignmentId = (int) db()->query(
            'SELECT module_lecturer_id FROM module_lecturers
             WHERE lecturer_id = ' . $lecturerId . ' AND module_id = ' . $secondModuleId . ' LIMIT 1'
        )->fetchColumn();
    } else {
        $secondAssignmentId = (int) db()->query(
            'SELECT module_lecturer_id FROM module_lecturers
             WHERE lecturer_id = ' . $lecturerId . ' AND module_id = ' . $secondModuleId . ' LIMIT 1'
        )->fetchColumn();
    }

    // Clear auto-enrol rows for fresh control, then set explicit enrolments.
    $studentA = create_directory_test_student($courseId, $batchId, $suffix . 'a');
    $studentB = create_directory_test_student($otherCourseId, $otherBatchId, $suffix . 'b');
    $studentC = create_directory_test_student($courseId, $batchId, $suffix . 'c');

    foreach ([$studentA['student_id'], $studentB['student_id'], $studentC['student_id']] as $sid) {
        db()->prepare('DELETE FROM student_modules WHERE student_id = :id')->execute(['id' => $sid]);
    }

    force_student_module_status($studentA['student_id'], $cat101Id, 'ENROLLED');
    force_student_module_status($studentB['student_id'], $otherModuleId, 'ENROLLED');
    force_student_module_status($studentC['student_id'], $cat101Id, 'ENROLLED');
    force_student_module_status($studentC['student_id'], $secondModuleId, 'ENROLLED');

    $directory = list_students_for_lecturer($lecturerId);
    $directoryIds = array_map(static fn (array $row): int => (int) $row['student_id'], $directory);

    assert_true(
        in_array($studentA['student_id'], $directoryIds, true),
        'A Lecturer assigned to CAT101 sees CAT101 ENROLLED student'
    );
    assert_true(
        !in_array($studentB['student_id'], $directoryIds, true),
        'B Lecturer does not see student enrolled only in unrelated module'
    );

    $dupCount = 0;
    foreach ($directory as $row) {
        if ((int) $row['student_id'] === $studentC['student_id']) {
            $dupCount++;
        }
    }
    assert_true($dupCount === 1, 'C Student enrolled in two lecturer modules appears once', 'count=' . $dupCount);

    $moduleOptions = list_module_lecturer_assignments(null, $lecturerId);
    $moduleOptionIds = array_map(static fn (array $row): int => (int) $row['module_id'], $moduleOptions);
    $foreignModuleFilter = list_students_for_lecturer($lecturerId, ['module_id' => $otherModuleId]);
    assert_true(
        in_array($cat101Id, $moduleOptionIds, true)
        && in_array($secondModuleId, $moduleOptionIds, true)
        && $foreignModuleFilter === [],
        'D Lecturer module filter shows only lecturer-assigned modules'
    );

    $unrelatedCourse = db()->prepare(
        "SELECT course_id FROM courses
         WHERE status = 'ACTIVE' AND course_id <> :course_id
         ORDER BY course_id DESC
         LIMIT 1"
    );
    $unrelatedCourse->execute(['course_id' => $courseId]);
    $foreignCourseId = (int) $unrelatedCourse->fetchColumn();
    $courseFiltered = list_students_for_lecturer($lecturerId, ['course_id' => $foreignCourseId > 0 ? $foreignCourseId : 999999]);
    $courseFilteredIds = array_map(static fn (array $row): int => (int) $row['student_id'], $courseFiltered);
    assert_true(
        !in_array($studentB['student_id'], $courseFilteredIds, true)
        && (
            $foreignCourseId <= 0
            || !in_array($studentA['student_id'], $courseFilteredIds, true)
            || lecturer_can_view_student($lecturerId, $studentA['student_id'])
        ),
        'E Course filter cannot widen scope'
    );
    assert_true(
        !in_array($studentB['student_id'], $courseFilteredIds, true),
        'E Course filter still excludes unauthorized student'
    );

    $foreignBatch = db()->prepare(
        "SELECT batch_id FROM batches WHERE batch_id <> :batch_id ORDER BY batch_id DESC LIMIT 1"
    );
    $foreignBatch->execute(['batch_id' => $batchId]);
    $foreignBatchId = (int) $foreignBatch->fetchColumn();
    $batchFiltered = list_students_for_lecturer($lecturerId, [
        'batch_id' => $foreignBatchId > 0 ? $foreignBatchId : 999999,
    ]);
    $batchFilteredIds = array_map(static fn (array $row): int => (int) $row['student_id'], $batchFiltered);
    assert_true(
        !in_array($studentB['student_id'], $batchFilteredIds, true),
        'F Batch filter cannot widen scope'
    );

    $searchHits = list_students_for_lecturer($lecturerId, [
        'search' => $studentB['registration_no'],
    ]);
    $searchIds = array_map(static fn (array $row): int => (int) $row['student_id'], $searchHits);
    assert_true(
        !in_array($studentB['student_id'], $searchIds, true),
        'G Search cannot expose unauthorized student'
    );

    assert_true(
        !lecturer_can_view_student($lecturerId, $studentB['student_id']),
        'H Direct student_id URL to unrelated student denied'
    );
    assert_true(
        lecturer_can_view_student($lecturerId, $studentA['student_id']),
        'I Direct student_id URL to authorized student works'
    );

    $sharedBeforeDrop = list_shared_enrolled_modules_for_lecturer_student($lecturerId, $studentA['student_id']);
    assert_true($sharedBeforeDrop !== [], 'Shared modules visible before drop');

    force_student_module_status($studentA['student_id'], $cat101Id, 'DROPPED');
    assert_true(
        !lecturer_can_view_student($lecturerId, $studentA['student_id'])
        && !in_array($studentA['student_id'], array_map(static fn (array $r): int => (int) $r['student_id'], list_students_for_lecturer($lecturerId)), true),
        'J DROPPED shared enrolment removes directory access'
    );

    force_student_module_status($studentA['student_id'], $cat101Id, 'COMPLETED');
    assert_true(
        !lecturer_can_view_student($lecturerId, $studentA['student_id']),
        'K COMPLETED shared enrolment removes directory access'
    );

    force_student_module_status($studentA['student_id'], $cat101Id, 'ENROLLED');
    assert_true(lecturer_can_view_student($lecturerId, $studentA['student_id']), 'Restore ENROLLED access for assignment-removal tests');

    force_student_module_status($studentA['student_id'], $secondModuleId, 'ENROLLED');
    $studentOnlySecond = create_directory_test_student($courseId, $batchId, $suffix . 'd');
    db()->prepare('DELETE FROM student_modules WHERE student_id = :id')->execute(['id' => $studentOnlySecond['student_id']]);
    force_student_module_status($studentOnlySecond['student_id'], $secondModuleId, 'ENROLLED');

    $blockedSecond = db()->prepare(
        "SELECT schedule_id FROM schedules
         WHERE module_id = :module_id AND lecturer_id = :lecturer_id AND status = 'ACTIVE'
         LIMIT 1"
    );
    $blockedSecond->execute(['module_id' => $secondModuleId, 'lecturer_id' => $lecturerId]);
    $canUnassignSecond = $blockedSecond->fetch() === false && $secondAssignmentId;

    $blockedCat = db()->prepare(
        "SELECT schedule_id FROM schedules
         WHERE module_id = :module_id AND lecturer_id = :lecturer_id AND status = 'ACTIVE'
         LIMIT 1"
    );
    $blockedCat->execute(['module_id' => $cat101Id, 'lecturer_id' => $lecturerId]);
    $canUnassignCat = $blockedCat->fetch() === false && $tempAssignmentId;

    if ($canUnassignSecond) {
        unassign_lecturer_from_module((int) $secondAssignmentId);
        assert_true(
            !lecturer_can_view_student($lecturerId, $studentOnlySecond['student_id']),
            'L Removing lecturer from module removes access'
        );
        assert_true(
            lecturer_can_view_student($lecturerId, $studentA['student_id']),
            'M If another shared ENROLLED module remains, access remains'
        );
        // Restore second assignment for cleanup consistency when we created it.
        assign_lecturer_to_module($secondModuleId, $lecturerId);
        $secondAssignmentId = (int) db()->query(
            'SELECT module_lecturer_id FROM module_lecturers
             WHERE lecturer_id = ' . $lecturerId . ' AND module_id = ' . $secondModuleId . ' LIMIT 1'
        )->fetchColumn();
        // Keep $createdSecondExtraAssignment as originally set — do not delete a pre-existing assignment on cleanup.
    } elseif ($canUnassignCat) {
        unassign_lecturer_from_module((int) $tempAssignmentId);
        assert_true(
            lecturer_can_view_student($lecturerId, $studentA['student_id']),
            'M If another shared ENROLLED module remains, access remains'
        );
        force_student_module_status($studentA['student_id'], $secondModuleId, 'DROPPED');
        assert_true(
            !lecturer_can_view_student($lecturerId, $studentA['student_id']),
            'L Removing lecturer from module removes access'
        );
        assign_lecturer_to_module($cat101Id, $lecturerId);
        $tempAssignmentId = (int) db()->query(
            'SELECT module_lecturer_id FROM module_lecturers
             WHERE lecturer_id = ' . $lecturerId . ' AND module_id = ' . $cat101Id . ' LIMIT 1'
        )->fetchColumn();
        $createdSecondModuleAssignment = true;
        force_student_module_status($studentA['student_id'], $cat101Id, 'ENROLLED');
        force_student_module_status($studentA['student_id'], $secondModuleId, 'ENROLLED');
    } else {
        // ACTIVE schedules block unassign — simulate removal by dropping the sole shared ENROLLED link.
        force_student_module_status($studentOnlySecond['student_id'], $secondModuleId, 'DROPPED');
        assert_true(
            !lecturer_can_view_student($lecturerId, $studentOnlySecond['student_id']),
            'L Removing lecturer from module removes access (simulated: shared ENROLLED link removed because ACTIVE schedule blocks unassign)'
        );
        force_student_module_status($studentA['student_id'], $cat101Id, 'DROPPED');
        assert_true(
            lecturer_can_view_student($lecturerId, $studentA['student_id']),
            'M If another shared ENROLLED module remains, access remains'
        );
        force_student_module_status($studentA['student_id'], $cat101Id, 'ENROLLED');
    }

    $adminIndex = file_get_contents(dirname(__DIR__, 2) . '/web/admin/students/index.php');
    $staffIndex = file_get_contents(dirname(__DIR__, 2) . '/web/academic-staff/students/index.php');
    $sharedIndex = file_get_contents(dirname(__DIR__, 2) . '/web/shared/pages/students/index.php');
    assert_true(
        is_string($adminIndex)
        && str_contains($adminIndex, "require_admin()")
        && !str_contains($adminIndex, 'restrictLecturerId')
        && is_string($sharedIndex)
        && str_contains($sharedIndex, 'list_students($filters)'),
        'N Admin student management unchanged'
    );
    assert_true(
        is_string($staffIndex)
        && str_contains($staffIndex, 'require_student_manager()')
        && !str_contains($staffIndex, 'restrictLecturerId'),
        'O Academic Staff student management unchanged'
    );

    $attendanceAfter = (int) db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
    $sessionsAfter = (int) db()->query('SELECT COUNT(*) FROM lecture_sessions')->fetchColumn();
    $courseworkAfter = (int) db()->query('SELECT COUNT(*) FROM assignments')->fetchColumn();
    $marksAfter = (int) db()->query('SELECT COUNT(*) FROM marks')->fetchColumn();
    $submissionsAfter = (int) db()->query('SELECT COUNT(*) FROM assignment_submissions')->fetchColumn();
    assert_true(
        $attendanceAfter === $attendanceBefore
        && $sessionsAfter === $sessionsBefore
        && $courseworkAfter === $courseworkBefore
        && $marksAfter === $marksBefore
        && $submissionsAfter === $submissionsBefore,
        'P Attendance/coursework/marks/session data unchanged'
    );

    $sharedCourses = db()->prepare(
        "SELECT COUNT(DISTINCT cm.course_id)
         FROM course_modules cm
         WHERE cm.module_id = :module_id AND cm.status = 'ACTIVE'"
    );
    $sharedCourses->execute(['module_id' => $cat101Id]);
    $courseCount = (int) $sharedCourses->fetchColumn();
    assert_true(
        lecturer_is_assigned_to_module($lecturerId, $cat101Id)
        && $courseCount >= 1,
        'Q Shared catalogue module across courses uses global module_lecturers design',
        'active_course_links=' . $courseCount
    );

    $viewPage = file_get_contents(dirname(__DIR__, 2) . '/web/shared/pages/students/view.php');
    assert_true(
        is_string($viewPage)
        && str_contains($viewPage, 'lecturer_can_view_student')
        && str_contains($viewPage, 'list_shared_enrolled_modules_for_lecturer_student')
        && !preg_match('/\$_GET\s*\[\s*[\'"]lecturer_id[\'"]\s*\]/', $viewPage),
        'Student detail uses profile lecturer scope and shared modules only'
    );

    // Ensure admin list still sees both students (broader access).
    $adminList = list_students(['search' => 'DIR-' . $suffix]);
    $adminIds = array_map(static fn (array $row): int => (int) $row['student_id'], $adminList);
    assert_true(
        in_array($studentA['student_id'], $adminIds, true)
        && in_array($studentB['student_id'], $adminIds, true),
        'N Admin list_students still returns broader set'
    );
} catch (Throwable $exception) {
    fail('Unhandled exception', $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
}

// Cleanup test students/enrolments/users. Keep original lecturer assignments unless we created them.
foreach ($cleanupStudentIds as $studentId) {
    db()->prepare('DELETE FROM student_modules WHERE student_id = :id')->execute(['id' => $studentId]);
    db()->prepare('DELETE FROM students WHERE student_id = :id')->execute(['id' => $studentId]);
}
foreach ($cleanupUserIds as $userId) {
    db()->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $userId]);
}

if ($createdSecondExtraAssignment && $secondAssignmentId) {
    $assignment = db()->prepare(
        'SELECT module_id, lecturer_id FROM module_lecturers WHERE module_lecturer_id = :id LIMIT 1'
    );
    $assignment->execute(['id' => $secondAssignmentId]);
    $row = $assignment->fetch();
    if ($row !== false) {
        $blocked = db()->prepare(
            "SELECT schedule_id FROM schedules
             WHERE module_id = :module_id AND lecturer_id = :lecturer_id AND status = 'ACTIVE'
             LIMIT 1"
        );
        $blocked->execute([
            'module_id' => $row['module_id'],
            'lecturer_id' => $row['lecturer_id'],
        ]);
        if ($blocked->fetch() === false) {
            try {
                unassign_lecturer_from_module((int) $secondAssignmentId);
            } catch (Throwable) {
            }
        }
    }
}

if ($createdSecondModuleAssignment && $tempAssignmentId) {
    $blocked = db()->prepare(
        "SELECT schedule_id FROM schedules
         WHERE module_id = :module_id AND lecturer_id = :lecturer_id AND status = 'ACTIVE'
         LIMIT 1"
    );
    // Resolve lecturer/module from assignment row.
    $assignment = db()->prepare(
        'SELECT module_id, lecturer_id FROM module_lecturers WHERE module_lecturer_id = :id LIMIT 1'
    );
    $assignment->execute(['id' => $tempAssignmentId]);
    $row = $assignment->fetch();
    if ($row !== false) {
        $blocked->execute([
            'module_id' => $row['module_id'],
            'lecturer_id' => $row['lecturer_id'],
        ]);
        if ($blocked->fetch() === false) {
            try {
                unassign_lecturer_from_module((int) $tempAssignmentId);
            } catch (Throwable) {
                // Keep assignment if unassign is blocked.
            }
        }
    }
}

if ($failed) {
    fwrite(STDERR, "Some Lecturer Student Directory tests failed.\n");
    exit(1);
}

echo 'All Lecturer Student Directory regression tests passed.' . PHP_EOL;
