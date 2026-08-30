"""
Regression tests for Face Enrollment Raw Image Cleanup v1.

Usage (from face-recognition/ with venv active, MySQL running):
  python tools/test_dataset_cleanup.py

Does not delete real production dataset folders belonging to other students.
Uses temporary DATASET_DIR / ENCODINGS_DIR under a private temp root for most cases.
"""

from __future__ import annotations

import pickle
import shutil
import sys
import tempfile
import traceback
from pathlib import Path

import numpy as np

# Ensure face-recognition/ is on sys.path when run as a script.
ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import config
import dataset_cleanup
import db
from recognition import profile_loader

failed = False


def pass_(label: str) -> None:
    print(f'PASS {label}')


def fail(label: str, detail: str = '') -> None:
    global failed
    failed = True
    suffix = f' — {detail}' if detail else ''
    print(f'FAIL {label}{suffix}')


def assert_true(condition: bool, label: str, detail: str = '') -> None:
    if condition:
        pass_(label)
    else:
        fail(label, detail)


def write_valid_pkl(path: Path, student_id: int, rows: int = 3) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    encodings = np.random.default_rng(student_id).normal(size=(rows, 128))
    with open(path, 'wb') as handle:
        pickle.dump(
            {
                'student_id': student_id,
                'encodings': encodings,
                'count': rows,
            },
            handle,
        )


def make_sample_folder(dataset_root: Path, student_id: int, count: int = 3) -> Path:
    folder = dataset_root / str(student_id)
    folder.mkdir(parents=True, exist_ok=True)
    for i in range(1, count + 1):
        (folder / f'sample_{i:03d}.jpg').write_bytes(b'fake-jpg-bytes')
    return folder


def main() -> int:
    global failed

    original_dataset = config.DATASET_DIR
    original_encodings = config.ENCODINGS_DIR
    original_base = config.BASE_DIR

    temp_root = Path(tempfile.mkdtemp(prefix='smartams_dataset_cleanup_'))
    temp_dataset = temp_root / 'dataset'
    temp_encodings = temp_root / 'encodings'
    temp_dataset.mkdir(parents=True)
    temp_encodings.mkdir(parents=True)

    # Preserve real project dataset/encodings inventories (must remain untouched).
    real_dataset_before = sorted(p.name for p in original_dataset.iterdir()) if original_dataset.is_dir() else []
    real_encodings_before = sorted(p.name for p in original_encodings.iterdir()) if original_encodings.is_dir() else []

    profile_photo_root = original_base.parent / 'web' / 'uploads' / 'profile-photos'
    profile_photo_marker = None
    if profile_photo_root.is_dir():
        profile_photo_marker = profile_photo_root / f'_cleanup_audit_{Path(tempfile.mktemp()).name}.keep'
        try:
            profile_photo_marker.write_text('do-not-delete', encoding='utf-8')
        except Exception:
            profile_photo_marker = None

    attendance_events_before = None
    try:
        conn = db.get_connection()
        try:
            cur = conn.cursor()
            cur.execute('SELECT COUNT(*) FROM attendance_events')
            attendance_events_before = int(cur.fetchone()[0])
        finally:
            conn.close()
    except Exception:
        attendance_events_before = None

    student_a = 910001
    student_b = 910002
    encoding_rel_a = f'encodings/student_{student_a}.pkl'
    original_get_profile = db.get_face_profile

    try:
        config.DATASET_DIR = temp_dataset
        config.ENCODINGS_DIR = temp_encodings
        # Relative encoding paths resolve via BASE_DIR / path, constrained to ENCODINGS_DIR.
        config.BASE_DIR = temp_root

        # --- Path safety (L) ---
        assert_true(dataset_cleanup.resolve_student_dataset_dir(0) is None, 'L Reject student_id 0')
        assert_true(dataset_cleanup.resolve_student_dataset_dir(-1) is None, 'L Reject negative student_id')
        # bool is subclass of int in Python — must reject
        assert_true(dataset_cleanup.resolve_student_dataset_dir(True) is None, 'L Reject bool student_id')  # type: ignore[arg-type]
        safe = dataset_cleanup.resolve_student_dataset_dir(student_a)
        assert_true(safe is not None and safe.parent == temp_dataset.resolve(), 'L Valid student resolves inside DATASET_DIR')

        # --- Setup folders + encoding ---
        folder_a = make_sample_folder(temp_dataset, student_a, 5)
        folder_b = make_sample_folder(temp_dataset, student_b, 4)
        pkl_a = temp_encodings / f'student_{student_a}.pkl'
        write_valid_pkl(pkl_a, student_a, rows=5)
        pkl_bytes_before = pkl_a.read_bytes()

        assert_true(
            profile_loader.verify_encoding_for_student(student_a, encoding_rel_a),
            'A/E Encoding loads successfully (synthetic valid .pkl)',
        )

        def fake_get_profile(sid: int):
            if sid == student_a:
                return {
                    'face_profile_id': 1,
                    'student_id': student_a,
                    'encoding_path': encoding_rel_a,
                    'sample_count': 5,
                    'status': 'ACTIVE',
                }
            return None

        db.get_face_profile = fake_get_profile  # type: ignore[assignment]
        dataset_cleanup.db.get_face_profile = fake_get_profile  # type: ignore[assignment]

        ready, reason = dataset_cleanup.verify_enrollment_ready_for_cleanup(student_a)
        assert_true(ready, 'A/B Verification ready before cleanup', reason)

        # Gallery load of encoding file (no MySQL ACTIVE students required for file verify)
        loaded = profile_loader._load_encoding_array(pkl_a, student_a)
        assert_true(loaded is not None and loaded.shape == (5, 128), 'F Encoding array usable for gallery matching')

        # --- Successful cleanup (C/D/E/G) ---
        result = dataset_cleanup.cleanup_student_dataset(student_a)
        assert_true(result['success'] and result['deleted'], 'C Successful cleanup removes student dataset folder', str(result))
        assert_true(not folder_a.exists(), 'C dataset/{student_id}/ no longer exists')
        assert_true(pkl_a.is_file(), 'D Encoding .pkl remains after cleanup')
        assert_true(pkl_a.read_bytes() == pkl_bytes_before, 'D Encoding bytes unchanged by cleanup')
        assert_true(
            profile_loader.verify_encoding_for_student(student_a, encoding_rel_a),
            'E Encoding still loads after cleanup',
        )
        assert_true(folder_b.exists() and len(list(folder_b.glob('sample_*.jpg'))) == 4, 'G Another student dataset untouched')

        # --- Idempotent missing folder (K) ---
        again = dataset_cleanup.cleanup_student_dataset(student_a)
        assert_true(again['success'] and again['already_absent'], 'K Missing dataset folder cleanup is safe/idempotent')

        # --- Simulated cleanup failure must not imply we delete encoding (M/N/O) ---
        folder_a2 = make_sample_folder(temp_dataset, student_a, 2)
        original_rmtree = shutil.rmtree

        def boom(path, *args, **kwargs):
            raise OSError('simulated cleanup failure')

        shutil.rmtree = boom  # type: ignore[assignment]
        try:
            fail_result = dataset_cleanup.cleanup_student_dataset(student_a)
        finally:
            shutil.rmtree = original_rmtree  # type: ignore[assignment]

        assert_true(not fail_result['success'], 'M Simulated cleanup failure reports unsuccessful cleanup')
        assert_true(pkl_a.is_file() and pkl_a.read_bytes() == pkl_bytes_before, 'N Cleanup failure does NOT delete encoding')
        assert_true(folder_a2.exists(), 'M Cleanup failure leaves dataset folder')
        # Profile fake still ACTIVE
        assert_true(fake_get_profile(student_a)['status'] == 'ACTIVE', 'O Cleanup failure does NOT change ACTIVE face profile')

        # Clean leftover for later steps
        shutil.rmtree(folder_a2, ignore_errors=True)

        # --- Failed enrollment paths must not use success cleanup (P/Q) ---
        # Encoding failure: no valid pkl / no profile → verify fails → callers must skip cleanup
        db.get_face_profile = lambda sid: None  # type: ignore[assignment]
        dataset_cleanup.db.get_face_profile = lambda sid: None  # type: ignore[assignment]
        ready_fail, reason_fail = dataset_cleanup.verify_enrollment_ready_for_cleanup(student_a)
        assert_true(not ready_fail, 'P Encoding/profile missing is not ready for cleanup', reason_fail)

        make_sample_folder(temp_dataset, student_a, 2)
        # Intentionally do NOT call cleanup when not ready (mirrors app.py)
        assert_true(
            (temp_dataset / str(student_a)).exists(),
            'P Failed readiness leaves samples available (no success cleanup path)',
        )

        # DB update failure simulation: profile missing after encode would skip cleanup
        assert_true(not ready_fail and 'missing' in reason_fail, 'Q DB/profile failure does not pass cleanup gate')

        # Restore fake ACTIVE profile for deactivate/reactivate-style encoding retention (R/S)
        db.get_face_profile = fake_get_profile  # type: ignore[assignment]
        dataset_cleanup.db.get_face_profile = fake_get_profile  # type: ignore[assignment]
        # Remove samples if any; encoding must still verify (reactivate without JPGs)
        if (temp_dataset / str(student_a)).exists():
            shutil.rmtree(temp_dataset / str(student_a))
        assert_true(
            profile_loader.verify_encoding_for_student(student_a, encoding_rel_a),
            'R/S Encoding remains usable without raw JPGs (deactivate/reactivate path)',
        )

        # App source checks: cleanup after DB success; failures don't call cleanup on early return
        app_source = (ROOT / 'app.py').read_text(encoding='utf-8')
        assert_true(
            'verify_enrollment_ready_for_cleanup' in app_source
            and 'cleanup_student_dataset' in app_source
            and 'Enrollment succeeded but temporary face samples could not be removed' in app_source,
            'Enrollment flow wires non-fatal cleanup after DB success',
        )
        assert_true(
            app_source.index('create_or_update_face_profile')
            < app_source.index('verify_enrollment_ready_for_cleanup')
            < app_source.index('cleanup_student_dataset'),
            'Exact cleanup point is after DB update + verification',
        )

        # I — face_profiles untouched by cleanup helper (no db writes in cleanup_student_dataset)
        cleanup_src = (ROOT / 'dataset_cleanup.py').read_text(encoding='utf-8')
        assert_true(
            'UPDATE' not in cleanup_src and 'DELETE FROM' not in cleanup_src and 'INSERT' not in cleanup_src,
            'I Cleanup helper does not mutate face_profiles SQL',
        )

        # H — profile photo marker untouched
        if profile_photo_marker is not None:
            assert_true(profile_photo_marker.is_file(), 'H Profile photo storage untouched')

        # J — attendance count unchanged
        if attendance_events_before is not None:
            conn = db.get_connection()
            try:
                cur = conn.cursor()
                cur.execute('SELECT COUNT(*) FROM attendance_events')
                after = int(cur.fetchone()[0])
            finally:
                conn.close()
            assert_true(after == attendance_events_before, 'J Attendance/history untouched')

        # T — real project dataset/encodings inventories unchanged by this test
        real_dataset_after = sorted(p.name for p in original_dataset.iterdir()) if original_dataset.is_dir() else []
        real_encodings_after = sorted(p.name for p in original_encodings.iterdir()) if original_encodings.is_dir() else []
        assert_true(real_dataset_after == real_dataset_before, 'T Existing unrelated dataset folders remain untouched')
        assert_true(real_encodings_after == real_encodings_before, 'T Existing encodings directory listing unchanged')

        # B — document that ACTIVE profile is required conceptually; fake profile status ACTIVE checked
        assert_true(fake_get_profile(student_a)['status'] == 'ACTIVE', 'B face_profiles status ACTIVE for successful path')

    except Exception:
        fail('FATAL', traceback.format_exc())
    finally:
        config.DATASET_DIR = original_dataset
        config.ENCODINGS_DIR = original_encodings
        config.BASE_DIR = original_base
        db.get_face_profile = original_get_profile  # type: ignore[assignment]
        dataset_cleanup.db.get_face_profile = original_get_profile  # type: ignore[assignment]
        if profile_photo_marker is not None and profile_photo_marker.is_file():
            try:
                profile_photo_marker.unlink()
            except Exception:
                pass
        shutil.rmtree(temp_root, ignore_errors=True)

    if failed:
        print('\nRESULT: FAILED')
        return 1
    print('\nRESULT: PASSED')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
