-- Coursework & Assessments unification Phase 1.
-- Extends assignments; adds assignment_results for Exam/Practical.
-- Does NOT drop assessments / assessment_results / marks.

-- activity_type + schedule columns (idempotent via migrate script checks)
ALTER TABLE assignments
  ADD COLUMN activity_type ENUM('ASSIGNMENT', 'PRESENTATION', 'EXAM', 'PRACTICAL') NOT NULL DEFAULT 'ASSIGNMENT' AFTER file_path;

ALTER TABLE assignments
  ADD COLUMN scheduled_date DATE DEFAULT NULL AFTER due_date;

ALTER TABLE assignments
  ADD COLUMN start_time TIME DEFAULT NULL AFTER scheduled_date;

ALTER TABLE assignments
  ADD COLUMN end_time TIME DEFAULT NULL AFTER start_time;

ALTER TABLE assignments
  ADD COLUMN room VARCHAR(150) DEFAULT NULL AFTER end_time;

CREATE TABLE IF NOT EXISTS assignment_results (
  result_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  assignment_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  marks_obtained DECIMAL(8,2) NOT NULL,
  remarks TEXT DEFAULT NULL,
  recorded_by INT UNSIGNED NOT NULL,
  recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (result_id),
  UNIQUE KEY uq_assignment_results_assignment_student (assignment_id, student_id),
  KEY idx_assignment_results_student (student_id),
  KEY idx_assignment_results_recorded_by (recorded_by),
  CONSTRAINT chk_assignment_results_marks_nonneg CHECK (marks_obtained >= 0),
  CONSTRAINT fk_assignment_results_assignment
    FOREIGN KEY (assignment_id) REFERENCES assignments (assignment_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_assignment_results_student
    FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_assignment_results_recorded_by
    FOREIGN KEY (recorded_by) REFERENCES lecturers (lecturer_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
