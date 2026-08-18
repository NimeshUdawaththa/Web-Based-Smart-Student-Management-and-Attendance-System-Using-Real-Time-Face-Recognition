<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Academic timetable, module enrolment, lecture sessions, and eligibility.
 * Does not write attendance events or attendance records.
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

function app_now(): DateTimeImmutable
{
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
    $time = $bound === 'end'
        ? (string) ($session['scheduled_end'] ?? '')
        : (string) ($session['scheduled_start'] ?? '');
    $date = (string) ($session['session_date'] ?? '');
    $hm = normalize_time_hm($time);
    if (!validate_date_ymd($date) || !validate_time_hm($hm)) {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i',
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
 * @return array{skipped?: bool, opened: int, closed: int, expired: int}
 */
function sync_scheduled_session_states(bool $force = false): array
{
    static $alreadyRan = false;
    if ($alreadyRan && !$force) {
        return ['skipped' => true, 'opened' => 0, 'closed' => 0, 'expired' => 0];
    }
    $alreadyRan = true;

    $opened = 0;
    $closed = 0;
    $expired = 0;

    try {
        $now = app_now();
        $pdo = db();
        $pdo->beginTransaction();
        $statement = $pdo->query(
            "SELECT session_id, lecturer_id, batch_id, status, session_date,
                    scheduled_start, scheduled_end, actual_start, actual_end
             FROM lecture_sessions
             WHERE status IN ('SCHEDULED', 'IN_PROGRESS')
             FOR UPDATE"
        );
        $rows = $statement === false ? [] : $statement->fetchAll();

        $busyLecturers = [];
        $busyBatches = [];
        foreach ($rows as $row) {
            if ($row['status'] !== 'IN_PROGRESS') {
                continue;
            }
            $end = session_scheduled_datetime($row, 'end');
            if ($end instanceof DateTimeImmutable && $now >= $end) {
                continue;
            }
            $busyLecturers[(int) $row['lecturer_id']] = true;
            $busyBatches[(int) $row['batch_id']] = true;
        }

        foreach ($rows as $row) {
            if ($row['status'] !== 'IN_PROGRESS') {
                continue;
            }
            $end = session_scheduled_datetime($row, 'end');
            if (!$end instanceof DateTimeImmutable || $now < $end) {
                continue;
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
        }

        foreach ($rows as $row) {
            if ($row['status'] !== 'SCHEDULED') {
                continue;
            }
            $end = session_scheduled_datetime($row, 'end');
            if (!$end instanceof DateTimeImmutable || $now < $end) {
                continue;
            }
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

        foreach ($rows as $row) {
            if ($row['status'] !== 'SCHEDULED') {
                continue;
            }
            $start = session_scheduled_datetime($row, 'start');
            $end = session_scheduled_datetime($row, 'end');
            if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
                continue;
            }
            if ($now < $start || $now >= $end) {
                continue;
            }

            $lecturerId = (int) $row['lecturer_id'];
            $batchId = (int) $row['batch_id'];
            if (isset($busyLecturers[$lecturerId]) || isset($busyBatches[$batchId])) {
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

        $pdo->commit();
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Lecture session timetable sync failed: ' . $exception->getMessage());
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
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) === 1) {
        return substr($time, 0, 5);
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
 * @param array{search?: string, course_id?: int, status?: string} $filters
 * @return list<array<string, mixed>>
 */
function list_modules(array $filters = []): array
{
    $sql = "SELECT m.module_id, m.course_id, m.module_code, m.module_name, m.credits, m.semester, m.status,
                   c.course_code, c.course_name
            FROM modules m
            INNER JOIN courses c ON c.course_id = m.course_id
            WHERE 1=1";
    $params = [];

    if (!empty($filters['search'])) {
        $sql .= ' AND (m.module_code LIKE :search OR m.module_name LIKE :search)';
        $params['search'] = '%' . $filters['search'] . '%';
    }

    if (!empty($filters['course_id'])) {
        $sql .= ' AND m.course_id = :course_id';
        $params['course_id'] = $filters['course_id'];
    }

    if (!empty($filters['status']) && in_array($filters['status'], module_statuses(), true)) {
        $sql .= ' AND m.status = :status';
        $params['status'] = $filters['status'];
    }

    $sql .= ' ORDER BY c.course_code, m.semester, m.module_code';
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function get_module(int $moduleId): ?array
{
    $statement = db()->prepare(
        "SELECT m.module_id, m.course_id, m.module_code, m.module_name, m.credits, m.semester, m.status,
                c.course_code, c.course_name
         FROM modules m
         INNER JOIN courses c ON c.course_id = m.course_id
         WHERE m.module_id = :module_id
         LIMIT 1"
    );
    $statement->execute(['module_id' => $moduleId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * @param array{course_id: int, module_code: string, module_name: string, credits: float, semester: int, status: string} $data
 */
function create_module(array $data): int
{
    $statement = db()->prepare(
        "INSERT INTO modules (course_id, module_code, module_name, credits, semester, status)
         VALUES (:course_id, :module_code, :module_name, :credits, :semester, :status)"
    );
    $statement->execute([
        'course_id' => $data['course_id'],
        'module_code' => $data['module_code'],
        'module_name' => $data['module_name'],
        'credits' => $data['credits'],
        'semester' => $data['semester'],
        'status' => $data['status'],
    ]);

    return (int) db()->lastInsertId();
}

/**
 * @param array{course_id: int, module_code: string, module_name: string, credits: float, semester: int, status: string} $data
 */
function update_module(int $moduleId, array $data): void
{
    $statement = db()->prepare(
        "UPDATE modules
         SET course_id = :course_id, module_code = :module_code, module_name = :module_name,
             credits = :credits, semester = :semester, status = :status
         WHERE module_id = :module_id"
    );
    $statement->execute([
        'course_id' => $data['course_id'],
        'module_code' => $data['module_code'],
        'module_name' => $data['module_name'],
        'credits' => $data['credits'],
        'semester' => $data['semester'],
        'status' => $data['status'],
        'module_id' => $moduleId,
    ]);
}

function module_code_exists(int $courseId, string $moduleCode, ?int $excludeModuleId = null): bool
{
    $sql = 'SELECT module_id FROM modules WHERE course_id = :course_id AND module_code = :module_code';
    $params = ['course_id' => $courseId, 'module_code' => $moduleCode];

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
                   m.module_code, m.module_name, m.status AS module_status, m.course_id,
                   c.course_code,
                   l.staff_no, l.first_name, l.last_name, l.status AS lecturer_status
            FROM module_lecturers ml
            INNER JOIN modules m ON m.module_id = ml.module_id
            INNER JOIN courses c ON c.course_id = m.course_id
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

    $sql .= ' ORDER BY c.course_code, m.module_code, l.last_name, l.first_name';
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
         INNER JOIN courses c ON c.course_id = m.course_id
         WHERE sm.student_id = :student_id
         ORDER BY sm.status, m.module_code"
    );
    $statement->execute(['student_id' => $studentId]);

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

    if ((int) $student['course_id'] !== (int) $module['course_id']) {
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

function drop_student_module(int $studentModuleId): void
{
    $statement = db()->prepare(
        'SELECT student_module_id, status FROM student_modules WHERE student_module_id = :id LIMIT 1'
    );
    $statement->execute(['id' => $studentModuleId]);
    $row = $statement->fetch();
    if ($row === false) {
        throw new InvalidArgumentException('Enrolment not found.');
    }

    if ($row['status'] === 'DROPPED') {
        throw new InvalidArgumentException('That enrolment is already dropped.');
    }

    $update = db()->prepare(
        "UPDATE student_modules SET status = 'DROPPED' WHERE student_module_id = :id"
    );
    $update->execute(['id' => $studentModuleId]);
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

    if ((int) $batch['course_id'] !== (int) $module['course_id']) {
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

/**
 * @param array{module_id?: int, lecturer_id?: int, batch_id?: int, day_of_week?: string, status?: string} $filters
 * @return list<array<string, mixed>>
 */
function list_schedules(array $filters = []): array
{
    $sql = "SELECT s.schedule_id, s.module_id, s.lecturer_id, s.batch_id, s.day_of_week,
                   s.start_time, s.end_time, s.room, s.status,
                   m.module_code, m.module_name, m.course_id,
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
                s.start_time, s.end_time, s.room, s.status,
                m.module_code, m.module_name, m.course_id,
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
    }

    if (!in_array($status, schedule_statuses(), true)) {
        $errors[] = 'Select a valid timetable status.';
    }

    if ($module !== null && $batch !== null && (int) $module['course_id'] !== (int) $batch['course_id']) {
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
        "INSERT INTO schedules (module_id, lecturer_id, batch_id, day_of_week, start_time, end_time, room, status)
         VALUES (:module_id, :lecturer_id, :batch_id, :day_of_week, :start_time, :end_time, :room, :status)"
    );
    $room = trim((string) ($data['room'] ?? ''));
    $statement->execute([
        'module_id' => $data['module_id'],
        'lecturer_id' => $data['lecturer_id'],
        'batch_id' => $data['batch_id'],
        'day_of_week' => $data['day_of_week'],
        'start_time' => normalize_time_hm((string) $data['start_time']),
        'end_time' => normalize_time_hm((string) $data['end_time']),
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
    sync_scheduled_session_states();

    $sql = "SELECT ls.session_id, ls.module_id, ls.lecturer_id, ls.batch_id, ls.schedule_id,
                   ls.session_date, ls.scheduled_start, ls.scheduled_end, ls.actual_start, ls.actual_end,
                   ls.room, ls.late_after_minutes, ls.status,
                   m.module_code, m.module_name, m.course_id, m.status AS module_status,
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
    sync_scheduled_session_states();

    $statement = db()->prepare(
        "SELECT ls.session_id, ls.module_id, ls.lecturer_id, ls.batch_id, ls.schedule_id,
                ls.session_date, ls.scheduled_start, ls.scheduled_end, ls.actual_start, ls.actual_end,
                ls.room, ls.late_after_minutes, ls.status,
                m.module_code, m.module_name, m.course_id, m.status AS module_status,
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

    if ((int) $module['course_id'] !== (int) $batch['course_id']) {
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
            (module_id, lecturer_id, batch_id, schedule_id, session_date, scheduled_start, scheduled_end, room, late_after_minutes, status)
         VALUES
            (:module_id, :lecturer_id, :batch_id, :schedule_id, :session_date, :scheduled_start, :scheduled_end, :room, :late_after_minutes, 'SCHEDULED')"
    );
    $statement->execute([
        'module_id' => $moduleId,
        'lecturer_id' => $lecturerId,
        'batch_id' => $batchId,
        'schedule_id' => $scheduleId,
        'session_date' => $date,
        'scheduled_start' => $start,
        'scheduled_end' => $end,
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
                    (module_id, lecturer_id, batch_id, schedule_id, session_date, scheduled_start, scheduled_end, room, late_after_minutes, status)
                 VALUES
                    (:module_id, :lecturer_id, :batch_id, :schedule_id, :session_date, :scheduled_start, :scheduled_end, :room, :late_after_minutes, 'SCHEDULED')"
            );
            $insert->execute([
                'module_id' => $schedule['module_id'],
                'lecturer_id' => $schedule['lecturer_id'],
                'batch_id' => $schedule['batch_id'],
                'schedule_id' => $schedule['schedule_id'],
                'session_date' => $sessionDate,
                'scheduled_start' => normalize_time_hm((string) $schedule['start_time']),
                'scheduled_end' => normalize_time_hm((string) $schedule['end_time']),
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
}

function is_student_eligible_for_session(int $studentId, int $sessionId): bool
{
    $session = get_lecture_session($sessionId);
    if ($session === null) {
        return false;
    }

    if (in_array($session['status'], ['CANCELLED', 'COMPLETED'], true)) {
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
function list_eligible_students_for_session(int $sessionId): array
{
    $session = get_lecture_session($sessionId);
    if ($session === null || in_array($session['status'], ['CANCELLED', 'COMPLETED'], true)) {
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
                s.start_time, s.end_time, s.room, s.status,
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

function student_has_in_event(int $studentId, int $sessionId): bool
{
    $statement = db()->prepare(
        "SELECT event_id FROM attendance_events
         WHERE student_id = :student_id AND session_id = :session_id AND event_type = 'IN'
         LIMIT 1"
    );
    $statement->execute([
        'student_id' => $studentId,
        'session_id' => $sessionId,
    ]);

    return $statement->fetch() !== false;
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

/**
 * Record a face-recognition IN event when exactly one eligible session is IN_PROGRESS.
 * Does not write OUT events or attendance_records.
 *
 * @return array<string, mixed>
 */
function record_face_check_in(int $studentId, float $confidence, ?string $cameraId = null): array
{
    $resolution = resolve_in_progress_session_for_student($studentId);
    if (($resolution['result'] ?? '') !== 'ELIGIBLE') {
        return public_check_in_result($resolution);
    }

    /** @var array<string, mixed> $session */
    $session = $resolution['session'];
    $sessionId = (int) $session['session_id'];
    $camera = normalize_camera_id($cameraId);
    $confidence = max(0.0, min(100.0, round($confidence, 2)));
    $lockName = sprintf('att_in_%d_%d', $studentId, $sessionId);

    $pdo = db();
    $lockAcquired = false;

    try {
        $lockStatement = $pdo->prepare('SELECT GET_LOCK(:lock_name, 5)');
        $lockStatement->execute(['lock_name' => $lockName]);
        $lockAcquired = (int) $lockStatement->fetchColumn() === 1;
        if (!$lockAcquired) {
            error_log('Could not acquire attendance IN lock for student_id=' . $studentId . ' session_id=' . $sessionId);
            return [
                'result' => 'ERROR',
                'student_id' => $studentId,
            ];
        }

        $pdo->beginTransaction();

        $statusLock = $pdo->prepare(
            "SELECT session_id, status FROM lecture_sessions WHERE session_id = :session_id FOR UPDATE"
        );
        $statusLock->execute(['session_id' => $sessionId]);
        $lockedSession = $statusLock->fetch();
        if ($lockedSession === false || $lockedSession['status'] !== 'IN_PROGRESS') {
            $pdo->rollBack();
            return [
                'result' => 'NO_ACTIVE_SESSION',
                'student_id' => $studentId,
            ];
        }

        if (!is_student_eligible_for_session($studentId, $sessionId)) {
            $pdo->rollBack();
            return [
                'result' => 'NOT_ELIGIBLE',
                'student_id' => $studentId,
                'session_id' => $sessionId,
                'module_code' => $session['module_code'] ?? null,
            ];
        }

        $existing = $pdo->prepare(
            "SELECT event_id, recognized_at FROM attendance_events
             WHERE student_id = :student_id AND session_id = :session_id AND event_type = 'IN'
             ORDER BY recognized_at ASC, event_id ASC
             LIMIT 1"
        );
        $existing->execute([
            'student_id' => $studentId,
            'session_id' => $sessionId,
        ]);
        $existingIn = $existing->fetch();
        if ($existingIn !== false) {
            $pdo->commit();
            return [
                'result' => 'ALREADY_CHECKED_IN',
                'student_id' => $studentId,
                'session_id' => $sessionId,
                'module_code' => $session['module_code'] ?? null,
                'event_id' => (int) $existingIn['event_id'],
                'recognized_at' => (string) $existingIn['recognized_at'],
            ];
        }

        $recognizedAt = app_now_datetime();
        $insert = $pdo->prepare(
            "INSERT INTO attendance_events
                (student_id, session_id, event_type, recognized_at, confidence, camera_id)
             VALUES
                (:student_id, :session_id, 'IN', :recognized_at, :confidence, :camera_id)"
        );
        $insert->execute([
            'student_id' => $studentId,
            'session_id' => $sessionId,
            'recognized_at' => $recognizedAt,
            'confidence' => $confidence,
            'camera_id' => $camera,
        ]);
        $eventId = (int) $pdo->lastInsertId();
        $pdo->commit();

        return [
            'result' => 'CHECKED_IN',
            'student_id' => $studentId,
            'session_id' => $sessionId,
            'module_code' => $session['module_code'] ?? null,
            'event_id' => $eventId,
            'recognized_at' => $recognizedAt,
            'confidence' => $confidence,
            'camera_id' => $camera,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Face check-in failed for student_id=' . $studentId . ': ' . $exception->getMessage());
        return [
            'result' => 'ERROR',
            'student_id' => $studentId,
        ];
    } finally {
        if ($lockAcquired) {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => $lockName]);
        }
    }
}

/**
 * @param array<string, mixed> $resolution
 * @return array<string, mixed>
 */
function public_check_in_result(array $resolution): array
{
    $result = (string) ($resolution['result'] ?? 'ERROR');
    $payload = ['result' => $result];

    if (isset($resolution['student']['student_id'])) {
        $payload['student_id'] = (int) $resolution['student']['student_id'];
    }
    if (isset($resolution['session']['session_id'])) {
        $payload['session_id'] = (int) $resolution['session']['session_id'];
        $payload['module_code'] = $resolution['session']['module_code'] ?? null;
    }
    if (isset($resolution['session_ids'])) {
        $payload['session_ids'] = $resolution['session_ids'];
    }

    return $payload;
}
