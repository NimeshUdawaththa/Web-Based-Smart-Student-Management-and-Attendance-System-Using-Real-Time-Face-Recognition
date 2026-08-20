"""
Regression checks for enrollment embedded live preview (no camera required).

Confirms:
- preview frames are memory-only
- idle preview is inactive
- cancel flag works
- TARGET_SAMPLES / sample save path unchanged in source
- Flask preview/cancel routes exist without DB writes
"""

from __future__ import annotations

import sys
from pathlib import Path

import cv2
import numpy as np

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

import config
import face_capture


def assert_true(cond: bool, label: str) -> None:
    if not cond:
        raise AssertionError(f'FAIL: {label}')
    print(f'PASS {label}')


def main() -> None:
    assert_true(config.TARGET_SAMPLES == 25, 'TARGET_SAMPLES remains 25')

    face_capture._set_preview_active(False)
    face_capture.clear_cancel()
    assert_true(not face_capture.is_preview_active(), 'Idle preview inactive')
    assert_true(face_capture.get_preview_jpeg() is None, 'Idle preview has no JPEG')

    face_capture._set_preview_active(True)
    frame = np.zeros((240, 320, 3), dtype=np.uint8)
    frame[:] = (40, 80, 120)
    cv2.rectangle(frame, (100, 60), (220, 200), (0, 255, 0), 2)
    face_capture._publish_preview_frame(frame)
    jpeg = face_capture.get_preview_jpeg()
    assert_true(isinstance(jpeg, (bytes, bytearray)) and len(jpeg) > 50, 'Preview publishes JPEG bytes')
    assert_true(jpeg[:2] == b'\xff\xd8', 'Preview JPEG starts with SOI marker')

    # Ensure no preview sidecar files under dataset/encodings from this helper.
    before_dataset = {p.name for p in config.DATASET_DIR.glob('*')} if config.DATASET_DIR.exists() else set()
    before_enc = {p.name for p in config.ENCODINGS_DIR.glob('*')} if config.ENCODINGS_DIR.exists() else set()
    face_capture._publish_preview_frame(frame)
    after_dataset = {p.name for p in config.DATASET_DIR.glob('*')} if config.DATASET_DIR.exists() else set()
    after_enc = {p.name for p in config.ENCODINGS_DIR.glob('*')} if config.ENCODINGS_DIR.exists() else set()
    assert_true(before_dataset == after_dataset, 'Preview does not create dataset folders')
    assert_true(before_enc == after_enc, 'Preview does not create encoding files')

    face_capture.request_cancel()
    assert_true(face_capture._cancel_event.is_set(), 'Cancel request sets capture cancel flag')
    face_capture.clear_cancel()
    face_capture._set_preview_active(False)
    assert_true(face_capture.get_preview_jpeg() is None, 'Clearing preview drops JPEG buffer')

    app_source = (ROOT / 'app.py').read_text(encoding='utf-8')
    capture_source = (ROOT / 'face_capture.py').read_text(encoding='utf-8')
    assert_true('/api/enrollment/preview' in app_source, 'Flask MJPEG preview route present')
    assert_true('/api/enrollment/preview.jpg' in app_source, 'Flask JPEG snapshot route present')
    assert_true('/api/enrollment/cancel' in app_source, 'Flask cancel route present')
    assert_true('create_or_update_face_profile' not in app_source.split('def enrollment_preview')[1].split('def enrollment_preview_jpeg')[0],
                'Preview handler has no face_profiles writes')
    assert_true('_publish_preview_frame' in capture_source, 'Capture loop publishes preview frames')
    assert_true('cv2.imwrite(str(filename), frame)' in capture_source, 'Samples still save original frame')
    assert_true('try_acquire_camera_mutex' in capture_source, 'Camera mutex ownership preserved')
    assert_true('MATCH_THRESHOLD' not in capture_source, 'Capture module does not alter match threshold')

    print('\nRESULT: PASSED')


if __name__ == '__main__':
    main()
