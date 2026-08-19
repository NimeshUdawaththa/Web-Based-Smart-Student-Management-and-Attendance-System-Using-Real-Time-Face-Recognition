-- Campus Events v1.1: optional poster, remove unused event_type.
-- Safe to apply only via the PHP migrator on existing databases.
-- Does not touch attendance_events.event_type.

-- ADD COLUMN poster_path VARCHAR(255) DEFAULT NULL AFTER location;
-- DROP COLUMN event_type;
