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
    set_flash('error', 'Activity not found.');
    redirect($academicRoutePrefix . '/assignments/index.php');
}

if ($courseworkCanEdit) {
    if ($restrictLecturerId === null || !lecturer_can_manage_coursework_assignment($restrictLecturerId, $assignment)) {
        deny_access();
    }
} elseif (!staff_can_monitor_coursework()) {
    deny_access();
}

$requiresSubmission = assignment_requires_submission($assignment);
$showsSchedule = assignment_requires_schedule($assignment);
$typeLabel = assignment_activity_type_label($assignment);
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
                <span class="badge <?= e(assignment_activity_badge_class($assignment)) ?> me-1"><?= e($typeLabel) ?></span>
                <span class="badge <?= e(status_badge_class((string) $assignment['status'])) ?>"><?= e((string) $assignment['status']) ?></span>
            </div>
            <div class="app-actions">
                <?php if ($courseworkCanEdit): ?>
                    <a class="btn btn-outline-secondary btn-sm" href="<?= e(app_url($academicRoutePrefix . '/assignments/edit.php?id=' . $assignment['assignment_id'])) ?>">Edit</a>
                <?php endif; ?>
                <?php if ($requiresSubmission): ?>
                    <a class="btn btn-primary btn-sm" href="<?= e(app_url($academicRoutePrefix . '/assignments/submissions.php?id=' . $assignment['assignment_id'])) ?>">Submissions</a>
                <?php else: ?>
                    <a class="btn btn-primary btn-sm" href="<?= e(app_url($academicRoutePrefix . '/assignments/results.php?id=' . $assignment['assignment_id'])) ?>">Results</a>
                <?php endif; ?>
            </div>
        </div>

        <p class="mb-2"><strong>Type:</strong> <?= e($typeLabel) ?></p>
        <p class="mb-2"><strong>Maximum marks:</strong> <?= e((string) $assignment['max_marks']) ?></p>
        <p class="mb-2"><strong>Lecturer:</strong> <?= e($assignment['lecturer_first_name'] . ' ' . $assignment['lecturer_last_name']) ?></p>

        <?php if ($showsSchedule): ?>
            <p class="mb-2"><strong><?= $typeLabel === 'Presentation' ? 'Presentation schedule' : 'Scheduled' ?>:</strong> <?= e(format_assignment_schedule_summary($assignment)) ?></p>
            <p class="mb-2"><strong>Room / Location:</strong> <?= e((string) ($assignment['room'] ?? '') !== '' ? (string) $assignment['room'] : '—') ?></p>
        <?php else: ?>
            <p class="mb-2"><strong>Due:</strong> <?= e(format_assignment_datetime((string) $assignment['due_date'])) ?></p>
        <?php endif; ?>

        <div class="mb-3">
            <strong><?= $requiresSubmission ? 'Instructions' : 'Instructions / Details' ?></strong>
            <div class="border rounded p-3 bg-light mt-1"><?= nl2br(e((string) ($assignment['description'] ?? ''))) ?: '<span class="text-muted">No instructions.</span>' ?></div>
        </div>

        <?php if ($requiresSubmission): ?>
            <?php if (!empty($assignment['file_path'])): ?>
                <a class="btn btn-outline-primary btn-sm" href="<?= e(assignment_download_url($academicRoutePrefix, 'brief', (int) $assignment['assignment_id'])) ?>">Download brief</a>
            <?php else: ?>
                <p class="text-muted mb-0">No brief attachment.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
