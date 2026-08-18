"""
Direct 128-d embedding matching against enrolled student galleries.

Student-level score: mean of the best MATCH_K Euclidean distances between the
live embedding and that student's stored enrollment embeddings. A match is
accepted only when that score is strictly below MATCH_THRESHOLD. The closest
student is never chosen just because a gallery exists.
"""

from __future__ import annotations

from dataclasses import dataclass

import face_recognition
import numpy as np

import config
from recognition.profile_loader import FaceGallery, StudentProfile


@dataclass(frozen=True)
class MatchResult:
    is_match: bool
    student_id: int | None
    registration_no: str | None
    first_name: str | None
    last_name: str | None
    distance: float | None
    confidence: float | None
    compared_students: int
    label: str

    @property
    def full_name(self) -> str | None:
        if self.first_name is None:
            return None
        return f'{self.first_name} {self.last_name}'.strip()


def distance_to_confidence(distance: float, threshold: float) -> float:
    """100 at distance 0, 0 at the threshold. Not written to the database."""
    if threshold <= 0:
        return 0.0
    return max(0.0, min(100.0, (1.0 - (distance / threshold)) * 100.0))


def student_level_distance(live_encoding: np.ndarray, gallery_encodings: np.ndarray, match_k: int) -> float:
    """
    Compare one live embedding with all stored embeddings for one student.

    Uses the mean of the K nearest gallery distances. A single lucky sample
    cannot accept a match, and poor-angle enrollment photos cannot dominate
    the score the way a full average would.
    """
    distances = face_recognition.face_distance(gallery_encodings, live_encoding)
    if distances.size == 0:
        return float('inf')

    k = max(1, min(int(match_k), int(distances.size)))
    if k == 1:
        return float(np.min(distances))

    best = np.partition(distances, k - 1)[:k]
    return float(np.mean(best))


def match_encoding(
    live_encoding: np.ndarray,
    gallery: FaceGallery,
    threshold: float | None = None,
    match_k: int | None = None,
) -> MatchResult:
    threshold = config.MATCH_THRESHOLD if threshold is None else threshold
    match_k = config.MATCH_K if match_k is None else match_k

    best_profile: StudentProfile | None = None
    best_distance = float('inf')

    for profile in gallery.profiles.values():
        distance = student_level_distance(live_encoding, profile.encodings, match_k)
        if distance < best_distance:
            best_distance = distance
            best_profile = profile

    compared = gallery.student_count
    if best_profile is None or not np.isfinite(best_distance):
        return MatchResult(
            is_match=False,
            student_id=None,
            registration_no=None,
            first_name=None,
            last_name=None,
            distance=None,
            confidence=None,
            compared_students=compared,
            label=config.UNKNOWN_LABEL,
        )

    if best_distance >= threshold:
        return MatchResult(
            is_match=False,
            student_id=None,
            registration_no=None,
            first_name=None,
            last_name=None,
            distance=float(best_distance),
            confidence=distance_to_confidence(best_distance, threshold),
            compared_students=compared,
            label=config.UNKNOWN_LABEL,
        )

    return MatchResult(
        is_match=True,
        student_id=best_profile.student_id,
        registration_no=best_profile.registration_no,
        first_name=best_profile.first_name,
        last_name=best_profile.last_name,
        distance=float(best_distance),
        confidence=distance_to_confidence(best_distance, threshold),
        compared_students=compared,
        label=best_profile.full_name,
    )
