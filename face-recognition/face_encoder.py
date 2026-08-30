"""
Face embedding generation using the `face_recognition` library (dlib-based 128-d embeddings).

Supports safe re-enrollment by writing a temporary .new.pkl first, validating it,
then atomically promoting it over encodings/student_{id}.pkl only after success.
"""

from __future__ import annotations

import os
import pickle
import shutil
from pathlib import Path

import numpy as np

import config
from recognition.profile_loader import _load_encoding_array


def final_encoding_filename(student_id: int) -> str:
    return f'student_{student_id}.pkl'


def temp_encoding_filename(student_id: int) -> str:
    return f'student_{student_id}.new.pkl'


def backup_encoding_filename(student_id: int) -> str:
    return f'student_{student_id}.bak.pkl'


def final_encoding_path(student_id: int) -> Path:
    return config.ENCODINGS_DIR / final_encoding_filename(student_id)


def temp_encoding_path(student_id: int) -> Path:
    return config.ENCODINGS_DIR / temp_encoding_filename(student_id)


def backup_encoding_path(student_id: int) -> Path:
    return config.ENCODINGS_DIR / backup_encoding_filename(student_id)


def relative_encoding_path(student_id: int) -> str:
    return f'encodings/{final_encoding_filename(student_id)}'


def _write_encoding_payload(path: Path, student_id: int, encodings: list) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    data = {
        'student_id': student_id,
        'encodings': np.array(encodings),
        'count': len(encodings),
    }
    # Write via a sibling temp file then replace for crash-safety of the .new file itself.
    tmp = path.with_suffix(path.suffix + '.partial')
    try:
        with open(tmp, 'wb') as handle:
            pickle.dump(data, handle)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(tmp, path)
    finally:
        if tmp.exists():
            try:
                tmp.unlink()
            except OSError:
                pass


def generate_embeddings(student_id: int, *, temporary: bool = True) -> dict:
    """
    Read saved sample images for a student and produce 128-d face embeddings.

    By default writes encodings/student_{id}.new.pkl so an existing ACTIVE
    encodings/student_{id}.pkl is never overwritten until promote_encoding().

    Returns dict:
        success: bool
        encoding_count: int
        encoding_path: str   (relative final path encodings/student_{id}.pkl)
        temp_path: str       (absolute temp path when temporary=True)
        error: str | None
    """
    sample_dir = config.DATASET_DIR / str(student_id)
    if not sample_dir.is_dir():
        return {
            'success': False,
            'encoding_count': 0,
            'encoding_path': '',
            'temp_path': '',
            'error': f'Sample directory not found: {sample_dir}',
        }

    image_paths = sorted(sample_dir.glob('sample_*.jpg'))
    if not image_paths:
        return {
            'success': False,
            'encoding_count': 0,
            'encoding_path': '',
            'temp_path': '',
            'error': 'No sample images found',
        }

    encodings = []
    import face_recognition

    for img_path in image_paths:
        image = face_recognition.load_image_file(str(img_path))
        face_encs = face_recognition.face_encodings(image)
        if face_encs:
            encodings.append(face_encs[0])

    if not encodings:
        return {
            'success': False,
            'encoding_count': 0,
            'encoding_path': '',
            'temp_path': '',
            'error': 'Could not extract any face embeddings from the samples',
        }

    config.ENCODINGS_DIR.mkdir(parents=True, exist_ok=True)
    target = temp_encoding_path(student_id) if temporary else final_encoding_path(student_id)
    _write_encoding_payload(target, student_id, encodings)

    if _load_encoding_array(target, student_id) is None:
        try:
            target.unlink(missing_ok=True)
        except OSError:
            pass
        return {
            'success': False,
            'encoding_count': 0,
            'encoding_path': '',
            'temp_path': '',
            'error': 'Generated encoding failed validation',
        }

    return {
        'success': True,
        'encoding_count': len(encodings),
        'encoding_path': relative_encoding_path(student_id),
        'temp_path': str(target) if temporary else '',
        'error': None,
    }


def promote_encoding(student_id: int) -> dict:
    """
    Atomically replace encodings/student_{id}.pkl with the validated .new.pkl.

    If a previous final encoding exists, it is copied to .bak.pkl first so a
    later DB failure can restore it. Does not touch face_profiles or datasets.
    """
    temp = temp_encoding_path(student_id)
    final = final_encoding_path(student_id)
    backup = backup_encoding_path(student_id)

    if not temp.is_file():
        return {
            'success': False,
            'encoding_path': relative_encoding_path(student_id),
            'error': 'Temporary encoding file missing',
            'had_previous': final.is_file(),
        }

    if _load_encoding_array(temp, student_id) is None:
        return {
            'success': False,
            'encoding_path': relative_encoding_path(student_id),
            'error': 'Temporary encoding failed validation before promote',
            'had_previous': final.is_file(),
        }

    had_previous = final.is_file()
    try:
        if had_previous:
            shutil.copy2(final, backup)
        elif backup.exists():
            backup.unlink()

        os.replace(temp, final)
    except Exception as exc:
        # Leave temp in place if replace failed; restore final from backup if needed.
        if backup.is_file() and not final.is_file():
            try:
                os.replace(backup, final)
            except Exception:
                pass
        return {
            'success': False,
            'encoding_path': relative_encoding_path(student_id),
            'error': f'Failed to promote encoding: {exc}',
            'had_previous': had_previous,
        }

    return {
        'success': True,
        'encoding_path': relative_encoding_path(student_id),
        'error': None,
        'had_previous': had_previous,
        'backup_path': str(backup) if backup.is_file() else '',
    }


def restore_encoding_backup(student_id: int) -> dict:
    """Restore encodings/student_{id}.pkl from .bak.pkl after a failed DB update."""
    final = final_encoding_path(student_id)
    backup = backup_encoding_path(student_id)
    if not backup.is_file():
        return {'success': False, 'restored': False, 'error': 'No encoding backup to restore'}

    try:
        os.replace(backup, final)
    except Exception as exc:
        return {'success': False, 'restored': False, 'error': str(exc)}

    return {'success': True, 'restored': True, 'error': None}


def clear_encoding_backup(student_id: int) -> None:
    backup = backup_encoding_path(student_id)
    try:
        backup.unlink(missing_ok=True)
    except OSError:
        pass


def discard_temporary_encoding(student_id: int) -> None:
    temp = temp_encoding_path(student_id)
    try:
        temp.unlink(missing_ok=True)
    except OSError:
        pass


def encoding_bytes(student_id: int) -> bytes | None:
    path = final_encoding_path(student_id)
    if not path.is_file():
        return None
    return path.read_bytes()
