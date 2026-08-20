-- =============================================================================
-- Smart Student Management and Attendance System
-- Initial relational schema for MySQL / MariaDB (XAMPP)
-- Engine: InnoDB | Character set: utf8mb4
-- =============================================================================
-- Import example (MariaDB/MySQL client):
--   mysql -u root -p < database/schema/schema.sql
-- Or import this file through phpMyAdmin.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS smart_student_management
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE smart_student_management;

-- -----------------------------------------------------------------------------
-- 1. users
-- Central authentication identity for all four roles.
-- Passwords must be stored as hashes only (password_hash).
-- -----------------------------------------------------------------------------
CREATE TABLE users (
  user_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('ADMIN', 'ACADEMIC_STAFF', 'LECTURER', 'STUDENT') NOT NULL,
  status ENUM('ACTIVE', 'INACTIVE', 'SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. courses
-- Academic programmes offered by the institution.
-- -----------------------------------------------------------------------------
CREATE TABLE courses (
  course_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_code VARCHAR(20) NOT NULL,
  course_name VARCHAR(150) NOT NULL,
  duration_years TINYINT UNSIGNED NOT NULL,
  status ENUM('ACTIVE', 'INACTIVE', 'ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (course_id),
  UNIQUE KEY uq_courses_code (course_code),
  CONSTRAINT chk_courses_duration CHECK (duration_years > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. batches
-- An intake/group of students belonging to one course.
-- UNIQUE (batch_id, course_id) exists so students can reference both columns
-- together and cannot be assigned a batch from a different course.
-- -----------------------------------------------------------------------------
CREATE TABLE batches (
  batch_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id INT UNSIGNED NOT NULL,
  batch_name VARCHAR(100) NOT NULL,
  intake_year SMALLINT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE DEFAULT NULL,
  status ENUM('ACTIVE', 'COMPLETED', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (batch_id),
  UNIQUE KEY uq_batches_course_name (course_id, batch_name),
  UNIQUE KEY uq_batches_id_course (batch_id, course_id),
  KEY idx_batches_intake_year (intake_year),
  CONSTRAINT chk_batches_dates CHECK (end_date IS NULL OR end_date >= start_date),
  CONSTRAINT fk_batches_course
    FOREIGN KEY (course_id) REFERENCES courses (course_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. students
-- Student profile linked to exactly one login user.
-- -----------------------------------------------------------------------------
CREATE TABLE students (
  student_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  registration_no VARCHAR(50) NOT NULL,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  phone VARCHAR(20) DEFAULT NULL,
  date_of_birth DATE DEFAULT NULL,
  gender ENUM('MALE', 'FEMALE', 'OTHER') DEFAULT NULL,
  course_id INT UNSIGNED NOT NULL,
  batch_id INT UNSIGNED NOT NULL,
  enrollment_date DATE NOT NULL,
  profile_photo VARCHAR(255) DEFAULT NULL,
  status ENUM('ACTIVE', 'INACTIVE', 'GRADUATED', 'SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id),
  UNIQUE KEY uq_students_user_id (user_id),
  UNIQUE KEY uq_students_registration_no (registration_no),
  KEY idx_students_name (last_name, first_name),
  KEY idx_students_course_batch (course_id, batch_id),
  CONSTRAINT fk_students_user
    FOREIGN KEY (user_id) REFERENCES users (user_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_students_course
    FOREIGN KEY (course_id) REFERENCES courses (course_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_students_batch
    FOREIGN KEY (batch_id) REFERENCES batches (batch_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_students_batch_course
    FOREIGN KEY (batch_id, course_id) REFERENCES batches (batch_id, course_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. lecturers
-- Lecturer profile linked to exactly one login user.
-- -----------------------------------------------------------------------------
CREATE TABLE lecturers (
  lecturer_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  staff_no VARCHAR(50) NOT NULL,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  phone VARCHAR(20) DEFAULT NULL,
  department VARCHAR(100) DEFAULT NULL,
  status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (lecturer_id),
  UNIQUE KEY uq_lecturers_user_id (user_id),
  UNIQUE KEY uq_lecturers_staff_no (staff_no),
  KEY idx_lecturers_department (department),
  CONSTRAINT fk_lecturers_user
    FOREIGN KEY (user_id) REFERENCES users (user_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. academic_staff
-- Academic staff profile linked to exactly one login user.
-- -----------------------------------------------------------------------------
CREATE TABLE academic_staff (
  academic_staff_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  staff_no VARCHAR(50) NOT NULL,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  phone VARCHAR(20) DEFAULT NULL,
  position VARCHAR(100) DEFAULT NULL,
  status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (academic_staff_id),
  UNIQUE KEY uq_academic_staff_user_id (user_id),
  UNIQUE KEY uq_academic_staff_staff_no (staff_no),
  CONSTRAINT fk_academic_staff_user
    FOREIGN KEY (user_id) REFERENCES users (user_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. modules
-- Independent Module Catalogue. A module may be linked to many courses.
-- -----------------------------------------------------------------------------
CREATE TABLE modules (
  module_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  module_code VARCHAR(20) NOT NULL,
  module_name VARCHAR(150) NOT NULL,
  credits DECIMAL(4,1) NOT NULL,
  semester TINYINT UNSIGNED NOT NULL,
  status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (module_id),
  UNIQUE KEY uq_modules_code (module_code),
  KEY idx_modules_semester (semester),
  CONSTRAINT chk_modules_credits CHECK (credits > 0),
  CONSTRAINT chk_modules_semester CHECK (semester > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7b. course_modules
-- Which catalogue modules a course currently uses. Rows are never hard-deleted.
-- -----------------------------------------------------------------------------
CREATE TABLE course_modules (
  course_module_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id INT UNSIGNED NOT NULL,
  module_id INT UNSIGNED NOT NULL,
  status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (course_module_id),
  UNIQUE KEY uq_course_modules_course_module (course_id, module_id),
  KEY idx_course_modules_module (module_id),
  CONSTRAINT fk_course_modules_course
    FOREIGN KEY (course_id) REFERENCES courses (course_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_course_modules_module
    FOREIGN KEY (module_id) REFERENCES modules (module_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. student_modules
-- Enrolment of a student on a module. One row per student/module pair.
-- -----------------------------------------------------------------------------
CREATE TABLE student_modules (
  student_module_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT UNSIGNED NOT NULL,
  module_id INT UNSIGNED NOT NULL,
  enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('ENROLLED', 'COMPLETED', 'DROPPED') NOT NULL DEFAULT 'ENROLLED',
  PRIMARY KEY (student_module_id),
  UNIQUE KEY uq_student_modules_student_module (student_id, module_id),
  KEY idx_student_modules_module (module_id),
  CONSTRAINT fk_student_modules_student
    FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_student_modules_module
    FOREIGN KEY (module_id) REFERENCES modules (module_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8b. batch_modules
-- Which modules a batch currently takes. Subset of ACTIVE course_modules for the batch's course.
-- status INACTIVE means unassigned going forward; rows are never hard-deleted.
-- -----------------------------------------------------------------------------
CREATE TABLE batch_modules (
  batch_module_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id INT UNSIGNED NOT NULL,
  module_id INT UNSIGNED NOT NULL,
  status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (batch_module_id),
  UNIQUE KEY uq_batch_modules_batch_module (batch_id, module_id),
  KEY idx_batch_modules_module (module_id),
  CONSTRAINT fk_batch_modules_batch
    FOREIGN KEY (batch_id) REFERENCES batches (batch_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_batch_modules_module
    FOREIGN KEY (module_id) REFERENCES modules (module_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. module_lecturers
-- Assignment of lecturers to modules. One row per lecturer/module pair.
-- -----------------------------------------------------------------------------
CREATE TABLE module_lecturers (
  module_lecturer_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  module_id INT UNSIGNED NOT NULL,
  lecturer_id INT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (module_lecturer_id),
  UNIQUE KEY uq_module_lecturers_module_lecturer (module_id, lecturer_id),
  KEY idx_module_lecturers_lecturer (lecturer_id),
  CONSTRAINT fk_module_lecturers_module
    FOREIGN KEY (module_id) REFERENCES modules (module_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_module_lecturers_lecturer
    FOREIGN KEY (lecturer_id) REFERENCES lecturers (lecturer_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 10. schedules
-- Recurring weekly timetable slots. Not a single lecture occurrence.
-- -----------------------------------------------------------------------------
CREATE TABLE schedules (
  schedule_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  module_id INT UNSIGNED NOT NULL,
  lecturer_id INT UNSIGNED NOT NULL,
  batch_id INT UNSIGNED NOT NULL,
  day_of_week ENUM('MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY') NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  break_start TIME DEFAULT NULL,
  break_end TIME DEFAULT NULL,
  room VARCHAR(50) DEFAULT NULL,
  status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (schedule_id),
  KEY idx_schedules_batch_day (batch_id, day_of_week),
  KEY idx_schedules_lecturer_day (lecturer_id, day_of_week, start_time),
  KEY idx_schedules_module (module_id),
  CONSTRAINT chk_schedules_time CHECK (end_time > start_time),
  CONSTRAINT chk_schedules_break CHECK (
    (break_start IS NULL AND break_end IS NULL)
    OR (
      break_start IS NOT NULL AND break_end IS NOT NULL
      AND break_start >= start_time
      AND break_end <= end_time
      AND break_end > break_start
    )
  ),
  CONSTRAINT fk_schedules_module
    FOREIGN KEY (module_id) REFERENCES modules (module_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_schedules_lecturer
    FOREIGN KEY (lecturer_id) REFERENCES lecturers (lecturer_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_schedules_batch
    FOREIGN KEY (batch_id) REFERENCES batches (batch_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 11. lecture_sessions
-- A real occurrence of a lecture on a specific date.
-- schedule_id is nullable so ad-hoc sessions can exist without a timetable row.
-- -----------------------------------------------------------------------------
CREATE TABLE lecture_sessions (
  session_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  module_id INT UNSIGNED NOT NULL,
  lecturer_id INT UNSIGNED NOT NULL,
  batch_id INT UNSIGNED NOT NULL,
  schedule_id INT UNSIGNED DEFAULT NULL,
  session_date DATE NOT NULL,
  scheduled_start TIME NOT NULL,
  scheduled_end TIME NOT NULL,
  break_start TIME DEFAULT NULL,
  break_end TIME DEFAULT NULL,
  actual_start DATETIME DEFAULT NULL,
  actual_end DATETIME DEFAULT NULL,
  room VARCHAR(50) DEFAULT NULL,
  late_after_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  status ENUM('SCHEDULED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED') NOT NULL DEFAULT 'SCHEDULED',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (session_id),
  UNIQUE KEY uq_lecture_sessions_occurrence (module_id, batch_id, session_date, scheduled_start),
  KEY idx_lecture_sessions_date_status (session_date, status),
  KEY idx_lecture_sessions_batch_date (batch_id, session_date),
  KEY idx_lecture_sessions_lecturer_date (lecturer_id, session_date),
  KEY idx_lecture_sessions_schedule (schedule_id),
  CONSTRAINT chk_lecture_sessions_scheduled_time CHECK (scheduled_end > scheduled_start),
  CONSTRAINT chk_lecture_sessions_break CHECK (
    (break_start IS NULL AND break_end IS NULL)
    OR (
      break_start IS NOT NULL AND break_end IS NOT NULL
      AND break_start >= scheduled_start
      AND break_end <= scheduled_end
      AND break_end > break_start
    )
  ),
  CONSTRAINT chk_lecture_sessions_actual_time CHECK (
    actual_start IS NULL OR actual_end IS NULL OR actual_end >= actual_start
  ),
  CONSTRAINT fk_lecture_sessions_module
    FOREIGN KEY (module_id) REFERENCES modules (module_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_lecture_sessions_lecturer
    FOREIGN KEY (lecturer_id) REFERENCES lecturers (lecturer_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_lecture_sessions_batch
    FOREIGN KEY (batch_id) REFERENCES batches (batch_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_lecture_sessions_schedule
    FOREIGN KEY (schedule_id) REFERENCES schedules (schedule_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 12. face_profiles
-- Metadata only. Encoding files live on disk under face-recognition/encodings/.
-- Initial design: at most one face profile row per student.
-- -----------------------------------------------------------------------------
CREATE TABLE face_profiles (
  face_profile_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT UNSIGNED NOT NULL,
  encoding_path VARCHAR(255) NOT NULL,
  sample_count INT UNSIGNED NOT NULL DEFAULT 0,
  enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  PRIMARY KEY (face_profile_id),
  UNIQUE KEY uq_face_profiles_student (student_id),
  CONSTRAINT fk_face_profiles_student
    FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 13. attendance_events
-- Raw valid IN/OUT recognition events. Not the final attendance decision.
-- -----------------------------------------------------------------------------
CREATE TABLE attendance_events (
  event_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT UNSIGNED NOT NULL,
  session_id INT UNSIGNED NOT NULL,
  event_type ENUM('IN', 'OUT') NOT NULL,
  recognized_at DATETIME NOT NULL,
  confidence DECIMAL(5,2) NOT NULL,
  camera_id VARCHAR(50) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id),
  KEY idx_attendance_events_session_student_time (session_id, student_id, recognized_at),
  KEY idx_attendance_events_student_time (student_id, recognized_at),
  KEY idx_attendance_events_session_type_time (session_id, event_type, recognized_at),
  CONSTRAINT chk_attendance_events_confidence CHECK (confidence >= 0 AND confidence <= 100),
  CONSTRAINT fk_attendance_events_student
    FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_attendance_events_session
    FOREIGN KEY (session_id) REFERENCES lecture_sessions (session_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 13b. attendance_early_pending
-- Door-camera early arrival state. Not an official attendance audit event.
-- Promoted to attendance_events.IN at scheduled start if still inside.
-- -----------------------------------------------------------------------------
CREATE TABLE attendance_early_pending (
  pending_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT UNSIGNED NOT NULL,
  session_id INT UNSIGNED NOT NULL,
  inside TINYINT(1) NOT NULL DEFAULT 0,
  early_entry_time DATETIME DEFAULT NULL,
  last_direction ENUM('ENTRY', 'EXIT') NOT NULL,
  last_seen_at DATETIME NOT NULL,
  confidence DECIMAL(5,2) DEFAULT NULL,
  camera_id VARCHAR(50) DEFAULT NULL,
  status ENUM('OPEN', 'PROMOTED', 'CANCELLED') NOT NULL DEFAULT 'OPEN',
  promoted_event_id INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (pending_id),
  UNIQUE KEY uq_early_pending_student_session (student_id, session_id),
  KEY idx_early_pending_session_status_inside (session_id, status, inside),
  CONSTRAINT chk_early_pending_inside CHECK (inside IN (0, 1)),
  CONSTRAINT chk_early_pending_confidence CHECK (confidence IS NULL OR (confidence >= 0 AND confidence <= 100)),
  CONSTRAINT fk_early_pending_student
    FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_early_pending_session
    FOREIGN KEY (session_id) REFERENCES lecture_sessions (session_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_early_pending_event
    FOREIGN KEY (promoted_event_id) REFERENCES attendance_events (event_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 14. attendance_records
-- Processed attendance result: one row per student per lecture session.
-- left_early is independent of PRESENT/LATE/ABSENT so a student can be late
-- and also leave early.
-- -----------------------------------------------------------------------------
CREATE TABLE attendance_records (
  attendance_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT UNSIGNED NOT NULL,
  session_id INT UNSIGNED NOT NULL,
  first_entry DATETIME DEFAULT NULL,
  last_exit DATETIME DEFAULT NULL,
  total_present_minutes INT UNSIGNED NOT NULL DEFAULT 0,
  teaching_minutes INT UNSIGNED NOT NULL DEFAULT 0,
  attendance_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  status ENUM('PRESENT', 'LATE', 'ABSENT') NOT NULL DEFAULT 'ABSENT',
  late_minutes INT UNSIGNED NOT NULL DEFAULT 0,
  left_early TINYINT(1) NOT NULL DEFAULT 0,
  finalized_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (attendance_id),
  UNIQUE KEY uq_attendance_records_student_session (student_id, session_id),
  KEY idx_attendance_records_session_status (session_id, status),
  CONSTRAINT chk_attendance_records_left_early CHECK (left_early IN (0, 1)),
  CONSTRAINT chk_attendance_records_exit_after_entry CHECK (
    first_entry IS NULL OR last_exit IS NULL OR last_exit >= first_entry
  ),
  CONSTRAINT fk_attendance_records_student
    FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_attendance_records_session
    FOREIGN KEY (session_id) REFERENCES lecture_sessions (session_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 15. assignments
-- -----------------------------------------------------------------------------
CREATE TABLE assignments (
  assignment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  module_id INT UNSIGNED NOT NULL,
  lecturer_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT DEFAULT NULL,
  file_path VARCHAR(255) DEFAULT NULL,
  due_date DATETIME NOT NULL,
  max_marks DECIMAL(8,2) NOT NULL,
  status ENUM('DRAFT', 'PUBLISHED', 'CLOSED') NOT NULL DEFAULT 'DRAFT',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (assignment_id),
  KEY idx_assignments_module_due (module_id, due_date),
  KEY idx_assignments_lecturer (lecturer_id),
  CONSTRAINT chk_assignments_max_marks CHECK (max_marks > 0),
  CONSTRAINT fk_assignments_module
    FOREIGN KEY (module_id) REFERENCES modules (module_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_assignments_lecturer
    FOREIGN KEY (lecturer_id) REFERENCES lecturers (lecturer_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 16. assignment_submissions
-- One submission record per student per assignment in this initial design.
-- Resubmission should update this row rather than insert a second row.
-- -----------------------------------------------------------------------------
CREATE TABLE assignment_submissions (
  submission_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  assignment_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('SUBMITTED', 'LATE', 'GRADED') NOT NULL DEFAULT 'SUBMITTED',
  grade DECIMAL(8,2) DEFAULT NULL,
  feedback TEXT DEFAULT NULL,
  PRIMARY KEY (submission_id),
  UNIQUE KEY uq_assignment_submissions_assignment_student (assignment_id, student_id),
  KEY idx_assignment_submissions_student (student_id),
  CONSTRAINT chk_assignment_submissions_grade CHECK (grade IS NULL OR grade >= 0),
  CONSTRAINT fk_assignment_submissions_assignment
    FOREIGN KEY (assignment_id) REFERENCES assignments (assignment_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_assignment_submissions_student
    FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 17. marks
-- Module assessment results. recorded_by references lecturers, not users.
-- -----------------------------------------------------------------------------
CREATE TABLE marks (
  mark_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT UNSIGNED NOT NULL,
  module_id INT UNSIGNED NOT NULL,
  assessment_type VARCHAR(50) NOT NULL,
  assessment_name VARCHAR(150) NOT NULL,
  marks_obtained DECIMAL(8,2) NOT NULL,
  max_marks DECIMAL(8,2) NOT NULL,
  recorded_by INT UNSIGNED NOT NULL,
  recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  remarks VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (mark_id),
  KEY idx_marks_student_module (student_id, module_id),
  KEY idx_marks_module_type (module_id, assessment_type),
  KEY idx_marks_recorded_by (recorded_by),
  CONSTRAINT chk_marks_range CHECK (
    marks_obtained >= 0 AND max_marks > 0 AND marks_obtained <= max_marks
  ),
  CONSTRAINT fk_marks_student
    FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_marks_module
    FOREIGN KEY (module_id) REFERENCES modules (module_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_marks_recorded_by
    FOREIGN KEY (recorded_by) REFERENCES lecturers (lecturer_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 18. announcements
-- target_role is a deprecated v1 compatibility snapshot (NOT NULL leftover).
-- Source of truth for audiences is announcement_targets.
-- -----------------------------------------------------------------------------
CREATE TABLE announcements (
  announcement_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(200) NOT NULL,
  message TEXT NOT NULL,
  target_role ENUM('ALL', 'ADMIN', 'ACADEMIC_STAFF', 'LECTURER', 'STUDENT') NOT NULL DEFAULT 'ALL',
  created_by INT UNSIGNED NOT NULL,
  published_at DATETIME DEFAULT NULL,
  expires_at DATETIME DEFAULT NULL,
  status ENUM('DRAFT', 'PUBLISHED', 'ARCHIVED') NOT NULL DEFAULT 'DRAFT',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (announcement_id),
  KEY idx_announcements_target_status_published (target_role, status, published_at),
  KEY idx_announcements_created_by (created_by),
  CONSTRAINT chk_announcements_expiry CHECK (expires_at IS NULL OR published_at IS NULL OR expires_at >= published_at),
  CONSTRAINT fk_announcements_created_by
    FOREIGN KEY (created_by) REFERENCES users (user_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 18b. announcement_targets
-- One row per individual audience role. ALL is not stored; it expands to four rows.
-- -----------------------------------------------------------------------------
CREATE TABLE announcement_targets (
  announcement_target_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  announcement_id INT UNSIGNED NOT NULL,
  target_role ENUM('ADMIN', 'ACADEMIC_STAFF', 'LECTURER', 'STUDENT') NOT NULL,
  PRIMARY KEY (announcement_target_id),
  UNIQUE KEY uq_announcement_targets_announcement_role (announcement_id, target_role),
  KEY idx_announcement_targets_role (target_role),
  CONSTRAINT fk_announcement_targets_announcement
    FOREIGN KEY (announcement_id) REFERENCES announcements (announcement_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 18c. campus_events
-- One-off campus/academic events (workshops, seminars, orientation, etc.).
-- Not lecture timetable sessions and not attendance IN/OUT events.
-- -----------------------------------------------------------------------------
CREATE TABLE campus_events (
  campus_event_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(200) NOT NULL,
  description TEXT DEFAULT NULL,
  start_datetime DATETIME NOT NULL,
  end_datetime DATETIME NOT NULL,
  location VARCHAR(150) DEFAULT NULL,
  poster_path VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED NOT NULL,
  status ENUM('DRAFT', 'PUBLISHED', 'CANCELLED') NOT NULL DEFAULT 'DRAFT',
  published_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (campus_event_id),
  KEY idx_campus_events_status_start (status, start_datetime),
  KEY idx_campus_events_published_at (published_at),
  KEY idx_campus_events_created_by (created_by),
  CONSTRAINT chk_campus_events_time CHECK (end_datetime > start_datetime),
  CONSTRAINT fk_campus_events_created_by
    FOREIGN KEY (created_by) REFERENCES users (user_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 18d. campus_event_targets
-- One row per individual audience role. ALL is not stored.
-- -----------------------------------------------------------------------------
CREATE TABLE campus_event_targets (
  campus_event_target_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  campus_event_id INT UNSIGNED NOT NULL,
  target_role ENUM('ADMIN', 'ACADEMIC_STAFF', 'LECTURER', 'STUDENT') NOT NULL,
  PRIMARY KEY (campus_event_target_id),
  UNIQUE KEY uq_campus_event_targets_event_role (campus_event_id, target_role),
  KEY idx_campus_event_targets_role (target_role),
  CONSTRAINT fk_campus_event_targets_event
    FOREIGN KEY (campus_event_id) REFERENCES campus_events (campus_event_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 19. audit_logs
-- Append-oriented activity history. user_id is nullable so logs remain if a
-- user row is ever removed, and so system actions can be recorded.
-- -----------------------------------------------------------------------------
CREATE TABLE audit_logs (
  log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED DEFAULT NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(50) DEFAULT NULL,
  entity_id INT UNSIGNED DEFAULT NULL,
  description TEXT DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (log_id),
  KEY idx_audit_logs_user_created (user_id, created_at),
  KEY idx_audit_logs_entity (entity_type, entity_id),
  KEY idx_audit_logs_created_at (created_at),
  CONSTRAINT fk_audit_logs_user
    FOREIGN KEY (user_id) REFERENCES users (user_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
