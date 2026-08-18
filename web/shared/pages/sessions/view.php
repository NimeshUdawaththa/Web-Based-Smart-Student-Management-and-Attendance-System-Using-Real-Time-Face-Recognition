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

$sessionId = positive_int($_GET['id'] ?? $_POST['session_id'] ?? null);
if ($sessionId === null) {
    set_flash('error', 'Lecture session not found.');
    redirect($academicRoutePrefix . '/sessions/index.php');
}

$session = get_lecture_session($sessionId);
if ($session === null) {
    set_flash('error', 'Lecture session not found.');
    redirect($academicRoutePrefix . '/sessions/index.php');
}

if ($restrictLecturerId !== null && (int) $session['lecturer_id'] !== $restrictLecturerId) {
    set_flash('error', 'You can only view your own lecture sessions.');
    redirect($academicRoutePrefix . '/sessions/index.php');
}

$pageTitle = $session['module_code'] . ' – ' . $session['session_date'];
$canControl = user_can_control_session($session);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/sessions/view.php?id=' . $sessionId);
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'start') {
            start_lecture_session($sessionId);
            set_flash('success', 'Lecture session started. Status is IN_PROGRESS.');
        } elseif ($action === 'complete') {
            complete_lecture_session($sessionId);
            set_flash('success', 'Lecture session completed.');
        } elseif ($action === 'cancel') {
            cancel_lecture_session($sessionId);
            set_flash('success', 'Lecture session cancelled.');
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
        redirect($academicRoutePrefix . '/sessions/view.php?id=' . $sessionId);
    } catch (InvalidArgumentException $exception) {
        set_flash('error', $exception->getMessage());
        redirect($academicRoutePrefix . '/sessions/view.php?id=' . $sessionId);
    }
}

$eligibleStudents = list_eligible_students_for_session($sessionId);
$lateThreshold = late_threshold_time((string) $session['scheduled_start'], (int) $session['late_after_minutes']);

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($academicRoutePrefix . '/sessions/index.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to Sessions</a>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h2 class="h5 mb-1"><?= e($session['module_code'] . ' – ' . $session['module_name']) ?></h2>
                <p class="text-muted mb-2">
                    <?= e($session['course_code'] . ' · ' . $session['batch_name']) ?><br>
                    <?= e($session['session_date']) ?>
                    · <?= e(format_time_display($session['scheduled_start']) . ' – ' . format_time_display($session['scheduled_end'])) ?>
                    · Room <?= e($session['room'] ?: '-') ?><br>
                    Lecturer: <?= e($session['lecturer_first_name'] . ' ' . $session['lecturer_last_name']) ?>
                </p>
                <span class="badge <?= e(status_badge_class($session['status'])) ?>"><?= e($session['status']) ?></span>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <?php if ($canControl && $session['status'] === 'SCHEDULED'): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="session_id" value="<?= e((string) $sessionId) ?>">
                        <input type="hidden" name="action" value="start">
                        <button type="submit" class="btn btn-success">Start Session</button>
                    </form>
                <?php endif; ?>
                <?php if ($canControl && $session['status'] === 'IN_PROGRESS'): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="session_id" value="<?= e((string) $sessionId) ?>">
                        <input type="hidden" name="action" value="complete">
                        <button type="submit" class="btn btn-primary">Stop / Complete</button>
                    </form>
                <?php endif; ?>
                <?php if ($canManage && in_array($session['status'], ['SCHEDULED', 'IN_PROGRESS'], true)): ?>
                    <form method="post" onsubmit="return confirm('Cancel this lecture session?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="session_id" value="<?= e((string) $sessionId) ?>">
                        <input type="hidden" name="action" value="cancel">
                        <button type="submit" class="btn btn-outline-danger">Cancel Session</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <hr>
        <div class="row small">
            <div class="col-md-3"><strong>Actual start</strong><br><?= e($session['actual_start'] ?: 'Not started') ?></div>
            <div class="col-md-3"><strong>Actual end</strong><br><?= e($session['actual_end'] ?: 'Not ended') ?></div>
            <div class="col-md-3"><strong>Late after</strong><br><?= e((string) $session['late_after_minutes']) ?> minutes</div>
            <div class="col-md-3"><strong>Late threshold</strong><br><?= e($lateThreshold) ?> <span class="text-muted">(not applied yet)</span></div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Eligible Students</h2>
        <span class="badge text-bg-secondary"><?= count($eligibleStudents) ?></span>
    </div>
    <div class="card-body py-2">
        <p class="text-muted small mb-0">
            Students who are ACTIVE, belong to this batch, and are ENROLLED in <?= e($session['module_code']) ?>.
            Later, face recognition may identify other people, but attendance will only be processed for this list.
        </p>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Registration No</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Enrolment</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($eligibleStudents === []): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No eligible students. Enrol the batch in this module first.</td></tr>
                <?php else: ?>
                    <?php foreach ($eligibleStudents as $student): ?>
                        <tr>
                            <td><?= e($student['registration_no']) ?></td>
                            <td><?= e($student['first_name'] . ' ' . $student['last_name']) ?></td>
                            <td><span class="badge <?= e(status_badge_class($student['status'])) ?>"><?= e($student['status']) ?></span></td>
                            <td><span class="badge <?= e(status_badge_class($student['enrolment_status'])) ?>"><?= e($student['enrolment_status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
