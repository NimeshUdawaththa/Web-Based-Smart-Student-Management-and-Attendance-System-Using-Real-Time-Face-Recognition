<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $studentRoutePrefix */
$studentRoutePrefix = $studentRoutePrefix ?? 'admin/students';

$studentId = positive_int($_GET['id'] ?? null);
if ($studentId === null) {
    set_flash('error', 'Student not found.');
    redirect($studentRoutePrefix . '/index.php');
}

$student = get_student($studentId);
if ($student === null) {
    set_flash('error', 'Student not found.');
    redirect($studentRoutePrefix . '/index.php');
}

$pageTitle = 'Face Enrollment Placeholder';

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($studentRoutePrefix . '/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Back to Students</a>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5">Face Enrollment Module</h2>
        <p class="text-muted">
            Student: <strong><?= e($student['first_name'] . ' ' . $student['last_name']) ?></strong>
            (<?= e($student['registration_no']) ?>)
        </p>
        <p>
            Current face status:
            <span class="badge <?= e(status_badge_class($student['face_status'])) ?>"><?= e($student['face_status']) ?></span>
        </p>
        <div class="alert alert-warning mb-0">
            The Face Enrollment module will be implemented in a later development stage.
            No camera access, face capture, or AI processing is available yet.
        </div>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
