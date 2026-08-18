<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';

$pageTitle = 'Generate Lecture Sessions';
$errors = [];
$form = [
    'week_date' => app_today(),
    'late_after_minutes' => '15',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/sessions/generate.php');
    }

    $form['week_date'] = trim((string) ($_POST['week_date'] ?? ''));
    $form['late_after_minutes'] = trim((string) ($_POST['late_after_minutes'] ?? '15'));

    try {
        $result = generate_sessions_for_week($form['week_date'], (int) $form['late_after_minutes']);
        $monday = monday_of_week($form['week_date']);
        set_flash(
            'success',
            sprintf(
                'Generated sessions for the week starting %s: %d created, %d skipped (already existed or invalid).',
                $monday ?? $form['week_date'],
                $result['created'],
                $result['skipped']
            )
        );
        redirect($academicRoutePrefix . '/sessions/index.php?view=upcoming');
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    }
}

$monday = monday_of_week($form['week_date']) ?? $form['week_date'];
$activeSchedules = list_schedules(['status' => 'ACTIVE']);

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($academicRoutePrefix . '/sessions/index.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to Sessions</a>
</div>

<p class="text-muted">Creates dated lecture sessions from every <strong>ACTIVE</strong> timetable entry for the selected week. Existing sessions are skipped using the unique module/batch/date/start-time rule.</p>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post" class="card shadow-sm mb-4">
    <div class="card-body row g-3">
        <?= csrf_field() ?>
        <div class="col-md-4">
            <label for="week_date" class="form-label">Any date in the week</label>
            <input type="date" class="form-control" id="week_date" name="week_date" value="<?= e($form['week_date']) ?>" required>
            <div class="form-text">Week starts Monday <?= e($monday) ?>.</div>
        </div>
        <div class="col-md-4">
            <label for="late_after_minutes" class="form-label">Late after (minutes)</label>
            <input type="number" min="0" max="180" class="form-control" id="late_after_minutes" name="late_after_minutes" value="<?= e($form['late_after_minutes']) ?>" required>
            <div class="form-text">Copied onto each generated session. Not used for attendance yet.</div>
        </div>
        <div class="col-md-4 d-flex align-items-end">
            <button type="submit" class="btn btn-primary">Generate Sessions</button>
        </div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Active timetable entries (<?= count($activeSchedules) ?>)</h2></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>Day</th>
                    <th>Time</th>
                    <th>Module</th>
                    <th>Batch</th>
                    <th>Lecturer</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($activeSchedules === []): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">No active timetable entries. Create one first.</td></tr>
                <?php else: ?>
                    <?php foreach ($activeSchedules as $schedule): ?>
                        <tr>
                            <td><?= e(ucfirst(strtolower($schedule['day_of_week']))) ?></td>
                            <td><?= e(format_time_display($schedule['start_time']) . ' – ' . format_time_display($schedule['end_time'])) ?></td>
                            <td><?= e($schedule['module_code']) ?></td>
                            <td><?= e($schedule['batch_name']) ?></td>
                            <td><?= e($schedule['lecturer_first_name'] . ' ' . $schedule['lecturer_last_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
