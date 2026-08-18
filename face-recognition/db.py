"""
MySQL database helpers for the Face Recognition service.
Uses mysql-connector-python to match the existing XAMPP MySQL setup.
"""

import mysql.connector
from mysql.connector import Error as MySQLError
import config


def get_connection():
    return mysql.connector.connect(
        host=config.DB_HOST,
        port=config.DB_PORT,
        database=config.DB_NAME,
        user=config.DB_USER,
        password=config.DB_PASS,
        charset='utf8mb4',
        collation='utf8mb4_unicode_ci',
    )


def get_student_by_id(student_id: int) -> dict | None:
    """Fetch a student row by student_id. Returns None if not found."""
    conn = get_connection()
    try:
        cursor = conn.cursor(dictionary=True)
        cursor.execute(
            "SELECT s.student_id, s.registration_no, s.first_name, s.last_name, s.status "
            "FROM students s WHERE s.student_id = %s LIMIT 1",
            (student_id,),
        )
        return cursor.fetchone()
    finally:
        conn.close()


def list_active_face_profiles() -> list[dict]:
    """
    Return ACTIVE face profiles joined with authoritative student identity.
    Encoding files are not loaded here.
    """
    conn = get_connection()
    try:
        cursor = conn.cursor(dictionary=True)
        cursor.execute(
            "SELECT fp.face_profile_id, fp.student_id, fp.encoding_path, fp.sample_count, "
            "fp.enrolled_at, fp.last_updated, fp.status AS face_status, "
            "s.registration_no, s.first_name, s.last_name, s.status AS student_status "
            "FROM face_profiles fp "
            "INNER JOIN students s ON s.student_id = fp.student_id "
            "WHERE fp.status = 'ACTIVE' "
            "ORDER BY fp.student_id ASC"
        )
        return list(cursor.fetchall())
    finally:
        conn.close()


def get_face_profile(student_id: int) -> dict | None:
    """Fetch existing face profile for a student."""
    conn = get_connection()
    try:
        cursor = conn.cursor(dictionary=True)
        cursor.execute(
            "SELECT face_profile_id, student_id, encoding_path, sample_count, "
            "enrolled_at, last_updated, status "
            "FROM face_profiles WHERE student_id = %s LIMIT 1",
            (student_id,),
        )
        return cursor.fetchone()
    finally:
        conn.close()


def create_or_update_face_profile(student_id: int, encoding_path: str, sample_count: int) -> None:
    """
    Insert or update the face_profiles row for a student.
    Uses a transaction so partial updates cannot leave inconsistent state.
    """
    conn = get_connection()
    try:
        conn.start_transaction()
        cursor = conn.cursor(dictionary=True)

        cursor.execute(
            "SELECT face_profile_id FROM face_profiles WHERE student_id = %s FOR UPDATE",
            (student_id,),
        )
        existing = cursor.fetchone()

        if existing:
            cursor.execute(
                "UPDATE face_profiles SET encoding_path = %s, sample_count = %s, "
                "status = 'ACTIVE', last_updated = NOW() WHERE face_profile_id = %s",
                (encoding_path, sample_count, existing['face_profile_id']),
            )
        else:
            cursor.execute(
                "INSERT INTO face_profiles (student_id, encoding_path, sample_count, status) "
                "VALUES (%s, %s, %s, 'ACTIVE')",
                (student_id, encoding_path, sample_count),
            )

        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()
