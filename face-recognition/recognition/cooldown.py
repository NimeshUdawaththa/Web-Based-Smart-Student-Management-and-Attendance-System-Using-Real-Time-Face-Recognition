"""
Per-student recognition cooldown.

Visual identification may continue every processed frame. This cache only
tracks when a student was last accepted as a distinct recognition, so a
later attendance step can avoid duplicate IN/OUT writes. This module does
not touch the database.
"""

from __future__ import annotations

import time


class RecognitionCooldown:
    def __init__(self, seconds: float):
        self.seconds = max(0.0, float(seconds))
        self._last_recognized: dict[int, float] = {}
        self._last_seen: dict[int, float] = {}

    def observe(self, student_id: int, now: float | None = None) -> bool:
        """
        Record a valid visual match.

        Returns True when this match is outside the cooldown window and would
        be eligible for a future attendance event. last_recognized_time is
        updated only when the cooldown allows, so remaining in front of the
        camera does not keep resetting the window.
        """
        now = time.monotonic() if now is None else now
        self._last_seen[student_id] = now
        last = self._last_recognized.get(student_id)
        if last is not None and (now - last) < self.seconds:
            return False
        self._last_recognized[student_id] = now
        return True

    def last_recognized_at(self, student_id: int) -> float | None:
        return self._last_recognized.get(student_id)

    def tracked_count(self) -> int:
        return len(self._last_recognized)

    def snapshot(self) -> dict:
        return {
            'cooldown_seconds': self.seconds,
            'tracked_students': len(self._last_recognized),
        }

    def clear(self) -> None:
        self._last_recognized.clear()
        self._last_seen.clear()
