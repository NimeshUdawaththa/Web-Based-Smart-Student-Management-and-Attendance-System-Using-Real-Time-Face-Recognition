"""
Live webcam recognition of enrolled students.

Usage (from the face-recognition directory, venv active):

    python -m recognition.live_recognition

Press Q to quit, R to reload enrolled profiles. No attendance is written.
"""

from __future__ import annotations

import logging
import sys
import threading
import time
from pathlib import Path

import cv2
import face_recognition
import numpy as np

# Allow `python recognition/live_recognition.py` as well as `python -m ...`
_ROOT = Path(__file__).resolve().parent.parent
if str(_ROOT) not in sys.path:
    sys.path.insert(0, str(_ROOT))

import config
import db
from recognition.cooldown import RecognitionCooldown
from recognition.matcher import MatchResult, match_encoding
from recognition.metrics import RecognitionMetrics
from recognition.profile_loader import FaceGallery, get_gallery, reload_gallery

logger = logging.getLogger(__name__)

_BOX_KNOWN = (40, 180, 60)
_BOX_UNKNOWN = (40, 40, 220)
_TEXT_KNOWN = (240, 255, 240)
_TEXT_UNKNOWN = (220, 220, 255)


def _scale_location(location: tuple[int, int, int, int], inv_scale: float) -> tuple[int, int, int, int]:
    top, right, bottom, left = location
    return (
        int(top * inv_scale),
        int(right * inv_scale),
        int(bottom * inv_scale),
        int(left * inv_scale),
    )


def recognize_faces_in_frame(bgr_frame: np.ndarray, gallery: FaceGallery) -> list[tuple[tuple[int, int, int, int], MatchResult]]:
    """Detect faces in a downscaled copy, then match each embedding independently."""
    scale = config.RECOGNITION_FRAME_SCALE
    if scale <= 0 or scale > 1:
        scale = 0.25

    model = 'hog'
    small = cv2.resize(bgr_frame, (0, 0), fx=scale, fy=scale)
    rgb_small = cv2.cvtColor(small, cv2.COLOR_BGR2RGB)
    locations = face_recognition.face_locations(rgb_small, model=model)
    encodings = face_recognition.face_encodings(rgb_small, locations)

    inv_scale = 1.0 / scale
    results: list[tuple[tuple[int, int, int, int], MatchResult]] = []
    for location, encoding in zip(locations, encodings):
        match = match_encoding(encoding, gallery)
        results.append((_scale_location(location, inv_scale), match))
    return results


def _draw_match(frame: np.ndarray, location: tuple[int, int, int, int], match: MatchResult) -> None:
    top, right, bottom, left = location
    color = _BOX_KNOWN if match.is_match else _BOX_UNKNOWN
    text_color = _TEXT_KNOWN if match.is_match else _TEXT_UNKNOWN
    cv2.rectangle(frame, (left, top), (right, bottom), color, 2)

    if match.is_match:
        name_line = f'{match.full_name} | {match.registration_no}'
        id_line = f'ID {match.student_id}'
        dist_line = f'd={match.distance:.3f}  {match.confidence:.0f}%'
    else:
        name_line = config.UNKNOWN_LABEL
        id_line = ''
        dist_line = f'd={match.distance:.3f}' if match.distance is not None else 'no gallery'

    y = max(top - 8, 50)
    cv2.putText(frame, name_line, (left, y - 22), cv2.FONT_HERSHEY_SIMPLEX, 0.55, text_color, 2)
    if id_line:
        cv2.putText(frame, id_line, (left, y), cv2.FONT_HERSHEY_SIMPLEX, 0.5, text_color, 1)
        cv2.putText(frame, dist_line, (left, bottom + 18), cv2.FONT_HERSHEY_SIMPLEX, 0.5, text_color, 1)
    else:
        cv2.putText(frame, dist_line, (left, bottom + 18), cv2.FONT_HERSHEY_SIMPLEX, 0.5, text_color, 1)


def _draw_hud(frame: np.ndarray, gallery: FaceGallery, fps: float, processing_ms: float | None) -> None:
    lines = [
        f'Enrolled: {gallery.student_count}  skipped: {len(gallery.skipped)}  threshold: {config.MATCH_THRESHOLD:.2f}',
        f'FPS: {fps:.1f}  last process: {processing_ms:.0f} ms' if processing_ms is not None else f'FPS: {fps:.1f}',
        'Q quit   R reload profiles',
    ]
    y = 24
    for line in lines:
        cv2.putText(frame, line, (10, y), cv2.FONT_HERSHEY_SIMPLEX, 0.55, (255, 255, 255), 2)
        y += 22


def run_live_recognition(
    stop_event: threading.Event | None = None,
    metrics: RecognitionMetrics | None = None,
    cooldown: RecognitionCooldown | None = None,
) -> dict:
    """
    Open the webcam and identify enrolled students until Q or stop_event.

    Always releases the camera. Does not write attendance events.
    """
    gallery = get_gallery()
    if metrics is None:
        metrics = RecognitionMetrics()
    if cooldown is None:
        cooldown = RecognitionCooldown(config.RECOGNITION_COOLDOWN_SECONDS)

    cap = cv2.VideoCapture(config.CAMERA_INDEX)
    if not cap.isOpened():
        logger.error('Cannot open camera index %s', config.CAMERA_INDEX)
        return {'success': False, 'error': 'Cannot open camera', 'cancelled': False}

    window_title = 'SmartAMS – Live Recognition (Q quit, R reload)'
    frame_index = 0
    last_results: list[tuple[tuple[int, int, int, int], MatchResult]] = []
    last_process_ms: float | None = None
    fps = 0.0
    fps_counter = 0
    fps_t0 = time.perf_counter()
    cancelled = False

    logger.info(
        'Live recognition started (threshold=%.3f, match_k=%s, scale=%.2f, skip=%s)',
        config.MATCH_THRESHOLD,
        config.MATCH_K,
        config.RECOGNITION_FRAME_SCALE,
        config.PROCESS_EVERY_N_FRAMES,
    )

    try:
        while True:
            if stop_event is not None and stop_event.is_set():
                cancelled = True
                break

            ok, frame = cap.read()
            if not ok:
                logger.warning('Camera frame read failed')
                break

            frame_index += 1
            fps_counter += 1
            now = time.perf_counter()
            elapsed = now - fps_t0
            if elapsed >= 1.0:
                fps = fps_counter / elapsed
                fps_counter = 0
                fps_t0 = now

            process_every = max(1, int(config.PROCESS_EVERY_N_FRAMES))
            should_process = frame_index == 1 or (frame_index % process_every == 0)

            if should_process:
                t0 = time.perf_counter()
                last_results = recognize_faces_in_frame(frame, gallery)
                last_process_ms = (time.perf_counter() - t0) * 1000.0
                metrics.record_frame([match for _, match in last_results], last_process_ms)

                for _, match in last_results:
                    if match.is_match and match.student_id is not None:
                        eligible = cooldown.observe(match.student_id)
                        if eligible:
                            student = db.get_student_by_id(match.student_id)
                            if student is None:
                                logger.warning(
                                    'Matched student_id=%s is not present in MySQL; treating display identity as stale',
                                    match.student_id,
                                )
                            else:
                                logger.info(
                                    'Recognized student_id=%s registration_no=%s name=%s %s distance=%.4f (cooldown open; no attendance write)',
                                    student['student_id'],
                                    student['registration_no'],
                                    student['first_name'],
                                    student['last_name'],
                                    match.distance if match.distance is not None else -1.0,
                                )

            display = frame
            for location, match in last_results:
                _draw_match(display, location, match)
            _draw_hud(display, gallery, fps, last_process_ms)

            cv2.imshow(window_title, display)
            key = cv2.waitKey(1) & 0xFF
            if key in (ord('q'), ord('Q')):
                cancelled = True
                break
            if key in (ord('r'), ord('R')):
                try:
                    summary = reload_gallery()
                    logger.info('Reloaded face profiles: %s', summary)
                except Exception:
                    logger.exception('Failed to reload face profiles')

    finally:
        cap.release()
        cv2.destroyAllWindows()
        logger.info('Live recognition stopped; camera released')

    snapshot = metrics.snapshot()
    return {
        'success': True,
        'cancelled': cancelled,
        'metrics': snapshot,
        'cooldown': cooldown.snapshot(),
    }


def main() -> int:
    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s [%(levelname)s] %(name)s: %(message)s',
    )
    try:
        reload_gallery()
    except Exception:
        logger.exception('Could not load enrolled face profiles. Is MySQL running?')
        return 1

    result = run_live_recognition()
    if not result.get('success'):
        logger.error(result.get('error', 'Recognition failed'))
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
