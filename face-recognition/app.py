"""
Flask REST service – bridges the PHP web application and the Python face enrollment pipeline.
Runs on localhost only.
"""

import threading

from flask import Flask, jsonify, request
from flask_cors import CORS

import config
import db
import face_capture
import face_encoder

app = Flask(__name__)
CORS(app, origins=['http://localhost', 'http://localhost:8080', 'http://127.0.0.1:8080'])

# In-memory enrollment session tracker (single-server dev usage)
_sessions: dict[int, dict] = {}
_session_lock = threading.Lock()


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

            with _session_lock:
                _sessions[student_id].update({
                    'status': 'completed',
                    'captured': capture_result['sample_count'],
                    'encodings': enc_result['encoding_count'],
                    'error': None,
                })

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


# ---------- Entry point ----------

if __name__ == '__main__':
    print(f'Face Recognition Service starting on http://{config.FLASK_HOST}:{config.FLASK_PORT}')
    app.run(host=config.FLASK_HOST, port=config.FLASK_PORT, debug=config.FLASK_DEBUG)
