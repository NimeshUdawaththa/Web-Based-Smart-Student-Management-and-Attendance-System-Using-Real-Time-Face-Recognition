-- Final attendance snapshot columns. Does not change attendance_events.
-- total_present_minutes remains attended teaching minutes.
-- Unique (student_id, session_id) is unchanged.

ALTER TABLE attendance_records
  ADD COLUMN teaching_minutes INT UNSIGNED NOT NULL DEFAULT 0
    AFTER total_present_minutes,
  ADD COLUMN attendance_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00
    AFTER teaching_minutes,
  ADD COLUMN finalized_at DATETIME DEFAULT NULL
    AFTER left_early;
