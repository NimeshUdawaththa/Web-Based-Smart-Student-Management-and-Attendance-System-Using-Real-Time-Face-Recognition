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
