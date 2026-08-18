<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';
/** @var bool $canManage */
$canManage = $canManage ?? can_manage_academic();
/** @var int|null $restrictLecturerId */
$restrictLecturerId = $restrictLecturerId ?? null;

$pageTitle = $restrictLecturerId ? 'My Lecture Sessions' : 'Lecture Sessions';
$today = app_today();
$view = (string) ($_GET['view'] ?? 'upcoming');
$status = (string) ($_GET['status'] ?? '');
$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');

$filters = array_filter([
    'lecturer_id' => $restrictLecturerId,
    'status' => in_array($status, lecture_session_statuses(), true) ? $status : null,
]);

if ($view === 'today') {
    $filters['from'] = $today;
    $filters['to'] = $today;
} elseif ($view === 'upcoming' && $status === '') {
    $filters['upcoming'] = true;
} else {
    if (validate_date_ymd($from)) {
        $filters['from'] = $from;
    }
    if (validate_date_ymd($to)) {
        $filters['to'] = $to;
    }
}

$sessions = list_lecture_sessions($filters);
$activeSessions = get_in_progress_sessions($restrictLecturerId);

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <p class="text-muted mb-0">Statuses update from the timetable when this page is opened. Start, Stop, and Cancel remain available as manual overrides.</p>
    <?php if ($canManage): ?>
        <div class="d-flex gap-2">
            <a href="<?= e(app_url($academicRoutePrefix . '/sessions/generate.php')) ?>" class="btn btn-outline-primary">Generate This Week</a>
            <a href="<?= e(app_url($academicRoutePrefix . '/sessions/create.php')) ?>" class="btn btn-primary">Create Session</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($activeSessions !== []): ?>
    <div class="alert alert-success">
        <strong>Active session<?= count($activeSessions) === 1 ? '' : 's' ?>:</strong>
        <?php foreach ($activeSessions as $active): ?>
            <a href="<?= e(app_url($academicRoutePrefix . '/sessions/view.php?id=' . $active['session_id'])) ?>" class="alert-link">
                <?= e($active['module_code'] . ' · ' . $active['batch_name'] . ' · ' . format_time_display($active['scheduled_start'])) ?>
            </a><?= $active !== end($activeSessions) ? '; ' : '' ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="mb-3 d-flex gap-2 flex-wrap">
    <a href="<?= e(app_url($academicRoutePrefix . '/sessions/index.php?view=today')) ?>" class="btn btn-sm <?= $view === 'today' ? 'btn-primary' : 'btn-outline-primary' ?>">Today</a>
    <a href="<?= e(app_url($academicRoutePrefix . '/sessions/index.php?view=upcoming')) ?>" class="btn btn-sm <?= $view === 'upcoming' ? 'btn-primary' : 'btn-outline-primary' ?>">Upcoming</a>
    <a href="<?= e(app_url($academicRoutePrefix . '/sessions/index.php?view=all')) ?>" class="btn btn-sm <?= $view === 'all' ? 'btn-primary' : 'btn-outline-primary' ?>">All</a>
</div>

<form method="get" class="card shadow-sm mb-4">
    <div class="card-body row g-3">
        <input type="hidden" name="view" value="all">
        <div class="col-md-3">
            <label for="from" class="form-label">From</label>
            <input type="date" class="form-control" id="from" name="from" value="<?= e($from) ?>">
        </div>
        <div class="col-md-3">
            <label for="to" class="form-label">To</label>
            <input type="date" class="form-control" id="to" name="to" value="<?= e($to) ?>">
        </div>
        <div class="col-md-3">
            <label for="status" class="form-label">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All statuses</option>
                <?php foreach (lecture_session_statuses() as $sessionStatus): ?>
                    <option value="<?= e($sessionStatus) ?>" <?= $status === $sessionStatus ? 'selected' : '' ?>><?= e($sessionStatus) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex align-items-end">
            <button type="submit" class="btn btn-outline-primary">Filter</button>
        </div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Module</th>
                    <th>Batch</th>
                    <th>Lecturer</th>
                    <th>Room</th>
                    <th>Late after</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($sessions === []): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No lecture sessions found.</td></tr>
                <?php else: ?>
                    <?php foreach ($sessions as $session): ?>
                        <tr>
                            <td><?= e($session['session_date']) ?></td>
                            <td><?= e(format_time_display($session['scheduled_start']) . ' – ' . format_time_display($session['scheduled_end'])) ?></td>
                            <td><?= e($session['module_code']) ?></td>
                            <td><?= e($session['batch_name']) ?></td>
                            <td><?= e($session['lecturer_first_name'] . ' ' . $session['lecturer_last_name']) ?></td>
                            <td><?= e($session['room'] ?: '-') ?></td>
                            <td><?= e((string) $session['late_after_minutes']) ?> min</td>
                            <td>
                                <span class="badge <?= e(status_badge_class($session['status'])) ?>"><?= e($session['status']) ?></span>
                                <?php $hint = session_lifecycle_hint($session); ?>
                                <?php if ($hint !== ''): ?>
                                    <div class="small text-muted"><?= e($hint) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="<?= e(app_url($academicRoutePrefix . '/sessions/view.php?id=' . $session['session_id'])) ?>" class="btn btn-sm btn-outline-primary">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
