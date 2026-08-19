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

$announcementId = positive_int($_GET['id'] ?? $_POST['announcement_id'] ?? null);
$isEdit = $announcementId !== null;
$announcement = $isEdit ? get_announcement($announcementId) : null;

if ($isEdit && $announcement === null) {
    set_flash('error', 'Announcement not found.');
    redirect($academicRoutePrefix . '/announcements/index.php');
}

$user = current_user();
$pageTitle = $isEdit ? 'Edit Announcement' : 'Create Announcement';
$errors = [];
$selectedTargets = $isEdit ? ($announcement['target_roles'] ?? []) : [];
$form = [
    'title' => $isEdit ? (string) $announcement['title'] : '',
    'message' => $isEdit ? (string) $announcement['message'] : '',
    'published_at' => $isEdit ? announcement_datetime_local_value(isset($announcement['published_at']) ? (string) $announcement['published_at'] : null) : '',
    'expires_at' => $isEdit ? announcement_datetime_local_value(isset($announcement['expires_at']) ? (string) $announcement['expires_at'] : null) : '',
    'status' => $isEdit ? (string) $announcement['status'] : 'DRAFT',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/announcements/' . ($isEdit ? 'edit.php?id=' . $announcementId : 'create.php'));
    }

    $form['title'] = trim((string) ($_POST['title'] ?? ''));
    $form['message'] = trim((string) ($_POST['message'] ?? ''));
    $form['published_at'] = trim((string) ($_POST['published_at'] ?? ''));
    $form['expires_at'] = trim((string) ($_POST['expires_at'] ?? ''));
    $form['status'] = trim((string) ($_POST['status'] ?? ''));
    $postedTargets = $_POST['target_roles'] ?? [];
    $selectedTargets = is_array($postedTargets) ? $postedTargets : [];

    try {
        $payload = [
            'title' => $form['title'],
            'message' => $form['message'],
            'target_roles' => $selectedTargets,
            'published_at' => $form['published_at'] !== '' ? $form['published_at'] : null,
            'expires_at' => $form['expires_at'] !== '' ? $form['expires_at'] : null,
            'status' => $form['status'],
        ];

        if ($isEdit) {
            update_announcement($announcementId, $payload);
            set_flash('success', 'Announcement updated.');
        } else {
            $announcementId = create_announcement((int) $user['user_id'], $payload);
            set_flash('success', 'Announcement created.');
        }

        redirect($academicRoutePrefix . '/announcements/view.php?id=' . $announcementId);
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    }
}

$allSelected = $selectedTargets === announcement_selectable_roles()
    || count(array_intersect(announcement_selectable_roles(), $selectedTargets)) === 4;

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <?php if ($isEdit): ?>
                <input type="hidden" name="announcement_id" value="<?= (int) $announcementId ?>">
            <?php endif; ?>

            <div class="mb-3">
                <label for="title" class="form-label">Title</label>
                <input type="text" class="form-control" id="title" name="title" maxlength="200" required value="<?= e($form['title']) ?>">
            </div>

            <div class="mb-3">
                <label for="message" class="form-label">Message</label>
                <textarea class="form-control" id="message" name="message" rows="8" required><?= e($form['message']) ?></textarea>
                <div class="form-text">Plain text only. Line breaks are kept when displayed.</div>
            </div>

            <div class="mb-3">
                <div class="form-label">Target Audience</div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="target_select_all" <?= $allSelected ? 'checked' : '' ?>>
                    <label class="form-check-label" for="target_select_all">Select All</label>
                </div>
                <?php foreach (announcement_selectable_roles() as $role): ?>
                    <div class="form-check">
                        <input class="form-check-input announcement-target-role" type="checkbox" name="target_roles[]" id="target_role_<?= e($role) ?>" value="<?= e($role) ?>" <?= in_array($role, $selectedTargets, true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="target_role_<?= e($role) ?>"><?= e(announcement_target_label($role)) ?></label>
                    </div>
                <?php endforeach; ?>
                <div class="form-text">Select at least one audience. Select All saves Students, Lecturers, Academic Staff, and Admin.</div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status" required>
                        <?php foreach (announcement_statuses() as $status): ?>
                            <option value="<?= e($status) ?>" <?= $form['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="published_at" class="form-label">Publish date/time</label>
                    <input type="datetime-local" class="form-control" id="published_at" name="published_at" value="<?= e($form['published_at']) ?>">
                    <div class="form-text">Leave blank when publishing to use the current application time. Future times stay hidden until then.</div>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="expires_at" class="form-label">Expiry date/time (optional)</label>
                    <input type="datetime-local" class="form-control" id="expires_at" name="expires_at" value="<?= e($form['expires_at']) ?>">
                    <div class="form-text">Leave blank for no expiry. Must be on or after the publish time.</div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create announcement' ?></button>
                <a href="<?= e(app_url($academicRoutePrefix . '/announcements/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const selectAll = document.getElementById('target_select_all');
    const boxes = Array.from(document.querySelectorAll('.announcement-target-role'));
    if (!selectAll || boxes.length === 0) {
        return;
    }

    function syncSelectAll() {
        const checked = boxes.filter(function (box) { return box.checked; }).length;
        selectAll.checked = checked === boxes.length;
        selectAll.indeterminate = checked > 0 && checked < boxes.length;
    }

    selectAll.addEventListener('change', function () {
        boxes.forEach(function (box) {
            box.checked = selectAll.checked;
        });
        selectAll.indeterminate = false;
    });

    boxes.forEach(function (box) {
        box.addEventListener('change', syncSelectAll);
    });

    syncSelectAll();
})();
</script>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
