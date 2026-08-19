-- Campus Events v1: one-off workshops/seminars/etc. Not attendance_events.

CREATE TABLE IF NOT EXISTS campus_events (
  campus_event_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(200) NOT NULL,
  description TEXT DEFAULT NULL,
  event_type VARCHAR(50) NOT NULL,
  start_datetime DATETIME NOT NULL,
  end_datetime DATETIME NOT NULL,
  location VARCHAR(150) DEFAULT NULL,
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

CREATE TABLE IF NOT EXISTS campus_event_targets (
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
