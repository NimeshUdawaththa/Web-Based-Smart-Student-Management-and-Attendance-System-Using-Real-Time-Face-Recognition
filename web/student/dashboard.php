<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/includes/init.php';

require_role('STUDENT');

$pageTitle = 'Student Dashboard';
$hideTopbarTitle = true;
$user = current_user();
$displayName = current_user_display_name();
$student = current_student_profile();
$today = app_today();
$todaySessions = [];
$upcomingSessions = [];
$recentAnnouncements = list_visible_announcements_for_role('STUDENT', 3);
$upcomingCampusEvents = list_campus_events_for_role('STUDENT', 'upcoming', 3);

if ($student !== null) {
    $todaySessions = list_visible_sessions_for_student((int) $student['student_id'], [
        'from' => $today,
        'to' => $today,
    ]);
    $upcomingSessions = list_visible_sessions_for_student((int) $student['student_id'], [
        'upcoming' => true,
    ]);
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<section class="app-dashboard-welcome">
    <h1 class="app-dashboard-welcome__title">Welcome, <?= e($displayName) ?></h1>
    <p class="app-dashboard-welcome__meta mb-0">
        Your lectures, attendance, coursework, and results in one place.
    </p>
</section>

<?php if ($student === null): ?>
    <div class="alert alert-warning">No student profile is linked to this login.</div>
<?php else: ?>
    <section class="app-dashboard-section">
        <h2 class="app-dashboard-section__title">At a glance</h2>
        <div class="row g-3">
            <div class="col-sm-6 col-lg-4">
                <div class="metric-card">
                    <div class="metric-card__row">
                        <div>
                            <span class="metric-card__label">Today's Lectures</span>
                            <span class="metric-card__value"><?= count($todaySessions) ?></span>
                            <span class="metric-card__helper">Eligible lecture sessions today</span>
                        </div>
                        <span class="metric-card__icon" aria-hidden="true"><i class="bi bi-calendar-event"></i></span>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="metric-card">
                    <div class="metric-card__row">
                        <div>
                            <span class="metric-card__label">Upcoming Lectures</span>
                            <span class="metric-card__value"><?= count($upcomingSessions) ?></span>
                            <span class="metric-card__helper">Scheduled ahead for you</span>
                        </div>
                        <span class="metric-card__icon" aria-hidden="true"><i class="bi bi-calendar-check"></i></span>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="metric-card">
                    <div class="metric-card__row">
                        <div>
                            <span class="metric-card__label">Campus Events</span>
                            <span class="metric-card__value"><?= count($upcomingCampusEvents) ?></span>
                            <span class="metric-card__helper">Upcoming (preview)</span>
                        </div>
                        <span class="metric-card__icon" aria-hidden="true"><i class="bi bi-calendar2-event"></i></span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="app-dashboard-section">
        <h2 class="app-dashboard-section__title">Your learning</h2>
        <div class="row g-3">
            <div class="col-md-6 col-xl-4">
                <div class="card app-action-card">
                    <div class="card-body">
                        <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-calendar3"></i></span>
                        <h3 class="h6 mb-1">My Timetable</h3>
                        <p class="text-muted small">Today's and upcoming lectures for modules you are enrolled in.</p>
                        <a href="<?= e(app_url('student/timetable.php')) ?>" class="btn btn-primary btn-sm">Open</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-4">
                <div class="card app-action-card">
                    <div class="card-body">
                        <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-calendar-event"></i></span>
                        <h3 class="h6 mb-1">Today's Lectures</h3>
                        <p class="text-muted small mb-2"><?= count($todaySessions) ?> lecture<?= count($todaySessions) === 1 ? '' : 's' ?> today.</p>
                        <a href="<?= e(app_url('student/timetable.php')) ?>" class="btn btn-outline-primary btn-sm">View</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-4">
                <div class="card app-action-card">
                    <div class="card-body">
                        <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-calendar-plus"></i></span>
                        <h3 class="h6 mb-1">Upcoming Lectures</h3>
                        <p class="text-muted small mb-2"><?= count($upcomingSessions) ?> upcoming lecture<?= count($upcomingSessions) === 1 ? '' : 's' ?>.</p>
                        <a href="<?= e(app_url('student/timetable.php')) ?>" class="btn btn-outline-primary btn-sm">View</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-4">
                <div class="card app-action-card">
                    <div class="card-body">
                        <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span>
                        <h3 class="h6 mb-1">My Attendance</h3>
                        <p class="text-muted small">Final attendance for lectures you were eligible to attend.</p>
                        <a href="<?= e(app_url('student/attendance.php')) ?>" class="btn btn-outline-primary btn-sm">Open</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-4">
                <div class="card app-action-card">
                    <div class="card-body">
                        <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-journal-text"></i></span>
                        <h3 class="h6 mb-1">My Assignments</h3>
                        <p class="text-muted small">View instructions and submit work for modules you are enrolled in.</p>
                        <a href="<?= e(app_url('student/assignments/index.php')) ?>" class="btn btn-outline-primary btn-sm">Open</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-4">
                <div class="card app-action-card">
                    <div class="card-body">
                        <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-bar-chart"></i></span>
                        <h3 class="h6 mb-1">My Results</h3>
                        <p class="text-muted small">View quiz, exam, and other module assessment results.</p>
                        <a href="<?= e(app_url('student/results/index.php')) ?>" class="btn btn-outline-primary btn-sm">Open</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="app-dashboard-section">
    <h2 class="app-dashboard-section__title">Campus updates</h2>
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card shadow-sm app-feed-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h3 class="h6 mb-0"><i class="bi bi-megaphone me-1" aria-hidden="true"></i> Recent Announcements</h3>
                        <a href="<?= e(app_url('student/announcements/index.php')) ?>" class="btn btn-outline-primary btn-sm">View All</a>
                    </div>
                    <?php if ($recentAnnouncements === []): ?>
                        <p class="text-muted mb-0">No announcements right now.</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($recentAnnouncements as $notice): ?>
                                <li class="mb-2">
                                    <a href="<?= e(app_url('student/announcements/view.php?id=' . $notice['announcement_id'])) ?>"><?= e((string) $notice['title']) ?></a>
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
                        <a href="<?= e(app_url('student/events/index.php')) ?>" class="btn btn-outline-primary btn-sm">View All</a>
                    </div>
                    <?php if ($upcomingCampusEvents === []): ?>
                        <p class="text-muted mb-0">No upcoming events right now.</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($upcomingCampusEvents as $campusEvent): ?>
                                <li class="mb-2">
                                    <a href="<?= e(app_url('student/events/view.php?id=' . $campusEvent['campus_event_id'])) ?>"><?= e((string) $campusEvent['title']) ?></a>
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
