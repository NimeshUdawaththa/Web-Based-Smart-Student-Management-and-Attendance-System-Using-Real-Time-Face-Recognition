<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'student';

$user = current_user();
$role = (string) ($user['role'] ?? '');
if (!in_array($role, ['STUDENT', 'LECTURER'], true)) {
    deny_access();
}

$campusEventId = positive_int($_GET['id'] ?? null);
$campusEvent = $campusEventId !== null ? get_campus_event($campusEventId) : null;
if ($campusEvent === null || !reader_can_view_campus_event($campusEvent, $role)) {
    set_flash('error', 'Event not found.');
    redirect($academicRoutePrefix . '/events/index.php');
}

$pageTitle = (string) $campusEvent['title'];
$lifecycle = (string) ($campusEvent['lifecycle_label'] ?? campus_event_lifecycle_label($campusEvent));

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<p class="mb-4">
    <a href="<?= e(app_url($academicRoutePrefix . '/events/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Back to events</a>
</p>

<div class="card shadow-sm overflow-hidden">
    <?php if (!empty($campusEvent['poster_path'])): ?>
        <img src="<?= e(campus_event_poster_url($academicRoutePrefix, (int) $campusEvent['campus_event_id'])) ?>" alt="" class="w-100" style="max-height: 420px; object-fit: cover;">
    <?php endif; ?>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-2">
            <span class="badge <?= e(status_badge_class($lifecycle)) ?>"><?= e($lifecycle) ?></span>
        </div>
        <h2 class="h4 mb-3"><?= e((string) $campusEvent['title']) ?></h2>
        <p class="text-muted small mb-3">
            <?= e(format_campus_event_datetime(isset($campusEvent['start_datetime']) ? (string) $campusEvent['start_datetime'] : null)) ?>
            –
            <?= e(format_campus_event_datetime(isset($campusEvent['end_datetime']) ? (string) $campusEvent['end_datetime'] : null)) ?>
            <?php if (!empty($campusEvent['location'])): ?>
                · <?= e((string) $campusEvent['location']) ?>
            <?php endif; ?>
        </p>
        <?php if (!empty($campusEvent['description'])): ?>
            <div class="mb-0"><?= nl2br(e((string) $campusEvent['description'])) ?></div>
        <?php endif; ?>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
