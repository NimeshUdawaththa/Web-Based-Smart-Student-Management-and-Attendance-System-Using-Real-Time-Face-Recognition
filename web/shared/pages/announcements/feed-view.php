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

$announcementId = positive_int($_GET['id'] ?? null);
$announcement = $announcementId !== null ? get_announcement($announcementId) : null;
if ($announcement === null || !reader_can_view_announcement($announcement, $role)) {
    set_flash('error', 'Announcement not found.');
    redirect($academicRoutePrefix . '/announcements/index.php');
}

$pageTitle = (string) $announcement['title'];

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<p class="mb-4">
    <a href="<?= e(app_url($academicRoutePrefix . '/announcements/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Back to announcements</a>
</p>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h4 mb-3"><?= e((string) $announcement['title']) ?></h2>
        <p class="text-muted small mb-3">
            Published <?= e(format_announcement_datetime(isset($announcement['published_at']) ? (string) $announcement['published_at'] : null)) ?>
            <?php if (!empty($announcement['expires_at'])): ?>
                · Expires <?= e(format_announcement_datetime((string) $announcement['expires_at'])) ?>
            <?php endif; ?>
        </p>
        <div class="mb-0"><?= nl2br(e((string) $announcement['message'])) ?></div>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
