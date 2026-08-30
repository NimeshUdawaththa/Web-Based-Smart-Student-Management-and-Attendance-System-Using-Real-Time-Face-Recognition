"""
Live webcam recognition of enrolled students.

Usage (from the face-recognition directory, venv active):

    python -m recognition.live_recognition

Press Q to quit, R to reload enrolled profiles.
Eligible recognized students are checked in/out through PHP using an explicit
ENTRY or EXIT camera mode. Press E to toggle mode on a single test webcam.
"""

from __future__ import annotations

import logging
import os
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
from recognition.attendance_client import AttendanceDecision, submit_recognized_attendance, unknown_decision
from recognition import camera_runtime
from recognition.cooldown import RecognitionCooldown
from recognition.matcher import MatchResult, match_encoding
from recognition.metrics import RecognitionMetrics
from recognition.profile_loader import FaceGallery, get_gallery, reload_gallery

logger = logging.getLogger(__name__)

_BOX_KNOWN = (40, 180, 60)
_BOX_UNKNOWN = (40, 40, 220)
_BOX_WARN = (0, 140, 255)
_TEXT_KNOWN = (240, 255, 240)
_TEXT_UNKNOWN = (220, 220, 255)
_TEXT_WARN = (220, 240, 255)


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


def _draw_match(
    frame: np.ndarray,
    location: tuple[int, int, int, int],
    match: MatchResult,
    decision: AttendanceDecision,
) -> None:
    top, right, bottom, left = location
    if decision.code == 'UNKNOWN' or not match.is_match:
        color, text_color = _BOX_UNKNOWN, _TEXT_UNKNOWN
    elif decision.code in {
        'NOT_ELIGIBLE',
        'NO_ACTIVE_SESSION',
        'AMBIGUOUS_ACTIVE_SESSIONS',
        'INVALID_STUDENT',
        'TOO_EARLY',
        'EARLY_PENDING',
        'EARLY_EXIT',
    }:
        color, text_color = _BOX_WARN, _TEXT_WARN
    else:
        color, text_color = _BOX_KNOWN, _TEXT_KNOWN

    cv2.rectangle(frame, (left, top), (right, bottom), color, 2)

    lines: list[str] = [decision.overlay_title]
    if match.is_match:
        lines.append(match.full_name or match.label)
        if match.registration_no:
            lines.append(str(match.registration_no))
        if decision.code in {'CHECKED_IN', 'RE_ENTERED', 'CHECKED_OUT', 'ALREADY_INSIDE', 'ALREADY_CHECKED_IN'} and decision.module_code:
            lines.append(str(decision.module_code))
        if decision.code in {'CHECKED_IN', 'RE_ENTERED', 'CHECKED_OUT'} and decision.recognized_at:
            lines.append(str(decision.recognized_at))
    elif match.distance is not None:
        lines.append(f'd={match.distance:.3f}')

    y = max(top - 10, 20 + 20 * len(lines))
    for index, line in enumerate(reversed(lines)):
        cv2.putText(
            frame,
            line,
            (left, y - (index * 20)),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.52,
            text_color,
            2,
        )


def _attendance_for_match(
    match: MatchResult,
    cooldown: RecognitionCooldown,
    cache: dict[int, AttendanceDecision],
    camera_mode: str,
) -> AttendanceDecision:
    if not match.is_match or match.student_id is None:
        return unknown_decision()

    student_id = match.student_id
    if not cooldown.observe(student_id):
        cached = cache.get(student_id)
        if cached is None:
            return AttendanceDecision(code='ERROR', camera_mode=camera_mode)
        if cached.code in {'CHECKED_IN', 'RE_ENTERED'} and camera_mode == 'ENTRY':
            return AttendanceDecision(
                code='ALREADY_INSIDE',
                module_code=cached.module_code,
                recognized_at=cached.recognized_at,
                session_id=cached.session_id,
                event_id=cached.event_id,
                camera_mode=camera_mode,
            )
        if cached.code == 'CHECKED_OUT' and camera_mode == 'EXIT':
            return AttendanceDecision(
                code='ALREADY_OUTSIDE',
                module_code=cached.module_code,
                recognized_at=cached.recognized_at,
                session_id=cached.session_id,
                event_id=cached.event_id,
                camera_mode=camera_mode,
            )
        return cached

    decision = submit_recognized_attendance(student_id, match.confidence, camera_mode)
    cache[student_id] = decision
    if decision.code in {'CHECKED_IN', 'RE_ENTERED'}:
        logger.info(
            '%s student_id=%s session_id=%s module=%s event_id=%s mode=%s',
            decision.code,
            student_id,
            decision.session_id,
            decision.module_code,
            decision.event_id,
            camera_mode,
        )
    elif decision.code == 'CHECKED_OUT':
        logger.info('Checked out student_id=%s session_id=%s', student_id, decision.session_id)
    elif decision.code in {
        'ALREADY_INSIDE',
        'ALREADY_OUTSIDE',
        'EARLY_PENDING',
        'EARLY_EXIT',
        'TOO_EARLY',
        'NOT_ELIGIBLE',
        'NO_ACTIVE_SESSION',
        'AMBIGUOUS_ACTIVE_SESSIONS',
    }:
        logger.info('No new attendance event for student_id=%s result=%s', student_id, decision.code)
    elif decision.code == 'ERROR':
        logger.warning('Attendance unavailable for student_id=%s', student_id)
    return decision


def _draw_hud(frame: np.ndarray, gallery: FaceGallery, fps: float, processing_ms: float | None, camera_mode: str) -> None:
    lines = [
        f'Camera: {config.CAMERA_INDEX}   Mode: {camera_mode}',
        f'Enrolled: {gallery.student_count}  skipped: {len(gallery.skipped)}  threshold: {config.MATCH_THRESHOLD:.2f}',
        f'FPS: {fps:.1f}  last process: {processing_ms:.0f} ms' if processing_ms is not None else f'FPS: {fps:.1f}',
        f'E toggle ENTRY/EXIT   Q quit   R reload',
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
    Open the webcam, identify enrolled students, and record IN/OUT attendance
    through PHP. Direction comes from camera_mode (press E to toggle).
    """
    gallery = get_gallery()
    if metrics is None:
        metrics = RecognitionMetrics()
    if cooldown is None:
        cooldown = RecognitionCooldown(config.RECOGNITION_COOLDOWN_SECONDS)

    camera_runtime.configure_file_logging()
    if not camera_runtime.try_acquire_camera_mutex():
        error = 'Camera already running'
        print(error, flush=True)
        logger.error(error)
        return {'success': False, 'error': error, 'cancelled': False, 'already_running': True}

    camera_index = config.CAMERA_INDEX
    message = f'Opening camera index: {camera_index}'
    print(message, flush=True)
    logger.info(message)
    camera_runtime.mark_starting(os.getpid())
    camera_runtime.clear_stop_request()

    cap = None
    try:
        cap = cv2.VideoCapture(camera_index)
    except Exception:
        cap = None
    if cap is None or not cap.isOpened():
        if cap is not None:
            cap.release()
        error = f'Could not open camera index {camera_index}'
        print(error, flush=True)
        logger.error(error)
        camera_runtime.mark_error(error, os.getpid())
        camera_runtime.release_camera_mutex()
        return {'success': False, 'error': error, 'cancelled': False}

    ok, first_frame = cap.read()
    if not ok or first_frame is None:
        cap.release()
        error = f'Could not open camera index {camera_index}'
        print(error, flush=True)
        logger.error(error)
        camera_runtime.mark_error(error, os.getpid())
        camera_runtime.release_camera_mutex()
        return {'success': False, 'error': error, 'cancelled': False}

    window_title = 'SmartAMS – Live Recognition (E mode, Q quit, R reload)'
    frame_index = 0
    last_results: list[tuple[tuple[int, int, int, int], MatchResult, AttendanceDecision]] = []
    last_process_ms: float | None = None
    fps = 0.0
    fps_counter = 0
    fps_t0 = time.perf_counter()
    cancelled = False
    attendance_cache: dict[int, AttendanceDecision] = {}
    camera_mode = camera_runtime.camera_mode()
    camera_runtime.mark_online(os.getpid(), camera_mode)
    last_heartbeat = 0.0

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
            if camera_runtime.stop_requested():
                logger.info('Stop requested from Camera Management')
                cancelled = True
                break

            requested_mode = camera_runtime.camera_mode()
            if requested_mode != camera_mode:
                camera_mode = requested_mode
                attendance_cache.clear()
                cooldown.clear()
                logger.info('Camera mode set to %s', camera_mode)

            now_mono = time.perf_counter()
            if now_mono - last_heartbeat >= 1.0:
                camera_runtime.heartbeat(os.getpid(), camera_mode)
                last_heartbeat = now_mono

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
                recognized = recognize_faces_in_frame(frame, gallery)
                last_process_ms = (time.perf_counter() - t0) * 1000.0
                metrics.record_frame([match for _, match in recognized], last_process_ms)

                last_results = []
                for location, match in recognized:
                    try:
                        decision = _attendance_for_match(match, cooldown, attendance_cache, camera_mode)
                    except Exception:
                        logger.exception('Attendance handling failed for a face; continuing recognition')
                        decision = AttendanceDecision(code='ERROR') if match.is_match else unknown_decision()
                    last_results.append((location, match, decision))

            display = frame
            for location, match, decision in last_results:
                _draw_match(display, location, match, decision)
            _draw_hud(display, gallery, fps, last_process_ms, camera_mode)

            cv2.imshow(window_title, display)
            key = cv2.waitKey(1) & 0xFF
            if key in (ord('e'), ord('E')):
                camera_mode = 'EXIT' if camera_mode == 'ENTRY' else 'ENTRY'
                camera_runtime.set_camera_mode(camera_mode)
                attendance_cache.clear()
                cooldown.clear()
                logger.info('Camera mode set to %s', camera_mode)
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
        if cap is not None:
            cap.release()
        cv2.destroyAllWindows()
        camera_runtime.mark_offline()
        camera_runtime.release_camera_mutex()
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
    camera_runtime.configure_file_logging()
    try:
        reload_gallery()
    except Exception:
        logger.exception('Could not load enrolled face profiles. Is MySQL running?')
        camera_runtime.mark_error('Could not load enrolled face profiles')
        return 1

    result = run_live_recognition()
    if not result.get('success'):
        error = str(result.get('error') or 'Recognition failed')
        print(error, flush=True)
        logger.error(error)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
