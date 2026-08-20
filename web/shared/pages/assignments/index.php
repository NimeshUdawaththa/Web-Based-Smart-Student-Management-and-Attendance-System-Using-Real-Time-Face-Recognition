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

$user = current_user();
$role = $user['role'] ?? '';

if ($courseworkCanEdit) {
    if ($restrictLecturerId === null || $restrictLecturerId <= 0) {
        set_flash('error', 'No lecturer profile is linked to this account.');
        redirect($academicRoutePrefix . '/dashboard.php');
    }
    $pageTitle = 'Coursework & Assessments';
    $assignments = list_coursework_assignments_for_lecturer($restrictLecturerId);
} else {
    if (!staff_can_monitor_coursework()) {
        deny_access();
    }
    $pageTitle = 'Coursework Monitor';
    $assignments = list_coursework_assignments_for_monitor();
}

$colspan = $courseworkCanEdit ? 7 : 8;

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="app-list-toolbar">
    <p class="app-list-toolbar__desc">
        <?php if ($courseworkCanEdit): ?>
            Create and manage assignments, presentations, exams and practical assessments
            for your modules.
        <?php else: ?>
            Read-only view of coursework and assessments across modules.
        <?php endif; ?>
    </p>
    <?php if ($courseworkCanEdit): ?>
        <a href="<?= e(app_url($academicRoutePrefix . '/assignments/create.php')) ?>" class="btn btn-primary">Create Activity</a>
    <?php endif; ?>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Title</th>
                    <th>Type</th>
                    <th>Module</th>
                    <?php if (!$courseworkCanEdit): ?>
                        <th>Lecturer</th>
                    <?php endif; ?>
                    <th>Due / Scheduled</th>
                    <th>Max marks</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($assignments === []): ?>
                    <tr>
                        <td colspan="<?= (int) $colspan ?>" class="p-0">
                            <div class="app-empty-state">
                                <p class="app-empty-state__title mb-0">No coursework or assessments found.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($assignments as $row): ?>
                        <?php
                        $requiresSubmission = assignment_requires_submission($row);
                        $showsSchedule = assignment_requires_schedule($row);
                        $typeLabel = assignment_activity_type_label($row);
                        ?>
                        <tr>
                            <td><?= e((string) $row['title']) ?></td>
                            <td>
                                <span class="badge <?= e(assignment_activity_badge_class($row)) ?>"><?= e($typeLabel) ?></span>
                            </td>
                            <td><?= app_truncate_html((string) ($row['module_code'] . ' – ' . $row['module_name']), 'md') ?></td>
                            <?php if (!$courseworkCanEdit): ?>
                                <td><?= e($row['lecturer_first_name'] . ' ' . $row['lecturer_last_name']) ?></td>
                            <?php endif; ?>
                            <td>
                                <?php if ($showsSchedule): ?>
                                    <div><?= e(format_assignment_schedule_summary($row)) ?></div>
                                    <?php if (!empty($row['room'])): ?>
                                        <div class="small text-muted"><?= e((string) $row['room']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Due <?= e(format_assignment_datetime((string) $row['due_date'])) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) $row['max_marks']) ?></td>
                            <td><span class="badge <?= e(status_badge_class((string) $row['status'])) ?>"><?= e((string) $row['status']) ?></span></td>
                            <td class="text-end">
                                <div class="app-actions">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= e(app_url($academicRoutePrefix . '/assignments/view.php?id=' . $row['assignment_id'])) ?>">View</a>
                                    <?php if ($courseworkCanEdit): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($academicRoutePrefix . '/assignments/edit.php?id=' . $row['assignment_id'])) ?>">Edit</a>
                                    <?php endif; ?>
                                    <?php if ($requiresSubmission): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= e(app_url($academicRoutePrefix . '/assignments/submissions.php?id=' . $row['assignment_id'])) ?>">Submissions</a>
                                    <?php else: ?>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= e(app_url($academicRoutePrefix . '/assignments/results.php?id=' . $row['assignment_id'])) ?>">Results</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
