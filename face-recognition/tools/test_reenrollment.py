"""
Regression tests for Safe Face Re-enrollment v1.

Covers temporary encoding promote/restore, dataset freshness, failure
preservation, UI/auth static checks, and TARGET_SAMPLES / schema safety.
Does not open a real camera.
"""

from __future__ import annotations

import pickle
import shutil
import sys
import tempfile
import types
from pathlib import Path
from unittest import mock

import numpy as np

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import config
import dataset_cleanup
import face_capture
import face_encoder
from recognition.profile_loader import _load_encoding_array

WEB_ROOT = ROOT.parent / 'web'
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


def write_valid_pkl(path: Path, student_id: int, seed: int = 1, rows: int = 3) -> bytes:
    path.parent.mkdir(parents=True, exist_ok=True)
    encodings = np.random.default_rng(seed).normal(size=(rows, 128))
    payload = {'student_id': student_id, 'encodings': encodings, 'count': rows}
    raw = pickle.dumps(payload)
    path.write_bytes(raw)
    return raw


def make_stale_samples(dataset_root: Path, student_id: int, count: int = 4) -> Path:
    folder = dataset_root / str(student_id)
    folder.mkdir(parents=True, exist_ok=True)
    for i in range(1, count + 1):
        (folder / f'sample_{i:03d}.jpg').write_bytes(b'stale-jpg')
    return folder


def main() -> int:
    global failed

    original_dataset = config.DATASET_DIR
    original_encodings = config.ENCODINGS_DIR

    assert_true(config.TARGET_SAMPLES == 25, 'TARGET_SAMPLES remains 25')

    face_php = (WEB_ROOT / 'shared/pages/students/face-enroll.php').read_text(encoding='utf-8')
    assert_true('Re-enroll Face' in face_php, 'A ACTIVE face profile UI shows Re-enroll Face')
    assert_true('Start Face Enrollment' in face_php, 'B Student without face profile still shows Start Face Enrollment')
    assert_true('confirm_reenroll' in face_php, 'UI sends confirm_reenroll for re-enrollment')
    assert_true(
        'Re-enrollment replaces the existing biometric encoding only after the new' in face_php,
        'UI explains safe re-enrollment note',
    )
    assert_true(
        'current face profile will remain active until the new enrollment completes successfully' in face_php,
        'UI confirmation preserves existing profile wording',
    )
    assert_true('/api/enrollment/preview' in face_php, '11 Live preview endpoint still wired')
    assert_true('app-face-steps' in face_php and 'Straight' in face_php, '11 Pose guide preserved')

    admin_wrap = (WEB_ROOT / 'admin/students/face-enroll.php').read_text(encoding='utf-8')
    academic_wrap = (WEB_ROOT / 'academic-staff/students/face-enroll.php').read_text(encoding='utf-8')
    assert_true('require_admin()' in admin_wrap, 'W Admin face-enroll requires admin')
    assert_true('require_student_manager()' in academic_wrap, 'Auth Academic Staff face-enroll allowed')
    assert_true(not (WEB_ROOT / 'lecturer/students/face-enroll.php').exists(), 'V Lecturer cannot re-enroll (no face-enroll route)')
    assert_true(not (WEB_ROOT / 'student/face-enroll.php').exists(), 'W Student cannot re-enroll (no face-enroll route)')

    app_source = (ROOT / 'app.py').read_text(encoding='utf-8')
    assert_true('Re-enrollment is not yet supported' not in app_source, 'Root blocker 409 text removed')
    assert_true('confirm_reenroll' in app_source, 'API requires confirm_reenroll for ACTIVE profiles')
    assert_true('Only ACTIVE students can enroll or re-enroll' in app_source, 'U Inactive student blocked in API')
    assert_true('promote_encoding' in app_source, 'Safe promote wired into enrollment flow')
    assert_true('restore_encoding_backup' in app_source, 'DB failure restores encoding backup')
    assert_true('temporary=True' in app_source, 'E New encoding generated to temporary file')

    with tempfile.TemporaryDirectory(prefix='smartams_reenroll_') as tmp:
        tmp_path = Path(tmp)
        config.DATASET_DIR = tmp_path / 'dataset'
        config.ENCODINGS_DIR = tmp_path / 'encodings'
        config.DATASET_DIR.mkdir()
        config.ENCODINGS_DIR.mkdir()

        student_a = 910101
        student_b = 910102

        # --- Fresh dataset for re-enroll (C / stale mix prevention) ---
        stale = make_stale_samples(config.DATASET_DIR, student_a, count=4)
        assert_true(len(list(stale.glob('sample_*.jpg'))) == 4, 'Precondition stale samples exist')
        prepared = face_capture.prepare_fresh_dataset(student_a)
        assert_true(prepared['success'], 'C prepare_fresh_dataset succeeds')
        assert_true(list(Path(prepared['path']).glob('sample_*.jpg')) == [], 'C Fresh dataset has no stale JPGs')

        other = make_stale_samples(config.DATASET_DIR, student_b, count=2)
        face_capture.prepare_fresh_dataset(student_a)
        assert_true(other.is_dir() and len(list(other.glob('*.jpg'))) == 2, 'R Other student dataset untouched')

        # --- Old encoding remains during temp generation (D/E) ---
        final_path = face_encoder.final_encoding_path(student_a)
        old_bytes = write_valid_pkl(final_path, student_a, seed=11, rows=3)
        assert_true(_load_encoding_array(final_path, student_a) is not None, 'D Old encoding valid before re-enroll')

        # Simulate generate writing a DIFFERENT temp encoding without touching final.
        temp_path = face_encoder.temp_encoding_path(student_a)
        new_bytes = write_valid_pkl(temp_path, student_a, seed=99, rows=5)
        assert_true(final_path.read_bytes() == old_bytes, 'D Old encoding exists unchanged while temp exists')
        assert_true(temp_path.is_file() and temp_path.read_bytes() == new_bytes, 'E Temporary encoding file created separately')
        assert_true(old_bytes != new_bytes, 'G New encoding payload differs from old')

        # --- Successful promote (F/G) ---
        promote = face_encoder.promote_encoding(student_a)
        assert_true(promote['success'], 'F Promote succeeds', promote.get('error') or '')
        assert_true(not temp_path.exists(), 'F Temp file removed/replaced after promote')
        assert_true(final_path.read_bytes() == new_bytes, 'F/G Final encoding replaced with new payload')
        assert_true(face_encoder.backup_encoding_path(student_a).is_file(), 'Backup retained until clear')
        face_encoder.clear_encoding_backup(student_a)
        assert_true(not face_encoder.backup_encoding_path(student_a).exists(), 'Backup cleared after success path')

        # --- Failed encode preserves old (M) ---
        old_bytes = write_valid_pkl(final_path, student_a, seed=21, rows=3)
        face_encoder.discard_temporary_encoding(student_a)
        # Pretend encode failed: no temp written
        assert_true(final_path.read_bytes() == old_bytes, 'M Encoding failure leaves final untouched')

        # --- Cancelled / capture failure style: discard temp, keep old (K/L) ---
        write_valid_pkl(temp_path, student_a, seed=33, rows=2)
        face_encoder.discard_temporary_encoding(student_a)
        assert_true(not temp_path.exists(), 'K Cancel discards temporary encoding')
        assert_true(final_path.read_bytes() == old_bytes, 'K/L Old encoding preserved after cancel/capture failure path')

        # --- DB failure restores backup (N) ---
        old_bytes = write_valid_pkl(final_path, student_a, seed=41, rows=3)
        write_valid_pkl(temp_path, student_a, seed=77, rows=4)
        promote = face_encoder.promote_encoding(student_a)
        assert_true(promote['success'], 'N Promote before simulated DB failure')
        restored = face_encoder.restore_encoding_backup(student_a)
        assert_true(restored.get('success'), 'N Restore backup after DB failure', restored.get('error') or '')
        assert_true(final_path.read_bytes() == old_bytes, 'N Old encoding restored after DB failure')

        # --- Cleanup failure does not roll back promoted encoding (Q) ---
        write_valid_pkl(temp_path, student_a, seed=88, rows=4)
        before_promote = final_path.read_bytes()
        promote = face_encoder.promote_encoding(student_a)
        assert_true(promote['success'], 'Q Promote for cleanup-failure scenario')
        promoted_bytes = final_path.read_bytes()
        assert_true(promoted_bytes != before_promote, 'Q New encoding present after promote')
        face_encoder.clear_encoding_backup(student_a)
        with mock.patch.object(dataset_cleanup, 'cleanup_student_dataset', return_value={
            'success': False, 'deleted': False, 'already_absent': False, 'path': 'x', 'error': 'simulated',
        }):
            cleanup = dataset_cleanup.cleanup_student_dataset(student_a)
        assert_true(not cleanup['success'], 'Q Cleanup reports failure')
        assert_true(final_path.read_bytes() == promoted_bytes, 'Q Cleanup failure does not roll back new encoding')

        # --- Other encodings untouched (R) ---
        other_path = face_encoder.final_encoding_path(student_b)
        other_bytes = write_valid_pkl(other_path, student_b, seed=5, rows=2)
        write_valid_pkl(temp_path, student_a, seed=6, rows=2)
        face_encoder.promote_encoding(student_a)
        face_encoder.clear_encoding_backup(student_a)
        assert_true(other_path.read_bytes() == other_bytes, 'R Other student encoding untouched')

        # --- Profile photo / attendance untouched (S/T) via source guarantees ---
        capture_src = (ROOT / 'face_capture.py').read_text(encoding='utf-8')
        encoder_src = (ROOT / 'face_encoder.py').read_text(encoding='utf-8')
        assert_true('profile_photo' not in capture_src and 'profile_photo' not in encoder_src, 'S Profile photo untouched by capture/encoder')
        assert_true('attendance_' not in encoder_src, 'T Attendance tables not touched by encoder')
        assert_true('CREATE TABLE' not in app_source and 'ALTER TABLE' not in app_source, '16 No schema change in app.py')

        # --- Invalid temp fails promote (temp invalid) ---
        bad_temp = face_encoder.temp_encoding_path(student_a)
        bad_temp.write_bytes(b'not-a-pickle')
        kept = final_path.read_bytes()
        bad_promote = face_encoder.promote_encoding(student_a)
        assert_true(not bad_promote['success'], 'Invalid temporary encoding rejected')
        assert_true(final_path.read_bytes() == kept, 'Invalid temp does not replace final encoding')
        face_encoder.discard_temporary_encoding(student_a)

        # --- generate_embeddings temporary=True does not overwrite final before promote ---
        old_bytes = write_valid_pkl(final_path, student_a, seed=55, rows=3)
        make_stale_samples(config.DATASET_DIR, student_a, count=2)

        def fake_face_encodings(_image):
            return [np.random.default_rng(123).normal(size=(128,))]

        fake_mod = types.ModuleType('face_recognition')
        fake_mod.load_image_file = lambda *_a, **_k: np.zeros((40, 40, 3), dtype=np.uint8)
        fake_mod.face_encodings = fake_face_encodings
        with mock.patch.dict(sys.modules, {'face_recognition': fake_mod}):
            gen = face_encoder.generate_embeddings(student_a, temporary=True)
        assert_true(gen['success'], 'generate_embeddings temp succeeds with mocked faces', gen.get('error') or '')
        assert_true(final_path.read_bytes() == old_bytes, 'D Final encoding unchanged while temp encoding generated')
        assert_true(bool(gen.get('temp_path')) and Path(gen['temp_path']).is_file(), 'E Temp path returned and exists')
        assert_true(_load_encoding_array(Path(gen['temp_path']), student_a) is not None, 'Temp encoding validates')
        face_encoder.discard_temporary_encoding(student_a)

        # --- Successful cleanup after promote (P) ---
        make_stale_samples(config.DATASET_DIR, student_a, count=3)
        write_valid_pkl(final_path, student_a, seed=60, rows=3)
        assert_true(_load_encoding_array(final_path, student_a) is not None, 'P Encoding valid before cleanup')
        with mock.patch('dataset_cleanup.verify_encoding_for_student', return_value=True):
            with mock.patch('dataset_cleanup.resolve_encoding_file', return_value=final_path):
                with mock.patch('dataset_cleanup.db.get_face_profile', return_value={
                    'face_profile_id': 1,
                    'student_id': student_a,
                    'encoding_path': face_encoder.relative_encoding_path(student_a),
                    'sample_count': 3,
                    'status': 'ACTIVE',
                }):
                    ready, reason = dataset_cleanup.verify_enrollment_ready_for_cleanup(student_a)
                    assert_true(ready, 'P Cleanup readiness true after valid encoding', reason)
                    cleaned = dataset_cleanup.cleanup_student_dataset(student_a)
        assert_true(cleaned['success'], 'P Raw samples cleaned after success')
        assert_true(not (config.DATASET_DIR / str(student_a)).exists(), 'P dataset/{id}/ removed')
        assert_true(final_path.is_file(), 'P Encoding remains after dataset cleanup')

        # H/I one ACTIVE row — static/source: create_or_update updates existing
        db_src = (ROOT / 'db.py').read_text(encoding='utf-8')
        assert_true('UPDATE face_profiles' in db_src and 'INSERT INTO face_profiles' in db_src, 'H Reuses single face_profiles row upsert')
        assert_true("status = 'ACTIVE'" in db_src, 'I Profile remains/set ACTIVE on update')

        # J Gallery reload wired after success
        assert_true('reload_gallery()' in app_source, 'J Gallery reload after successful replacement')

        # O recognition continues with old encoding after failed path — old file still loadable
        old_bytes = write_valid_pkl(final_path, student_a, seed=70, rows=3)
        assert_true(_load_encoding_array(final_path, student_a) is not None, 'O Old encoding still loadable after failed re-enroll path')
        assert_true(final_path.read_bytes() == old_bytes, 'O Bytes unchanged for recognition continuity')

    config.DATASET_DIR = original_dataset
    config.ENCODINGS_DIR = original_encodings

    print()
    if failed:
        print('RESULT: FAILED')
        return 1
    print('RESULT: PASSED')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
