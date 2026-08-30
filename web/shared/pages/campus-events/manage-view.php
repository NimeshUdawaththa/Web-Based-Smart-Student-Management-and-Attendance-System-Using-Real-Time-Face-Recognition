<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'admin';

if (!staff_can_manage_campus_events()) {
    deny_access();
}

$campusEventId = positive_int($_GET['id'] ?? null);
$campusEvent = $campusEventId !== null ? get_campus_event($campusEventId) : null;
if ($campusEvent === null) {
    set_flash('error', 'Event not found.');
    redirect($academicRoutePrefix . '/events/index.php');
}

$pageTitle = (string) $campusEvent['title'];
$lifecycle = (string) ($campusEvent['lifecycle_label'] ?? campus_event_lifecycle_label($campusEvent));

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <a href="<?= e(app_url($academicRoutePrefix . '/events/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Back to list</a>
    <a href="<?= e(app_url($academicRoutePrefix . '/events/edit.php?id=' . $campusEvent['campus_event_id'])) ?>" class="btn btn-primary btn-sm">Edit</a>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge <?= e(status_badge_class($lifecycle)) ?>"><?= e($lifecycle) ?></span>
            <span class="badge text-bg-light"><?= e((string) $campusEvent['status']) ?></span>
            <span class="badge text-bg-light"><?= e((string) ($campusEvent['audience_label'] ?? '')) ?></span>
        </div>
        <?php if (!empty($campusEvent['poster_path'])): ?>
            <img src="<?= e(campus_event_poster_url($academicRoutePrefix, (int) $campusEvent['campus_event_id'])) ?>" alt="" class="img-fluid rounded border mb-3" style="max-height: 280px;">
        <?php endif; ?>
        <dl class="row mb-0">
            <dt class="col-sm-3">Start</dt>
            <dd class="col-sm-9"><?= e(format_campus_event_datetime(isset($campusEvent['start_datetime']) ? (string) $campusEvent['start_datetime'] : null)) ?></dd>
            <dt class="col-sm-3">End</dt>
            <dd class="col-sm-9"><?= e(format_campus_event_datetime(isset($campusEvent['end_datetime']) ? (string) $campusEvent['end_datetime'] : null)) ?></dd>
            <dt class="col-sm-3">Location</dt>
            <dd class="col-sm-9"><?= e((string) ($campusEvent['location'] ?: '—')) ?></dd>
            <dt class="col-sm-3">Published</dt>
            <dd class="col-sm-9"><?= e(format_campus_event_datetime(isset($campusEvent['published_at']) ? (string) $campusEvent['published_at'] : null)) ?></dd>
            <dt class="col-sm-3">Created by</dt>
            <dd class="col-sm-9"><?= e((string) $campusEvent['created_by_username']) ?></dd>
        </dl>
        <?php if (!empty($campusEvent['description'])): ?>
            <hr>
            <div class="mb-0"><?= nl2br(e((string) $campusEvent['description'])) ?></div>
        <?php endif; ?>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
