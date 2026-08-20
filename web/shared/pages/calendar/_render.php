<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
/** @var string $calendarPath */
/** @var string $view */
/** @var string $anchorDate */
/** @var string $today */
/** @var string $heading */
/** @var string $prevAnchor */
/** @var string $nextAnchor */
/** @var list<string> $gridDays */
/** @var DateTimeImmutable $anchor */
/** @var DateTimeZone $tz */
/** @var list<array<string, mixed>> $calendarEvents */
$calendarEvents = $calendarEvents ?? [];
/** @var array<string, list<array<string, mixed>>> $eventsByDate */
$eventsByDate = $eventsByDate ?? calendar_group_events_by_date($calendarEvents);
/** @var bool $showLecturer */
$showLecturer = $showLecturer ?? false;
/** @var array<string, scalar> $filterQueryForNav */
$filterQueryForNav = $filterQueryForNav ?? [];
/** @var bool $calendarAllowSessionOpen */
$calendarAllowSessionOpen = $calendarAllowSessionOpen ?? true;

$emptyFilterMessage = 'No events match the selected filters for this period.';
$statusLegend = [
    'SCHEDULED' => 'Scheduled',
    'IN_PROGRESS' => 'In Progress',
    'COMPLETED' => 'Completed',
    'CANCELLED' => 'Cancelled',
];
$typeLegend = [
    'LECTURE' => 'Lecture',
    'PRESENTATION' => 'Presentation',
    'EXAM' => 'Exam',
    'PRACTICAL' => 'Practical',
];
?>

<div class="app-cal-toolbar d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="btn-group app-cal-toolbar__views" role="group" aria-label="Calendar view">
        <a href="<?= e(calendar_url($calendarPath, 'month', $anchorDate, $filterQueryForNav)) ?>" class="btn btn-sm <?= $view === 'month' ? 'btn-primary' : 'btn-outline-primary' ?>">Month</a>
        <a href="<?= e(calendar_url($calendarPath, 'week', $anchorDate, $filterQueryForNav)) ?>" class="btn btn-sm <?= $view === 'week' ? 'btn-primary' : 'btn-outline-primary' ?>">Week</a>
        <a href="<?= e(calendar_url($calendarPath, 'today', $today, $filterQueryForNav)) ?>" class="btn btn-sm <?= $view === 'today' ? 'btn-primary' : 'btn-outline-primary' ?>">Today</a>
    </div>
    <div class="app-cal-toolbar__nav d-flex align-items-center gap-2 flex-wrap">
        <a href="<?= e(calendar_url($calendarPath, $view, $prevAnchor, $filterQueryForNav)) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Previous period">&larr;</a>
        <strong class="app-cal-toolbar__heading text-nowrap"><?= e($heading) ?></strong>
        <a href="<?= e(calendar_url($calendarPath, $view, $nextAnchor, $filterQueryForNav)) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Next period">&rarr;</a>
        <?php if ($anchorDate !== $today): ?>
            <a href="<?= e(calendar_url($calendarPath, $view, $today, $filterQueryForNav)) ?>" class="btn btn-sm btn-outline-primary">Go to today</a>
        <?php endif; ?>
    </div>
</div>

<div class="app-cal-legend mb-2" aria-label="Event type legend">
    <?php foreach ($typeLegend as $typeKey => $typeLabel): ?>
        <span class="app-cal-legend__item">
            <span class="app-cal-legend__swatch <?= e(calendar_event_type_class($typeKey)) ?>" aria-hidden="true"></span>
            <span class="app-cal-legend__label"><?= e($typeLabel) ?></span>
        </span>
    <?php endforeach; ?>
</div>
<div class="app-cal-legend mb-3" aria-label="Lecture session status legend">
    <?php foreach ($statusLegend as $statusKey => $statusLabel): ?>
        <span class="app-cal-legend__item">
            <span class="app-cal-legend__swatch <?= e(calendar_session_status_class($statusKey)) ?>" aria-hidden="true"></span>
            <span class="app-cal-legend__label"><?= e($statusLabel) ?></span>
        </span>
    <?php endforeach; ?>
</div>

<?php if ($calendarEvents === []): ?>
    <div class="alert alert-light border mb-4">
        <?php if ($filterQueryForNav !== []): ?>
            <?= e($emptyFilterMessage) ?>
        <?php elseif ($view === 'today'): ?>
            No lectures or scheduled assessments for this day.
        <?php elseif ($view === 'week'): ?>
            No lectures or scheduled assessments this week.
        <?php else: ?>
            No lectures or scheduled assessments this month.
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
$renderEvent = static function (array $event, bool $cardLayout, bool $showLecturer, bool $allowSessionOpen): void {
    $type = (string) ($event['event_type'] ?? 'LECTURE');
    $typeLabel = calendar_event_type_label($type);
    $timeLabel = format_time_display($event['start_time']) . '–' . format_time_display($event['end_time']);
    $typeClass = calendar_event_type_class($type);
    $statusClass = !empty($event['is_lecture'])
        ? calendar_session_status_class((string) $event['status'])
        : '';
    $classes = trim('lecturer-cal-event ' . ($cardLayout ? 'lecturer-cal-event-card w-100 text-start border rounded p-3 bg-white ' : 'w-100 text-start border-0 rounded px-2 py-1 mb-1 small ') . $typeClass . ' ' . $statusClass);
    $detailUrl = trim((string) ($event['detail_url'] ?? ''));
    $isLecture = !empty($event['is_lecture']);

    if ($isLecture && $allowSessionOpen) {
        ?>
        <button type="button"
                class="<?= e($classes) ?>"
                data-bs-toggle="modal"
                data-bs-target="#sessionDetailModal"
                data-session-id="<?= e((string) $event['source_id']) ?>"
                data-module-code="<?= e((string) $event['module_code']) ?>"
                data-module-name="<?= e((string) $event['module_name']) ?>"
                data-batch-name="<?= e((string) $event['batch_name']) ?>"
                data-lecturer-name="<?= e((string) $event['lecturer_name']) ?>"
                data-session-date="<?= e((string) $event['event_date']) ?>"
                data-scheduled-start="<?= e(format_time_display($event['start_time'])) ?>"
                data-scheduled-end="<?= e(format_time_display($event['end_time'])) ?>"
                data-room="<?= e((string) $event['location']) ?>"
                data-status="<?= e((string) $event['status']) ?>">
            <div class="fw-semibold text-truncate"><?= e($typeLabel) ?></div>
            <div class="text-truncate"><?= e((string) $event['module_code'] . ' · ' . (string) $event['title']) ?></div>
            <?php if ($showLecturer && (string) $event['lecturer_name'] !== ''): ?>
                <div class="text-truncate"><?= e((string) $event['lecturer_name']) ?></div>
            <?php endif; ?>
            <div class="text-muted text-truncate"><?= e($timeLabel) ?></div>
            <div class="app-cal-event-status small"><?= e((string) $event['status']) ?></div>
        </button>
        <?php
        return;
    }

    if ($detailUrl !== '') {
        ?>
        <a href="<?= e($detailUrl) ?>" class="<?= e($classes) ?> text-decoration-none text-body">
            <div class="fw-semibold text-truncate"><?= e($typeLabel) ?></div>
            <div class="text-truncate"><?= e((string) $event['module_code'] . ' · ' . (string) $event['title']) ?></div>
            <?php if ($showLecturer && (string) $event['lecturer_name'] !== ''): ?>
                <div class="text-truncate"><?= e((string) $event['lecturer_name']) ?></div>
            <?php endif; ?>
            <div class="text-muted text-truncate"><?= e($timeLabel) ?></div>
            <?php if ((string) $event['location'] !== ''): ?>
                <div class="text-muted text-truncate small"><?= e((string) $event['location']) ?></div>
            <?php endif; ?>
            <div class="app-cal-event-status small"><?= e((string) $event['status']) ?></div>
            <span class="visually-hidden"><?= e($typeLabel . ' details') ?></span>
        </a>
        <?php
        return;
    }

    ?>
    <div class="<?= e($classes) ?>">
        <div class="fw-semibold text-truncate"><?= e($typeLabel) ?></div>
        <div class="text-truncate"><?= e((string) $event['module_code'] . ' · ' . (string) $event['title']) ?></div>
        <div class="text-muted text-truncate"><?= e($timeLabel) ?></div>
        <div class="app-cal-event-status small"><?= e((string) $event['status']) ?></div>
    </div>
    <?php
};
?>

<?php if ($view === 'today'): ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <?php if (($eventsByDate[$anchorDate] ?? []) === []): ?>
                <p class="text-muted mb-0"><?= $filterQueryForNav !== [] ? e($emptyFilterMessage) : 'No events today.' ?></p>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($eventsByDate[$anchorDate] as $event): ?>
                        <div class="col-md-6 col-lg-4">
                            <?php $renderEvent($event, true, $showLecturer, $calendarAllowSessionOpen); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="card shadow-sm lecturer-cal-grid-card">
        <div class="table-responsive">
            <table class="table table-bordered lecturer-cal-grid mb-0">
                <thead class="table-light">
                    <tr>
                        <?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $weekdayLabel): ?>
                            <th class="text-center small"><?= e($weekdayLabel) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($row = 0, $rowCount = (int) ceil(count($gridDays) / 7); $row < $rowCount; $row++): ?>
                        <tr>
                            <?php for ($col = 0; $col < 7; $col++): ?>
                                <?php
                                $index = ($row * 7) + $col;
                                if (!isset($gridDays[$index])) {
                                    echo '<td class="lecturer-cal-cell lecturer-cal-cell--empty"></td>';
                                    continue;
                                }
                                $dayKey = $gridDays[$index];
                                $dayDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dayKey, $tz);
                                $isCurrentMonth = $view !== 'month' || ($dayDate instanceof DateTimeImmutable && $dayDate->format('n') === $anchor->format('n'));
                                $isToday = $dayKey === $today;
                                $dayEvents = $eventsByDate[$dayKey] ?? [];
                                ?>
                                <td class="lecturer-cal-cell align-top <?= $isCurrentMonth ? '' : 'lecturer-cal-cell--muted' ?><?= $isToday ? ' lecturer-cal-cell--today' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <a href="<?= e(calendar_url($calendarPath, 'today', $dayKey, $filterQueryForNav)) ?>" class="lecturer-cal-day-number small fw-semibold text-decoration-none"><?= e($dayDate instanceof DateTimeImmutable ? $dayDate->format('j') : '') ?></a>
                                        <?php if ($isToday): ?><span class="badge text-bg-primary">Today</span><?php endif; ?>
                                    </div>
                                    <div class="lecturer-cal-events">
                                        <?php foreach ($dayEvents as $event): ?>
                                            <?php $renderEvent($event, false, $showLecturer, $calendarAllowSessionOpen); ?>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($calendarAllowSessionOpen): ?>
<div class="modal fade" id="sessionDetailModal" tabindex="-1" aria-labelledby="sessionDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="sessionDetailModalLabel">Session details</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Module</dt>
                    <dd class="col-sm-8" id="sessionDetailModule">-</dd>
                    <dt class="col-sm-4">Batch</dt>
                    <dd class="col-sm-8" id="sessionDetailBatch">-</dd>
                    <?php if ($showLecturer): ?>
                        <dt class="col-sm-4">Lecturer</dt>
                        <dd class="col-sm-8" id="sessionDetailLecturer">-</dd>
                    <?php endif; ?>
                    <dt class="col-sm-4">Date</dt>
                    <dd class="col-sm-8" id="sessionDetailDate">-</dd>
                    <dt class="col-sm-4">Scheduled start</dt>
                    <dd class="col-sm-8" id="sessionDetailStart">-</dd>
                    <dt class="col-sm-4">Scheduled end</dt>
                    <dd class="col-sm-8" id="sessionDetailEnd">-</dd>
                    <dt class="col-sm-4">Room</dt>
                    <dd class="col-sm-8" id="sessionDetailRoom">-</dd>
                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8"><span id="sessionDetailStatus" class="badge">-</span></dd>
                </dl>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <a href="#" id="sessionDetailOpenLink" class="btn btn-primary">Open Session</a>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const modal = document.getElementById('sessionDetailModal');
    if (!modal) {
        return;
    }

    const showLecturer = <?= $showLecturer ? 'true' : 'false' ?>;
    const badgeClasses = {
        SCHEDULED: 'text-bg-primary',
        IN_PROGRESS: 'text-bg-success',
        COMPLETED: 'text-bg-secondary',
        CANCELLED: 'text-bg-danger',
    };

    modal.addEventListener('show.bs.modal', (event) => {
        const trigger = event.relatedTarget;
        if (!trigger) {
            return;
        }

        const moduleCode = trigger.getAttribute('data-module-code') || '-';
        const moduleName = trigger.getAttribute('data-module-name') || '';
        const batchName = trigger.getAttribute('data-batch-name') || '-';
        const lecturerName = trigger.getAttribute('data-lecturer-name') || '-';
        const sessionDate = trigger.getAttribute('data-session-date') || '-';
        const scheduledStart = trigger.getAttribute('data-scheduled-start') || '-';
        const scheduledEnd = trigger.getAttribute('data-scheduled-end') || '-';
        const room = trigger.getAttribute('data-room') || '';
        const status = trigger.getAttribute('data-status') || '-';
        const sessionId = trigger.getAttribute('data-session-id') || '';

        document.getElementById('sessionDetailModule').textContent = moduleName
            ? moduleCode + ' – ' + moduleName
            : moduleCode;
        document.getElementById('sessionDetailBatch').textContent = batchName;
        if (showLecturer) {
            const lecturerEl = document.getElementById('sessionDetailLecturer');
            if (lecturerEl) {
                lecturerEl.textContent = lecturerName;
            }
        }
        document.getElementById('sessionDetailDate').textContent = sessionDate;
        document.getElementById('sessionDetailStart').textContent = scheduledStart;
        document.getElementById('sessionDetailEnd').textContent = scheduledEnd;
        document.getElementById('sessionDetailRoom').textContent = room !== '' ? room : '-';

        const statusBadge = document.getElementById('sessionDetailStatus');
        statusBadge.textContent = status;
        statusBadge.className = 'badge ' + (badgeClasses[status] || 'text-bg-light');

        const openLink = document.getElementById('sessionDetailOpenLink');
        openLink.href = <?= json_encode(app_url($academicRoutePrefix . '/sessions/view.php?id='), JSON_THROW_ON_ERROR) ?> + encodeURIComponent(sessionId);
    });
})();
</script>
<?php endif; ?>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
