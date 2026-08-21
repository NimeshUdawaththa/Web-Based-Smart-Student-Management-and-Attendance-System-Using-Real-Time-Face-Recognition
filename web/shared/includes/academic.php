<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Academic timetable, module enrolment, lecture sessions, and eligibility.
 * Attendance IN/OUT recording lives in attendance.php.
 */

/**
 * @return list<string>
 */
function module_statuses(): array
{
    return ['ACTIVE', 'INACTIVE'];
}

/**
 * @return list<string>
 */
function student_module_statuses(): array
{
    return ['ENROLLED', 'COMPLETED', 'DROPPED'];
}

/**
 * @return list<string>
 */
function schedule_statuses(): array
{
    return ['ACTIVE', 'INACTIVE'];
}

/**
 * @return list<string>
 */
function lecture_session_statuses(): array
{
    return ['SCHEDULED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'];
}

/**
 * @return list<string>
 */
function weekdays(): array
{
    return ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];
}

function app_today(): string
{
    return app_now()->format('Y-m-d');
}

function app_now_datetime(): string
{
    return app_now()->format('Y-m-d H:i:s');
}

function set_app_now_override(?DateTimeImmutable $now): void
{
    $GLOBALS['smartams_app_now_override'] = $now;
}

function app_now(): DateTimeImmutable
{
    if (isset($GLOBALS['smartams_app_now_override']) && $GLOBALS['smartams_app_now_override'] instanceof DateTimeImmutable) {
        return $GLOBALS['smartams_app_now_override'];
    }

    return new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
}

function parse_app_datetime(?string $value): ?DateTimeImmutable
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone(APP_TIMEZONE));
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }
    }

    try {
        return new DateTimeImmutable($value, new DateTimeZone(APP_TIMEZONE));
    } catch (Exception) {
        return null;
    }
}

function session_scheduled_datetime(array $session, string $bound): ?DateTimeImmutable
{
    $rawTime = $bound === 'end'
        ? ($session['scheduled_end'] ?? '')
        : ($session['scheduled_start'] ?? '');
    $rawDate = $session['session_date'] ?? '';

    if ($rawDate instanceof DateTimeInterface) {
        $date = $rawDate->format('Y-m-d');
    } else {
        $date = trim((string) $rawDate);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $date, $dateMatch) === 1) {
            $date = $dateMatch[1];
        }
    }

    if ($rawTime instanceof DateTimeInterface) {
        $time = $rawTime->format('H:i:s');
    } else {
        $time = trim((string) $rawTime);
        if (preg_match('/(\d{1,2}:\d{2}(?::\d{2}(?:\.\d+)?)?)/', $time, $timeMatch) === 1) {
            $time = $timeMatch[1];
        }
    }

    $hm = normalize_time_hm($time);
    if (!validate_date_ymd($date) || !validate_time_hm($hm)) {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i',
        $date . ' ' . $hm,
        new DateTimeZone(APP_TIMEZONE)
    );

    return $parsed === false ? null : $parsed;
}

/**
 * Hybrid timetable sync. No background daemon.
 *
 * Runs at most once per PHP request unless $force is true.
 *
 * Rules (APP_TIMEZONE, e.g. Asia/Colombo):
 * - SCHEDULED and start <= now < end → IN_PROGRESS.
 *   actual_start = scheduled start datetime (timetable open, not "when a page loaded").
 * - SCHEDULED and now >= end → COMPLETED without opening.
 *   actual_start stays NULL; actual_end = scheduled end.
 * - IN_PROGRESS and now >= end → COMPLETED.
 *   actual_end = scheduled end; existing actual_start is kept.
 * - CANCELLED and COMPLETED are never changed.
 *
 * Auto-start still respects one IN_PROGRESS session per lecturer and per batch.
 *
 * After status transitions, OPEN early-pending rows are promoted to official IN
 * (inside at scheduled start) or CANCELLED (left before start / session never opened).
 *
 * @return array{skipped?: bool, opened: int, closed: int, expired: int}
 */
function sync_scheduled_session_states(bool $force = false): array
{
    static $succeeded = false;
    if ($succeeded && !$force) {
        return ['skipped' => true, 'opened' => 0, 'closed' => 0, 'expired' => 0];
    }

    $opened = 0;
    $closed = 0;
    $expired = 0;
    $justCompleted = [];
    $ownsTransaction = false;
    $pdo = db();

    try {
        $now = app_now();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        $statement = $pdo->query(
            "SELECT session_id, lecturer_id, batch_id, status, session_date,
                    scheduled_start, scheduled_end, actual_start, actual_end
             FROM lecture_sessions
             WHERE status IN ('SCHEDULED', 'IN_PROGRESS')
             FOR UPDATE"
        );
        $rows = $statement === false ? [] : $statement->fetchAll();
        if ($statement instanceof PDOStatement) {
            $statement->closeCursor();
        }

        foreach ($rows as $row) {
            if ($row['status'] !== 'IN_PROGRESS') {
                continue;
            }
            $end = session_scheduled_datetime($row, 'end');
            if (!$end instanceof DateTimeImmutable || $now < $end) {
                continue;
            }
            $start = session_scheduled_datetime($row, 'start');
            if ($start instanceof DateTimeImmutable) {
                promote_open_early_pending_for_session($pdo, (int) $row['session_id'], $start);
            }
            $complete = $pdo->prepare(
                "UPDATE lecture_sessions
                 SET status = 'COMPLETED', actual_end = :actual_end
                 WHERE session_id = :session_id AND status = 'IN_PROGRESS'"
            );
            $complete->execute([
                'actual_end' => $end->format('Y-m-d H:i:s'),
                'session_id' => $row['session_id'],
            ]);
            $closed += $complete->rowCount();
            if ($complete->rowCount() > 0) {
                $justCompleted[] = (int) $row['session_id'];
            }
        }

        foreach ($rows as $row) {
            if ($row['status'] !== 'SCHEDULED') {
                continue;
            }
            $end = session_scheduled_datetime($row, 'end');
            if (!$end instanceof DateTimeImmutable || $now < $end) {
                continue;
            }
            cancel_open_early_pending_for_session($pdo, (int) $row['session_id']);
            $expire = $pdo->prepare(
                "UPDATE lecture_sessions
                 SET status = 'COMPLETED', actual_end = :actual_end
                 WHERE session_id = :session_id AND status = 'SCHEDULED'"
            );
            $expire->execute([
                'actual_end' => $end->format('Y-m-d H:i:s'),
                'session_id' => $row['session_id'],
            ]);
            $expired += $expire->rowCount();
        }

        $busyLecturers = [];
        $busyBatches = [];
        $busyRows = $pdo->query(
            "SELECT session_id, lecturer_id, batch_id, session_date, scheduled_start, scheduled_end
             FROM lecture_sessions
             WHERE status = 'IN_PROGRESS'
             FOR UPDATE"
        );
        $busyList = $busyRows === false ? [] : $busyRows->fetchAll();
        if ($busyRows instanceof PDOStatement) {
            $busyRows->closeCursor();
        }
        foreach ($busyList as $busy) {
            $end = session_scheduled_datetime($busy, 'end');
            if ($end instanceof DateTimeImmutable && $now >= $end) {
                continue;
            }
            $busyLecturers[(int) $busy['lecturer_id']] = true;
            $busyBatches[(int) $busy['batch_id']] = true;
        }

        foreach ($rows as $row) {
            if ($row['status'] !== 'SCHEDULED') {
                continue;
            }
            $start = session_scheduled_datetime($row, 'start');
            $end = session_scheduled_datetime($row, 'end');
            if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
                error_log(
                    'Lecture session timetable sync could not parse times for session_id='
                    . $row['session_id']
                );
                continue;
            }
            if ($now < $start || $now >= $end) {
                continue;
            }

            $lecturerId = (int) $row['lecturer_id'];
            $batchId = (int) $row['batch_id'];
            if (isset($busyLecturers[$lecturerId]) || isset($busyBatches[$batchId])) {
                error_log(
                    'Lecture session timetable sync deferred auto-start for session_id='
                    . $row['session_id']
                    . ' because another IN_PROGRESS session exists for this lecturer or batch'
                );
                continue;
            }

            $open = $pdo->prepare(
                "UPDATE lecture_sessions
                 SET status = 'IN_PROGRESS', actual_start = :actual_start
                 WHERE session_id = :session_id AND status = 'SCHEDULED'"
            );
            $open->execute([
                'actual_start' => $start->format('Y-m-d H:i:s'),
                'session_id' => $row['session_id'],
            ]);
            if ($open->rowCount() > 0) {
                $opened++;
                $busyLecturers[$lecturerId] = true;
                $busyBatches[$batchId] = true;
            }
        }

        $openNow = $pdo->query(
            "SELECT session_id, session_date, scheduled_start, scheduled_end
             FROM lecture_sessions
             WHERE status = 'IN_PROGRESS'
             FOR UPDATE"
        );
        $openList = $openNow === false ? [] : $openNow->fetchAll();
        if ($openNow instanceof PDOStatement) {
            $openNow->closeCursor();
        }
        foreach ($openList as $openRow) {
            $start = session_scheduled_datetime($openRow, 'start');
            if ($start instanceof DateTimeImmutable && $now >= $start) {
                promote_open_early_pending_for_session($pdo, (int) $openRow['session_id'], $start);
            }
        }

        cancel_open_early_pending_for_terminal_sessions($pdo);

        foreach ($justCompleted as $completedId) {
            finalize_session_attendance($completedId);
        }

        if ($ownsTransaction) {
            $pdo->commit();
            $succeeded = true;
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Lecture session timetable sync failed: ' . $exception->getMessage());
        $succeeded = false;
    }

    return [
        'opened' => $opened,
        'closed' => $closed,
        'expired' => $expired,
    ];
}

function session_lifecycle_note(array $session): string
{
    $status = (string) ($session['status'] ?? '');
    $scheduledStart = session_scheduled_datetime($session, 'start');
    $actualStart = parse_app_datetime(isset($session['actual_start']) ? (string) $session['actual_start'] : null);

    return match ($status) {
        'SCHEDULED' => 'Waiting for the scheduled start. Opening a session page, dashboard, or attendance check will start it automatically when that time is reached.',
        'IN_PROGRESS' => (
            $actualStart instanceof DateTimeImmutable
            && $scheduledStart instanceof DateTimeImmutable
            && $actualStart->format('Y-m-d H:i') === $scheduledStart->format('Y-m-d H:i')
                ? 'Opened automatically from the timetable at the scheduled start. Manual Stop remains available.'
                : (
                    $actualStart instanceof DateTimeImmutable
                    && $scheduledStart instanceof DateTimeImmutable
                    && $actualStart < $scheduledStart
                        ? 'Started manually before the scheduled start.'
                        : 'In progress. Manual Stop remains available. It will complete automatically at the scheduled end.'
                )
        ),
        'COMPLETED' => empty($session['actual_start'])
            ? 'Closed automatically after the scheduled end without ever opening. It will not reopen.'
            : 'Completed. It will not reopen automatically.',
        'CANCELLED' => 'Cancelled. Timetable sync will not start or complete this session.',
        default => '',
    };
}

function session_lifecycle_hint(array $session): string
{
    $status = (string) ($session['status'] ?? '');
    $scheduledStart = session_scheduled_datetime($session, 'start');
    $actualStart = parse_app_datetime(isset($session['actual_start']) ? (string) $session['actual_start'] : null);

    if (
        $status === 'IN_PROGRESS'
        && $actualStart instanceof DateTimeImmutable
        && $scheduledStart instanceof DateTimeImmutable
        && $actualStart->format('Y-m-d H:i') === $scheduledStart->format('Y-m-d H:i')
    ) {
        return 'Opened from timetable';
    }

    if (
        $status === 'IN_PROGRESS'
        && $actualStart instanceof DateTimeImmutable
        && $scheduledStart instanceof DateTimeImmutable
        && $actualStart < $scheduledStart
    ) {
        return 'Started early (manual)';
    }

    if ($status === 'COMPLETED' && empty($session['actual_start'])) {
        return 'Ended without opening';
    }

    return '';
}

function weekday_from_date(string $date): ?string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date, new DateTimeZone(APP_TIMEZONE));
    if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
        return null;
    }

    return strtoupper($parsed->format('l'));
}

function monday_of_week(string $date): ?string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date, new DateTimeZone(APP_TIMEZONE));
    if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
        return null;
    }

    $offset = ((int) $parsed->format('N')) - 1;

    return $parsed->modify('-' . $offset . ' days')->format('Y-m-d');
}

function weekday_offset(string $weekday): ?int
{
    $index = array_search($weekday, weekdays(), true);

    return $index === false ? null : (int) $index;
}

function validate_time_hm(string $time): bool
{
    $parsed = DateTimeImmutable::createFromFormat('H:i', $time);
    if ($parsed !== false && $parsed->format('H:i') === $time) {
        return true;
    }

    $parsedSeconds = DateTimeImmutable::createFromFormat('H:i:s', $time);

    return $parsedSeconds !== false && $parsedSeconds->format('H:i:s') === $time;
}

function normalize_time_hm(string $time): string
{
    $time = trim($time);
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2}(?:\.\d+)?)?$/', $time, $match) === 1) {
        return sprintf('%02d:%02d', (int) $match[1], (int) $match[2]);
    }

    return $time;
}

function format_time_display(?string $time): string
{
    if ($time === null || $time === '') {
        return '-';
    }

    return normalize_time_hm($time);
}

function times_overlap(string $startA, string $endA, string $startB, string $endB): bool
{
    return $startA < $endB && $startB < $endA;
}

function can_manage_academic(): bool
{
    $user = current_user();

    return $user !== null && in_array($user['role'], ['ADMIN', 'ACADEMIC_STAFF'], true);
}

/**
 * @return array<string, mixed>|null
 */
function get_lecturer_by_user_id(int $userId): ?array
{
    $statement = db()->prepare(
        "SELECT l.lecturer_id, l.user_id, l.staff_no, l.first_name, l.last_name, l.phone, l.department, l.status,
                u.username, u.email, u.status AS account_status
         FROM lecturers l
         INNER JOIN users u ON u.user_id = l.user_id
         WHERE l.user_id = :user_id
         LIMIT 1"
    );
    $statement->execute(['user_id' => $userId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * @return array<string, mixed>|null
 */
function get_student_by_user_id(int $userId): ?array
{
    $statement = db()->prepare(
        "SELECT s.student_id, s.user_id, s.registration_no, s.first_name, s.last_name, s.course_id, s.batch_id, s.status
         FROM students s
         WHERE s.user_id = :user_id
         LIMIT 1"
    );
    $statement->execute(['user_id' => $userId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * @return array<string, mixed>|null
 */
function current_lecturer_profile(): ?array
{
    $user = current_user();
    if ($user === null || $user['role'] !== 'LECTURER') {
        return null;
    }

    return get_lecturer_by_user_id($user['user_id']);
}

/**
 * @return array<string, mixed>|null
 */
function current_student_profile(): ?array
{
    $user = current_user();
    if ($user === null || $user['role'] !== 'STUDENT') {
        return null;
    }

    return get_student_by_user_id($user['user_id']);
}

/**
 * @return list<array<string, mixed>>
 */
function list_batches(?int $courseId = null, bool $activeOnly = false): array
{
    $sql = "SELECT b.batch_id, b.course_id, b.batch_name, b.intake_year, b.status,
                   c.course_code, c.course_name
            FROM batches b
            INNER JOIN courses c ON c.course_id = b.course_id
            WHERE 1=1";
    $params = [];

    if ($courseId !== null) {
        $sql .= ' AND b.course_id = :course_id';
        $params['course_id'] = $courseId;
    }

    if ($activeOnly) {
        $sql .= " AND b.status = 'ACTIVE'";
    }

    $sql .= ' ORDER BY c.course_code, b.intake_year DESC, b.batch_name';
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * @return array{exists: bool, nullable: bool}
 */
function modules_legacy_course_id_meta(bool $refresh = false): array
{
    static $meta = null;
    if ($refresh) {
        $meta = null;
    }
    if ($meta !== null) {
        return $meta;
    }

    $statement = db()->query(
        "SELECT IS_NULLABLE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'modules'
           AND COLUMN_NAME = 'course_id'
         LIMIT 1"
    );
    $row = $statement === false ? false : $statement->fetch();
    if ($row === false) {
        $meta = ['exists' => false, 'nullable' => true];
        return $meta;
    }

    $meta = [
        'exists' => true,
        'nullable' => strtoupper((string) $row['IS_NULLABLE']) === 'YES',
    ];

    return $meta;
}

function schema_table_exists(string $table): bool
{
    $statement = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $statement->execute(['table_name' => $table]);

    return (int) $statement->fetchColumn() > 0;
}

function schema_index_exists(string $table, string $indexName): bool
{
    $statement = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND INDEX_NAME = :index_name'
    );
    $statement->execute([
        'table_name' => $table,
        'index_name' => $indexName,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

function schema_constraint_exists(string $table, string $constraintName): bool
{
    $statement = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND CONSTRAINT_NAME = :constraint_name'
    );
    $statement->execute([
        'table_name' => $table,
        'constraint_name' => $constraintName,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

function ensure_course_modules_table(): bool
{
    if (schema_table_exists('course_modules')) {
        backfill_course_modules_from_legacy_course_id();
        return false;
    }

    db()->exec(
        "CREATE TABLE course_modules (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    backfill_course_modules_from_legacy_course_id();

    return true;
}

function backfill_course_modules_from_legacy_course_id(): int
{
    if (!schema_table_exists('course_modules')) {
        return 0;
    }
    $meta = modules_legacy_course_id_meta();
    if (!$meta['exists']) {
        return 0;
    }

    $statement = db()->query(
        "INSERT INTO course_modules (course_id, module_id, status, assigned_at)
         SELECT m.course_id, m.module_id, 'ACTIVE', m.created_at
         FROM modules m
         WHERE m.course_id IS NOT NULL
           AND NOT EXISTS (
             SELECT 1 FROM course_modules cm
             WHERE cm.course_id = m.course_id AND cm.module_id = m.module_id
           )"
    );

    return $statement === false ? 0 : $statement->rowCount();
}

/**
 * @return list<string>
 */
function duplicate_module_codes(): array
{
    $statement = db()->query(
        'SELECT module_code FROM modules GROUP BY module_code HAVING COUNT(*) > 1'
    );
    if ($statement === false) {
        return [];
    }

    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

function ensure_modules_catalogue_ready(): void
{
    $duplicates = duplicate_module_codes();
    if ($duplicates !== []) {
        throw new RuntimeException(
            'Cannot make module_code globally unique; duplicates exist: ' . implode(', ', $duplicates)
        );
    }

    if (!schema_index_exists('modules', 'uq_modules_code')) {
        db()->exec('ALTER TABLE modules ADD UNIQUE KEY uq_modules_code (module_code)');
    }

    $meta = modules_legacy_course_id_meta();
    if ($meta['exists'] && !$meta['nullable']) {
        db()->exec('ALTER TABLE modules MODIFY course_id INT UNSIGNED NULL');
        modules_legacy_course_id_meta(true);
    }
}

function drop_modules_course_id_column(): bool
{
    ensure_course_modules_table();
    backfill_course_modules_from_legacy_course_id();
    ensure_modules_catalogue_ready();

    $meta = modules_legacy_course_id_meta();
    if (!$meta['exists']) {
        if (!schema_index_exists('modules', 'idx_modules_semester')) {
            db()->exec('ALTER TABLE modules ADD KEY idx_modules_semester (semester)');
        }

        return false;
    }

    if (schema_constraint_exists('modules', 'fk_modules_course')) {
        db()->exec('ALTER TABLE modules DROP FOREIGN KEY fk_modules_course');
    }
    if (schema_index_exists('modules', 'uq_modules_course_code')) {
        db()->exec('ALTER TABLE modules DROP INDEX uq_modules_course_code');
    }
    if (schema_index_exists('modules', 'idx_modules_semester')) {
        db()->exec('ALTER TABLE modules DROP INDEX idx_modules_semester');
    }

    db()->exec('ALTER TABLE modules DROP COLUMN course_id');
    modules_legacy_course_id_meta(true);

    if (!schema_index_exists('modules', 'idx_modules_semester')) {
        db()->exec('ALTER TABLE modules ADD KEY idx_modules_semester (semester)');
    }

    return true;
}

function course_has_module(int $courseId, int $moduleId, bool $activeOnly = true): bool
{
    ensure_course_modules_table();
    $sql = 'SELECT course_module_id FROM course_modules
            WHERE course_id = :course_id AND module_id = :module_id';
    if ($activeOnly) {
        $sql .= " AND status = 'ACTIVE'";
    }
    $statement = db()->prepare($sql . ' LIMIT 1');
    $statement->execute([
        'course_id' => $courseId,
        'module_id' => $moduleId,
    ]);

    return $statement->fetch() !== false;
}

/**
 * @return list<int>
 */
function list_active_course_ids_for_module(int $moduleId): array
{
    ensure_course_modules_table();
    $statement = db()->prepare(
        "SELECT course_id FROM course_modules
         WHERE module_id = :module_id AND status = 'ACTIVE'
         ORDER BY course_id"
    );
    $statement->execute(['module_id' => $moduleId]);

    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

function module_is_valid_for_batch_course(int $batchId, int $moduleId): bool
{
    $batch = get_batch($batchId);
    if ($batch === null) {
        return false;
    }

    $module = get_module($moduleId);
    if ($module === null || ($module['status'] ?? '') !== 'ACTIVE') {
        return false;
    }

    return course_has_module((int) $batch['course_id'], $moduleId, true);
}

/**
 * @return list<array<string, mixed>>
 */
function list_course_module_rows(int $courseId): array
{
    ensure_course_modules_table();
    $statement = db()->prepare(
        "SELECT cm.course_module_id, cm.course_id, cm.module_id, cm.status, cm.assigned_at,
                m.module_code, m.module_name, m.credits, m.semester, m.status AS module_status
         FROM course_modules cm
         INNER JOIN modules m ON m.module_id = cm.module_id
         WHERE cm.course_id = :course_id
         ORDER BY m.semester, m.module_code"
    );
    $statement->execute(['course_id' => $courseId]);

    return $statement->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function list_active_course_modules(int $courseId): array
{
    ensure_course_modules_table();
    $statement = db()->prepare(
        "SELECT cm.course_module_id, cm.course_id, cm.module_id, cm.status, cm.assigned_at,
                m.module_code, m.module_name, m.credits, m.semester, m.status AS module_status
         FROM course_modules cm
         INNER JOIN modules m ON m.module_id = cm.module_id
         WHERE cm.course_id = :course_id
           AND cm.status = 'ACTIVE'
           AND m.status = 'ACTIVE'
         ORDER BY m.semester, m.module_code"
    );
    $statement->execute(['course_id' => $courseId]);

    return $statement->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function list_modules_for_course_assignment(int $courseId): array
{
    $catalogue = list_modules(['status' => 'ACTIVE']);
    $assigned = [];
    foreach (list_course_module_rows($courseId) as $row) {
        $assigned[(int) $row['module_id']] = $row;
    }

    $visible = [];
    $seen = [];
    foreach ($catalogue as $module) {
        $moduleId = (int) $module['module_id'];
        $seen[$moduleId] = true;
        $assignment = $assigned[$moduleId] ?? null;
        $module['course_module_id'] = $assignment['course_module_id'] ?? null;
        $module['course_module_status'] = $assignment['status'] ?? null;
        $module['is_assigned'] = $assignment !== null && $assignment['status'] === 'ACTIVE';
        $visible[] = $module;
    }

    foreach ($assigned as $moduleId => $row) {
        if (isset($seen[$moduleId]) || $row['status'] !== 'ACTIVE') {
            continue;
        }
        $module = get_module($moduleId);
        if ($module === null) {
            continue;
        }
        $module['course_module_id'] = $row['course_module_id'];
        $module['course_module_status'] = $row['status'];
        $module['is_assigned'] = true;
        $visible[] = $module;
    }

    return $visible;
}

/**
 * @param list<mixed> $selectedModuleIds
 */
function save_course_module_selection(int $courseId, array $selectedModuleIds): void
{
    ensure_course_modules_table();
    $course = get_course($courseId);
    if ($course === null) {
        throw new InvalidArgumentException('Course not found.');
    }

    $normalized = [];
    foreach ($selectedModuleIds as $rawId) {
        $moduleId = positive_int($rawId);
        if ($moduleId === null) {
            throw new InvalidArgumentException('Select valid modules.');
        }
        $normalized[$moduleId] = $moduleId;
    }
    $selected = array_values($normalized);

    foreach ($selected as $moduleId) {
        $module = get_module($moduleId);
        if ($module === null) {
            throw new InvalidArgumentException('Select a valid module.');
        }
        if ($module['status'] !== 'ACTIVE') {
            throw new InvalidArgumentException('Only active catalogue modules can be assigned to a course.');
        }
    }

    $existing = [];
    foreach (list_course_module_rows($courseId) as $row) {
        $existing[(int) $row['module_id']] = $row;
    }

    $pdo = db();
    $started = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started = true;
    }

    try {
        foreach ($selected as $moduleId) {
            if (!isset($existing[$moduleId])) {
                $insert = $pdo->prepare(
                    "INSERT INTO course_modules (course_id, module_id, status)
                     VALUES (:course_id, :module_id, 'ACTIVE')"
                );
                $insert->execute([
                    'course_id' => $courseId,
                    'module_id' => $moduleId,
                ]);
                continue;
            }

            if ($existing[$moduleId]['status'] !== 'ACTIVE') {
                $update = $pdo->prepare(
                    "UPDATE course_modules SET status = 'ACTIVE' WHERE course_module_id = :id"
                );
                $update->execute(['id' => $existing[$moduleId]['course_module_id']]);
            }
        }

        foreach ($existing as $moduleId => $row) {
            if (!in_array($moduleId, $selected, true) && $row['status'] === 'ACTIVE') {
                $update = $pdo->prepare(
                    "UPDATE course_modules SET status = 'INACTIVE' WHERE course_module_id = :id"
                );
                $update->execute(['id' => $row['course_module_id']]);
            }
        }

        if ($started) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Atomically create a course and assign optional catalogue modules.
 * Reuses create_course() + save_course_module_selection(); rolls back on any failure.
 *
 * @param array{course_code?: mixed, course_name?: mixed, duration_years?: mixed, status?: mixed} $data
 * @param list<mixed> $moduleIds
 * @return array{course_id: int, module_count: int}
 */
function create_course_with_modules(array $data, array $moduleIds = []): array
{
    $pdo = db();
    $started = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started = true;
    }

    try {
        $courseId = create_course($data);
        if ($moduleIds !== []) {
            save_course_module_selection($courseId, $moduleIds);
        }

        $moduleCount = count(list_active_course_modules($courseId));

        if ($started) {
            $pdo->commit();
        }

        return [
            'course_id' => $courseId,
            'module_count' => $moduleCount,
        ];
    } catch (Throwable $exception) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * @param array{search?: string, course_id?: int, status?: string, course_module_status?: string} $filters
 * @return list<array<string, mixed>>
 */
function list_modules(array $filters = []): array
{
    ensure_course_modules_table();
    $sql = "SELECT m.module_id, m.module_code, m.module_name, m.credits, m.semester, m.status,
                   (SELECT COUNT(*) FROM course_modules cmc
                    WHERE cmc.module_id = m.module_id AND cmc.status = 'ACTIVE') AS course_count";
    $params = [];

    if (!empty($filters['course_id'])) {
        $sql .= ", cm.status AS course_module_status, cm.assigned_at
                 FROM modules m
                 INNER JOIN course_modules cm ON cm.module_id = m.module_id AND cm.course_id = :course_id
                 WHERE 1=1";
        $params['course_id'] = (int) $filters['course_id'];
        if (!empty($filters['course_module_status']) && in_array($filters['course_module_status'], ['ACTIVE', 'INACTIVE'], true)) {
            $sql .= ' AND cm.status = :course_module_status';
            $params['course_module_status'] = $filters['course_module_status'];
        }
    } else {
        $sql .= ' FROM modules m WHERE 1=1';
    }

    if (!empty($filters['search'])) {
        $sql .= ' AND (m.module_code LIKE :search OR m.module_name LIKE :search)';
        $params['search'] = '%' . $filters['search'] . '%';
    }

    if (!empty($filters['status']) && in_array($filters['status'], module_statuses(), true)) {
        $sql .= ' AND m.status = :status';
        $params['status'] = $filters['status'];
    }

    $sql .= ' ORDER BY m.semester, m.module_code';
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function get_module(int $moduleId): ?array
{
    ensure_course_modules_table();
    $statement = db()->prepare(
        "SELECT m.module_id, m.module_code, m.module_name, m.credits, m.semester, m.status,
                (SELECT COUNT(*) FROM course_modules cm
                 WHERE cm.module_id = m.module_id AND cm.status = 'ACTIVE') AS course_count
         FROM modules m
         WHERE m.module_id = :module_id
         LIMIT 1"
    );
    $statement->execute(['module_id' => $moduleId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * @param array{module_code: string, module_name: string, credits: mixed, semester: mixed, status: string} $data
 * @return array{module_code: string, module_name: string, credits: float, semester: int, status: string}
 */
function validate_module_payload(array $data, ?int $excludeModuleId = null): array
{
    $code = trim((string) ($data['module_code'] ?? ''));
    $name = trim((string) ($data['module_name'] ?? ''));
    $status = (string) ($data['status'] ?? 'ACTIVE');
    $credits = filter_var($data['credits'] ?? null, FILTER_VALIDATE_FLOAT);
    $semester = filter_var($data['semester'] ?? null, FILTER_VALIDATE_INT);

    if ($code === '' || $name === '') {
        throw new InvalidArgumentException('Module code and name are required.');
    }

    if (mb_strlen($code) > 20) {
        throw new InvalidArgumentException('Module code must be at most 20 characters.');
    }

    if (mb_strlen($name) > 150) {
        throw new InvalidArgumentException('Module name must be at most 150 characters.');
    }

    if ($credits === false || $credits <= 0) {
        throw new InvalidArgumentException('Credits must be greater than 0.');
    }

    if ($semester === false || $semester < 1) {
        throw new InvalidArgumentException('Semester must be 1 or greater.');
    }

    if (!in_array($status, module_statuses(), true)) {
        throw new InvalidArgumentException('Select a valid status.');
    }

    if (module_code_exists($code, $excludeModuleId)) {
        throw new InvalidArgumentException('That module code already exists in the catalogue.');
    }

    return [
        'module_code' => $code,
        'module_name' => $name,
        'credits' => $credits,
        'semester' => $semester,
        'status' => $status,
    ];
}

/**
 * @param array{module_code: string, module_name: string, credits: mixed, semester: mixed, status: string, course_id?: int} $data
 */
function create_module(array $data): int
{
    ensure_course_modules_table();
    ensure_modules_catalogue_ready();
    $payload = validate_module_payload($data);
    $legacyCourseId = positive_int($data['course_id'] ?? null);
    if ($legacyCourseId !== null && get_course($legacyCourseId) === null) {
        throw new InvalidArgumentException('Select a valid course.');
    }

    $meta = modules_legacy_course_id_meta();
    $pdo = db();
    $started = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started = true;
    }

    try {
        if ($meta['exists']) {
            $statement = $pdo->prepare(
                'INSERT INTO modules (course_id, module_code, module_name, credits, semester, status)
                 VALUES (:course_id, :module_code, :module_name, :credits, :semester, :status)'
            );
            $statement->execute([
                'course_id' => $legacyCourseId,
                'module_code' => $payload['module_code'],
                'module_name' => $payload['module_name'],
                'credits' => $payload['credits'],
                'semester' => $payload['semester'],
                'status' => $payload['status'],
            ]);
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO modules (module_code, module_name, credits, semester, status)
                 VALUES (:module_code, :module_name, :credits, :semester, :status)'
            );
            $statement->execute($payload);
        }

        $moduleId = (int) $pdo->lastInsertId();
        if ($legacyCourseId !== null) {
            $link = $pdo->prepare(
                "INSERT INTO course_modules (course_id, module_id, status)
                 VALUES (:course_id, :module_id, 'ACTIVE')"
            );
            $link->execute([
                'course_id' => $legacyCourseId,
                'module_id' => $moduleId,
            ]);
        }

        if ($started) {
            $pdo->commit();
        }

        return $moduleId;
    } catch (Throwable $exception) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * @param array{module_code: string, module_name: string, credits: mixed, semester: mixed, status: string} $data
 */
function update_module(int $moduleId, array $data): void
{
    $existing = get_module($moduleId);
    if ($existing === null) {
        throw new InvalidArgumentException('Module not found.');
    }

    $payload = validate_module_payload($data, $moduleId);
    $statement = db()->prepare(
        'UPDATE modules
         SET module_code = :module_code, module_name = :module_name,
             credits = :credits, semester = :semester, status = :status
         WHERE module_id = :module_id'
    );
    $statement->execute([
        'module_code' => $payload['module_code'],
        'module_name' => $payload['module_name'],
        'credits' => $payload['credits'],
        'semester' => $payload['semester'],
        'status' => $payload['status'],
        'module_id' => $moduleId,
    ]);
}

function module_code_exists(string $moduleCode, ?int $excludeModuleId = null): bool
{
    $sql = 'SELECT module_id FROM modules WHERE module_code = :module_code';
    $params = ['module_code' => $moduleCode];

    if ($excludeModuleId !== null) {
        $sql .= ' AND module_id <> :module_id';
        $params['module_id'] = $excludeModuleId;
    }

    $statement = db()->prepare($sql . ' LIMIT 1');
    $statement->execute($params);

    return $statement->fetch() !== false;
}

/**
 * @return list<array<string, mixed>>
 */
function list_module_lecturer_assignments(?int $moduleId = null, ?int $lecturerId = null): array
{
    $sql = "SELECT ml.module_lecturer_id, ml.module_id, ml.lecturer_id, ml.assigned_at,
                   m.module_code, m.module_name, m.status AS module_status,
                   (SELECT GROUP_CONCAT(c.course_code ORDER BY c.course_code SEPARATOR ', ')
                    FROM course_modules cm
                    INNER JOIN courses c ON c.course_id = cm.course_id
                    WHERE cm.module_id = m.module_id AND cm.status = 'ACTIVE') AS course_code,
                   l.staff_no, l.first_name, l.last_name, l.status AS lecturer_status
            FROM module_lecturers ml
            INNER JOIN modules m ON m.module_id = ml.module_id
            INNER JOIN lecturers l ON l.lecturer_id = ml.lecturer_id
            WHERE 1=1";
    $params = [];

    if ($moduleId !== null) {
        $sql .= ' AND ml.module_id = :module_id';
        $params['module_id'] = $moduleId;
    }

    if ($lecturerId !== null) {
        $sql .= ' AND ml.lecturer_id = :lecturer_id';
        $params['lecturer_id'] = $lecturerId;
    }

    $sql .= ' ORDER BY m.module_code, l.last_name, l.first_name';
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function lecturer_is_assigned_to_module(int $lecturerId, int $moduleId): bool
{
    $statement = db()->prepare(
        'SELECT module_lecturer_id FROM module_lecturers
         WHERE lecturer_id = :lecturer_id AND module_id = :module_id
         LIMIT 1'
    );
    $statement->execute([
        'lecturer_id' => $lecturerId,
        'module_id' => $moduleId,
    ]);

    return $statement->fetch() !== false;
}

function assign_lecturer_to_module(int $moduleId, int $lecturerId): void
{
    $module = get_module($moduleId);
    if ($module === null) {
        throw new InvalidArgumentException('Module not found.');
    }

    $lecturer = get_lecturer($lecturerId);
    if ($lecturer === null) {
        throw new InvalidArgumentException('Lecturer not found.');
    }

    if ($lecturer['status'] !== 'ACTIVE') {
        throw new InvalidArgumentException('Only active lecturers can be assigned to a module.');
    }

    if (lecturer_is_assigned_to_module($lecturerId, $moduleId)) {
        throw new InvalidArgumentException('That lecturer is already assigned to this module.');
    }

    $statement = db()->prepare(
        'INSERT INTO module_lecturers (module_id, lecturer_id) VALUES (:module_id, :lecturer_id)'
    );
    $statement->execute([
        'module_id' => $moduleId,
        'lecturer_id' => $lecturerId,
    ]);
}

function unassign_lecturer_from_module(int $moduleLecturerId): void
{
    $statement = db()->prepare(
        'SELECT ml.module_lecturer_id, ml.module_id, ml.lecturer_id
         FROM module_lecturers ml
         WHERE ml.module_lecturer_id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $moduleLecturerId]);
    $row = $statement->fetch();
    if ($row === false) {
        throw new InvalidArgumentException('Assignment not found.');
    }

    $activeSchedules = db()->prepare(
        "SELECT schedule_id FROM schedules
         WHERE module_id = :module_id AND lecturer_id = :lecturer_id AND status = 'ACTIVE'
         LIMIT 1"
    );
    $activeSchedules->execute([
        'module_id' => $row['module_id'],
        'lecturer_id' => $row['lecturer_id'],
    ]);
    if ($activeSchedules->fetch() !== false) {
        throw new InvalidArgumentException('Deactivate timetable entries for this lecturer and module before removing the assignment.');
    }

    $delete = db()->prepare('DELETE FROM module_lecturers WHERE module_lecturer_id = :id');
    $delete->execute(['id' => $moduleLecturerId]);
}

/**
 * @return array<int, list<array<string, mixed>>>
 */
function lecturers_grouped_by_module(): array
{
    $grouped = [];
    foreach (list_module_lecturer_assignments() as $row) {
        if ($row['lecturer_status'] !== 'ACTIVE') {
            continue;
        }
        $moduleId = (int) $row['module_id'];
        $grouped[$moduleId][] = [
            'lecturer_id' => (int) $row['lecturer_id'],
            'staff_no' => $row['staff_no'],
            'name' => $row['first_name'] . ' ' . $row['last_name'],
        ];
    }

    return $grouped;
}

/**
 * @return list<array<string, mixed>>
 */
function list_student_module_enrolments(int $studentId): array
{
    $statement = db()->prepare(
        "SELECT sm.student_module_id, sm.student_id, sm.module_id, sm.enrolled_at, sm.status,
                m.module_code, m.module_name, m.semester, m.status AS module_status,
                c.course_code
         FROM student_modules sm
         INNER JOIN modules m ON m.module_id = sm.module_id
         INNER JOIN students st ON st.student_id = sm.student_id
         INNER JOIN courses c ON c.course_id = st.course_id
         WHERE sm.student_id = :student_id
         ORDER BY sm.status, m.module_code"
    );
    $statement->execute(['student_id' => $studentId]);

    return $statement->fetchAll();
}

/**
 * Lecturer Student Directory: student is visible only when they share at least one
 * ENROLLED student_modules row with a module assigned to the lecturer.
 */
function lecturer_can_view_student(int $lecturerId, int $studentId): bool
{
    if ($lecturerId <= 0 || $studentId <= 0) {
        return false;
    }

    $statement = db()->prepare(
        "SELECT sm.student_module_id
         FROM student_modules sm
         INNER JOIN module_lecturers ml
           ON ml.module_id = sm.module_id
          AND ml.lecturer_id = :lecturer_id
         INNER JOIN students s ON s.student_id = sm.student_id AND s.status = 'ACTIVE'
         WHERE sm.student_id = :student_id
           AND sm.status = 'ENROLLED'
         LIMIT 1"
    );
    $statement->execute([
        'lecturer_id' => $lecturerId,
        'student_id' => $studentId,
    ]);

    return $statement->fetch() !== false;
}

/**
 * Modules the lecturer teaches that the student is currently ENROLLED in.
 *
 * @return list<array<string, mixed>>
 */
function list_shared_enrolled_modules_for_lecturer_student(int $lecturerId, int $studentId): array
{
    $statement = db()->prepare(
        "SELECT sm.student_module_id, sm.module_id, sm.enrolled_at, sm.status,
                m.module_code, m.module_name, m.semester, m.status AS module_status
         FROM student_modules sm
         INNER JOIN module_lecturers ml
           ON ml.module_id = sm.module_id
          AND ml.lecturer_id = :lecturer_id
         INNER JOIN modules m ON m.module_id = sm.module_id
         WHERE sm.student_id = :student_id
           AND sm.status = 'ENROLLED'
         ORDER BY m.module_code"
    );
    $statement->execute([
        'lecturer_id' => $lecturerId,
        'student_id' => $studentId,
    ]);

    return $statement->fetchAll();
}

/**
 * Students visible in the Lecturer Student Directory (DISTINCT by student).
 *
 * @param array{
 *   search?: string,
 *   course_id?: int,
 *   batch_id?: int,
 *   module_id?: int,
 *   status?: string
 * } $filters
 * @return list<array<string, mixed>>
 */
function list_students_for_lecturer(int $lecturerId, array $filters = []): array
{
    if ($lecturerId <= 0) {
        return [];
    }

    $moduleFilter = !empty($filters['module_id']) ? positive_int($filters['module_id']) : null;
    if ($moduleFilter !== null && !lecturer_is_assigned_to_module($lecturerId, $moduleFilter)) {
        return [];
    }

    $sql = "SELECT s.student_id, s.registration_no, s.first_name, s.last_name, s.phone,
                   s.date_of_birth, s.gender, s.enrollment_date, s.status, s.profile_photo,
                   u.user_id, u.username, u.email, u.status AS account_status,
                   c.course_id, c.course_code, c.course_name,
                   b.batch_id, b.batch_name,
                   CASE
                       WHEN fp.face_profile_id IS NOT NULL AND fp.status = 'ACTIVE' THEN 'ENROLLED'
                       ELSE 'NOT ENROLLED'
                   END AS face_status,
                   GROUP_CONCAT(DISTINCT m.module_code ORDER BY m.module_code SEPARATOR ', ') AS enrolled_modules
            FROM students s
            INNER JOIN users u ON u.user_id = s.user_id
            INNER JOIN courses c ON c.course_id = s.course_id
            INNER JOIN batches b ON b.batch_id = s.batch_id
            INNER JOIN student_modules sm
              ON sm.student_id = s.student_id
             AND sm.status = 'ENROLLED'
            INNER JOIN module_lecturers ml
              ON ml.module_id = sm.module_id
             AND ml.lecturer_id = :lecturer_id
            INNER JOIN modules m ON m.module_id = sm.module_id
            LEFT JOIN face_profiles fp ON fp.student_id = s.student_id AND fp.status = 'ACTIVE'
            WHERE 1=1";
    $params = ['lecturer_id' => $lecturerId];

    if (!empty($filters['search'])) {
        $sql .= ' AND (s.registration_no LIKE :search1 OR s.first_name LIKE :search2 OR s.last_name LIKE :search3 OR u.email LIKE :search4 OR u.username LIKE :search5)';
        $searchTerm = '%' . $filters['search'] . '%';
        $params['search1'] = $searchTerm;
        $params['search2'] = $searchTerm;
        $params['search3'] = $searchTerm;
        $params['search4'] = $searchTerm;
        $params['search5'] = $searchTerm;
    }

    if (!empty($filters['course_id'])) {
        $sql .= ' AND s.course_id = :course_id';
        $params['course_id'] = (int) $filters['course_id'];
    }

    if (!empty($filters['batch_id'])) {
        $sql .= ' AND s.batch_id = :batch_id';
        $params['batch_id'] = (int) $filters['batch_id'];
    }

    if ($moduleFilter !== null) {
        $sql .= ' AND sm.module_id = :module_id';
        $params['module_id'] = $moduleFilter;
    }

    if (!empty($filters['status']) && in_array($filters['status'], student_statuses(), true)) {
        $sql .= ' AND s.status = :status';
        $params['status'] = $filters['status'];
    } else {
        // Directory defaults to ACTIVE students only (inactive remain in Admin/Staff lists).
        $sql .= " AND s.status = 'ACTIVE'";
    }

    $sql .= ' GROUP BY s.student_id, s.registration_no, s.first_name, s.last_name, s.phone,
                       s.date_of_birth, s.gender, s.enrollment_date, s.status, s.profile_photo,
                       u.user_id, u.username, u.email, u.status,
                       c.course_id, c.course_code, c.course_name,
                       b.batch_id, b.batch_name, fp.face_profile_id, fp.status
              ORDER BY s.created_at DESC, s.student_id DESC';

    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * Course options for lecturer directory filters (scoped; cannot widen access).
 *
 * @return list<array<string, mixed>>
 */
function list_courses_for_lecturer_directory(int $lecturerId): array
{
    if ($lecturerId <= 0) {
        return [];
    }

    $statement = db()->prepare(
        "SELECT DISTINCT c.course_id, c.course_code, c.course_name
         FROM courses c
         INNER JOIN students s ON s.course_id = c.course_id
         INNER JOIN student_modules sm
           ON sm.student_id = s.student_id
          AND sm.status = 'ENROLLED'
         INNER JOIN module_lecturers ml
           ON ml.module_id = sm.module_id
          AND ml.lecturer_id = :lecturer_id
         ORDER BY c.course_code"
    );
    $statement->execute(['lecturer_id' => $lecturerId]);

    return $statement->fetchAll();
}

/**
 * Batch options for lecturer directory filters (scoped; cannot widen access).
 *
 * @return list<array<string, mixed>>
 */
function list_batches_for_lecturer_directory(int $lecturerId, ?int $courseId = null): array
{
    if ($lecturerId <= 0) {
        return [];
    }

    $sql = "SELECT DISTINCT b.batch_id, b.batch_name, b.course_id, c.course_code
            FROM batches b
            INNER JOIN courses c ON c.course_id = b.course_id
            INNER JOIN students s ON s.batch_id = b.batch_id
            INNER JOIN student_modules sm
              ON sm.student_id = s.student_id
             AND sm.status = 'ENROLLED'
            INNER JOIN module_lecturers ml
              ON ml.module_id = sm.module_id
             AND ml.lecturer_id = :lecturer_id
            WHERE 1=1";
    $params = ['lecturer_id' => $lecturerId];

    if ($courseId !== null && $courseId > 0) {
        $sql .= ' AND b.course_id = :course_id';
        $params['course_id'] = $courseId;
    }

    $sql .= ' ORDER BY c.course_code, b.batch_name';
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function get_student_module_enrolment(int $studentId, int $moduleId): ?array
{
    $statement = db()->prepare(
        'SELECT student_module_id, student_id, module_id, enrolled_at, status
         FROM student_modules
         WHERE student_id = :student_id AND module_id = :module_id
         LIMIT 1'
    );
    $statement->execute([
        'student_id' => $studentId,
        'module_id' => $moduleId,
    ]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

function enroll_student_in_module(int $studentId, int $moduleId): void
{
    $student = get_student($studentId);
    if ($student === null) {
        throw new InvalidArgumentException('Student not found.');
    }

    $module = get_module($moduleId);
    if ($module === null) {
        throw new InvalidArgumentException('Module not found.');
    }

    if (!course_has_module((int) $student['course_id'], $moduleId, true)) {
        throw new InvalidArgumentException('The module does not belong to the student\'s course.');
    }

    $existing = get_student_module_enrolment($studentId, $moduleId);
    if ($existing !== null) {
        if ($existing['status'] === 'ENROLLED') {
            throw new InvalidArgumentException('The student is already enrolled in this module.');
        }

        $update = db()->prepare(
            "UPDATE student_modules
             SET status = 'ENROLLED', enrolled_at = NOW()
             WHERE student_module_id = :id"
        );
        $update->execute(['id' => $existing['student_module_id']]);

        return;
    }

    $insert = db()->prepare(
        "INSERT INTO student_modules (student_id, module_id, status)
         VALUES (:student_id, :module_id, 'ENROLLED')"
    );
    $insert->execute([
        'student_id' => $studentId,
        'module_id' => $moduleId,
    ]);
}

function drop_student_module(int $studentModuleId, ?int $expectedStudentId = null): void
{
    $sql = 'SELECT student_module_id, student_id, status FROM student_modules WHERE student_module_id = :id';
    $params = ['id' => $studentModuleId];
    if ($expectedStudentId !== null) {
        $sql .= ' AND student_id = :student_id';
        $params['student_id'] = $expectedStudentId;
    }

    $statement = db()->prepare($sql . ' LIMIT 1');
    $statement->execute($params);
    $row = $statement->fetch();
    if ($row === false) {
        throw new InvalidArgumentException('Enrolment not found.');
    }

    if ($row['status'] === 'DROPPED') {
        throw new InvalidArgumentException('That enrolment is already dropped.');
    }

    $update = db()->prepare(
        "UPDATE student_modules SET status = 'DROPPED' WHERE student_module_id = :id AND student_id = :student_id"
    );
    $update->execute([
        'id' => $studentModuleId,
        'student_id' => (int) $row['student_id'],
    ]);
}

/**
 * @return array{enrolled: int, reactivated: int, skipped: int}
 */
function bulk_enroll_batch_in_module(int $batchId, int $moduleId): array
{
    $batch = get_batch($batchId);
    if ($batch === null) {
        throw new InvalidArgumentException('Batch not found.');
    }

    $module = get_module($moduleId);
    if ($module === null) {
        throw new InvalidArgumentException('Module not found.');
    }

    if (!course_has_module((int) $batch['course_id'], $moduleId, true)) {
        throw new InvalidArgumentException('The module and batch must belong to the same course.');
    }

    $students = db()->prepare(
        "SELECT student_id FROM students WHERE batch_id = :batch_id AND status = 'ACTIVE'"
    );
    $students->execute(['batch_id' => $batchId]);
    $studentIds = $students->fetchAll(PDO::FETCH_COLUMN);

    $enrolled = 0;
    $reactivated = 0;
    $skipped = 0;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($studentIds as $studentId) {
            $existing = get_student_module_enrolment((int) $studentId, $moduleId);
            if ($existing === null) {
                $insert = $pdo->prepare(
                    "INSERT INTO student_modules (student_id, module_id, status)
                     VALUES (:student_id, :module_id, 'ENROLLED')"
                );
                $insert->execute([
                    'student_id' => (int) $studentId,
                    'module_id' => $moduleId,
                ]);
                $enrolled++;
                continue;
            }

            if ($existing['status'] === 'ENROLLED') {
                $skipped++;
                continue;
            }

            $update = $pdo->prepare(
                "UPDATE student_modules SET status = 'ENROLLED', enrolled_at = NOW()
                 WHERE student_module_id = :id"
            );
            $update->execute(['id' => $existing['student_module_id']]);
            $reactivated++;
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return [
        'enrolled' => $enrolled,
        'reactivated' => $reactivated,
        'skipped' => $skipped,
    ];
}

function ensure_batch_modules_table(): bool
{
    $pdo = db();
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'batch_modules'"
    );
    if ($exists !== false && (int) $exists->fetchColumn() > 0) {
        return false;
    }

    $pdo->exec(
        "CREATE TABLE batch_modules (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    return true;
}

/**
 * @return list<array<string, mixed>>
 */
function list_batch_module_rows(int $batchId): array
{
    ensure_batch_modules_table();
    $statement = db()->prepare(
        "SELECT bm.batch_module_id, bm.batch_id, bm.module_id, bm.status, bm.assigned_at,
                m.module_code, m.module_name, m.status AS module_status, m.semester
         FROM batch_modules bm
         INNER JOIN modules m ON m.module_id = bm.module_id
         WHERE bm.batch_id = :batch_id
         ORDER BY m.semester, m.module_code"
    );
    $statement->execute(['batch_id' => $batchId]);

    return $statement->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function list_active_assigned_batch_modules(int $batchId): array
{
    ensure_batch_modules_table();
    ensure_course_modules_table();
    $statement = db()->prepare(
        "SELECT bm.batch_module_id, bm.batch_id, bm.module_id, bm.status,
                m.module_code, m.module_name, m.status AS module_status
         FROM batch_modules bm
         INNER JOIN modules m ON m.module_id = bm.module_id
         INNER JOIN batches b ON b.batch_id = bm.batch_id
         INNER JOIN course_modules cm
            ON cm.module_id = bm.module_id
           AND cm.course_id = b.course_id
           AND cm.status = 'ACTIVE'
         WHERE bm.batch_id = :batch_id
           AND bm.status = 'ACTIVE'
           AND m.status = 'ACTIVE'
         ORDER BY m.module_code"
    );
    $statement->execute(['batch_id' => $batchId]);

    return $statement->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function list_modules_for_batch_assignment(int $batchId): array
{
    $batch = get_batch($batchId);
    if ($batch === null) {
        return [];
    }

    $courseModules = list_active_course_modules((int) $batch['course_id']);
    $assigned = [];
    foreach (list_batch_module_rows($batchId) as $row) {
        $assigned[(int) $row['module_id']] = $row;
    }

    $visible = [];
    foreach ($courseModules as $module) {
        $moduleId = (int) $module['module_id'];
        $assignment = $assigned[$moduleId] ?? null;
        $isAssignedActive = $assignment !== null && $assignment['status'] === 'ACTIVE';
        $module['batch_module_id'] = $assignment['batch_module_id'] ?? null;
        $module['batch_module_status'] = $assignment['status'] ?? null;
        $module['is_assigned'] = $isAssignedActive;
        $module['can_assign'] = $module['module_status'] === 'ACTIVE';
        $module['status'] = $module['module_status'];
        $visible[] = $module;
    }

    foreach ($assigned as $moduleId => $assignment) {
        if ($assignment['status'] !== 'ACTIVE') {
            continue;
        }
        $already = false;
        foreach ($visible as $row) {
            if ((int) $row['module_id'] === $moduleId) {
                $already = true;
                break;
            }
        }
        if ($already) {
            continue;
        }
        $module = get_module($moduleId);
        if ($module === null) {
            continue;
        }
        $module['batch_module_id'] = $assignment['batch_module_id'];
        $module['batch_module_status'] = $assignment['status'];
        $module['is_assigned'] = true;
        $module['can_assign'] = $module['status'] === 'ACTIVE';
        $module['module_status'] = $module['status'];
        $visible[] = $module;
    }

    return $visible;
}

/**
 * @return list<array<string, mixed>>
 */
function list_active_modules_for_batch(int $batchId): array
{
    $batch = get_batch($batchId);
    if ($batch === null) {
        return [];
    }

    return list_active_course_modules((int) $batch['course_id']);
}

/**
 * @param list<mixed> $selectedModuleIds
 */
function save_batch_module_selection(int $batchId, array $selectedModuleIds): void
{
    ensure_batch_modules_table();
    $batch = get_batch($batchId);
    if ($batch === null) {
        throw new InvalidArgumentException('Batch not found.');
    }

    $courseId = (int) $batch['course_id'];
    $normalized = [];
    foreach ($selectedModuleIds as $rawId) {
        $moduleId = positive_int($rawId);
        if ($moduleId === null) {
            throw new InvalidArgumentException('Select valid modules.');
        }
        $normalized[$moduleId] = $moduleId;
    }
    $selected = array_values($normalized);

    foreach ($selected as $moduleId) {
        $module = get_module($moduleId);
        if ($module === null) {
            throw new InvalidArgumentException('Select a valid module.');
        }
        if (!course_has_module($courseId, $moduleId, true)) {
            throw new InvalidArgumentException('Modules must belong to the same course as the batch.');
        }
        if ($module['status'] !== 'ACTIVE') {
            throw new InvalidArgumentException('Only active modules can be assigned to a batch.');
        }
    }

    $existing = [];
    foreach (list_batch_module_rows($batchId) as $row) {
        $existing[(int) $row['module_id']] = $row;
    }

    $pdo = db();
    $started = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started = true;
    }

    try {
        foreach ($selected as $moduleId) {
            if (!isset($existing[$moduleId])) {
                $insert = $pdo->prepare(
                    "INSERT INTO batch_modules (batch_id, module_id, status)
                     VALUES (:batch_id, :module_id, 'ACTIVE')"
                );
                $insert->execute([
                    'batch_id' => $batchId,
                    'module_id' => $moduleId,
                ]);
                continue;
            }

            if ($existing[$moduleId]['status'] !== 'ACTIVE') {
                $update = $pdo->prepare(
                    "UPDATE batch_modules SET status = 'ACTIVE' WHERE batch_module_id = :id"
                );
                $update->execute(['id' => $existing[$moduleId]['batch_module_id']]);
            }
        }

        foreach ($existing as $moduleId => $row) {
            if (!in_array($moduleId, $selected, true) && $row['status'] === 'ACTIVE') {
                $update = $pdo->prepare(
                    "UPDATE batch_modules SET status = 'INACTIVE' WHERE batch_module_id = :id"
                );
                $update->execute(['id' => $row['batch_module_id']]);
            }
        }

        if ($started) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * @return 'inserted'|'reactivated'|'skipped'
 */
function apply_student_module_enrolment(PDO $pdo, int $studentId, int $moduleId, bool $reactivateExisting): string
{
    $existing = get_student_module_enrolment($studentId, $moduleId);
    if ($existing === null) {
        $insert = $pdo->prepare(
            "INSERT INTO student_modules (student_id, module_id, status)
             VALUES (:student_id, :module_id, 'ENROLLED')"
        );
        $insert->execute([
            'student_id' => $studentId,
            'module_id' => $moduleId,
        ]);

        return 'inserted';
    }

    if ($existing['status'] === 'ENROLLED') {
        return 'skipped';
    }

    if (!$reactivateExisting) {
        return 'skipped';
    }

    $update = $pdo->prepare(
        "UPDATE student_modules
         SET status = 'ENROLLED', enrolled_at = NOW()
         WHERE student_module_id = :id"
    );
    $update->execute(['id' => $existing['student_module_id']]);

    return 'reactivated';
}

function student_may_auto_enrol_in_batch_modules(array $student): bool
{
    if (($student['status'] ?? '') !== 'ACTIVE') {
        return false;
    }

    $course = get_course((int) $student['course_id']);
    $batch = get_batch((int) $student['batch_id']);
    if ($course === null || $batch === null) {
        return false;
    }

    return ($course['status'] ?? '') === 'ACTIVE' && ($batch['status'] ?? '') === 'ACTIVE';
}

function auto_enrol_student_into_batch_modules(int $studentId, ?PDO $connection = null): int
{
    ensure_batch_modules_table();
    $pdo = $connection ?? db();
    $studentStatement = $pdo->prepare(
        'SELECT student_id, course_id, batch_id, status
         FROM students
         WHERE student_id = :student_id
         LIMIT 1'
    );
    $studentStatement->execute(['student_id' => $studentId]);
    $student = $studentStatement->fetch();
    if ($student === false || !student_may_auto_enrol_in_batch_modules($student)) {
        return 0;
    }

    $modules = list_active_assigned_batch_modules((int) $student['batch_id']);
    $started = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started = true;
    }

    try {
        $inserted = 0;
        foreach ($modules as $module) {
            if (!course_has_module((int) $student['course_id'], (int) $module['module_id'], true)) {
                continue;
            }
            $result = apply_student_module_enrolment($pdo, $studentId, (int) $module['module_id'], false);
            if ($result === 'inserted') {
                $inserted++;
            }
        }
        if ($started) {
            $pdo->commit();
        }

        return $inserted;
    } catch (Throwable $exception) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * @return array{students_checked: int, modules_assigned: int, enrolments_added: int, enrolments_reactivated: int}
 */
function sync_batch_module_enrolments(int $batchId): array
{
    ensure_batch_modules_table();
    $batch = get_batch($batchId);
    if ($batch === null) {
        throw new InvalidArgumentException('Batch not found.');
    }

    $course = get_course((int) $batch['course_id']);
    if ($course === null || ($course['status'] ?? '') !== 'ACTIVE' || ($batch['status'] ?? '') !== 'ACTIVE') {
        throw new InvalidArgumentException('Sync requires an active course and an active batch.');
    }

    $modules = list_active_assigned_batch_modules($batchId);
    $students = db()->prepare(
        "SELECT student_id FROM students WHERE batch_id = :batch_id AND status = 'ACTIVE'"
    );
    $students->execute(['batch_id' => $batchId]);
    $studentIds = $students->fetchAll(PDO::FETCH_COLUMN);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $added = 0;
        $reactivated = 0;
        foreach ($studentIds as $studentId) {
            $student = get_student((int) $studentId);
            if ($student === null || !student_may_auto_enrol_in_batch_modules($student)) {
                continue;
            }
            foreach ($modules as $module) {
                if (!course_has_module((int) $student['course_id'], (int) $module['module_id'], true)) {
                    continue;
                }
                $result = apply_student_module_enrolment($pdo, (int) $studentId, (int) $module['module_id'], true);
                if ($result === 'inserted') {
                    $added++;
                } elseif ($result === 'reactivated') {
                    $reactivated++;
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return [
        'students_checked' => count($studentIds),
        'modules_assigned' => count($modules),
        'enrolments_added' => $added,
        'enrolments_reactivated' => $reactivated,
    ];
}

/**
 * @param array{module_id?: int, lecturer_id?: int, batch_id?: int, day_of_week?: string, status?: string} $filters
 * @return list<array<string, mixed>>
 */
function list_schedules(array $filters = []): array
{
    $sql = "SELECT s.schedule_id, s.module_id, s.lecturer_id, s.batch_id, s.day_of_week,
                   s.start_time, s.end_time, s.break_start, s.break_end, s.room, s.status,
                   m.module_code, m.module_name,
                   l.staff_no, l.first_name AS lecturer_first_name, l.last_name AS lecturer_last_name,
                   b.batch_name, c.course_code, c.course_name
            FROM schedules s
            INNER JOIN modules m ON m.module_id = s.module_id
            INNER JOIN lecturers l ON l.lecturer_id = s.lecturer_id
            INNER JOIN batches b ON b.batch_id = s.batch_id
            INNER JOIN courses c ON c.course_id = b.course_id
            WHERE 1=1";
    $params = [];

    if (!empty($filters['module_id'])) {
        $sql .= ' AND s.module_id = :module_id';
        $params['module_id'] = $filters['module_id'];
    }

    if (!empty($filters['lecturer_id'])) {
        $sql .= ' AND s.lecturer_id = :lecturer_id';
        $params['lecturer_id'] = $filters['lecturer_id'];
    }

    if (!empty($filters['batch_id'])) {
        $sql .= ' AND s.batch_id = :batch_id';
        $params['batch_id'] = $filters['batch_id'];
    }

    if (!empty($filters['day_of_week']) && in_array($filters['day_of_week'], weekdays(), true)) {
        $sql .= ' AND s.day_of_week = :day_of_week';
        $params['day_of_week'] = $filters['day_of_week'];
    }

    if (!empty($filters['status']) && in_array($filters['status'], schedule_statuses(), true)) {
        $sql .= ' AND s.status = :status';
        $params['status'] = $filters['status'];
    }

    $sql .= ' ORDER BY FIELD(s.day_of_week, \'MONDAY\',\'TUESDAY\',\'WEDNESDAY\',\'THURSDAY\',\'FRIDAY\',\'SATURDAY\',\'SUNDAY\'), s.start_time, m.module_code';
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function get_schedule(int $scheduleId): ?array
{
    $statement = db()->prepare(
        "SELECT s.schedule_id, s.module_id, s.lecturer_id, s.batch_id, s.day_of_week,
                s.start_time, s.end_time, s.break_start, s.break_end, s.room, s.status,
                m.module_code, m.module_name,
                l.staff_no, l.first_name AS lecturer_first_name, l.last_name AS lecturer_last_name,
                b.batch_name, c.course_code, c.course_name
         FROM schedules s
         INNER JOIN modules m ON m.module_id = s.module_id
         INNER JOIN lecturers l ON l.lecturer_id = s.lecturer_id
         INNER JOIN batches b ON b.batch_id = s.batch_id
         INNER JOIN courses c ON c.course_id = b.course_id
         WHERE s.schedule_id = :schedule_id
         LIMIT 1"
    );
    $statement->execute(['schedule_id' => $scheduleId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * @return list<string>
 */
function validate_schedule_payload(array $data, ?int $excludeScheduleId = null): array
{
    $errors = [];
    $moduleId = (int) ($data['module_id'] ?? 0);
    $lecturerId = (int) ($data['lecturer_id'] ?? 0);
    $batchId = (int) ($data['batch_id'] ?? 0);
    $day = (string) ($data['day_of_week'] ?? '');
    $start = normalize_time_hm((string) ($data['start_time'] ?? ''));
    $end = normalize_time_hm((string) ($data['end_time'] ?? ''));
    $status = (string) ($data['status'] ?? 'ACTIVE');
    $room = trim((string) ($data['room'] ?? ''));

    $module = $moduleId > 0 ? get_module($moduleId) : null;
    $lecturer = $lecturerId > 0 ? get_lecturer($lecturerId) : null;
    $batch = $batchId > 0 ? get_batch($batchId) : null;

    if ($module === null) {
        $errors[] = 'Select a valid module.';
    }

    if ($lecturer === null) {
        $errors[] = 'Select a valid lecturer.';
    }

    if ($batch === null) {
        $errors[] = 'Select a valid batch.';
    }

    if (!in_array($day, weekdays(), true)) {
        $errors[] = 'Select a valid day of the week.';
    }

    if (!validate_time_hm($start) || !validate_time_hm($end)) {
        $errors[] = 'Enter valid start and end times.';
    } elseif ($end <= $start) {
        $errors[] = 'End time must be after start time.';
    } else {
        $errors = array_merge(
            $errors,
            validate_break_times($start, $end, $data['break_start'] ?? null, $data['break_end'] ?? null)
        );
    }

    if (!in_array($status, schedule_statuses(), true)) {
        $errors[] = 'Select a valid timetable status.';
    }

    if ($module !== null && $batch !== null && !course_has_module((int) $batch['course_id'], $moduleId, true)) {
        $errors[] = 'The module and batch must belong to the same course.';
    }

    if ($module !== null && $lecturer !== null && !lecturer_is_assigned_to_module($lecturerId, $moduleId)) {
        $errors[] = 'The lecturer must be assigned to the selected module.';
    }

    if ($errors !== []) {
        return $errors;
    }

    $duplicate = db()->prepare(
        'SELECT schedule_id FROM schedules
         WHERE module_id = :module_id AND batch_id = :batch_id
           AND day_of_week = :day_of_week AND start_time = :start_time
           AND (:exclude_id = 0 OR schedule_id <> :exclude_id2)
         LIMIT 1'
    );
    $duplicate->execute([
        'module_id' => $moduleId,
        'batch_id' => $batchId,
        'day_of_week' => $day,
        'start_time' => $start,
        'exclude_id' => $excludeScheduleId ?? 0,
        'exclude_id2' => $excludeScheduleId ?? 0,
    ]);
    if ($duplicate->fetch() !== false) {
        $errors[] = 'A timetable entry already exists for this module, batch, day, and start time.';
    }

    $lecturerConflict = db()->prepare(
        "SELECT schedule_id FROM schedules
         WHERE lecturer_id = :lecturer_id AND day_of_week = :day_of_week AND status = 'ACTIVE'
           AND start_time < :end_time AND end_time > :start_time
           AND (:exclude_id = 0 OR schedule_id <> :exclude_id2)
         LIMIT 1"
    );
    $lecturerConflict->execute([
        'lecturer_id' => $lecturerId,
        'day_of_week' => $day,
        'start_time' => $start,
        'end_time' => $end,
        'exclude_id' => $excludeScheduleId ?? 0,
        'exclude_id2' => $excludeScheduleId ?? 0,
    ]);
    if ($status === 'ACTIVE' && $lecturerConflict->fetch() !== false) {
        $errors[] = 'That lecturer already has an overlapping active timetable slot on this day.';
    }

    if ($room !== '') {
        $roomConflict = db()->prepare(
            "SELECT schedule_id FROM schedules
             WHERE room = :room AND day_of_week = :day_of_week AND status = 'ACTIVE'
               AND start_time < :end_time AND end_time > :start_time
               AND (:exclude_id = 0 OR schedule_id <> :exclude_id2)
             LIMIT 1"
        );
        $roomConflict->execute([
            'room' => $room,
            'day_of_week' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'exclude_id' => $excludeScheduleId ?? 0,
            'exclude_id2' => $excludeScheduleId ?? 0,
        ]);
        if ($status === 'ACTIVE' && $roomConflict->fetch() !== false) {
            $errors[] = 'That room already has an overlapping active timetable slot on this day.';
        }
    }

    return $errors;
}

/**
 * @param array<string, mixed> $data
 */
function create_schedule(array $data): int
{
    $errors = validate_schedule_payload($data);
    if ($errors !== []) {
        throw new InvalidArgumentException($errors[0]);
    }

    $statement = db()->prepare(
        "INSERT INTO schedules (module_id, lecturer_id, batch_id, day_of_week, start_time, end_time, break_start, break_end, room, status)
         VALUES (:module_id, :lecturer_id, :batch_id, :day_of_week, :start_time, :end_time, :break_start, :break_end, :room, :status)"
    );
    $room = trim((string) ($data['room'] ?? ''));
    $statement->execute([
        'module_id' => $data['module_id'],
        'lecturer_id' => $data['lecturer_id'],
        'batch_id' => $data['batch_id'],
        'day_of_week' => $data['day_of_week'],
        'start_time' => normalize_time_hm((string) $data['start_time']),
        'end_time' => normalize_time_hm((string) $data['end_time']),
        'break_start' => optional_time_hm(isset($data['break_start']) ? (string) $data['break_start'] : null),
        'break_end' => optional_time_hm(isset($data['break_end']) ? (string) $data['break_end'] : null),
        'room' => $room === '' ? null : $room,
        'status' => $data['status'],
    ]);

    return (int) db()->lastInsertId();
}

/**
 * @param array<string, mixed> $data
 */
function update_schedule(int $scheduleId, array $data): void
{
    $errors = validate_schedule_payload($data, $scheduleId);
    if ($errors !== []) {
        throw new InvalidArgumentException($errors[0]);
    }

    $room = trim((string) ($data['room'] ?? ''));
    $statement = db()->prepare(
        "UPDATE schedules
         SET module_id = :module_id, lecturer_id = :lecturer_id, batch_id = :batch_id,
             day_of_week = :day_of_week, start_time = :start_time, end_time = :end_time,
             break_start = :break_start, break_end = :break_end,
             room = :room, status = :status
         WHERE schedule_id = :schedule_id"
    );
    $statement->execute([
        'module_id' => $data['module_id'],
        'lecturer_id' => $data['lecturer_id'],
        'batch_id' => $data['batch_id'],
        'day_of_week' => $data['day_of_week'],
        'start_time' => normalize_time_hm((string) $data['start_time']),
        'end_time' => normalize_time_hm((string) $data['end_time']),
        'break_start' => optional_time_hm(isset($data['break_start']) ? (string) $data['break_start'] : null),
        'break_end' => optional_time_hm(isset($data['break_end']) ? (string) $data['break_end'] : null),
        'room' => $room === '' ? null : $room,
        'status' => $data['status'],
        'schedule_id' => $scheduleId,
    ]);
}

function set_schedule_status(int $scheduleId, string $status): void
{
    if (!in_array($status, schedule_statuses(), true)) {
        throw new InvalidArgumentException('Invalid timetable status.');
    }

    $schedule = get_schedule($scheduleId);
    if ($schedule === null) {
        throw new InvalidArgumentException('Timetable entry not found.');
    }

    if ($status === 'ACTIVE') {
        $errors = validate_schedule_payload([
            'module_id' => $schedule['module_id'],
            'lecturer_id' => $schedule['lecturer_id'],
            'batch_id' => $schedule['batch_id'],
            'day_of_week' => $schedule['day_of_week'],
            'start_time' => $schedule['start_time'],
            'end_time' => $schedule['end_time'],
            'break_start' => $schedule['break_start'] ?? null,
            'break_end' => $schedule['break_end'] ?? null,
            'room' => $schedule['room'] ?? '',
            'status' => 'ACTIVE',
        ], $scheduleId);
        if ($errors !== []) {
            throw new InvalidArgumentException($errors[0]);
        }
    }

    $statement = db()->prepare('UPDATE schedules SET status = :status WHERE schedule_id = :schedule_id');
    $statement->execute([
        'status' => $status,
        'schedule_id' => $scheduleId,
    ]);
}

/**
 * @param array{from?: string, to?: string, status?: string, lecturer_id?: int, batch_id?: int, module_id?: int, upcoming?: bool} $filters
 * @return list<array<string, mixed>>
 */
function list_lecture_sessions(array $filters = []): array
{
    if (!db()->inTransaction()) {
        sync_scheduled_session_states();
    }

    $sql = "SELECT ls.session_id, ls.module_id, ls.lecturer_id, ls.batch_id, ls.schedule_id,
                   ls.session_date, ls.scheduled_start, ls.scheduled_end, ls.break_start, ls.break_end,
                   ls.actual_start, ls.actual_end,
                   ls.room, ls.late_after_minutes, ls.status,
                   m.module_code, m.module_name, m.status AS module_status,
                   l.staff_no, l.first_name AS lecturer_first_name, l.last_name AS lecturer_last_name,
                   b.batch_name, c.course_code, c.course_name
            FROM lecture_sessions ls
            INNER JOIN modules m ON m.module_id = ls.module_id
            INNER JOIN lecturers l ON l.lecturer_id = ls.lecturer_id
            INNER JOIN batches b ON b.batch_id = ls.batch_id
            INNER JOIN courses c ON c.course_id = b.course_id
            WHERE 1=1";
    $params = [];

    if (!empty($filters['from'])) {
        $sql .= ' AND ls.session_date >= :date_from';
        $params['date_from'] = $filters['from'];
    }

    if (!empty($filters['to'])) {
        $sql .= ' AND ls.session_date <= :date_to';
        $params['date_to'] = $filters['to'];
    }

    if (!empty($filters['status']) && in_array($filters['status'], lecture_session_statuses(), true)) {
        $sql .= ' AND ls.status = :status';
        $params['status'] = $filters['status'];
    }

    if (!empty($filters['lecturer_id'])) {
        $sql .= ' AND ls.lecturer_id = :lecturer_id';
        $params['lecturer_id'] = $filters['lecturer_id'];
    }

    if (!empty($filters['batch_id'])) {
        $sql .= ' AND ls.batch_id = :batch_id';
        $params['batch_id'] = $filters['batch_id'];
    }

    if (!empty($filters['module_id'])) {
        $sql .= ' AND ls.module_id = :module_id';
        $params['module_id'] = $filters['module_id'];
    }

    if (!empty($filters['course_id'])) {
        // Course scope via batch → course (Module Catalogue / course_modules), not modules.course_id.
        $sql .= ' AND b.course_id = :course_id';
        $params['course_id'] = (int) $filters['course_id'];
    }

    if (!empty($filters['upcoming'])) {
        $sql .= " AND ls.session_date >= :today AND ls.status IN ('SCHEDULED', 'IN_PROGRESS')";
        $params['today'] = app_today();
    }

    $sql .= ' ORDER BY ls.session_date, ls.scheduled_start, m.module_code';
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function get_lecture_session(int $sessionId): ?array
{
    if (!db()->inTransaction()) {
        sync_scheduled_session_states();
    }

    $statement = db()->prepare(
        "SELECT ls.session_id, ls.module_id, ls.lecturer_id, ls.batch_id, ls.schedule_id,
                ls.session_date, ls.scheduled_start, ls.scheduled_end, ls.break_start, ls.break_end,
                ls.actual_start, ls.actual_end,
                ls.room, ls.late_after_minutes, ls.status,
                m.module_code, m.module_name, m.status AS module_status,
                l.staff_no, l.first_name AS lecturer_first_name, l.last_name AS lecturer_last_name,
                b.batch_name, c.course_code, c.course_name
         FROM lecture_sessions ls
         INNER JOIN modules m ON m.module_id = ls.module_id
         INNER JOIN lecturers l ON l.lecturer_id = ls.lecturer_id
         INNER JOIN batches b ON b.batch_id = ls.batch_id
         INNER JOIN courses c ON c.course_id = b.course_id
         WHERE ls.session_id = :session_id
         LIMIT 1"
    );
    $statement->execute(['session_id' => $sessionId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

function late_threshold_time(string $scheduledStart, int $lateAfterMinutes): string
{
    $start = DateTimeImmutable::createFromFormat('H:i:s', strlen($scheduledStart) === 5 ? $scheduledStart . ':00' : $scheduledStart)
        ?: DateTimeImmutable::createFromFormat('H:i', normalize_time_hm($scheduledStart));
    if ($start === false) {
        return format_time_display($scheduledStart);
    }

    return $start->modify('+' . max(0, $lateAfterMinutes) . ' minutes')->format('H:i');
}

function session_already_exists(int $moduleId, int $batchId, string $date, string $start, ?int $excludeSessionId = null): bool
{
    $sql = 'SELECT session_id FROM lecture_sessions
            WHERE module_id = :module_id AND batch_id = :batch_id
              AND session_date = :session_date AND scheduled_start = :scheduled_start';
    $params = [
        'module_id' => $moduleId,
        'batch_id' => $batchId,
        'session_date' => $date,
        'scheduled_start' => normalize_time_hm($start),
    ];
    if ($excludeSessionId !== null) {
        $sql .= ' AND session_id <> :session_id';
        $params['session_id'] = $excludeSessionId;
    }
    $statement = db()->prepare($sql . ' LIMIT 1');
    $statement->execute($params);

    return $statement->fetch() !== false;
}

/**
 * @param array<string, mixed> $data
 */
function create_lecture_session(array $data): int
{
    $moduleId = (int) $data['module_id'];
    $lecturerId = (int) $data['lecturer_id'];
    $batchId = (int) $data['batch_id'];
    $date = (string) $data['session_date'];
    $start = normalize_time_hm((string) $data['scheduled_start']);
    $end = normalize_time_hm((string) $data['scheduled_end']);
    $lateAfter = (int) $data['late_after_minutes'];
    $room = trim((string) ($data['room'] ?? ''));
    $scheduleId = isset($data['schedule_id']) ? positive_int($data['schedule_id']) : null;

    $module = get_module($moduleId);
    $lecturer = get_lecturer($lecturerId);
    $batch = get_batch($batchId);

    if ($module === null || $lecturer === null || $batch === null) {
        throw new InvalidArgumentException('Module, lecturer, and batch are required.');
    }

    if (!validate_date_ymd($date)) {
        throw new InvalidArgumentException('Enter a valid session date.');
    }

    if (!validate_time_hm($start) || !validate_time_hm($end) || $end <= $start) {
        throw new InvalidArgumentException('End time must be after start time.');
    }

    if ($lateAfter < 0 || $lateAfter > 180) {
        throw new InvalidArgumentException('Late after minutes must be between 0 and 180.');
    }

    $breakErrors = validate_break_times($start, $end, $data['break_start'] ?? null, $data['break_end'] ?? null);
    if ($breakErrors !== []) {
        throw new InvalidArgumentException($breakErrors[0]);
    }

    if (!course_has_module((int) $batch['course_id'], $moduleId, true)) {
        throw new InvalidArgumentException('The module and batch must belong to the same course.');
    }

    if (!lecturer_is_assigned_to_module($lecturerId, $moduleId)) {
        throw new InvalidArgumentException('The lecturer must be assigned to the selected module.');
    }

    if (session_already_exists($moduleId, $batchId, $date, $start)) {
        throw new InvalidArgumentException('A lecture session already exists for this module, batch, date, and start time.');
    }

    $statement = db()->prepare(
        "INSERT INTO lecture_sessions
            (module_id, lecturer_id, batch_id, schedule_id, session_date, scheduled_start, scheduled_end, break_start, break_end, room, late_after_minutes, status)
         VALUES
            (:module_id, :lecturer_id, :batch_id, :schedule_id, :session_date, :scheduled_start, :scheduled_end, :break_start, :break_end, :room, :late_after_minutes, 'SCHEDULED')"
    );
    $statement->execute([
        'module_id' => $moduleId,
        'lecturer_id' => $lecturerId,
        'batch_id' => $batchId,
        'schedule_id' => $scheduleId,
        'session_date' => $date,
        'scheduled_start' => $start,
        'scheduled_end' => $end,
        'break_start' => optional_time_hm(isset($data['break_start']) ? (string) $data['break_start'] : null),
        'break_end' => optional_time_hm(isset($data['break_end']) ? (string) $data['break_end'] : null),
        'room' => $room === '' ? null : $room,
        'late_after_minutes' => $lateAfter,
    ]);

    return (int) db()->lastInsertId();
}

/**
 * @return array{created: int, skipped: int}
 */
function generate_sessions_for_week(string $weekDate, int $lateAfterMinutes = 15): array
{
    $monday = monday_of_week($weekDate);
    if ($monday === null) {
        throw new InvalidArgumentException('Enter a valid date in the week to generate.');
    }

    if ($lateAfterMinutes < 0 || $lateAfterMinutes > 180) {
        throw new InvalidArgumentException('Late after minutes must be between 0 and 180.');
    }

    $schedules = list_schedules(['status' => 'ACTIVE']);
    $created = 0;
    $skipped = 0;
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $mondayDate = new DateTimeImmutable($monday, new DateTimeZone(APP_TIMEZONE));
        foreach ($schedules as $schedule) {
            $offset = weekday_offset((string) $schedule['day_of_week']);
            if ($offset === null) {
                $skipped++;
                continue;
            }

            $sessionDate = $mondayDate->modify('+' . $offset . ' days')->format('Y-m-d');
            if (session_already_exists((int) $schedule['module_id'], (int) $schedule['batch_id'], $sessionDate, (string) $schedule['start_time'])) {
                $skipped++;
                continue;
            }

            $insert = $pdo->prepare(
                "INSERT INTO lecture_sessions
                    (module_id, lecturer_id, batch_id, schedule_id, session_date, scheduled_start, scheduled_end, break_start, break_end, room, late_after_minutes, status)
                 VALUES
                    (:module_id, :lecturer_id, :batch_id, :schedule_id, :session_date, :scheduled_start, :scheduled_end, :break_start, :break_end, :room, :late_after_minutes, 'SCHEDULED')"
            );
            $insert->execute([
                'module_id' => $schedule['module_id'],
                'lecturer_id' => $schedule['lecturer_id'],
                'batch_id' => $schedule['batch_id'],
                'schedule_id' => $schedule['schedule_id'],
                'session_date' => $sessionDate,
                'scheduled_start' => normalize_time_hm((string) $schedule['start_time']),
                'scheduled_end' => normalize_time_hm((string) $schedule['end_time']),
                'break_start' => optional_time_hm(isset($schedule['break_start']) ? (string) $schedule['break_start'] : null),
                'break_end' => optional_time_hm(isset($schedule['break_end']) ? (string) $schedule['break_end'] : null),
                'room' => $schedule['room'],
                'late_after_minutes' => $lateAfterMinutes,
            ]);
            $created++;
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return ['created' => $created, 'skipped' => $skipped];
}

function lecturer_in_progress_session_id(int $lecturerId, ?int $excludeSessionId = null): ?int
{
    $sql = "SELECT session_id FROM lecture_sessions
            WHERE lecturer_id = :lecturer_id AND status = 'IN_PROGRESS'";
    $params = ['lecturer_id' => $lecturerId];
    if ($excludeSessionId !== null) {
        $sql .= ' AND session_id <> :session_id';
        $params['session_id'] = $excludeSessionId;
    }
    $statement = db()->prepare($sql . ' LIMIT 1');
    $statement->execute($params);
    $id = $statement->fetchColumn();

    return $id === false ? null : (int) $id;
}

function batch_in_progress_session_id(int $batchId, ?int $excludeSessionId = null): ?int
{
    $sql = "SELECT session_id FROM lecture_sessions
            WHERE batch_id = :batch_id AND status = 'IN_PROGRESS'";
    $params = ['batch_id' => $batchId];
    if ($excludeSessionId !== null) {
        $sql .= ' AND session_id <> :session_id';
        $params['session_id'] = $excludeSessionId;
    }
    $statement = db()->prepare($sql . ' LIMIT 1');
    $statement->execute($params);
    $id = $statement->fetchColumn();

    return $id === false ? null : (int) $id;
}

function user_can_control_session(array $session): bool
{
    if (can_manage_academic()) {
        return true;
    }

    $lecturer = current_lecturer_profile();
    if ($lecturer === null) {
        return false;
    }

    return (int) $session['lecturer_id'] === (int) $lecturer['lecturer_id'];
}

function start_lecture_session(int $sessionId): void
{
    sync_scheduled_session_states();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare(
            'SELECT session_id, lecturer_id, batch_id, status
             FROM lecture_sessions
             WHERE session_id = :session_id
             FOR UPDATE'
        );
        $lock->execute(['session_id' => $sessionId]);
        $session = $lock->fetch();
        if ($session === false) {
            throw new InvalidArgumentException('Lecture session not found.');
        }

        if (!user_can_control_session($session)) {
            throw new InvalidArgumentException('You do not have permission to start this session.');
        }

        if ($session['status'] === 'IN_PROGRESS') {
            $pdo->commit();
            return;
        }

        if ($session['status'] !== 'SCHEDULED') {
            throw new InvalidArgumentException('Only scheduled sessions can be started.');
        }

        $lecturerBusy = lecturer_in_progress_session_id((int) $session['lecturer_id'], $sessionId);
        if ($lecturerBusy !== null) {
            throw new InvalidArgumentException('This lecturer already has a session in progress.');
        }

        $batchBusy = batch_in_progress_session_id((int) $session['batch_id'], $sessionId);
        if ($batchBusy !== null) {
            throw new InvalidArgumentException('This batch already has a session in progress.');
        }

        $update = $pdo->prepare(
            "UPDATE lecture_sessions
             SET status = 'IN_PROGRESS', actual_start = :actual_start
             WHERE session_id = :session_id"
        );
        $update->execute([
            'actual_start' => app_now_datetime(),
            'session_id' => $sessionId,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function complete_lecture_session(int $sessionId): void
{
    $session = get_lecture_session($sessionId);
    if ($session === null) {
        throw new InvalidArgumentException('Lecture session not found.');
    }

    if (!user_can_control_session($session)) {
        throw new InvalidArgumentException('You do not have permission to stop this session.');
    }

    if ($session['status'] === 'COMPLETED') {
        return;
    }

    if ($session['status'] !== 'IN_PROGRESS') {
        throw new InvalidArgumentException('Only an in-progress session can be completed.');
    }

    $statement = db()->prepare(
        "UPDATE lecture_sessions
         SET status = 'COMPLETED', actual_end = :actual_end
         WHERE session_id = :session_id"
    );
    $statement->execute([
        'actual_end' => app_now_datetime(),
        'session_id' => $sessionId,
    ]);
    cancel_open_early_pending_for_session(db(), $sessionId);
    finalize_session_attendance($sessionId);
}

function cancel_lecture_session(int $sessionId): void
{
    if (!can_manage_academic()) {
        throw new InvalidArgumentException('Only academic staff or an administrator can cancel a session.');
    }

    $session = get_lecture_session($sessionId);
    if ($session === null) {
        throw new InvalidArgumentException('Lecture session not found.');
    }

    if (!in_array($session['status'], ['SCHEDULED', 'IN_PROGRESS'], true)) {
        throw new InvalidArgumentException('Only scheduled or in-progress sessions can be cancelled.');
    }

    $params = [
        'session_id' => $sessionId,
    ];
    $sql = "UPDATE lecture_sessions SET status = 'CANCELLED'";
    if ($session['status'] === 'IN_PROGRESS' && empty($session['actual_end'])) {
        $sql .= ', actual_end = :actual_end';
        $params['actual_end'] = app_now_datetime();
    }
    $sql .= ' WHERE session_id = :session_id';

    $statement = db()->prepare($sql);
    $statement->execute($params);
    cancel_open_early_pending_for_session(db(), $sessionId);
}

function is_student_eligible_for_session(int $studentId, int $sessionId, bool $allowCompleted = false): bool
{
    $session = get_lecture_session($sessionId);
    if ($session === null) {
        return false;
    }

    if ($session['status'] === 'CANCELLED') {
        return false;
    }
    if ($session['status'] === 'COMPLETED' && !$allowCompleted) {
        return false;
    }

    if (($session['module_status'] ?? '') !== 'ACTIVE') {
        return false;
    }

    $student = get_student($studentId);
    if ($student === null || $student['status'] !== 'ACTIVE') {
        return false;
    }

    if ((int) $student['batch_id'] !== (int) $session['batch_id']) {
        return false;
    }

    $enrolment = get_student_module_enrolment($studentId, (int) $session['module_id']);

    return $enrolment !== null && $enrolment['status'] === 'ENROLLED';
}

/**
 * @return list<array<string, mixed>>
 */
function list_eligible_students_for_session(int $sessionId, bool $forFinalization = false): array
{
    $session = get_lecture_session($sessionId);
    if ($session === null || $session['status'] === 'CANCELLED') {
        return [];
    }

    if (!$forFinalization && $session['status'] === 'COMPLETED') {
        return [];
    }

    if (($session['module_status'] ?? '') !== 'ACTIVE') {
        return [];
    }

    $statement = db()->prepare(
        "SELECT s.student_id, s.registration_no, s.first_name, s.last_name, s.status,
                sm.status AS enrolment_status
         FROM students s
         INNER JOIN student_modules sm
            ON sm.student_id = s.student_id
           AND sm.module_id = :module_id
           AND sm.status = 'ENROLLED'
         WHERE s.batch_id = :batch_id
           AND s.status = 'ACTIVE'
         ORDER BY s.last_name, s.first_name, s.registration_no"
    );
    $statement->execute([
        'module_id' => $session['module_id'],
        'batch_id' => $session['batch_id'],
    ]);

    return $statement->fetchAll();
}

function count_eligible_students_for_session(int $sessionId): int
{
    return count(list_eligible_students_for_session($sessionId));
}

/**
 * @return list<array<string, mixed>>
 */
function get_in_progress_sessions(?int $lecturerId = null): array
{
    $filters = ['status' => 'IN_PROGRESS'];
    if ($lecturerId !== null) {
        $filters['lecturer_id'] = $lecturerId;
    }

    return list_lecture_sessions($filters);
}

/**
 * Active IN_PROGRESS sessions this student would be eligible to attend.
 *
 * @return list<array<string, mixed>>
 */
function get_eligible_in_progress_sessions_for_student(int $studentId): array
{
    $eligible = [];
    foreach (get_in_progress_sessions() as $session) {
        if (is_student_eligible_for_session($studentId, (int) $session['session_id'])) {
            $eligible[] = $session;
        }
    }

    return $eligible;
}

/**
 * @return list<array<string, mixed>>
 */
function list_student_schedules(int $studentId): array
{
    $student = get_student($studentId);
    if ($student === null) {
        return [];
    }

    $statement = db()->prepare(
        "SELECT s.schedule_id, s.module_id, s.lecturer_id, s.batch_id, s.day_of_week,
                s.start_time, s.end_time, s.break_start, s.break_end, s.room, s.status,
                m.module_code, m.module_name,
                l.first_name AS lecturer_first_name, l.last_name AS lecturer_last_name,
                b.batch_name, c.course_code
         FROM schedules s
         INNER JOIN modules m ON m.module_id = s.module_id
         INNER JOIN lecturers l ON l.lecturer_id = s.lecturer_id
         INNER JOIN batches b ON b.batch_id = s.batch_id
         INNER JOIN courses c ON c.course_id = b.course_id
         INNER JOIN student_modules sm
            ON sm.module_id = s.module_id
           AND sm.student_id = :student_id
           AND sm.status = 'ENROLLED'
         WHERE s.batch_id = :batch_id
           AND s.status = 'ACTIVE'
           AND m.status = 'ACTIVE'
         ORDER BY FIELD(s.day_of_week, 'MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY'), s.start_time"
    );
    $statement->execute([
        'student_id' => $studentId,
        'batch_id' => $student['batch_id'],
    ]);

    return $statement->fetchAll();
}

/**
 * Student-visible sessions: same batch, enrolled in the module, not cancelled.
 *
 * @return list<array<string, mixed>>
 */
function list_visible_sessions_for_student(int $studentId, array $filters = []): array
{
    $student = get_student($studentId);
    if ($student === null) {
        return [];
    }

    $sessions = list_lecture_sessions(array_filter([
        'from' => $filters['from'] ?? null,
        'to' => $filters['to'] ?? null,
        'upcoming' => $filters['upcoming'] ?? null,
        'status' => $filters['status'] ?? null,
        'batch_id' => (int) $student['batch_id'],
    ], static fn ($value) => $value !== null && $value !== false && $value !== ''));

    $visible = [];
    foreach ($sessions as $session) {
        if ($session['status'] === 'CANCELLED') {
            continue;
        }
        $enrolment = get_student_module_enrolment($studentId, (int) $session['module_id']);
        if ($enrolment === null || $enrolment['status'] !== 'ENROLLED') {
            continue;
        }
        $visible[] = $session;
    }

    return $visible;
}

/**
 * Late threshold datetime for a lecture session.
 * Not used to write LATE/ABSENT; reserved for the next attendance stage.
 */
function session_late_threshold_datetime(array $session): ?DateTimeImmutable
{
    $date = (string) ($session['session_date'] ?? '');
    $start = normalize_time_hm((string) ($session['scheduled_start'] ?? ''));
    $minutes = (int) ($session['late_after_minutes'] ?? 0);
    if (!validate_date_ymd($date) || !validate_time_hm($start)) {
        return null;
    }

    $threshold = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i',
        $date . ' ' . $start,
        new DateTimeZone(APP_TIMEZONE)
    );
    if ($threshold === false) {
        return null;
    }

    return $threshold->modify('+' . max(0, $minutes) . ' minutes');
}

function normalize_camera_id(?string $cameraId): ?string
{
    $value = trim((string) $cameraId);
    if ($value === '') {
        return null;
    }

    $value = preg_replace('/[^A-Za-z0-9._-]/', '', $value) ?? '';
    $value = substr($value, 0, 50);

    return $value === '' ? null : $value;
}

/**
 * Resolve which IN_PROGRESS session a recognized student may check in to.
 * Does not insert attendance. Reuses is_student_eligible_for_session().
 *
 * @return array{
 *   result: string,
 *   student?: array<string, mixed>,
 *   session?: array<string, mixed>,
 *   session_ids?: list<int>
 * }
 */
function resolve_in_progress_session_for_student(int $studentId): array
{
    sync_scheduled_session_states();

    $student = get_student($studentId);
    if ($student === null) {
        return ['result' => 'INVALID_STUDENT'];
    }

    $inProgress = get_in_progress_sessions();
    if ($inProgress === []) {
        return [
            'result' => 'NO_ACTIVE_SESSION',
            'student' => $student,
        ];
    }

    $eligible = [];
    foreach ($inProgress as $session) {
        if (is_student_eligible_for_session($studentId, (int) $session['session_id'])) {
            $eligible[] = $session;
        }
    }

    if ($eligible === []) {
        return [
            'result' => 'NOT_ELIGIBLE',
            'student' => $student,
        ];
    }

    if (count($eligible) > 1) {
        $ids = array_map(static fn (array $session): int => (int) $session['session_id'], $eligible);
        error_log(
            'Ambiguous IN_PROGRESS sessions for student_id=' . $studentId
            . ' session_ids=' . implode(',', $ids)
        );

        return [
            'result' => 'AMBIGUOUS_ACTIVE_SESSIONS',
            'student' => $student,
            'session_ids' => $ids,
        ];
    }

    return [
        'result' => 'ELIGIBLE',
        'student' => $student,
        'session' => $eligible[0],
    ];
}

require_once __DIR__ . '/attendance.php';
