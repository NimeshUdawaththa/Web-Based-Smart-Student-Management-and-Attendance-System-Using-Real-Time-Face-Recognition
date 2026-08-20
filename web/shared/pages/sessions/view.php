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
            set_flash('success', 'Lecture session completed. Final attendance has been processed.');
        } elseif ($action === 'finalize_attendance') {
            $stats = finalize_session_attendance_authorized($sessionId);
            set_flash('success', 'Attendance recalculated for ' . $stats['finalized'] . ' student(s).');
        } elseif ($action === 'cancel') {
            cancel_lecture_session($sessionId);
            set_flash('success', 'Lecture session cancelled.');
        } elseif ($action === 'update_break') {
            update_lecture_session_break(
                $sessionId,
                (string) ($_POST['break_start'] ?? ''),
                (string) ($_POST['break_end'] ?? '')
            );
            set_flash('success', 'Official break updated for this session.');
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
        redirect($academicRoutePrefix . '/sessions/view.php?id=' . $sessionId);
    } catch (InvalidArgumentException $exception) {
        set_flash('error', $exception->getMessage());
        redirect($academicRoutePrefix . '/sessions/view.php?id=' . $sessionId);
    }
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
$lifecycleNote = session_lifecycle_note($session);

$eligibleStudents = list_eligible_students_for_session($sessionId, $session['status'] === 'COMPLETED');
$lateThreshold = late_threshold_time((string) $session['scheduled_start'], (int) $session['late_after_minutes']);
$sessionEvents = list_session_attendance_events($sessionId);
$sessionPending = list_session_early_pending($sessionId);
$finalRecords = $session['status'] === 'COMPLETED' ? list_session_attendance_records($sessionId) : [];
$attendancePreviews = [];
if ($session['status'] !== 'COMPLETED') {
    foreach ($eligibleStudents as $student) {
        $attendancePreviews[] = calculate_session_attendance_preview($session, (int) $student['student_id']) + [
            'registration_no' => $student['registration_no'],
            'first_name' => $student['first_name'],
            'last_name' => $student['last_name'],
        ];
    }
}
$breakStartValue = optional_time_hm(isset($session['break_start']) ? (string) $session['break_start'] : null) ?? '';
$breakEndValue = optional_time_hm(isset($session['break_end']) ? (string) $session['break_end'] : null) ?? '';

$sessionStatus = (string) $session['status'];
$presentCount = 0;
$lateCount = 0;
$absentCount = 0;
if ($sessionStatus === 'COMPLETED') {
    foreach ($finalRecords as $record) {
        $recordStatus = strtoupper((string) ($record['status'] ?? ''));
        if ($recordStatus === 'PRESENT') {
            $presentCount++;
        } elseif ($recordStatus === 'LATE') {
            $lateCount++;
        } elseif ($recordStatus === 'ABSENT') {
            $absentCount++;
        }
    }
}
$pendingInsideCount = 0;
foreach ($sessionPending as $pending) {
    if ((int) ($pending['inside'] ?? 0) === 1) {
        $pendingInsideCount++;
    }
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($academicRoutePrefix . '/sessions/index.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to Sessions</a>
</div>

<div class="app-workflow-steps" aria-label="Session workflow">
    <?php if ($sessionStatus === 'CANCELLED'): ?>
        <span class="app-workflow-steps__item is-cancelled">Cancelled</span>
    <?php else: ?>
        <span class="app-workflow-steps__item <?= $sessionStatus === 'SCHEDULED' ? 'is-current' : 'is-done' ?>">Scheduled</span>
        <span class="app-workflow-steps__sep" aria-hidden="true">→</span>
        <span class="app-workflow-steps__item <?= $sessionStatus === 'IN_PROGRESS' ? 'is-current' : ($sessionStatus === 'COMPLETED' ? 'is-done' : '') ?>">In Progress</span>
        <span class="app-workflow-steps__sep" aria-hidden="true">→</span>
        <span class="app-workflow-steps__item <?= $sessionStatus === 'COMPLETED' ? 'is-current' : '' ?>">Completed</span>
    <?php endif; ?>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
            <div>
                <h2 class="h5 mb-1"><?= e($session['module_code'] . ' – ' . $session['module_name']) ?></h2>
                <?php if ($lifecycleNote !== ''): ?>
                    <p class="small text-muted mb-0"><?= e($lifecycleNote) ?></p>
                <?php endif; ?>
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
                <?php if ($canControl && $session['status'] === 'COMPLETED'): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="session_id" value="<?= e((string) $sessionId) ?>">
                        <input type="hidden" name="action" value="finalize_attendance">
                        <button type="submit" class="btn btn-outline-primary">Recalculate / Finalize Attendance</button>
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
        <dl class="app-session-summary-grid mb-3">
            <div>
                <dt>Module</dt>
                <dd><?= e($session['module_code'] . ' – ' . $session['module_name']) ?></dd>
            </div>
            <div>
                <dt>Batch</dt>
                <dd><?= e($session['course_code'] . ' · ' . $session['batch_name']) ?></dd>
            </div>
            <div>
                <dt>Lecturer</dt>
                <dd><?= e($session['lecturer_first_name'] . ' ' . $session['lecturer_last_name']) ?></dd>
            </div>
            <div>
                <dt>Date</dt>
                <dd><?= e((string) $session['session_date']) ?></dd>
            </div>
            <div>
                <dt>Start – End</dt>
                <dd><?= e(format_time_display($session['scheduled_start']) . ' – ' . format_time_display($session['scheduled_end'])) ?></dd>
            </div>
            <div>
                <dt>Room</dt>
                <dd><?= e($session['room'] ?: '—') ?></dd>
            </div>
            <div>
                <dt>Status</dt>
                <dd><span class="badge <?= e(status_badge_class($session['status'])) ?>"><?= e($session['status']) ?></span></dd>
            </div>
        </dl>
        <hr>
        <div class="row small">
            <div class="col-md-3"><strong>Actual start</strong><br><?= e($session['actual_start'] ?: 'Not started') ?></div>
            <div class="col-md-3"><strong>Actual end</strong><br><?= e($session['actual_end'] ?: 'Not ended') ?></div>
            <div class="col-md-3"><strong>Late after</strong><br><?= e((string) $session['late_after_minutes']) ?> minutes (threshold <?= e($lateThreshold) ?>)</div>
            <div class="col-md-3"><strong>Official break</strong><br><?= e(format_break_display($session['break_start'] ?? null, $session['break_end'] ?? null)) ?></div>
        </div>
    </div>
</div>

<?php if ($canControl && !in_array($session['status'], ['COMPLETED', 'CANCELLED'], true)): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6">Official break for this session</h2>
            <p class="small text-muted">Inherited from the timetable when generated. You may override it for this occurrence only.</p>
            <form method="post" class="row g-3 align-items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="session_id" value="<?= e((string) $sessionId) ?>">
                <input type="hidden" name="action" value="update_break">
                <div class="col-md-3">
                    <label for="break_start" class="form-label">Break start</label>
                    <input type="time" class="form-control" id="break_start" name="break_start" value="<?= e($breakStartValue) ?>">
                </div>
                <div class="col-md-3">
                    <label for="break_end" class="form-label">Break end</label>
                    <input type="time" class="form-control" id="break_end" name="break_end" value="<?= e($breakEndValue) ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-outline-primary">Save break</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="metric-card">
            <span class="metric-card__label">Eligible</span>
            <span class="metric-card__value"><?= count($eligibleStudents) ?></span>
        </div>
    </div>
    <?php if ($sessionStatus === 'COMPLETED'): ?>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <span class="metric-card__label">Present</span>
                <span class="metric-card__value"><?= $presentCount ?></span>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <span class="metric-card__label">Late</span>
                <span class="metric-card__value"><?= $lateCount ?></span>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <span class="metric-card__label">Absent</span>
                <span class="metric-card__value"><?= $absentCount ?></span>
            </div>
        </div>
    <?php elseif ($canControl || $canManage): ?>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <span class="metric-card__label">Pending Inside</span>
                <span class="metric-card__value"><?= $pendingInsideCount ?></span>
                <span class="metric-card__helper">Pre-session only</span>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($session['status'] === 'COMPLETED'): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Final attendance</h2>
            <span class="badge text-bg-secondary"><?= count($finalRecords) ?></span>
        </div>
        <div class="card-body py-2">
            <p class="small text-muted mb-0">
                One record per eligible student. Teaching minutes exclude the official break and are clipped to teaching time.
                Last Out can be later than the session end (physical exit).
            </p>
            <p class="app-attendance-note">
                Session-specific Final OUT: after completion, new IN events are closed for this session.
                An existing student's final EXIT may still update Last Out.
            </p>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Reg No</th>
                        <th>Student</th>
                        <th>First In</th>
                        <th>Last Out</th>
                        <th>Attended</th>
                        <th>Teaching Time</th>
                        <th>Attendance %</th>
                        <th>Status</th>
                        <th>Left Early</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($finalRecords === []): ?>
                        <tr>
                            <td colspan="10" class="p-0">
                                <div class="app-empty-state">
                                    <p class="app-empty-state__title mb-0">No final attendance yet.</p>
                                    <p class="app-empty-state__message mb-0">Use Recalculate / Finalize Attendance.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($finalRecords as $record): ?>
                            <tr>
                                <td><?= e((string) $record['registration_no']) ?></td>
                                <td><?= e($record['first_name'] . ' ' . $record['last_name']) ?></td>
                                <td><?= e($record['first_entry'] ?: '—') ?></td>
                                <td><?= e($record['last_exit'] ?: '—') ?></td>
                                <td><?= e((string) $record['total_present_minutes']) ?> min</td>
                                <td><?= e((string) $record['teaching_minutes']) ?> min</td>
                                <td><?= e(number_format((float) $record['attendance_percent'], 2)) ?>%</td>
                                <td><span class="badge <?= e(status_badge_class((string) $record['status'])) ?>"><?= e((string) $record['status']) ?></span></td>
                                <td><?= (int) $record['left_early'] === 1 ? 'Yes' : 'No' ?></td>
                                <td><a class="small" href="#attendance-audit">View Audit</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Teaching-time preview</h2>
    </div>
    <div class="card-body py-2">
        <p class="small text-muted mb-0">
            Preview only. Official break does not count as missed teaching time.
            PRESENT / LATE / ABSENT / LEFT EARLY are not saved yet.
        </p>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Student</th>
                    <th>First IN</th>
                    <th>Last OUT</th>
                    <th>Attended</th>
                    <th>Missed</th>
                    <th>%</th>
                    <th>Preview flags</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($attendancePreviews === []): ?>
                    <tr>
                        <td colspan="7" class="p-0">
                            <div class="app-empty-state">
                                <p class="app-empty-state__title mb-0">No eligible students.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($attendancePreviews as $preview): ?>
                        <tr>
                            <td><?= e($preview['registration_no'] . ' · ' . $preview['first_name'] . ' ' . $preview['last_name']) ?></td>
                            <td><?= e($preview['first_official_entry'] ?: '-') ?></td>
                            <td><?= e($preview['last_exit'] ?: '-') ?></td>
                            <td><?= e((string) $preview['attended_teaching_minutes']) ?> / <?= e((string) $preview['teaching_minutes']) ?> min</td>
                            <td><?= e((string) $preview['missed_teaching_minutes']) ?> min</td>
                            <td><?= e((string) $preview['attendance_percent']) ?>%</td>
                            <td class="small text-muted">
                                <?php
                                $flags = [];
                                if ($preview['preview_on_time_candidate']) {
                                    $flags[] = 'on-time?';
                                }
                                if ($preview['preview_late_candidate']) {
                                    $flags[] = 'late?';
                                }
                                if ($preview['preview_left_early_candidate']) {
                                    $flags[] = 'left early?';
                                }
                                if ($preview['preview_absent_candidate']) {
                                    $flags[] = 'absent?';
                                }
                                echo e($flags === [] ? '—' : implode(', ', $flags));
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm mb-4 app-table-secondary" id="attendance-audit">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Event audit (raw IN/OUT)</h2>
        <span class="badge text-bg-secondary"><?= count($sessionEvents) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Time</th>
                    <th>Student</th>
                    <th>Event</th>
                    <th>Camera</th>
                    <th>Confidence</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($sessionEvents === []): ?>
                    <tr>
                        <td colspan="5" class="p-0">
                            <div class="app-empty-state">
                                <p class="app-empty-state__title mb-0">No IN/OUT events yet.</p>
                                <p class="app-empty-state__message mb-0">Raw camera detections will appear here during the session.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($sessionEvents as $event): ?>
                        <tr>
                            <td><?= e((string) $event['recognized_at']) ?></td>
                            <td><?= e($event['registration_no'] . ' · ' . $event['first_name'] . ' ' . $event['last_name']) ?></td>
                            <td><span class="badge <?= e($event['event_type'] === 'IN' ? 'text-bg-success' : 'text-bg-secondary') ?>"><?= e($event['event_type']) ?></span></td>
                            <td><?= e($event['camera_id'] ?: '-') ?></td>
                            <td><?= e((string) $event['confidence']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canControl || $canManage): ?>
    <div class="card shadow-sm mb-4 app-table-secondary">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Early arrival pending</h2>
            <span class="badge text-bg-secondary"><?= count($sessionPending) ?></span>
        </div>
        <div class="card-body py-2">
            <p class="small text-muted mb-0">
                Pre-session / not final attendance. Students detected before the lecture starts.
                Pending inside students are promoted to an official IN at scheduled start.
            </p>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Student</th>
                        <th>Early entry</th>
                        <th>Presence</th>
                        <th>Last direction</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sessionPending === []): ?>
                        <tr>
                            <td colspan="5" class="p-0">
                                <div class="app-empty-state">
                                    <p class="app-empty-state__title mb-0">No early-arrival pending rows.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sessionPending as $pending): ?>
                            <tr>
                                <td><?= e($pending['registration_no'] . ' · ' . $pending['first_name'] . ' ' . $pending['last_name']) ?></td>
                                <td><?= e($pending['early_entry_time'] ?: '—') ?></td>
                                <td><?= (int) $pending['inside'] === 1 ? 'Inside' : 'Outside' ?></td>
                                <td><?= e((string) $pending['last_direction']) ?></td>
                                <td><span class="badge <?= e(status_badge_class((string) $pending['status'])) ?>"><?= e((string) $pending['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

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
                    <tr>
                        <td colspan="4" class="p-0">
                            <div class="app-empty-state">
                                <p class="app-empty-state__title mb-0">No eligible students.</p>
                                <p class="app-empty-state__message mb-0">Enrol the batch in this module first.</p>
                            </div>
                        </td>
                    </tr>
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
