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

$pageTitle = 'Events';
$rows = list_campus_events_for_management();

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <p class="text-muted mb-0">Campus events such as workshops and seminars. These are not lecture timetable sessions and are not used for attendance.</p>
    <a href="<?= e(app_url($academicRoutePrefix . '/events/create.php')) ?>" class="btn btn-primary">Create event</a>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-striped mb-0 align-middle">
            <thead>
                <tr>
                    <th>Poster</th>
                    <th>Title</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Location</th>
                    <th>Audience</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">No events yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $lifecycle = (string) ($row['lifecycle_label'] ?? campus_event_lifecycle_label($row)); ?>
                        <tr>
                            <td>
                                <?php if (!empty($row['poster_path'])): ?>
                                    <img src="<?= e(campus_event_poster_url($academicRoutePrefix, (int) $row['campus_event_id'])) ?>" alt="" class="rounded border" style="width: 48px; height: 48px; object-fit: cover;">
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) $row['title']) ?></td>
                            <td><?= e(format_campus_event_datetime(isset($row['start_datetime']) ? (string) $row['start_datetime'] : null)) ?></td>
                            <td><?= e(format_campus_event_datetime(isset($row['end_datetime']) ? (string) $row['end_datetime'] : null)) ?></td>
                            <td><?= e((string) ($row['location'] ?: '—')) ?></td>
                            <td><?= e((string) ($row['audience_label'] ?? campus_event_audience_label($row['target_roles'] ?? []))) ?></td>
                            <td>
                                <span class="badge <?= e(status_badge_class($lifecycle)) ?>"><?= e($lifecycle) ?></span>
                                <div class="small text-muted"><?= e((string) $row['status']) ?></div>
                            </td>
                            <td><?= e((string) $row['created_by_username']) ?></td>
                            <td class="text-nowrap">
                                <a href="<?= e(app_url($academicRoutePrefix . '/events/view.php?id=' . $row['campus_event_id'])) ?>" class="btn btn-sm btn-outline-secondary">View</a>
                                <a href="<?= e(app_url($academicRoutePrefix . '/events/edit.php?id=' . $row['campus_event_id'])) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
