<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

const PROFILE_PASSWORD_MIN_LENGTH = 8;

function role_profile_path(string $role): string
{
    return match ($role) {
        'ADMIN' => 'admin/profile.php',
        'ACADEMIC_STAFF' => 'academic-staff/profile.php',
        'LECTURER' => 'lecturer/profile.php',
        'STUDENT' => 'student/profile.php',
        default => 'login.php',
    };
}

/**
 * @return array<string, mixed>|null
 */
function get_academic_staff_by_user_id(int $userId): ?array
{
    $statement = db()->prepare(
        "SELECT a.academic_staff_id, a.user_id, a.staff_no, a.first_name, a.last_name, a.phone, a.position, a.status,
                u.username, u.email, u.status AS account_status
         FROM academic_staff a
         INNER JOIN users u ON u.user_id = a.user_id
         WHERE a.user_id = :user_id
         LIMIT 1"
    );
    $statement->execute(['user_id' => $userId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * @return array<string, mixed>|null
 */
function current_academic_staff_profile(): ?array
{
    $user = current_user();
    if ($user === null || $user['role'] !== 'ACADEMIC_STAFF') {
        return null;
    }

    return get_academic_staff_by_user_id($user['user_id']);
}

/**
 * Own profile snapshot for the authenticated user only.
 *
 * @return array{
 *   account: array<string, mixed>,
 *   person: array<string, mixed>|null,
 *   display_name: string,
 *   editable: list<string>
 * }
 */
function load_own_profile(int $userId): array
{
    $account = get_user($userId);
    if ($account === null) {
        throw new InvalidArgumentException('Account not found.');
    }

    $role = (string) $account['role'];
    $person = null;
    $editable = ['email'];
    $displayName = (string) $account['username'];

    if ($role === 'STUDENT') {
        $statement = db()->prepare(
            "SELECT s.student_id, s.user_id, s.registration_no, s.first_name, s.last_name, s.phone,
                    s.date_of_birth, s.gender, s.course_id, s.batch_id, s.enrollment_date, s.status,
                    c.course_code, c.course_name, b.batch_name
             FROM students s
             INNER JOIN courses c ON c.course_id = s.course_id
             INNER JOIN batches b ON b.batch_id = s.batch_id
             WHERE s.user_id = :user_id
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId]);
        $person = $statement->fetch() ?: null;
        $editable = ['email', 'first_name', 'last_name', 'phone'];
        if (is_array($person)) {
            $displayName = trim((string) $person['first_name'] . ' ' . (string) $person['last_name']);
        }
    } elseif ($role === 'LECTURER') {
        $person = get_lecturer_by_user_id($userId);
        $editable = ['email', 'first_name', 'last_name', 'phone'];
        if (is_array($person)) {
            $displayName = trim((string) $person['first_name'] . ' ' . (string) $person['last_name']);
        }
    } elseif ($role === 'ACADEMIC_STAFF') {
        $person = get_academic_staff_by_user_id($userId);
        $editable = ['email', 'first_name', 'last_name', 'phone'];
        if (is_array($person)) {
            $displayName = trim((string) $person['first_name'] . ' ' . (string) $person['last_name']);
        }
    }

    return [
        'account' => $account,
        'person' => $person,
        'display_name' => $displayName !== '' ? $displayName : (string) $account['username'],
        'editable' => $editable,
    ];
}

function password_meets_policy(string $password): bool
{
    return strlen($password) >= PROFILE_PASSWORD_MIN_LENGTH;
}

/**
 * Update only self-service fields for the authenticated user.
 * Ignores any posted user_id / role / academic identifiers.
 *
 * @param array<string, mixed> $posted
 */
function update_own_profile(int $authenticatedUserId, array $posted): void
{
    $profile = load_own_profile($authenticatedUserId);
    $account = $profile['account'];
    $role = (string) $account['role'];
    $editable = $profile['editable'];

    $email = trim((string) ($posted['email'] ?? ''));
    if (!in_array('email', $editable, true)) {
        throw new InvalidArgumentException('Email cannot be updated for this account.');
    }
    if ($email === '' || !validate_email_address($email)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    if (email_exists($email, $authenticatedUserId)) {
        throw new InvalidArgumentException('Email is already in use.');
    }

    $firstName = trim((string) ($posted['first_name'] ?? ''));
    $lastName = trim((string) ($posted['last_name'] ?? ''));
    $phone = trim((string) ($posted['phone'] ?? ''));

    if (in_array('first_name', $editable, true) || in_array('last_name', $editable, true)) {
        if ($firstName === '' || $lastName === '') {
            throw new InvalidArgumentException('First name and last name are required.');
        }
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $userUpdate = $pdo->prepare(
            'UPDATE users SET email = :email WHERE user_id = :user_id'
        );
        $userUpdate->execute([
            'email' => $email,
            'user_id' => $authenticatedUserId,
        ]);

        if ($role === 'STUDENT' && is_array($profile['person'])) {
            $stmt = $pdo->prepare(
                'UPDATE students
                 SET first_name = :first_name, last_name = :last_name, phone = :phone
                 WHERE user_id = :user_id'
            );
            $stmt->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone === '' ? null : $phone,
                'user_id' => $authenticatedUserId,
            ]);
        } elseif ($role === 'LECTURER' && is_array($profile['person'])) {
            $stmt = $pdo->prepare(
                'UPDATE lecturers
                 SET first_name = :first_name, last_name = :last_name, phone = :phone
                 WHERE user_id = :user_id'
            );
            $stmt->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone === '' ? null : $phone,
                'user_id' => $authenticatedUserId,
            ]);
        } elseif ($role === 'ACADEMIC_STAFF' && is_array($profile['person'])) {
            $stmt = $pdo->prepare(
                'UPDATE academic_staff
                 SET first_name = :first_name, last_name = :last_name, phone = :phone
                 WHERE user_id = :user_id'
            );
            $stmt->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone === '' ? null : $phone,
                'user_id' => $authenticatedUserId,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function change_own_password(
    int $authenticatedUserId,
    string $currentPassword,
    string $newPassword,
    string $confirmPassword
): void {
    if ($authenticatedUserId <= 0) {
        throw new InvalidArgumentException('Not authenticated.');
    }

    $statement = db()->prepare(
        'SELECT user_id, password_hash FROM users WHERE user_id = :user_id LIMIT 1'
    );
    $statement->execute(['user_id' => $authenticatedUserId]);
    $row = $statement->fetch();
    if ($row === false || !is_string($row['password_hash'])) {
        throw new InvalidArgumentException('Account not found.');
    }

    if ($currentPassword === '' || !password_verify($currentPassword, $row['password_hash'])) {
        throw new InvalidArgumentException('Current password is incorrect.');
    }

    if ($newPassword === '' || $confirmPassword === '') {
        throw new InvalidArgumentException('Enter and confirm a new password.');
    }

    if ($newPassword !== $confirmPassword) {
        throw new InvalidArgumentException('New password and confirmation do not match.');
    }

    if (!password_meets_policy($newPassword)) {
        throw new InvalidArgumentException(
            'New password must be at least ' . PROFILE_PASSWORD_MIN_LENGTH . ' characters.'
        );
    }

    if (password_verify($newPassword, $row['password_hash'])) {
        throw new InvalidArgumentException('New password must be different from the current password.');
    }

    $update = db()->prepare(
        'UPDATE users SET password_hash = :password_hash WHERE user_id = :user_id'
    );
    $update->execute([
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'user_id' => $authenticatedUserId,
    ]);
}
