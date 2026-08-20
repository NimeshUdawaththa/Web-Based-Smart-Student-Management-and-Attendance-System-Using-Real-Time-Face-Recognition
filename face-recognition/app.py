"""
Flask REST service – bridges the PHP web application and the Python face pipeline.
Runs on localhost only. Does not expose embeddings or dataset images.
"""

import logging
import threading

from flask import Flask, jsonify, request
from flask_cors import CORS

import config
import dataset_cleanup
import db
import face_capture
import face_encoder
from recognition.cooldown import RecognitionCooldown
from recognition.live_recognition import run_live_recognition
from recognition.metrics import RecognitionMetrics
from recognition.profile_loader import get_gallery, reload_gallery

logger = logging.getLogger(__name__)

app = Flask(__name__)
CORS(app, origins=['http://localhost', 'http://localhost:8080', 'http://127.0.0.1:8080'])

# In-memory enrollment session tracker (single-server dev usage)
_sessions: dict[int, dict] = {}
_session_lock = threading.Lock()

# Local recognition test loop (camera window on the server machine)
_recognition_lock = threading.Lock()
_recognition_stop = threading.Event()
_recognition_thread: threading.Thread | None = None
_recognition_metrics = RecognitionMetrics()
_recognition_cooldown = RecognitionCooldown(config.RECOGNITION_COOLDOWN_SECONDS)
_recognition_state: dict = {
    'status': 'idle',
    'error': None,
}


def _enrollment_in_progress() -> bool:
    with _session_lock:
        return any(s.get('status') in ('capturing', 'encoding') for s in _sessions.values())


def _recognition_running() -> bool:
    with _recognition_lock:
        return _recognition_state['status'] == 'running'


# ---------- Health ----------

@app.route('/health', methods=['GET'])
def health():
    db_ok = False
    try:
        conn = db.get_connection()
        cursor = conn.cursor()
        cursor.execute('SELECT 1')
        cursor.fetchone()
        conn.close()
        db_ok = True
    except Exception:
        pass

    return jsonify({
        'status': 'running',
        'database': 'connected' if db_ok else 'unavailable',
    })


# ---------- Start Enrollment ----------

@app.route('/api/enrollment/start', methods=['POST'])
def enrollment_start():
    data = request.get_json(silent=True) or {}
    student_id = data.get('student_id')

    if student_id is None:
        return jsonify({'error': 'student_id is required'}), 400

    try:
        student_id = int(student_id)
        if student_id <= 0:
            raise ValueError
    except (ValueError, TypeError):
        return jsonify({'error': 'student_id must be a positive integer'}), 400

    student = db.get_student_by_id(student_id)
    if student is None:
        return jsonify({'error': 'Student not found in database'}), 404

    profile = db.get_face_profile(student_id)
    if profile and profile['status'] == 'ACTIVE':
        return jsonify({
            'error': 'Student already has an active face profile. Re-enrollment is not yet supported.',
            'face_status': 'ENROLLED',
        }), 409

    if _recognition_running():
        return jsonify({'error': 'Live recognition is using the camera. Stop it before enrollment.'}), 409

    with _session_lock:
        if student_id in _sessions and _sessions[student_id].get('status') == 'capturing':
            return jsonify({'error': 'Enrollment already in progress for this student'}), 409
        _sessions[student_id] = {
            'status': 'capturing',
            'captured': 0,
            'target': config.TARGET_SAMPLES,
            'error': None,
        }

    def _run_enrollment():
        try:
            def on_progress(captured, target):
                with _session_lock:
                    _sessions[student_id]['captured'] = captured

            capture_result = face_capture.capture_samples(student_id, progress_callback=on_progress)

            if not capture_result['success']:
                with _session_lock:
                    _sessions[student_id].update({
                        'status': 'failed',
                        'captured': capture_result['sample_count'],
                        'error': capture_result.get('error', 'Capture did not complete'),
                    })
                return

            with _session_lock:
                _sessions[student_id]['status'] = 'encoding'

            enc_result = face_encoder.generate_embeddings(student_id)

            if not enc_result['success']:
                with _session_lock:
                    _sessions[student_id].update({
                        'status': 'failed',
                        'error': enc_result.get('error', 'Embedding generation failed'),
                    })
                return

            db.create_or_update_face_profile(
                student_id=student_id,
                encoding_path=enc_result['encoding_path'],
                sample_count=capture_result['sample_count'],
            )

            cleanup_warning = None
            ready, ready_reason = dataset_cleanup.verify_enrollment_ready_for_cleanup(student_id)
            if not ready:
                logger.warning(
                    'Enrollment succeeded but dataset cleanup skipped for student_id=%s: %s',
                    student_id,
                    ready_reason,
                )
                cleanup_warning = (
                    'Enrollment succeeded but temporary face samples were kept '
                    '(encoding verification incomplete).'
                )
            else:
                try:
                    reload_gallery()
                except Exception:
                    logger.exception('Enrollment completed but face gallery reload failed')

                cleanup_result = dataset_cleanup.cleanup_student_dataset(student_id)
                if not cleanup_result.get('success'):
                    logger.warning(
                        'Enrollment succeeded but temporary face samples could not be removed '
                        'for student_id=%s: %s',
                        student_id,
                        cleanup_result.get('error'),
                    )
                    cleanup_warning = (
                        'Enrollment succeeded but temporary face samples could not be removed.'
                    )

            with _session_lock:
                session_payload = {
                    'status': 'completed',
                    'captured': capture_result['sample_count'],
                    'encodings': enc_result['encoding_count'],
                    'error': None,
                }
                if cleanup_warning:
                    session_payload['cleanup_warning'] = cleanup_warning
                _sessions[student_id].update(session_payload)

        except Exception as exc:
            with _session_lock:
                _sessions[student_id].update({
                    'status': 'failed',
                    'error': str(exc),
                })

    thread = threading.Thread(target=_run_enrollment, daemon=True)
    thread.start()

    return jsonify({
        'message': 'Enrollment started – camera window will open on the server machine',
        'student_id': student_id,
        'student_name': f"{student['first_name']} {student['last_name']}",
        'target_samples': config.TARGET_SAMPLES,
    }), 202


# ---------- Enrollment Status ----------

@app.route('/api/enrollment/status/<int:student_id>', methods=['GET'])
def enrollment_status(student_id: int):
    if student_id <= 0:
        return jsonify({'error': 'Invalid student_id'}), 400

    with _session_lock:
        session = _sessions.get(student_id)

    if session is None:
        profile = db.get_face_profile(student_id)
        if profile and profile['status'] == 'ACTIVE':
            return jsonify({
                'status': 'completed',
                'face_status': 'ENROLLED',
                'sample_count': profile['sample_count'],
            })
        return jsonify({'status': 'not_started', 'face_status': 'NOT ENROLLED'})

    return jsonify(session)


# ---------- Recognition (identification only; no attendance writes) ----------

@app.route('/api/recognition/students', methods=['GET'])
def recognition_students():
    gallery = get_gallery()
    students = []
    for profile in gallery.profiles.values():
        students.append({
            'student_id': profile.student_id,
            'registration_no': profile.registration_no,
            'full_name': profile.full_name,
            'sample_count': profile.sample_count,
            'embedding_count': profile.embedding_count,
        })
    students.sort(key=lambda item: item['student_id'])
    return jsonify({
        'count': len(students),
        'students': students,
    })


@app.route('/api/recognition/status', methods=['GET'])
def recognition_status():
    gallery = get_gallery()
    with _recognition_lock:
        status = _recognition_state['status']
        error = _recognition_state['error']

    skipped = [
        {'student_id': item.student_id, 'reason': item.reason}
        for item in gallery.skipped
    ]
    return jsonify({
        'status': status,
        'error': error,
        'profiles_loaded': gallery.student_count,
        'profiles_skipped': skipped,
        'loaded_at': gallery.loaded_at.isoformat() if gallery.loaded_at else None,
        'threshold': config.MATCH_THRESHOLD,
        'match_k': config.MATCH_K,
        'frame_scale': config.RECOGNITION_FRAME_SCALE,
        'process_every_n_frames': config.PROCESS_EVERY_N_FRAMES,
        'cooldown_seconds': config.RECOGNITION_COOLDOWN_SECONDS,
        'camera_index': config.CAMERA_INDEX,
        'metrics': _recognition_metrics.snapshot(),
        'cooldown': _recognition_cooldown.snapshot(),
    })


@app.route('/api/recognition/reload', methods=['POST'])
def recognition_reload():
    try:
        summary = reload_gallery()
    except Exception as exc:
        logger.exception('Face gallery reload failed')
        return jsonify({'error': 'Failed to reload enrolled face profiles', 'detail': str(exc)}), 500
    return jsonify({
        'message': 'Enrolled face profiles reloaded',
        **summary,
    })


@app.route('/api/recognition/start', methods=['POST'])
def recognition_start():
    global _recognition_thread

    if _enrollment_in_progress():
        return jsonify({'error': 'Enrollment is using the camera. Finish or cancel it first.'}), 409

    with _recognition_lock:
        if _recognition_state['status'] == 'running':
            return jsonify({'error': 'Live recognition is already running'}), 409

        try:
            reload_gallery()
        except Exception as exc:
            logger.exception('Cannot start recognition; gallery reload failed')
            return jsonify({'error': 'Failed to load enrolled face profiles', 'detail': str(exc)}), 500

        _recognition_stop.clear()
        _recognition_metrics.reset()
        _recognition_cooldown.clear()
        _recognition_state['status'] = 'running'
        _recognition_state['error'] = None

        def _run_recognition():
            try:
                result = run_live_recognition(
                    stop_event=_recognition_stop,
                    metrics=_recognition_metrics,
                    cooldown=_recognition_cooldown,
                )
                error = None if result.get('success') else result.get('error')
            except Exception as exc:
                logger.exception('Live recognition loop failed')
                error = str(exc)

            with _recognition_lock:
                _recognition_state['status'] = 'idle'
                _recognition_state['error'] = error

        _recognition_thread = threading.Thread(target=_run_recognition, daemon=True)
        _recognition_thread.start()

    return jsonify({
        'message': 'Live recognition started – camera window will open on the server machine',
        'threshold': config.MATCH_THRESHOLD,
        'profiles_loaded': get_gallery().student_count,
    }), 202


@app.route('/api/recognition/stop', methods=['POST'])
def recognition_stop():
    _recognition_stop.set()
    thread = _recognition_thread
    if thread is not None and thread.is_alive():
        thread.join(timeout=3.0)
    with _recognition_lock:
        _recognition_state['status'] = 'idle'
    return jsonify({
        'message': 'Live recognition stop requested',
        'status': _recognition_state['status'],
        'metrics': _recognition_metrics.snapshot(),
    })


# ---------- Entry point ----------

if __name__ == '__main__':
    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s [%(levelname)s] %(name)s: %(message)s',
    )
    try:
        reload_gallery()
    except Exception:
        logger.exception('Initial face gallery load failed (service will still start)')

    print(f'Face Recognition Service starting on http://{config.FLASK_HOST}:{config.FLASK_PORT}')
    app.run(host=config.FLASK_HOST, port=config.FLASK_PORT, debug=config.FLASK_DEBUG)
