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

$rows = list_assignment_submission_matrix((int) $assignment['assignment_id']);
$pageTitle = 'Submissions — ' . (string) $assignment['title'];

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($academicRoutePrefix . '/assignments/view.php?id=' . $assignment['assignment_id'])) ?>">&larr; Assignment details</a>
</div>

<p class="text-muted">
    Enrolled students in <?= e($assignment['module_code']) ?>. Due <?= e(format_assignment_datetime((string) $assignment['due_date'])) ?>.
</p>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-striped mb-0 align-middle">
            <thead>
                <tr>
                    <th>Registration No</th>
                    <th>Student</th>
                    <th>Submission Status</th>
                    <th>Submitted At</th>
                    <th>Late / On Time</th>
                    <th>Grade</th>
                    <th>File</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No enrolled students for this module.</td></tr>
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
                            <td class="text-nowrap">
                                <?php if ($row['submission_id']): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= e(assignment_download_url($academicRoutePrefix, 'submission', (int) $row['submission_id'])) ?>">Download</a>
                                    <?php if ($courseworkCanEdit): ?>
                                        <a class="btn btn-sm btn-primary" href="<?= e(assignment_grade_url($academicRoutePrefix, (int) $row['submission_id'])) ?>">
                                            <?= ($row['matrix_status'] ?? '') === 'GRADED' ? 'Edit Grade' : 'Grade' ?>
                                        </a>
                                    <?php else: ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assignment_grade_url($academicRoutePrefix, (int) $row['submission_id'])) ?>">View result</a>
                                    <?php endif; ?>
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
