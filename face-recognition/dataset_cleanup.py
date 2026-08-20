"""
Safe cleanup of temporary face enrollment sample images under DATASET_DIR.

Raw JPGs are only needed to generate embeddings. After a valid encoding file and
face_profiles row exist, the per-student dataset folder may be removed.
This module never touches encodings/, profile photos, or other students' data.
"""

from __future__ import annotations

import logging
import shutil
from pathlib import Path

import config
import db
from recognition.profile_loader import resolve_encoding_file, verify_encoding_for_student

logger = logging.getLogger(__name__)


def resolve_student_dataset_dir(student_id: int) -> Path | None:
    """
    Resolve dataset/{student_id} under DATASET_DIR.

    Returns None if student_id is invalid or the resolved path would escape DATASET_DIR.
    Does not require the directory to exist.
    """
    if not isinstance(student_id, int) or isinstance(student_id, bool) or student_id <= 0:
        return None

    dataset_root = config.DATASET_DIR.resolve()
    candidate = (dataset_root / str(student_id)).resolve()

    try:
        candidate.relative_to(dataset_root)
    except ValueError:
        return None

    # Reject unexpected nesting (student_id must be a single path segment).
    if candidate.parent != dataset_root:
        return None

    return candidate


def verify_enrollment_ready_for_cleanup(student_id: int) -> tuple[bool, str]:
    """
    Confirm face_profiles + encoding file are valid before deleting raw samples.
    """
    if not isinstance(student_id, int) or isinstance(student_id, bool) or student_id <= 0:
        return False, 'invalid student_id'

    try:
        profile = db.get_face_profile(student_id)
    except Exception as exc:
        logger.warning('Could not load face profile for cleanup verification student_id=%s: %s', student_id, exc)
        return False, 'face profile lookup failed'

    if profile is None:
        return False, 'face_profiles row missing'

    encoding_path = profile.get('encoding_path')
    if not encoding_path or not isinstance(encoding_path, str) or not encoding_path.strip():
        return False, 'encoding_path missing'

    encoding_file = resolve_encoding_file(encoding_path)
    if encoding_file is None:
        return False, 'encoding path invalid'

    if not encoding_file.is_file():
        return False, 'encoding file missing'

    if not verify_encoding_for_student(student_id, encoding_path):
        return False, 'encoding file unreadable or invalid'

    return True, 'ok'


def cleanup_student_dataset(student_id: int) -> dict:
    """
    Delete only dataset/{student_id}/ for a single student.

    Returns:
        success: bool — True when folder removed or already absent
        deleted: bool — True when files/folders were removed
        already_absent: bool
        path: str | None — resolved target path when known
        error: str | None
    """
    target = resolve_student_dataset_dir(student_id)
    if target is None:
        logger.warning('Refusing dataset cleanup for unsafe/invalid student_id=%r', student_id)
        return {
            'success': False,
            'deleted': False,
            'already_absent': False,
            'path': None,
            'error': 'Invalid student_id or path outside DATASET_DIR',
        }

    if not target.exists():
        return {
            'success': True,
            'deleted': False,
            'already_absent': True,
            'path': str(target),
            'error': None,
        }

    if not target.is_dir():
        logger.warning('Dataset cleanup target is not a directory: %s', target)
        return {
            'success': False,
            'deleted': False,
            'already_absent': False,
            'path': str(target),
            'error': 'Dataset target is not a directory',
        }

    try:
        shutil.rmtree(target)
    except Exception as exc:
        logger.warning(
            'Enrollment dataset cleanup failed for student_id=%s path=%s: %s',
            student_id,
            target,
            exc,
        )
        return {
            'success': False,
            'deleted': False,
            'already_absent': False,
            'path': str(target),
            'error': str(exc),
        }

    logger.info('Removed temporary enrollment samples for student_id=%s (%s)', student_id, target)
    return {
        'success': True,
        'deleted': True,
        'already_absent': False,
        'path': str(target),
        'error': None,
    }
