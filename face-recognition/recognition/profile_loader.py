"""
Load enrolled face embeddings for recognition.

Only ACTIVE face_profiles for ACTIVE students are considered. Encoding files must live under
the configured encodings directory. Corrupt or missing files are skipped
so the rest of the gallery can still be used.
"""

from __future__ import annotations

import logging
import pickle
import threading
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

import config
import db

logger = logging.getLogger(__name__)

_EXPECTED_DIM = 128


@dataclass
class StudentProfile:
    student_id: int
    registration_no: str
    first_name: str
    last_name: str
    encodings: np.ndarray  # shape (N, 128)
    sample_count: int
    embedding_count: int

    @property
    def full_name(self) -> str:
        return f'{self.first_name} {self.last_name}'.strip()


@dataclass
class SkippedProfile:
    student_id: int
    reason: str


@dataclass
class FaceGallery:
    profiles: dict[int, StudentProfile] = field(default_factory=dict)
    skipped: list[SkippedProfile] = field(default_factory=list)
    loaded_at: datetime | None = None

    def reload(self) -> dict:
        """Replace the in-memory gallery from MySQL + encoding files."""
        profiles: dict[int, StudentProfile] = {}
        skipped: list[SkippedProfile] = []

        try:
            rows = db.list_active_face_profiles()
        except Exception:
            logger.exception('Failed to query active face profiles')
            raise

        for row in rows:
            student_id = int(row['student_id'])
            encoding_file = resolve_encoding_file(row.get('encoding_path') or '')
            if encoding_file is None:
                skipped.append(SkippedProfile(student_id, 'invalid encoding path'))
                logger.warning('Skipping student_id=%s: encoding path is outside the encodings directory or invalid', student_id)
                continue

            if not encoding_file.is_file():
                skipped.append(SkippedProfile(student_id, 'encoding file missing'))
                logger.warning('Skipping student_id=%s: encoding file does not exist', student_id)
                continue

            loaded = _load_encoding_array(encoding_file, student_id)
            if loaded is None:
                skipped.append(SkippedProfile(student_id, 'encoding file unreadable or invalid'))
                continue

            profiles[student_id] = StudentProfile(
                student_id=student_id,
                registration_no=str(row['registration_no']),
                first_name=str(row['first_name']),
                last_name=str(row['last_name']),
                encodings=loaded,
                sample_count=int(row['sample_count'] or 0),
                embedding_count=int(loaded.shape[0]),
            )

        self.profiles = profiles
        self.skipped = skipped
        self.loaded_at = datetime.now(timezone.utc)
        logger.info(
            'Loaded %s enrolled face profile(s); skipped %s',
            len(profiles),
            len(skipped),
        )
        return {
            'loaded': len(profiles),
            'skipped': len(skipped),
            'loaded_at': self.loaded_at.isoformat(),
        }

    def get(self, student_id: int) -> StudentProfile | None:
        return self.profiles.get(student_id)

    @property
    def student_count(self) -> int:
        return len(self.profiles)


_gallery: FaceGallery | None = None
_gallery_lock = threading.Lock()


def get_gallery() -> FaceGallery:
    """Process-wide gallery. Embeddings are loaded once, then reused."""
    global _gallery
    with _gallery_lock:
        if _gallery is None:
            _gallery = FaceGallery()
        if _gallery.loaded_at is None:
            _gallery.reload()
        return _gallery


def reload_gallery() -> dict:
    gallery = get_gallery()
    with _gallery_lock:
        return gallery.reload()


def resolve_encoding_file(encoding_path: str) -> Path | None:
    """
    Resolve a DB encoding_path to a real file under ENCODINGS_DIR.
    Rejects absolute paths outside that directory and any parent traversal.
    """
    if not encoding_path or not isinstance(encoding_path, str):
        return None

    raw = encoding_path.strip().replace('\\', '/')
    if not raw:
        return None

    path = Path(raw)
    if '..' in path.parts:
        return None

    encodings_root = config.ENCODINGS_DIR.resolve()
    if path.is_absolute():
        candidate = path.resolve()
    else:
        candidate = (config.BASE_DIR / path).resolve()

    try:
        candidate.relative_to(encodings_root)
    except ValueError:
        return None

    if candidate.suffix.lower() != '.pkl':
        return None

    return candidate


def _load_encoding_array(path: Path, expected_student_id: int) -> np.ndarray | None:
    try:
        with open(path, 'rb') as handle:
            data = pickle.load(handle)
    except Exception as exc:
        logger.warning(
            'Skipping student_id=%s: cannot read encoding file (%s)',
            expected_student_id,
            type(exc).__name__,
        )
        return None

    if not isinstance(data, dict):
        logger.warning('Skipping student_id=%s: encoding payload is not a dict', expected_student_id)
        return None

    pickled_id = data.get('student_id')
    if pickled_id is not None and int(pickled_id) != expected_student_id:
        logger.warning(
            'Skipping student_id=%s: encoding file student_id does not match the database row',
            expected_student_id,
        )
        return None

    encodings = data.get('encodings')
    try:
        array = np.asarray(encodings, dtype=np.float64)
    except Exception:
        logger.warning('Skipping student_id=%s: encodings cannot be converted to an array', expected_student_id)
        return None

    if array.ndim == 1 and array.shape[0] == _EXPECTED_DIM:
        array = array.reshape(1, _EXPECTED_DIM)

    if array.ndim != 2 or array.shape[1] != _EXPECTED_DIM or array.shape[0] == 0:
        logger.warning(
            'Skipping student_id=%s: expected shape (N, %s), got %s',
            expected_student_id,
            _EXPECTED_DIM,
            getattr(array, 'shape', None),
        )
        return None

    if not np.isfinite(array).all():
        logger.warning('Skipping student_id=%s: encoding contains non-finite values', expected_student_id)
        return None

    return array


def verify_encoding_for_student(student_id: int, encoding_path: str) -> bool:
    """
    True when encoding_path resolves under ENCODINGS_DIR and loads as a valid
    embedding payload for this student_id. Used before deleting raw samples.
    """
    encoding_file = resolve_encoding_file(encoding_path)
    if encoding_file is None or not encoding_file.is_file():
        return False
    return _load_encoding_array(encoding_file, student_id) is not None
