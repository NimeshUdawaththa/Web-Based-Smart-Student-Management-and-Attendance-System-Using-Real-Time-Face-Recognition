<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $studentRoutePrefix */
$studentRoutePrefix = $studentRoutePrefix ?? 'admin/students';

$pageTitle = 'Register Student';
$courses = list_active_courses();
$errors = [];
$form = [
    'username' => '',
    'email' => '',
    'password' => '',
    'registration_no' => '',
    'first_name' => '',
    'last_name' => '',
    'phone' => '',
    'date_of_birth' => '',
    'gender' => '',
    'course_id' => '',
    'batch_id' => '',
    'enrollment_date' => date('Y-m-d'),
    'status' => 'ACTIVE',
    'account_status' => 'ACTIVE',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($studentRoutePrefix . '/register.php');
    }

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    if ($form['username'] === '' || $form['email'] === '' || $form['password'] === '') {
        $errors[] = 'Username, email, and initial password are required.';
    }

    if ($form['registration_no'] === '' || $form['first_name'] === '' || $form['last_name'] === '') {
        $errors[] = 'Registration number, first name, and last name are required.';
    }

    if (!validate_email_address($form['email'])) {
        $errors[] = 'Enter a valid email address.';
    }

    if (strlen($form['password']) < 8) {
        $errors[] = 'Initial password must be at least 8 characters.';
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
            register_student([
                'username' => $form['username'],
                'email' => $form['email'],
                'password' => $form['password'],
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

            set_flash('success', 'Student registered successfully. Face enrollment is not completed yet.');
            redirect($studentRoutePrefix . '/index.php');
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Student registration failed: ' . $exception->getMessage());
            $errors[] = 'Unable to register the student. Please try again.';
        }
    }
}

$selectedCourseId = positive_int($form['course_id']);
$batches = $selectedCourseId !== null ? list_batches_for_course($selectedCourseId) : [];
$batchOptions = [];

foreach ($courses as $course) {
    $batchOptions[(string) $course['course_id']] = list_batches_for_course((int) $course['course_id']);
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($studentRoutePrefix . '/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Back to Students</a>
</div>

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
        <p class="app-required-note"><span class="app-required-note__mark" aria-hidden="true">*</span> <span class="visually-hidden">Asterisk means </span>Required</p>

        <div class="app-form-section">
            <h2 class="app-form-section__title">Account</h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="username" class="form-label app-required">Username</label>
                    <input type="text" class="form-control" id="username" name="username" value="<?= e($form['username']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="email" class="form-label app-required">Email</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?= e($form['email']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="password" class="form-label app-required">Initial Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
            </div>
        </div>

        <div class="app-form-section">
            <h2 class="app-form-section__title">Personal Details</h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="registration_no" class="form-label app-required">Registration Number</label>
                    <input type="text" class="form-control" id="registration_no" name="registration_no" value="<?= e($form['registration_no']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="first_name" class="form-label app-required">First Name</label>
                    <input type="text" class="form-control" id="first_name" name="first_name" value="<?= e($form['first_name']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="last_name" class="form-label app-required">Last Name</label>
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
            </div>
        </div>

        <div class="app-form-section">
            <h2 class="app-form-section__title">Academic Placement</h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="course_id" class="form-label app-required">Course</label>
                    <select class="form-select course-select" id="course_id" name="course_id" data-batch-target="batch_id" required>
                        <option value="">Select course</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?= e((string) $course['course_id']) ?>" <?= (string) $selectedCourseId === (string) $course['course_id'] ? 'selected' : '' ?>>
                                <?= e($course['course_code'] . ' - ' . $course['course_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="batch_id" class="form-label app-required">Batch</label>
                    <select class="form-select batch-select" id="batch_id" name="batch_id" required>
                        <option value="">Select batch</option>
                        <?php foreach ($batches as $batch): ?>
                            <option value="<?= e((string) $batch['batch_id']) ?>" <?= $form['batch_id'] === (string) $batch['batch_id'] ? 'selected' : '' ?>>
                                <?= e($batch['batch_name'] . ' (' . $batch['intake_year'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="enrollment_date" class="form-label app-required">Enrollment Date</label>
                    <input type="date" class="form-control" id="enrollment_date" name="enrollment_date" value="<?= e($form['enrollment_date']) ?>" required>
                </div>
            </div>
        </div>

        <div class="app-form-section">
            <h2 class="app-form-section__title">Status</h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="status" class="form-label">Student Status</label>
                    <select class="form-select" id="status" name="status">
                        <?php foreach (student_statuses() as $studentStatus): ?>
                            <option value="<?= e($studentStatus) ?>" <?= $form['status'] === $studentStatus ? 'selected' : '' ?>><?= e($studentStatus) ?></option>
                        <?php endforeach; ?>
                    </select>
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
        </div>

        <div class="app-form-actions">
            <button type="submit" class="btn btn-primary">Register Student</button>
        </div>
    </div>
</form>

<script type="application/json" id="batch-options-data"><?= json_encode($batchOptions, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
