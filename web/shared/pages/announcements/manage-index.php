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

<div class="app-list-toolbar">
    <p class="app-list-toolbar__desc">Publish notices for students, lecturers, or everyone. Draft, scheduled, and expired items stay on this list.</p>
    <a href="<?= e(app_url($academicRoutePrefix . '/announcements/create.php')) ?>" class="btn btn-primary">Create announcement</a>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Title</th>
                    <th>Audience</th>
                    <th>Published</th>
                    <th>Expires</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="7" class="p-0">
                            <div class="app-empty-state">
                                <p class="app-empty-state__title mb-0">No announcements yet.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $lifecycle = announcement_lifecycle_label($row); ?>
                        <tr>
                            <td><?= app_truncate_html((string) $row['title'], 'md') ?></td>
                            <td><?= e((string) ($row['audience_label'] ?? announcement_audience_label($row['target_roles'] ?? []))) ?></td>
                            <td><?= e(format_announcement_datetime(isset($row['published_at']) ? (string) $row['published_at'] : null)) ?></td>
                            <td><?= e(format_announcement_datetime(isset($row['expires_at']) ? (string) $row['expires_at'] : null)) ?></td>
                            <td>
                                <span class="badge <?= e(status_badge_class($lifecycle)) ?>"><?= e($lifecycle) ?></span>
                                <div class="small text-muted"><?= e((string) $row['status']) ?></div>
                            </td>
                            <td><?= e((string) $row['created_by_username']) ?></td>
                            <td class="text-end">
                                <div class="app-actions">
                                    <a href="<?= e(app_url($academicRoutePrefix . '/announcements/view.php?id=' . $row['announcement_id'])) ?>" class="btn btn-sm btn-outline-secondary">View</a>
                                    <a href="<?= e(app_url($academicRoutePrefix . '/announcements/edit.php?id=' . $row['announcement_id'])) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
