<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $studentRoutePrefix */
$studentRoutePrefix = $studentRoutePrefix ?? 'lecturer/students';
/** @var int|null $restrictLecturerId */
$restrictLecturerId = $restrictLecturerId ?? null;

if ($restrictLecturerId === null || $restrictLecturerId <= 0) {
    set_flash('error', 'No lecturer profile is linked to this account.');
    redirect('lecturer/dashboard.php');
}

$studentId = positive_int($_GET['id'] ?? null);
if ($studentId === null) {
    set_flash('error', 'Student not found.');
    redirect($studentRoutePrefix . '/index.php');
}

if (!lecturer_can_view_student($restrictLecturerId, $studentId)) {
    set_flash('error', 'You can only view students enrolled in modules assigned to you.');
    redirect($studentRoutePrefix . '/index.php');
}

$student = get_student($studentId);
if ($student === null) {
    set_flash('error', 'Student not found.');
    redirect($studentRoutePrefix . '/index.php');
}

$sharedModules = list_shared_enrolled_modules_for_lecturer_student($restrictLecturerId, $studentId);
$pageTitle = $student['registration_no'] . ' – ' . $student['first_name'] . ' ' . $student['last_name'];

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <p class="text-muted mb-0">Read-only student profile for modules you teach.</p>
    <a href="<?= e(app_url($studentRoutePrefix . '/index.php')) ?>" class="btn btn-outline-secondary">Back to directory</a>
</div>

<div class="d-flex align-items-center gap-3 mb-4">
    <?= render_student_profile_avatar($student, 'md') ?>
    <div>
        <div class="fw-semibold"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></div>
        <div class="small text-muted"><?= e((string) $student['registration_no']) ?></div>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Registration No</dt>
            <dd class="col-sm-9"><?= e((string) $student['registration_no']) ?></dd>
            <dt class="col-sm-3">Full Name</dt>
            <dd class="col-sm-9"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></dd>
            <dt class="col-sm-3">Email</dt>
            <dd class="col-sm-9"><?= e((string) $student['email']) ?></dd>
            <dt class="col-sm-3">Course</dt>
            <dd class="col-sm-9"><?= e($student['course_code'] . ' – ' . $student['course_name']) ?></dd>
            <dt class="col-sm-3">Batch</dt>
            <dd class="col-sm-9"><?= e((string) $student['batch_name']) ?></dd>
            <dt class="col-sm-3">Status</dt>
            <dd class="col-sm-9"><span class="badge <?= e(status_badge_class((string) $student['status'])) ?>"><?= e((string) $student['status']) ?></span></dd>
            <dt class="col-sm-3">Face</dt>
            <dd class="col-sm-9"><span class="badge <?= e(status_badge_class((string) $student['face_status'])) ?>"><?= e((string) $student['face_status']) ?></span></dd>
        </dl>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Shared module enrolments</h2>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Module</th>
                    <th>Name</th>
                    <th>Semester</th>
                    <th>Status</th>
                    <th>Enrolled At</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($sharedModules === []): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No shared ENROLLED modules.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($sharedModules as $module): ?>
                        <tr>
                            <td><?= e((string) $module['module_code']) ?></td>
                            <td><?= e((string) $module['module_name']) ?></td>
                            <td><?= e((string) ($module['semester'] ?? '-')) ?></td>
                            <td><span class="badge <?= e(status_badge_class((string) $module['status'])) ?>"><?= e((string) $module['status']) ?></span></td>
                            <td><?= e((string) ($module['enrolled_at'] ?? '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
