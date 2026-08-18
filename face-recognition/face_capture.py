"""
Face capture module – opens the webcam, detects exactly one face per frame,
applies quality checks, and saves samples to disk.
"""

import time
from pathlib import Path

import cv2
import numpy as np

import config


def _is_blurry(gray_face: np.ndarray) -> bool:
    variance = cv2.Laplacian(gray_face, cv2.CV_64F).var()
    return variance < config.BLUR_THRESHOLD


def _detect_faces(frame: np.ndarray, detector: cv2.CascadeClassifier) -> list:
    gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    faces = detector.detectMultiScale(
        gray, scaleFactor=1.1, minNeighbors=5, minSize=(config.MIN_FACE_SIZE, config.MIN_FACE_SIZE)
    )
    return faces if isinstance(faces, np.ndarray) else []


def capture_samples(student_id: int, progress_callback=None) -> dict:
    """
    Open the webcam and capture TARGET_SAMPLES face images for the given student.

    Returns dict:
        success: bool
        sample_count: int
        save_dir: str
        cancelled: bool
    """
    save_dir = config.DATASET_DIR / str(student_id)
    save_dir.mkdir(parents=True, exist_ok=True)

    detector = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')

    cap = cv2.VideoCapture(config.CAMERA_INDEX)
    if not cap.isOpened():
        return {'success': False, 'sample_count': 0, 'save_dir': str(save_dir),
                'cancelled': False, 'error': 'Cannot open camera'}

    captured = 0
    last_capture_time = 0.0
    target = config.TARGET_SAMPLES
    cancelled = False

    try:
        while captured < target:
            ret, frame = cap.read()
            if not ret:
                break

            faces = _detect_faces(frame, detector)
            status_text = ''

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
                    cv2.imwrite(str(filename), frame)
                    last_capture_time = now
                    status_text = f'Captured {captured}/{target}'
                    if progress_callback:
                        progress_callback(captured, target)

                cv2.rectangle(frame, (x, y), (x + w, y + h), (0, 255, 0), 2)

            cv2.putText(frame, status_text, (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 255), 2)
            cv2.imshow(f'Face Enrollment – Student {student_id}', frame)

            key = cv2.waitKey(1) & 0xFF
            if key == ord('q') or key == ord('Q'):
                cancelled = True
                break

    finally:
        cap.release()
        cv2.destroyAllWindows()

    if cancelled and captured < target:
        return {'success': False, 'sample_count': captured, 'save_dir': str(save_dir),
                'cancelled': True, 'error': 'Enrollment cancelled by user'}

    return {
        'success': captured >= target,
        'sample_count': captured,
        'save_dir': str(save_dir),
        'cancelled': False,
    }
