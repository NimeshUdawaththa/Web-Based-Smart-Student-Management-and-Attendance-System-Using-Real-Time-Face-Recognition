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

$pageTitle = 'Face Enrollment – ' . e($student['first_name'] . ' ' . $student['last_name']);

$faceServiceUrl = 'http://127.0.0.1:5000';

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($studentRoutePrefix . '/index.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to Students</a>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <h2 class="h5 mb-0">Face Enrollment</h2>
    </div>
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th class="text-muted" style="width:140px">Student Name</th><td><strong><?= e($student['first_name'] . ' ' . $student['last_name']) ?></strong></td></tr>
                    <tr><th class="text-muted">Registration No</th><td><?= e($student['registration_no']) ?></td></tr>
                    <tr><th class="text-muted">Course / Batch</th><td><?= e($student['course_name'] . ' – ' . $student['batch_name']) ?></td></tr>
                    <tr>
                        <th class="text-muted">Face Status</th>
                        <td><span id="face-status-badge" class="badge <?= e(status_badge_class($student['face_status'])) ?>"><?= e($student['face_status']) ?></span></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Service status -->
        <div id="service-status" class="alert alert-secondary mb-3">
            Checking face recognition service...
        </div>

        <!-- Instructions -->
        <div class="alert alert-info mb-3">
            <h6 class="alert-heading mb-1">Enrollment Instructions</h6>
            <ul class="mb-0 small">
                <li>Click <strong>Start Face Enrollment</strong> to open the camera on the server machine.</li>
                <li>Look directly at the camera. The system will capture ~25 face samples automatically.</li>
                <li>Move your head slightly (left, right, small angle changes) for natural variation.</li>
                <li>Ensure good lighting and only one face is visible.</li>
                <li>Press <kbd>Q</kbd> on the camera window to cancel at any time.</li>
            </ul>
        </div>

        <!-- Action + Progress -->
        <div id="enrollment-controls">
            <button id="btn-start-enrollment" class="btn btn-primary btn-lg" disabled>
                <span class="spinner-border spinner-border-sm d-none" id="btn-spinner"></span>
                Start Face Enrollment
            </button>
        </div>

        <div id="enrollment-progress" class="mt-3 d-none">
            <div class="progress mb-2" style="height: 25px;">
                <div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0 / 25</div>
            </div>
            <p id="progress-text" class="text-muted small">Waiting for capture to begin...</p>
        </div>

        <div id="enrollment-result" class="mt-3 d-none"></div>
    </div>
</div>

<script>
(function() {
    const SERVICE_URL = <?= json_encode($faceServiceUrl, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const STUDENT_ID = <?= (int)$student['student_id'] ?>;

    const serviceStatusEl = document.getElementById('service-status');
    const btnStart = document.getElementById('btn-start-enrollment');
    const btnSpinner = document.getElementById('btn-spinner');
    const progressSection = document.getElementById('enrollment-progress');
    const progressBar = document.getElementById('progress-bar');
    const progressText = document.getElementById('progress-text');
    const resultEl = document.getElementById('enrollment-result');
    const badgeEl = document.getElementById('face-status-badge');

    let pollTimer = null;

    async function checkService() {
        try {
            const resp = await fetch(SERVICE_URL + '/health');
            const data = await resp.json();
            if (data.status === 'running' && data.database === 'connected') {
                serviceStatusEl.className = 'alert alert-success mb-3';
                serviceStatusEl.textContent = 'Face recognition service is running and database is connected.';
                btnStart.disabled = false;
            } else {
                serviceStatusEl.className = 'alert alert-warning mb-3';
                serviceStatusEl.textContent = 'Face service is running but database is ' + (data.database || 'unknown') + '.';
            }
        } catch (e) {
            serviceStatusEl.className = 'alert alert-danger mb-3';
            serviceStatusEl.innerHTML = 'Face recognition service is <strong>not running</strong>. Start it with: <code>python app.py</code> in the <code>face-recognition/</code> folder.';
        }
    }

    async function startEnrollment() {
        btnStart.disabled = true;
        btnSpinner.classList.remove('d-none');
        resultEl.classList.add('d-none');

        try {
            const resp = await fetch(SERVICE_URL + '/api/enrollment/start', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ student_id: STUDENT_ID }),
            });
            const data = await resp.json();

            if (!resp.ok) {
                showResult('danger', data.error || 'Failed to start enrollment.');
                btnStart.disabled = false;
                btnSpinner.classList.add('d-none');
                return;
            }

            progressSection.classList.remove('d-none');
            progressText.textContent = 'Camera opened – look at the camera...';
            pollTimer = setInterval(pollStatus, 1500);

        } catch (e) {
            showResult('danger', 'Cannot reach face recognition service.');
            btnStart.disabled = false;
            btnSpinner.classList.add('d-none');
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
                progressText.textContent = 'Capturing face samples...';

            } else if (data.status === 'encoding') {
                progressBar.style.width = '100%';
                progressBar.textContent = 'Processing...';
                progressText.textContent = 'Generating face embeddings...';

            } else if (data.status === 'completed') {
                clearInterval(pollTimer);
                progressBar.style.width = '100%';
                progressBar.classList.remove('progress-bar-animated');
                progressBar.classList.add('bg-success');
                progressBar.textContent = 'Complete';
                progressText.textContent = '';
                showResult('success', 'Face enrollment completed successfully! ' + (data.encodings || '') + ' embeddings generated from ' + (data.captured || '') + ' samples.');
                badgeEl.textContent = 'ENROLLED';
                badgeEl.className = 'badge bg-success';
                btnSpinner.classList.add('d-none');

            } else if (data.status === 'failed') {
                clearInterval(pollTimer);
                progressBar.classList.remove('progress-bar-animated');
                progressBar.classList.add('bg-danger');
                showResult('danger', 'Enrollment failed: ' + (data.error || 'Unknown error'));
                btnStart.disabled = false;
                btnSpinner.classList.add('d-none');

            } else if (data.status === 'not_started') {
                // might have completed previously
                if (data.face_status === 'ENROLLED') {
                    clearInterval(pollTimer);
                    showResult('info', 'This student is already enrolled.');
                    badgeEl.textContent = 'ENROLLED';
                    badgeEl.className = 'badge bg-success';
                    btnStart.disabled = false;
                    btnSpinner.classList.add('d-none');
                }
            }
        } catch (e) {
            // service may be busy; keep polling
        }
    }

    function showResult(type, message) {
        resultEl.className = 'mt-3 alert alert-' + type;
        resultEl.textContent = message;
        resultEl.classList.remove('d-none');
    }

    btnStart.addEventListener('click', startEnrollment);
    checkService();
})();
</script>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
