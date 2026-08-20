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

/** Safe display name for chrome (never exposes secrets). */
function current_user_display_name(): string
{
    $user = current_user();
    if ($user === null) {
        return '';
    }

    try {
        $profile = load_own_profile((int) $user['user_id']);
        $name = trim((string) ($profile['display_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
    } catch (Throwable) {
        // Fall through to username.
    }

    return (string) ($user['username'] ?? '');
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
                    s.profile_photo, c.course_code, c.course_name, b.batch_name
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

/**
 * Administrative password reset for a STUDENT account.
 * Target is always resolved from student_id → linked user_id.
 * Posted user_id / role / username fields must be ignored by callers.
 */
function admin_reset_student_password(int $studentId, string $newPassword, string $confirmPassword): void
{
    if ($studentId <= 0) {
        throw new InvalidArgumentException('Student not found.');
    }

    $student = get_student($studentId);
    if ($student === null) {
        throw new InvalidArgumentException('Student not found.');
    }

    $userId = (int) $student['user_id'];
    $statement = db()->prepare(
        'SELECT user_id, password_hash, role, status
         FROM users
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $statement->execute(['user_id' => $userId]);
    $user = $statement->fetch();
    if ($user === false) {
        throw new InvalidArgumentException('Linked user account not found.');
    }
    if ((string) $user['role'] !== 'STUDENT') {
        throw new InvalidArgumentException('Password reset is only allowed for student accounts.');
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

    if (is_string($user['password_hash']) && password_verify($newPassword, $user['password_hash'])) {
        throw new InvalidArgumentException('Choose a different password from the current one.');
    }

    $update = db()->prepare(
        'UPDATE users SET password_hash = :password_hash WHERE user_id = :user_id AND role = \'STUDENT\''
    );
    $update->execute([
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'user_id' => $userId,
    ]);

    if ($update->rowCount() < 1) {
        $verify = db()->prepare(
            'SELECT password_hash FROM users WHERE user_id = :user_id AND role = \'STUDENT\' LIMIT 1'
        );
        $verify->execute(['user_id' => $userId]);
        $hash = $verify->fetchColumn();
        if (!is_string($hash) || !password_verify($newPassword, $hash)) {
            throw new RuntimeException('Unable to reset the student password.');
        }
    }
}

const PROFILE_PHOTO_MAX_DIMENSION = 800;

/**
 * @return list<string>
 */
function profile_photo_allowed_extensions(): array
{
    return ['jpg', 'jpeg', 'png', 'webp'];
}

/**
 * @return list<string>
 */
function profile_photo_forbidden_extensions(): array
{
    return ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'cgi', 'pl', 'py', 'js', 'html', 'htm', 'shtml', 'asp', 'aspx', 'exe', 'bat', 'cmd', 'sh', 'svg'];
}

/**
 * @return array<string, list<string>>
 */
function profile_photo_mime_map(): array
{
    return [
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png' => ['image/png', 'image/x-png'],
        'webp' => ['image/webp'],
    ];
}

function profile_photo_storage_root(): string
{
    $path = UPLOADS_PATH;
    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException('Upload directory is not available.');
    }
    $root = realpath($path);
    if ($root === false) {
        throw new RuntimeException('Upload directory is not available.');
    }

    return $root;
}

function profile_photo_ensure_dir(): string
{
    $root = profile_photo_storage_root();
    $dir = $root . DIRECTORY_SEPARATOR . 'profile-photos';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create profile photo directory.');
    }

    return $dir;
}

function profile_photo_normalize_path(?string $relative): ?string
{
    if ($relative === null) {
        return null;
    }
    $relative = str_replace('\\', '/', trim($relative));
    if ($relative === '' || str_contains($relative, '..') || !str_starts_with($relative, 'profile-photos/')) {
        return null;
    }
    if (!preg_match('#^profile-photos/[a-f0-9]{32}\.(jpg|jpeg|png|webp)$#', $relative)) {
        return null;
    }

    return $relative;
}

function profile_photo_resolve_path(?string $relative): ?string
{
    $normalized = profile_photo_normalize_path($relative);
    if ($normalized === null) {
        return null;
    }
    $absolute = profile_photo_storage_root() . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    $real = realpath($absolute);
    $root = profile_photo_storage_root();
    if ($real === false || !str_starts_with($real, $root) || !is_file($real)) {
        return null;
    }

    return $real;
}

function profile_photo_matches_magic(string $path, string $ext): bool
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $header = fread($handle, 12) ?: '';
    fclose($handle);

    return match ($ext) {
        'jpg', 'jpeg' => str_starts_with($header, "\xFF\xD8\xFF"),
        'png' => str_starts_with($header, "\x89PNG\r\n\x1A\n"),
        'webp' => strlen($header) >= 12 && str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP',
        default => false,
    };
}

/**
 * Optionally re-encode and downscale using GD when available.
 * Returns absolute path to the final image to store (may be original tmp).
 */
function profile_photo_normalize_image(string $tmp, string $ext): string
{
    if (!extension_loaded('gd')) {
        return $tmp;
    }

    $info = @getimagesize($tmp);
    if (!is_array($info) || !isset($info[0], $info[1], $info[2])) {
        throw new InvalidArgumentException('The file is not a valid image.');
    }
    $width = (int) $info[0];
    $height = (int) $info[1];
    if ($width < 1 || $height < 1) {
        throw new InvalidArgumentException('The file is not a valid image.');
    }

    $source = match ($ext) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($tmp),
        'png' => @imagecreatefrompng($tmp),
        'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        default => false,
    };
    if ($source === false) {
        // GD cannot decode (e.g. WebP unsupported) — keep validated original.
        return $tmp;
    }

    $max = PROFILE_PHOTO_MAX_DIMENSION;
    $scale = 1.0;
    if ($width > $max || $height > $max) {
        $scale = min($max / $width, $max / $height);
    }
    $newW = max(1, (int) round($width * $scale));
    $newH = max(1, (int) round($height * $scale));

    $canvas = imagecreatetruecolor($newW, $newH);
    if ($canvas === false) {
        imagedestroy($source);
        return $tmp;
    }

    if ($ext === 'png' || $ext === 'webp') {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefilledrectangle($canvas, 0, 0, $newW, $newH, $transparent);
        }
    }

    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newW, $newH, $width, $height);
    imagedestroy($source);

    $out = tempnam(sys_get_temp_dir(), 'spp');
    if ($out === false) {
        imagedestroy($canvas);
        return $tmp;
    }

    $ok = match ($ext) {
        'jpg', 'jpeg' => imagejpeg($canvas, $out, 85),
        'png' => imagepng($canvas, $out, 6),
        'webp' => function_exists('imagewebp') ? imagewebp($canvas, $out, 85) : false,
        default => false,
    };
    imagedestroy($canvas);

    if (!$ok || !is_file($out) || filesize($out) === 0) {
        @unlink($out);
        return $tmp;
    }

    return $out;
}

/**
 * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed} $file
 * @return array{relative_path: string}
 */
function profile_photo_store_uploaded_file(array $file): array
{
    profile_photo_ensure_dir();

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Choose a profile photo to upload.');
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('That image is larger than the allowed upload size.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The profile photo could not be uploaded.');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    $original = (string) ($file['name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw new InvalidArgumentException('The profile photo could not be uploaded.');
    }
    if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('The profile photo could not be uploaded.');
    }
    if ($size <= 0 || $size > PROFILE_PHOTO_MAX_BYTES) {
        throw new InvalidArgumentException('Profile photo must be between 1 byte and 2 MB.');
    }

    $base = basename(str_replace(['\\', "\0"], ['/', ''], $original));
    $lower = strtolower($base);
    if ($base === '' || $base === '.' || $base === '..' || str_contains($lower, '..')) {
        throw new InvalidArgumentException('Invalid file name.');
    }
    $parts = explode('.', $lower);
    array_shift($parts);
    foreach ($parts as $part) {
        if (in_array($part, profile_photo_forbidden_extensions(), true)) {
            throw new InvalidArgumentException('That file type is not allowed.');
        }
    }
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    if (!in_array($ext, profile_photo_allowed_extensions(), true)) {
        throw new InvalidArgumentException('Allowed profile photo types: JPG, JPEG, PNG, WEBP.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower((string) $finfo->file($tmp));
    if ($mime === 'image/svg+xml' || $mime === 'text/html' || str_contains($mime, 'svg') || str_contains($mime, 'php')) {
        throw new InvalidArgumentException('That file type is not allowed.');
    }
    $allowedMimes = profile_photo_mime_map()[$ext] ?? [];
    if (!in_array($mime, $allowedMimes, true)) {
        throw new InvalidArgumentException('The file contents do not match an allowed image type.');
    }
    if (!profile_photo_matches_magic($tmp, $ext)) {
        throw new InvalidArgumentException('The file contents do not match an allowed image type.');
    }

    $info = @getimagesize($tmp);
    if (!is_array($info) || !isset($info[2])) {
        throw new InvalidArgumentException('The file is not a valid image.');
    }
    $imageType = (int) $info[2];
    $okType = match ($ext) {
        'jpg', 'jpeg' => $imageType === IMAGETYPE_JPEG,
        'png' => $imageType === IMAGETYPE_PNG,
        'webp' => defined('IMAGETYPE_WEBP') && $imageType === IMAGETYPE_WEBP,
        default => false,
    };
    if (!$okType) {
        throw new InvalidArgumentException('The file is not a valid image.');
    }

    $probe = file_get_contents($tmp, false, null, 0, 64) ?: '';
    $trimmed = ltrim($probe);
    if (str_starts_with($trimmed, '<?') || str_starts_with(strtolower($trimmed), '<script') || str_starts_with($trimmed, '<svg')) {
        throw new InvalidArgumentException('That file type is not allowed.');
    }

    $normalizedTmp = profile_photo_normalize_image($tmp, $ext);
    $cleanupNormalized = $normalizedTmp !== $tmp;

    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
    $relative = 'profile-photos/' . $storedName;
    $destination = profile_photo_storage_root() . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    try {
        if ($cleanupNormalized) {
            $moved = copy($normalizedTmp, $destination);
        } else {
            $moved = PHP_SAPI === 'cli'
                ? copy($tmp, $destination)
                : move_uploaded_file($tmp, $destination);
        }
        if (!$moved || !is_file($destination)) {
            throw new RuntimeException('Unable to store the profile photo.');
        }
    } finally {
        if ($cleanupNormalized) {
            @unlink($normalizedTmp);
        }
    }

    return ['relative_path' => $relative];
}

function profile_photo_delete_managed(?string $relative): void
{
    $absolute = profile_photo_resolve_path($relative);
    if ($absolute !== null) {
        @unlink($absolute);
    }
}

/**
 * Upload/replace the authenticated student's own profile photo.
 * Ignores any posted student_id / user_id.
 *
 * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed} $file
 */
function student_upload_own_profile_photo(int $authenticatedUserId, array $file): void
{
    $student = get_student_by_user_id($authenticatedUserId);
    if ($student === null) {
        throw new InvalidArgumentException('No student profile is linked to this account.');
    }

    $stored = profile_photo_store_uploaded_file($file);
    $oldPath = null;
    $oldStmt = db()->prepare('SELECT profile_photo FROM students WHERE student_id = :id LIMIT 1');
    $oldStmt->execute(['id' => (int) $student['student_id']]);
    $oldRow = $oldStmt->fetch();
    if ($oldRow !== false) {
        $oldPath = isset($oldRow['profile_photo']) ? (string) $oldRow['profile_photo'] : null;
    }

    try {
        $update = db()->prepare(
            'UPDATE students SET profile_photo = :profile_photo WHERE student_id = :student_id AND user_id = :user_id'
        );
        $update->execute([
            'profile_photo' => $stored['relative_path'],
            'student_id' => (int) $student['student_id'],
            'user_id' => $authenticatedUserId,
        ]);
        if ($update->rowCount() < 1) {
            // Still verify the row exists for this owner.
            $check = db()->prepare(
                'SELECT profile_photo FROM students WHERE student_id = :student_id AND user_id = :user_id LIMIT 1'
            );
            $check->execute([
                'student_id' => (int) $student['student_id'],
                'user_id' => $authenticatedUserId,
            ]);
            $current = $check->fetchColumn();
            if ((string) $current !== $stored['relative_path']) {
                throw new RuntimeException('Unable to update the profile photo.');
            }
        }
    } catch (Throwable $exception) {
        profile_photo_delete_managed($stored['relative_path']);
        throw $exception;
    }

    if ($oldPath !== null && $oldPath !== '' && $oldPath !== $stored['relative_path']) {
        profile_photo_delete_managed($oldPath);
    }
}

function student_remove_own_profile_photo(int $authenticatedUserId): void
{
    $student = get_student_by_user_id($authenticatedUserId);
    if ($student === null) {
        throw new InvalidArgumentException('No student profile is linked to this account.');
    }

    $stmt = db()->prepare(
        'SELECT profile_photo FROM students WHERE student_id = :student_id AND user_id = :user_id LIMIT 1'
    );
    $stmt->execute([
        'student_id' => (int) $student['student_id'],
        'user_id' => $authenticatedUserId,
    ]);
    $row = $stmt->fetch();
    if ($row === false) {
        throw new InvalidArgumentException('Student not found.');
    }
    $oldPath = isset($row['profile_photo']) ? (string) $row['profile_photo'] : null;

    $update = db()->prepare(
        'UPDATE students SET profile_photo = NULL WHERE student_id = :student_id AND user_id = :user_id'
    );
    $update->execute([
        'student_id' => (int) $student['student_id'],
        'user_id' => $authenticatedUserId,
    ]);

    if ($oldPath !== null && $oldPath !== '') {
        profile_photo_delete_managed($oldPath);
    }
}

function user_can_view_student_profile_photo(array $user, int $studentId): bool
{
    if ($studentId <= 0) {
        return false;
    }

    $role = (string) ($user['role'] ?? '');
    if ($role === 'ADMIN' || $role === 'ACADEMIC_STAFF') {
        return get_student($studentId) !== null;
    }

    if ($role === 'STUDENT') {
        $ownUserId = (int) ($user['user_id'] ?? 0);
        if ($ownUserId <= 0) {
            return false;
        }
        $own = get_student_by_user_id($ownUserId);

        return $own !== null && (int) $own['student_id'] === $studentId;
    }

    if ($role === 'LECTURER') {
        $lecturerUserId = (int) ($user['user_id'] ?? 0);
        if ($lecturerUserId <= 0) {
            return false;
        }
        $lecturer = get_lecturer_by_user_id($lecturerUserId);
        if ($lecturer === null) {
            return false;
        }

        return lecturer_can_view_student((int) $lecturer['lecturer_id'], $studentId);
    }

    return false;
}

/**
 * @return array{absolute_path: string, mime: string}|null
 */
function student_authorized_profile_photo(int $studentId, array $user): ?array
{
    if (!user_can_view_student_profile_photo($user, $studentId)) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT profile_photo FROM students WHERE student_id = :student_id LIMIT 1'
    );
    $statement->execute(['student_id' => $studentId]);
    $row = $statement->fetch();
    if ($row === false || empty($row['profile_photo'])) {
        return null;
    }

    $absolute = profile_photo_resolve_path((string) $row['profile_photo']);
    if ($absolute === null) {
        return null;
    }

    $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        default => null,
    };
    if ($mime === null) {
        return null;
    }

    return [
        'absolute_path' => $absolute,
        'mime' => $mime,
    ];
}

function student_send_profile_photo(array $payload): never
{
    header('Content-Type: ' . $payload['mime']);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline');
    header('Content-Length: ' . (string) filesize($payload['absolute_path']));
    header('Cache-Control: private, max-age=300');
    readfile($payload['absolute_path']);
    exit;
}

function student_profile_photo_route_for_role(string $role): string
{
    return match ($role) {
        'ADMIN' => 'admin/students/profile-photo.php',
        'ACADEMIC_STAFF' => 'academic-staff/students/profile-photo.php',
        'LECTURER' => 'lecturer/students/profile-photo.php',
        'STUDENT' => 'student/profile-photo.php',
        default => 'login.php',
    };
}

function student_profile_photo_url(int $studentId): string
{
    $user = current_user();
    $role = $user['role'] ?? 'STUDENT';

    return app_url(student_profile_photo_route_for_role((string) $role) . '?id=' . $studentId);
}

/**
 * Render a small avatar (photo or initials). Read-only.
 */
function render_student_profile_avatar(array $student, string $size = 'md'): string
{
    $studentId = (int) ($student['student_id'] ?? 0);
    $first = trim((string) ($student['first_name'] ?? ''));
    $last = trim((string) ($student['last_name'] ?? ''));
    $initials = strtoupper(mb_substr($first !== '' ? $first : 'S', 0, 1) . mb_substr($last !== '' ? $last : '', 0, 1));
    if ($initials === '') {
        $initials = 'S';
    }

    $hasPhoto = profile_photo_resolve_path(isset($student['profile_photo']) ? (string) $student['profile_photo'] : null) !== null;
    $sizeClass = $size === 'lg' ? 'profile-avatar--lg' : ($size === 'sm' ? 'profile-avatar--sm' : 'profile-avatar--md');

    if ($hasPhoto && $studentId > 0) {
        $url = student_profile_photo_url($studentId);
        return '<img src="' . e($url) . '" alt="" class="profile-avatar ' . e($sizeClass) . '" width="96" height="96">';
    }

    return '<div class="profile-avatar profile-avatar--placeholder ' . e($sizeClass) . '" aria-hidden="true">'
        . e($initials)
        . '</div>';
}
