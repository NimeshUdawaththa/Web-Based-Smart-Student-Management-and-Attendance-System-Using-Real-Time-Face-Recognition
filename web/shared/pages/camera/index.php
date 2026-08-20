<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

$pageTitle = 'Camera Management';
$routePrefix = $cameraRoutePrefix ?? 'academic-staff';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Your session expired. Please try again.');
        redirect($routePrefix . '/camera.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        $result = match ($action) {
            'start' => camera_start(),
            'stop' => camera_stop(),
            default => ['ok' => false, 'message' => 'Unknown camera action.'],
        };
    } catch (Throwable $exception) {
        $result = ['ok' => false, 'message' => $exception->getMessage()];
    }

    if (!empty($result['ok'])) {
        set_flash('success', (string) $result['message']);
    } else {
        set_flash('error', (string) ($result['message'] ?? 'Camera action failed.'));
    }
    redirect($routePrefix . '/camera.php');
}

$status = camera_public_status();
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($status);
    exit;
}

$dash = static function (?string $value): string {
    $text = trim((string) $value);
    return $text === '' ? '—' : $text;
};

$modeRaw = strtoupper(trim((string) ($status['mode'] ?? '')));
$modeClass = match ($modeRaw) {
    'ENTRY' => 'app-camera-mode-entry',
    'EXIT' => 'app-camera-mode-exit',
    default => '',
};

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<p class="text-muted mb-3">
    Start the attendance camera when lectures need face recognition.
    Students are only marked when an eligible lecture session is active.
</p>

<?php if (empty($status['can_start'])): ?>
    <div class="alert alert-warning">
        Camera process control is not available on this machine. Check that PHP <code>proc_open</code> is enabled and
        <code>face-recognition\venv\Scripts\python.exe</code> exists.
    </div>
<?php endif; ?>

<div class="app-camera-hero">
    <div class="app-camera-stat">
        <span class="app-camera-stat__label">Status</span>
        <span id="camera-status-badge" class="badge <?= e(camera_status_badge_class((string) $status['status'])) ?>"><?= e((string) $status['status']) ?></span>
    </div>
    <div class="app-camera-stat">
        <span class="app-camera-stat__label">Source</span>
        <span class="app-camera-stat__value" id="camera-label"><?= e((string) $status['camera']) ?></span>
    </div>
    <div class="app-camera-stat">
        <span class="app-camera-stat__label">Mode</span>
        <span class="app-camera-stat__value <?= e($modeClass) ?>" id="camera-mode"><?= e((string) $status['mode']) ?></span>
        <p class="small text-muted mb-0 mt-2">ENTRY = student enters session · EXIT = student leaves session</p>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h6">Camera controls</h2>
        <div class="d-flex flex-wrap gap-2">
            <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="start">
                <button type="submit" class="btn btn-primary" <?= empty($status['can_start']) ? 'disabled' : '' ?>>Start Camera</button>
            </form>
            <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="stop">
                <button type="submit" class="btn btn-outline-danger">Stop Camera</button>
            </form>
        </div>
    </div>
</div>

<div class="alert alert-info">
    Press <strong>E</strong> in the recognition window to switch between ENTRY and EXIT.
    The camera does not switch modes automatically.
</div>

<details class="app-diag-details">
    <summary>Technical details</summary>
    <dl class="row mb-0 mt-3" id="camera-status-fields">
        <dt class="col-sm-4">Configured Index</dt>
        <dd class="col-sm-8" id="camera-index"><?= e((string) $status['camera_index']) ?></dd>
        <dt class="col-sm-4">Process PID</dt>
        <dd class="col-sm-8" id="camera-pid"><?= e($dash(isset($status['pid']) ? (string) $status['pid'] : null)) ?></dd>
        <dt class="col-sm-4">Last Started</dt>
        <dd class="col-sm-8" id="camera-started"><?= e($dash(isset($status['last_started']) ? (string) $status['last_started'] : null)) ?></dd>
        <dt class="col-sm-4">Last Stopped</dt>
        <dd class="col-sm-8" id="camera-stopped"><?= e($dash(isset($status['last_stopped']) ? (string) $status['last_stopped'] : null)) ?></dd>
        <dt class="col-sm-4">Last Error</dt>
        <dd class="col-sm-8" id="camera-error"><?= e($dash(isset($status['last_error']) ? (string) $status['last_error'] : null)) ?></dd>
    </dl>
</details>

<script>
(function () {
    const statusUrl = <?= json_encode(app_url($routePrefix . '/camera.php?format=json'), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const badge = document.getElementById('camera-status-badge');
    const fields = {
        camera: document.getElementById('camera-label'),
        index: document.getElementById('camera-index'),
        mode: document.getElementById('camera-mode'),
        pid: document.getElementById('camera-pid'),
        started: document.getElementById('camera-started'),
        stopped: document.getElementById('camera-stopped'),
        error: document.getElementById('camera-error'),
    };
    const badgeClass = {
        ONLINE: 'badge text-bg-success',
        STARTING: 'badge text-bg-warning',
        STOPPING: 'badge text-bg-warning',
        ERROR: 'badge text-bg-danger',
        OFFLINE: 'badge text-bg-secondary',
    };
    const dash = (value) => {
        if (value === null || value === undefined || String(value).trim() === '') {
            return '—';
        }
        return String(value);
    };
    const applyModeClass = (el, mode) => {
        el.classList.remove('app-camera-mode-entry', 'app-camera-mode-exit');
        const normalized = String(mode || '').trim().toUpperCase();
        if (normalized === 'ENTRY') {
            el.classList.add('app-camera-mode-entry');
        } else if (normalized === 'EXIT') {
            el.classList.add('app-camera-mode-exit');
        }
    };

    async function refresh() {
        try {
            const response = await fetch(statusUrl, { credentials: 'same-origin' });
            if (!response.ok) {
                return;
            }
            const data = await response.json();
            const status = String(data.status || 'OFFLINE');
            badge.className = badgeClass[status] || badgeClass.OFFLINE;
            badge.textContent = status;
            fields.camera.textContent = dash(data.camera);
            fields.index.textContent = dash(data.camera_index);
            fields.mode.textContent = dash(data.mode);
            applyModeClass(fields.mode, data.mode);
            fields.pid.textContent = dash(data.pid);
            fields.started.textContent = dash(data.last_started);
            fields.stopped.textContent = dash(data.last_stopped);
            fields.error.textContent = dash(data.last_error);
        } catch (e) {
            // Keep the last server-rendered values if polling fails.
        }
    }

    setInterval(refresh, 3000);
})();
</script>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
