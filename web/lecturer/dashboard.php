<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/includes/init.php';

require_role('LECTURER');

$pageTitle = 'Lecturer Dashboard';
$hideTopbarTitle = true;
$user = current_user();
$displayName = current_user_display_name();
$lecturer = current_lecturer_profile();
$today = app_today();
$todaySessions = [];
$upcomingSessions = [];
$activeSessions = [];
$recentAnnouncements = list_visible_announcements_for_role('LECTURER', 3);
$upcomingCampusEvents = list_campus_events_for_role('LECTURER', 'upcoming', 3);

if ($lecturer !== null) {
    $todaySessions = list_lecture_sessions([
        'lecturer_id' => (int) $lecturer['lecturer_id'],
        'from' => $today,
        'to' => $today,
    ]);
    $upcomingSessions = list_lecture_sessions([
        'lecturer_id' => (int) $lecturer['lecturer_id'],
        'upcoming' => true,
    ]);
    $activeSessions = get_in_progress_sessions((int) $lecturer['lecturer_id']);
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<section class="app-dashboard-welcome">
    <h1 class="app-dashboard-welcome__title">Welcome, <?= e($displayName) ?></h1>
    <p class="app-dashboard-welcome__meta mb-0">
        Your lectures, active sessions, and teaching tools for today.
    </p>
</section>

<?php if ($lecturer === null): ?>
    <div class="alert alert-warning">
        This login does not have a lecturer profile. Ask an administrator to create a lecturer record for this account.
    </div>
<?php else: ?>
    <section class="app-dashboard-section">
        <h2 class="app-dashboard-section__title">At a glance</h2>
        <div class="row g-3">
            <div class="col-6 col-lg-3">
                <div class="metric-card">
                    <div class="metric-card__row">
                        <div>
                            <span class="metric-card__label">Today's Lectures</span>
                            <span class="metric-card__value"><?= count($todaySessions) ?></span>
                        </div>
                        <span class="metric-card__icon" aria-hidden="true"><i class="bi bi-calendar-event"></i></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="metric-card">
                    <div class="metric-card__row">
                        <div>
                            <span class="metric-card__label">Upcoming</span>
                            <span class="metric-card__value"><?= count($upcomingSessions) ?></span>
                        </div>
                        <span class="metric-card__icon" aria-hidden="true"><i class="bi bi-calendar-check"></i></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="metric-card">
                    <div class="metric-card__row">
                        <div>
                            <span class="metric-card__label">In Progress</span>
                            <span class="metric-card__value"><?= count($activeSessions) ?></span>
                        </div>
                        <span class="metric-card__icon" aria-hidden="true"><i class="bi bi-play-circle"></i></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="metric-card">
                    <div class="metric-card__row">
                        <div>
                            <span class="metric-card__label">Active Session</span>
                            <span class="metric-card__value"><?= $activeSessions === [] ? '—' : 'Yes' ?></span>
                            <?php if ($activeSessions !== []): ?>
                                <span class="metric-card__helper"><?= e($activeSessions[0]['module_code'] ?? '') ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="metric-card__icon" aria-hidden="true"><i class="bi bi-broadcast"></i></span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="app-dashboard-section">
        <h2 class="app-dashboard-section__title">Teaching today</h2>
        <div class="row g-3">
            <div class="col-md-6 col-xl-4">
                <div class="card app-action-card">
                    <div class="card-body">
                        <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-calendar3"></i></span>
                        <h3 class="h6 mb-1">My Calendar</h3>
                        <p class="text-muted small">Dated lecture sessions assigned to you.</p>
                        <a href="<?= e(app_url('lecturer/schedules/index.php')) ?>" class="btn btn-primary btn-sm">Open calendar</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-4">
                <div class="card app-action-card">
                    <div class="card-body">
                        <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-calendar-event"></i></span>
                        <h3 class="h6 mb-1">Today's Lectures</h3>
                        <p class="text-muted small mb-2"><?= count($todaySessions) ?> lecture<?= count($todaySessions) === 1 ? '' : 's' ?> today.</p>
                        <a href="<?= e(app_url('lecturer/sessions/index.php?view=today')) ?>" class="btn btn-outline-primary btn-sm">View today</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-4">
                <div class="card app-action-card">
                    <div class="card-body">
                        <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-calendar-plus"></i></span>
                        <h3 class="h6 mb-1">Upcoming Lectures</h3>
                        <p class="text-muted small mb-2"><?= count($upcomingSessions) ?> upcoming session<?= count($upcomingSessions) === 1 ? '' : 's' ?>.</p>
                        <a href="<?= e(app_url('lecturer/sessions/index.php?view=upcoming')) ?>" class="btn btn-outline-primary btn-sm">View upcoming</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-4">
                <div class="card app-action-card">
                    <div class="card-body">
                        <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-play-circle"></i></span>
                        <h3 class="h6 mb-1">Active Session</h3>
                        <?php if ($activeSessions === []): ?>
                            <p class="text-muted small mb-2">No session is in progress.</p>
                            <a href="<?= e(app_url('lecturer/sessions/index.php?view=today')) ?>" class="btn btn-outline-primary btn-sm">Open sessions</a>
                        <?php else: ?>
                            <?php foreach ($activeSessions as $session): ?>
                                <p class="small mb-2"><?= e($session['module_code'] . ' · ' . $session['batch_name']) ?></p>
                                <a href="<?= e(app_url('lecturer/sessions/view.php?id=' . $session['session_id'])) ?>" class="btn btn-success btn-sm">Manage active session</a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-4">
                <div class="card app-action-card">
                    <div class="card-body">
                        <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span>
                        <h3 class="h6 mb-1">Attendance Reports</h3>
                        <p class="text-muted small">Final attendance for your completed lectures.</p>
                        <a href="<?= e(app_url('lecturer/attendance/reports.php')) ?>" class="btn btn-outline-primary btn-sm">Open</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-4">
                <div class="card app-action-card">
                    <div class="card-body">
                        <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-people"></i></span>
                        <h3 class="h6 mb-1">Student Directory</h3>
                        <p class="text-muted small">View students linked to modules you teach.</p>
                        <a href="<?= e(app_url('lecturer/students/index.php')) ?>" class="btn btn-outline-primary btn-sm">View students</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="app-dashboard-section">
        <h2 class="app-dashboard-section__title">Assessment</h2>
        <div class="row g-3">
            <div class="col-md-6 col-xl-4">
                <div class="card app-action-card">
                    <div class="card-body">
                        <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-journal-bookmark"></i></span>
                        <h3 class="h6 mb-1">Coursework &amp; Assessments</h3>
                        <p class="text-muted small">Create and manage assignments, presentations, exams and practicals.</p>
                        <a href="<?= e(app_url('lecturer/assignments/index.php')) ?>" class="btn btn-outline-primary btn-sm">Open</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="app-dashboard-section">
    <h2 class="app-dashboard-section__title">Communication</h2>
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card shadow-sm app-feed-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h3 class="h6 mb-0"><i class="bi bi-megaphone me-1" aria-hidden="true"></i> Recent Announcements</h3>
                        <a href="<?= e(app_url('lecturer/announcements/index.php')) ?>" class="btn btn-outline-primary btn-sm">View All</a>
                    </div>
                    <?php if ($recentAnnouncements === []): ?>
                        <p class="text-muted mb-0">No announcements right now.</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($recentAnnouncements as $notice): ?>
                                <li class="mb-2">
                                    <a href="<?= e(app_url('lecturer/announcements/view.php?id=' . $notice['announcement_id'])) ?>"><?= e((string) $notice['title']) ?></a>
                                    <div class="small text-muted"><?= e(format_announcement_datetime(isset($notice['published_at']) ? (string) $notice['published_at'] : null)) ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm app-feed-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h3 class="h6 mb-0"><i class="bi bi-calendar2-event me-1" aria-hidden="true"></i> Upcoming Events</h3>
                        <a href="<?= e(app_url('lecturer/events/index.php')) ?>" class="btn btn-outline-primary btn-sm">View All</a>
                    </div>
                    <?php if ($upcomingCampusEvents === []): ?>
                        <p class="text-muted mb-0">No upcoming events right now.</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($upcomingCampusEvents as $campusEvent): ?>
                                <li class="mb-2">
                                    <a href="<?= e(app_url('lecturer/events/view.php?id=' . $campusEvent['campus_event_id'])) ?>"><?= e((string) $campusEvent['title']) ?></a>
                                    <div class="small text-muted"><?= e(format_campus_event_datetime(isset($campusEvent['start_datetime']) ? (string) $campusEvent['start_datetime'] : null)) ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
