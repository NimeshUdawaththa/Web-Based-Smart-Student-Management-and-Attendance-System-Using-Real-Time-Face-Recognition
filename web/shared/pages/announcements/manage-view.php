<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'admin';

if (!staff_can_manage_announcements()) {
    deny_access();
}

$announcementId = positive_int($_GET['id'] ?? null);
$announcement = $announcementId !== null ? get_announcement($announcementId) : null;
if ($announcement === null) {
    set_flash('error', 'Announcement not found.');
    redirect($academicRoutePrefix . '/announcements/index.php');
}

$pageTitle = (string) $announcement['title'];
$lifecycle = announcement_lifecycle_label($announcement);

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <a href="<?= e(app_url($academicRoutePrefix . '/announcements/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Back to list</a>
    <a href="<?= e(app_url($academicRoutePrefix . '/announcements/edit.php?id=' . $announcement['announcement_id'])) ?>" class="btn btn-primary btn-sm">Edit</a>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge <?= e(status_badge_class($lifecycle)) ?>"><?= e($lifecycle) ?></span>
            <span class="badge text-bg-light"><?= e((string) $announcement['status']) ?></span>
            <span class="badge text-bg-light"><?= e((string) ($announcement['audience_label'] ?? announcement_audience_label($announcement['target_roles'] ?? []))) ?></span>
        </div>
        <dl class="row mb-0">
            <dt class="col-sm-3">Published</dt>
            <dd class="col-sm-9"><?= e(format_announcement_datetime(isset($announcement['published_at']) ? (string) $announcement['published_at'] : null)) ?></dd>
            <dt class="col-sm-3">Expires</dt>
            <dd class="col-sm-9"><?= e(format_announcement_datetime(isset($announcement['expires_at']) ? (string) $announcement['expires_at'] : null)) ?></dd>
            <dt class="col-sm-3">Created by</dt>
            <dd class="col-sm-9"><?= e((string) $announcement['created_by_username']) ?></dd>
        </dl>
        <hr>
        <div class="mb-0"><?= nl2br(e((string) $announcement['message'])) ?></div>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
