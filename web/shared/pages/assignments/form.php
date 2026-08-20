<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'lecturer';
/** @var int $restrictLecturerId */
$restrictLecturerId = $restrictLecturerId ?? 0;

if ($restrictLecturerId <= 0) {
    set_flash('error', 'No lecturer profile is linked to this account.');
    redirect($academicRoutePrefix . '/dashboard.php');
}

$assignmentId = positive_int($_GET['id'] ?? $_POST['assignment_id'] ?? null);
$isEdit = $assignmentId !== null;
$assignment = $isEdit ? get_coursework_assignment($assignmentId) : null;

if ($isEdit && ($assignment === null || !lecturer_can_manage_coursework_assignment($restrictLecturerId, $assignment))) {
    deny_access();
}

$taughtModules = list_module_lecturer_assignments(null, $restrictLecturerId);
$pageTitle = $isEdit ? 'Edit Activity' : 'Create Activity';
$errors = [];

$defaultType = $isEdit
    ? strtoupper(trim((string) ($assignment['activity_type'] ?? 'ASSIGNMENT')))
    : 'ASSIGNMENT';

$form = [
    'activity_type' => $defaultType,
    'module_id' => $isEdit ? (string) $assignment['module_id'] : '',
    'title' => $isEdit ? (string) $assignment['title'] : '',
    'description' => $isEdit ? (string) ($assignment['description'] ?? '') : '',
    'due_date' => $isEdit ? str_replace(' ', 'T', substr((string) $assignment['due_date'], 0, 16)) : '',
    'scheduled_date' => $isEdit ? (string) ($assignment['scheduled_date'] ?? '') : '',
    'start_time' => $isEdit ? format_assignment_time_hm($assignment['start_time'] ?? null) : '',
    'end_time' => $isEdit ? format_assignment_time_hm($assignment['end_time'] ?? null) : '',
    'room' => $isEdit ? (string) ($assignment['room'] ?? '') : '',
    'max_marks' => $isEdit ? (string) $assignment['max_marks'] : '100',
    'status' => $isEdit ? (string) $assignment['status'] : 'DRAFT',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/assignments/' . ($isEdit ? 'edit.php?id=' . $assignmentId : 'create.php'));
    }

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $form['activity_type'] = strtoupper($form['activity_type']);

    $moduleId = positive_int($form['module_id']);
    $maxMarks = filter_var($form['max_marks'], FILTER_VALIDATE_FLOAT);
    $requiresSubmission = false;
    $requiresSchedule = false;

    try {
        $activityType = normalize_assignment_activity_type($form['activity_type']);
        $form['activity_type'] = $activityType;
        $requiresSubmission = assignment_requires_submission($activityType);
        $requiresSchedule = assignment_requires_schedule($activityType);
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    }

    if ($moduleId === null || !lecturer_is_assigned_to_module($restrictLecturerId, $moduleId)) {
        $errors[] = 'Select a module you are assigned to teach.';
    }
    if ($form['title'] === '') {
        $errors[] = 'Title is required.';
    }
    if ($maxMarks === false || $maxMarks <= 0) {
        $errors[] = 'Max marks must be greater than 0.';
    }
    if (!in_array($form['status'], assignment_statuses(), true)) {
        $errors[] = 'Select a valid status.';
    }

    $due = null;
    $scheduledDate = null;
    $startTime = null;
    $endTime = null;
    $room = null;
    $newFilePath = $isEdit ? ($assignment['file_path'] ?? null) : null;
    $oldFilePath = $isEdit ? ($assignment['file_path'] ?? null) : null;
    $storedNewFile = null;
    $removeFile = isset($_POST['remove_file']);
    $upload = $_FILES['brief_file'] ?? null;
    $hasUpload = is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($errors === [] && $requiresSchedule) {
        try {
            $schedule = normalize_assignment_schedule(
                $form['scheduled_date'],
                $form['start_time'],
                $form['end_time'],
                true
            );
            $scheduledDate = $schedule['date'];
            $startTime = $schedule['start'];
            $endTime = $schedule['end'];
            $room = normalize_assignment_room($form['room']);
            // Compatibility: due_date mirrors scheduled end (NOT NULL column).
            $due = parse_app_datetime(assignment_due_date_from_schedule((string) $scheduledDate, (string) $endTime));
            if ($due === null) {
                $errors[] = 'Unable to derive a valid schedule timestamp.';
            }
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }
    } elseif ($errors === []) {
        $due = parse_app_datetime(str_replace('T', ' ', $form['due_date']));
        if ($due === null) {
            $errors[] = 'Enter a valid due date and time.';
        }
        $scheduledDate = null;
        $startTime = null;
        $endTime = null;
        $room = null;
    }

    if ($errors === [] && $requiresSubmission) {
        if ($hasUpload) {
            try {
                $storedNewFile = assignment_store_uploaded_file($upload, 'briefs');
                $newFilePath = $storedNewFile['relative_path'];
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }
        } elseif ($removeFile && !$hasUpload) {
            $newFilePath = null;
        }
    } elseif ($errors === [] && !$requiresSubmission) {
        $newFilePath = null;
        if ($hasUpload) {
            $errors[] = 'Brief attachments are not used for Exam or Practical activities.';
        }
    }

    if ($errors === []) {
        $payload = [
            'module_id' => $moduleId,
            'title' => $form['title'],
            'description' => $form['description'] !== '' ? $form['description'] : null,
            'activity_type' => $form['activity_type'],
            'due_date' => $due->format('Y-m-d H:i:s'),
            'scheduled_date' => $scheduledDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'room' => $room,
            'max_marks' => round((float) $maxMarks, 2),
            'status' => $form['status'],
            'file_path' => $newFilePath,
        ];
        try {
            if ($isEdit) {
                update_coursework_assignment($assignmentId, $restrictLecturerId, $payload);
                if ($requiresSubmission) {
                    if ($storedNewFile !== null && is_string($oldFilePath) && $oldFilePath !== $newFilePath) {
                        assignment_delete_stored_file($oldFilePath);
                    } elseif ($removeFile && is_string($oldFilePath) && $newFilePath === null) {
                        assignment_delete_stored_file($oldFilePath);
                    }
                } elseif (is_string($oldFilePath) && $oldFilePath !== '') {
                    assignment_delete_stored_file($oldFilePath);
                }
                set_flash('success', 'Activity updated.');
            } else {
                $assignmentId = create_coursework_assignment($restrictLecturerId, $payload);
                set_flash('success', 'Activity created.');
            }
            redirect($academicRoutePrefix . '/assignments/view.php?id=' . $assignmentId);
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
            if ($storedNewFile !== null) {
                assignment_delete_stored_file($storedNewFile['relative_path']);
            }
        } catch (Throwable $exception) {
            error_log('Assignment save failed: ' . $exception->getMessage());
            $errors[] = 'Unable to save the activity.';
            if ($storedNewFile !== null) {
                assignment_delete_stored_file($storedNewFile['relative_path']);
            }
        }
    } elseif ($storedNewFile !== null) {
        assignment_delete_stored_file($storedNewFile['relative_path']);
    }
}

$isAssignment = $form['activity_type'] === 'ASSIGNMENT';
$isPresentation = $form['activity_type'] === 'PRESENTATION';
$isScheduled = in_array($form['activity_type'], ['PRESENTATION', 'EXAM', 'PRACTICAL'], true);
$allowsBrief = in_array($form['activity_type'], ['ASSIGNMENT', 'PRESENTATION'], true);

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
        <form method="post" enctype="multipart/form-data" id="coursework-activity-form">
            <?= csrf_field() ?>
            <?php if ($isEdit): ?>
                <input type="hidden" name="assignment_id" value="<?= e((string) $assignmentId) ?>">
            <?php endif; ?>
            <p class="app-required-note"><span class="app-required-note__mark" aria-hidden="true">*</span> <span class="visually-hidden">Asterisk means </span>Required</p>

            <div class="app-form-section">
                <h2 class="app-form-section__title">Activity Details</h2>
                <div class="mb-3">
                    <label for="activity_type" class="form-label app-required">Activity Type</label>
                    <select class="form-select" id="activity_type" name="activity_type" required>
                        <?php foreach (assignment_activity_types() as $type): ?>
                            <option value="<?= e($type) ?>" <?= $form['activity_type'] === $type ? 'selected' : '' ?>>
                                <?= e(assignment_activity_type_label($type)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="module_id" class="form-label app-required">Module</label>
                    <select class="form-select" id="module_id" name="module_id" required>
                        <option value="">Select module</option>
                        <?php foreach ($taughtModules as $module): ?>
                            <option value="<?= e((string) $module['module_id']) ?>" <?= $form['module_id'] === (string) $module['module_id'] ? 'selected' : '' ?>>
                                <?= e($module['module_code'] . ' – ' . $module['module_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="title" class="form-label app-required">Title</label>
                    <input type="text" class="form-control" id="title" name="title" maxlength="200" required value="<?= e($form['title']) ?>">
                </div>
                <div class="mb-0">
                    <label for="description" class="form-label" id="description-label">Instructions / Description</label>
                    <textarea class="form-control" id="description" name="description" rows="6"><?= e($form['description']) ?></textarea>
                </div>
            </div>

            <div class="app-form-section" id="section-due"<?= $isAssignment ? '' : ' hidden' ?>>
                <h2 class="app-form-section__title">Due Date &amp; Marks</h2>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="due_date" class="form-label app-required">Due date and time</label>
                        <input type="datetime-local" class="form-control" id="due_date" name="due_date"
                               value="<?= e($form['due_date']) ?>"<?= $isAssignment ? ' required' : ' disabled' ?>>
                        <div class="form-text">Times use <?= e(APP_TIMEZONE) ?>.</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="max_marks_due" class="form-label app-required">Maximum marks</label>
                        <input type="number" class="form-control js-max-marks" id="max_marks_due" name="max_marks" min="0.01" step="0.01"
                               value="<?= e($form['max_marks']) ?>"<?= $isAssignment ? ' required' : ' disabled' ?>>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="status_due" class="form-label app-required">Status</label>
                        <select class="form-select js-status" id="status_due" name="status"<?= $isAssignment ? '' : ' disabled' ?>>
                            <?php foreach (assignment_statuses() as $status): ?>
                                <option value="<?= e($status) ?>" <?= $form['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Students never see DRAFT activities.</div>
                    </div>
                </div>
            </div>

            <div class="app-form-section" id="section-schedule"<?= $isScheduled ? '' : ' hidden' ?>>
                <h2 class="app-form-section__title" id="schedule-section-title"><?= $isPresentation ? 'Presentation Schedule &amp; Marks' : 'Schedule &amp; Marks' ?></h2>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="scheduled_date" class="form-label app-required" id="scheduled-date-label"><?= $isPresentation ? 'Presentation date' : 'Scheduled date' ?></label>
                        <input type="date" class="form-control" id="scheduled_date" name="scheduled_date"
                               value="<?= e($form['scheduled_date']) ?>"<?= $isScheduled ? ' required' : ' disabled' ?>>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="start_time" class="form-label app-required">Start time</label>
                        <input type="time" class="form-control" id="start_time" name="start_time"
                               value="<?= e($form['start_time']) ?>"<?= $isScheduled ? ' required' : ' disabled' ?>>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="end_time" class="form-label app-required">End time</label>
                        <input type="time" class="form-control" id="end_time" name="end_time"
                               value="<?= e($form['end_time']) ?>"<?= $isScheduled ? ' required' : ' disabled' ?>>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="room" class="form-label">Room / Location</label>
                        <input type="text" class="form-control" id="room" name="room" maxlength="150"
                               value="<?= e($form['room']) ?>"<?= $isScheduled ? '' : ' disabled' ?>>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="max_marks_schedule" class="form-label app-required">Maximum marks</label>
                        <input type="number" class="form-control js-max-marks" id="max_marks_schedule" name="max_marks" min="0.01" step="0.01"
                               value="<?= e($form['max_marks']) ?>"<?= $isScheduled ? ' required' : ' disabled' ?>>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="status_schedule" class="form-label app-required">Status</label>
                        <select class="form-select js-status" id="status_schedule" name="status"<?= $isScheduled ? '' : ' disabled' ?>>
                            <?php foreach (assignment_statuses() as $status): ?>
                                <option value="<?= e($status) ?>" <?= $form['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Students never see DRAFT activities.</div>
                    </div>
                </div>
            </div>

            <div class="app-form-section" id="section-attachment"<?= $allowsBrief ? '' : ' hidden' ?>>
                <h2 class="app-form-section__title">Optional Brief Attachment</h2>
                <div class="mb-0">
                    <label for="brief_file" class="form-label">Attachment</label>
                    <input type="file" class="form-control" id="brief_file" name="brief_file" accept=".pdf,.doc,.docx,.zip"
                        <?= $allowsBrief ? '' : ' disabled' ?>>
                    <div class="form-text">PDF, DOC, DOCX, or ZIP. Maximum <?= e((string) max(1, (int) round(ASSIGNMENT_UPLOAD_MAX_BYTES / 1048576))) ?> MB.</div>
                    <?php if ($isEdit && !empty($assignment['file_path'])): ?>
                        <div class="form-check mt-2" id="remove-file-wrap">
                            <input class="form-check-input" type="checkbox" id="remove_file" name="remove_file" value="1"
                                <?= $allowsBrief ? '' : ' disabled' ?>>
                            <label class="form-check-label" for="remove_file">Remove current attachment</label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="app-form-actions">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create Activity' ?></button>
                <a class="btn btn-outline-secondary" href="<?= e(app_url($academicRoutePrefix . '/assignments/index.php')) ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var typeSelect = document.getElementById('activity_type');
    if (!typeSelect) {
        return;
    }

    var sectionDue = document.getElementById('section-due');
    var sectionSchedule = document.getElementById('section-schedule');
    var sectionAttachment = document.getElementById('section-attachment');
    var dueInput = document.getElementById('due_date');
    var scheduledDate = document.getElementById('scheduled_date');
    var startTime = document.getElementById('start_time');
    var endTime = document.getElementById('end_time');
    var room = document.getElementById('room');
    var brief = document.getElementById('brief_file');
    var removeFile = document.getElementById('remove_file');
    var descriptionLabel = document.getElementById('description-label');
    var scheduleTitle = document.getElementById('schedule-section-title');
    var scheduledDateLabel = document.getElementById('scheduled-date-label');
    var maxMarksFields = document.querySelectorAll('.js-max-marks');
    var statusFields = document.querySelectorAll('.js-status');

    function setDisabled(el, disabled) {
        if (!el) {
            return;
        }
        el.disabled = !!disabled;
        if (disabled) {
            el.removeAttribute('required');
        }
    }

    function setSectionVisible(section, visible) {
        if (!section) {
            return;
        }
        if (visible) {
            section.removeAttribute('hidden');
        } else {
            section.setAttribute('hidden', 'hidden');
        }
    }

    function syncMaxMarks(source) {
        maxMarksFields.forEach(function (field) {
            if (field !== source && !field.disabled) {
                field.value = source.value;
            }
        });
    }

    function syncStatus(source) {
        statusFields.forEach(function (field) {
            if (field !== source && !field.disabled) {
                field.value = source.value;
            }
        });
    }

    maxMarksFields.forEach(function (field) {
        field.addEventListener('input', function () {
            syncMaxMarks(field);
        });
    });
    statusFields.forEach(function (field) {
        field.addEventListener('change', function () {
            syncStatus(field);
        });
    });

    function applyType() {
        var type = (typeSelect.value || '').toUpperCase();
        var isAssignment = type === 'ASSIGNMENT';
        var isPresentation = type === 'PRESENTATION';
        var isScheduled = type === 'PRESENTATION' || type === 'EXAM' || type === 'PRACTICAL';
        var allowsBrief = type === 'ASSIGNMENT' || type === 'PRESENTATION';

        setSectionVisible(sectionDue, isAssignment);
        setSectionVisible(sectionSchedule, isScheduled);
        setSectionVisible(sectionAttachment, allowsBrief);

        setDisabled(dueInput, !isAssignment);
        if (dueInput && isAssignment) {
            dueInput.setAttribute('required', 'required');
        }

        setDisabled(scheduledDate, !isScheduled);
        setDisabled(startTime, !isScheduled);
        setDisabled(endTime, !isScheduled);
        setDisabled(room, !isScheduled);
        if (scheduledDate && isScheduled) {
            scheduledDate.setAttribute('required', 'required');
        }
        if (startTime && isScheduled) {
            startTime.setAttribute('required', 'required');
        }
        if (endTime && isScheduled) {
            endTime.setAttribute('required', 'required');
        }

        setDisabled(brief, !allowsBrief);
        setDisabled(removeFile, !allowsBrief);

        maxMarksFields.forEach(function (field) {
            var inDue = field.closest('#section-due');
            var enable = isAssignment ? !!inDue : (isScheduled ? !inDue : false);
            setDisabled(field, !enable);
            if (enable) {
                field.setAttribute('required', 'required');
            }
        });
        statusFields.forEach(function (field) {
            var inDue = field.closest('#section-due');
            var enable = isAssignment ? !!inDue : (isScheduled ? !inDue : false);
            setDisabled(field, !enable);
        });

        if (descriptionLabel) {
            descriptionLabel.textContent = (type === 'EXAM' || type === 'PRACTICAL')
                ? 'Instructions / Details'
                : 'Instructions / Description';
        }
        if (scheduleTitle) {
            scheduleTitle.innerHTML = isPresentation
                ? 'Presentation Schedule &amp; Marks'
                : 'Schedule &amp; Marks';
        }
        if (scheduledDateLabel) {
            scheduledDateLabel.textContent = isPresentation ? 'Presentation date' : 'Scheduled date';
        }
    }

    typeSelect.addEventListener('change', applyType);
    applyType();
})();
</script>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
