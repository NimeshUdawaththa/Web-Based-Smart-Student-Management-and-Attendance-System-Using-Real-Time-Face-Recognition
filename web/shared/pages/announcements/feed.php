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

$pageTitle = 'Announcements';
$rows = list_visible_announcements_for_role($role);

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<p class="text-muted mb-4">Notices published for you. Draft, scheduled, and expired items are not shown.</p>

<?php if ($rows === []): ?>
    <div class="alert alert-light border">There are no announcements for you right now.</div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($rows as $row): ?>
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h5 mb-2"><?= e((string) $row['title']) ?></h2>
                        <p class="text-muted small mb-3">
                            Published <?= e(format_announcement_datetime(isset($row['published_at']) ? (string) $row['published_at'] : null)) ?>
                            <?php if (!empty($row['expires_at'])): ?>
                                · Expires <?= e(format_announcement_datetime((string) $row['expires_at'])) ?>
                            <?php endif; ?>
                        </p>
                        <div class="mb-3"><?= nl2br(e((string) $row['message'])) ?></div>
                        <a href="<?= e(app_url($academicRoutePrefix . '/announcements/view.php?id=' . $row['announcement_id'])) ?>" class="btn btn-sm btn-outline-primary">View</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
