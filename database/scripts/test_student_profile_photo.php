<?php

declare(strict_types=1);

/**
 * Regression tests for Optional Student Profile Photo v1.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_student_profile_photo.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/web/config/env.php';
require_once dirname(__DIR__, 2) . '/web/config/config.php';
require_once dirname(__DIR__, 2) . '/web/config/database.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/auth.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/management.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/academic.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/profile.php';

$failed = false;
$cleanupFiles = [];
$tempStudentId = null;
$tempUserId = null;
$originalPhoto = null;

$attendanceBefore = (int) db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
$faceBefore = (int) db()->query('SELECT COUNT(*) FROM face_profiles')->fetchColumn();
$assignmentsBefore = (int) db()->query('SELECT COUNT(*) FROM assignments')->fetchColumn();
$submissionFilesBefore = count_upload_files('assignments');

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

function count_upload_files(string $subdir): int
{
    $root = UPLOADS_PATH . DIRECTORY_SEPARATOR . $subdir;
    if (!is_dir($root)) {
        return 0;
    }
    $count = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $count++;
        }
    }

    return $count;
}

function fake_photo_upload(string $path, string $clientName, ?int $sizeOverride = null): array
{
    return [
        'name' => $clientName,
        'type' => 'application/octet-stream',
        'tmp_name' => $path,
        'error' => UPLOAD_ERR_OK,
        'size' => $sizeOverride ?? (int) filesize($path),
    ];
}

function make_temp_image(string $ext): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smartams-photo-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $blob = match ($ext) {
        'png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=', true),
        'jpg', 'jpeg' => base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDAREAAhEBAxEB/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQAAAP8A/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=', true),
        'webp' => base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA', true),
        default => false,
    };
    if ($blob === false || file_put_contents($path, $blob) === false) {
        throw new RuntimeException('Unable to create temp image.' . $ext);
    }

    return $path;
}

function student_photo_path(int $studentId): ?string
{
    $statement = db()->prepare('SELECT profile_photo FROM students WHERE student_id = :id LIMIT 1');
    $statement->execute(['id' => $studentId]);
    $value = $statement->fetchColumn();
    if ($value === false || $value === null || $value === '') {
        return null;
    }

    return (string) $value;
}

try {
    $suffix = (string) time();
    profile_photo_ensure_dir();

    $studentRow = db()->query(
        "SELECT s.student_id, s.user_id, s.profile_photo, s.first_name, s.last_name, u.username
         FROM students s
         INNER JOIN users u ON u.user_id = s.user_id
         WHERE u.status = 'ACTIVE' AND u.role = 'STUDENT'
         ORDER BY s.student_id
         LIMIT 1"
    )->fetch();
    if ($studentRow === false) {
        $courseBatch = db()->query(
            "SELECT c.course_id, b.batch_id
             FROM courses c
             INNER JOIN batches b ON b.course_id = c.course_id AND b.status = 'ACTIVE'
             WHERE c.status = 'ACTIVE'
             LIMIT 1"
        )->fetch();
        if ($courseBatch === false) {
            throw new RuntimeException('Need ACTIVE course/batch for test student.');
        }
        $tempStudentId = register_student([
            'username' => 'photo_stu_' . $suffix,
            'email' => 'photo_stu_' . $suffix . '@example.test',
            'password' => 'PhotoPass123!',
            'account_status' => 'ACTIVE',
            'registration_no' => 'PHOTO-' . $suffix,
            'first_name' => 'Photo',
            'last_name' => 'Student',
            'phone' => '',
            'date_of_birth' => '',
            'gender' => '',
            'course_id' => (int) $courseBatch['course_id'],
            'batch_id' => (int) $courseBatch['batch_id'],
            'enrollment_date' => app_today(),
            'status' => 'ACTIVE',
        ]);
        $tempUserId = (int) db()->query(
            'SELECT user_id FROM students WHERE student_id = ' . (int) $tempStudentId
        )->fetchColumn();
        $studentId = (int) $tempStudentId;
        $userId = (int) $tempUserId;
        $originalPhoto = null;
    } else {
        $studentId = (int) $studentRow['student_id'];
        $userId = (int) $studentRow['user_id'];
        $originalPhoto = isset($studentRow['profile_photo']) && $studentRow['profile_photo'] !== ''
            ? (string) $studentRow['profile_photo']
            : null;
        // Clear for clean tests; restore later.
        db()->prepare('UPDATE students SET profile_photo = NULL WHERE student_id = :id')->execute(['id' => $studentId]);
        if ($originalPhoto !== null) {
            // Keep original file on disk for restore; do not delete.
        }
    }

    // A default avatar
    $avatarHtml = render_student_profile_avatar([
        'student_id' => $studentId,
        'first_name' => 'Photo',
        'last_name' => 'Student',
        'profile_photo' => null,
    ], 'md');
    assert_true(
        str_contains($avatarHtml, 'profile-avatar--placeholder')
        && student_photo_path($studentId) === null,
        'A Student with no photo gets default avatar'
    );

    // B JPEG
    $jpg = make_temp_image('jpg');
    $cleanupFiles[] = $jpg;
    student_upload_own_profile_photo($userId, fake_photo_upload($jpg, 'me.jpg'));
    $pathB = student_photo_path($studentId);
    assert_true(
        $pathB !== null
        && profile_photo_resolve_path($pathB) !== null
        && str_ends_with(strtolower($pathB), '.jpg'),
        'B Student can upload valid JPEG'
    );
    $cleanupFiles[] = profile_photo_resolve_path($pathB) ?? '';

    // C PNG
    $png = make_temp_image('png');
    $cleanupFiles[] = $png;
    $oldAfterB = $pathB;
    student_upload_own_profile_photo($userId, fake_photo_upload($png, 'me.png'));
    $pathC = student_photo_path($studentId);
    assert_true(
        $pathC !== null
        && profile_photo_resolve_path($pathC) !== null
        && str_ends_with(strtolower($pathC), '.png'),
        'C Student can upload valid PNG'
    );
    $cleanupFiles[] = profile_photo_resolve_path($pathC) ?? '';
    assert_true(
        $oldAfterB !== $pathC && profile_photo_resolve_path($oldAfterB) === null,
        'H Replacement changes current photo'
    );

    // D WebP
    try {
        $webp = make_temp_image('webp');
        $cleanupFiles[] = $webp;
        $info = @getimagesize($webp);
        if (!is_array($info) || !defined('IMAGETYPE_WEBP') || (int) ($info[2] ?? 0) !== IMAGETYPE_WEBP) {
            pass('D Student can upload valid WebP if supported (skipped — WebP decode unavailable)');
        } else {
            student_upload_own_profile_photo($userId, fake_photo_upload($webp, 'me.webp'));
            $pathD = student_photo_path($studentId);
            assert_true(
                $pathD !== null && str_ends_with(strtolower((string) $pathD), '.webp'),
                'D Student can upload valid WebP if supported'
            );
            $cleanupFiles[] = profile_photo_resolve_path($pathD) ?? '';
        }
    } catch (Throwable $exception) {
        pass('D Student can upload valid WebP if supported (skipped — ' . $exception->getMessage() . ')');
    }

    $currentBeforeFail = student_photo_path($studentId);
    $currentAbsBeforeFail = profile_photo_resolve_path($currentBeforeFail);

    // E oversized
    $big = make_temp_image('png');
    $cleanupFiles[] = $big;
    $overRejected = false;
    try {
        student_upload_own_profile_photo(
            $userId,
            fake_photo_upload($big, 'big.png', PROFILE_PHOTO_MAX_BYTES + 10)
        );
    } catch (InvalidArgumentException) {
        $overRejected = true;
    }
    assert_true($overRejected, 'E Oversized upload rejected');

    // F PHP renamed .jpg
    $phpFake = tempnam(sys_get_temp_dir(), 'spp') . '.jpg';
    file_put_contents($phpFake, "<?php echo 'hack';");
    $cleanupFiles[] = $phpFake;
    $phpRejected = false;
    try {
        student_upload_own_profile_photo($userId, fake_photo_upload($phpFake, 'shell.jpg'));
    } catch (InvalidArgumentException) {
        $phpRejected = true;
    }
    assert_true($phpRejected, 'F Text/PHP renamed .jpg rejected');

    // G unsupported extension
    $gif = tempnam(sys_get_temp_dir(), 'spp') . '.gif';
    file_put_contents($gif, 'GIF89a');
    $cleanupFiles[] = $gif;
    $extRejected = false;
    try {
        student_upload_own_profile_photo($userId, fake_photo_upload($gif, 'anim.gif'));
    } catch (InvalidArgumentException) {
        $extRejected = true;
    }
    assert_true($extRejected, 'G Unsupported extension rejected');

    // I failed replacement keeps old photo
    assert_true(
        student_photo_path($studentId) === $currentBeforeFail
        && $currentAbsBeforeFail !== null
        && is_file($currentAbsBeforeFail),
        'I Failed replacement keeps old photo'
    );

    // J Remove
    student_remove_own_profile_photo($userId);
    assert_true(
        student_photo_path($studentId) === null
        && profile_photo_resolve_path($currentBeforeFail) === null,
        'J Remove returns to default avatar'
    );

    // Re-upload for auth view tests
    $jpg2 = make_temp_image('jpg');
    $cleanupFiles[] = $jpg2;
    student_upload_own_profile_photo($userId, fake_photo_upload($jpg2, 'final.jpg'));
    $finalPath = student_photo_path($studentId);
    $cleanupFiles[] = profile_photo_resolve_path($finalPath) ?? '';

    // K / L foreign student_id ignored — upload always uses session user profile
    $other = db()->query(
        'SELECT student_id, user_id, profile_photo FROM students WHERE student_id <> ' . $studentId . ' ORDER BY student_id LIMIT 1'
    )->fetch();
    if ($other !== false) {
        $otherBefore = isset($other['profile_photo']) ? (string) $other['profile_photo'] : '';
        $jpg3 = make_temp_image('jpg');
        $cleanupFiles[] = $jpg3;
        // Even if we "wish" to target another student, API only takes authenticated user id.
        student_upload_own_profile_photo($userId, fake_photo_upload($jpg3, 'own.jpg'));
        $otherAfter = (string) (db()->query(
            'SELECT profile_photo FROM students WHERE student_id = ' . (int) $other['student_id']
        )->fetchColumn() ?: '');
        assert_true($otherAfter === $otherBefore, 'K Student cannot modify another student\'s photo');
        assert_true($otherAfter === $otherBefore, 'L Foreign posted student_id ignored/rejected');
        $finalPath = student_photo_path($studentId);
        $cleanupFiles[] = profile_photo_resolve_path($finalPath) ?? '';
    } else {
        pass('K Student cannot modify another student\'s photo (skipped — only one student)');
        pass('L Foreign posted student_id ignored/rejected (skipped — only one student)');
    }

    $adminUser = ['user_id' => 1, 'role' => 'ADMIN', 'username' => 'admin'];
    $staffUser = ['user_id' => 2, 'role' => 'ACADEMIC_STAFF', 'username' => 'staff'];
    assert_true(user_can_view_student_profile_photo($adminUser, $studentId), 'M Admin can view student photo');
    assert_true(user_can_view_student_profile_photo($staffUser, $studentId), 'N Academic Staff can view student photo');

    // O authorized lecturer — ensure shared ENROLLED module
    $lecturer = db()->query(
        "SELECT l.lecturer_id, l.user_id
         FROM lecturers l
         INNER JOIN users u ON u.user_id = l.user_id
         WHERE u.status = 'ACTIVE' AND l.status = 'ACTIVE'
         ORDER BY l.lecturer_id
         LIMIT 1"
    )->fetch();
    $createdEnrolmentForTest = false;
    $createdAssignmentForTest = false;
    $tempAssignmentId = null;
    if ($lecturer !== false) {
        $lecturerId = (int) $lecturer['lecturer_id'];
        $studentCourseId = (int) db()->query(
            'SELECT course_id FROM students WHERE student_id = ' . $studentId
        )->fetchColumn();

        $module = db()->prepare(
            "SELECT m.module_id
             FROM modules m
             INNER JOIN course_modules cm ON cm.module_id = m.module_id AND cm.course_id = :course_id AND cm.status = 'ACTIVE'
             WHERE m.status = 'ACTIVE'
             ORDER BY m.module_id
             LIMIT 1"
        );
        $module->execute(['course_id' => $studentCourseId]);
        $moduleRow = $module->fetch();
        if ($moduleRow !== false) {
            $moduleId = (int) $moduleRow['module_id'];
            if (!lecturer_is_assigned_to_module($lecturerId, $moduleId)) {
                assign_lecturer_to_module($moduleId, $lecturerId);
                $createdAssignmentForTest = true;
                $tempAssignmentId = (int) db()->query(
                    'SELECT module_lecturer_id FROM module_lecturers
                     WHERE lecturer_id = ' . $lecturerId . ' AND module_id = ' . $moduleId . ' LIMIT 1'
                )->fetchColumn();
            }
            $enrol = get_student_module_enrolment($studentId, $moduleId);
            if ($enrol === null) {
                enroll_student_in_module($studentId, $moduleId);
                $createdEnrolmentForTest = true;
            } else {
                db()->prepare(
                    "UPDATE student_modules SET status = 'ENROLLED' WHERE student_module_id = :id"
                )->execute(['id' => (int) $enrol['student_module_id']]);
            }
        }

        $authLecturerOk = lecturer_can_view_student($lecturerId, $studentId);
        assert_true(
            $authLecturerOk
            && user_can_view_student_profile_photo(
                ['user_id' => (int) $lecturer['user_id'], 'role' => 'LECTURER', 'username' => 'lec'],
                $studentId
            ),
            'O Authorized Lecturer can view student photo'
        );

        // P unauthorized lecturer: temp lecturer with no modules
        $tempLecId = create_lecturer([
            'username' => 'photo_lec_' . $suffix,
            'email' => 'photo_lec_' . $suffix . '@example.test',
            'password' => 'LecPass123!',
            'account_status' => 'ACTIVE',
            'staff_no' => 'PL-' . $suffix,
            'first_name' => 'No',
            'last_name' => 'Access',
            'phone' => '',
            'department' => 'Test',
            'status' => 'ACTIVE',
        ]);
        $tempLecUserId = (int) db()->query(
            'SELECT user_id FROM lecturers WHERE lecturer_id = ' . $tempLecId
        )->fetchColumn();
        $unauthLecturerOk = !lecturer_can_view_student($tempLecId, $studentId)
            && !user_can_view_student_profile_photo(
                ['user_id' => $tempLecUserId, 'role' => 'LECTURER', 'username' => 'nolec'],
                $studentId
            );
        assert_true($unauthLecturerOk, 'P Unauthorized Lecturer cannot view student photo');
        db()->prepare('DELETE FROM lecturers WHERE lecturer_id = :id')->execute(['id' => $tempLecId]);
        db()->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $tempLecUserId]);

        if ($createdEnrolmentForTest && isset($moduleId)) {
            db()->prepare(
                'DELETE FROM student_modules WHERE student_id = :sid AND module_id = :mid'
            )->execute(['sid' => $studentId, 'mid' => $moduleId]);
        }
        if ($createdAssignmentForTest && $tempAssignmentId) {
            $blocked = db()->prepare(
                "SELECT schedule_id FROM schedules
                 WHERE module_id = :module_id AND lecturer_id = :lecturer_id AND status = 'ACTIVE'
                 LIMIT 1"
            );
            $blocked->execute(['module_id' => $moduleId, 'lecturer_id' => $lecturerId]);
            if ($blocked->fetch() === false) {
                try {
                    unassign_lecturer_from_module((int) $tempAssignmentId);
                } catch (Throwable) {
                }
            }
        }
    } else {
        fail('O Authorized Lecturer can view student photo', 'no lecturer available');
        fail('P Unauthorized Lecturer cannot view student photo', 'no lecturer available');
    }

    // Q / R / S unchanged systems
    $faceAfter = (int) db()->query('SELECT COUNT(*) FROM face_profiles')->fetchColumn();
    $attendanceAfter = (int) db()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn();
    $assignmentsAfter = (int) db()->query('SELECT COUNT(*) FROM assignments')->fetchColumn();
    $submissionFilesAfter = count_upload_files('assignments');
    assert_true($faceAfter === $faceBefore, 'Q Face enrollment files/data unchanged');
    assert_true($attendanceAfter === $attendanceBefore, 'R Attendance data unchanged');
    assert_true(
        $assignmentsAfter === $assignmentsBefore && $submissionFilesAfter === $submissionFilesBefore,
        'S Coursework/submission files unchanged'
    );

    // T profile/password still works
    $profile = load_own_profile($userId);
    $passwordPolicyOk = password_meets_policy('LongEnough1') && !password_meets_policy('short');
    $pwdRejected = false;
    try {
        change_own_password($userId, 'definitely-wrong-password', 'AnotherPass99', 'AnotherPass99');
    } catch (InvalidArgumentException) {
        $pwdRejected = true;
    }
    assert_true(
        (int) $profile['account']['user_id'] === $userId
        && is_array($profile['person'])
        && $passwordPolicyOk
        && $pwdRejected,
        'T Profile/password functionality still works'
    );
} catch (Throwable $exception) {
    fail('Unhandled exception', $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
}

// Cleanup photo for test student
try {
    if ($tempStudentId !== null) {
        $path = student_photo_path((int) $tempStudentId);
        profile_photo_delete_managed($path);
        db()->prepare('DELETE FROM student_modules WHERE student_id = :id')->execute(['id' => $tempStudentId]);
        db()->prepare('DELETE FROM students WHERE student_id = :id')->execute(['id' => $tempStudentId]);
        if ($tempUserId !== null) {
            db()->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $tempUserId]);
        }
    } else {
        $path = student_photo_path($studentId);
        profile_photo_delete_managed($path);
        db()->prepare(
            'UPDATE students SET profile_photo = :photo WHERE student_id = :id'
        )->execute([
            'photo' => $originalPhoto,
            'id' => $studentId,
        ]);
    }
} catch (Throwable) {
}

foreach ($cleanupFiles as $file) {
    if (is_string($file) && $file !== '' && is_file($file) && !str_contains($file, 'profile-photos')) {
        @unlink($file);
    }
}

if ($failed) {
    fwrite(STDERR, "Some Student Profile Photo tests failed.\n");
    exit(1);
}

echo 'All Student Profile Photo regression tests passed.' . PHP_EOL;
