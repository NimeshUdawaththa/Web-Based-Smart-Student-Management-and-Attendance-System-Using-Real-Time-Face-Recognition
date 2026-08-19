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

$pageTitle = 'Announcements';
$rows = list_announcements_for_management();

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <p class="text-muted mb-0">Publish notices for students, lecturers, or everyone. Draft, scheduled, and expired items stay on this list.</p>
    <a href="<?= e(app_url($academicRoutePrefix . '/announcements/create.php')) ?>" class="btn btn-primary">Create announcement</a>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-striped mb-0 align-middle">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Audience</th>
                    <th>Published</th>
                    <th>Expires</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No announcements yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $lifecycle = announcement_lifecycle_label($row); ?>
                        <tr>
                            <td><?= e((string) $row['title']) ?></td>
                            <td><?= e((string) ($row['audience_label'] ?? announcement_audience_label($row['target_roles'] ?? []))) ?></td>
                            <td><?= e(format_announcement_datetime(isset($row['published_at']) ? (string) $row['published_at'] : null)) ?></td>
                            <td><?= e(format_announcement_datetime(isset($row['expires_at']) ? (string) $row['expires_at'] : null)) ?></td>
                            <td>
                                <span class="badge <?= e(status_badge_class($lifecycle)) ?>"><?= e($lifecycle) ?></span>
                                <div class="small text-muted"><?= e((string) $row['status']) ?></div>
                            </td>
                            <td><?= e((string) $row['created_by_username']) ?></td>
                            <td class="text-nowrap">
                                <a href="<?= e(app_url($academicRoutePrefix . '/announcements/view.php?id=' . $row['announcement_id'])) ?>" class="btn btn-sm btn-outline-secondary">View</a>
                                <a href="<?= e(app_url($academicRoutePrefix . '/announcements/edit.php?id=' . $row['announcement_id'])) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
