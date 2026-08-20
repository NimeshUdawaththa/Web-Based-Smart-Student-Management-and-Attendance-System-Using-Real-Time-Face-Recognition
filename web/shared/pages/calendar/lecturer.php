<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'lecturer';
/** @var int|null $restrictLecturerId */
$restrictLecturerId = $restrictLecturerId ?? null;

if ($restrictLecturerId === null) {
    set_flash('error', 'No lecturer profile is linked to this account.');
    redirect($academicRoutePrefix . '/dashboard.php');
}

$pageTitle = 'My Calendar';
$today = app_today();
$view = (string) ($_GET['view'] ?? 'month');
if (!in_array($view, ['month', 'week', 'today'], true)) {
    $view = 'month';
}

$anchorDate = validate_date_ymd((string) ($_GET['date'] ?? '')) ? (string) $_GET['date'] : $today;
$tz = new DateTimeZone(APP_TIMEZONE);
$anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $anchorDate, $tz);
if (!$anchor instanceof DateTimeImmutable) {
    $anchorDate = $today;
    $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $anchorDate, $tz);
}

$calendarPath = $academicRoutePrefix . '/schedules/index.php';

if ($view === 'today') {
    $rangeFrom = $anchorDate;
    $rangeTo = $anchorDate;
} elseif ($view === 'week') {
    $weekStart = monday_of_week($anchorDate) ?? $anchorDate;
    $weekStartDate = DateTimeImmutable::createFromFormat('!Y-m-d', $weekStart, $tz);
    $rangeFrom = $weekStart;
    $rangeTo = $weekStartDate instanceof DateTimeImmutable
        ? $weekStartDate->modify('+6 days')->format('Y-m-d')
        : $anchorDate;
} else {
    $firstOfMonth = $anchor->modify('first day of this month');
    $lastOfMonth = $anchor->modify('last day of this month');
    $rangeFrom = monday_of_week($firstOfMonth->format('Y-m-d')) ?? $firstOfMonth->format('Y-m-d');
    $lastOffset = 7 - (int) $lastOfMonth->format('N');
    if ($lastOffset === 7) {
        $lastOffset = 0;
    }
    $rangeTo = $lastOfMonth->modify('+' . $lastOffset . ' days')->format('Y-m-d');
}

$sessions = list_lecture_sessions([
    'lecturer_id' => $restrictLecturerId,
    'from' => $rangeFrom,
    'to' => $rangeTo,
]);

$sessionsByDate = [];
foreach ($sessions as $session) {
    $sessionsByDate[(string) $session['session_date']][] = $session;
}

/**
 * @return list<string>
 */
function calendar_day_keys(string $from, string $to): array
{
    $tz = new DateTimeZone(APP_TIMEZONE);
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
    if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
        return [];
    }

    $days = [];
    for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
        $days[] = $cursor->format('Y-m-d');
    }

    return $days;
}

function calendar_url(string $path, string $view, string $date): string
{
    return app_url($path . '?view=' . rawurlencode($view) . '&date=' . rawurlencode($date));
}

function calendar_session_status_class(string $status): string
{
    return match ($status) {
        'IN_PROGRESS' => 'lecturer-cal-event--in-progress',
        'COMPLETED' => 'lecturer-cal-event--completed',
        'CANCELLED' => 'lecturer-cal-event--cancelled',
        default => 'lecturer-cal-event--scheduled',
    };
}

$gridDays = $view === 'today'
    ? [$anchorDate]
    : calendar_day_keys($rangeFrom, $rangeTo);

if ($view === 'month') {
    $prevAnchor = $anchor->modify('-1 month')->format('Y-m-d');
    $nextAnchor = $anchor->modify('+1 month')->format('Y-m-d');
    $heading = $anchor->format('F Y');
} elseif ($view === 'week') {
    $weekStartDate = DateTimeImmutable::createFromFormat('!Y-m-d', monday_of_week($anchorDate) ?? $anchorDate, $tz);
    $weekEndDate = $weekStartDate instanceof DateTimeImmutable ? $weekStartDate->modify('+6 days') : $anchor;
    $prevAnchor = $anchor->modify('-7 days')->format('Y-m-d');
    $nextAnchor = $anchor->modify('+7 days')->format('Y-m-d');
    $heading = ($weekStartDate?->format('j M Y') ?? $anchorDate) . ' – ' . $weekEndDate->format('j M Y');
} else {
    $prevAnchor = $anchor->modify('-1 day')->format('Y-m-d');
    $nextAnchor = $anchor->modify('+1 day')->format('Y-m-d');
    $heading = $anchor->format('l, j F Y');
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<p class="text-muted mb-4">
    Dated lecture sessions assigned to you. Statuses update when this page is opened.
</p>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="btn-group" role="group" aria-label="Calendar view">
        <a href="<?= e(calendar_url($calendarPath, 'month', $anchorDate)) ?>" class="btn btn-sm <?= $view === 'month' ? 'btn-primary' : 'btn-outline-primary' ?>">Month</a>
        <a href="<?= e(calendar_url($calendarPath, 'week', $anchorDate)) ?>" class="btn btn-sm <?= $view === 'week' ? 'btn-primary' : 'btn-outline-primary' ?>">Week</a>
        <a href="<?= e(calendar_url($calendarPath, 'today', $today)) ?>" class="btn btn-sm <?= $view === 'today' ? 'btn-primary' : 'btn-outline-primary' ?>">Today</a>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="<?= e(calendar_url($calendarPath, $view, $prevAnchor)) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Previous">&larr;</a>
        <strong class="text-nowrap"><?= e($heading) ?></strong>
        <a href="<?= e(calendar_url($calendarPath, $view, $nextAnchor)) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Next">&rarr;</a>
        <?php if ($anchorDate !== $today): ?>
            <a href="<?= e(calendar_url($calendarPath, $view, $today)) ?>" class="btn btn-sm btn-outline-primary">Go to today</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($sessions === []): ?>
    <div class="alert alert-light border mb-4">
        <?php if ($view === 'today'): ?>
            No lecture sessions scheduled for this day.
        <?php elseif ($view === 'week'): ?>
            No lecture sessions scheduled this week.
        <?php else: ?>
            No lecture sessions scheduled this month.
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($view === 'today'): ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <?php if (($sessionsByDate[$anchorDate] ?? []) === []): ?>
                <p class="text-muted mb-0">No sessions today.</p>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($sessionsByDate[$anchorDate] as $session): ?>
                        <?php
                        $timeLabel = format_time_display($session['scheduled_start']) . '–' . format_time_display($session['scheduled_end']);
                        ?>
                        <div class="col-md-6 col-lg-4">
                            <button type="button"
                                    class="lecturer-cal-event lecturer-cal-event-card w-100 text-start border rounded p-3 bg-white <?= e(calendar_session_status_class((string) $session['status'])) ?>"
                                    data-bs-toggle="modal"
                                    data-bs-target="#sessionDetailModal"
                                    data-session-id="<?= e((string) $session['session_id']) ?>"
                                    data-module-code="<?= e((string) $session['module_code']) ?>"
                                    data-module-name="<?= e((string) $session['module_name']) ?>"
                                    data-batch-name="<?= e((string) $session['batch_name']) ?>"
                                    data-session-date="<?= e((string) $session['session_date']) ?>"
                                    data-scheduled-start="<?= e(format_time_display($session['scheduled_start'])) ?>"
                                    data-scheduled-end="<?= e(format_time_display($session['scheduled_end'])) ?>"
                                    data-room="<?= e((string) ($session['room'] ?? '')) ?>"
                                    data-status="<?= e((string) $session['status']) ?>">
                                <div class="fw-semibold"><?= e((string) $session['module_code']) ?></div>
                                <div class="small"><?= e((string) $session['batch_name']) ?></div>
                                <div class="small text-muted"><?= e($timeLabel) ?></div>
                                <span class="badge mt-2 <?= e(status_badge_class((string) $session['status'])) ?>"><?= e((string) $session['status']) ?></span>
                            </button>
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
                                $daySessions = $sessionsByDate[$dayKey] ?? [];
                                ?>
                                <td class="lecturer-cal-cell align-top <?= $isCurrentMonth ? '' : 'lecturer-cal-cell--muted' ?><?= $isToday ? ' lecturer-cal-cell--today' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <a href="<?= e(calendar_url($calendarPath, 'today', $dayKey)) ?>" class="lecturer-cal-day-number small fw-semibold text-decoration-none"><?= e($dayDate instanceof DateTimeImmutable ? $dayDate->format('j') : '') ?></a>
                                        <?php if ($isToday): ?><span class="badge text-bg-primary">Today</span><?php endif; ?>
                                    </div>
                                    <div class="lecturer-cal-events">
                                        <?php foreach ($daySessions as $session): ?>
                                            <?php
                                            $timeLabel = format_time_display($session['scheduled_start']) . '–' . format_time_display($session['scheduled_end']);
                                            ?>
                                            <button type="button"
                                                    class="lecturer-cal-event w-100 text-start border-0 rounded px-2 py-1 mb-1 small <?= e(calendar_session_status_class((string) $session['status'])) ?>"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#sessionDetailModal"
                                                    data-session-id="<?= e((string) $session['session_id']) ?>"
                                                    data-module-code="<?= e((string) $session['module_code']) ?>"
                                                    data-module-name="<?= e((string) $session['module_name']) ?>"
                                                    data-batch-name="<?= e((string) $session['batch_name']) ?>"
                                                    data-session-date="<?= e((string) $session['session_date']) ?>"
                                                    data-scheduled-start="<?= e(format_time_display($session['scheduled_start'])) ?>"
                                                    data-scheduled-end="<?= e(format_time_display($session['scheduled_end'])) ?>"
                                                    data-room="<?= e((string) ($session['room'] ?? '')) ?>"
                                                    data-status="<?= e((string) $session['status']) ?>">
                                                <div class="fw-semibold"><?= e((string) $session['module_code']) ?></div>
                                                <div><?= e((string) $session['batch_name']) ?></div>
                                                <div class="text-muted"><?= e($timeLabel) ?></div>
                                            </button>
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

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
