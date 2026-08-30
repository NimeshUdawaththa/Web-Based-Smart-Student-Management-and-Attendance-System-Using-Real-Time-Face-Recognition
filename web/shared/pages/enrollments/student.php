<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';
/** @var string $studentRoutePrefix */
$studentRoutePrefix = $studentRoutePrefix ?? $academicRoutePrefix . '/students';

$studentId = positive_int($_GET['id'] ?? $_POST['student_id'] ?? null);
if ($studentId === null) {
    set_flash('error', 'Student not found.');
    redirect($academicRoutePrefix . '/enrollments/index.php');
}

$student = get_student($studentId);
if ($student === null) {
    set_flash('error', 'Student not found.');
    redirect($academicRoutePrefix . '/enrollments/index.php');
}

$pageTitle = 'Module Enrollment – ' . $student['first_name'] . ' ' . $student['last_name'];
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/enrollments/student.php?id=' . $studentId);
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'enroll') {
            $moduleId = positive_int($_POST['module_id'] ?? null);
            if ($moduleId === null) {
                throw new InvalidArgumentException('Select a module.');
            }
            enroll_student_in_module($studentId, $moduleId);
            set_flash('success', 'Student enrolled in the module.');
        } elseif ($action === 'drop') {
            $enrolmentId = positive_int($_POST['student_module_id'] ?? null);
            if ($enrolmentId === null) {
                throw new InvalidArgumentException('Enrolment not found.');
            }
            drop_student_module($enrolmentId, $studentId);
            set_flash('success', 'Module enrolment dropped.');
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
        redirect($academicRoutePrefix . '/enrollments/student.php?id=' . $studentId);
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    }
}

$enrolments = list_student_module_enrolments($studentId);
$availableModules = list_modules([
    'course_id' => (int) $student['course_id'],
    'status' => 'ACTIVE',
    'course_module_status' => 'ACTIVE',
]);

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3 d-flex gap-2">
    <a href="<?= e(app_url($academicRoutePrefix . '/enrollments/index.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Back</a>
    <a href="<?= e(app_url($studentRoutePrefix . '/edit.php?id=' . $studentId)) ?>" class="btn btn-outline-secondary btn-sm">Student Record</a>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <strong><?= e($student['first_name'] . ' ' . $student['last_name']) ?></strong>
        · <?= e($student['registration_no']) ?>
        · <?= e($student['course_code'] . ' – ' . $student['batch_name']) ?>
        · <span class="badge <?= e(status_badge_class($student['status'])) ?>"><?= e($student['status']) ?></span>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Enrol in Module</h2></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="student_id" value="<?= e((string) $studentId) ?>">
            <input type="hidden" name="action" value="enroll">
            <div class="col-md-8">
                <label for="module_id" class="form-label">Module (same course only)</label>
                <select class="form-select" id="module_id" name="module_id" required>
                    <option value="">Select module</option>
                    <?php foreach ($availableModules as $module): ?>
                        <option value="<?= e((string) $module['module_id']) ?>">
                            <?= e($module['module_code'] . ' – ' . $module['module_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary">Enrol</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Module</th>
                    <th>Course</th>
                    <th>Enrolled At</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($enrolments === []): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No module enrolments yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($enrolments as $enrolment): ?>
                        <tr>
                            <td><?= e($enrolment['module_code'] . ' – ' . $enrolment['module_name']) ?></td>
                            <td><?= e($enrolment['course_code']) ?></td>
                            <td><?= e((string) $enrolment['enrolled_at']) ?></td>
                            <td><span class="badge <?= e(status_badge_class($enrolment['status'])) ?>"><?= e($enrolment['status']) ?></span></td>
                            <td class="text-end">
                                <?php if ($enrolment['status'] !== 'DROPPED'): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Drop this module enrolment?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="student_id" value="<?= e((string) $studentId) ?>">
                                        <input type="hidden" name="action" value="drop">
                                        <input type="hidden" name="student_module_id" value="<?= e((string) $enrolment['student_module_id']) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Drop</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
