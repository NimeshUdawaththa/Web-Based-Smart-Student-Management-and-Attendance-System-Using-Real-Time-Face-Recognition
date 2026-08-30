<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Notices stored in announcements.
 * Audience source of truth: announcement_targets (one row per role).
 * announcements.target_role is a deprecated v1 snapshot, still written for compatibility.
 */

function announcement_selectable_roles(): array
{
    return ['STUDENT', 'LECTURER', 'ACADEMIC_STAFF', 'ADMIN'];
}

function announcement_target_label(string $target): string
{
    return match ($target) {
        'ALL' => 'All Roles',
        'ADMIN' => 'Admin',
        'ACADEMIC_STAFF' => 'Academic Staff',
        'LECTURER' => 'Lecturers',
        'STUDENT' => 'Students',
        default => $target,
    };
}

/**
 * @param list<string> $roles
 */
function announcement_audience_label(array $roles): string
{
    $canonical = announcement_selectable_roles();
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

    return implode(', ', array_map('announcement_target_label', $normalized));
}

function format_announcement_datetime(?string $value): string
{
    $parsed = parse_app_datetime($value);

    return $parsed instanceof DateTimeImmutable ? $parsed->format('Y-m-d H:i') : '—';
}

function announcement_datetime_local_value(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }

    return str_replace(' ', 'T', substr($value, 0, 16));
}

function announcement_statuses(): array
{
    return ['DRAFT', 'PUBLISHED', 'ARCHIVED'];
}

function announcement_is_expired(array $row, ?DateTimeImmutable $now = null): bool
{
    $now ??= app_now();
    $expires = parse_app_datetime(isset($row['expires_at']) ? (string) $row['expires_at'] : null);

    return $expires !== null && $now > $expires;
}

function announcement_is_scheduled(array $row, ?DateTimeImmutable $now = null): bool
{
    $now ??= app_now();
    $published = parse_app_datetime(isset($row['published_at']) ? (string) $row['published_at'] : null);

    return ($row['status'] ?? '') === 'PUBLISHED' && $published !== null && $published > $now;
}

function announcement_is_visible_to_readers(array $row, ?DateTimeImmutable $now = null): bool
{
    $now ??= app_now();
    if (($row['status'] ?? '') !== 'PUBLISHED') {
        return false;
    }

    $published = parse_app_datetime(isset($row['published_at']) ? (string) $row['published_at'] : null);
    if ($published === null || $published > $now) {
        return false;
    }

    return !announcement_is_expired($row, $now);
}

function announcement_lifecycle_label(array $row, ?DateTimeImmutable $now = null): string
{
    $status = (string) ($row['status'] ?? '');
    if ($status === 'DRAFT') {
        return 'Draft';
    }
    if ($status === 'ARCHIVED') {
        return 'Archived';
    }
    if (announcement_is_scheduled($row, $now)) {
        return 'Scheduled';
    }
    if (announcement_is_expired($row, $now)) {
        return 'Expired';
    }
    if ($status === 'PUBLISHED' && announcement_is_visible_to_readers($row, $now)) {
        return 'Active';
    }

    return $status !== '' ? $status : 'Unknown';
}

function staff_can_manage_announcements(): bool
{
    return can_manage_academic();
}

function ensure_announcement_targets_table(): bool
{
    $pdo = db();
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'announcement_targets'"
    );
    if ($exists !== false && (int) $exists->fetchColumn() > 0) {
        return false;
    }

    $pdo->exec(
        "CREATE TABLE announcement_targets (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    return true;
}

function backfill_legacy_announcement_targets(): int
{
    $pdo = db();
    $inserted = 0;
    $map = [
        'STUDENT' => ['STUDENT', 'ALL'],
        'LECTURER' => ['LECTURER', 'ALL'],
        'ACADEMIC_STAFF' => ['ACADEMIC_STAFF', 'ALL'],
        'ADMIN' => ['ADMIN', 'ALL'],
    ];

    foreach ($map as $role => $legacyValues) {
        $placeholders = implode(', ', array_fill(0, count($legacyValues), '?'));
        $sql = "INSERT INTO announcement_targets (announcement_id, target_role)
                SELECT a.announcement_id, ?
                FROM announcements a
                WHERE a.target_role IN ({$placeholders})
                  AND NOT EXISTS (
                    SELECT 1 FROM announcement_targets t
                    WHERE t.announcement_id = a.announcement_id AND t.target_role = ?
                  )";
        $statement = $pdo->prepare($sql);
        $statement->execute(array_merge([$role], $legacyValues, [$role]));
        $inserted += $statement->rowCount();
    }

    return $inserted;
}

/**
 * @param list<string> $roles
 */
function announcement_legacy_snapshot_role(array $roles): string
{
    $canonical = announcement_selectable_roles();
    $normalized = [];
    foreach ($canonical as $role) {
        if (in_array($role, $roles, true)) {
            $normalized[] = $role;
        }
    }

    if ($normalized === $canonical) {
        return 'ALL';
    }
    if (count($normalized) === 1) {
        return $normalized[0];
    }

    return $normalized[0] ?? 'ALL';
}

/**
 * @return list<string>
 */
function list_announcement_target_roles(int $announcementId): array
{
    $statement = db()->prepare(
        'SELECT target_role FROM announcement_targets
         WHERE announcement_id = :announcement_id'
    );
    $statement->execute(['announcement_id' => $announcementId]);
    $found = $statement->fetchAll(PDO::FETCH_COLUMN);
    $roles = [];
    foreach (announcement_selectable_roles() as $role) {
        if (in_array($role, $found, true)) {
            $roles[] = $role;
        }
    }

    return $roles;
}

/**
 * @param list<int> $announcementIds
 * @return array<int, list<string>>
 */
function announcement_targets_grouped(array $announcementIds): array
{
    $grouped = [];
    foreach ($announcementIds as $id) {
        $grouped[$id] = [];
    }
    if ($announcementIds === []) {
        return $grouped;
    }

    $placeholders = implode(',', array_fill(0, count($announcementIds), '?'));
    $statement = db()->prepare(
        "SELECT announcement_id, target_role
         FROM announcement_targets
         WHERE announcement_id IN ({$placeholders})"
    );
    $statement->execute(array_values($announcementIds));

    $canonical = announcement_selectable_roles();
    while ($row = $statement->fetch()) {
        $id = (int) $row['announcement_id'];
        $role = (string) $row['target_role'];
        if (!isset($grouped[$id])) {
            $grouped[$id] = [];
        }
        $grouped[$id][] = $role;
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
function announcement_hydrate_targets(array $rows): array
{
    $ids = array_map(static fn(array $row): int => (int) $row['announcement_id'], $rows);
    $grouped = announcement_targets_grouped($ids);
    foreach ($rows as $i => $row) {
        $id = (int) $row['announcement_id'];
        $rows[$i]['target_roles'] = $grouped[$id] ?? [];
        $rows[$i]['audience_label'] = announcement_audience_label($rows[$i]['target_roles']);
    }

    return $rows;
}

/**
 * @return array<string, mixed>|null
 */
function get_announcement(int $announcementId): ?array
{
    $statement = db()->prepare(
        "SELECT a.announcement_id, a.title, a.message, a.target_role, a.created_by,
                a.published_at, a.expires_at, a.status, a.created_at, a.updated_at,
                u.username AS created_by_username
         FROM announcements a
         INNER JOIN users u ON u.user_id = a.created_by
         WHERE a.announcement_id = :announcement_id
         LIMIT 1"
    );
    $statement->execute(['announcement_id' => $announcementId]);
    $row = $statement->fetch();
    if ($row === false) {
        return null;
    }

    $hydrated = announcement_hydrate_targets([$row]);

    return $hydrated[0];
}

/**
 * @return list<array<string, mixed>>
 */
function list_announcements_for_management(): array
{
    $statement = db()->query(
        "SELECT a.announcement_id, a.title, a.message, a.target_role, a.created_by,
                a.published_at, a.expires_at, a.status, a.created_at, a.updated_at,
                u.username AS created_by_username
         FROM announcements a
         INNER JOIN users u ON u.user_id = a.created_by
         ORDER BY a.created_at DESC, a.announcement_id DESC"
    );

    return announcement_hydrate_targets($statement->fetchAll());
}

/**
 * @return list<array<string, mixed>>
 */
function list_visible_announcements_for_role(string $role, ?int $limit = null): array
{
    if (!in_array($role, announcement_selectable_roles(), true)) {
        return [];
    }

    $now = app_now_datetime();
    $sql = "SELECT a.announcement_id, a.title, a.message, a.target_role, a.created_by,
                   a.published_at, a.expires_at, a.status, a.created_at, a.updated_at,
                   u.username AS created_by_username
            FROM announcements a
            INNER JOIN users u ON u.user_id = a.created_by
            INNER JOIN announcement_targets t
              ON t.announcement_id = a.announcement_id AND t.target_role = :audience
            WHERE a.status = 'PUBLISHED'
              AND a.published_at IS NOT NULL
              AND a.published_at <= :now
              AND (a.expires_at IS NULL OR a.expires_at >= :now_expiry)
            ORDER BY a.published_at DESC, a.announcement_id DESC";

    if ($limit !== null && $limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit;
    }

    $statement = db()->prepare($sql);
    $statement->execute([
        'audience' => $role,
        'now' => $now,
        'now_expiry' => $now,
    ]);

    return announcement_hydrate_targets($statement->fetchAll());
}

function count_currently_active_announcements(): int
{
    $now = app_now_datetime();
    $statement = db()->prepare(
        "SELECT COUNT(*) FROM announcements
         WHERE status = 'PUBLISHED'
           AND published_at IS NOT NULL
           AND published_at <= :now
           AND (expires_at IS NULL OR expires_at >= :now_expiry)"
    );
    $statement->execute([
        'now' => $now,
        'now_expiry' => $now,
    ]);

    return (int) $statement->fetchColumn();
}

function reader_can_view_announcement(array $row, string $role): bool
{
    $targets = $row['target_roles'] ?? null;
    if (!is_array($targets)) {
        $targets = list_announcement_target_roles((int) $row['announcement_id']);
    }

    return announcement_is_visible_to_readers($row)
        && in_array($role, $targets, true);
}

/**
 * @param array<string, mixed> $data
 */
function create_announcement(int $createdByUserId, array $data): int
{
    $payload = announcement_normalize_payload($data);
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare(
            'INSERT INTO announcements (title, message, target_role, created_by, published_at, expires_at, status)
             VALUES (:title, :message, :target_role, :created_by, :published_at, :expires_at, :status)'
        );
        $statement->execute([
            'title' => $payload['title'],
            'message' => $payload['message'],
            'target_role' => $payload['legacy_target_role'],
            'created_by' => $createdByUserId,
            'published_at' => $payload['published_at'],
            'expires_at' => $payload['expires_at'],
            'status' => $payload['status'],
        ]);
        $announcementId = (int) $pdo->lastInsertId();
        announcement_replace_targets($announcementId, $payload['target_roles']);
        $pdo->commit();

        return $announcementId;
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
function update_announcement(int $announcementId, array $data): void
{
    if (get_announcement($announcementId) === null) {
        throw new InvalidArgumentException('Announcement not found.');
    }

    $payload = announcement_normalize_payload($data);
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare(
            'UPDATE announcements
             SET title = :title,
                 message = :message,
                 target_role = :target_role,
                 published_at = :published_at,
                 expires_at = :expires_at,
                 status = :status
             WHERE announcement_id = :announcement_id'
        );
        $statement->execute([
            'title' => $payload['title'],
            'message' => $payload['message'],
            'target_role' => $payload['legacy_target_role'],
            'published_at' => $payload['published_at'],
            'expires_at' => $payload['expires_at'],
            'status' => $payload['status'],
            'announcement_id' => $announcementId,
        ]);
        announcement_replace_targets($announcementId, $payload['target_roles']);
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
function announcement_replace_targets(int $announcementId, array $roles): void
{
    $delete = db()->prepare('DELETE FROM announcement_targets WHERE announcement_id = :announcement_id');
    $delete->execute(['announcement_id' => $announcementId]);

    $insert = db()->prepare(
        'INSERT INTO announcement_targets (announcement_id, target_role)
         VALUES (:announcement_id, :target_role)'
    );
    foreach ($roles as $role) {
        $insert->execute([
            'announcement_id' => $announcementId,
            'target_role' => $role,
        ]);
    }
}

/**
 * @param array<string, mixed> $data
 * @return array{title: string, message: string, target_roles: list<string>, legacy_target_role: string, published_at: ?string, expires_at: ?string, status: string}
 */
function announcement_normalize_payload(array $data): array
{
    $title = trim((string) ($data['title'] ?? ''));
    $message = trim((string) ($data['message'] ?? ''));
    $status = trim((string) ($data['status'] ?? ''));

    if ($title === '') {
        throw new InvalidArgumentException('Title is required.');
    }
    if (mb_strlen($title) > 200) {
        throw new InvalidArgumentException('Title must be 200 characters or fewer.');
    }
    if ($message === '') {
        throw new InvalidArgumentException('Message is required.');
    }
    if (!in_array($status, announcement_statuses(), true)) {
        throw new InvalidArgumentException('Select a valid status.');
    }

    $roles = announcement_normalize_target_roles($data);

    $publishedRaw = $data['published_at'] ?? null;
    $expiresRaw = $data['expires_at'] ?? null;
    $published = is_string($publishedRaw) && trim($publishedRaw) !== ''
        ? parse_app_datetime(str_replace('T', ' ', trim($publishedRaw)))
        : null;
    $expires = is_string($expiresRaw) && trim($expiresRaw) !== ''
        ? parse_app_datetime(str_replace('T', ' ', trim($expiresRaw)))
        : null;

    if (is_string($publishedRaw) && trim($publishedRaw) !== '' && $published === null) {
        throw new InvalidArgumentException('Enter a valid publish date and time.');
    }
    if (is_string($expiresRaw) && trim($expiresRaw) !== '' && $expires === null) {
        throw new InvalidArgumentException('Enter a valid expiry date and time.');
    }

    if ($status === 'PUBLISHED' && $published === null) {
        $published = app_now();
    }

    if ($expires !== null && $published !== null && $expires < $published) {
        throw new InvalidArgumentException('Expiry must be on or after the publish date.');
    }

    return [
        'title' => $title,
        'message' => $message,
        'target_roles' => $roles,
        'legacy_target_role' => announcement_legacy_snapshot_role($roles),
        'published_at' => $published?->format('Y-m-d H:i:s'),
        'expires_at' => $expires?->format('Y-m-d H:i:s'),
        'status' => $status,
    ];
}

/**
 * @param array<string, mixed> $data
 * @return list<string>
 */
function announcement_normalize_target_roles(array $data): array
{
    $raw = $data['target_roles'] ?? null;
    if ($raw === null && isset($data['target_role'])) {
        $legacy = trim((string) $data['target_role']);
        if ($legacy === 'ALL') {
            $raw = announcement_selectable_roles();
        } else {
            $raw = [$legacy];
        }
    }

    if (!is_array($raw)) {
        throw new InvalidArgumentException('Select at least one target audience.');
    }

    $allowed = announcement_selectable_roles();
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
