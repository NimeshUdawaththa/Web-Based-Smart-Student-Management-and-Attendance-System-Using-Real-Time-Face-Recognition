"""
Configuration for the Face Recognition service.
Loads values from the project .env file (same one PHP uses).
"""

import os
from pathlib import Path
from dotenv import load_dotenv

BASE_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = BASE_DIR.parent

load_dotenv(PROJECT_ROOT / '.env')

# --- Database (mirrors PHP .env) ---
DB_HOST = os.getenv('DB_HOST', 'localhost')
DB_PORT = int(os.getenv('DB_PORT', '3306'))
DB_NAME = os.getenv('DB_NAME', 'smart_student_management')
DB_USER = os.getenv('DB_USER', 'root')
DB_PASS = os.getenv('DB_PASS', '')

# --- Flask ---
FLASK_HOST = '127.0.0.1'
FLASK_PORT = 5000
FLASK_DEBUG = os.getenv('APP_DEBUG', 'false').lower() in ('true', '1')

# --- Face capture thresholds (configurable) ---
TARGET_SAMPLES = 25
MIN_FACE_SIZE = 80          # pixels – minimum width/height of detected face box
BLUR_THRESHOLD = 50.0       # Laplacian variance; below this is considered blurry
CAPTURE_INTERVAL_MS = 400   # minimum milliseconds between saved frames
CAMERA_INDEX = 0

# --- Storage paths ---
DATASET_DIR = BASE_DIR / 'dataset'
ENCODINGS_DIR = BASE_DIR / 'encodings'

# --- Recognition (direct embedding matching; no KNN/SVM) ---
# dlib/face_recognition default compare tolerance is 0.6. A slightly stricter
# student-level threshold reduces false IDs before attendance is added later.
MATCH_THRESHOLD = float(os.getenv('FACE_MATCH_THRESHOLD', '0.50'))
# Average of the best K gallery distances per student (see matcher.py).
MATCH_K = max(1, int(os.getenv('FACE_MATCH_K', '3')))
RECOGNITION_FRAME_SCALE = float(os.getenv('FACE_FRAME_SCALE', '0.25'))
PROCESS_EVERY_N_FRAMES = int(os.getenv('FACE_PROCESS_EVERY_N', '2'))
RECOGNITION_COOLDOWN_SECONDS = float(os.getenv('FACE_RECOGNITION_COOLDOWN', '5.0'))
FACE_DETECTION_MODEL = os.getenv('FACE_DETECTION_MODEL', 'hog')  # CPU only
UNKNOWN_LABEL = 'UNKNOWN'
