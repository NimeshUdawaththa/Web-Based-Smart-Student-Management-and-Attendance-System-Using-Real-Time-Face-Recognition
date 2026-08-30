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

if (!assignment_requires_submission($assignment)) {
    set_flash('error', 'Submissions are only available for Assignment and Presentation activities. Use Results for this activity.');
    redirect($academicRoutePrefix . '/assignments/results.php?id=' . $assignment['assignment_id']);
}

$rows = list_assignment_submission_matrix((int) $assignment['assignment_id']);
$typeLabel = assignment_activity_type_label($assignment);
$pageTitle = 'Submissions — ' . (string) $assignment['title'];

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($academicRoutePrefix . '/assignments/view.php?id=' . $assignment['assignment_id'])) ?>">&larr; Activity details</a>
</div>

<p class="text-muted">
    <?= e($typeLabel) ?> for enrolled students in <?= e($assignment['module_code']) ?>.
    <?php if (assignment_requires_schedule($assignment)): ?>
        Schedule <?= e(format_assignment_schedule_summary($assignment)) ?>.
        Supporting files may be submitted until the scheduled end time.
    <?php else: ?>
        Due <?= e(format_assignment_datetime((string) $assignment['due_date'])) ?>.
    <?php endif; ?>
</p>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Registration No</th>
                    <th>Student</th>
                    <th>Submission Status</th>
                    <th>Submitted At</th>
                    <th>Late / On Time</th>
                    <th>Grade</th>
                    <th>File</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="8" class="p-0">
                            <div class="app-empty-state">
                                <p class="app-empty-state__title mb-0">No enrolled students for this module.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e((string) $row['registration_no']) ?></td>
                            <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                            <td><span class="badge <?= e(status_badge_class((string) $row['matrix_status'])) ?>"><?= e((string) $row['matrix_status']) ?></span></td>
                            <td><?= $row['submitted_at'] ? e(format_assignment_datetime((string) $row['submitted_at'])) : '—' ?></td>
                            <td><?= e((string) $row['timing']) ?></td>
                            <td><?= e((string) $row['grade_display']) ?></td>
                            <td><?= $row['submission_id'] ? 'Attached' : '—' ?></td>
                            <td class="text-end">
                                <?php if ($row['submission_id']): ?>
                                    <div class="app-actions">
                                        <a class="btn btn-sm btn-outline-primary" href="<?= e(assignment_download_url($academicRoutePrefix, 'submission', (int) $row['submission_id'])) ?>">Download</a>
                                        <?php if ($courseworkCanEdit): ?>
                                            <a class="btn btn-sm btn-primary" href="<?= e(assignment_grade_url($academicRoutePrefix, (int) $row['submission_id'])) ?>">
                                                <?= ($row['matrix_status'] ?? '') === 'GRADED' ? 'Edit Grade' : 'Grade' ?>
                                            </a>
                                        <?php else: ?>
                                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(assignment_grade_url($academicRoutePrefix, (int) $row['submission_id'])) ?>">View result</a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    —
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
