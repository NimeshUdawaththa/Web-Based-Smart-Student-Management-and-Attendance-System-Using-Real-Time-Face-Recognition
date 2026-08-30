-- Announcements v1.1: normalized multi-audience targets.
-- Does not drop announcements.target_role (deprecated compatibility snapshot).
-- Source of truth after this migration is announcement_targets.

CREATE TABLE IF NOT EXISTS announcement_targets (
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

INSERT INTO announcement_targets (announcement_id, target_role)
SELECT a.announcement_id, 'STUDENT'
FROM announcements a
WHERE a.target_role IN ('STUDENT', 'ALL')
  AND NOT EXISTS (
    SELECT 1 FROM announcement_targets t
    WHERE t.announcement_id = a.announcement_id AND t.target_role = 'STUDENT'
  );

INSERT INTO announcement_targets (announcement_id, target_role)
SELECT a.announcement_id, 'LECTURER'
FROM announcements a
WHERE a.target_role IN ('LECTURER', 'ALL')
  AND NOT EXISTS (
    SELECT 1 FROM announcement_targets t
    WHERE t.announcement_id = a.announcement_id AND t.target_role = 'LECTURER'
  );

INSERT INTO announcement_targets (announcement_id, target_role)
SELECT a.announcement_id, 'ACADEMIC_STAFF'
FROM announcements a
WHERE a.target_role IN ('ACADEMIC_STAFF', 'ALL')
  AND NOT EXISTS (
    SELECT 1 FROM announcement_targets t
    WHERE t.announcement_id = a.announcement_id AND t.target_role = 'ACADEMIC_STAFF'
  );

INSERT INTO announcement_targets (announcement_id, target_role)
SELECT a.announcement_id, 'ADMIN'
FROM announcements a
WHERE a.target_role IN ('ADMIN', 'ALL')
  AND NOT EXISTS (
    SELECT 1 FROM announcement_targets t
    WHERE t.announcement_id = a.announcement_id AND t.target_role = 'ADMIN'
  );
