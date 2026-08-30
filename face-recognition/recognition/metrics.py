"""
In-memory recognition counters for a later evaluation report.

Stores match / unknown counts, distances, and processing times. Does not
write attendance or persist a final report.
"""

from __future__ import annotations

import threading
from collections import deque
from dataclasses import dataclass
from time import time


@dataclass(frozen=True)
class RecognitionEvent:
    timestamp: float
    predicted_label: str
    student_id: int | None
    registration_no: str | None
    distance: float | None
    confidence: float | None
    is_match: bool
    processing_ms: float
    ground_truth: str | None = None  # filled by a later evaluation pass


class RecognitionMetrics:
    def __init__(self, max_events: int = 500):
        self._lock = threading.Lock()
        self.frames_processed = 0
        self.faces_detected = 0
        self.known_matches = 0
        self.unknown_rejections = 0
        self.events: deque[RecognitionEvent] = deque(maxlen=max_events)
        self._proc_times_ms: deque[float] = deque(maxlen=180)
        self._window_started = time()

    def reset(self) -> None:
        with self._lock:
            self.frames_processed = 0
            self.faces_detected = 0
            self.known_matches = 0
            self.unknown_rejections = 0
            self.events.clear()
            self._proc_times_ms.clear()
            self._window_started = time()

    def record_frame(self, results: list, processing_ms: float) -> None:
        with self._lock:
            self.frames_processed += 1
            self.faces_detected += len(results)
            self._proc_times_ms.append(float(processing_ms))

            for match in results:
                event = RecognitionEvent(
                    timestamp=time(),
                    predicted_label=match.label,
                    student_id=match.student_id,
                    registration_no=match.registration_no,
                    distance=match.distance,
                    confidence=match.confidence,
                    is_match=match.is_match,
                    processing_ms=float(processing_ms),
                )
                self.events.append(event)
                if match.is_match:
                    self.known_matches += 1
                else:
                    self.unknown_rejections += 1

    def snapshot(self) -> dict:
        with self._lock:
            times = list(self._proc_times_ms)
            avg_ms = sum(times) / len(times) if times else None
            elapsed = max(time() - self._window_started, 1e-6)
            fps = self.frames_processed / elapsed if self.frames_processed else 0.0
            recent_distances = [
                event.distance for event in list(self.events)[-20:]
                if event.distance is not None
            ]
            return {
                'frames_processed': self.frames_processed,
                'faces_detected': self.faces_detected,
                'known_matches': self.known_matches,
                'unknown_rejections': self.unknown_rejections,
                'avg_processing_ms': round(avg_ms, 2) if avg_ms is not None else None,
                'approximate_fps': round(fps, 2),
                'recent_distances': [round(value, 4) for value in recent_distances],
                'event_buffer_size': len(self.events),
            }
