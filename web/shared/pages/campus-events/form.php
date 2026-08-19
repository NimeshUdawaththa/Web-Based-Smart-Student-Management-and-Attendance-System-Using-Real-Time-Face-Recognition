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

$campusEventId = positive_int($_GET['id'] ?? $_POST['campus_event_id'] ?? null);
$isEdit = $campusEventId !== null;
$campusEvent = $isEdit ? get_campus_event($campusEventId) : null;

if ($isEdit && $campusEvent === null) {
    set_flash('error', 'Event not found.');
    redirect($academicRoutePrefix . '/events/index.php');
}

$user = current_user();
$pageTitle = $isEdit ? 'Edit Event' : 'Create Event';
$errors = [];
$selectedTargets = $isEdit ? ($campusEvent['target_roles'] ?? []) : [];
$existingPoster = $isEdit && !empty($campusEvent['poster_path']) ? (string) $campusEvent['poster_path'] : null;
$form = [
    'title' => $isEdit ? (string) $campusEvent['title'] : '',
    'description' => $isEdit ? (string) ($campusEvent['description'] ?? '') : '',
    'start_datetime' => $isEdit ? campus_event_datetime_local_value(isset($campusEvent['start_datetime']) ? (string) $campusEvent['start_datetime'] : null) : '',
    'end_datetime' => $isEdit ? campus_event_datetime_local_value(isset($campusEvent['end_datetime']) ? (string) $campusEvent['end_datetime'] : null) : '',
    'location' => $isEdit ? (string) ($campusEvent['location'] ?? '') : '',
    'published_at' => $isEdit ? campus_event_datetime_local_value(isset($campusEvent['published_at']) ? (string) $campusEvent['published_at'] : null) : '',
    'status' => $isEdit ? (string) $campusEvent['status'] : 'DRAFT',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/events/' . ($isEdit ? 'edit.php?id=' . $campusEventId : 'create.php'));
    }

    foreach (['title', 'description', 'start_datetime', 'end_datetime', 'location', 'published_at', 'status'] as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $postedTargets = $_POST['target_roles'] ?? [];
    $selectedTargets = is_array($postedTargets) ? $postedTargets : [];

    $newPoster = null;
    $upload = $_FILES['poster'] ?? null;
    $hasUpload = is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    try {
        if ($hasUpload) {
            $newPoster = campus_event_store_poster($upload);
        }

        $payload = [
            'title' => $form['title'],
            'description' => $form['description'],
            'start_datetime' => $form['start_datetime'],
            'end_datetime' => $form['end_datetime'],
            'location' => $form['location'],
            'target_roles' => $selectedTargets,
            'published_at' => $form['published_at'] !== '' ? $form['published_at'] : null,
            'status' => $form['status'],
        ];
        if ($newPoster !== null) {
            $payload['poster_path'] = $newPoster['relative_path'];
        }

        if ($isEdit) {
            update_campus_event($campusEventId, $payload);
            if ($newPoster !== null && $existingPoster !== null && $existingPoster !== $newPoster['relative_path']) {
                campus_event_delete_poster($existingPoster);
            }
            set_flash('success', 'Event updated.');
        } else {
            $campusEventId = create_campus_event((int) $user['user_id'], $payload);
            set_flash('success', 'Event created.');
        }

        redirect($academicRoutePrefix . '/events/view.php?id=' . $campusEventId);
    } catch (InvalidArgumentException $exception) {
        if ($newPoster !== null) {
            campus_event_delete_poster($newPoster['relative_path']);
        }
        $errors[] = $exception->getMessage();
    } catch (RuntimeException $exception) {
        if ($newPoster !== null) {
            campus_event_delete_poster($newPoster['relative_path']);
        }
        $errors[] = $exception->getMessage();
    }
}

$allSelected = count(array_intersect(campus_event_selectable_roles(), $selectedTargets)) === 4;

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
        <form method="post" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <?php if ($isEdit): ?>
                <input type="hidden" name="campus_event_id" value="<?= (int) $campusEventId ?>">
            <?php endif; ?>

            <div class="mb-3">
                <label for="title" class="form-label">Title</label>
                <input type="text" class="form-control" id="title" name="title" maxlength="200" required value="<?= e($form['title']) ?>">
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea class="form-control" id="description" name="description" rows="6"><?= e($form['description']) ?></textarea>
                <div class="form-text">Plain text only. Line breaks are kept when displayed.</div>
            </div>

            <div class="mb-3">
                <label for="poster" class="form-label">Poster Image (optional)</label>
                <?php if ($isEdit && $existingPoster !== null): ?>
                    <div class="mb-2">
                        <img src="<?= e(campus_event_poster_url($academicRoutePrefix, (int) $campusEventId)) ?>" alt="" class="img-fluid rounded border" style="max-height: 180px;">
                    </div>
                <?php endif; ?>
                <input type="file" class="form-control" id="poster" name="poster" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                <div class="form-text">JPG, PNG, or WEBP. Leave empty when editing to keep the current poster.</div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="start_datetime" class="form-label">Start date/time</label>
                    <input type="datetime-local" class="form-control" id="start_datetime" name="start_datetime" required value="<?= e($form['start_datetime']) ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="end_datetime" class="form-label">End date/time</label>
                    <input type="datetime-local" class="form-control" id="end_datetime" name="end_datetime" required value="<?= e($form['end_datetime']) ?>">
                </div>
            </div>

            <div class="mb-3">
                <label for="location" class="form-label">Location</label>
                <input type="text" class="form-control" id="location" name="location" maxlength="150" value="<?= e($form['location']) ?>">
            </div>

            <div class="mb-3">
                <div class="form-label">Target Audience</div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="target_select_all" <?= $allSelected ? 'checked' : '' ?>>
                    <label class="form-check-label" for="target_select_all">Select All</label>
                </div>
                <?php foreach (campus_event_selectable_roles() as $role): ?>
                    <div class="form-check">
                        <input class="form-check-input campus-event-target-role" type="checkbox" name="target_roles[]" id="target_role_<?= e($role) ?>" value="<?= e($role) ?>" <?= in_array($role, $selectedTargets, true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="target_role_<?= e($role) ?>"><?= e(campus_event_target_label($role)) ?></label>
                    </div>
                <?php endforeach; ?>
                <div class="form-text">Select at least one audience. Select All saves Students, Lecturers, Academic Staff, and Admin.</div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status" required>
                        <?php foreach (campus_event_statuses() as $status): ?>
                            <option value="<?= e($status) ?>" <?= $form['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8 mb-3">
                    <label for="published_at" class="form-label">Publish date/time</label>
                    <input type="datetime-local" class="form-control" id="published_at" name="published_at" value="<?= e($form['published_at']) ?>">
                    <div class="form-text">Leave blank when publishing to use the current application time.</div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create event' ?></button>
                <a href="<?= e(app_url($academicRoutePrefix . '/events/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const selectAll = document.getElementById('target_select_all');
    const boxes = Array.from(document.querySelectorAll('.campus-event-target-role'));
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
