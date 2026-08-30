<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/includes/init.php';

require_role('ACADEMIC_STAFF');

$pageTitle = 'Academic Staff Dashboard';
$hideTopbarTitle = true;
$user = current_user();
$displayName = current_user_display_name();
$today = app_today();

$activeStudentCount = count_active_students();
$todaySessions = list_lecture_sessions(['from' => $today, 'to' => $today]);
$upcomingSessions = list_lecture_sessions(['upcoming' => true]);
$activeSessions = get_in_progress_sessions();
$activeAnnouncementCount = count_currently_active_announcements();
$upcomingCampusEventCount = count_upcoming_campus_events();

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<section class="app-dashboard-welcome">
    <h1 class="app-dashboard-welcome__title">Welcome, <?= e($displayName) ?></h1>
    <p class="app-dashboard-welcome__meta mb-0">
        Schedule lectures from the Lecture Calendar, then manage sessions, enrolments, and reports.
    </p>
</section>

<section class="app-dashboard-section">
    <h2 class="app-dashboard-section__title">At a glance</h2>
    <div class="row g-3">
        <div class="col-6 col-lg-3">
            <div class="metric-card">
                <div class="metric-card__row">
                    <div>
                        <span class="metric-card__label">Active Students</span>
                        <span class="metric-card__value"><?= (int) $activeStudentCount ?></span>
                    </div>
                    <span class="metric-card__icon" aria-hidden="true"><i class="bi bi-people"></i></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="metric-card">
                <div class="metric-card__row">
                    <div>
                        <span class="metric-card__label">Today's Sessions</span>
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
                        <span class="metric-card__label">Upcoming</span>
                        <span class="metric-card__value"><?= count($upcomingSessions) ?></span>
                    </div>
                    <span class="metric-card__icon" aria-hidden="true"><i class="bi bi-calendar-check"></i></span>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="app-dashboard-section">
    <h2 class="app-dashboard-section__title">Primary workflow</h2>
    <div class="row g-3">
        <div class="col-md-6 col-xl-3">
            <div class="card app-action-card">
                <div class="card-body">
                    <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-calendar-event"></i></span>
                    <h3 class="h6 mb-1">Lecture Calendar</h3>
                    <p class="text-muted small">View dated lecture sessions across lecturers.</p>
                    <a href="<?= e(app_url('academic-staff/calendar/index.php')) ?>" class="btn btn-primary btn-sm">Open</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card app-action-card">
                <div class="card-body">
                    <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-plus-circle"></i></span>
                    <h3 class="h6 mb-1">Create Session</h3>
                    <p class="text-muted small">Schedule a dated lecture session for a lecturer.</p>
                    <a href="<?= e(app_url('academic-staff/sessions/create.php')) ?>" class="btn btn-primary btn-sm">Create</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card app-action-card">
                <div class="card-body">
                    <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-calendar-check"></i></span>
                    <h3 class="h6 mb-1">Lecture Sessions</h3>
                    <p class="text-muted small">Start, stop, or cancel dated lecture sessions as needed.</p>
                    <a href="<?= e(app_url('academic-staff/sessions/index.php')) ?>" class="btn btn-outline-primary btn-sm">Open</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card app-action-card">
                <div class="card-body">
                    <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-camera-video"></i></span>
                    <h3 class="h6 mb-1">Camera Management</h3>
                    <p class="text-muted small">Start or stop the USB attendance camera.</p>
                    <a href="<?= e(app_url('academic-staff/camera.php')) ?>" class="btn btn-outline-primary btn-sm">Open</a>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="app-dashboard-section">
    <h2 class="app-dashboard-section__title">People &amp; structure</h2>
    <div class="row g-3">
        <div class="col-md-6 col-xl-3">
            <div class="card app-action-card">
                <div class="card-body">
                    <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-mortarboard"></i></span>
                    <h3 class="h6 mb-1">Student Management</h3>
                    <p class="text-muted small">Register and maintain student records.</p>
                    <a href="<?= e(app_url('academic-staff/students/index.php')) ?>" class="btn btn-outline-primary btn-sm">Open</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card app-action-card">
                <div class="card-body">
                    <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-person-check"></i></span>
                    <h3 class="h6 mb-1">Module Enrollment</h3>
                    <p class="text-muted small">Enrol students so they become eligible for a lecture.</p>
                    <a href="<?= e(app_url('academic-staff/enrollments/index.php')) ?>" class="btn btn-outline-primary btn-sm">Open</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card app-action-card">
                <div class="card-body">
                    <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-diagram-3"></i></span>
                    <h3 class="h6 mb-1">Lecturer Assignments</h3>
                    <p class="text-muted small">Assign lecturers to the modules they teach.</p>
                    <a href="<?= e(app_url('academic-staff/module-assignments/index.php')) ?>" class="btn btn-outline-primary btn-sm">Open</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card app-action-card">
                <div class="card-body">
                    <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-journal-bookmark"></i></span>
                    <h3 class="h6 mb-1">Module Catalogue</h3>
                    <p class="text-muted small">Maintain modules used across courses and batches.</p>
                    <a href="<?= e(app_url('academic-staff/modules/index.php')) ?>" class="btn btn-outline-primary btn-sm">Open</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card app-action-card">
                <div class="card-body">
                    <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-building"></i></span>
                    <h3 class="h6 mb-1">Courses</h3>
                    <p class="text-muted small">Create and maintain academic programmes.</p>
                    <a href="<?= e(app_url('academic-staff/courses/index.php')) ?>" class="btn btn-outline-primary btn-sm">Open</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card app-action-card">
                <div class="card-body">
                    <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-collection"></i></span>
                    <h3 class="h6 mb-1">Batches</h3>
                    <p class="text-muted small">Manage student intakes for each course.</p>
                    <a href="<?= e(app_url('academic-staff/batches/index.php')) ?>" class="btn btn-outline-primary btn-sm">Open</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card app-action-card">
                <div class="card-body">
                    <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-person-badge"></i></span>
                    <h3 class="h6 mb-1">Lecturers</h3>
                    <p class="text-muted small">Browse lecturer profiles for teaching operations.</p>
                    <a href="<?= e(app_url('academic-staff/lecturers/index.php')) ?>" class="btn btn-outline-primary btn-sm">Open</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card app-action-card">
                <div class="card-body">
                    <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span>
                    <h3 class="h6 mb-1">Attendance Reports</h3>
                    <p class="text-muted small">Monitor finalized attendance across modules and batches.</p>
                    <a href="<?= e(app_url('academic-staff/attendance/reports.php')) ?>" class="btn btn-outline-primary btn-sm">Open</a>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="app-dashboard-section">
    <h2 class="app-dashboard-section__title">Assessment &amp; communication</h2>
    <div class="row g-3">
        <div class="col-md-6 col-xl-3">
            <div class="card app-action-card">
                <div class="card-body">
                    <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-journal-text"></i></span>
                    <h3 class="h6 mb-1">Coursework Monitor</h3>
                    <p class="text-muted small">Read-only view of coursework and assessments.</p>
                    <a href="<?= e(app_url('academic-staff/assignments/index.php')) ?>" class="btn btn-outline-primary btn-sm">Open</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card app-action-card">
                <div class="card-body">
                    <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-megaphone"></i></span>
                    <h3 class="h6 mb-1">Announcements</h3>
                    <p class="text-muted small mb-2"><?= (int) $activeAnnouncementCount ?> currently active notice<?= $activeAnnouncementCount === 1 ? '' : 's' ?>.</p>
                    <a href="<?= e(app_url('academic-staff/announcements/index.php')) ?>" class="btn btn-outline-primary btn-sm">Manage</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card app-action-card">
                <div class="card-body">
                    <span class="app-action-card__icon" aria-hidden="true"><i class="bi bi-calendar2-event"></i></span>
                    <h3 class="h6 mb-1">Events</h3>
                    <p class="text-muted small mb-2"><?= (int) $upcomingCampusEventCount ?> upcoming published event<?= $upcomingCampusEventCount === 1 ? '' : 's' ?>.</p>
                    <a href="<?= e(app_url('academic-staff/events/index.php')) ?>" class="btn btn-outline-primary btn-sm">Manage</a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
