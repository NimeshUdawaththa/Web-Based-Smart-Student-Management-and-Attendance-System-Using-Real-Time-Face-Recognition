<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $studentRoutePrefix */
$studentRoutePrefix = $studentRoutePrefix ?? 'admin/students';

$studentId = positive_int($_GET['id'] ?? $_POST['student_id'] ?? null);
if ($studentId === null) {
    set_flash('error', 'Student not found.');
    redirect($studentRoutePrefix . '/index.php');
}

$student = get_student($studentId);
if ($student === null) {
    set_flash('error', 'Student not found.');
    redirect($studentRoutePrefix . '/index.php');
}

$pageTitle = 'Edit Student';
$courses = courses_for_selection((int) $student['course_id']);
$errors = [];
$form = [
    'student_id' => (string) $studentId,
    'email' => (string) $student['email'],
    'registration_no' => (string) $student['registration_no'],
    'first_name' => (string) $student['first_name'],
    'last_name' => (string) $student['last_name'],
    'phone' => (string) ($student['phone'] ?? ''),
    'date_of_birth' => (string) ($student['date_of_birth'] ?? ''),
    'gender' => (string) ($student['gender'] ?? ''),
    'course_id' => (string) $student['course_id'],
    'batch_id' => (string) $student['batch_id'],
    'enrollment_date' => (string) $student['enrollment_date'],
    'status' => (string) $student['status'],
    'account_status' => (string) $student['account_status'],
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($studentRoutePrefix . '/edit.php?id=' . $studentId);
    }

    $lifecycleAction = (string) ($_POST['lifecycle_action'] ?? '');
    if ($lifecycleAction === 'deactivate' || $lifecycleAction === 'reactivate') {
        try {
            if ($lifecycleAction === 'deactivate') {
                deactivate_student($studentId);
                set_flash('success', 'Student deactivated. Login and new attendance are disabled. Historical records were preserved.');
            } else {
                reactivate_student($studentId);
                set_flash('success', 'Student reactivated. Login and face attendance are enabled again.');
            }
            redirect($studentRoutePrefix . '/edit.php?id=' . $studentId);
        } catch (InvalidArgumentException $exception) {
            set_flash('error', $exception->getMessage());
            redirect($studentRoutePrefix . '/edit.php?id=' . $studentId);
        } catch (Throwable $exception) {
            error_log('Student lifecycle action failed: ' . $exception->getMessage());
            set_flash('error', 'Unable to update student status. Please try again.');
            redirect($studentRoutePrefix . '/edit.php?id=' . $studentId);
        }
    }

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    if ($form['email'] === '' || $form['registration_no'] === '' || $form['first_name'] === '' || $form['last_name'] === '') {
        $errors[] = 'Email, registration number, first name, and last name are required.';
    }

    if (!validate_email_address($form['email'])) {
        $errors[] = 'Enter a valid email address.';
    }

    $courseId = positive_int($form['course_id']);
    $batchId = positive_int($form['batch_id']);

    if ($courseId === null || $batchId === null) {
        $errors[] = 'Course and batch are required.';
    }

    if ($form['enrollment_date'] === '' || !validate_date_ymd($form['enrollment_date'])) {
        $errors[] = 'Enter a valid enrollment date.';
    }

    if ($form['gender'] !== '' && !in_array($form['gender'], gender_options(), true)) {
        $errors[] = 'Select a valid gender option.';
    }

    if (!in_array($form['status'], student_statuses(), true)) {
        $errors[] = 'Select a valid student status.';
    }

    if (!in_array($form['account_status'], user_statuses(), true)) {
        $errors[] = 'Select a valid account status.';
    }

    if ($form['date_of_birth'] !== '' && !validate_date_ymd($form['date_of_birth'])) {
        $errors[] = 'Enter a valid date of birth.';
    }

    if ($errors === [] && $courseId !== null && $batchId !== null) {
        try {
            update_student($studentId, [
                'email' => $form['email'],
                'registration_no' => $form['registration_no'],
                'first_name' => $form['first_name'],
                'last_name' => $form['last_name'],
                'phone' => $form['phone'],
                'date_of_birth' => $form['date_of_birth'],
                'gender' => $form['gender'],
                'course_id' => $courseId,
                'batch_id' => $batchId,
                'enrollment_date' => $form['enrollment_date'],
                'status' => $form['status'],
                'account_status' => $form['account_status'],
            ]);

            set_flash('success', 'Student record updated successfully.');
            redirect($studentRoutePrefix . '/index.php');
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Student update failed: ' . $exception->getMessage());
            $errors[] = 'Unable to update the student. Please try again.';
        }
    }
}

$dbStudent = get_student($studentId);
if ($dbStudent === null) {
    set_flash('error', 'Student not found.');
    redirect($studentRoutePrefix . '/index.php');
}

$selectedCourseId = positive_int($form['course_id']);
$includeCurrentBatch = $selectedCourseId === (int) $dbStudent['course_id'] ? (int) $dbStudent['batch_id'] : null;
$batches = $selectedCourseId !== null ? batches_for_selection($selectedCourseId, $includeCurrentBatch) : [];
$batchOptions = [];

foreach ($courses as $course) {
    $includeBatch = ((int) $course['course_id'] === (int) $dbStudent['course_id']) ? (int) $dbStudent['batch_id'] : null;
    $batchOptions[(string) $course['course_id']] = batches_for_selection((int) $course['course_id'], $includeBatch);
}

$isStudentActive = (string) $dbStudent['status'] === 'ACTIVE';
$isStudentInactive = (string) $dbStudent['status'] === 'INACTIVE';
$statusMismatch = student_account_status_is_mismatched($dbStudent);

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($studentRoutePrefix . '/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Back to Students</a>
</div>

<div class="d-flex align-items-center gap-3 mb-4">
    <?= render_student_profile_avatar($dbStudent, 'md') ?>
    <div>
        <div class="fw-semibold"><?= e($dbStudent['first_name'] . ' ' . $dbStudent['last_name']) ?></div>
        <div class="small text-muted"><?= e((string) $dbStudent['registration_no']) ?></div>
        <div class="mt-1">
            <span class="badge <?= e(status_badge_class((string) $dbStudent['status'])) ?>"><?= e((string) $dbStudent['status']) ?></span>
            <span class="badge <?= e(status_badge_class((string) $dbStudent['account_status'])) ?>">Account: <?= e((string) $dbStudent['account_status']) ?></span>
        </div>
    </div>
</div>

<?php if ($statusMismatch): ?>
    <div class="alert alert-warning">
        Status mismatch detected: student profile is <strong><?= e((string) $dbStudent['status']) ?></strong>
        but login account is <strong><?= e((string) $dbStudent['account_status']) ?></strong>.
        Use <strong>Deactivate Student / Reactivate Student</strong> below to align ACTIVE/INACTIVE consistently.
        No automatic bulk repair is applied.
    </div>
<?php endif; ?>

<?php if ($isStudentActive || $isStudentInactive): ?>
    <div class="card shadow-sm mb-4 border-<?= $isStudentActive ? 'warning' : 'success' ?>">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h2 class="h6 mb-1"><?= $isStudentActive ? 'Deactivate Student' : 'Reactivate Student' ?></h2>
                <p class="text-muted mb-0 small">
                    <?php if ($isStudentActive): ?>
                        Login and new attendance will be disabled. Historical academic records, face enrollment, and profile photo are preserved.
                    <?php else: ?>
                        Restores login and face attendance eligibility. Existing face enrollment and historical records are kept.
                    <?php endif; ?>
                </p>
            </div>
            <form method="post" class="mb-0"
                  onsubmit="return confirm(<?= e(json_encode(
                      $isStudentActive
                          ? 'Deactivate this student? Login and new attendance will be disabled. Historical academic records will be preserved.'
                          : 'Reactivate this student? Login and face attendance will be enabled again.'
                  )) ?>);">
                <?= csrf_field() ?>
                <input type="hidden" name="student_id" value="<?= e((string) $studentId) ?>">
                <input type="hidden" name="lifecycle_action" value="<?= $isStudentActive ? 'deactivate' : 'reactivate' ?>">
                <button type="submit" class="btn btn-<?= $isStudentActive ? 'warning' : 'success' ?>">
                    <?= $isStudentActive ? 'Deactivate Student' : 'Reactivate Student' ?>
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" class="card shadow-sm">
    <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="student_id" value="<?= e((string) $studentId) ?>">

        <div class="alert alert-info">
            Face status: <strong><?= e($dbStudent['face_status']) ?></strong>.
            Username remains unchanged for this account.
            Prefer <strong>Deactivate / Reactivate</strong> above for account lifecycle; status fields here remain available for other cases (e.g. GRADUATED).
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="form-label">Username</label>
                <input type="text" class="form-control" value="<?= e($dbStudent['username']) ?>" disabled>
            </div>
            <div class="col-md-4">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" id="email" name="email" value="<?= e($form['email']) ?>" required>
            </div>
            <div class="col-md-4">
                <label for="account_status" class="form-label">Account Status</label>
                <select class="form-select" id="account_status" name="account_status">
                    <?php foreach (user_statuses() as $accountStatus): ?>
                        <option value="<?= e($accountStatus) ?>" <?= $form['account_status'] === $accountStatus ? 'selected' : '' ?>><?= e($accountStatus) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-4">
                <label for="registration_no" class="form-label">Registration Number</label>
                <input type="text" class="form-control" id="registration_no" name="registration_no" value="<?= e($form['registration_no']) ?>" required>
            </div>
            <div class="col-md-4">
                <label for="first_name" class="form-label">First Name</label>
                <input type="text" class="form-control" id="first_name" name="first_name" value="<?= e($form['first_name']) ?>" required>
            </div>
            <div class="col-md-4">
                <label for="last_name" class="form-label">Last Name</label>
                <input type="text" class="form-control" id="last_name" name="last_name" value="<?= e($form['last_name']) ?>" required>
            </div>
            <div class="col-md-4">
                <label for="phone" class="form-label">Phone</label>
                <input type="text" class="form-control" id="phone" name="phone" value="<?= e($form['phone']) ?>">
            </div>
            <div class="col-md-4">
                <label for="date_of_birth" class="form-label">Date of Birth</label>
                <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" value="<?= e($form['date_of_birth']) ?>">
            </div>
            <div class="col-md-4">
                <label for="gender" class="form-label">Gender</label>
                <select class="form-select" id="gender" name="gender">
                    <option value="">Select gender</option>
                    <?php foreach (gender_options() as $gender): ?>
                        <option value="<?= e($gender) ?>" <?= $form['gender'] === $gender ? 'selected' : '' ?>><?= e($gender) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="course_id" class="form-label">Course</label>
                <select class="form-select course-select" id="course_id" name="course_id" data-batch-target="batch_id" required>
                    <option value="">Select course</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?= e((string) $course['course_id']) ?>" <?= (string) $selectedCourseId === (string) $course['course_id'] ? 'selected' : '' ?>>
                            <?= e(course_choice_label($course)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="batch_id" class="form-label">Batch</label>
                <select class="form-select batch-select" id="batch_id" name="batch_id" required>
                    <option value="">Select batch</option>
                    <?php foreach ($batches as $batch): ?>
                        <option value="<?= e((string) $batch['batch_id']) ?>" <?= $form['batch_id'] === (string) $batch['batch_id'] ? 'selected' : '' ?>>
                            <?= e($batch['label'] ?? batch_choice_label($batch)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="enrollment_date" class="form-label">Enrollment Date</label>
                <input type="date" class="form-control" id="enrollment_date" name="enrollment_date" value="<?= e($form['enrollment_date']) ?>" required>
            </div>
            <div class="col-md-4">
                <label for="status" class="form-label">Student Status</label>
                <select class="form-select" id="status" name="status">
                    <?php foreach (student_statuses() as $studentStatus): ?>
                        <option value="<?= e($studentStatus) ?>" <?= $form['status'] === $studentStatus ? 'selected' : '' ?>><?= e($studentStatus) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="mt-4 d-flex gap-2 flex-wrap">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="<?= e(app_url(str_replace('/students', '/enrollments', $studentRoutePrefix) . '/student.php?id=' . $studentId)) ?>" class="btn btn-outline-secondary">Module Enrollment</a>
            <a href="<?= e(app_url($studentRoutePrefix . '/face-enroll.php?id=' . $studentId)) ?>" class="btn btn-outline-secondary">Enroll Face</a>
            <a href="<?= e(app_url($studentRoutePrefix . '/reset-password.php?id=' . $studentId)) ?>" class="btn btn-outline-warning">Reset Password</a>
        </div>
    </div>
</form>

<script type="application/json" id="batch-options-data"><?= json_encode($batchOptions, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
