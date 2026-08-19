"""
Probe OpenCV camera indexes 0, 1, and 2.

Does not run face matching or attendance. Opens one index at a time,
reads a single frame if possible, then releases the camera.

Usage (from the face-recognition directory, venv active):

    python tools/test_cameras.py
"""

from __future__ import annotations

import sys
from pathlib import Path

import cv2

_ROOT = Path(__file__).resolve().parent.parent
if str(_ROOT) not in sys.path:
    sys.path.insert(0, str(_ROOT))


def probe_index(index: int) -> dict:
    cap = None
    result: dict = {
        'index': index,
        'opened': False,
        'frame_ok': False,
        'width': None,
        'height': None,
        'error': None,
    }
    try:
        print(f'Opening camera index: {index}', flush=True)
        cap = cv2.VideoCapture(index)
        if cap is None or not cap.isOpened():
            result['error'] = f'Could not open camera index {index}'
            print(result['error'], flush=True)
            return result

        result['opened'] = True
        ok, frame = cap.read()
        result['frame_ok'] = bool(ok and frame is not None)
        if result['frame_ok']:
            height, width = frame.shape[:2]
            result['width'] = int(width)
            result['height'] = int(height)
            print(
                f'Camera index {index}: OPEN  frame={width}x{height}',
                flush=True,
            )
        else:
            result['error'] = f'Opened index {index} but could not read a frame'
            print(result['error'], flush=True)
    except Exception as exc:
        result['error'] = f'Could not open camera index {index}'
        print(f'{result["error"]} ({exc})', flush=True)
    finally:
        if cap is not None:
            cap.release()
        cv2.destroyAllWindows()

    return result


def main() -> int:
    print('Testing camera indexes 0, 1, 2 (one at a time).', flush=True)
    print('This does not start face recognition or attendance.\n', flush=True)

    results = [probe_index(index) for index in (0, 1, 2)]
    usable = [row['index'] for row in results if row['opened'] and row['frame_ok']]

    print('\nSummary', flush=True)
    print('--------', flush=True)
    for row in results:
        if row['opened'] and row['frame_ok']:
            status = f'usable  {row["width"]}x{row["height"]}'
        elif row['opened']:
            status = 'opened but no frame'
        else:
            status = 'not available'
        print(f'  FACE_CAMERA_INDEX={row["index"]}  {status}', flush=True)

    if usable:
        print(
            '\nSet FACE_CAMERA_INDEX in the project .env to the USB webcam index, then restart recognition.',
            flush=True,
        )
        return 0

    print('\nNo camera index returned a frame.', flush=True)
    return 1


if __name__ == '__main__':
    raise SystemExit(main())
