<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Campus Events (workshops, seminars, orientation, etc.).
 * Not attendance_events and not lecture_sessions.
 */

function campus_event_selectable_roles(): array
{
    return ['STUDENT', 'LECTURER', 'ACADEMIC_STAFF', 'ADMIN'];
}

function campus_event_statuses(): array
{
    return ['DRAFT', 'PUBLISHED', 'CANCELLED'];
}

function campus_event_target_label(string $role): string
{
    return match ($role) {
        'ADMIN' => 'Admin',
        'ACADEMIC_STAFF' => 'Academic Staff',
        'LECTURER' => 'Lecturers',
        'STUDENT' => 'Students',
        default => $role,
    };
}

/**
 * @param list<string> $roles
 */
function campus_event_audience_label(array $roles): string
{
    $canonical = campus_event_selectable_roles();
    $normalized = [];
    foreach ($canonical as $role) {
        if (in_array($role, $roles, true)) {
            $normalized[] = $role;
        }
    }

    if ($normalized === []) {
        return 'None';
    }
    if ($normalized === $canonical) {
        return 'All Roles';
    }

    return implode(', ', array_map('campus_event_target_label', $normalized));
}

function format_campus_event_datetime(?string $value): string
{
    $parsed = parse_app_datetime($value);

    return $parsed instanceof DateTimeImmutable ? $parsed->format('Y-m-d H:i') : '—';
}

function campus_event_datetime_local_value(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }

    return str_replace(' ', 'T', substr($value, 0, 16));
}

function staff_can_manage_campus_events(): bool
{
    return can_manage_academic();
}

function campus_events_column_exists(string $column): bool
{
    $statement = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $statement->execute([
        'table_name' => 'campus_events',
        'column_name' => $column,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

/**
 * @return list<string>
 */
function migrate_campus_events_schema(): array
{
    $notes = [];
    if (ensure_campus_events_tables()) {
        $notes[] = 'created campus_events tables';
    }

    if (!campus_events_column_exists('poster_path')) {
        db()->exec('ALTER TABLE campus_events ADD COLUMN poster_path VARCHAR(255) DEFAULT NULL AFTER location');
        $notes[] = 'added campus_events.poster_path';
    }

    if (campus_events_column_exists('event_type')) {
        db()->exec('ALTER TABLE campus_events DROP COLUMN event_type');
        $notes[] = 'dropped campus_events.event_type';
    }

    return $notes;
}

function campus_event_allowed_poster_extensions(): array
{
    return ['jpg', 'jpeg', 'png', 'webp'];
}

function campus_event_forbidden_poster_extensions(): array
{
    return [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'pht', 'phps',
        'exe', 'com', 'scr', 'dll', 'so', 'bat', 'cmd', 'ps1', 'sh', 'bash',
        'js', 'mjs', 'html', 'htm', 'shtml', 'xhtml', 'svg', 'svgz', 'hta',
        'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp', 'jar', 'war', 'msi',
        'vbs', 'vbe', 'wsf',
    ];
}

function campus_event_poster_mime_map(): array
{
    return [
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png' => ['image/png', 'image/x-png'],
        'webp' => ['image/webp'],
    ];
}

function campus_event_storage_root(): string
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

function campus_event_ensure_poster_dir(): string
{
    $root = campus_event_storage_root();
    $dir = $root . DIRECTORY_SEPARATOR . 'events' . DIRECTORY_SEPARATOR . 'posters';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create event poster directory.');
    }

    return $dir;
}

function campus_event_normalize_poster_path(?string $relative): ?string
{
    if ($relative === null) {
        return null;
    }
    $relative = str_replace('\\', '/', trim($relative));
    if ($relative === '' || str_contains($relative, '..') || !str_starts_with($relative, 'events/posters/')) {
        return null;
    }
    if (!preg_match('#^events/posters/[a-f0-9]{32}\.(jpg|jpeg|png|webp)$#', $relative)) {
        return null;
    }

    return $relative;
}

function campus_event_resolve_poster_path(?string $relative): ?string
{
    $normalized = campus_event_normalize_poster_path($relative);
    if ($normalized === null) {
        return null;
    }

    $root = campus_event_storage_root();
    $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    $resolved = realpath($candidate);
    if ($resolved === false || !is_file($resolved)) {
        return null;
    }

    $rootPrefix = str_replace('/', DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR);
    $resolvedCmp = str_replace('/', DIRECTORY_SEPARATOR, $resolved);
    if (!str_starts_with(strtolower($resolvedCmp), strtolower($rootPrefix))) {
        return null;
    }

    return $resolved;
}

function campus_event_delete_poster(?string $relative): void
{
    $path = campus_event_resolve_poster_path($relative);
    if ($path !== null && is_file($path)) {
        unlink($path);
    }
}

function campus_event_poster_matches_magic(string $path, string $ext): bool
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
 * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed} $file
 * @return array{relative_path: string}
 */
function campus_event_store_poster(array $file): array
{
    campus_event_ensure_poster_dir();

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Choose a poster image to upload.');
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('That image is larger than the allowed upload size.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The poster could not be uploaded.');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    $original = (string) ($file['name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw new InvalidArgumentException('The poster could not be uploaded.');
    }
    if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('The poster could not be uploaded.');
    }
    if ($size <= 0 || $size > EVENT_POSTER_MAX_BYTES) {
        $mb = (string) max(1, (int) round(EVENT_POSTER_MAX_BYTES / 1048576));
        throw new InvalidArgumentException('Poster must be between 1 byte and ' . $mb . ' MB.');
    }

    $base = basename(str_replace(['\\', "\0"], ['/', ''], $original));
    $lower = strtolower($base);
    if ($base === '' || $base === '.' || $base === '..' || str_contains($lower, '..')) {
        throw new InvalidArgumentException('Invalid file name.');
    }
    $parts = explode('.', $lower);
    array_shift($parts);
    foreach ($parts as $part) {
        if (in_array($part, campus_event_forbidden_poster_extensions(), true)) {
            throw new InvalidArgumentException('That file type is not allowed.');
        }
    }
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    if (!in_array($ext, campus_event_allowed_poster_extensions(), true)) {
        throw new InvalidArgumentException('Allowed poster types: JPG, JPEG, PNG, WEBP.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower((string) $finfo->file($tmp));
    if ($mime === 'image/svg+xml' || $mime === 'text/html' || str_contains($mime, 'svg')) {
        throw new InvalidArgumentException('That file type is not allowed.');
    }
    $allowedMimes = campus_event_poster_mime_map()[$ext] ?? [];
    if (!in_array($mime, $allowedMimes, true)) {
        throw new InvalidArgumentException('The file contents do not match an allowed image type.');
    }
    if (!campus_event_poster_matches_magic($tmp, $ext)) {
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

    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
    $relative = 'events/posters/' . $storedName;
    $destination = campus_event_storage_root() . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    $moved = PHP_SAPI === 'cli'
        ? copy($tmp, $destination)
        : move_uploaded_file($tmp, $destination);
    if (!$moved) {
        throw new RuntimeException('Unable to store the poster image.');
    }

    return ['relative_path' => $relative];
}

function campus_event_poster_url(string $routePrefix, int $campusEventId): string
{
    return app_url($routePrefix . '/events/poster.php?id=' . $campusEventId);
}

function campus_event_role_can_access_poster(array $row, string $role): bool
{
    if (in_array($role, ['ADMIN', 'ACADEMIC_STAFF'], true)) {
        return true;
    }

    return reader_can_view_campus_event($row, $role);
}

function campus_event_description_preview(?string $description, int $limit = 180): string
{
    $text = trim((string) $description);
    if ($text === '') {
        return '';
    }
    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $limit)) . '…';
}

/**
 * @return array{absolute_path: string, mime: string}|null
 */
function campus_event_authorized_poster(int $campusEventId, string $role): ?array
{
    $row = get_campus_event($campusEventId);
    if ($row === null || empty($row['poster_path'])) {
        return null;
    }
    if (!campus_event_role_can_access_poster($row, $role)) {
        return null;
    }
    $absolute = campus_event_resolve_poster_path(isset($row['poster_path']) ? (string) $row['poster_path'] : null);
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

function campus_event_send_poster(array $payload): never
{
    header('Content-Type: ' . $payload['mime']);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline');
    header('Content-Length: ' . (string) filesize($payload['absolute_path']));
    header('Cache-Control: private, no-store');
    readfile($payload['absolute_path']);
    exit;
}

function ensure_campus_events_tables(): bool
{
    $pdo = db();
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'campus_events'"
    );
    $created = false;
    if ($exists === false || (int) $exists->fetchColumn() === 0) {
        $pdo->exec(
            "CREATE TABLE campus_events (
              campus_event_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              title VARCHAR(200) NOT NULL,
              description TEXT DEFAULT NULL,
              start_datetime DATETIME NOT NULL,
              end_datetime DATETIME NOT NULL,
              location VARCHAR(150) DEFAULT NULL,
              poster_path VARCHAR(255) DEFAULT NULL,
              created_by INT UNSIGNED NOT NULL,
              status ENUM('DRAFT', 'PUBLISHED', 'CANCELLED') NOT NULL DEFAULT 'DRAFT',
              published_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (campus_event_id),
              KEY idx_campus_events_status_start (status, start_datetime),
              KEY idx_campus_events_published_at (published_at),
              KEY idx_campus_events_created_by (created_by),
              CONSTRAINT chk_campus_events_time CHECK (end_datetime > start_datetime),
              CONSTRAINT fk_campus_events_created_by
                FOREIGN KEY (created_by) REFERENCES users (user_id)
                ON DELETE RESTRICT
                ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $created = true;
    }

    $targetsExist = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'campus_event_targets'"
    );
    if ($targetsExist === false || (int) $targetsExist->fetchColumn() === 0) {
        $pdo->exec(
            "CREATE TABLE campus_event_targets (
              campus_event_target_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              campus_event_id INT UNSIGNED NOT NULL,
              target_role ENUM('ADMIN', 'ACADEMIC_STAFF', 'LECTURER', 'STUDENT') NOT NULL,
              PRIMARY KEY (campus_event_target_id),
              UNIQUE KEY uq_campus_event_targets_event_role (campus_event_id, target_role),
              KEY idx_campus_event_targets_role (target_role),
              CONSTRAINT fk_campus_event_targets_event
                FOREIGN KEY (campus_event_id) REFERENCES campus_events (campus_event_id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $created = true;
    }

    return $created;
}

function campus_event_lifecycle_label(array $row, ?DateTimeImmutable $now = null): string
{
    $now ??= app_now();
    $status = (string) ($row['status'] ?? '');
    if ($status === 'DRAFT') {
        return 'Draft';
    }
    if ($status === 'CANCELLED') {
        return 'Cancelled';
    }

    $published = parse_app_datetime(isset($row['published_at']) ? (string) $row['published_at'] : null);
    if ($status === 'PUBLISHED' && ($published === null || $published > $now)) {
        return 'Scheduled';
    }

    $start = parse_app_datetime(isset($row['start_datetime']) ? (string) $row['start_datetime'] : null);
    $end = parse_app_datetime(isset($row['end_datetime']) ? (string) $row['end_datetime'] : null);
    if ($end !== null && $now > $end) {
        return 'Past';
    }
    if ($start !== null && $end !== null && $now >= $start && $now <= $end) {
        return 'Ongoing';
    }
    if ($start !== null && $now < $start) {
        return 'Upcoming';
    }

    return $status !== '' ? $status : 'Unknown';
}

/**
 * @return list<string>
 */
function list_campus_event_target_roles(int $campusEventId): array
{
    $statement = db()->prepare(
        'SELECT target_role FROM campus_event_targets WHERE campus_event_id = :campus_event_id'
    );
    $statement->execute(['campus_event_id' => $campusEventId]);
    $found = $statement->fetchAll(PDO::FETCH_COLUMN);
    $roles = [];
    foreach (campus_event_selectable_roles() as $role) {
        if (in_array($role, $found, true)) {
            $roles[] = $role;
        }
    }

    return $roles;
}

/**
 * @param list<int> $ids
 * @return array<int, list<string>>
 */
function campus_event_targets_grouped(array $ids): array
{
    $grouped = [];
    foreach ($ids as $id) {
        $grouped[$id] = [];
    }
    if ($ids === []) {
        return $grouped;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = db()->prepare(
        "SELECT campus_event_id, target_role
         FROM campus_event_targets
         WHERE campus_event_id IN ({$placeholders})"
    );
    $statement->execute(array_values($ids));
    $canonical = campus_event_selectable_roles();
    while ($row = $statement->fetch()) {
        $id = (int) $row['campus_event_id'];
        $grouped[$id][] = (string) $row['target_role'];
    }
    foreach ($grouped as $id => $roles) {
        $ordered = [];
        foreach ($canonical as $role) {
            if (in_array($role, $roles, true)) {
                $ordered[] = $role;
            }
        }
        $grouped[$id] = $ordered;
    }

    return $grouped;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function campus_event_hydrate_targets(array $rows): array
{
    $ids = array_map(static fn(array $row): int => (int) $row['campus_event_id'], $rows);
    $grouped = campus_event_targets_grouped($ids);
    foreach ($rows as $i => $row) {
        $id = (int) $row['campus_event_id'];
        $rows[$i]['target_roles'] = $grouped[$id] ?? [];
        $rows[$i]['audience_label'] = campus_event_audience_label($rows[$i]['target_roles']);
        $rows[$i]['lifecycle_label'] = campus_event_lifecycle_label($rows[$i]);
    }

    return $rows;
}

/**
 * @return array<string, mixed>|null
 */
function get_campus_event(int $campusEventId): ?array
{
    $statement = db()->prepare(
        "SELECT e.campus_event_id, e.title, e.description, e.start_datetime, e.end_datetime,
                e.location, e.poster_path, e.created_by, e.status, e.published_at, e.created_at, e.updated_at,
                u.username AS created_by_username
         FROM campus_events e
         INNER JOIN users u ON u.user_id = e.created_by
         WHERE e.campus_event_id = :campus_event_id
         LIMIT 1"
    );
    $statement->execute(['campus_event_id' => $campusEventId]);
    $row = $statement->fetch();
    if ($row === false) {
        return null;
    }

    $hydrated = campus_event_hydrate_targets([$row]);

    return $hydrated[0];
}

/**
 * @return list<array<string, mixed>>
 */
function list_campus_events_for_management(): array
{
    $statement = db()->query(
        "SELECT e.campus_event_id, e.title, e.description, e.start_datetime, e.end_datetime,
                e.location, e.poster_path, e.created_by, e.status, e.published_at, e.created_at, e.updated_at,
                u.username AS created_by_username
         FROM campus_events e
         INNER JOIN users u ON u.user_id = e.created_by
         ORDER BY e.start_datetime DESC, e.campus_event_id DESC"
    );

    return campus_event_hydrate_targets($statement->fetchAll());
}

function campus_event_is_published_for_readers(array $row, ?DateTimeImmutable $now = null): bool
{
    $now ??= app_now();
    $status = (string) ($row['status'] ?? '');
    if (!in_array($status, ['PUBLISHED', 'CANCELLED'], true)) {
        return false;
    }

    $published = parse_app_datetime(isset($row['published_at']) ? (string) $row['published_at'] : null);

    return $published !== null && $published <= $now;
}

function reader_can_view_campus_event(array $row, string $role): bool
{
    $targets = $row['target_roles'] ?? null;
    if (!is_array($targets)) {
        $targets = list_campus_event_target_roles((int) $row['campus_event_id']);
    }

    return campus_event_is_published_for_readers($row)
        && in_array($role, $targets, true);
}

/**
 * @return list<array<string, mixed>>
 */
function list_campus_events_for_role(string $role, string $view = 'upcoming', ?int $limit = null): array
{
    if (!in_array($role, campus_event_selectable_roles(), true)) {
        return [];
    }

    $now = app_now_datetime();
    $sql = "SELECT e.campus_event_id, e.title, e.description, e.start_datetime, e.end_datetime,
                   e.location, e.poster_path, e.created_by, e.status, e.published_at, e.created_at, e.updated_at,
                   u.username AS created_by_username
            FROM campus_events e
            INNER JOIN users u ON u.user_id = e.created_by
            INNER JOIN campus_event_targets t
              ON t.campus_event_id = e.campus_event_id AND t.target_role = :audience
            WHERE e.status IN ('PUBLISHED', 'CANCELLED')
              AND e.published_at IS NOT NULL
              AND e.published_at <= :now";

    if ($view === 'past') {
        $sql .= ' AND e.end_datetime < :window';
        $sql .= ' ORDER BY e.start_datetime DESC, e.campus_event_id DESC';
    } else {
        $sql .= ' AND e.end_datetime >= :window';
        $sql .= ' ORDER BY e.start_datetime ASC, e.campus_event_id ASC';
    }

    if ($limit !== null && $limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit;
    }

    $statement = db()->prepare($sql);
    $statement->execute([
        'audience' => $role,
        'now' => $now,
        'window' => $now,
    ]);

    return campus_event_hydrate_targets($statement->fetchAll());
}

function count_upcoming_campus_events(): int
{
    $now = app_now_datetime();
    $statement = db()->prepare(
        "SELECT COUNT(*) FROM campus_events
         WHERE status = 'PUBLISHED'
           AND published_at IS NOT NULL
           AND published_at <= :now
           AND end_datetime >= :window"
    );
    $statement->execute([
        'now' => $now,
        'window' => $now,
    ]);

    return (int) $statement->fetchColumn();
}

/**
 * @param array<string, mixed> $data
 */
function create_campus_event(int $createdByUserId, array $data): int
{
    $payload = campus_event_normalize_payload($data);
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare(
            'INSERT INTO campus_events
                (title, description, start_datetime, end_datetime, location, poster_path, created_by, status, published_at)
             VALUES
                (:title, :description, :start_datetime, :end_datetime, :location, :poster_path, :created_by, :status, :published_at)'
        );
        $statement->execute([
            'title' => $payload['title'],
            'description' => $payload['description'],
            'start_datetime' => $payload['start_datetime'],
            'end_datetime' => $payload['end_datetime'],
            'location' => $payload['location'],
            'poster_path' => $payload['poster_path'],
            'created_by' => $createdByUserId,
            'status' => $payload['status'],
            'published_at' => $payload['published_at'],
        ]);
        $campusEventId = (int) $pdo->lastInsertId();
        campus_event_replace_targets($campusEventId, $payload['target_roles']);
        $pdo->commit();

        return $campusEventId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * @param array<string, mixed> $data
 */
function update_campus_event(int $campusEventId, array $data): void
{
    $existing = get_campus_event($campusEventId);
    if ($existing === null) {
        throw new InvalidArgumentException('Event not found.');
    }

    $payload = campus_event_normalize_payload($data);
    if (!array_key_exists('poster_path', $data)) {
        $payload['poster_path'] = $existing['poster_path'] ?? null;
    } else {
        $incoming = $data['poster_path'];
        $payload['poster_path'] = is_string($incoming) && $incoming !== ''
            ? campus_event_normalize_poster_path($incoming)
            : null;
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare(
            'UPDATE campus_events
             SET title = :title,
                 description = :description,
                 start_datetime = :start_datetime,
                 end_datetime = :end_datetime,
                 location = :location,
                 poster_path = :poster_path,
                 status = :status,
                 published_at = :published_at
             WHERE campus_event_id = :campus_event_id'
        );
        $statement->execute([
            'title' => $payload['title'],
            'description' => $payload['description'],
            'start_datetime' => $payload['start_datetime'],
            'end_datetime' => $payload['end_datetime'],
            'location' => $payload['location'],
            'poster_path' => $payload['poster_path'],
            'status' => $payload['status'],
            'published_at' => $payload['published_at'],
            'campus_event_id' => $campusEventId,
        ]);
        campus_event_replace_targets($campusEventId, $payload['target_roles']);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * @param list<string> $roles
 */
function campus_event_replace_targets(int $campusEventId, array $roles): void
{
    $delete = db()->prepare('DELETE FROM campus_event_targets WHERE campus_event_id = :campus_event_id');
    $delete->execute(['campus_event_id' => $campusEventId]);

    $insert = db()->prepare(
        'INSERT INTO campus_event_targets (campus_event_id, target_role)
         VALUES (:campus_event_id, :target_role)'
    );
    foreach ($roles as $role) {
        $insert->execute([
            'campus_event_id' => $campusEventId,
            'target_role' => $role,
        ]);
    }
}

/**
 * @param array<string, mixed> $data
 * @return array{title: string, description: ?string, start_datetime: string, end_datetime: string, location: ?string, poster_path: ?string, target_roles: list<string>, status: string, published_at: ?string}
 */
function campus_event_normalize_payload(array $data): array
{
    $title = trim((string) ($data['title'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $location = trim((string) ($data['location'] ?? ''));
    $status = trim((string) ($data['status'] ?? ''));

    if ($title === '') {
        throw new InvalidArgumentException('Title is required.');
    }
    if (mb_strlen($title) > 200) {
        throw new InvalidArgumentException('Title must be 200 characters or fewer.');
    }
    if ($location !== '' && mb_strlen($location) > 150) {
        throw new InvalidArgumentException('Location must be 150 characters or fewer.');
    }
    if (!in_array($status, campus_event_statuses(), true)) {
        throw new InvalidArgumentException('Select a valid status.');
    }

    $roles = campus_event_normalize_target_roles($data);

    $startRaw = $data['start_datetime'] ?? null;
    $endRaw = $data['end_datetime'] ?? null;
    $start = is_string($startRaw) && trim($startRaw) !== ''
        ? parse_app_datetime(str_replace('T', ' ', trim($startRaw)))
        : null;
    $end = is_string($endRaw) && trim($endRaw) !== ''
        ? parse_app_datetime(str_replace('T', ' ', trim($endRaw)))
        : null;

    if ($start === null) {
        throw new InvalidArgumentException('Enter a valid start date and time.');
    }
    if ($end === null) {
        throw new InvalidArgumentException('Enter a valid end date and time.');
    }
    if ($end <= $start) {
        throw new InvalidArgumentException('End must be after the start date and time.');
    }

    $publishedRaw = $data['published_at'] ?? null;
    $published = is_string($publishedRaw) && trim($publishedRaw) !== ''
        ? parse_app_datetime(str_replace('T', ' ', trim($publishedRaw)))
        : null;
    if (is_string($publishedRaw) && trim($publishedRaw) !== '' && $published === null) {
        throw new InvalidArgumentException('Enter a valid publish date and time.');
    }

    if ($status === 'PUBLISHED' && $published === null) {
        $published = app_now();
    }

    $posterPath = null;
    if (array_key_exists('poster_path', $data) && is_string($data['poster_path']) && trim($data['poster_path']) !== '') {
        $posterPath = campus_event_normalize_poster_path(trim($data['poster_path']));
        if ($posterPath === null) {
            throw new InvalidArgumentException('The poster file is not valid.');
        }
    }

    return [
        'title' => $title,
        'description' => $description !== '' ? $description : null,
        'start_datetime' => $start->format('Y-m-d H:i:s'),
        'end_datetime' => $end->format('Y-m-d H:i:s'),
        'location' => $location !== '' ? $location : null,
        'poster_path' => $posterPath,
        'target_roles' => $roles,
        'status' => $status,
        'published_at' => $published?->format('Y-m-d H:i:s'),
    ];
}

/**
 * @param array<string, mixed> $data
 * @return list<string>
 */
function campus_event_normalize_target_roles(array $data): array
{
    $raw = $data['target_roles'] ?? null;
    if (!is_array($raw)) {
        throw new InvalidArgumentException('Select at least one target audience.');
    }

    $allowed = campus_event_selectable_roles();
    $normalized = [];
    foreach ($raw as $value) {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException('Select a valid target audience.');
        }
        $role = trim((string) $value);
        if ($role === '') {
            continue;
        }
        if (!in_array($role, $allowed, true)) {
            throw new InvalidArgumentException('Select a valid target audience.');
        }
        if (!in_array($role, $normalized, true)) {
            $normalized[] = $role;
        }
    }

    $ordered = [];
    foreach ($allowed as $role) {
        if (in_array($role, $normalized, true)) {
            $ordered[] = $role;
        }
    }
    if ($ordered === []) {
        throw new InvalidArgumentException('Select at least one target audience.');
    }

    return $ordered;
}
