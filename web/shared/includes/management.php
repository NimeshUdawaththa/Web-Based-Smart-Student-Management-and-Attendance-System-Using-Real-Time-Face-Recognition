<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * @return list<string>
 */
function user_statuses(): array
{
    return ['ACTIVE', 'INACTIVE', 'SUSPENDED'];
}

/**
 * @return list<string>
 */
function student_statuses(): array
{
    return ['ACTIVE', 'INACTIVE', 'GRADUATED', 'SUSPENDED'];
}

/**
 * @return list<string>
 */
function staff_statuses(): array
{
    return ['ACTIVE', 'INACTIVE'];
}

/**
 * @return list<string>
 */
function gender_options(): array
{
    return ['MALE', 'FEMALE', 'OTHER'];
}

function positive_int(mixed $value): ?int
{
    if (!is_string($value) && !is_int($value)) {
        return null;
    }

    $filtered = filter_var($value, FILTER_VALIDATE_INT);

    return ($filtered !== false && $filtered > 0) ? $filtered : null;
}

function validate_email_address(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validate_date_ymd(string $date): bool
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

/**
 * @return list<string>
 */
function course_statuses(): array
{
    return ['ACTIVE', 'INACTIVE', 'ARCHIVED'];
}

/**
 * @return list<string>
 */
function batch_statuses(): array
{
    return ['ACTIVE', 'COMPLETED', 'INACTIVE'];
}

function course_duration_min(): int
{
    return 1;
}

function course_duration_max(): int
{
    return 10;
}

function intake_year_min(): int
{
    return 2000;
}

function intake_year_max(): int
{
    return 2100;
}

function is_integrity_constraint_violation(Throwable $exception): bool
{
    if (!$exception instanceof PDOException) {
        return false;
    }

    $code = (string) $exception->getCode();
    $sqlState = (string) ($exception->errorInfo[0] ?? $code);

    return $code === '23000' || $sqlState === '23000';
}

function course_status_label(string $status): string
{
    return match ($status) {
        'ACTIVE' => 'Active',
        'INACTIVE' => 'Inactive',
        'ARCHIVED' => 'Archived',
        default => $status,
    };
}

function batch_status_label(string $status): string
{
    return match ($status) {
        'ACTIVE' => 'Active',
        'COMPLETED' => 'Completed',
        'INACTIVE' => 'Inactive',
        default => $status,
    };
}

function course_choice_label(array $course): string
{
    $label = (string) $course['course_code'] . ' - ' . (string) $course['course_name'];
    $status = (string) ($course['status'] ?? 'ACTIVE');
    if ($status !== 'ACTIVE') {
        $label .= ' (' . course_status_label($status) . ')';
    }

    return $label;
}

function batch_choice_label(array $batch): string
{
    $label = (string) $batch['batch_name'] . ' (' . (string) $batch['intake_year'] . ')';
    $status = (string) ($batch['status'] ?? 'ACTIVE');
    if ($status !== 'ACTIVE') {
        $label .= ' (' . batch_status_label($status) . ')';
    }

    return $label;
}

function course_is_open_for_new(array $course): bool
{
    return ($course['status'] ?? '') === 'ACTIVE';
}

function batch_is_open_for_new(array $batch): bool
{
    return ($batch['status'] ?? '') === 'ACTIVE';
}

/**
 * @return list<array<string, mixed>>
 */
function list_active_courses(): array
{
    $statement = db()->query(
        "SELECT course_id, course_code, course_name, duration_years, status
         FROM courses
         WHERE status = 'ACTIVE'
         ORDER BY course_name"
    );

    return $statement->fetchAll();
}

/**
 * @param array{search?: string, status?: string} $filters
 * @return list<array<string, mixed>>
 */
function list_courses(array $filters = []): array
{
    if (function_exists('ensure_course_modules_table')) {
        ensure_course_modules_table();
    }
    $sql = "SELECT c.course_id, c.course_code, c.course_name, c.duration_years, c.status,
                   c.created_at, c.updated_at,
                   (SELECT COUNT(*) FROM batches b WHERE b.course_id = c.course_id) AS batch_count,
                   (SELECT COUNT(*) FROM students s WHERE s.course_id = c.course_id) AS student_count,
                   (SELECT COUNT(*) FROM course_modules cm WHERE cm.course_id = c.course_id AND cm.status = 'ACTIVE') AS module_count
            FROM courses c
            WHERE 1=1";
    $params = [];

    if (!empty($filters['search'])) {
        $sql .= ' AND (c.course_code LIKE :search OR c.course_name LIKE :search)';
        $params['search'] = '%' . $filters['search'] . '%';
    }

    if (!empty($filters['status']) && in_array($filters['status'], course_statuses(), true)) {
        $sql .= ' AND c.status = :status';
        $params['status'] = $filters['status'];
    }

    $sql .= ' ORDER BY c.course_code';
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function list_batches_for_course(int $courseId): array
{
    $statement = db()->prepare(
        "SELECT batch_id, course_id, batch_name, intake_year, status
         FROM batches
         WHERE course_id = :course_id
           AND status = 'ACTIVE'
         ORDER BY intake_year DESC, batch_name"
    );
    $statement->execute(['course_id' => $courseId]);

    return $statement->fetchAll();
}

/**
 * Active courses, plus a current course that is no longer ACTIVE (edit forms).
 *
 * @return list<array<string, mixed>>
 */
function courses_for_selection(?int $includeCourseId = null): array
{
    $courses = list_active_courses();
    $ids = array_map(static fn (array $row): int => (int) $row['course_id'], $courses);

    if ($includeCourseId !== null && !in_array($includeCourseId, $ids, true)) {
        $current = get_course($includeCourseId);
        if ($current !== null) {
            $courses[] = $current;
        }
    }

    return $courses;
}

/**
 * Active batches for a course, plus the student's current batch when it belongs to that course.
 *
 * @return list<array<string, mixed>>
 */
function batches_for_selection(int $courseId, ?int $includeBatchId = null): array
{
    $batches = list_batches_for_course($courseId);
    $ids = array_map(static fn (array $row): int => (int) $row['batch_id'], $batches);

    if ($includeBatchId !== null && !in_array($includeBatchId, $ids, true)) {
        $current = get_batch($includeBatchId);
        if ($current !== null && (int) $current['course_id'] === $courseId) {
            $batches[] = $current;
        }
    }

    foreach ($batches as &$batch) {
        $batch['label'] = batch_choice_label($batch);
    }
    unset($batch);

    return $batches;
}

function batch_belongs_to_course(int $batchId, int $courseId): bool
{
    $statement = db()->prepare(
        'SELECT batch_id
         FROM batches
         WHERE batch_id = :batch_id
           AND course_id = :course_id
         LIMIT 1'
    );
    $statement->execute([
        'batch_id' => $batchId,
        'course_id' => $courseId,
    ]);

    return $statement->fetch() !== false;
}

function assert_active_course_and_batch(int $courseId, int $batchId): void
{
    $course = get_course($courseId);
    $batch = get_batch($batchId);

    if ($course === null || $batch === null) {
        throw new InvalidArgumentException('Select a valid course and batch.');
    }

    if (!batch_belongs_to_course($batchId, $courseId)) {
        throw new InvalidArgumentException('Selected batch does not belong to the selected course.');
    }

    if (!course_is_open_for_new($course)) {
        throw new InvalidArgumentException('The selected course is not active.');
    }

    if (!batch_is_open_for_new($batch)) {
        throw new InvalidArgumentException('The selected batch is not active.');
    }
}

function assert_course_batch_for_student_update(array $student, int $courseId, int $batchId): void
{
    $course = get_course($courseId);
    $batch = get_batch($batchId);

    if ($course === null || $batch === null) {
        throw new InvalidArgumentException('Select a valid course and batch.');
    }

    if (!batch_belongs_to_course($batchId, $courseId)) {
        throw new InvalidArgumentException('Selected batch does not belong to the selected course.');
    }

    $currentCourseId = (int) $student['course_id'];
    $currentBatchId = (int) $student['batch_id'];
    $keepingCurrentCourse = $courseId === $currentCourseId;
    $keepingCurrentBatch = $batchId === $currentBatchId;

    if (!$keepingCurrentCourse && !course_is_open_for_new($course)) {
        throw new InvalidArgumentException('The selected course is not active.');
    }

    if (!$keepingCurrentBatch && !batch_is_open_for_new($batch)) {
        throw new InvalidArgumentException('The selected batch is not active.');
    }
}

/**
 * @return array<string, mixed>|null
 */
function get_course(int $courseId): ?array
{
    $statement = db()->prepare(
        'SELECT course_id, course_code, course_name, duration_years, status, created_at, updated_at
         FROM courses
         WHERE course_id = :course_id
         LIMIT 1'
    );
    $statement->execute(['course_id' => $courseId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * @return array<string, mixed>|null
 */
function get_batch(int $batchId): ?array
{
    $statement = db()->prepare(
        'SELECT b.batch_id, b.course_id, b.batch_name, b.intake_year, b.start_date, b.end_date,
                b.status, b.created_at, b.updated_at,
                c.course_code, c.course_name, c.status AS course_status
         FROM batches b
         INNER JOIN courses c ON c.course_id = b.course_id
         WHERE b.batch_id = :batch_id
         LIMIT 1'
    );
    $statement->execute(['batch_id' => $batchId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

function course_code_exists(string $courseCode, ?int $excludeCourseId = null): bool
{
    $sql = 'SELECT course_id FROM courses WHERE course_code = :course_code';
    $params = ['course_code' => $courseCode];

    if ($excludeCourseId !== null) {
        $sql .= ' AND course_id <> :course_id';
        $params['course_id'] = $excludeCourseId;
    }

    $statement = db()->prepare($sql . ' LIMIT 1');
    $statement->execute($params);

    return $statement->fetch() !== false;
}

function batch_name_exists_for_course(int $courseId, string $batchName, ?int $excludeBatchId = null): bool
{
    $sql = 'SELECT batch_id FROM batches WHERE course_id = :course_id AND batch_name = :batch_name';
    $params = ['course_id' => $courseId, 'batch_name' => $batchName];

    if ($excludeBatchId !== null) {
        $sql .= ' AND batch_id <> :batch_id';
        $params['batch_id'] = $excludeBatchId;
    }

    $statement = db()->prepare($sql . ' LIMIT 1');
    $statement->execute($params);

    return $statement->fetch() !== false;
}

/**
 * @param array{course_code: string, course_name: string, duration_years: int, status: string} $data
 */
function create_course(array $data): int
{
    $payload = validate_course_payload($data);

    try {
        $statement = db()->prepare(
            'INSERT INTO courses (course_code, course_name, duration_years, status)
             VALUES (:course_code, :course_name, :duration_years, :status)'
        );
        $statement->execute($payload);

        return (int) db()->lastInsertId();
    } catch (PDOException $exception) {
        if (is_integrity_constraint_violation($exception)) {
            throw new InvalidArgumentException('That course code is already in use.');
        }
        throw $exception;
    }
}

/**
 * @param array{course_code: string, course_name: string, duration_years: int, status: string} $data
 */
function update_course(int $courseId, array $data): void
{
    if (get_course($courseId) === null) {
        throw new InvalidArgumentException('Course not found.');
    }

    $payload = validate_course_payload($data, $courseId);
    $payload['course_id'] = $courseId;

    try {
        $statement = db()->prepare(
            'UPDATE courses
             SET course_code = :course_code,
                 course_name = :course_name,
                 duration_years = :duration_years,
                 status = :status
             WHERE course_id = :course_id'
        );
        $statement->execute($payload);
    } catch (PDOException $exception) {
        if (is_integrity_constraint_violation($exception)) {
            throw new InvalidArgumentException('That course code is already in use.');
        }
        throw $exception;
    }
}

/**
 * @param array<string, mixed> $data
 * @return array{course_code: string, course_name: string, duration_years: int, status: string}
 */
function validate_course_payload(array $data, ?int $excludeCourseId = null): array
{
    $code = trim((string) ($data['course_code'] ?? ''));
    $name = trim((string) ($data['course_name'] ?? ''));
    $status = (string) ($data['status'] ?? '');
    $duration = filter_var($data['duration_years'] ?? null, FILTER_VALIDATE_INT);

    if ($code === '' || mb_strlen($code) > 20) {
        throw new InvalidArgumentException('Course code is required and must be at most 20 characters.');
    }

    if ($name === '' || mb_strlen($name) > 150) {
        throw new InvalidArgumentException('Course name is required and must be at most 150 characters.');
    }

    if ($duration === false || $duration < course_duration_min() || $duration > course_duration_max()) {
        throw new InvalidArgumentException(
            'Duration must be an integer between ' . course_duration_min() . ' and ' . course_duration_max() . '.'
        );
    }

    if (!in_array($status, course_statuses(), true)) {
        throw new InvalidArgumentException('Select a valid course status.');
    }

    if (course_code_exists($code, $excludeCourseId)) {
        throw new InvalidArgumentException('That course code is already in use.');
    }

    return [
        'course_code' => $code,
        'course_name' => $name,
        'duration_years' => $duration,
        'status' => $status,
    ];
}

/**
 * @param array{search?: string, course_id?: int, status?: string} $filters
 * @return list<array<string, mixed>>
 */
function list_manage_batches(array $filters = []): array
{
    $sql = "SELECT b.batch_id, b.course_id, b.batch_name, b.intake_year, b.start_date, b.end_date,
                   b.status, b.created_at, b.updated_at,
                   c.course_code, c.course_name,
                   (SELECT COUNT(*) FROM students s WHERE s.batch_id = b.batch_id) AS student_count,
                   (SELECT COUNT(*) FROM schedules sch WHERE sch.batch_id = b.batch_id) AS schedule_count,
                   (SELECT COUNT(*) FROM lecture_sessions ls WHERE ls.batch_id = b.batch_id) AS session_count
            FROM batches b
            INNER JOIN courses c ON c.course_id = b.course_id
            WHERE 1=1";
    $params = [];

    if (!empty($filters['search'])) {
        $sql .= ' AND (b.batch_name LIKE :search OR c.course_code LIKE :search OR c.course_name LIKE :search)';
        $params['search'] = '%' . $filters['search'] . '%';
    }

    if (!empty($filters['course_id'])) {
        $sql .= ' AND b.course_id = :course_id';
        $params['course_id'] = $filters['course_id'];
    }

    if (!empty($filters['status']) && in_array($filters['status'], batch_statuses(), true)) {
        $sql .= ' AND b.status = :status';
        $params['status'] = $filters['status'];
    }

    $sql .= ' ORDER BY c.course_code, b.intake_year DESC, b.batch_name';
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * @return array{batch_count: int, student_count: int, module_count: int}
 */
function course_dependency_counts(int $courseId): array
{
    if (function_exists('ensure_course_modules_table')) {
        ensure_course_modules_table();
    }
    $statement = db()->prepare(
        "SELECT
            (SELECT COUNT(*) FROM batches WHERE course_id = :course_id) AS batch_count,
            (SELECT COUNT(*) FROM students WHERE course_id = :course_id2) AS student_count,
            (SELECT COUNT(*) FROM course_modules WHERE course_id = :course_id3 AND status = 'ACTIVE') AS module_count"
    );
    $statement->execute([
        'course_id' => $courseId,
        'course_id2' => $courseId,
        'course_id3' => $courseId,
    ]);
    $row = $statement->fetch();

    return [
        'batch_count' => (int) ($row['batch_count'] ?? 0),
        'student_count' => (int) ($row['student_count'] ?? 0),
        'module_count' => (int) ($row['module_count'] ?? 0),
    ];
}

/**
 * @return array{student_count: int, schedule_count: int, session_count: int}
 */
function batch_dependency_counts(int $batchId): array
{
    $statement = db()->prepare(
        'SELECT
            (SELECT COUNT(*) FROM students WHERE batch_id = :batch_id) AS student_count,
            (SELECT COUNT(*) FROM schedules WHERE batch_id = :batch_id2) AS schedule_count,
            (SELECT COUNT(*) FROM lecture_sessions WHERE batch_id = :batch_id3) AS session_count'
    );
    $statement->execute([
        'batch_id' => $batchId,
        'batch_id2' => $batchId,
        'batch_id3' => $batchId,
    ]);
    $row = $statement->fetch();

    return [
        'student_count' => (int) ($row['student_count'] ?? 0),
        'schedule_count' => (int) ($row['schedule_count'] ?? 0),
        'session_count' => (int) ($row['session_count'] ?? 0),
    ];
}

/**
 * @param array{course_id: int, batch_name: string, intake_year: int, start_date: string, end_date?: ?string, status: string} $data
 */
function create_batch(array $data): int
{
    $courseId = (int) ($data['course_id'] ?? 0);
    $course = $courseId > 0 ? get_course($courseId) : null;
    if ($course === null) {
        throw new InvalidArgumentException('Select a valid course.');
    }
    if (!course_is_open_for_new($course)) {
        throw new InvalidArgumentException('Batches can only be created under an active course.');
    }

    $payload = validate_batch_payload($data, $courseId);

    try {
        $statement = db()->prepare(
            'INSERT INTO batches (course_id, batch_name, intake_year, start_date, end_date, status)
             VALUES (:course_id, :batch_name, :intake_year, :start_date, :end_date, :status)'
        );
        $statement->execute($payload);

        return (int) db()->lastInsertId();
    } catch (PDOException $exception) {
        if (is_integrity_constraint_violation($exception)) {
            throw new InvalidArgumentException('That batch name already exists for this course.');
        }
        throw $exception;
    }
}

/**
 * Updates a batch. course_id is never written.
 *
 * @param array{batch_name: string, intake_year: int, start_date: string, end_date?: ?string, status: string} $data
 */
function update_batch(int $batchId, array $data): void
{
    $batch = get_batch($batchId);
    if ($batch === null) {
        throw new InvalidArgumentException('Batch not found.');
    }

    $courseId = (int) $batch['course_id'];
    $payload = validate_batch_payload($data, $courseId, $batchId);
    unset($payload['course_id']);
    $payload['batch_id'] = $batchId;

    try {
        $statement = db()->prepare(
            'UPDATE batches
             SET batch_name = :batch_name,
                 intake_year = :intake_year,
                 start_date = :start_date,
                 end_date = :end_date,
                 status = :status
             WHERE batch_id = :batch_id'
        );
        $statement->execute($payload);
    } catch (PDOException $exception) {
        if (is_integrity_constraint_violation($exception)) {
            throw new InvalidArgumentException('That batch name already exists for this course.');
        }
        throw $exception;
    }
}

/**
 * @param array<string, mixed> $data
 * @return array{course_id: int, batch_name: string, intake_year: int, start_date: string, end_date: ?string, status: string}
 */
function validate_batch_payload(array $data, int $courseId, ?int $excludeBatchId = null): array
{
    $name = trim((string) ($data['batch_name'] ?? ''));
    $status = (string) ($data['status'] ?? '');
    $year = filter_var($data['intake_year'] ?? null, FILTER_VALIDATE_INT);
    $start = trim((string) ($data['start_date'] ?? ''));
    $endRaw = trim((string) ($data['end_date'] ?? ''));
    $end = $endRaw === '' ? null : $endRaw;

    if ($name === '' || mb_strlen($name) > 100) {
        throw new InvalidArgumentException('Batch name is required and must be at most 100 characters.');
    }

    if ($year === false || $year < intake_year_min() || $year > intake_year_max()) {
        throw new InvalidArgumentException(
            'Intake year must be between ' . intake_year_min() . ' and ' . intake_year_max() . '.'
        );
    }

    if ($start === '' || !validate_date_ymd($start)) {
        throw new InvalidArgumentException('Enter a valid start date.');
    }

    if ($end !== null && !validate_date_ymd($end)) {
        throw new InvalidArgumentException('Enter a valid end date.');
    }

    if ($end !== null && $end < $start) {
        throw new InvalidArgumentException('End date must be on or after the start date.');
    }

    if (!in_array($status, batch_statuses(), true)) {
        throw new InvalidArgumentException('Select a valid batch status.');
    }

    if (batch_name_exists_for_course($courseId, $name, $excludeBatchId)) {
        throw new InvalidArgumentException('That batch name already exists for this course.');
    }

    return [
        'course_id' => $courseId,
        'batch_name' => $name,
        'intake_year' => $year,
        'start_date' => $start,
        'end_date' => $end,
        'status' => $status,
    ];
}

function username_exists(string $username, ?int $excludeUserId = null): bool
{
    $sql = 'SELECT user_id FROM users WHERE username = :username';
    $params = ['username' => $username];

    if ($excludeUserId !== null) {
        $sql .= ' AND user_id <> :user_id';
        $params['user_id'] = $excludeUserId;
    }

    $statement = db()->prepare($sql . ' LIMIT 1');
    $statement->execute($params);

    return $statement->fetch() !== false;
}

function email_exists(string $email, ?int $excludeUserId = null): bool
{
    $sql = 'SELECT user_id FROM users WHERE email = :email';
    $params = ['email' => $email];

    if ($excludeUserId !== null) {
        $sql .= ' AND user_id <> :user_id';
        $params['user_id'] = $excludeUserId;
    }

    $statement = db()->prepare($sql . ' LIMIT 1');
    $statement->execute($params);

    return $statement->fetch() !== false;
}

function registration_no_exists(string $registrationNo, ?int $excludeStudentId = null): bool
{
    $sql = 'SELECT student_id FROM students WHERE registration_no = :registration_no';
    $params = ['registration_no' => $registrationNo];

    if ($excludeStudentId !== null) {
        $sql .= ' AND student_id <> :student_id';
        $params['student_id'] = $excludeStudentId;
    }

    $statement = db()->prepare($sql . ' LIMIT 1');
    $statement->execute($params);

    return $statement->fetch() !== false;
}

function staff_no_exists(string $table, string $staffNo, ?int $excludeId = null): bool
{
    if (!in_array($table, ['lecturers', 'academic_staff'], true)) {
        return false;
    }

    $idColumn = $table === 'lecturers' ? 'lecturer_id' : 'academic_staff_id';
    $sql = "SELECT {$idColumn} FROM {$table} WHERE staff_no = :staff_no";
    $params = ['staff_no' => $staffNo];

    if ($excludeId !== null) {
        $sql .= " AND {$idColumn} <> :exclude_id";
        $params['exclude_id'] = $excludeId;
    }

    $statement = db()->prepare($sql . ' LIMIT 1');
    $statement->execute($params);

    return $statement->fetch() !== false;
}

/**
 * Roles managed by Admin User Management (not STUDENT).
 *
 * @return list<string>
 */
function user_management_roles(): array
{
    return ['ADMIN', 'ACADEMIC_STAFF', 'LECTURER'];
}

/**
 * @param array{search?: string, role?: string, status?: string} $filters
 * @return list<array<string, mixed>>
 */
function list_users(array $filters = []): array
{
    $sql = "SELECT user_id, username, email, role, status, created_at, updated_at
            FROM users
            WHERE role IN ('ADMIN', 'ACADEMIC_STAFF', 'LECTURER')";
    $params = [];

    if (!empty($filters['search'])) {
        $sql .= ' AND (username LIKE :search OR email LIKE :search)';
        $params['search'] = '%' . $filters['search'] . '%';
    }

    if (!empty($filters['role'])) {
        if (!in_array($filters['role'], user_management_roles(), true)) {
            return [];
        }
        $sql .= ' AND role = :role';
        $params['role'] = $filters['role'];
    }

    if (!empty($filters['status']) && in_array($filters['status'], user_statuses(), true)) {
        $sql .= ' AND status = :status';
        $params['status'] = $filters['status'];
    }

    $sql .= ' ORDER BY created_at DESC, user_id DESC';

    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function get_user(int $userId): ?array
{
    $statement = db()->prepare(
        'SELECT user_id, username, email, role, status, created_at, updated_at
         FROM users
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $statement->execute(['user_id' => $userId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * Create a User Management account (ADMIN / ACADEMIC_STAFF / LECTURER only).
 * STUDENT accounts must be created via Student Management → Register Student.
 *
 * @param array{username: string, email: string, password: string, role: string, status: string} $data
 */
function create_user(array $data): int
{
    if (username_exists($data['username'])) {
        throw new InvalidArgumentException('Username is already in use.');
    }

    if (email_exists($data['email'])) {
        throw new InvalidArgumentException('Email is already in use.');
    }

    $role = (string) ($data['role'] ?? '');
    if ($role === 'STUDENT') {
        throw new InvalidArgumentException(student_accounts_managed_elsewhere_message() . ' Use Student Management → Register Student.');
    }
    if (!in_array($role, user_management_roles(), true)) {
        throw new InvalidArgumentException('Invalid role selected.');
    }

    if (!in_array($data['status'], user_statuses(), true)) {
        throw new InvalidArgumentException('Invalid account status selected.');
    }

    $statement = db()->prepare(
        'INSERT INTO users (username, email, password_hash, role, status)
         VALUES (:username, :email, :password_hash, :role, :status)'
    );
    $statement->execute([
        'username' => $data['username'],
        'email' => $data['email'],
        'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
        'role' => $role,
        'status' => $data['status'],
    ]);

    return (int) db()->lastInsertId();
}

/**
 * Canonical message when generic User Management targets a STUDENT account.
 */
function student_accounts_managed_elsewhere_message(): string
{
    return 'Student accounts are managed from Student Management.';
}

/**
 * Canonical message when generic User Management tries to change a STUDENT login status.
 */
function student_status_managed_elsewhere_message(): string
{
    return 'Student account status is managed from Student Management. Use Deactivate Student / Reactivate Student on the student record.';
}

/**
 * Linked student_id for a login account, if a student profile exists.
 */
function get_student_id_by_user_id(int $userId): ?int
{
    $statement = db()->prepare(
        'SELECT student_id FROM students WHERE user_id = :user_id LIMIT 1'
    );
    $statement->execute(['user_id' => $userId]);
    $value = $statement->fetchColumn();

    return $value === false ? null : (int) $value;
}

/**
 * True when ACTIVE/INACTIVE profile and login statuses disagree
 * (the desync User Management could previously create).
 * GRADUATED/SUSPENDED combinations are left for Student Management semantics.
 *
 * @param array<string, mixed> $student Row from get_student() (needs status + account_status)
 */
function student_account_status_is_mismatched(array $student): bool
{
    $profile = (string) ($student['status'] ?? '');
    $account = (string) ($student['account_status'] ?? '');

    return ($profile === 'ACTIVE' && $account === 'INACTIVE')
        || ($profile === 'INACTIVE' && $account === 'ACTIVE');
}

/**
 * Reject independent STUDENT login status changes via User Management helpers.
 * Does not write anything — call before mutating users.status for STUDENT accounts.
 */
function assert_user_management_may_change_status(array $user, string $newStatus): void
{
    if ((string) ($user['role'] ?? '') !== 'STUDENT') {
        return;
    }

    $current = (string) ($user['status'] ?? '');
    if ($newStatus === $current) {
        return;
    }

    throw new InvalidArgumentException(student_status_managed_elsewhere_message());
}

/**
 * @param array{username?: string, email?: string, role?: string, status?: string, password?: string|null} $data
 */
function update_user(int $userId, array $data): void
{
    $user = get_user($userId);
    if ($user === null) {
        throw new InvalidArgumentException('User not found.');
    }

    if ((string) $user['role'] === 'STUDENT') {
        throw new InvalidArgumentException(student_accounts_managed_elsewhere_message());
    }

    $username = $data['username'] ?? $user['username'];
    $email = $data['email'] ?? $user['email'];
    $role = $data['role'] ?? $user['role'];
    $status = $data['status'] ?? $user['status'];

    if (username_exists($username, $userId)) {
        throw new InvalidArgumentException('Username is already in use.');
    }

    if (email_exists($email, $userId)) {
        throw new InvalidArgumentException('Email is already in use.');
    }

    if ((string) $role === 'STUDENT') {
        throw new InvalidArgumentException(student_accounts_managed_elsewhere_message() . ' Use Student Management → Register Student.');
    }

    if (!in_array($role, user_management_roles(), true)) {
        throw new InvalidArgumentException('Invalid role selected.');
    }

    if (!in_array($status, user_statuses(), true)) {
        throw new InvalidArgumentException('Invalid account status selected.');
    }

    assert_user_management_may_change_status($user, (string) $status);

    $sql = 'UPDATE users SET username = :username, email = :email, role = :role, status = :status';
    $params = [
        'username' => $username,
        'email' => $email,
        'role' => $role,
        'status' => $status,
        'user_id' => $userId,
    ];

    if (!empty($data['password'])) {
        $sql .= ', password_hash = :password_hash';
        $params['password_hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
    }

    $sql .= ' WHERE user_id = :user_id';

    $statement = db()->prepare($sql);
    $statement->execute($params);
}

function set_user_status(int $userId, string $status): void
{
    if (!in_array($status, user_statuses(), true)) {
        throw new InvalidArgumentException('Invalid account status selected.');
    }

    $user = get_user($userId);
    if ($user === null) {
        throw new InvalidArgumentException('User not found.');
    }

    assert_user_management_may_change_status($user, $status);

    if ((string) $user['status'] === $status) {
        return;
    }

    $statement = db()->prepare(
        'UPDATE users SET status = :status WHERE user_id = :user_id'
    );
    $statement->execute([
        'status' => $status,
        'user_id' => $userId,
    ]);
}

/**
 * @param array{search?: string, course_id?: int, batch_id?: int, status?: string} $filters
 * @return list<array<string, mixed>>
 */
function list_students(array $filters = []): array
{
    $sql = "SELECT s.student_id, s.registration_no, s.first_name, s.last_name, s.phone,
                   s.date_of_birth, s.gender, s.enrollment_date, s.status,
                   u.user_id, u.username, u.email, u.status AS account_status,
                   c.course_id, c.course_code, c.course_name,
                   b.batch_id, b.batch_name,
                   CASE
                       WHEN fp.face_profile_id IS NOT NULL AND fp.status = 'ACTIVE' THEN 'ENROLLED'
                       ELSE 'NOT ENROLLED'
                   END AS face_status
            FROM students s
            INNER JOIN users u ON u.user_id = s.user_id
            INNER JOIN courses c ON c.course_id = s.course_id
            INNER JOIN batches b ON b.batch_id = s.batch_id
            LEFT JOIN face_profiles fp ON fp.student_id = s.student_id AND fp.status = 'ACTIVE'
            WHERE 1=1";
    $params = [];

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
        $params['course_id'] = $filters['course_id'];
    }

    if (!empty($filters['batch_id'])) {
        $sql .= ' AND s.batch_id = :batch_id';
        $params['batch_id'] = $filters['batch_id'];
    }

    if (!empty($filters['status']) && in_array($filters['status'], student_statuses(), true)) {
        $sql .= ' AND s.status = :status';
        $params['status'] = $filters['status'];
    }

    $sql .= ' ORDER BY s.created_at DESC, s.student_id DESC';

    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function get_student(int $studentId): ?array
{
    $statement = db()->prepare(
        "SELECT s.student_id, s.user_id, s.registration_no, s.first_name, s.last_name, s.phone,
                s.date_of_birth, s.gender, s.course_id, s.batch_id, s.enrollment_date, s.status,
                s.profile_photo,
                u.username, u.email, u.status AS account_status,
                c.course_code, c.course_name,
                b.batch_name,
                CASE
                    WHEN fp.face_profile_id IS NOT NULL AND fp.status = 'ACTIVE' THEN 'ENROLLED'
                    ELSE 'NOT ENROLLED'
                END AS face_status
         FROM students s
         INNER JOIN users u ON u.user_id = s.user_id
         INNER JOIN courses c ON c.course_id = s.course_id
         INNER JOIN batches b ON b.batch_id = s.batch_id
         LEFT JOIN face_profiles fp ON fp.student_id = s.student_id AND fp.status = 'ACTIVE'
         WHERE s.student_id = :student_id
         LIMIT 1"
    );
    $statement->execute(['student_id' => $studentId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

function resolve_inserted_student_id(PDO $pdo, int $userId): int
{
    $fromInsert = (int) $pdo->lastInsertId();
    $statement = $pdo->prepare(
        'SELECT student_id FROM students WHERE user_id = :user_id LIMIT 1'
    );
    $statement->execute(['user_id' => $userId]);
    $resolved = (int) $statement->fetchColumn();
    if ($resolved > 0) {
        return $resolved;
    }
    if ($fromInsert > 0 && $fromInsert !== $userId) {
        return $fromInsert;
    }

    throw new RuntimeException('Unable to resolve the new student id after insert.');
}

/**
 * @param array<string, mixed> $data
 */
function register_student(array $data): int
{
    if (username_exists($data['username'])) {
        throw new InvalidArgumentException('Username is already in use.');
    }

    if (email_exists($data['email'])) {
        throw new InvalidArgumentException('Email is already in use.');
    }

    if (registration_no_exists($data['registration_no'])) {
        throw new InvalidArgumentException('Registration number is already in use.');
    }

    assert_active_course_and_batch((int) $data['course_id'], (int) $data['batch_id']);

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $userStatement = $pdo->prepare(
            "INSERT INTO users (username, email, password_hash, role, status)
             VALUES (:username, :email, :password_hash, 'STUDENT', :status)"
        );
        $userStatement->execute([
            'username' => $data['username'],
            'email' => $data['email'],
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            'status' => $data['account_status'],
        ]);

        $userId = (int) $pdo->lastInsertId();

        $studentStatement = $pdo->prepare(
            'INSERT INTO students
                (user_id, registration_no, first_name, last_name, phone, date_of_birth, gender,
                 course_id, batch_id, enrollment_date, status)
             VALUES
                (:user_id, :registration_no, :first_name, :last_name, :phone, :date_of_birth, :gender,
                 :course_id, :batch_id, :enrollment_date, :status)'
        );
        $studentStatement->execute([
            'user_id' => $userId,
            'registration_no' => $data['registration_no'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone' => $data['phone'] ?: null,
            'date_of_birth' => $data['date_of_birth'] ?: null,
            'gender' => $data['gender'] ?: null,
            'course_id' => $data['course_id'],
            'batch_id' => $data['batch_id'],
            'enrollment_date' => $data['enrollment_date'],
            'status' => $data['status'],
        ]);

        $studentId = resolve_inserted_student_id($pdo, $userId);
        if (($data['status'] ?? '') === 'ACTIVE') {
            auto_enrol_student_into_batch_modules($studentId, $pdo);
        }
        $pdo->commit();

        return $studentId;
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/**
 * @param array<string, mixed> $data
 */
function update_student(int $studentId, array $data): void
{
    $student = get_student($studentId);
    if ($student === null) {
        throw new InvalidArgumentException('Student not found.');
    }

    $userId = (int) $student['user_id'];

    if (registration_no_exists($data['registration_no'], $studentId)) {
        throw new InvalidArgumentException('Registration number is already in use.');
    }

    if (email_exists($data['email'], $userId)) {
        throw new InvalidArgumentException('Email is already in use.');
    }

    assert_course_batch_for_student_update($student, (int) $data['course_id'], (int) $data['batch_id']);

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $userStatement = $pdo->prepare(
            'UPDATE users SET email = :email, status = :status WHERE user_id = :user_id'
        );
        $userStatement->execute([
            'email' => $data['email'],
            'status' => $data['account_status'],
            'user_id' => $userId,
        ]);

        $studentStatement = $pdo->prepare(
            'UPDATE students
             SET registration_no = :registration_no,
                 first_name = :first_name,
                 last_name = :last_name,
                 phone = :phone,
                 date_of_birth = :date_of_birth,
                 gender = :gender,
                 course_id = :course_id,
                 batch_id = :batch_id,
                 enrollment_date = :enrollment_date,
                 status = :status
             WHERE student_id = :student_id'
        );
        $studentStatement->execute([
            'registration_no' => $data['registration_no'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone' => $data['phone'] ?: null,
            'date_of_birth' => $data['date_of_birth'] ?: null,
            'gender' => $data['gender'] ?: null,
            'course_id' => $data['course_id'],
            'batch_id' => $data['batch_id'],
            'enrollment_date' => $data['enrollment_date'],
            'status' => $data['status'],
            'student_id' => $studentId,
        ]);

        if (($data['status'] ?? '') === 'ACTIVE') {
            auto_enrol_student_into_batch_modules($studentId, $pdo);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/**
 * Soft-deactivate a student: preserve all history; block login and new attendance.
 * Sets students.status and linked users.status to INACTIVE.
 * Does not delete face profiles, enrolments, attendance, marks, or photos.
 */
function deactivate_student(int $studentId): void
{
    $student = get_student($studentId);
    if ($student === null) {
        throw new InvalidArgumentException('Student not found.');
    }
    if ((string) $student['status'] === 'INACTIVE') {
        throw new InvalidArgumentException('Student is already inactive.');
    }
    if ((string) $student['status'] !== 'ACTIVE') {
        throw new InvalidArgumentException('Only ACTIVE students can be deactivated from this action.');
    }

    $userId = (int) $student['user_id'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE students SET status = 'INACTIVE' WHERE student_id = :student_id"
        )->execute(['student_id' => $studentId]);
        $pdo->prepare(
            "UPDATE users SET status = 'INACTIVE' WHERE user_id = :user_id AND role = 'STUDENT'"
        )->execute(['user_id' => $userId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/**
 * Reactivate an INACTIVE student and linked STUDENT login account.
 * Does not alter attendance, marks, submissions, enrolments, or face data.
 */
function reactivate_student(int $studentId): void
{
    $student = get_student($studentId);
    if ($student === null) {
        throw new InvalidArgumentException('Student not found.');
    }
    if ((string) $student['status'] === 'ACTIVE') {
        throw new InvalidArgumentException('Student is already active.');
    }
    if ((string) $student['status'] !== 'INACTIVE') {
        throw new InvalidArgumentException('Only INACTIVE students can be reactivated from this action.');
    }

    $userId = (int) $student['user_id'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE students SET status = 'ACTIVE' WHERE student_id = :student_id"
        )->execute(['student_id' => $studentId]);
        $pdo->prepare(
            "UPDATE users SET status = 'ACTIVE' WHERE user_id = :user_id AND role = 'STUDENT'"
        )->execute(['user_id' => $userId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/**
 * @param array{search?: string, status?: string} $filters
 * @return list<array<string, mixed>>
 */
function list_lecturers(array $filters = []): array
{
    $sql = "SELECT l.lecturer_id, l.staff_no, l.first_name, l.last_name, l.phone, l.department, l.status,
                   u.user_id, u.username, u.email, u.status AS account_status
            FROM lecturers l
            INNER JOIN users u ON u.user_id = l.user_id
            WHERE 1=1";
    $params = [];

    if (!empty($filters['search'])) {
        $sql .= ' AND (l.staff_no LIKE :search OR l.first_name LIKE :search OR l.last_name LIKE :search OR u.email LIKE :search)';
        $params['search'] = '%' . $filters['search'] . '%';
    }

    if (!empty($filters['status']) && in_array($filters['status'], staff_statuses(), true)) {
        $sql .= ' AND l.status = :status';
        $params['status'] = $filters['status'];
    }

    $sql .= ' ORDER BY l.created_at DESC, l.lecturer_id DESC';

    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function get_lecturer(int $lecturerId): ?array
{
    $statement = db()->prepare(
        "SELECT l.lecturer_id, l.user_id, l.staff_no, l.first_name, l.last_name, l.phone, l.department, l.status,
                u.username, u.email, u.status AS account_status
         FROM lecturers l
         INNER JOIN users u ON u.user_id = l.user_id
         WHERE l.lecturer_id = :lecturer_id
         LIMIT 1"
    );
    $statement->execute(['lecturer_id' => $lecturerId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * @param array<string, mixed> $data
 */
function create_lecturer(array $data): int
{
    if (username_exists($data['username'])) {
        throw new InvalidArgumentException('Username is already in use.');
    }

    if (email_exists($data['email'])) {
        throw new InvalidArgumentException('Email is already in use.');
    }

    if (staff_no_exists('lecturers', $data['staff_no'])) {
        throw new InvalidArgumentException('Staff number is already in use.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $userStatement = $pdo->prepare(
            "INSERT INTO users (username, email, password_hash, role, status)
             VALUES (:username, :email, :password_hash, 'LECTURER', :status)"
        );
        $userStatement->execute([
            'username' => $data['username'],
            'email' => $data['email'],
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            'status' => $data['account_status'],
        ]);

        $userId = (int) $pdo->lastInsertId();

        $lecturerStatement = $pdo->prepare(
            'INSERT INTO lecturers (user_id, staff_no, first_name, last_name, phone, department, status)
             VALUES (:user_id, :staff_no, :first_name, :last_name, :phone, :department, :status)'
        );
        $lecturerStatement->execute([
            'user_id' => $userId,
            'staff_no' => $data['staff_no'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone' => $data['phone'] ?: null,
            'department' => $data['department'] ?: null,
            'status' => $data['status'],
        ]);

        $lecturerId = (int) $pdo->lastInsertId();
        $pdo->commit();

        return $lecturerId;
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/**
 * @param array<string, mixed> $data
 */
function update_lecturer(int $lecturerId, array $data): void
{
    $lecturer = get_lecturer($lecturerId);
    if ($lecturer === null) {
        throw new InvalidArgumentException('Lecturer not found.');
    }

    $userId = (int) $lecturer['user_id'];

    if (email_exists($data['email'], $userId)) {
        throw new InvalidArgumentException('Email is already in use.');
    }

    if (staff_no_exists('lecturers', $data['staff_no'], $lecturerId)) {
        throw new InvalidArgumentException('Staff number is already in use.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $userSql = 'UPDATE users SET email = :email, status = :status';
        $params = [
            'email' => $data['email'],
            'status' => $data['account_status'],
            'user_id' => $userId,
        ];

        if (!empty($data['password'])) {
            $userSql .= ', password_hash = :password_hash';
            $params['password_hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        }

        $userSql .= ' WHERE user_id = :user_id';

        $userStatement = $pdo->prepare($userSql);
        $userStatement->execute($params);

        $lecturerStatement = $pdo->prepare(
            'UPDATE lecturers
             SET staff_no = :staff_no,
                 first_name = :first_name,
                 last_name = :last_name,
                 phone = :phone,
                 department = :department,
                 status = :status
             WHERE lecturer_id = :lecturer_id'
        );
        $lecturerStatement->execute([
            'staff_no' => $data['staff_no'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone' => $data['phone'] ?: null,
            'department' => $data['department'] ?: null,
            'status' => $data['status'],
            'lecturer_id' => $lecturerId,
        ]);

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/**
 * @param array{search?: string, status?: string} $filters
 * @return list<array<string, mixed>>
 */
function list_academic_staff(array $filters = []): array
{
    $sql = "SELECT a.academic_staff_id, a.staff_no, a.first_name, a.last_name, a.phone, a.position, a.status,
                   u.user_id, u.username, u.email, u.status AS account_status
            FROM academic_staff a
            INNER JOIN users u ON u.user_id = a.user_id
            WHERE 1=1";
    $params = [];

    if (!empty($filters['search'])) {
        $sql .= ' AND (a.staff_no LIKE :search OR a.first_name LIKE :search OR a.last_name LIKE :search OR u.email LIKE :search)';
        $params['search'] = '%' . $filters['search'] . '%';
    }

    if (!empty($filters['status']) && in_array($filters['status'], staff_statuses(), true)) {
        $sql .= ' AND a.status = :status';
        $params['status'] = $filters['status'];
    }

    $sql .= ' ORDER BY a.created_at DESC, a.academic_staff_id DESC';

    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function get_academic_staff_member(int $staffId): ?array
{
    $statement = db()->prepare(
        "SELECT a.academic_staff_id, a.user_id, a.staff_no, a.first_name, a.last_name, a.phone, a.position, a.status,
                u.username, u.email, u.status AS account_status
         FROM academic_staff a
         INNER JOIN users u ON u.user_id = a.user_id
         WHERE a.academic_staff_id = :academic_staff_id
         LIMIT 1"
    );
    $statement->execute(['academic_staff_id' => $staffId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * @param array<string, mixed> $data
 */
function create_academic_staff_member(array $data): int
{
    if (username_exists($data['username'])) {
        throw new InvalidArgumentException('Username is already in use.');
    }

    if (email_exists($data['email'])) {
        throw new InvalidArgumentException('Email is already in use.');
    }

    if (staff_no_exists('academic_staff', $data['staff_no'])) {
        throw new InvalidArgumentException('Staff number is already in use.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $userStatement = $pdo->prepare(
            "INSERT INTO users (username, email, password_hash, role, status)
             VALUES (:username, :email, :password_hash, 'ACADEMIC_STAFF', :status)"
        );
        $userStatement->execute([
            'username' => $data['username'],
            'email' => $data['email'],
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            'status' => $data['account_status'],
        ]);

        $userId = (int) $pdo->lastInsertId();

        $staffStatement = $pdo->prepare(
            'INSERT INTO academic_staff (user_id, staff_no, first_name, last_name, phone, position, status)
             VALUES (:user_id, :staff_no, :first_name, :last_name, :phone, :position, :status)'
        );
        $staffStatement->execute([
            'user_id' => $userId,
            'staff_no' => $data['staff_no'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone' => $data['phone'] ?: null,
            'position' => $data['position'] ?: null,
            'status' => $data['status'],
        ]);

        $staffId = (int) $pdo->lastInsertId();
        $pdo->commit();

        return $staffId;
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/**
 * @param array<string, mixed> $data
 */
function update_academic_staff_member(int $staffId, array $data): void
{
    $staff = get_academic_staff_member($staffId);
    if ($staff === null) {
        throw new InvalidArgumentException('Academic staff member not found.');
    }

    $userId = (int) $staff['user_id'];

    if (email_exists($data['email'], $userId)) {
        throw new InvalidArgumentException('Email is already in use.');
    }

    if (staff_no_exists('academic_staff', $data['staff_no'], $staffId)) {
        throw new InvalidArgumentException('Staff number is already in use.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $userSql = 'UPDATE users SET email = :email, status = :status';
        $params = [
            'email' => $data['email'],
            'status' => $data['account_status'],
            'user_id' => $userId,
        ];

        if (!empty($data['password'])) {
            $userSql .= ', password_hash = :password_hash';
            $params['password_hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        }

        $userSql .= ' WHERE user_id = :user_id';

        $userStatement = $pdo->prepare($userSql);
        $userStatement->execute($params);

        $staffStatement = $pdo->prepare(
            'UPDATE academic_staff
             SET staff_no = :staff_no,
                 first_name = :first_name,
                 last_name = :last_name,
                 phone = :phone,
                 position = :position,
                 status = :status
             WHERE academic_staff_id = :academic_staff_id'
        );
        $staffStatement->execute([
            'staff_no' => $data['staff_no'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone' => $data['phone'] ?: null,
            'position' => $data['position'] ?: null,
            'status' => $data['status'],
            'academic_staff_id' => $staffId,
        ]);

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/**
 * @return list<array{label: string, href: string, active?: bool}>
 */
/**
 * Flat role navigation (labels/hrefs unchanged for authorization & tests).
 * Optional keys: group, icon (Bootstrap Icons name without bi- prefix).
 *
 * @return list<array{label: string, href: string, group?: string, icon?: string}>
 */
function management_nav_items(string $role): array
{
    return match ($role) {
        'ADMIN' => [
            ['label' => 'Dashboard', 'href' => app_url('admin/dashboard.php'), 'group' => 'OVERVIEW', 'icon' => 'speedometer2'],
            ['label' => 'User Management', 'href' => app_url('admin/users/index.php'), 'group' => 'PEOPLE', 'icon' => 'people'],
            ['label' => 'Student Management', 'href' => app_url('admin/students/index.php'), 'group' => 'PEOPLE', 'icon' => 'mortarboard'],
            ['label' => 'Lecturer Management', 'href' => app_url('admin/lecturers/index.php'), 'group' => 'PEOPLE', 'icon' => 'person-badge'],
            ['label' => 'Academic Staff', 'href' => app_url('admin/academic-staff/index.php'), 'group' => 'PEOPLE', 'icon' => 'person-workspace'],
            ['label' => 'Courses', 'href' => app_url('admin/courses/index.php'), 'group' => 'ACADEMIC', 'icon' => 'building'],
            ['label' => 'Batches', 'href' => app_url('admin/batches/index.php'), 'group' => 'ACADEMIC', 'icon' => 'collection'],
            ['label' => 'Module Catalogue', 'href' => app_url('admin/modules/index.php'), 'group' => 'ACADEMIC', 'icon' => 'journal-bookmark'],
            ['label' => 'Lecturer Assignments', 'href' => app_url('admin/module-assignments/index.php'), 'group' => 'ACADEMIC', 'icon' => 'diagram-3'],
            ['label' => 'Module Enrollment', 'href' => app_url('admin/enrollments/index.php'), 'group' => 'ACADEMIC', 'icon' => 'person-check'],
            ['label' => 'Lecture Calendar', 'href' => app_url('admin/calendar/index.php'), 'group' => 'TEACHING', 'icon' => 'calendar-event'],
            ['label' => 'Lecture Sessions', 'href' => app_url('admin/sessions/index.php'), 'group' => 'TEACHING', 'icon' => 'calendar-check'],
            ['label' => 'Camera Management', 'href' => app_url('admin/camera.php'), 'group' => 'TEACHING', 'icon' => 'camera-video'],
            ['label' => 'Attendance Reports', 'href' => app_url('admin/attendance/reports.php'), 'group' => 'TEACHING', 'icon' => 'clipboard-check'],
            ['label' => 'Coursework Monitor', 'href' => app_url('admin/assignments/index.php'), 'group' => 'ASSESSMENT', 'icon' => 'journal-text'],
            ['label' => 'Marks Monitor', 'href' => app_url('admin/marks/index.php'), 'group' => 'ASSESSMENT', 'icon' => 'bar-chart'],
            ['label' => 'Announcements', 'href' => app_url('admin/announcements/index.php'), 'group' => 'COMMUNICATION', 'icon' => 'megaphone'],
            ['label' => 'Events', 'href' => app_url('admin/events/index.php'), 'group' => 'COMMUNICATION', 'icon' => 'calendar2-event'],
        ],
        'ACADEMIC_STAFF' => [
            ['label' => 'Dashboard', 'href' => app_url('academic-staff/dashboard.php'), 'group' => 'OVERVIEW', 'icon' => 'speedometer2'],
            ['label' => 'Student Management', 'href' => app_url('academic-staff/students/index.php'), 'group' => 'PEOPLE', 'icon' => 'mortarboard'],
            ['label' => 'Lecturers', 'href' => app_url('academic-staff/lecturers/index.php'), 'group' => 'PEOPLE', 'icon' => 'person-badge'],
            ['label' => 'Courses', 'href' => app_url('academic-staff/courses/index.php'), 'group' => 'ACADEMIC', 'icon' => 'building'],
            ['label' => 'Batches', 'href' => app_url('academic-staff/batches/index.php'), 'group' => 'ACADEMIC', 'icon' => 'collection'],
            ['label' => 'Module Catalogue', 'href' => app_url('academic-staff/modules/index.php'), 'group' => 'ACADEMIC', 'icon' => 'journal-bookmark'],
            ['label' => 'Lecturer Assignments', 'href' => app_url('academic-staff/module-assignments/index.php'), 'group' => 'ACADEMIC', 'icon' => 'diagram-3'],
            ['label' => 'Module Enrollment', 'href' => app_url('academic-staff/enrollments/index.php'), 'group' => 'ACADEMIC', 'icon' => 'person-check'],
            ['label' => 'Lecture Calendar', 'href' => app_url('academic-staff/calendar/index.php'), 'group' => 'TEACHING', 'icon' => 'calendar-event'],
            ['label' => 'Lecture Sessions', 'href' => app_url('academic-staff/sessions/index.php'), 'group' => 'TEACHING', 'icon' => 'calendar-check'],
            ['label' => 'Camera Management', 'href' => app_url('academic-staff/camera.php'), 'group' => 'TEACHING', 'icon' => 'camera-video'],
            ['label' => 'Attendance Reports', 'href' => app_url('academic-staff/attendance/reports.php'), 'group' => 'TEACHING', 'icon' => 'clipboard-check'],
            ['label' => 'Coursework Monitor', 'href' => app_url('academic-staff/assignments/index.php'), 'group' => 'ASSESSMENT', 'icon' => 'journal-text'],
            ['label' => 'Marks Monitor', 'href' => app_url('academic-staff/marks/index.php'), 'group' => 'ASSESSMENT', 'icon' => 'bar-chart'],
            ['label' => 'Announcements', 'href' => app_url('academic-staff/announcements/index.php'), 'group' => 'COMMUNICATION', 'icon' => 'megaphone'],
            ['label' => 'Events', 'href' => app_url('academic-staff/events/index.php'), 'group' => 'COMMUNICATION', 'icon' => 'calendar2-event'],
        ],
        'LECTURER' => [
            ['label' => 'Dashboard', 'href' => app_url('lecturer/dashboard.php'), 'group' => 'OVERVIEW', 'icon' => 'speedometer2'],
            ['label' => 'Students', 'href' => app_url('lecturer/students/index.php'), 'group' => 'TEACHING', 'icon' => 'people'],
            ['label' => 'My Calendar', 'href' => app_url('lecturer/schedules/index.php'), 'group' => 'TEACHING', 'icon' => 'calendar-event'],
            ['label' => 'Lecture Sessions', 'href' => app_url('lecturer/sessions/index.php'), 'group' => 'TEACHING', 'icon' => 'calendar-check'],
            ['label' => 'Attendance Reports', 'href' => app_url('lecturer/attendance/reports.php'), 'group' => 'TEACHING', 'icon' => 'clipboard-check'],
            ['label' => 'Coursework Assignments', 'href' => app_url('lecturer/assignments/index.php'), 'group' => 'ACADEMIC', 'icon' => 'journal-text'],
            ['label' => 'Module Marks', 'href' => app_url('lecturer/marks/index.php'), 'group' => 'ACADEMIC', 'icon' => 'bar-chart'],
            ['label' => 'Announcements', 'href' => app_url('lecturer/announcements/index.php'), 'group' => 'COMMUNICATION', 'icon' => 'megaphone'],
            ['label' => 'Events', 'href' => app_url('lecturer/events/index.php'), 'group' => 'COMMUNICATION', 'icon' => 'calendar2-event'],
        ],
        'STUDENT' => [
            ['label' => 'Dashboard', 'href' => app_url('student/dashboard.php'), 'group' => 'MENU', 'icon' => 'speedometer2'],
            ['label' => 'My Timetable', 'href' => app_url('student/timetable.php'), 'group' => 'MENU', 'icon' => 'calendar3'],
            ['label' => 'My Attendance', 'href' => app_url('student/attendance.php'), 'group' => 'MENU', 'icon' => 'clipboard-check'],
            ['label' => 'My Assignments', 'href' => app_url('student/assignments/index.php'), 'group' => 'MENU', 'icon' => 'journal-text'],
            ['label' => 'My Results', 'href' => app_url('student/results/index.php'), 'group' => 'MENU', 'icon' => 'bar-chart'],
            ['label' => 'Announcements', 'href' => app_url('student/announcements/index.php'), 'group' => 'MENU', 'icon' => 'megaphone'],
            ['label' => 'Events', 'href' => app_url('student/events/index.php'), 'group' => 'MENU', 'icon' => 'calendar2-event'],
        ],
        default => [
            ['label' => 'Dashboard', 'href' => app_url(role_dashboard_path($role)), 'group' => 'OVERVIEW', 'icon' => 'speedometer2'],
        ],
    };
}

/**
 * Group flat nav items for sidebar rendering. Does not change accessible routes.
 *
 * @return list<array{label: string, items: list<array<string, mixed>>}>
 */
function management_nav_groups(string $role): array
{
    $grouped = [];
    foreach (management_nav_items($role) as $item) {
        $group = (string) ($item['group'] ?? 'MENU');
        if (!isset($grouped[$group])) {
            $grouped[$group] = [
                'label' => $group,
                'items' => [],
            ];
        }
        $grouped[$group]['items'][] = $item;
    }

    return array_values($grouped);
}

function nav_item_is_active(string $currentPath, string $itemHref): bool
{
    $current = rtrim((string) (parse_url($currentPath, PHP_URL_PATH) ?: $currentPath), '/');
    $item = rtrim((string) (parse_url($itemHref, PHP_URL_PATH) ?: $itemHref), '/');
    if ($current === '' || $item === '') {
        return false;
    }
    if ($current === $item) {
        return true;
    }
    if (str_ends_with($item, '/index.php')) {
        $dir = substr($item, 0, -strlen('/index.php'));
        return $dir !== '' && str_starts_with($current . '/', $dir . '/');
    }

    return false;
}

/**
 * Prefer the longest matching nav href when multiple items could be active.
 */
function resolve_active_nav_href(string $currentPath, array $navItems, ?string $profileHref = null): ?string
{
    $candidates = $navItems;
    if ($profileHref !== null && $profileHref !== '') {
        $candidates[] = ['href' => $profileHref];
    }

    $bestHref = null;
    $bestLen = -1;
    foreach ($candidates as $item) {
        $href = (string) ($item['href'] ?? '');
        if ($href === '' || !nav_item_is_active($currentPath, $href)) {
            continue;
        }
        $path = rtrim((string) (parse_url($href, PHP_URL_PATH) ?: $href), '/');
        $len = strlen($path);
        if ($len > $bestLen) {
            $bestLen = $len;
            $bestHref = $href;
        }
    }

    return $bestHref;
}

/** Read-only KPI helper for dashboards. */
function count_active_students(): int
{
    $statement = db()->query("SELECT COUNT(*) FROM students WHERE status = 'ACTIVE'");

    return (int) $statement->fetchColumn();
}

function status_badge_class(string $status): string
{
    return match ($status) {
        'ACTIVE', 'ENROLLED', 'IN_PROGRESS', 'PROMOTED', 'PRESENT', 'PUBLISHED', 'SUBMITTED', 'GRADED', 'Recorded', 'Active', 'Upcoming', 'Ongoing' => 'text-bg-success',
        'INACTIVE', 'NOT ENROLLED', 'COMPLETED', 'DRAFT', 'NOT SUBMITTED', 'Not Recorded', 'ARCHIVED', 'Past' => 'text-bg-secondary',
        'SCHEDULED', 'OPEN' => 'text-bg-primary',
        'SUSPENDED', 'DROPPED', 'LATE', 'Expired', 'Scheduled' => 'text-bg-warning',
        'CANCELLED', 'ABSENT', 'CLOSED', 'Cancelled' => 'text-bg-danger',
        'GRADUATED' => 'text-bg-info',
        default => 'text-bg-light',
    };
}
