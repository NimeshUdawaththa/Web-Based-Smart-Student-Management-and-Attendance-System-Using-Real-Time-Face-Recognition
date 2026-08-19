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

$view = strtolower(trim((string) ($_GET['view'] ?? 'upcoming')));
if ($view !== 'past') {
    $view = 'upcoming';
}

$pageTitle = 'Events';
$rows = list_campus_events_for_role($role, $view);
$serverNowTs = app_now()->getTimestamp();
$refreshSeconds = 45;

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <p class="text-muted mb-0">Campus events for you. These are not lecture timetable sessions. This list refreshes automatically.</p>
    <div class="btn-group btn-group-sm">
        <a href="<?= e(app_url($academicRoutePrefix . '/events/index.php')) ?>" class="btn <?= $view === 'upcoming' ? 'btn-primary' : 'btn-outline-primary' ?>">Upcoming</a>
        <a href="<?= e(app_url($academicRoutePrefix . '/events/index.php?view=past')) ?>" class="btn <?= $view === 'past' ? 'btn-primary' : 'btn-outline-primary' ?>">Past Events</a>
    </div>
</div>

<?php if ($rows === []): ?>
    <div class="alert alert-light border">
        <?= $view === 'past' ? 'There are no past events for you.' : 'There are no upcoming events for you right now.' ?>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($rows as $row): ?>
            <?php
            $lifecycle = (string) ($row['lifecycle_label'] ?? campus_event_lifecycle_label($row));
            $hasPoster = !empty($row['poster_path']);
            $preview = campus_event_description_preview(isset($row['description']) ? (string) $row['description'] : null);
            $startTs = parse_app_datetime(isset($row['start_datetime']) ? (string) $row['start_datetime'] : null);
            $endTs = parse_app_datetime(isset($row['end_datetime']) ? (string) $row['end_datetime'] : null);
            ?>
            <div class="col-12">
                <div class="card shadow-sm overflow-hidden campus-event-card"
                     data-start-ts="<?= $startTs instanceof DateTimeImmutable ? (int) $startTs->getTimestamp() : '' ?>"
                     data-end-ts="<?= $endTs instanceof DateTimeImmutable ? (int) $endTs->getTimestamp() : '' ?>">
                    <?php if ($hasPoster): ?>
                        <img src="<?= e(campus_event_poster_url($academicRoutePrefix, (int) $row['campus_event_id'])) ?>" alt="" class="w-100" style="max-height: 240px; object-fit: cover;">
                    <?php endif; ?>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <span class="badge <?= e(status_badge_class($lifecycle)) ?>"><?= e($lifecycle) ?></span>
                            <span class="small text-muted campus-event-countdown"></span>
                        </div>
                        <h2 class="h5 mb-2"><?= e((string) $row['title']) ?></h2>
                        <p class="text-muted small mb-2">
                            <?= e(format_campus_event_datetime(isset($row['start_datetime']) ? (string) $row['start_datetime'] : null)) ?>
                            –
                            <?= e(format_campus_event_datetime(isset($row['end_datetime']) ? (string) $row['end_datetime'] : null)) ?>
                            <?php if (!empty($row['location'])): ?>
                                · <?= e((string) $row['location']) ?>
                            <?php endif; ?>
                        </p>
                        <?php if ($preview !== ''): ?>
                            <div class="mb-3"><?= nl2br(e($preview)) ?></div>
                        <?php endif; ?>
                        <a href="<?= e(app_url($academicRoutePrefix . '/events/view.php?id=' . $row['campus_event_id'])) ?>" class="btn btn-sm btn-outline-primary">View</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
(function () {
    const REFRESH_MS = <?= (int) $refreshSeconds ?> * 1000;
    const serverNow = <?= (int) $serverNowTs ?>;
    const browserNow = Date.now() / 1000;

    function estimatedAppNow() {
        return serverNow + ((Date.now() / 1000) - browserNow);
    }

    function formatRemaining(seconds) {
        const total = Math.max(0, Math.floor(seconds));
        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const secs = total % 60;
        if (hours > 0) {
            return hours + 'h ' + minutes + 'm';
        }
        if (minutes > 0) {
            return minutes + 'm ' + secs + 's';
        }
        return secs + 's';
    }

    function updateCountdowns() {
        const now = estimatedAppNow();
        document.querySelectorAll('.campus-event-card').forEach(function (card) {
            const label = card.querySelector('.campus-event-countdown');
            const startTs = Number(card.getAttribute('data-start-ts'));
            const endTs = Number(card.getAttribute('data-end-ts'));
            if (!label || !startTs || !endTs) {
                return;
            }
            if (now < startTs) {
                label.textContent = 'Starts in ' + formatRemaining(startTs - now);
            } else if (now < endTs) {
                label.textContent = 'Ends in ' + formatRemaining(endTs - now);
            } else {
                label.textContent = 'Ended';
            }
        });
    }

    function nextReloadDelayMs() {
        const now = estimatedAppNow();
        let soonest = REFRESH_MS / 1000;
        document.querySelectorAll('.campus-event-card').forEach(function (card) {
            const startTs = Number(card.getAttribute('data-start-ts'));
            const endTs = Number(card.getAttribute('data-end-ts'));
            [startTs, endTs].forEach(function (ts) {
                if (!ts) {
                    return;
                }
                const remaining = ts - now + 1;
                if (remaining > 0 && remaining < soonest) {
                    soonest = remaining;
                }
            });
        });
        return Math.max(1000, Math.round(soonest * 1000));
    }

    updateCountdowns();
    setInterval(updateCountdowns, 1000);
    setTimeout(function () {
        window.location.reload();
    }, nextReloadDelayMs());
})();
</script>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
