-- Minimal official-break migration for existing SmartAMS databases.
-- Adds optional break_start / break_end to recurring timetable slots and
-- dated lecture sessions. Existing rows stay NULL (no official break).
-- PHP still validates break times before insert/update.

ALTER TABLE schedules
  ADD COLUMN break_start TIME DEFAULT NULL AFTER end_time,
  ADD COLUMN break_end TIME DEFAULT NULL AFTER break_start;

ALTER TABLE lecture_sessions
  ADD COLUMN break_start TIME DEFAULT NULL AFTER scheduled_end,
  ADD COLUMN break_end TIME DEFAULT NULL AFTER break_start;
