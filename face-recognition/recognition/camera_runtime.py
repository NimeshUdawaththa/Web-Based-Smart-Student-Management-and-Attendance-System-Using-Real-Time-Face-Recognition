"""
Local camera-process state for SmartAMS.

PHP and Python share JSON files under face-recognition/runtime/.
This is machine-local process state, not academic attendance data.
"""

from __future__ import annotations

import ctypes
import json
import logging
import os
import tempfile
from datetime import datetime
from pathlib import Path
from typing import Any

logger = logging.getLogger(__name__)

_ROOT = Path(__file__).resolve().parent.parent
RUNTIME_DIR = _ROOT / 'runtime'
STATE_PATH = RUNTIME_DIR / 'camera_state.json'
CONTROL_PATH = RUNTIME_DIR / 'camera_control.json'
LOG_PATH = RUNTIME_DIR / 'camera.log'

_MUTEX_NAME = 'Local\\SmartAMS_FaceCamera'
_ERROR_ALREADY_EXISTS = 183
_mutex_handle = None

ALLOWED_MODES = {'ENTRY', 'EXIT'}
ALLOWED_STATUSES = {'OFFLINE', 'STARTING', 'ONLINE', 'STOPPING', 'ERROR'}


def ensure_runtime_dir() -> Path:
    RUNTIME_DIR.mkdir(parents=True, exist_ok=True)
    return RUNTIME_DIR


def _now_iso() -> str:
    return datetime.now().astimezone().replace(microsecond=0).isoformat(sep=' ')


def _atomic_write(path: Path, payload: dict[str, Any]) -> None:
    ensure_runtime_dir()
    encoded = json.dumps(payload, indent=2, ensure_ascii=True)
    fd, tmp_name = tempfile.mkstemp(prefix=path.name + '.', suffix='.tmp', dir=str(RUNTIME_DIR))
    try:
        with os.fdopen(fd, 'w', encoding='utf-8', newline='\n') as handle:
            handle.write(encoded)
            handle.write('\n')
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(tmp_name, path)
    except Exception:
        try:
            os.unlink(tmp_name)
        except OSError:
            pass
        raise


def _read_json(path: Path) -> dict[str, Any]:
    if not path.is_file():
        return {}
    try:
        raw = path.read_text(encoding='utf-8')
        data = json.loads(raw) if raw.strip() else {}
        return data if isinstance(data, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


def read_state() -> dict[str, Any]:
    return _read_json(STATE_PATH)


def read_control() -> dict[str, Any]:
    return _read_json(CONTROL_PATH)


def write_state(patch: dict[str, Any]) -> dict[str, Any]:
    state = read_state()
    state.update(patch)
    if 'pid' in state and state['pid'] is not None:
        try:
            state['pid'] = int(state['pid'])
        except (TypeError, ValueError):
            state['pid'] = None
    _atomic_write(STATE_PATH, state)
    return state


def write_control(patch: dict[str, Any]) -> dict[str, Any]:
    control = read_control()
    control.update(patch)
    mode = str(control.get('mode') or 'ENTRY').strip().upper()
    control['mode'] = mode if mode in ALLOWED_MODES else 'ENTRY'
    control['stop_requested'] = bool(control.get('stop_requested'))
    control['updated_at'] = _now_iso()
    _atomic_write(CONTROL_PATH, control)
    return control


def camera_mode() -> str:
    mode = str(read_control().get('mode') or '').strip().upper()
    if mode in ALLOWED_MODES:
        return mode
    from config import ATTENDANCE_CAMERA_MODE
    fallback = str(ATTENDANCE_CAMERA_MODE or 'ENTRY').strip().upper()
    return fallback if fallback in ALLOWED_MODES else 'ENTRY'


def set_camera_mode(mode: str) -> str:
    value = str(mode).strip().upper()
    if value not in ALLOWED_MODES:
        value = 'ENTRY'
    write_control({'mode': value, 'stop_requested': bool(read_control().get('stop_requested'))})
    return value


def stop_requested() -> bool:
    return bool(read_control().get('stop_requested'))


def clear_stop_request() -> None:
    write_control({'mode': camera_mode(), 'stop_requested': False})


def try_acquire_camera_mutex() -> bool:
    global _mutex_handle
    if os.name != 'nt':
        return True
    if _mutex_handle:
        return True
    kernel32 = ctypes.windll.kernel32
    handle = kernel32.CreateMutexW(None, True, _MUTEX_NAME)
    if not handle:
        return False
    if kernel32.GetLastError() == _ERROR_ALREADY_EXISTS:
        kernel32.CloseHandle(handle)
        return False
    _mutex_handle = handle
    return True


def release_camera_mutex() -> None:
    global _mutex_handle
    if not _mutex_handle:
        return
    try:
        ctypes.windll.kernel32.ReleaseMutex(_mutex_handle)
        ctypes.windll.kernel32.CloseHandle(_mutex_handle)
    except Exception:
        pass
    _mutex_handle = None


def configure_file_logging() -> None:
    ensure_runtime_dir()
    root = logging.getLogger()
    for existing in root.handlers:
        if getattr(existing, '_smartams_camera_log', False):
            return
    handler = logging.FileHandler(LOG_PATH, encoding='utf-8')
    handler.setFormatter(logging.Formatter('%(asctime)s [%(levelname)s] %(name)s: %(message)s'))
    handler._smartams_camera_log = True  # type: ignore[attr-defined]
    root.addHandler(handler)


def mark_starting(pid: int) -> None:
    write_state({
        'status': 'STARTING',
        'pid': pid,
        'last_error': None,
        'heartbeat_at': _now_iso(),
    })


def mark_online(pid: int, mode: str) -> None:
    write_state({
        'status': 'ONLINE',
        'pid': pid,
        'mode': mode if mode in ALLOWED_MODES else camera_mode(),
        'last_started': _now_iso(),
        'last_error': None,
        'heartbeat_at': _now_iso(),
    })
    write_control({'mode': mode if mode in ALLOWED_MODES else camera_mode(), 'stop_requested': False})


def mark_stopping(pid: int | None = None) -> None:
    patch: dict[str, Any] = {
        'status': 'STOPPING',
        'heartbeat_at': _now_iso(),
    }
    if pid is not None:
        patch['pid'] = pid
    write_state(patch)


def mark_offline(error: str | None = None) -> None:
    state = read_state()
    write_state({
        'status': 'ERROR' if error else 'OFFLINE',
        'pid': None,
        'last_stopped': _now_iso(),
        'last_error': error,
        'heartbeat_at': _now_iso(),
        'mode': state.get('mode') or camera_mode(),
    })


def mark_error(message: str, pid: int | None = None) -> None:
    write_state({
        'status': 'ERROR',
        'pid': pid,
        'last_error': message,
        'last_stopped': _now_iso(),
        'heartbeat_at': _now_iso(),
    })


def heartbeat(pid: int, mode: str) -> None:
    write_state({
        'status': 'ONLINE',
        'pid': pid,
        'mode': mode if mode in ALLOWED_MODES else camera_mode(),
        'heartbeat_at': _now_iso(),
        'last_error': None,
    })


def append_log(message: str) -> None:
    ensure_runtime_dir()
    line = f'{_now_iso()} {message}\n'
    with LOG_PATH.open('a', encoding='utf-8') as handle:
        handle.write(line)
