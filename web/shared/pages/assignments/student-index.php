<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

$student = current_student_profile();
if ($student === null) {
    set_flash('error', 'No student profile is linked to this account.');
    redirect('student/dashboard.php');
}

$pageTitle = 'Coursework & Assessments';
$assignments = list_coursework_assignments_for_student((int) $student['student_id']);

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<p class="text-muted mb-4">Assignments, presentations, exams and practicals for modules you are enrolled in. Draft work is not shown.</p>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Title</th>
                    <th>Type</th>
                    <th>Module</th>
                    <th>Due / Scheduled</th>
                    <th>Max marks</th>
                    <th>Status</th>
                    <th>Grade / Result</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($assignments === []): ?>
                    <tr>
                        <td colspan="8" class="p-0">
                            <div class="app-empty-state">
                                <p class="app-empty-state__title mb-0">No coursework or assessments are currently available.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($assignments as $row): ?>
                        <?php
                        $requiresSubmission = assignment_requires_submission($row);
                        $showsSchedule = assignment_requires_schedule($row);
                        $canSubmit = $requiresSubmission
                            && student_can_submit_coursework_assignment((int) $student['student_id'], $row);
                        ?>
                        <tr>
                            <td><?= app_truncate_html((string) $row['title'], 'md') ?></td>
                            <td>
                                <span class="badge <?= e(assignment_activity_badge_class($row)) ?>">
                                    <?= e(assignment_activity_type_label($row)) ?>
                                </span>
                            </td>
                            <td><?= app_truncate_html($row['module_code'] . ' – ' . $row['module_name'], 'md') ?></td>
                            <td>
                                <?php if ($showsSchedule): ?>
                                    <?= e(format_assignment_schedule_summary($row)) ?>
                                <?php else: ?>
                                    Due <?= e(format_assignment_datetime((string) $row['due_date'])) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) $row['max_marks']) ?></td>
                            <td><span class="badge <?= e(status_badge_class((string) $row['student_display_status'])) ?>"><?= e((string) $row['student_display_status']) ?></span></td>
                            <td><?= e((string) $row['own_grade_display']) ?></td>
                            <td class="text-end">
                                <div class="app-actions">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= e(app_url('student/assignments/view.php?id=' . $row['assignment_id'])) ?>">
                                        <?= $requiresSubmission ? 'View' : 'View Details' ?>
                                    </a>
                                    <?php if ($canSubmit): ?>
                                        <a class="btn btn-sm btn-primary" href="<?= e(app_url('student/assignments/view.php?id=' . $row['assignment_id'])) ?>">Submit</a>
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
