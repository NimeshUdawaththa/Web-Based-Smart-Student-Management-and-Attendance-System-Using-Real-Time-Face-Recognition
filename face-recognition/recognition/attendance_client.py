"""
Ask PHP to resolve the eligible IN_PROGRESS session and record an IN event.

Does not send embeddings or images. UNKNOWN faces must never call this module.
"""

from __future__ import annotations

import json
import logging
import subprocess
from dataclasses import dataclass
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

import config

logger = logging.getLogger(__name__)

_CHECKIN_SCRIPT = config.PROJECT_ROOT / 'web' / 'api' / 'academic' / 'recognition-check-in.php'


@dataclass(frozen=True)
class AttendanceDecision:
    code: str
    module_code: str | None = None
    recognized_at: str | None = None
    session_id: int | None = None
    event_id: int | None = None

    @property
    def overlay_title(self) -> str:
        return {
            'UNKNOWN': 'UNKNOWN',
            'CHECKED_IN': 'CHECKED IN',
            'ALREADY_CHECKED_IN': 'ALREADY CHECKED IN',
            'NOT_ELIGIBLE': 'NOT ELIGIBLE',
            'NO_ACTIVE_SESSION': 'NO ACTIVE SESSION',
            'AMBIGUOUS_ACTIVE_SESSIONS': 'NO ACTIVE SESSION',
            'INVALID_STUDENT': 'NOT ELIGIBLE',
            'ERROR': 'RECOGNIZED',
        }.get(self.code, 'RECOGNIZED')


def unknown_decision() -> AttendanceDecision:
    return AttendanceDecision(code='UNKNOWN')


def submit_recognized_check_in(student_id: int, confidence: float | None) -> AttendanceDecision:
    payload = {
        'student_id': int(student_id),
        'confidence': round(float(confidence) if confidence is not None else 0.0, 2),
        'camera_id': config.ATTENDANCE_CAMERA_ID,
    }

    try:
        if config.ATTENDANCE_BRIDGE == 'http':
            data = _post_http(payload)
        else:
            data = _run_php_cli(payload)
    except Exception:
        logger.exception('Attendance bridge failed for student_id=%s', student_id)
        return AttendanceDecision(code='ERROR')

    code = str(data.get('result') or 'ERROR')
    recognized_at = data.get('recognized_at')
    if isinstance(recognized_at, str) and len(recognized_at) >= 19:
        recognized_at = recognized_at[11:19]
    elif not isinstance(recognized_at, str):
        recognized_at = None

    session_id = data.get('session_id')
    event_id = data.get('event_id')
    return AttendanceDecision(
        code=code,
        module_code=data.get('module_code') if isinstance(data.get('module_code'), str) else None,
        recognized_at=recognized_at,
        session_id=int(session_id) if isinstance(session_id, int) or (isinstance(session_id, str) and session_id.isdigit()) else None,
        event_id=int(event_id) if isinstance(event_id, int) or (isinstance(event_id, str) and str(event_id).isdigit()) else None,
    )


def _run_php_cli(payload: dict) -> dict:
    php = Path(config.PHP_CLI_PATH)
    if not php.is_file():
        raise FileNotFoundError(f'PHP CLI not found: {php}')
    if not _CHECKIN_SCRIPT.is_file():
        raise FileNotFoundError(f'Check-in script not found: {_CHECKIN_SCRIPT}')

    body = json.dumps(payload)
    completed = subprocess.run(
        [str(php), str(_CHECKIN_SCRIPT), body],
        capture_output=True,
        text=True,
        timeout=config.ATTENDANCE_TIMEOUT_SECONDS,
        check=False,
        cwd=str(config.PROJECT_ROOT),
    )
    if completed.returncode != 0:
        logger.warning(
            'PHP check-in CLI exited %s: %s',
            completed.returncode,
            (completed.stderr or completed.stdout)[:300],
        )
    return _parse_json(completed.stdout)


def _post_http(payload: dict) -> dict:
    if not config.INTERNAL_API_TOKEN:
        raise RuntimeError('INTERNAL_API_TOKEN is not configured')

    url = f'{config.PHP_INTERNAL_URL}/api/academic/recognition-check-in.php'
    request = Request(
        url,
        data=json.dumps(payload).encode('utf-8'),
        method='POST',
        headers={
            'Content-Type': 'application/json',
            'X-Internal-Token': config.INTERNAL_API_TOKEN,
        },
    )
    try:
        with urlopen(request, timeout=config.ATTENDANCE_TIMEOUT_SECONDS) as response:
            raw = response.read().decode('utf-8')
    except HTTPError as exc:
        raw = exc.read().decode('utf-8', errors='replace')
        logger.warning('PHP check-in HTTP %s', exc.code)
        if not raw:
            return {'result': 'ERROR'}
    except URLError as exc:
        logger.warning('PHP check-in HTTP unavailable: %s', exc.reason)
        raise

    return _parse_json(raw)


def _parse_json(raw: str) -> dict:
    try:
        data = json.loads(raw)
    except json.JSONDecodeError:
        logger.warning('PHP check-in returned non-JSON')
        return {'result': 'ERROR'}
    return data if isinstance(data, dict) else {'result': 'ERROR'}
