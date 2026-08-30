-- Phase 6: drop legacy modules.course_id after course_modules is the source of truth.
-- Run only after PHP/JS no longer read modules.course_id.
-- Idempotent application is via database/scripts/migrate_drop_modules_course_id.php

-- 1. Ensure global unique module codes exist (uq_modules_code).
-- 2. Drop fk_modules_course, uq_modules_course_code, idx_modules_semester.
-- 3. Drop modules.course_id.
-- 4. Add idx_modules_semester (semester) if missing.
