<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $studentRoutePrefix */
$studentRoutePrefix = $studentRoutePrefix ?? 'admin/students';

$studentId = positive_int($_GET['id'] ?? null);
if ($studentId === null) {
    set_flash('error', 'Student not found.');
    redirect($studentRoutePrefix . '/index.php');
}

$student = get_student($studentId);
if ($student === null) {
    set_flash('error', 'Student not found.');
    redirect($studentRoutePrefix . '/index.php');
}

$pageTitle = 'Face Enrollment – ' . $student['first_name'] . ' ' . $student['last_name'];

$faceServiceUrl = 'http://127.0.0.1:5000';
$isFaceEnrolled = (($student['face_status'] ?? '') === 'ENROLLED');
$isStudentActive = (($student['status'] ?? '') === 'ACTIVE');

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($studentRoutePrefix . '/index.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to Students</a>
</div>

<header class="app-face-hero">
    <p class="app-face-hero__eyebrow">Face Enrollment</p>
    <h1 class="app-face-hero__title"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></h1>
    <p class="app-face-hero__meta mb-2"><?= e($student['registration_no']) ?></p>
    <div class="app-face-hero__badges">
        <span id="face-status-badge" class="badge <?= e(status_badge_class($student['face_status'])) ?>"><?= e($student['face_status']) ?></span>
        <div id="service-status" class="app-face-service app-face-service--checking" aria-live="polite">
            <span class="app-face-service__label">Face Service</span>
            <span class="badge text-bg-secondary">Checking…</span>
        </div>
    </div>
</header>

<section class="app-face-card" aria-label="Face capture">
    <div class="app-face-metrics" id="face-capture-guide" aria-live="polite">
        <div class="app-face-metric">
            <span class="app-face-metric__label">Current Pose</span>
            <span class="app-face-metric__value" id="pose-current-label">Look Straight</span>
        </div>
        <div class="app-face-metric">
            <span class="app-face-metric__label">Captured</span>
            <span class="app-face-metric__value" id="pose-captured-label">0 / 25</span>
        </div>
    </div>

    <p class="app-face-help text-muted mb-3">
        Keep your face within the guide and turn only slightly.
    </p>

    <div class="app-face-preview" id="face-live-preview" aria-label="Live enrollment camera preview">
        <div class="app-face-preview__frame is-idle" id="preview-frame">
            <img
                id="preview-stream"
                class="app-face-preview__video d-none"
                alt="Live enrollment camera feed"
                decoding="async"
            >
            <div class="app-face-preview__idle" id="preview-idle">
                <p class="mb-0">Camera preview will appear when enrollment starts.</p>
            </div>
            <div class="app-face-preview__completed d-none" id="preview-completed">
                <p class="mb-0 fw-semibold">Capture finished</p>
            </div>
            <div class="app-face-preview__oval" aria-hidden="true"></div>
            <p class="app-face-preview__pose" id="pose-preview-label">Look Straight</p>
        </div>
        <p id="preview-fallback" class="small text-muted mt-2 mb-0 d-none">
            Live preview unavailable. Enrollment service is still running.
        </p>
    </div>

    <div id="enrollment-progress" class="app-face-capture__progress mb-3 d-none">
        <div class="progress" style="height: 22px;">
            <div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0 / 25</div>
        </div>
        <p id="progress-text" class="text-muted small text-center mt-2 mb-0">Waiting for capture to begin...</p>
    </div>

    <div id="enrollment-controls" class="app-face-capture__actions d-flex flex-wrap gap-2 align-items-center justify-content-center">
        <?php if (!$isStudentActive): ?>
            <p class="text-muted small mb-0 text-center w-100">
                Face enrollment is only available for ACTIVE students.
            </p>
        <?php elseif ($isFaceEnrolled): ?>
            <p class="app-face-reenroll-note text-muted small mb-2 text-center w-100">
                Face Enrolled. Re-enrollment replaces the existing biometric encoding only after the new capture is completed successfully.
            </p>
            <button type="button" id="btn-start-enrollment" class="btn btn-primary btn-lg" disabled>
                <span class="spinner-border spinner-border-sm d-none" id="btn-spinner"></span>
                Re-enroll Face
            </button>
        <?php else: ?>
            <button type="button" id="btn-start-enrollment" class="btn btn-primary btn-lg" disabled>
                <span class="spinner-border spinner-border-sm d-none" id="btn-spinner"></span>
                Start Face Enrollment
            </button>
        <?php endif; ?>
        <button type="button" id="btn-cancel-enrollment" class="btn btn-outline-danger btn-lg d-none">
            Cancel Enrollment
        </button>
    </div>

    <ol class="app-face-steps list-unstyled mb-0" id="pose-stage-list" aria-label="Pose stages">
        <li class="app-face-steps__item is-current" data-pose="straight" data-range="0-5">
            <span class="app-face-steps__mark" aria-hidden="true"></span>
            <span class="app-face-steps__name">Straight</span>
        </li>
        <li class="app-face-steps__item" data-pose="left" data-range="6-10">
            <span class="app-face-steps__mark" aria-hidden="true"></span>
            <span class="app-face-steps__name">Left</span>
        </li>
        <li class="app-face-steps__item" data-pose="right" data-range="11-15">
            <span class="app-face-steps__mark" aria-hidden="true"></span>
            <span class="app-face-steps__name">Right</span>
        </li>
        <li class="app-face-steps__item" data-pose="up" data-range="16-20">
            <span class="app-face-steps__mark" aria-hidden="true"></span>
            <span class="app-face-steps__name">Up</span>
        </li>
        <li class="app-face-steps__item" data-pose="down" data-range="21-25">
            <span class="app-face-steps__mark" aria-hidden="true"></span>
            <span class="app-face-steps__name">Down</span>
        </li>
    </ol>
</section>

<p class="app-face-privacy text-muted small">
    Temporary face samples are used only to generate the biometric encoding and are removed after successful enrollment.
</p>

<div id="enrollment-result" class="app-face-result d-none" role="status"></div>
<div id="enrollment-cleanup-warning" class="mt-2 d-none"></div>

<script>
(function() {
    const SERVICE_URL = <?= json_encode($faceServiceUrl, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const STUDENT_ID = <?= (int)$student['student_id'] ?>;
    const IS_REENROLL = <?= $isFaceEnrolled ? 'true' : 'false' ?>;
    const STUDENT_ACTIVE = <?= $isStudentActive ? 'true' : 'false' ?>;

    const serviceStatusEl = document.getElementById('service-status');
    const btnStart = document.getElementById('btn-start-enrollment');
    const btnCancel = document.getElementById('btn-cancel-enrollment');
    const btnSpinner = document.getElementById('btn-spinner');
    const progressSection = document.getElementById('enrollment-progress');
    const progressBar = document.getElementById('progress-bar');
    const progressText = document.getElementById('progress-text');
    const resultEl = document.getElementById('enrollment-result');
    const cleanupWarningEl = document.getElementById('enrollment-cleanup-warning');
    const badgeEl = document.getElementById('face-status-badge');
    const poseCapturedLabel = document.getElementById('pose-captured-label');
    const poseCurrentLabel = document.getElementById('pose-current-label');
    const posePreviewLabel = document.getElementById('pose-preview-label');
    const poseStageList = document.getElementById('pose-stage-list');
    const previewFrame = document.getElementById('preview-frame');
    const previewStream = document.getElementById('preview-stream');
    const previewIdle = document.getElementById('preview-idle');
    const previewCompleted = document.getElementById('preview-completed');
    const previewFallback = document.getElementById('preview-fallback');

    let pollTimer = null;
    let previewWatchTimer = null;
    let previewActive = false;
    let previewLoadFailed = false;

    function poseForCaptured(captured) {
        const n = Math.max(0, Number(captured) || 0);
        if (n <= 5) {
            return { key: 'straight', label: 'Look Straight' };
        }
        if (n <= 10) {
            return { key: 'left', label: 'Turn Slightly Left' };
        }
        if (n <= 15) {
            return { key: 'right', label: 'Turn Slightly Right' };
        }
        if (n <= 20) {
            return { key: 'up', label: 'Look Slightly Up' };
        }
        return { key: 'down', label: 'Look Slightly Down / Return Straight' };
    }

    function updatePoseGuide(captured, target) {
        const total = target || 25;
        const pose = poseForCaptured(captured);
        if (poseCapturedLabel) {
            poseCapturedLabel.textContent = captured + ' / ' + total;
        }
        if (poseCurrentLabel) {
            poseCurrentLabel.textContent = pose.label;
        }
        if (posePreviewLabel) {
            posePreviewLabel.textContent = pose.label;
        }
        if (poseStageList) {
            poseStageList.querySelectorAll('.app-face-steps__item').forEach(function (item) {
                item.classList.toggle('is-current', item.getAttribute('data-pose') === pose.key);
                item.classList.toggle('is-done', false);
            });
            const order = ['straight', 'left', 'right', 'up', 'down'];
            const currentIdx = order.indexOf(pose.key);
            poseStageList.querySelectorAll('.app-face-steps__item').forEach(function (item) {
                const idx = order.indexOf(item.getAttribute('data-pose'));
                if (idx >= 0 && idx < currentIdx) {
                    item.classList.add('is-done');
                    item.classList.remove('is-current');
                }
            });
        }
    }

    function setPreviewIdle() {
        previewActive = false;
        previewLoadFailed = false;
        if (previewWatchTimer) {
            clearTimeout(previewWatchTimer);
            previewWatchTimer = null;
        }
        previewStream.removeAttribute('src');
        previewStream.classList.add('d-none');
        previewIdle.classList.remove('d-none');
        previewCompleted.classList.add('d-none');
        previewFallback.classList.add('d-none');
        previewFrame.classList.add('is-idle');
        previewFrame.classList.remove('is-live', 'is-completed');
    }

    function setPreviewCompleted() {
        previewActive = false;
        if (previewWatchTimer) {
            clearTimeout(previewWatchTimer);
            previewWatchTimer = null;
        }
        previewStream.removeAttribute('src');
        previewStream.classList.add('d-none');
        previewIdle.classList.add('d-none');
        previewCompleted.classList.remove('d-none');
        previewFallback.classList.add('d-none');
        previewFrame.classList.remove('is-idle', 'is-live');
        previewFrame.classList.add('is-completed');
    }

    function showPreviewFallback() {
        previewLoadFailed = true;
        previewFallback.classList.remove('d-none');
        previewIdle.classList.add('d-none');
        previewStream.classList.add('d-none');
        previewFrame.classList.add('is-idle');
        previewFrame.classList.remove('is-live');
    }

    function startLivePreview() {
        previewLoadFailed = false;
        previewActive = true;
        previewIdle.classList.add('d-none');
        previewCompleted.classList.add('d-none');
        previewFallback.classList.add('d-none');
        previewFrame.classList.remove('is-idle', 'is-completed');
        previewFrame.classList.add('is-live');
        previewStream.classList.remove('d-none');
        previewStream.src = SERVICE_URL + '/api/enrollment/preview?t=' + Date.now();

        if (previewWatchTimer) {
            clearTimeout(previewWatchTimer);
        }
        previewWatchTimer = setTimeout(function () {
            if (!previewActive || previewLoadFailed) {
                return;
            }
            if (!previewStream.naturalWidth) {
                showPreviewFallback();
            }
        }, 8000);
    }

    function stopLivePreview(completed) {
        if (completed) {
            setPreviewCompleted();
        } else {
            setPreviewIdle();
        }
    }

    async function checkService() {
        try {
            const resp = await fetch(SERVICE_URL + '/health');
            const data = await resp.json();
            if (data.status === 'running' && data.database === 'connected') {
                serviceStatusEl.className = 'app-face-service app-face-service--online';
                serviceStatusEl.innerHTML =
                    '<span class="app-face-service__label">Face Service</span>' +
                    '<span class="badge bg-success">ONLINE</span>';
                if (btnStart && STUDENT_ACTIVE) {
                    btnStart.disabled = false;
                }
            } else {
                serviceStatusEl.className = 'app-face-service app-face-service--warn alert alert-warning mb-0';
                serviceStatusEl.innerHTML =
                    '<span class="app-face-service__label">Face Service</span>' +
                    '<span class="badge text-bg-warning">DEGRADED</span>' +
                    '<p class="small mb-0 mt-2">Service is reachable but the database is ' + (data.database || 'unknown') + '.</p>';
            }
        } catch (e) {
            serviceStatusEl.className = 'app-face-service app-face-service--offline alert alert-danger mb-0';
            serviceStatusEl.innerHTML =
                '<div class="fw-semibold mb-1">Face Service OFFLINE</div>' +
                '<p class="mb-2 small">Activate the <code>face-recognition</code> virtual environment, then run <code>python app.py</code>.</p>' +
                '<button type="button" id="btn-retry-service" class="btn btn-sm btn-light">Retry connection</button>';
            const retryBtn = document.getElementById('btn-retry-service');
            if (retryBtn) {
                retryBtn.addEventListener('click', checkService);
            }
            if (btnStart) {
                btnStart.disabled = true;
            }
            stopLivePreview(false);
        }
    }

    async function startEnrollment() {
        if (!btnStart || !STUDENT_ACTIVE) {
            return;
        }
        if (IS_REENROLL) {
            const ok = window.confirm(
                "Re-enroll this student's face? The current face profile will remain active until the new enrollment completes successfully."
            );
            if (!ok) {
                return;
            }
        }

        btnStart.disabled = true;
        if (btnSpinner) {
            btnSpinner.classList.remove('d-none');
        }
        btnCancel.classList.remove('d-none');
        resultEl.classList.add('d-none');
        resultEl.innerHTML = '';
        cleanupWarningEl.classList.add('d-none');
        cleanupWarningEl.textContent = '';
        progressBar.classList.add('progress-bar-animated');
        progressBar.classList.remove('bg-success', 'bg-danger');
        progressBar.style.width = '0%';
        progressBar.textContent = '0 / 25';
        updatePoseGuide(0, 25);
        setPreviewIdle();

        const payload = { student_id: STUDENT_ID };
        if (IS_REENROLL) {
            payload.confirm_reenroll = true;
        }

        try {
            const resp = await fetch(SERVICE_URL + '/api/enrollment/start', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await resp.json();

            if (!resp.ok) {
                showResult('danger', data.error || 'Failed to start enrollment.');
                btnStart.disabled = false;
                btnSpinner.classList.add('d-none');
                btnCancel.classList.add('d-none');
                return;
            }

            progressSection.classList.remove('d-none');
            progressText.textContent = IS_REENROLL
                ? 'Re-enrollment capture starting… existing encoding stays active until success.'
                : 'Camera opening… follow the pose stages below.';
            startLivePreview();
            pollTimer = setInterval(pollStatus, 1500);

        } catch (e) {
            showResult('danger', 'Cannot reach face recognition service.');
            btnStart.disabled = false;
            btnSpinner.classList.add('d-none');
            btnCancel.classList.add('d-none');
            stopLivePreview(false);
        }
    }

    async function cancelEnrollment() {
        btnCancel.disabled = true;
        try {
            await fetch(SERVICE_URL + '/api/enrollment/cancel', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ student_id: STUDENT_ID }),
            });
            progressText.textContent = 'Cancel requested…';
        } catch (e) {
            progressText.textContent = 'Could not reach cancel endpoint; try Q on the server window.';
        } finally {
            btnCancel.disabled = false;
        }
    }

    async function pollStatus() {
        try {
            const resp = await fetch(SERVICE_URL + '/api/enrollment/status/' + STUDENT_ID);
            const data = await resp.json();

            if (data.status === 'capturing') {
                const captured = data.captured || 0;
                const target = data.target || 25;
                const pct = Math.round((captured / target) * 100);
                progressBar.style.width = pct + '%';
                progressBar.textContent = captured + ' / ' + target;
                const pose = poseForCaptured(captured);
                progressText.textContent = 'Capturing… Current pose: ' + pose.label;
                updatePoseGuide(captured, target);
                if (!previewActive && !previewLoadFailed) {
                    startLivePreview();
                }

            } else if (data.status === 'encoding') {
                progressBar.style.width = '100%';
                progressBar.textContent = 'Processing...';
                progressText.textContent = 'Generating face embeddings...';
                stopLivePreview(false);
                previewIdle.classList.add('d-none');
                previewCompleted.classList.remove('d-none');
                previewCompleted.querySelector('p').textContent = 'Capture finished – generating encoding…';
                previewFrame.classList.add('is-completed');
                btnCancel.classList.add('d-none');

            } else if (data.status === 'completed') {
                clearInterval(pollTimer);
                progressBar.style.width = '100%';
                progressBar.classList.remove('progress-bar-animated');
                progressBar.classList.add('bg-success');
                progressBar.textContent = 'Complete';
                progressText.textContent = '';
                updatePoseGuide(data.captured || 25, data.target || 25);
                stopLivePreview(true);
                previewCompleted.querySelector('p').textContent = 'Enrollment capture completed';
                showCompletedResult(data);
                if (data.cleanup_warning) {
                    showSecondaryWarning(data.cleanup_warning);
                }
                badgeEl.textContent = 'ENROLLED';
                badgeEl.className = 'badge bg-success';
                btnSpinner.classList.add('d-none');
                btnCancel.classList.add('d-none');

            } else if (data.status === 'failed') {
                clearInterval(pollTimer);
                progressBar.classList.remove('progress-bar-animated');
                progressBar.classList.add('bg-danger');
                stopLivePreview(false);
                showResult('danger', 'Enrollment failed: ' + (data.error || 'Unknown error'));
                btnStart.disabled = false;
                btnSpinner.classList.add('d-none');
                btnCancel.classList.add('d-none');

            } else if (data.status === 'not_started') {
                if (data.face_status === 'ENROLLED') {
                    clearInterval(pollTimer);
                    stopLivePreview(false);
                    showResult('info', 'This student is already enrolled.');
                    badgeEl.textContent = 'ENROLLED';
                    badgeEl.className = 'badge bg-success';
                    btnStart.disabled = false;
                    btnSpinner.classList.add('d-none');
                    btnCancel.classList.add('d-none');
                }
            }
        } catch (e) {
            // service may be busy; keep polling
        }
    }

    function showCompletedResult(data) {
        const samples = data.captured || data.sample_count || 25;
        const encodings = data.encodings || samples;
        const cleanupOk = !data.cleanup_warning;
        const title = (data.reenroll || IS_REENROLL)
            ? 'Face Re-enrollment Complete'
            : 'Face Enrollment Complete';
        resultEl.className = 'app-face-result app-face-result--success';
        resultEl.innerHTML =
            '<h2 class="app-face-result__title">' + title + '</h2>' +
            '<ul class="app-face-result__list">' +
            '<li>' + samples + ' samples captured</li>' +
            '<li>' + encodings + ' embeddings generated</li>' +
            '<li>Encoding saved successfully</li>' +
            '<li>' + (cleanupOk ? 'Temporary samples removed' : 'Temporary samples could not be fully removed') + '</li>' +
            '</ul>';
        resultEl.classList.remove('d-none');
    }

    function showResult(type, message) {
        resultEl.className = 'app-face-result alert alert-' + type;
        resultEl.textContent = message;
        resultEl.classList.remove('d-none');
    }

    function showSecondaryWarning(message) {
        cleanupWarningEl.className = 'mt-2 alert alert-warning';
        cleanupWarningEl.textContent = message;
        cleanupWarningEl.classList.remove('d-none');
    }

    previewStream.addEventListener('error', function () {
        if (previewActive) {
            showPreviewFallback();
        }
    });
    previewStream.addEventListener('load', function () {
        if (previewActive && previewStream.naturalWidth) {
            previewLoadFailed = false;
            previewFallback.classList.add('d-none');
            previewFrame.classList.add('is-live');
            previewFrame.classList.remove('is-idle');
        }
    });

    updatePoseGuide(0, 25);
    setPreviewIdle();
    if (btnStart) {
        btnStart.addEventListener('click', startEnrollment);
    }
    btnCancel.addEventListener('click', cancelEnrollment);
    checkService();
})();
</script>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
