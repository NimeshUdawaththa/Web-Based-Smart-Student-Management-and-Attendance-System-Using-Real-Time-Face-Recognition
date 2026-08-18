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
 * @return list<array<string, mixed>>
 */
function list_active_courses(): array
{
    $statement = db()->query(
        "SELECT course_id, course_code, course_name
         FROM courses
         WHERE status = 'ACTIVE'
         ORDER BY course_name"
    );

    return $statement->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function list_batches_for_course(int $courseId): array
{
    $statement = db()->prepare(
        "SELECT batch_id, batch_name, intake_year
         FROM batches
         WHERE course_id = :course_id
           AND status = 'ACTIVE'
         ORDER BY intake_year DESC, batch_name"
    );
    $statement->execute(['course_id' => $courseId]);

    return $statement->fetchAll();
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

/**
 * @return array<string, mixed>|null
 */
function get_course(int $courseId): ?array
{
    $statement = db()->prepare(
        'SELECT course_id, course_code, course_name
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
        'SELECT batch_id, course_id, batch_name
         FROM batches
         WHERE batch_id = :batch_id
         LIMIT 1'
    );
    $statement->execute(['batch_id' => $batchId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
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
 * @param array{search?: string, role?: string, status?: string} $filters
 * @return list<array<string, mixed>>
 */
function list_users(array $filters = []): array
{
    $sql = 'SELECT user_id, username, email, role, status, created_at, updated_at FROM users WHERE 1=1';
    $params = [];

    if (!empty($filters['search'])) {
        $sql .= ' AND (username LIKE :search OR email LIKE :search)';
        $params['search'] = '%' . $filters['search'] . '%';
    }

    if (!empty($filters['role']) && in_array($filters['role'], AUTH_ROLES, true)) {
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

    if (!in_array($data['role'], AUTH_ROLES, true)) {
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
        'role' => $data['role'],
        'status' => $data['status'],
    ]);

    return (int) db()->lastInsertId();
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

    if (!in_array($role, AUTH_ROLES, true)) {
        throw new InvalidArgumentException('Invalid role selected.');
    }

    if (!in_array($status, user_statuses(), true)) {
        throw new InvalidArgumentException('Invalid account status selected.');
    }

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

    $statement = db()->prepare(
        'UPDATE users SET status = :status WHERE user_id = :user_id'
    );
    $statement->execute([
        'status' => $status,
        'user_id' => $userId,
    ]);

    if ($statement->rowCount() === 0) {
        throw new InvalidArgumentException('User not found.');
    }
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
        $sql .= ' AND (s.registration_no LIKE :search OR s.first_name LIKE :search OR s.last_name LIKE :search OR u.email LIKE :search OR u.username LIKE :search)';
        $params['search'] = '%' . $filters['search'] . '%';
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

    if (!batch_belongs_to_course((int) $data['batch_id'], (int) $data['course_id'])) {
        throw new InvalidArgumentException('Selected batch does not belong to the selected course.');
    }

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

        $studentId = (int) $pdo->lastInsertId();
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

    if (!batch_belongs_to_course((int) $data['batch_id'], (int) $data['course_id'])) {
        throw new InvalidArgumentException('Selected batch does not belong to the selected course.');
    }

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
function management_nav_items(string $role): array
{
    return match ($role) {
        'ADMIN' => [
            ['label' => 'Dashboard', 'href' => app_url('admin/dashboard.php')],
            ['label' => 'User Management', 'href' => app_url('admin/users/index.php')],
            ['label' => 'Student Management', 'href' => app_url('admin/students/index.php')],
            ['label' => 'Lecturer Management', 'href' => app_url('admin/lecturers/index.php')],
            ['label' => 'Academic Staff', 'href' => app_url('admin/academic-staff/index.php')],
        ],
        'ACADEMIC_STAFF' => [
            ['label' => 'Dashboard', 'href' => app_url('academic-staff/dashboard.php')],
            ['label' => 'Student Management', 'href' => app_url('academic-staff/students/index.php')],
            ['label' => 'Lecturers', 'href' => app_url('academic-staff/lecturers/index.php')],
        ],
        'LECTURER' => [
            ['label' => 'Dashboard', 'href' => app_url('lecturer/dashboard.php')],
            ['label' => 'Students', 'href' => app_url('lecturer/students/index.php')],
        ],
        default => [
            ['label' => 'Dashboard', 'href' => app_url(role_dashboard_path($role))],
        ],
    };
}

function status_badge_class(string $status): string
{
    return match ($status) {
        'ACTIVE', 'ENROLLED' => 'text-bg-success',
        'INACTIVE', 'NOT ENROLLED' => 'text-bg-secondary',
        'SUSPENDED' => 'text-bg-warning',
        'GRADUATED' => 'text-bg-info',
        default => 'text-bg-light',
    };
}
