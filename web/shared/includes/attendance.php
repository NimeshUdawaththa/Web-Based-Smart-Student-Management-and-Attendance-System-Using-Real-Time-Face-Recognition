<?php

declare(strict_types=1);

/**
 * State-based attendance engine.
 *
 * PHP is authoritative. Does not write attendance_records or finalize
 * PRESENT / LATE / ABSENT / LEFT EARLY. Preview helpers only.
 */

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

function early_arrival_minutes(): int
{
    return max(0, (int) (env('EARLY_ARRIVAL_MINUTES', '10') ?? '10'));
}

function min_attendance_percent(): float
{
    return max(0.0, min(100.0, (float) (env('MIN_ATTENDANCE_PERCENT', '75') ?? '75')));
}

function optional_time_hm(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $hm = normalize_time_hm($value);

    return validate_time_hm($hm) ? $hm : null;
}

/**
 * @return list<string>
 */
function validate_break_times(string $lectureStart, string $lectureEnd, ?string $breakStart, ?string $breakEnd): array
{
    $errors = [];
    $start = normalize_time_hm($lectureStart);
    $end = normalize_time_hm($lectureEnd);
    $hasStart = $breakStart !== null && trim($breakStart) !== '';
    $hasEnd = $breakEnd !== null && trim($breakEnd) !== '';

    if (!$hasStart && !$hasEnd) {
        return [];
    }
    if ($hasStart !== $hasEnd) {
        $errors[] = 'Enter both official break start and end, or leave both empty.';
        return $errors;
    }

    $breakStartHm = optional_time_hm($breakStart);
    $breakEndHm = optional_time_hm($breakEnd);
    if ($breakStartHm === null || $breakEndHm === null) {
        $errors[] = 'Enter valid official break times.';
        return $errors;
    }
    if ($breakEndHm <= $breakStartHm) {
        $errors[] = 'Official break end must be after break start.';
    }
    if ($breakStartHm < $start || $breakEndHm > $end) {
        $errors[] = 'Official break must lie inside the lecture start and end times.';
    }

    return $errors;
}

function format_break_display(?string $start, ?string $end): string
{
    $startHm = optional_time_hm($start);
    $endHm = optional_time_hm($end);
    if ($startHm === null || $endHm === null) {
        return 'None';
    }

    return format_time_display($startHm) . '–' . format_time_display($endHm);
}

/**
 * ENTRY or EXIT from the internal camera payload. Never inferred by alternating events.
 */
function normalize_camera_mode(?string $mode, ?string $cameraId = null): string
{
    $mode = strtoupper(trim((string) $mode));
    if ($mode === 'ENTRY' || $mode === 'EXIT') {
        return $mode;
    }

    $camera = strtolower((string) $cameraId);
    if ($camera !== '' && (str_contains($camera, 'exit') || str_ends_with($camera, '-out'))) {
        return 'EXIT';
    }

    return 'ENTRY';
}

function session_break_start_datetime(array $session): ?DateTimeImmutable
{
    $hm = optional_time_hm(isset($session['break_start']) ? (string) $session['break_start'] : null);
    $date = (string) ($session['session_date'] ?? '');
    if ($hm === null || !validate_date_ymd($date)) {
        return null;
    }

    return DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $hm, new DateTimeZone(APP_TIMEZONE)) ?: null;
}

function session_break_end_datetime(array $session): ?DateTimeImmutable
{
    $hm = optional_time_hm(isset($session['break_end']) ? (string) $session['break_end'] : null);
    $date = (string) ($session['session_date'] ?? '');
    if ($hm === null || !validate_date_ymd($date)) {
        return null;
    }

    return DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $hm, new DateTimeZone(APP_TIMEZONE)) ?: null;
}

function session_early_open_datetime(array $session): ?DateTimeImmutable
{
    $start = session_scheduled_datetime($session, 'start');
    if (!$start instanceof DateTimeImmutable) {
        return null;
    }

    return $start->modify('-' . early_arrival_minutes() . ' minutes');
}

function cancel_open_early_pending_for_session(PDO $pdo, int $sessionId): int
{
    $statement = $pdo->prepare(
        "UPDATE attendance_early_pending
         SET status = 'CANCELLED', inside = 0
         WHERE session_id = :session_id AND status = 'OPEN'"
    );
    $statement->execute(['session_id' => $sessionId]);

    return $statement->rowCount();
}

function cancel_open_early_pending_for_terminal_sessions(PDO $pdo): int
{
    $statement = $pdo->query(
        "UPDATE attendance_early_pending p
         INNER JOIN lecture_sessions ls ON ls.session_id = p.session_id
         SET p.status = 'CANCELLED', p.inside = 0
         WHERE p.status = 'OPEN'
           AND ls.status IN ('COMPLETED', 'CANCELLED')"
    );

    return $statement === false ? 0 : $statement->rowCount();
}

/**
 * Promote OPEN inside pending rows to official IN at scheduled start.
 * Duplicate IN is impossible: pending row is locked, existing IN is reused,
 * and pending is marked PROMOTED with promoted_event_id.
 */
function promote_open_early_pending_for_session(PDO $pdo, int $sessionId, DateTimeImmutable $scheduledStart): int
{
    $lock = $pdo->prepare(
        "SELECT pending_id, student_id, session_id, inside, early_entry_time,
                last_direction, last_seen_at, confidence, camera_id, status, promoted_event_id
         FROM attendance_early_pending
         WHERE session_id = :session_id AND status = 'OPEN'
         FOR UPDATE"
    );
    $lock->execute(['session_id' => $sessionId]);
    $rows = $lock->fetchAll();
    $lock->closeCursor();

    $promoted = 0;
    $insert = $pdo->prepare(
        "INSERT INTO attendance_events
            (student_id, session_id, event_type, recognized_at, confidence, camera_id)
         VALUES
            (:student_id, :session_id, 'IN', :recognized_at, :confidence, :camera_id)"
    );
    $markPromoted = $pdo->prepare(
        "UPDATE attendance_early_pending
         SET status = 'PROMOTED', inside = 1, promoted_event_id = :promoted_event_id
         WHERE pending_id = :pending_id AND status = 'OPEN'"
    );
    $markCancelled = $pdo->prepare(
        "UPDATE attendance_early_pending
         SET status = 'CANCELLED', inside = 0
         WHERE pending_id = :pending_id AND status = 'OPEN'"
    );
    $existingIn = $pdo->prepare(
        "SELECT event_id FROM attendance_events
         WHERE student_id = :student_id AND session_id = :session_id AND event_type = 'IN'
         ORDER BY event_id ASC
         LIMIT 1"
    );

    foreach ($rows as $row) {
        if ((int) $row['inside'] !== 1) {
            $markCancelled->execute(['pending_id' => $row['pending_id']]);
            continue;
        }

        $existingIn->execute([
            'student_id' => $row['student_id'],
            'session_id' => $sessionId,
        ]);
        $existingId = $existingIn->fetchColumn();
        $existingIn->closeCursor();

        if ($existingId !== false) {
            $markPromoted->execute([
                'promoted_event_id' => (int) $existingId,
                'pending_id' => $row['pending_id'],
            ]);
            $promoted++;
            continue;
        }

        $confidence = $row['confidence'] !== null ? (float) $row['confidence'] : 0.0;
        $insert->execute([
            'student_id' => $row['student_id'],
            'session_id' => $sessionId,
            'recognized_at' => $scheduledStart->format('Y-m-d H:i:s'),
            'confidence' => $confidence,
            'camera_id' => $row['camera_id'],
        ]);
        $eventId = (int) $pdo->lastInsertId();
        $markPromoted->execute([
            'promoted_event_id' => $eventId,
            'pending_id' => $row['pending_id'],
        ]);
        $promoted++;
    }

    return $promoted;
}

/**
 * @return list<array<string, mixed>>
 */
function list_session_early_pending(int $sessionId): array
{
    $statement = db()->prepare(
        "SELECT p.pending_id, p.student_id, p.session_id, p.inside, p.early_entry_time,
                p.last_direction, p.last_seen_at, p.status, p.promoted_event_id,
                s.registration_no, s.first_name, s.last_name
         FROM attendance_early_pending p
         INNER JOIN students s ON s.student_id = p.student_id
         WHERE p.session_id = :session_id
         ORDER BY p.last_seen_at DESC, p.pending_id DESC"
    );
    $statement->execute(['session_id' => $sessionId]);

    return $statement->fetchAll();
}

function upsert_early_pending_presence(
    PDO $pdo,
    int $studentId,
    int $sessionId,
    bool $inside,
    DateTimeImmutable $seenAt,
    string $direction,
    float $confidence,
    ?string $cameraId
): void {
    $earlyEntry = $inside ? $seenAt->format('Y-m-d H:i:s') : null;
    $statement = $pdo->prepare(
        "INSERT INTO attendance_early_pending
            (student_id, session_id, inside, early_entry_time, last_direction, last_seen_at,
             confidence, camera_id, status, promoted_event_id)
         VALUES
            (:student_id, :session_id, :inside, :early_entry_time, :last_direction, :last_seen_at,
             :confidence, :camera_id, 'OPEN', NULL)
         ON DUPLICATE KEY UPDATE
            inside = IF(status = 'PROMOTED', inside, VALUES(inside)),
            early_entry_time = IF(status = 'PROMOTED', early_entry_time, VALUES(early_entry_time)),
            last_direction = IF(status = 'PROMOTED', last_direction, VALUES(last_direction)),
            last_seen_at = IF(status = 'PROMOTED', last_seen_at, VALUES(last_seen_at)),
            confidence = IF(status = 'PROMOTED', confidence, VALUES(confidence)),
            camera_id = IF(status = 'PROMOTED', camera_id, VALUES(camera_id)),
            status = IF(status = 'PROMOTED', status, 'OPEN'),
            promoted_event_id = IF(status = 'PROMOTED', promoted_event_id, NULL)"
    );
    $statement->execute([
        'student_id' => $studentId,
        'session_id' => $sessionId,
        'inside' => $inside ? 1 : 0,
        'early_entry_time' => $earlyEntry,
        'last_direction' => $direction,
        'last_seen_at' => $seenAt->format('Y-m-d H:i:s'),
        'confidence' => $confidence,
        'camera_id' => $cameraId,
    ]);
}

/**
 * @return array{inside: bool, last_type: string|null, last_event: array<string, mixed>|null}
 */
function student_session_presence_from_events(array $events): array
{
    $last = null;
    foreach ($events as $event) {
        $last = $event;
    }
    if (!is_array($last)) {
        return ['inside' => false, 'last_type' => null, 'last_event' => null];
    }

    $type = (string) ($last['event_type'] ?? '');

    return [
        'inside' => $type === 'IN',
        'last_type' => $type !== '' ? $type : null,
        'last_event' => $last,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function list_attendance_events_for_student_session(int $studentId, int $sessionId): array
{
    $statement = db()->prepare(
        "SELECT event_id, student_id, session_id, event_type, recognized_at, confidence, camera_id
         FROM attendance_events
         WHERE student_id = :student_id AND session_id = :session_id
         ORDER BY recognized_at ASC, event_id ASC"
    );
    $statement->execute([
        'student_id' => $studentId,
        'session_id' => $sessionId,
    ]);

    return $statement->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function list_session_attendance_events(int $sessionId, int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $statement = db()->prepare(
        "SELECT e.event_id, e.student_id, e.session_id, e.event_type, e.recognized_at, e.confidence, e.camera_id,
                s.registration_no, s.first_name, s.last_name
         FROM attendance_events e
         INNER JOIN students s ON s.student_id = e.student_id
         WHERE e.session_id = :session_id
         ORDER BY e.recognized_at DESC, e.event_id DESC
         LIMIT {$limit}"
    );
    $statement->execute(['session_id' => $sessionId]);

    return $statement->fetchAll();
}

/**
 * Resolve the lecture a recognition should attach to.
 * IN_PROGRESS sessions win. SCHEDULED sessions in the early-arrival window
 * are included so PENDING/TOO EARLY work before auto-start.
 *
 * @return array<string, mixed>
 */
function resolve_attendance_session_for_student(int $studentId): array
{
    sync_scheduled_session_states();

    $student = get_student($studentId);
    if ($student === null) {
        return ['result' => 'INVALID_STUDENT'];
    }

    $now = app_now();
    $today = $now->format('Y-m-d');
    $candidates = [];
    $tooEarly = [];
    foreach (list_lecture_sessions(['from' => $today, 'to' => $today]) as $session) {
        if (!in_array($session['status'], ['SCHEDULED', 'IN_PROGRESS'], true)) {
            continue;
        }
        if (!is_student_eligible_for_session($studentId, (int) $session['session_id'])) {
            continue;
        }
        $start = session_scheduled_datetime($session, 'start');
        $end = session_scheduled_datetime($session, 'end');
        $early = session_early_open_datetime($session);
        if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable || !$early instanceof DateTimeImmutable) {
            continue;
        }
        if ($now >= $end) {
            continue;
        }
        if ($now < $early) {
            $session['_start_ts'] = $start->getTimestamp();
            $tooEarly[] = $session;
            continue;
        }
        $session['_window'] = $now < $start ? 'pending' : 'open';
        $candidates[] = $session;
    }

    if ($candidates === [] && $tooEarly !== []) {
        usort(
            $tooEarly,
            static fn (array $a, array $b): int => $a['_start_ts'] <=> $b['_start_ts']
        );
        $earliest = $tooEarly[0]['_start_ts'];
        $candidates = array_values(array_filter(
            $tooEarly,
            static fn (array $session): bool => $session['_start_ts'] === $earliest
        ));
    }

    if ($candidates === []) {
        return [
            'result' => 'NO_ACTIVE_SESSION',
            'student' => $student,
        ];
    }

    if (count($candidates) > 1) {
        $ids = array_map(static fn (array $session): int => (int) $session['session_id'], $candidates);
        error_log(
            'Ambiguous attendance sessions for student_id=' . $studentId
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
        'session' => $candidates[0],
    ];
}

/**
 * @param array<string, mixed> $resolution
 * @return array<string, mixed>
 */
function public_attendance_result(array $resolution): array
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

/**
 * Record IN or OUT from a recognized student. Direction comes from camera mode.
 *
 * Door-camera early arrival:
 * - Before the early window: TOO_EARLY, no pending, no attendance_events.
 * - Early window until scheduled start: persist pending inside/outside.
 *   ENTRY → EARLY_PENDING (inside). EXIT → EARLY_EXIT (not inside).
 *   No official attendance_events until promotion at scheduled start.
 * - At/after scheduled start, lifecycle sync promotes pending inside → one IN
 *   with recognized_at = scheduled_start. Live recognitions then use the
 *   existing IN/OUT/re-entry engine.
 *
 * @return array<string, mixed>
 */
function record_face_attendance_event(
    int $studentId,
    float $confidence,
    ?string $cameraId = null,
    ?string $cameraMode = null
): array {
    $resolution = resolve_attendance_session_for_student($studentId);
    if (($resolution['result'] ?? '') !== 'ELIGIBLE') {
        return public_attendance_result($resolution);
    }

    /** @var array<string, mixed> $session */
    $session = $resolution['session'];
    $sessionId = (int) $session['session_id'];
    $fresh = get_lecture_session($sessionId);
    if ($fresh !== null) {
        $session = $fresh;
    }
    $camera = normalize_camera_id($cameraId);
    $mode = normalize_camera_mode($cameraMode, $camera);
    $confidence = max(0.0, min(100.0, round($confidence, 2)));
    $now = app_now();
    $start = session_scheduled_datetime($session, 'start');
    $end = session_scheduled_datetime($session, 'end');
    $early = session_early_open_datetime($session);

    $base = [
        'student_id' => $studentId,
        'session_id' => $sessionId,
        'module_code' => $session['module_code'] ?? null,
        'camera_id' => $camera,
        'camera_mode' => $mode,
    ];

    if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable || !$early instanceof DateTimeImmutable) {
        return $base + ['result' => 'ERROR'];
    }

    if ($now < $early) {
        return $base + ['result' => 'TOO_EARLY'];
    }

    if ($now < $start) {
        $pendingResult = record_early_pending_crossing($base, $studentId, $sessionId, $mode, $confidence, $camera, $now);
        if (($pendingResult['result'] ?? '') !== 'PROCEED_OFFICIAL') {
            return $pendingResult;
        }
        sync_scheduled_session_states(true);
        $freshAfterStart = get_lecture_session($sessionId);
        if ($freshAfterStart !== null) {
            $session = $freshAfterStart;
        }
        $now = app_now();
    }

    if ($session['status'] !== 'IN_PROGRESS') {
        return $base + ['result' => 'NO_ACTIVE_SESSION'];
    }

    $lockName = sprintf('att_evt_%d_%d', $studentId, $sessionId);
    $pdo = db();
    $lockAcquired = false;

    try {
        $lockStatement = $pdo->prepare('SELECT GET_LOCK(:lock_name, 5)');
        $lockStatement->execute(['lock_name' => $lockName]);
        $lockAcquired = (int) $lockStatement->fetchColumn() === 1;
        $lockStatement->closeCursor();
        if (!$lockAcquired) {
            error_log('Could not acquire attendance lock for student_id=' . $studentId . ' session_id=' . $sessionId);
            return $base + ['result' => 'ERROR'];
        }

        $pdo->beginTransaction();

        $statusLock = $pdo->prepare(
            "SELECT session_id, status FROM lecture_sessions WHERE session_id = :session_id FOR UPDATE"
        );
        $statusLock->execute(['session_id' => $sessionId]);
        $lockedSession = $statusLock->fetch();
        if ($lockedSession === false || $lockedSession['status'] !== 'IN_PROGRESS') {
            $pdo->rollBack();
            return $base + ['result' => 'NO_ACTIVE_SESSION'];
        }

        if (!is_student_eligible_for_session($studentId, $sessionId)) {
            $pdo->rollBack();
            return $base + ['result' => 'NOT_ELIGIBLE'];
        }

        $lastStatement = $pdo->prepare(
            "SELECT event_id, event_type, recognized_at FROM attendance_events
             WHERE student_id = :student_id AND session_id = :session_id
             ORDER BY recognized_at DESC, event_id DESC
             LIMIT 1
             FOR UPDATE"
        );
        $lastStatement->execute([
            'student_id' => $studentId,
            'session_id' => $sessionId,
        ]);
        $last = $lastStatement->fetch();
        $lastType = is_array($last) ? (string) $last['event_type'] : null;
        $inside = $lastType === 'IN';

        if ($mode === 'ENTRY' && $inside) {
            $pdo->commit();
            return $base + [
                'result' => 'ALREADY_INSIDE',
                'event_id' => (int) $last['event_id'],
                'recognized_at' => (string) $last['recognized_at'],
            ];
        }

        if ($mode === 'EXIT' && !$inside) {
            $pdo->commit();
            return $base + [
                'result' => $lastType === 'OUT' ? 'ALREADY_OUTSIDE' : 'ALREADY_OUTSIDE',
                'event_id' => is_array($last) ? (int) $last['event_id'] : null,
                'recognized_at' => is_array($last) ? (string) $last['recognized_at'] : null,
            ];
        }

        $eventType = $mode === 'EXIT' ? 'OUT' : 'IN';
        $recognizedAt = $now;

        $insert = $pdo->prepare(
            "INSERT INTO attendance_events
                (student_id, session_id, event_type, recognized_at, confidence, camera_id)
             VALUES
                (:student_id, :session_id, :event_type, :recognized_at, :confidence, :camera_id)"
        );
        $insert->execute([
            'student_id' => $studentId,
            'session_id' => $sessionId,
            'event_type' => $eventType,
            'recognized_at' => $recognizedAt->format('Y-m-d H:i:s'),
            'confidence' => $confidence,
            'camera_id' => $camera,
        ]);
        $eventId = (int) $pdo->lastInsertId();
        $pdo->commit();

        $result = 'CHECKED_IN';
        if ($eventType === 'OUT') {
            $result = 'CHECKED_OUT';
        } elseif ($lastType === 'OUT') {
            $result = 'RE_ENTERED';
        }

        return $base + [
            'result' => $result,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'recognized_at' => $recognizedAt->format('Y-m-d H:i:s'),
            'confidence' => $confidence,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Face attendance event failed for student_id=' . $studentId . ': ' . $exception->getMessage());
        return $base + ['result' => 'ERROR'];
    } finally {
        if ($lockAcquired) {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => $lockName]);
            $release->closeCursor();
        }
    }
}

/**
 * Persist door ENTRY/EXIT inside the early-arrival window. No attendance_events.
 *
 * @param array<string, mixed> $base
 * @return array<string, mixed>
 */
function record_early_pending_crossing(
    array $base,
    int $studentId,
    int $sessionId,
    string $mode,
    float $confidence,
    ?string $cameraId,
    DateTimeImmutable $seenAt
): array {
    $lockName = sprintf('att_evt_%d_%d', $studentId, $sessionId);
    $pdo = db();
    $lockAcquired = false;

    try {
        $lockStatement = $pdo->prepare('SELECT GET_LOCK(:lock_name, 5)');
        $lockStatement->execute(['lock_name' => $lockName]);
        $lockAcquired = (int) $lockStatement->fetchColumn() === 1;
        $lockStatement->closeCursor();
        if (!$lockAcquired) {
            error_log('Could not acquire early-pending lock for student_id=' . $studentId . ' session_id=' . $sessionId);
            return $base + ['result' => 'ERROR'];
        }

        $pdo->beginTransaction();

        $sessionLock = $pdo->prepare(
            "SELECT session_id, status, session_date, scheduled_start, scheduled_end
             FROM lecture_sessions
             WHERE session_id = :session_id
             FOR UPDATE"
        );
        $sessionLock->execute(['session_id' => $sessionId]);
        $lockedSession = $sessionLock->fetch();
        $sessionLock->closeCursor();
        if ($lockedSession === false) {
            $pdo->rollBack();
            return $base + ['result' => 'NO_ACTIVE_SESSION'];
        }

        $start = session_scheduled_datetime($lockedSession, 'start');
        $now = app_now();
        if (!$start instanceof DateTimeImmutable) {
            $pdo->rollBack();
            return $base + ['result' => 'ERROR'];
        }
        if ($now >= $start) {
            $pdo->rollBack();
            return $base + ['result' => 'PROCEED_OFFICIAL'];
        }

        $inside = $mode === 'ENTRY';
        upsert_early_pending_presence(
            $pdo,
            $studentId,
            $sessionId,
            $inside,
            $seenAt,
            $mode,
            $confidence,
            $cameraId
        );
        $pdo->commit();

        return $base + [
            'result' => $inside ? 'EARLY_PENDING' : 'EARLY_EXIT',
            'pending_inside' => $inside ? 1 : 0,
            'early_entry_time' => $inside ? $seenAt->format('Y-m-d H:i:s') : null,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Early pending attendance failed for student_id=' . $studentId . ': ' . $exception->getMessage());
        return $base + ['result' => 'ERROR'];
    } finally {
        if ($lockAcquired) {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => $lockName]);
            $release->closeCursor();
        }
    }
}

function record_face_check_in(int $studentId, float $confidence, ?string $cameraId = null): array
{
    return record_face_attendance_event($studentId, $confidence, $cameraId, 'ENTRY');
}

/**
 * @return list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>
 */
function teaching_intervals_for_session(array $session): array
{
    $start = session_scheduled_datetime($session, 'start');
    $end = session_scheduled_datetime($session, 'end');
    if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable || $end <= $start) {
        return [];
    }

    $breakStart = session_break_start_datetime($session);
    $breakEnd = session_break_end_datetime($session);
    if (
        $breakStart instanceof DateTimeImmutable
        && $breakEnd instanceof DateTimeImmutable
        && $breakEnd > $breakStart
        && $breakStart >= $start
        && $breakEnd <= $end
    ) {
        $intervals = [];
        if ($breakStart > $start) {
            $intervals[] = [$start, $breakStart];
        }
        if ($end > $breakEnd) {
            $intervals[] = [$breakEnd, $end];
        }

        return $intervals;
    }

    return [[$start, $end]];
}

/**
 * @param list<array<string, mixed>> $events
 * @return list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>
 */
function presence_intervals_from_events(array $events, DateTimeImmutable $clipEnd): array
{
    $inside = false;
    $open = null;
    $intervals = [];

    foreach ($events as $event) {
        $at = parse_app_datetime(isset($event['recognized_at']) ? (string) $event['recognized_at'] : null);
        if (!$at instanceof DateTimeImmutable) {
            continue;
        }
        $type = (string) ($event['event_type'] ?? '');
        if ($type === 'IN' && !$inside) {
            $open = $at;
            $inside = true;
        } elseif ($type === 'OUT' && $inside && $open instanceof DateTimeImmutable) {
            if ($at > $open) {
                $intervals[] = [$open, $at];
            }
            $inside = false;
            $open = null;
        }
    }

    if ($inside && $open instanceof DateTimeImmutable && $clipEnd > $open) {
        $intervals[] = [$open, $clipEnd];
    }

    return $intervals;
}

/**
 * @param list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> $left
 * @param list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> $right
 * @return list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>
 */
function intersect_datetime_intervals(array $left, array $right): array
{
    $out = [];
    foreach ($left as [$a0, $a1]) {
        foreach ($right as [$b0, $b1]) {
            $start = $a0 > $b0 ? $a0 : $b0;
            $end = $a1 < $b1 ? $a1 : $b1;
            if ($end > $start) {
                $out[] = [$start, $end];
            }
        }
    }

    return $out;
}

/**
 * @param list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> $intervals
 */
function datetime_intervals_minutes(array $intervals): int
{
    $seconds = 0;
    foreach ($intervals as [$start, $end]) {
        $seconds += max(0, $end->getTimestamp() - $start->getTimestamp());
    }

    return (int) floor($seconds / 60);
}

/**
 * Foundation for attended teaching time. Does not persist attendance_records.
 *
 * teaching_minutes = lecture length − official break
 * attended_teaching_minutes = time physically inside ∩ teaching intervals
 * missed_teaching_minutes = teaching_minutes − attended_teaching_minutes
 * attendance_percent = attended / teaching × 100 (0 if teaching is 0)
 *
 * @param list<array<string, mixed>>|null $events
 * @return array<string, mixed>
 */
function calculate_session_attendance_preview(array $session, int $studentId, ?array $events = null, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? app_now();
    $start = session_scheduled_datetime($session, 'start');
    $end = session_scheduled_datetime($session, 'end');
    $lateAt = session_late_threshold_datetime($session);
    $breakStart = session_break_start_datetime($session);
    $breakEnd = session_break_end_datetime($session);
    $events = $events ?? list_attendance_events_for_student_session($studentId, (int) $session['session_id']);

    $teaching = teaching_intervals_for_session($session);
    $teachingMinutes = datetime_intervals_minutes($teaching);
    $breakMinutes = 0;
    if ($breakStart instanceof DateTimeImmutable && $breakEnd instanceof DateTimeImmutable && $breakEnd > $breakStart) {
        $breakMinutes = (int) floor(($breakEnd->getTimestamp() - $breakStart->getTimestamp()) / 60);
    }

    $clipEnd = $end instanceof DateTimeImmutable
        ? ($now < $end ? $now : $end)
        : $now;
    $presence = presence_intervals_from_events($events, $clipEnd);
    $attendedIntervals = intersect_datetime_intervals($presence, $teaching);
    $attended = datetime_intervals_minutes($attendedIntervals);
    $physical = datetime_intervals_minutes($presence);
    $missed = max(0, $teachingMinutes - $attended);
    $percent = $teachingMinutes > 0 ? round(($attended / $teachingMinutes) * 100, 1) : 0.0;

    $firstIn = null;
    $lastOut = null;
    foreach ($events as $event) {
        $at = parse_app_datetime(isset($event['recognized_at']) ? (string) $event['recognized_at'] : null);
        if (!$at instanceof DateTimeImmutable) {
            continue;
        }
        if (($event['event_type'] ?? '') === 'IN' && $firstIn === null) {
            $firstIn = $at;
        }
        if (($event['event_type'] ?? '') === 'OUT') {
            $lastOut = $at;
        }
    }

    $presenceState = student_session_presence_from_events($events);
    $inside = (bool) $presenceState['inside'];

    $onTime = false;
    $late = false;
    if ($firstIn instanceof DateTimeImmutable && $lateAt instanceof DateTimeImmutable) {
        $onTime = $firstIn <= $lateAt;
        $late = $firstIn > $lateAt;
    }

    $leftEarly = false;
    if ($firstIn instanceof DateTimeImmutable && !$inside && $lastOut instanceof DateTimeImmutable && $end instanceof DateTimeImmutable && $lastOut < $end) {
        $duringBreak = $breakStart instanceof DateTimeImmutable
            && $breakEnd instanceof DateTimeImmutable
            && $lastOut >= $breakStart
            && $lastOut < $breakEnd;
        if ($duringBreak) {
            $leftEarly = $now >= $breakEnd;
        } else {
            $leftEarly = true;
        }
    }

    $absent = $firstIn === null || $percent < min_attendance_percent();

    return [
        'student_id' => $studentId,
        'session_id' => (int) ($session['session_id'] ?? 0),
        'first_official_entry' => $firstIn?->format('Y-m-d H:i:s'),
        'last_exit' => $lastOut?->format('Y-m-d H:i:s'),
        'currently_inside' => $inside,
        'lecture_minutes' => ($start instanceof DateTimeImmutable && $end instanceof DateTimeImmutable)
            ? (int) floor(($end->getTimestamp() - $start->getTimestamp()) / 60)
            : 0,
        'official_break_minutes' => $breakMinutes,
        'teaching_minutes' => $teachingMinutes,
        'physical_inside_minutes' => $physical,
        'attended_teaching_minutes' => $attended,
        'missed_teaching_minutes' => $missed,
        'attendance_percent' => $percent,
        'min_attendance_percent' => min_attendance_percent(),
        'preview_on_time_candidate' => $onTime,
        'preview_late_candidate' => $late,
        'preview_left_early_candidate' => $leftEarly,
        'preview_absent_candidate' => $absent,
        'final_status_applied' => false,
    ];
}

function update_lecture_session_break(int $sessionId, ?string $breakStart, ?string $breakEnd): void
{
    $session = get_lecture_session($sessionId);
    if ($session === null) {
        throw new InvalidArgumentException('Lecture session not found.');
    }
    if (!user_can_control_session($session)) {
        throw new InvalidArgumentException('You do not have permission to change this session break.');
    }
    if (in_array($session['status'], ['COMPLETED', 'CANCELLED'], true)) {
        throw new InvalidArgumentException('A completed or cancelled session cannot be edited.');
    }

    $errors = validate_break_times(
        (string) $session['scheduled_start'],
        (string) $session['scheduled_end'],
        $breakStart,
        $breakEnd
    );
    if ($errors !== []) {
        throw new InvalidArgumentException($errors[0]);
    }

    $startHm = optional_time_hm($breakStart);
    $endHm = optional_time_hm($breakEnd);
    $statement = db()->prepare(
        'UPDATE lecture_sessions
         SET break_start = :break_start, break_end = :break_end
         WHERE session_id = :session_id'
    );
    $statement->execute([
        'break_start' => $startHm,
        'break_end' => $endHm,
        'session_id' => $sessionId,
    ]);
}
