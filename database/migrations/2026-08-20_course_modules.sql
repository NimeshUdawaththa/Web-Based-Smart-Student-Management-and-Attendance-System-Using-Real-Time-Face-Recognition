-- Module Catalogue v1: course_modules link table + backfill from modules.course_id.
-- Idempotent application is via database/scripts/migrate_course_modules.php
-- This file does NOT drop modules.course_id.

CREATE TABLE IF NOT EXISTS course_modules (
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

-- Backfill is handled in PHP so it can detect whether modules.course_id still exists.
