-- Early door-camera pending state. Not an official IN/OUT audit event.
-- One row per student per lecture session. Promoted to attendance_events.IN
-- at scheduled start when inside=1.

CREATE TABLE IF NOT EXISTS attendance_early_pending (
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
