"""
Face embedding generation using the `face_recognition` library (dlib-based 128-d embeddings).

Modular: the classifier (KNN/SVM) will import from here later.
"""

import pickle
from pathlib import Path

import cv2
import face_recognition
import numpy as np

import config


def generate_embeddings(student_id: int) -> dict:
    """
    Read saved sample images for a student and produce 128-d face embeddings.

    Returns dict:
        success: bool
        encoding_count: int
        encoding_path: str   (relative to face-recognition/)
        error: str | None
    """
    sample_dir = config.DATASET_DIR / str(student_id)
    if not sample_dir.is_dir():
        return {'success': False, 'encoding_count': 0, 'encoding_path': '',
                'error': f'Sample directory not found: {sample_dir}'}

    image_paths = sorted(sample_dir.glob('sample_*.jpg'))
    if not image_paths:
        return {'success': False, 'encoding_count': 0, 'encoding_path': '',
                'error': 'No sample images found'}

    encodings = []
    for img_path in image_paths:
        image = face_recognition.load_image_file(str(img_path))
        face_encs = face_recognition.face_encodings(image)
        if face_encs:
            encodings.append(face_encs[0])

    if not encodings:
        return {'success': False, 'encoding_count': 0, 'encoding_path': '',
                'error': 'Could not extract any face embeddings from the samples'}

    config.ENCODINGS_DIR.mkdir(parents=True, exist_ok=True)

    encoding_filename = f'student_{student_id}.pkl'
    encoding_path = config.ENCODINGS_DIR / encoding_filename

    data = {
        'student_id': student_id,
        'encodings': np.array(encodings),
        'count': len(encodings),
    }

    with open(encoding_path, 'wb') as f:
        pickle.dump(data, f)

    relative_path = f'encodings/{encoding_filename}'

    return {
        'success': True,
        'encoding_count': len(encodings),
        'encoding_path': relative_path,
    }
