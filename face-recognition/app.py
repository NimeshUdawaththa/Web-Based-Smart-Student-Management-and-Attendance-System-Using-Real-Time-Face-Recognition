"""
Flask REST service – bridges the PHP web application and the Python face pipeline.
Runs on localhost only. Does not expose embeddings or dataset images.
"""

import logging
import threading
import time

from flask import Flask, Response, jsonify, request
from flask_cors import CORS

import config
import dataset_cleanup
import db
import face_capture
import face_encoder
from recognition.cooldown import RecognitionCooldown
from recognition.live_recognition import run_live_recognition
from recognition.metrics import RecognitionMetrics
from recognition.profile_loader import get_gallery, reload_gallery, verify_encoding_for_student

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

    if str(student.get('status') or '').upper() != 'ACTIVE':
        return jsonify({
            'error': 'Only ACTIVE students can enroll or re-enroll a face profile.',
            'student_status': student.get('status'),
        }), 403

    profile = db.get_face_profile(student_id)
    is_reenroll = bool(profile and profile.get('status') == 'ACTIVE')

    if is_reenroll:
        if not data.get('confirm_reenroll'):
            return jsonify({
                'error': 'Re-enrollment confirmation is required.',
                'face_status': 'ENROLLED',
                'requires_confirm_reenroll': True,
            }), 400

        encoding_path = profile.get('encoding_path') or face_encoder.relative_encoding_path(student_id)
        if not verify_encoding_for_student(student_id, encoding_path):
            return jsonify({
                'error': 'Existing face encoding is missing or invalid; cannot safely re-enroll.',
                'face_status': 'ENROLLED',
            }), 409

    if _recognition_running():
        return jsonify({'error': 'Live recognition is using the camera. Stop it before enrollment.'}), 409

    with _session_lock:
        if student_id in _sessions and _sessions[student_id].get('status') in ('capturing', 'encoding'):
            return jsonify({'error': 'Enrollment already in progress for this student'}), 409
        _sessions[student_id] = {
            'status': 'capturing',
            'captured': 0,
            'target': config.TARGET_SAMPLES,
            'error': None,
            'reenroll': is_reenroll,
        }

    def _run_enrollment():
        old_encoding_bytes = face_encoder.encoding_bytes(student_id) if is_reenroll else None
        try:
            def on_progress(captured, target):
                with _session_lock:
                    _sessions[student_id]['captured'] = captured

            # For re-enrollment, verify the live final encoding is still intact before capture.
            if is_reenroll and old_encoding_bytes is not None:
                current = face_encoder.encoding_bytes(student_id)
                if current != old_encoding_bytes:
                    with _session_lock:
                        _sessions[student_id].update({
                            'status': 'failed',
                            'error': 'Existing encoding changed unexpectedly before re-enrollment capture',
                        })
                    return

            capture_result = face_capture.capture_samples(student_id, progress_callback=on_progress)

            if not capture_result['success']:
                face_encoder.discard_temporary_encoding(student_id)
                with _session_lock:
                    _sessions[student_id].update({
                        'status': 'failed',
                        'captured': capture_result['sample_count'],
                        'error': capture_result.get('error', 'Capture did not complete'),
                        'preserved_existing': is_reenroll,
                    })
                return

            # Re-check old encoding still untouched after capture.
            if is_reenroll and old_encoding_bytes is not None:
                if face_encoder.encoding_bytes(student_id) != old_encoding_bytes:
                    face_encoder.discard_temporary_encoding(student_id)
                    with _session_lock:
                        _sessions[student_id].update({
                            'status': 'failed',
                            'error': 'Existing encoding was modified during capture; aborted without replacement',
                            'preserved_existing': True,
                        })
                    return

            with _session_lock:
                _sessions[student_id]['status'] = 'encoding'

            enc_result = face_encoder.generate_embeddings(student_id, temporary=True)

            if not enc_result['success']:
                face_encoder.discard_temporary_encoding(student_id)
                with _session_lock:
                    _sessions[student_id].update({
                        'status': 'failed',
                        'error': enc_result.get('error', 'Embedding generation failed'),
                        'preserved_existing': is_reenroll,
                    })
                return

            # Old final encoding must still match pre-capture bytes before promote.
            if is_reenroll and old_encoding_bytes is not None:
                if face_encoder.encoding_bytes(student_id) != old_encoding_bytes:
                    face_encoder.discard_temporary_encoding(student_id)
                    with _session_lock:
                        _sessions[student_id].update({
                            'status': 'failed',
                            'error': 'Existing encoding changed before promotion; aborted',
                            'preserved_existing': True,
                        })
                    return

            promote_result = face_encoder.promote_encoding(student_id)
            if not promote_result.get('success'):
                face_encoder.discard_temporary_encoding(student_id)
                with _session_lock:
                    _sessions[student_id].update({
                        'status': 'failed',
                        'error': promote_result.get('error', 'Failed to promote new encoding'),
                        'preserved_existing': is_reenroll,
                    })
                return

            try:
                db.create_or_update_face_profile(
                    student_id=student_id,
                    encoding_path=enc_result['encoding_path'],
                    sample_count=capture_result['sample_count'],
                )
            except Exception as exc:
                logger.exception('Face profile update failed after encoding promote student_id=%s', student_id)
                if is_reenroll:
                    restore = face_encoder.restore_encoding_backup(student_id)
                    if not restore.get('success'):
                        logger.error(
                            'CRITICAL: DB update failed and encoding backup restore failed for student_id=%s: %s',
                            student_id,
                            restore.get('error'),
                        )
                else:
                    # First enrollment: remove promoted file so we do not leave an orphan encoding
                    # without a face_profiles row.
                    try:
                        face_encoder.final_encoding_path(student_id).unlink(missing_ok=True)
                    except OSError:
                        pass
                    face_encoder.clear_encoding_backup(student_id)

                with _session_lock:
                    _sessions[student_id].update({
                        'status': 'failed',
                        'error': f'Failed to update face profile: {exc}',
                        'preserved_existing': is_reenroll,
                    })
                return

            face_encoder.clear_encoding_backup(student_id)

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
                    cleanup_warning = (
                        'Enrollment succeeded but the recognition gallery could not be reloaded. '
                        'Restart the face service or reload profiles if recognition still uses the previous encoding.'
                    )

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
                    'reenroll': is_reenroll,
                }
                if cleanup_warning:
                    session_payload['cleanup_warning'] = cleanup_warning
                _sessions[student_id].update(session_payload)

        except Exception as exc:
            face_encoder.discard_temporary_encoding(student_id)
            with _session_lock:
                _sessions[student_id].update({
                    'status': 'failed',
                    'error': str(exc),
                    'preserved_existing': is_reenroll,
                })

    thread = threading.Thread(target=_run_enrollment, daemon=True)
    thread.start()

    return jsonify({
        'message': (
            'Re-enrollment started – existing face profile stays active until the new encoding succeeds'
            if is_reenroll else
            'Enrollment started – live preview is available in the browser'
        ),
        'student_id': student_id,
        'student_name': f"{student['first_name']} {student['last_name']}",
        'target_samples': config.TARGET_SAMPLES,
        'preview_url': '/api/enrollment/preview',
        'reenroll': is_reenroll,
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


# ---------- Enrollment live preview (in-memory JPEG / MJPEG; no DB writes) ----------

@app.route('/api/enrollment/preview', methods=['GET'])
def enrollment_preview():
    """
    MJPEG stream of the latest enrollment capture frame.
    Reuses frames from the same VideoCapture owned by capture_samples().
    Safe idle response when enrollment is not active.
    """
    # Accept while capturing (even before first frame) so the browser can connect
    # immediately after /api/enrollment/start without a second camera open.
    if not face_capture.is_preview_active() and not _enrollment_in_progress():
        return jsonify({
            'active': False,
            'message': 'Enrollment preview is not active',
        }), 404

    boundary = b'frame'

    def generate():
        # Warm-up while the capture thread opens the camera.
        deadline = time.time() + 15.0
        while time.time() < deadline and not face_capture.is_preview_active():
            if not _enrollment_in_progress():
                return
            time.sleep(0.05)

        while face_capture.is_preview_active():
            jpeg = face_capture.get_preview_jpeg()
            if jpeg:
                yield (
                    b'--' + boundary + b'\r\n'
                    b'Content-Type: image/jpeg\r\n\r\n' + jpeg + b'\r\n'
                )
            time.sleep(0.04)

    return Response(
        generate(),
        mimetype='multipart/x-mixed-replace; boundary=frame',
        headers={
            'Cache-Control': 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma': 'no-cache',
            'X-Accel-Buffering': 'no',
        },
    )


@app.route('/api/enrollment/preview.jpg', methods=['GET'])
def enrollment_preview_jpeg():
    """Single latest preview frame (polling fallback). Memory only; no storage."""
    jpeg = face_capture.get_preview_jpeg()
    if not jpeg or not face_capture.is_preview_active():
        return jsonify({
            'active': False,
            'message': 'Enrollment preview is not active',
        }), 404
    return Response(
        jpeg,
        mimetype='image/jpeg',
        headers={
            'Cache-Control': 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma': 'no-cache',
        },
    )


@app.route('/api/enrollment/cancel', methods=['POST'])
def enrollment_cancel():
    """Request cancel of the active capture loop (web alternative to Q)."""
    data = request.get_json(silent=True) or {}
    student_id = data.get('student_id')

    if student_id is not None:
        try:
            student_id = int(student_id)
        except (ValueError, TypeError):
            return jsonify({'error': 'student_id must be a positive integer'}), 400
        if student_id <= 0:
            return jsonify({'error': 'student_id must be a positive integer'}), 400

        with _session_lock:
            session = _sessions.get(student_id)
            if session is None or session.get('status') not in ('capturing', 'encoding'):
                return jsonify({
                    'message': 'No active enrollment capture to cancel',
                    'active': False,
                }), 200

    if not face_capture.is_preview_active() and not _enrollment_in_progress():
        return jsonify({
            'message': 'No active enrollment capture to cancel',
            'active': False,
        }), 200

    face_capture.request_cancel()
    return jsonify({
        'message': 'Enrollment cancel requested',
        'active': True,
    }), 202


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
    app.run(host=config.FLASK_HOST, port=config.FLASK_PORT, debug=config.FLASK_DEBUG, threaded=True)
