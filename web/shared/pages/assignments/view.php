<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'lecturer';
/** @var bool $courseworkCanEdit */
$courseworkCanEdit = $courseworkCanEdit ?? false;
/** @var int|null $restrictLecturerId */
$restrictLecturerId = $restrictLecturerId ?? null;

$assignmentId = positive_int($_GET['id'] ?? null);
$assignment = $assignmentId !== null ? get_coursework_assignment($assignmentId) : null;

if ($assignment === null) {
    set_flash('error', 'Assignment not found.');
    redirect($academicRoutePrefix . '/assignments/index.php');
}

if ($courseworkCanEdit) {
    if ($restrictLecturerId === null || !lecturer_can_manage_coursework_assignment($restrictLecturerId, $assignment)) {
        deny_access();
    }
} elseif (!staff_can_monitor_coursework()) {
    deny_access();
}

$pageTitle = (string) $assignment['title'];
require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($academicRoutePrefix . '/assignments/index.php')) ?>">&larr; Back to list</a>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <p class="text-muted mb-1"><?= e($assignment['module_code'] . ' – ' . $assignment['module_name']) ?></p>
                <span class="badge <?= e(status_badge_class((string) $assignment['status'])) ?>"><?= e((string) $assignment['status']) ?></span>
            </div>
            <div>
                <?php if ($courseworkCanEdit): ?>
                    <a class="btn btn-outline-secondary btn-sm" href="<?= e(app_url($academicRoutePrefix . '/assignments/edit.php?id=' . $assignment['assignment_id'])) ?>">Edit</a>
                <?php endif; ?>
                <a class="btn btn-primary btn-sm" href="<?= e(app_url($academicRoutePrefix . '/assignments/submissions.php?id=' . $assignment['assignment_id'])) ?>">Submissions</a>
            </div>
        </div>
        <p class="mb-2"><strong>Due:</strong> <?= e(format_assignment_datetime((string) $assignment['due_date'])) ?></p>
        <p class="mb-2"><strong>Max marks:</strong> <?= e((string) $assignment['max_marks']) ?></p>
        <p class="mb-2"><strong>Lecturer:</strong> <?= e($assignment['lecturer_first_name'] . ' ' . $assignment['lecturer_last_name']) ?></p>
        <div class="mb-3">
            <strong>Instructions</strong>
            <div class="border rounded p-3 bg-light mt-1"><?= nl2br(e((string) ($assignment['description'] ?? ''))) ?: '<span class="text-muted">No instructions.</span>' ?></div>
        </div>
        <?php if (!empty($assignment['file_path'])): ?>
            <a class="btn btn-outline-primary btn-sm" href="<?= e(assignment_download_url($academicRoutePrefix, 'brief', (int) $assignment['assignment_id'])) ?>">Download attachment</a>
        <?php else: ?>
            <p class="text-muted mb-0">No lecturer attachment.</p>
        <?php endif; ?>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
