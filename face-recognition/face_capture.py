"""
Face capture module – opens the webcam, detects exactly one face per frame,
applies quality checks, and saves samples to disk.

Also publishes the latest enrollment frame in-memory for browser live preview
(no second VideoCapture; preview is transient JPEG bytes only).
"""

import shutil
import threading
import time
from pathlib import Path

import cv2
import numpy as np

import config
from recognition import camera_runtime

# Transient browser preview (enrollment-only; never written to disk/DB)
_preview_lock = threading.Lock()
_preview_jpeg: bytes | None = None
_preview_active = False
_cancel_event = threading.Event()

_PREVIEW_MAX_WIDTH = 720
_PREVIEW_JPEG_QUALITY = 70


def request_cancel() -> None:
    """Signal the active capture loop to stop (web cancel / API)."""
    _cancel_event.set()


def clear_cancel() -> None:
    _cancel_event.clear()


def is_preview_active() -> bool:
    with _preview_lock:
        return _preview_active


def get_preview_jpeg() -> bytes | None:
    with _preview_lock:
        return _preview_jpeg


def _set_preview_active(active: bool) -> None:
    global _preview_active
    with _preview_lock:
        _preview_active = active
        if not active:
            global _preview_jpeg
            _preview_jpeg = None


def _publish_preview_frame(frame: np.ndarray) -> None:
    """Encode a downscaled JPEG copy of the current enrollment frame (memory only)."""
    global _preview_jpeg
    try:
        preview = frame
        height, width = preview.shape[:2]
        if width > _PREVIEW_MAX_WIDTH:
            scale = _PREVIEW_MAX_WIDTH / float(width)
            preview = cv2.resize(
                preview,
                (int(width * scale), int(height * scale)),
                interpolation=cv2.INTER_AREA,
            )
        ok, buf = cv2.imencode(
            '.jpg',
            preview,
            [int(cv2.IMWRITE_JPEG_QUALITY), _PREVIEW_JPEG_QUALITY],
        )
        if not ok:
            return
        with _preview_lock:
            if _preview_active:
                _preview_jpeg = buf.tobytes()
    except Exception:
        # Preview is best-effort; never fail enrollment capture.
        pass


def _is_blurry(gray_face: np.ndarray) -> bool:
    variance = cv2.Laplacian(gray_face, cv2.CV_64F).var()
    return variance < config.BLUR_THRESHOLD


def _detect_faces(frame: np.ndarray, detector: cv2.CascadeClassifier) -> list:
    gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    faces = detector.detectMultiScale(
        gray, scaleFactor=1.1, minNeighbors=5, minSize=(config.MIN_FACE_SIZE, config.MIN_FACE_SIZE)
    )
    return faces if isinstance(faces, np.ndarray) else []


def prepare_fresh_dataset(student_id: int) -> dict:
    """
    Clear and recreate dataset/{student_id}/ so a new capture never mixes stale JPGs.

    Never touches encodings/, other students, or profile photos.
    """
    from dataset_cleanup import resolve_student_dataset_dir

    target = resolve_student_dataset_dir(student_id)
    if target is None:
        return {
            'success': False,
            'path': None,
            'error': 'Invalid student_id or path outside DATASET_DIR',
        }

    try:
        if target.exists():
            if not target.is_dir():
                return {
                    'success': False,
                    'path': str(target),
                    'error': 'Dataset target is not a directory',
                }
            shutil.rmtree(target)
        target.mkdir(parents=True, exist_ok=True)
    except Exception as exc:
        return {
            'success': False,
            'path': str(target),
            'error': str(exc),
        }

    return {'success': True, 'path': str(target), 'error': None}


def capture_samples(student_id: int, progress_callback=None) -> dict:
    """
    Open the webcam and capture TARGET_SAMPLES face images for the given student.

    Returns dict:
        success: bool
        sample_count: int
        save_dir: str
        cancelled: bool
    """
    prepared = prepare_fresh_dataset(student_id)
    if not prepared.get('success'):
        return {
            'success': False,
            'sample_count': 0,
            'save_dir': prepared.get('path') or '',
            'cancelled': False,
            'error': prepared.get('error') or 'Could not prepare fresh sample folder',
        }

    save_dir = Path(prepared['path'])

    detector = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')

    print(f'Opening camera index: {config.CAMERA_INDEX}', flush=True)
    if not camera_runtime.try_acquire_camera_mutex():
        error = 'Camera already running'
        print(error, flush=True)
        return {'success': False, 'sample_count': 0, 'save_dir': str(save_dir),
                'cancelled': False, 'error': error}

    clear_cancel()
    cap = None
    try:
        cap = cv2.VideoCapture(config.CAMERA_INDEX)
    except Exception:
        cap = None
    if cap is None or not cap.isOpened():
        if cap is not None:
            cap.release()
        camera_runtime.release_camera_mutex()
        error = f'Could not open camera index {config.CAMERA_INDEX}'
        print(error, flush=True)
        return {'success': False, 'sample_count': 0, 'save_dir': str(save_dir),
                'cancelled': False, 'error': error}

    captured = 0
    last_capture_time = 0.0
    target = config.TARGET_SAMPLES
    cancelled = False
    _set_preview_active(True)

    try:
        while captured < target:
            if _cancel_event.is_set():
                cancelled = True
                break

            ret, frame = cap.read()
            if not ret:
                break

            faces = _detect_faces(frame, detector)
            status_text = ''
            display = frame.copy()

            if len(faces) == 0:
                status_text = 'No face detected – look at the camera'
            elif len(faces) > 1:
                status_text = 'Multiple faces detected – only one person allowed'
            else:
                x, y, w, h = faces[0]
                now = time.time() * 1000
                gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
                face_roi = gray[y:y + h, x:x + w]

                if _is_blurry(face_roi):
                    status_text = 'Blurry – hold still'
                elif (now - last_capture_time) < config.CAPTURE_INTERVAL_MS:
                    status_text = f'Captured {captured}/{target} – hold position...'
                else:
                    captured += 1
                    filename = save_dir / f'sample_{captured:03d}.jpg'
                    # Save the original enrollment frame (not the preview overlay).
                    cv2.imwrite(str(filename), frame)
                    last_capture_time = now
                    status_text = f'Captured {captured}/{target}'
                    if progress_callback:
                        progress_callback(captured, target)

                cv2.rectangle(display, (x, y), (x + w, y + h), (0, 255, 0), 2)

            cv2.putText(display, status_text, (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 255), 2)
            _publish_preview_frame(display)

            # Keep the server OpenCV window for keyboard cancel (Q). Browser
            # preview is primary for positioning; window is a safe fallback.
            cv2.imshow(f'Face Enrollment – Student {student_id}', display)

            key = cv2.waitKey(1) & 0xFF
            if key == ord('q') or key == ord('Q'):
                cancelled = True
                break

    finally:
        _set_preview_active(False)
        clear_cancel()
        if cap is not None:
            cap.release()
        cv2.destroyAllWindows()
        camera_runtime.release_camera_mutex()

    if cancelled and captured < target:
        return {'success': False, 'sample_count': captured, 'save_dir': str(save_dir),
                'cancelled': True, 'error': 'Enrollment cancelled by user'}

    return {
        'success': captured >= target,
        'sample_count': captured,
        'save_dir': str(save_dir),
        'cancelled': False,
    }
