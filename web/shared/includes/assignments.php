<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Coursework & Assessments (Assignment / Presentation / Exam / Practical).
 *
 * ASSIGNMENT:
 *   due_date required; schedule fields NULL; student file submission.
 *
 * PRESENTATION:
 *   scheduled_date/start/end required (room optional); student supporting-file
 *   submission still enabled. Compatibility: due_date is derived from the
 *   scheduled end datetime (DB column remains NOT NULL) — UI must show the
 *   presentation schedule, not a misleading "Due …" label.
 *
 * EXAM / PRACTICAL:
 *   schedule required; no student submission; lecturers use assignment_results.
 *
 * Calendar-ready (helpers only; UI integration is a later phase):
 *   PRESENTATION, EXAM, PRACTICAL — not ASSIGNMENT. Never lecture_sessions.
 */

function assignment_statuses(): array
{
    return ['DRAFT', 'PUBLISHED', 'CLOSED'];
}

function assignment_activity_types(): array
{
    return ['ASSIGNMENT', 'PRESENTATION', 'EXAM', 'PRACTICAL'];
}

/**
 * @param array<string, mixed>|string $assignmentOrType
 */
function assignment_requires_submission(array|string $assignmentOrType): bool
{
    $type = is_array($assignmentOrType)
        ? strtoupper(trim((string) ($assignmentOrType['activity_type'] ?? '')))
        : strtoupper(trim($assignmentOrType));

    return match ($type) {
        'ASSIGNMENT', 'PRESENTATION' => true,
        'EXAM', 'PRACTICAL' => false,
        default => throw new InvalidArgumentException('Select a valid activity type.'),
    };
}

/**
 * Whether the activity needs scheduled_date / start_time / end_time.
 * PRESENTATION is scheduled but still accepts supporting-file submissions.
 */
function assignment_requires_schedule(array|string $assignmentOrType): bool
{
    $type = is_array($assignmentOrType)
        ? strtoupper(trim((string) ($assignmentOrType['activity_type'] ?? '')))
        : strtoupper(trim($assignmentOrType));

    return match ($type) {
        'PRESENTATION', 'EXAM', 'PRACTICAL' => true,
        'ASSIGNMENT' => false,
        default => throw new InvalidArgumentException('Select a valid activity type.'),
    };
}

/** Alias for calendar-ready helpers (Presentation / Exam / Practical). */
function assignment_is_calendar_activity(array|string $assignmentOrType): bool
{
    return assignment_requires_schedule($assignmentOrType);
}

function normalize_assignment_activity_type(mixed $raw): string
{
    $type = strtoupper(trim((string) $raw));
    if (!in_array($type, assignment_activity_types(), true)) {
        throw new InvalidArgumentException('Select a valid activity type.');
    }

    return $type;
}

function normalize_assignment_room(mixed $raw): ?string
{
    $text = is_string($raw) ? trim($raw) : '';
    if ($text === '') {
        return null;
    }
    if (mb_strlen($text) > 150) {
        throw new InvalidArgumentException('Room must be 150 characters or fewer.');
    }

    return $text;
}

/**
 * @return array{date: ?string, start: ?string, end: ?string}
 */
function normalize_assignment_schedule(mixed $dateRaw, mixed $startRaw, mixed $endRaw, bool $required): array
{
    $date = is_string($dateRaw) ? trim($dateRaw) : ($dateRaw === null ? '' : trim((string) $dateRaw));
    $start = is_string($startRaw) ? trim($startRaw) : ($startRaw === null ? '' : trim((string) $startRaw));
    $end = is_string($endRaw) ? trim($endRaw) : ($endRaw === null ? '' : trim((string) $endRaw));

    $empty = ($date === '' && $start === '' && $end === '');
    if ($empty) {
        if ($required) {
            throw new InvalidArgumentException('Scheduled date, start time and end time are required for this activity type.');
        }

        return ['date' => null, 'start' => null, 'end' => null];
    }

    if ($date === '' || $start === '' || $end === '') {
        throw new InvalidArgumentException('Scheduled date, start time and end time must all be provided together.');
    }
    if (!validate_date_ymd($date)) {
        throw new InvalidArgumentException('Enter a valid scheduled date.');
    }

    $startNorm = normalize_time_hm($start);
    $endNorm = normalize_time_hm($end);
    if (!validate_time_hm($startNorm) || !validate_time_hm($endNorm)) {
        throw new InvalidArgumentException('Enter valid start and end times.');
    }
    if ($endNorm <= $startNorm) {
        throw new InvalidArgumentException('End time must be after start time.');
    }

    return [
        'date' => $date,
        'start' => $startNorm . ':00',
        'end' => $endNorm . ':00',
    ];
}

function assignment_results_table_exists(): bool
{
    $statement = db()->query("SHOW TABLES LIKE 'assignment_results'");

    return $statement !== false && $statement->fetchColumn() !== false;
}

function assignments_column_exists(string $column): bool
{
    $statement = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $statement->execute([
        'table_name' => 'assignments',
        'column_name' => $column,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

/**
 * @return list<string>
 */
function migrate_coursework_assessments_unification_schema(): array
{
    $notes = [];
    $pdo = db();

    if (!assignments_column_exists('activity_type')) {
        $pdo->exec(
            "ALTER TABLE assignments
             ADD COLUMN activity_type ENUM('ASSIGNMENT', 'PRESENTATION', 'EXAM', 'PRACTICAL')
             NOT NULL DEFAULT 'ASSIGNMENT' AFTER file_path"
        );
        $notes[] = 'added assignments.activity_type';
    }
    if (!assignments_column_exists('scheduled_date')) {
        $pdo->exec('ALTER TABLE assignments ADD COLUMN scheduled_date DATE DEFAULT NULL AFTER due_date');
        $notes[] = 'added assignments.scheduled_date';
    }
    if (!assignments_column_exists('start_time')) {
        $pdo->exec('ALTER TABLE assignments ADD COLUMN start_time TIME DEFAULT NULL AFTER scheduled_date');
        $notes[] = 'added assignments.start_time';
    }
    if (!assignments_column_exists('end_time')) {
        $pdo->exec('ALTER TABLE assignments ADD COLUMN end_time TIME DEFAULT NULL AFTER start_time');
        $notes[] = 'added assignments.end_time';
    }
    if (!assignments_column_exists('room')) {
        $pdo->exec('ALTER TABLE assignments ADD COLUMN room VARCHAR(150) DEFAULT NULL AFTER end_time');
        $notes[] = 'added assignments.room';
    }

    if (!assignment_results_table_exists()) {
        $pdo->exec(
            "CREATE TABLE assignment_results (
              result_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              assignment_id INT UNSIGNED NOT NULL,
              student_id INT UNSIGNED NOT NULL,
              marks_obtained DECIMAL(8,2) NOT NULL,
              remarks TEXT DEFAULT NULL,
              recorded_by INT UNSIGNED NOT NULL,
              recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (result_id),
              UNIQUE KEY uq_assignment_results_assignment_student (assignment_id, student_id),
              KEY idx_assignment_results_student (student_id),
              KEY idx_assignment_results_recorded_by (recorded_by),
              CONSTRAINT chk_assignment_results_marks_nonneg CHECK (marks_obtained >= 0),
              CONSTRAINT fk_assignment_results_assignment
                FOREIGN KEY (assignment_id) REFERENCES assignments (assignment_id)
                ON DELETE RESTRICT
                ON UPDATE CASCADE,
              CONSTRAINT fk_assignment_results_student
                FOREIGN KEY (student_id) REFERENCES students (student_id)
                ON DELETE RESTRICT
                ON UPDATE CASCADE,
              CONSTRAINT fk_assignment_results_recorded_by
                FOREIGN KEY (recorded_by) REFERENCES lecturers (lecturer_id)
                ON DELETE RESTRICT
                ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $notes[] = 'created assignment_results table';
    }

    return $notes;
}

function assignment_allowed_extensions(): array
{
    return ['pdf', 'doc', 'docx', 'zip'];
}

function assignment_forbidden_extensions(): array
{
    return [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'pht', 'phps',
        'exe', 'com', 'scr', 'dll', 'so', 'bat', 'cmd', 'ps1', 'sh', 'bash',
        'js', 'mjs', 'html', 'htm', 'shtml', 'xhtml', 'svg', 'hta',
        'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp', 'jar', 'war', 'msi',
        'vbs', 'vbe', 'wsf',
    ];
}

function assignment_mime_map(): array
{
    return [
        'pdf' => ['application/pdf', 'application/octet-stream', 'text/plain'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream',
        ],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
    ];
}

function assignment_storage_root(): string
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

function assignment_ensure_storage_dirs(): void
{
    $root = assignment_storage_root();
    foreach (['assignments', 'assignments/briefs', 'assignments/submissions'] as $relative) {
        $dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create assignment upload directory.');
        }
    }
}

function format_assignment_datetime(?string $value): string
{
    $parsed = parse_app_datetime($value);

    return $parsed instanceof DateTimeImmutable ? $parsed->format('Y-m-d H:i') : '';
}

function assignment_activity_type_label(array|string $assignmentOrType): string
{
    $type = is_array($assignmentOrType)
        ? strtoupper(trim((string) ($assignmentOrType['activity_type'] ?? 'ASSIGNMENT')))
        : strtoupper(trim($assignmentOrType));

    return match ($type) {
        'ASSIGNMENT' => 'Assignment',
        'PRESENTATION' => 'Presentation',
        'EXAM' => 'Exam',
        'PRACTICAL' => 'Practical',
        default => 'Activity',
    };
}

function assignment_activity_badge_class(array|string $assignmentOrType): string
{
    $type = is_array($assignmentOrType)
        ? strtoupper(trim((string) ($assignmentOrType['activity_type'] ?? 'ASSIGNMENT')))
        : strtoupper(trim($assignmentOrType));

    return match ($type) {
        'ASSIGNMENT' => 'text-bg-primary',
        'PRESENTATION' => 'text-bg-info',
        'EXAM' => 'text-bg-warning',
        'PRACTICAL' => 'text-bg-success',
        default => 'text-bg-secondary',
    };
}

function format_assignment_time_hm(?string $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^(\d{2}:\d{2})/', $raw, $matches) === 1) {
        return $matches[1];
    }

    return $raw;
}

/**
 * Human-readable schedule line for Exam/Practical (date · start–end).
 */
function format_assignment_schedule_summary(array $assignment): string
{
    $dateRaw = trim((string) ($assignment['scheduled_date'] ?? ''));
    $start = format_assignment_time_hm($assignment['start_time'] ?? null);
    $end = format_assignment_time_hm($assignment['end_time'] ?? null);
    if ($dateRaw === '' || $start === '' || $end === '') {
        return '—';
    }

    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $dateRaw);
    $dateLabel = $parsed instanceof DateTimeImmutable ? $parsed->format('d M Y') : $dateRaw;

    return $dateLabel . ' · ' . $start . '–' . $end;
}

/**
 * Derive the required due_date column from an Exam/Practical end sitting.
 */
function assignment_due_date_from_schedule(string $scheduledDate, string $endTime): string
{
    $end = normalize_time_hm($endTime);

    return $scheduledDate . ' ' . $end . ':00';
}

function student_coursework_display_status(array $assignment, ?array $submission): string
{
    if (!assignment_requires_submission($assignment)) {
        return (($assignment['status'] ?? '') === 'CLOSED') ? 'CLOSED' : 'PUBLISHED';
    }

    if ($submission !== null && ($submission['status'] ?? '') === 'GRADED') {
        return 'GRADED';
    }

    if (($assignment['status'] ?? '') === 'CLOSED') {
        return 'CLOSED';
    }

    if ($submission !== null) {
        return assignment_submission_is_late($assignment, $submission) ? 'LATE' : 'SUBMITTED';
    }

    if (($assignment['status'] ?? '') !== 'PUBLISHED') {
        return 'CLOSED';
    }

    if (assignment_due_has_passed($assignment)) {
        return 'NOT SUBMITTED';
    }

    return 'OPEN';
}

function student_is_enrolled_in_module(int $studentId, int $moduleId): bool
{
    $enrolment = get_student_module_enrolment($studentId, $moduleId);

    return $enrolment !== null && $enrolment['status'] === 'ENROLLED';
}

function assignment_due_has_passed(array $assignment): bool
{
    $due = parse_app_datetime((string) ($assignment['due_date'] ?? ''));
    if ($due === null) {
        return true;
    }

    return app_now() > $due;
}

function assignment_is_open_for_submission(array $assignment): bool
{
    return ($assignment['status'] ?? '') === 'PUBLISHED' && !assignment_due_has_passed($assignment);
}

function assignment_submission_is_late(array $assignment, array $submission): bool
{
    $due = parse_app_datetime((string) ($assignment['due_date'] ?? ''));
    $submitted = parse_app_datetime((string) ($submission['submitted_at'] ?? ''));
    if ($due === null || $submitted === null) {
        return false;
    }

    return $submitted > $due;
}

function lecturer_can_manage_coursework_assignment(int $lecturerId, array $assignment): bool
{
    return (int) $assignment['lecturer_id'] === $lecturerId
        && lecturer_is_assigned_to_module($lecturerId, (int) $assignment['module_id']);
}

function student_can_view_coursework_assignment(int $studentId, array $assignment): bool
{
    if (($assignment['status'] ?? '') === 'DRAFT') {
        return false;
    }

    return student_is_enrolled_in_module($studentId, (int) $assignment['module_id']);
}

function student_can_submit_coursework_assignment(int $studentId, array $assignment): bool
{
    if (!assignment_requires_submission($assignment)) {
        return false;
    }
    if (!student_can_view_coursework_assignment($studentId, $assignment)
        || !assignment_is_open_for_submission($assignment)
    ) {
        return false;
    }

    $existing = get_assignment_submission_for_student((int) $assignment['assignment_id'], $studentId);
    if ($existing !== null && ($existing['status'] ?? '') === 'GRADED') {
        return false;
    }

    return true;
}

function assignment_submission_is_graded(?array $submission): bool
{
    return $submission !== null && ($submission['status'] ?? '') === 'GRADED';
}

function format_assignment_grade_display(mixed $grade, mixed $maxMarks): string
{
    if ($grade === null || $grade === '') {
        return 'Not graded yet';
    }

    return rtrim(rtrim(number_format((float) $grade, 2, '.', ''), '0'), '.')
        . ' / '
        . rtrim(rtrim(number_format((float) $maxMarks, 2, '.', ''), '0'), '.');
}

/**
 * @throws InvalidArgumentException
 */
function parse_coursework_grade(mixed $raw, float $maxMarks): float
{
    if (is_int($raw) || is_float($raw)) {
        $text = (string) $raw;
    } elseif (is_string($raw)) {
        $text = trim($raw);
    } else {
        throw new InvalidArgumentException('Grade must be a number.');
    }

    if ($text === '' || preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
        throw new InvalidArgumentException('Grade must be a number.');
    }

    $grade = (float) $text;
    if ($grade < 0) {
        throw new InvalidArgumentException('Grade cannot be negative.');
    }
    if ($grade > $maxMarks) {
        throw new InvalidArgumentException('Grade cannot be greater than the maximum marks.');
    }

    return round($grade, 2);
}

function lecturer_can_grade_submission(int $lecturerId, array $assignment, array $submission): bool
{
    return lecturer_can_manage_coursework_assignment($lecturerId, $assignment)
        && (int) $submission['assignment_id'] === (int) $assignment['assignment_id'];
}

function student_can_view_submission_result(int $studentId, array $assignment, array $submission): bool
{
    return (int) $submission['student_id'] === $studentId
        && student_can_view_coursework_assignment($studentId, $assignment);
}

function staff_can_monitor_coursework(): bool
{
    return can_manage_academic();
}

function assignment_select_sql(): string
{
    return "SELECT a.assignment_id, a.module_id, a.lecturer_id, a.title, a.description, a.file_path,
                   a.activity_type, a.due_date, a.scheduled_date, a.start_time, a.end_time, a.room,
                   a.max_marks, a.status, a.created_at, a.updated_at,
                   m.module_code, m.module_name, m.status AS module_status,
                   (SELECT GROUP_CONCAT(c.course_code ORDER BY c.course_code SEPARATOR ', ')
                    FROM course_modules cm
                    INNER JOIN courses c ON c.course_id = cm.course_id
                    WHERE cm.module_id = m.module_id AND cm.status = 'ACTIVE') AS course_code,
                   l.staff_no, l.first_name AS lecturer_first_name, l.last_name AS lecturer_last_name
            FROM assignments a
            INNER JOIN modules m ON m.module_id = a.module_id
            INNER JOIN lecturers l ON l.lecturer_id = a.lecturer_id";
}

/**
 * @return array<string, mixed>|null
 */
function get_coursework_assignment(int $assignmentId): ?array
{
    $statement = db()->prepare(assignment_select_sql() . ' WHERE a.assignment_id = :assignment_id LIMIT 1');
    $statement->execute(['assignment_id' => $assignmentId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * @return list<array<string, mixed>>
 */
function list_coursework_assignments_for_lecturer(int $lecturerId): array
{
    $statement = db()->prepare(
        assignment_select_sql() . "
         INNER JOIN module_lecturers ml
            ON ml.module_id = a.module_id AND ml.lecturer_id = a.lecturer_id
         WHERE a.lecturer_id = :lecturer_id
         ORDER BY a.due_date DESC, a.assignment_id DESC"
    );
    $statement->execute(['lecturer_id' => $lecturerId]);

    return $statement->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function list_coursework_assignments_for_student(int $studentId): array
{
    $statement = db()->prepare(
        assignment_select_sql() . "
         INNER JOIN student_modules sm ON sm.module_id = a.module_id
         WHERE sm.student_id = :student_id
           AND sm.status = 'ENROLLED'
           AND a.status IN ('PUBLISHED', 'CLOSED')
         ORDER BY a.due_date ASC, a.assignment_id DESC"
    );
    $statement->execute(['student_id' => $studentId]);
    $rows = $statement->fetchAll();

    foreach ($rows as &$row) {
        if (!assignment_requires_submission($row)) {
            $direct = get_assignment_direct_result((int) $row['assignment_id'], $studentId);
            $row['own_direct_result'] = $direct;
            $row['own_submitted_at'] = null;
            $row['own_submission_status'] = null;
            $row['own_grade'] = $direct['marks_obtained'] ?? null;
            $row['own_feedback'] = $direct['remarks'] ?? null;
            if ($direct !== null) {
                $row['student_display_status'] = 'RECORDED';
                $row['own_grade_display'] = format_assignment_grade_display(
                    $direct['marks_obtained'],
                    $row['max_marks']
                );
            } else {
                $row['student_display_status'] = student_coursework_display_status($row, null);
                $row['own_grade_display'] = '—';
            }
            continue;
        }

        $submission = get_assignment_submission_for_student((int) $row['assignment_id'], $studentId);
        $row['own_direct_result'] = null;
        $row['student_display_status'] = student_coursework_display_status($row, $submission);
        $row['own_submitted_at'] = $submission['submitted_at'] ?? null;
        $row['own_submission_status'] = $submission['status'] ?? null;
        $row['own_grade'] = $submission['grade'] ?? null;
        $row['own_feedback'] = $submission['feedback'] ?? null;
        $row['own_grade_display'] = assignment_submission_is_graded($submission)
            ? format_assignment_grade_display($submission['grade'] ?? null, $row['max_marks'])
            : 'Not graded yet';
    }
    unset($row);

    return $rows;
}

/**
 * Graded coursework for My Results. Read-only. Does not write to marks.
 *
 * @return list<array<string, mixed>>
 */
function list_graded_coursework_results_for_student(int $studentId): array
{
    $statement = db()->prepare(
        "SELECT a.assignment_id, a.title, a.max_marks, a.module_id, a.status AS assignment_status,
                m.module_code, m.module_name,
                sub.submission_id, sub.student_id, sub.grade, sub.feedback, sub.status AS submission_status
         FROM assignment_submissions sub
         INNER JOIN assignments a ON a.assignment_id = sub.assignment_id
         INNER JOIN modules m ON m.module_id = a.module_id
         INNER JOIN student_modules sm
            ON sm.module_id = a.module_id AND sm.student_id = sub.student_id
         WHERE sub.student_id = :student_id
           AND sub.status = 'GRADED'
           AND sub.grade IS NOT NULL
           AND sm.status = 'ENROLLED'
           AND a.status <> 'DRAFT'
         ORDER BY m.module_code, a.title"
    );
    $statement->execute(['student_id' => $studentId]);
    $rows = $statement->fetchAll();

    $visible = [];
    foreach ($rows as $row) {
        if ((int) $row['student_id'] !== $studentId) {
            continue;
        }
        if (!student_can_view_submission_result($studentId, $row, $row)) {
            continue;
        }
        $max = (float) $row['max_marks'];
        $grade = (float) $row['grade'];
        $row['percentage'] = $max > 0 ? round(($grade / $max) * 100, 1) : null;
        $row['grade_display'] = format_assignment_grade_display($row['grade'], $row['max_marks']);
        $visible[] = $row;
    }

    return $visible;
}

/**
 * @return list<array<string, mixed>>
 */
function list_coursework_assignments_for_monitor(): array
{
    $statement = db()->query(
        assignment_select_sql() . ' ORDER BY a.due_date DESC, a.assignment_id DESC'
    );

    return $statement->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function get_assignment_submission(int $submissionId): ?array
{
    $statement = db()->prepare(
        'SELECT submission_id, assignment_id, student_id, file_path, submitted_at, status, grade, feedback
         FROM assignment_submissions
         WHERE submission_id = :submission_id
         LIMIT 1'
    );
    $statement->execute(['submission_id' => $submissionId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * @return array<string, mixed>|null
 */
function get_assignment_submission_for_student(int $assignmentId, int $studentId): ?array
{
    $statement = db()->prepare(
        'SELECT submission_id, assignment_id, student_id, file_path, submitted_at, status, grade, feedback
         FROM assignment_submissions
         WHERE assignment_id = :assignment_id AND student_id = :student_id
         LIMIT 1'
    );
    $statement->execute([
        'assignment_id' => $assignmentId,
        'student_id' => $studentId,
    ]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * @return list<array<string, mixed>>
 */
function list_enrolled_students_for_module(int $moduleId): array
{
    $statement = db()->prepare(
        "SELECT s.student_id, s.registration_no, s.first_name, s.last_name
         FROM student_modules sm
         INNER JOIN students s ON s.student_id = sm.student_id
         WHERE sm.module_id = :module_id AND sm.status = 'ENROLLED'
         ORDER BY s.registration_no"
    );
    $statement->execute(['module_id' => $moduleId]);

    return $statement->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function list_assignment_submission_matrix(int $assignmentId): array
{
    $assignment = get_coursework_assignment($assignmentId);
    if ($assignment === null) {
        return [];
    }

    $statement = db()->prepare(
        "SELECT s.student_id, s.registration_no, s.first_name, s.last_name,
                sub.submission_id, sub.file_path, sub.submitted_at, sub.status AS submission_status,
                sub.grade, sub.feedback
         FROM student_modules sm
         INNER JOIN students s ON s.student_id = sm.student_id
         LEFT JOIN assignment_submissions sub
            ON sub.assignment_id = :assignment_id AND sub.student_id = s.student_id
         WHERE sm.module_id = :module_id AND sm.status = 'ENROLLED'
         ORDER BY s.registration_no"
    );
    $statement->execute([
        'assignment_id' => $assignmentId,
        'module_id' => (int) $assignment['module_id'],
    ]);
    $rows = $statement->fetchAll();

    foreach ($rows as &$row) {
        if ($row['submission_id'] === null) {
            $row['matrix_status'] = 'NOT SUBMITTED';
            $row['timing'] = '—';
            $row['grade_display'] = '—';
        } else {
            $row['timing'] = assignment_submission_is_late($assignment, $row) ? 'Late' : 'On Time';
            if (($row['submission_status'] ?? '') === 'GRADED') {
                $row['matrix_status'] = 'GRADED';
                $row['grade_display'] = format_assignment_grade_display($row['grade'], $assignment['max_marks']);
            } else {
                $row['matrix_status'] = $row['timing'] === 'Late' ? 'LATE' : 'SUBMITTED';
                $row['grade_display'] = 'Not graded';
            }
        }
    }
    unset($row);

    return $rows;
}

function assignment_normalize_relative_path(string $relative): ?string
{
    $relative = str_replace('\\', '/', trim($relative));
    if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
        return null;
    }
    if (!str_starts_with($relative, 'assignments/')) {
        return null;
    }
    if (preg_match('/[[:cntrl:]]/', $relative) === 1) {
        return null;
    }

    return $relative;
}

function assignment_resolve_stored_path(string $relative): ?string
{
    $normalized = assignment_normalize_relative_path($relative);
    if ($normalized === null) {
        return null;
    }

    $root = assignment_storage_root();
    $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    $resolved = realpath($candidate);
    if ($resolved === false || !is_file($resolved)) {
        return null;
    }

    $rootPrefix = $root . DIRECTORY_SEPARATOR;
    $resolvedCmp = str_replace('/', DIRECTORY_SEPARATOR, $resolved);
    $rootCmp = str_replace('/', DIRECTORY_SEPARATOR, $rootPrefix);
    if (!str_starts_with(strtolower($resolvedCmp), strtolower($rootCmp))) {
        return null;
    }

    return $resolved;
}

function assignment_delete_stored_file(?string $relative): void
{
    if ($relative === null || $relative === '') {
        return;
    }

    $path = assignment_resolve_stored_path($relative);
    if ($path !== null && is_file($path)) {
        unlink($path);
    }
}

function assignment_extension_from_name(string $filename): string
{
    $base = basename(str_replace('\\', '/', $filename));
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));

    return $ext;
}

function assignment_validate_original_filename(string $filename): string
{
    $base = basename(str_replace(['\\', "\0"], ['/', ''], $filename));
    if ($base === '' || $base === '.' || $base === '..') {
        throw new InvalidArgumentException('Invalid file name.');
    }

    $lower = strtolower($base);
    if (str_contains($lower, '..')) {
        throw new InvalidArgumentException('Invalid file name.');
    }

    $parts = explode('.', $lower);
    array_shift($parts);
    foreach ($parts as $part) {
        if (in_array($part, assignment_forbidden_extensions(), true)) {
            throw new InvalidArgumentException('That file type is not allowed.');
        }
    }

    $ext = assignment_extension_from_name($base);
    if (!in_array($ext, assignment_allowed_extensions(), true)) {
        throw new InvalidArgumentException('Allowed file types: PDF, DOC, DOCX, ZIP.');
    }

    return $ext;
}

function assignment_file_matches_magic(string $path, string $ext): bool
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $header = fread($handle, 8) ?: '';
    fclose($handle);

    return match ($ext) {
        'pdf' => str_starts_with($header, '%PDF'),
        'zip', 'docx' => str_starts_with($header, 'PK'),
        'doc' => str_starts_with($header, "\xD0\xCF\x11\xE0"),
        default => false,
    };
}

/**
 * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed} $file
 * @return array{relative_path: string, download_name: string}
 */
function assignment_store_uploaded_file(array $file, string $subdir): array
{
    assignment_ensure_storage_dirs();

    if (!in_array($subdir, ['briefs', 'submissions'], true)) {
        throw new InvalidArgumentException('Invalid upload destination.');
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Choose a file to upload.');
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('That file is larger than the allowed upload size.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The file could not be uploaded.');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    $original = (string) ($file['name'] ?? '');

    if ($tmp === '' || !is_file($tmp)) {
        throw new InvalidArgumentException('The file could not be uploaded.');
    }
    if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('The file could not be uploaded.');
    }
    if ($size <= 0 || $size > ASSIGNMENT_UPLOAD_MAX_BYTES) {
        $mb = (string) max(1, (int) round(ASSIGNMENT_UPLOAD_MAX_BYTES / 1048576));
        throw new InvalidArgumentException('File must be between 1 byte and ' . $mb . ' MB.');
    }

    $ext = assignment_validate_original_filename($original);

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower((string) $finfo->file($tmp));
    $allowedMimes = assignment_mime_map()[$ext] ?? [];
    if ($allowedMimes !== [] && !in_array($mime, $allowedMimes, true)) {
        throw new InvalidArgumentException('The file contents do not match an allowed type.');
    }
    if (!assignment_file_matches_magic($tmp, $ext)) {
        throw new InvalidArgumentException('The file contents do not match an allowed type.');
    }

    $probe = file_get_contents($tmp, false, null, 0, 64) ?: '';
    $trimmed = ltrim($probe);
    if (str_starts_with($trimmed, '<?') || str_starts_with(strtolower($trimmed), '<script')) {
        throw new InvalidArgumentException('That file type is not allowed.');
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
    $relative = 'assignments/' . $subdir . '/' . $storedName;
    $destination = assignment_storage_root() . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    $moved = PHP_SAPI === 'cli'
        ? copy($tmp, $destination)
        : move_uploaded_file($tmp, $destination);

    if (!$moved) {
        throw new RuntimeException('Unable to store the uploaded file.');
    }

    $safeDownload = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($original)) ?: ('file.' . $ext);

    return [
        'relative_path' => $relative,
        'download_name' => $safeDownload,
    ];
}

/**
 * @param array{
 *   module_id: int,
 *   title: string,
 *   description: ?string,
 *   due_date: string,
 *   max_marks: float,
 *   status: string,
 *   file_path: ?string,
 *   activity_type?: string,
 *   scheduled_date?: ?string,
 *   start_time?: ?string,
 *   end_time?: ?string,
 *   room?: ?string
 * } $data
 */
function create_coursework_assignment(int $lecturerId, array $data): int
{
    if (!lecturer_is_assigned_to_module($lecturerId, $data['module_id'])) {
        throw new InvalidArgumentException('You can only create assignments for modules you teach.');
    }

    $data = assignment_normalize_payload($data);
    assignment_assert_payload($data);

    $statement = db()->prepare(
        'INSERT INTO assignments (
            module_id, lecturer_id, title, description, file_path, activity_type,
            due_date, scheduled_date, start_time, end_time, room, max_marks, status
         ) VALUES (
            :module_id, :lecturer_id, :title, :description, :file_path, :activity_type,
            :due_date, :scheduled_date, :start_time, :end_time, :room, :max_marks, :status
         )'
    );
    $statement->execute([
        'module_id' => $data['module_id'],
        'lecturer_id' => $lecturerId,
        'title' => $data['title'],
        'description' => $data['description'],
        'file_path' => $data['file_path'],
        'activity_type' => $data['activity_type'],
        'due_date' => $data['due_date'],
        'scheduled_date' => $data['scheduled_date'],
        'start_time' => $data['start_time'],
        'end_time' => $data['end_time'],
        'room' => $data['room'],
        'max_marks' => $data['max_marks'],
        'status' => $data['status'],
    ]);

    return (int) db()->lastInsertId();
}

/**
 * @param array{
 *   module_id: int,
 *   title: string,
 *   description: ?string,
 *   due_date: string,
 *   max_marks: float,
 *   status: string,
 *   file_path: ?string,
 *   activity_type?: string,
 *   scheduled_date?: ?string,
 *   start_time?: ?string,
 *   end_time?: ?string,
 *   room?: ?string
 * } $data
 */
function update_coursework_assignment(int $assignmentId, int $lecturerId, array $data): void
{
    $existing = get_coursework_assignment($assignmentId);
    if ($existing === null || !lecturer_can_manage_coursework_assignment($lecturerId, $existing)) {
        throw new InvalidArgumentException('Assignment not found.');
    }
    if (!lecturer_is_assigned_to_module($lecturerId, $data['module_id'])) {
        throw new InvalidArgumentException('You can only assign work to modules you teach.');
    }

    $data = assignment_normalize_payload($data, $existing);
    assignment_assert_type_change_safety($existing, $data);
    assignment_assert_payload($data);

    $statement = db()->prepare(
        'UPDATE assignments
         SET module_id = :module_id,
             title = :title,
             description = :description,
             file_path = :file_path,
             activity_type = :activity_type,
             due_date = :due_date,
             scheduled_date = :scheduled_date,
             start_time = :start_time,
             end_time = :end_time,
             room = :room,
             max_marks = :max_marks,
             status = :status
         WHERE assignment_id = :assignment_id AND lecturer_id = :lecturer_id'
    );
    $statement->execute([
        'module_id' => $data['module_id'],
        'title' => $data['title'],
        'description' => $data['description'],
        'file_path' => $data['file_path'],
        'activity_type' => $data['activity_type'],
        'due_date' => $data['due_date'],
        'scheduled_date' => $data['scheduled_date'],
        'start_time' => $data['start_time'],
        'end_time' => $data['end_time'],
        'room' => $data['room'],
        'max_marks' => $data['max_marks'],
        'status' => $data['status'],
        'assignment_id' => $assignmentId,
        'lecturer_id' => $lecturerId,
    ]);
}

/**
 * @param array<string, mixed> $data
 * @param array<string, mixed>|null $existing
 * @return array<string, mixed>
 */
function assignment_normalize_payload(array $data, ?array $existing = null): array
{
    $activityType = normalize_assignment_activity_type(
        $data['activity_type'] ?? ($existing['activity_type'] ?? 'ASSIGNMENT')
    );
    $requiresSchedule = assignment_requires_schedule($activityType);

    $dateRaw = array_key_exists('scheduled_date', $data)
        ? $data['scheduled_date']
        : ($existing['scheduled_date'] ?? null);
    $startRaw = array_key_exists('start_time', $data)
        ? $data['start_time']
        : ($existing['start_time'] ?? null);
    $endRaw = array_key_exists('end_time', $data)
        ? $data['end_time']
        : ($existing['end_time'] ?? null);

    if (!$requiresSchedule) {
        $dateRaw = null;
        $startRaw = null;
        $endRaw = null;
    }

    $schedule = normalize_assignment_schedule($dateRaw, $startRaw, $endRaw, $requiresSchedule);

    $data['activity_type'] = $activityType;
    $data['scheduled_date'] = $schedule['date'];
    $data['start_time'] = $schedule['start'];
    $data['end_time'] = $schedule['end'];
    $data['room'] = array_key_exists('room', $data)
        ? normalize_assignment_room($data['room'])
        : ($requiresSchedule ? normalize_assignment_room($existing['room'] ?? null) : null);

    if (!array_key_exists('file_path', $data)) {
        $data['file_path'] = $existing['file_path'] ?? null;
    }

    return $data;
}

/**
 * @param array<string, mixed> $existing
 * @param array<string, mixed> $data
 */
function assignment_assert_type_change_safety(array $existing, array $data): void
{
    $assignmentId = (int) $existing['assignment_id'];
    $oldType = strtoupper(trim((string) ($existing['activity_type'] ?? 'ASSIGNMENT')));
    $newType = (string) $data['activity_type'];
    $oldModule = (int) $existing['module_id'];
    $newModule = (int) $data['module_id'];
    $newMax = (float) $data['max_marks'];

    $subStmt = db()->prepare('SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = :id');
    $subStmt->execute(['id' => $assignmentId]);
    $submissionCount = (int) $subStmt->fetchColumn();

    $resultCount = 0;
    if (assignment_results_table_exists()) {
        $resStmt = db()->prepare('SELECT COUNT(*) FROM assignment_results WHERE assignment_id = :id');
        $resStmt->execute(['id' => $assignmentId]);
        $resultCount = (int) $resStmt->fetchColumn();
    }

    if ($oldType !== $newType) {
        if ($submissionCount > 0 && assignment_requires_submission($oldType) && !assignment_requires_submission($newType)) {
            throw new InvalidArgumentException('Cannot change activity type after student submissions exist.');
        }
        if ($resultCount > 0 && !assignment_requires_submission($oldType) && assignment_requires_submission($newType)) {
            throw new InvalidArgumentException('Cannot change activity type after direct results exist.');
        }
    }

    if ($oldModule !== $newModule && ($submissionCount > 0 || $resultCount > 0)) {
        throw new InvalidArgumentException('Cannot change module after submissions or results exist.');
    }

    $highestGrade = db()->prepare(
        'SELECT MAX(grade) FROM assignment_submissions WHERE assignment_id = :id AND grade IS NOT NULL'
    );
    $highestGrade->execute(['id' => $assignmentId]);
    $gradeMax = $highestGrade->fetchColumn();
    if ($gradeMax !== false && $gradeMax !== null && (float) $gradeMax > $newMax) {
        throw new InvalidArgumentException('Max marks cannot be lower than an existing coursework grade.');
    }

    if (assignment_results_table_exists()) {
        $highestResult = db()->prepare(
            'SELECT MAX(marks_obtained) FROM assignment_results WHERE assignment_id = :id'
        );
        $highestResult->execute(['id' => $assignmentId]);
        $resultMax = $highestResult->fetchColumn();
        if ($resultMax !== false && $resultMax !== null && (float) $resultMax > $newMax) {
            throw new InvalidArgumentException('Max marks cannot be lower than an existing recorded result.');
        }
    }
}

/**
 * @param array<string, mixed> $data
 */
function assignment_assert_payload(array $data): void
{
    if (get_module($data['module_id']) === null) {
        throw new InvalidArgumentException('Select a valid module.');
    }
    if ($data['title'] === '') {
        throw new InvalidArgumentException('Title is required.');
    }
    if (mb_strlen($data['title']) > 200) {
        throw new InvalidArgumentException('Title must be 200 characters or fewer.');
    }
    if (parse_app_datetime($data['due_date']) === null) {
        throw new InvalidArgumentException('Enter a valid due date and time.');
    }
    if ($data['max_marks'] <= 0) {
        throw new InvalidArgumentException('Max marks must be greater than 0.');
    }
    if (!in_array($data['status'], assignment_statuses(), true)) {
        throw new InvalidArgumentException('Select a valid status.');
    }

    $activityType = normalize_assignment_activity_type($data['activity_type'] ?? 'ASSIGNMENT');
    $requiresSchedule = assignment_requires_schedule($activityType);
    // Re-validate schedule consistency (normalize already ran for create/update)
    normalize_assignment_schedule(
        $data['scheduled_date'] ?? null,
        $data['start_time'] ?? null,
        $data['end_time'] ?? null,
        $requiresSchedule
    );
}

/**
 * First submit = INSERT, replacement = UPDATE the unique (assignment_id, student_id) row.
 *
 * @return array{submission_id: int, replaced: bool}
 */
function save_student_assignment_submission(int $assignmentId, int $studentId, array $file): array
{
    $assignment = get_coursework_assignment($assignmentId);
    if ($assignment === null || !student_can_view_coursework_assignment($studentId, $assignment)) {
        throw new InvalidArgumentException('You cannot submit this assignment.');
    }

    if (!assignment_requires_submission($assignment)) {
        throw new InvalidArgumentException('This activity does not accept student file submissions.');
    }

    $existing = get_assignment_submission_for_student($assignmentId, $studentId);
    if (assignment_submission_is_graded($existing)) {
        throw new InvalidArgumentException('Graded submissions cannot be replaced.');
    }

    if (!assignment_is_open_for_submission($assignment)) {
        throw new InvalidArgumentException('You cannot submit this assignment.');
    }

    $stored = assignment_store_uploaded_file($file, 'submissions');
    $now = app_now_datetime();
    $status = parse_app_datetime($now) > parse_app_datetime((string) $assignment['due_date'])
        ? 'LATE'
        : 'SUBMITTED';

    $pdo = db();
    try {
        $pdo->beginTransaction();
        if ($existing === null) {
            $statement = $pdo->prepare(
                'INSERT INTO assignment_submissions (assignment_id, student_id, file_path, submitted_at, status)
                 VALUES (:assignment_id, :student_id, :file_path, :submitted_at, :status)'
            );
            $statement->execute([
                'assignment_id' => $assignmentId,
                'student_id' => $studentId,
                'file_path' => $stored['relative_path'],
                'submitted_at' => $now,
                'status' => $status,
            ]);
            $submissionId = (int) $pdo->lastInsertId();
            $replaced = false;
            $oldPath = null;
        } else {
            $statement = $pdo->prepare(
                'UPDATE assignment_submissions
                 SET file_path = :file_path, submitted_at = :submitted_at, status = :status
                 WHERE submission_id = :submission_id
                   AND assignment_id = :assignment_id
                   AND student_id = :student_id'
            );
            $statement->execute([
                'file_path' => $stored['relative_path'],
                'submitted_at' => $now,
                'status' => $status,
                'submission_id' => (int) $existing['submission_id'],
                'assignment_id' => $assignmentId,
                'student_id' => $studentId,
            ]);
            $submissionId = (int) $existing['submission_id'];
            $replaced = true;
            $oldPath = (string) $existing['file_path'];
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        assignment_delete_stored_file($stored['relative_path']);
        throw $exception;
    }

    if ($replaced && $oldPath !== null && $oldPath !== $stored['relative_path']) {
        assignment_delete_stored_file($oldPath);
    }

    return ['submission_id' => $submissionId, 'replaced' => $replaced];
}

/**
 * @return array{absolute_path: string, download_name: string, mime: string}|null
 */
function assignment_authorized_download(string $kind, int $id, string $role, ?int $lecturerId, ?int $studentId): ?array
{
    if (!in_array($kind, ['brief', 'submission'], true)) {
        return null;
    }

    if ($kind === 'brief') {
        $assignment = get_coursework_assignment($id);
        if ($assignment === null || empty($assignment['file_path'])) {
            return null;
        }
        if (!assignment_actor_can_download_brief($assignment, $role, $lecturerId, $studentId)) {
            return null;
        }
        $relative = (string) $assignment['file_path'];
        $ext = assignment_extension_from_name($relative);
        $downloadName = 'assignment-' . (int) $assignment['assignment_id'] . '-brief.' . ($ext !== '' ? $ext : 'bin');
    } else {
        $submission = get_assignment_submission($id);
        if ($submission === null) {
            return null;
        }
        $assignment = get_coursework_assignment((int) $submission['assignment_id']);
        if ($assignment === null) {
            return null;
        }
        if (!assignment_actor_can_download_submission($assignment, $submission, $role, $lecturerId, $studentId)) {
            return null;
        }
        $relative = (string) $submission['file_path'];
        $ext = assignment_extension_from_name($relative);
        $downloadName = 'submission-' . (int) $submission['submission_id'] . '.' . ($ext !== '' ? $ext : 'bin');
    }

    $absolute = assignment_resolve_stored_path($relative);
    if ($absolute === null) {
        return null;
    }

    $ext = assignment_extension_from_name($absolute);
    $mime = match ($ext) {
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'zip' => 'application/zip',
        default => 'application/octet-stream',
    };

    return [
        'absolute_path' => $absolute,
        'download_name' => $downloadName,
        'mime' => $mime,
    ];
}

function assignment_actor_can_download_brief(array $assignment, string $role, ?int $lecturerId, ?int $studentId): bool
{
    if (in_array($role, ['ADMIN', 'ACADEMIC_STAFF'], true)) {
        return true;
    }
    if ($role === 'LECTURER' && $lecturerId !== null) {
        return lecturer_can_manage_coursework_assignment($lecturerId, $assignment);
    }
    if ($role === 'STUDENT' && $studentId !== null) {
        return student_can_view_coursework_assignment($studentId, $assignment);
    }

    return false;
}

function assignment_actor_can_download_submission(array $assignment, array $submission, string $role, ?int $lecturerId, ?int $studentId): bool
{
    if (in_array($role, ['ADMIN', 'ACADEMIC_STAFF'], true)) {
        return true;
    }
    if ($role === 'LECTURER' && $lecturerId !== null) {
        return lecturer_can_manage_coursework_assignment($lecturerId, $assignment);
    }
    if ($role === 'STUDENT' && $studentId !== null) {
        return (int) $submission['student_id'] === $studentId
            && student_can_view_coursework_assignment($studentId, $assignment);
    }

    return false;
}

function assignment_send_download(array $payload): never
{
    $path = $payload['absolute_path'];
    $name = str_replace(['"', "\r", "\n"], '', (string) $payload['download_name']);

    header('Content-Type: ' . $payload['mime']);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

function assignment_download_url(string $routePrefix, string $kind, int $id): string
{
    return app_url($routePrefix . '/assignments/download.php?type=' . rawurlencode($kind) . '&id=' . $id);
}

function assignment_grade_url(string $routePrefix, int $submissionId): string
{
    return app_url($routePrefix . '/assignments/grade.php?submission_id=' . $submissionId);
}

/**
 * Grade an existing submission. Updates the same row. Does not write to marks.
 *
 * @return array<string, mixed>
 */
function grade_coursework_submission(int $lecturerId, int $submissionId, mixed $rawGrade, mixed $rawFeedback): array
{
    $submission = get_assignment_submission($submissionId);
    if ($submission === null) {
        throw new InvalidArgumentException('Submission not found.');
    }

    $assignment = get_coursework_assignment((int) $submission['assignment_id']);
    if ($assignment === null || !lecturer_can_grade_submission($lecturerId, $assignment, $submission)) {
        throw new InvalidArgumentException('You cannot grade this submission.');
    }

    $grade = parse_coursework_grade($rawGrade, (float) $assignment['max_marks']);
    $feedback = is_string($rawFeedback) ? trim($rawFeedback) : '';
    $feedback = $feedback === '' ? null : $feedback;

    $statement = db()->prepare(
        "UPDATE assignment_submissions
         SET grade = :grade, feedback = :feedback, status = 'GRADED'
         WHERE submission_id = :submission_id AND assignment_id = :assignment_id"
    );
    $statement->execute([
        'grade' => $grade,
        'feedback' => $feedback,
        'submission_id' => $submissionId,
        'assignment_id' => (int) $assignment['assignment_id'],
    ]);

    $updated = get_assignment_submission($submissionId);
    if ($updated === null) {
        throw new RuntimeException('Unable to save the grade.');
    }

    return $updated;
}

function normalize_assignment_result_remarks(mixed $raw): ?string
{
    $text = is_string($raw) ? trim($raw) : '';
    if ($text === '') {
        return null;
    }

    return $text;
}

/**
 * @return array<string, mixed>|null
 */
function get_assignment_direct_result(int $assignmentId, int $studentId): ?array
{
    if (!assignment_results_table_exists()) {
        return null;
    }
    $statement = db()->prepare(
        'SELECT * FROM assignment_results
         WHERE assignment_id = :assignment_id AND student_id = :student_id
         LIMIT 1'
    );
    $statement->execute([
        'assignment_id' => $assignmentId,
        'student_id' => $studentId,
    ]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * Enrolled roster with optional direct result (Exam/Practical).
 *
 * @return list<array<string, mixed>>
 */
function list_assignment_direct_results_roster(int $assignmentId): array
{
    $assignment = get_coursework_assignment($assignmentId);
    if ($assignment === null) {
        throw new InvalidArgumentException('Assignment not found.');
    }
    if (assignment_requires_submission($assignment)) {
        throw new InvalidArgumentException('Direct results are only available for Exam and Practical activities.');
    }

    $students = list_enrolled_students_for_module((int) $assignment['module_id']);
    $rows = [];
    foreach ($students as $student) {
        $studentId = (int) $student['student_id'];
        $existing = get_assignment_direct_result($assignmentId, $studentId);
        $rows[] = [
            'student_id' => $studentId,
            'registration_no' => $student['registration_no'],
            'first_name' => $student['first_name'],
            'last_name' => $student['last_name'],
            'result_id' => $existing['result_id'] ?? null,
            'marks_obtained' => $existing['marks_obtained'] ?? null,
            'max_marks' => (float) $assignment['max_marks'],
            'remarks' => $existing['remarks'] ?? null,
            'recorded_at' => $existing['recorded_at'] ?? null,
            'status' => $existing === null ? 'Not Recorded' : 'Recorded',
            'percentage' => $existing === null
                ? null
                : marks_percentage((float) $existing['marks_obtained'], (float) $assignment['max_marks']),
        ];
    }

    return $rows;
}

/**
 * @return array{recorded: int, not_recorded: int, enrolled: int}
 */
function count_assignment_direct_result_coverage(int $assignmentId): array
{
    $roster = list_assignment_direct_results_roster($assignmentId);
    $recorded = 0;
    foreach ($roster as $row) {
        if ($row['status'] === 'Recorded') {
            $recorded++;
        }
    }

    return [
        'recorded' => $recorded,
        'not_recorded' => count($roster) - $recorded,
        'enrolled' => count($roster),
    ];
}

/**
 * @return 'inserted'|'updated'
 */
function save_assignment_direct_result(
    int $lecturerId,
    int $assignmentId,
    int $studentId,
    mixed $rawMarks,
    mixed $rawRemarks = null
): string {
    $assignment = get_coursework_assignment($assignmentId);
    if ($assignment === null) {
        throw new InvalidArgumentException('Assignment not found.');
    }
    if (!lecturer_can_manage_coursework_assignment($lecturerId, $assignment)) {
        throw new InvalidArgumentException('You cannot record results for this activity.');
    }
    if (assignment_requires_submission($assignment)) {
        throw new InvalidArgumentException('Direct results are only allowed for Exam and Practical activities.');
    }
    if (!student_is_enrolled_in_module($studentId, (int) $assignment['module_id'])) {
        throw new InvalidArgumentException('Results can only be recorded for students enrolled in this module.');
    }

    $obtained = parse_coursework_grade($rawMarks, (float) $assignment['max_marks']);
    $remarks = normalize_assignment_result_remarks($rawRemarks);
    $now = app_now_datetime();
    $existing = get_assignment_direct_result($assignmentId, $studentId);

    if ($existing === null) {
        $insert = db()->prepare(
            'INSERT INTO assignment_results (
                assignment_id, student_id, marks_obtained, remarks, recorded_by, recorded_at
             ) VALUES (
                :assignment_id, :student_id, :marks_obtained, :remarks, :recorded_by, :recorded_at
             )'
        );
        $insert->execute([
            'assignment_id' => $assignmentId,
            'student_id' => $studentId,
            'marks_obtained' => $obtained,
            'remarks' => $remarks,
            'recorded_by' => $lecturerId,
            'recorded_at' => $now,
        ]);

        return 'inserted';
    }

    $update = db()->prepare(
        'UPDATE assignment_results
         SET marks_obtained = :marks_obtained,
             remarks = :remarks,
             recorded_by = :recorded_by,
             recorded_at = :recorded_at
         WHERE result_id = :result_id
           AND assignment_id = :assignment_id
           AND student_id = :student_id'
    );
    $update->execute([
        'marks_obtained' => $obtained,
        'remarks' => $remarks,
        'recorded_by' => $lecturerId,
        'recorded_at' => $now,
        'result_id' => (int) $existing['result_id'],
        'assignment_id' => $assignmentId,
        'student_id' => $studentId,
    ]);

    return 'updated';
}

/**
 * Recorded Exam/Practical results for one student (self-scoped helper).
 *
 * @return list<array<string, mixed>>
 */
function list_direct_assignment_results_for_student(int $studentId): array
{
    if (!assignment_results_table_exists()) {
        return [];
    }

    $statement = db()->prepare(
        "SELECT a.assignment_id, a.title, a.activity_type, a.max_marks, a.module_id, a.status AS assignment_status,
                m.module_code, m.module_name,
                ar.result_id, ar.student_id, ar.marks_obtained, ar.remarks, ar.recorded_at
         FROM assignment_results ar
         INNER JOIN assignments a ON a.assignment_id = ar.assignment_id
         INNER JOIN modules m ON m.module_id = a.module_id
         INNER JOIN student_modules sm
            ON sm.module_id = a.module_id AND sm.student_id = :student_id AND sm.status = 'ENROLLED'
         WHERE ar.student_id = :student_id2
           AND a.activity_type IN ('EXAM', 'PRACTICAL')
           AND a.status <> 'DRAFT'
         ORDER BY m.module_code, a.title"
    );
    $statement->execute([
        'student_id' => $studentId,
        'student_id2' => $studentId,
    ]);
    $rows = $statement->fetchAll();
    foreach ($rows as &$row) {
        $row['percentage'] = marks_percentage((float) $row['marks_obtained'], (float) $row['max_marks']);
        $row['grade_display'] = format_assignment_grade_display($row['marks_obtained'], $row['max_marks']);
    }
    unset($row);

    return $rows;
}

/**
 * Calendar-ready PRESENTATION / EXAM / PRACTICAL for modules a lecturer teaches.
 * ASSIGNMENT is never returned. Does not create lecture_sessions.
 *
 * @return list<array<string, mixed>>
 */
function list_calendar_coursework_for_lecturer(int $lecturerId, string $fromDate, string $toDate): array
{
    if (!validate_date_ymd($fromDate) || !validate_date_ymd($toDate)) {
        throw new InvalidArgumentException('Enter a valid date range.');
    }

    $statement = db()->prepare(
        assignment_select_sql() . "
         INNER JOIN module_lecturers ml
            ON ml.module_id = a.module_id AND ml.lecturer_id = :lecturer_id
         WHERE a.activity_type IN ('PRESENTATION', 'EXAM', 'PRACTICAL')
           AND a.scheduled_date IS NOT NULL
           AND a.start_time IS NOT NULL
           AND a.end_time IS NOT NULL
           AND a.status <> 'DRAFT'
           AND a.scheduled_date BETWEEN :from_date AND :to_date
         ORDER BY a.scheduled_date, a.start_time, m.module_code, a.title"
    );
    $statement->execute([
        'lecturer_id' => $lecturerId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ]);

    return $statement->fetchAll();
}

/**
 * Calendar-ready PRESENTATION / EXAM / PRACTICAL for ENROLLED students.
 * ASSIGNMENT is never returned. Does not create lecture_sessions.
 *
 * @return list<array<string, mixed>>
 */
function list_calendar_coursework_for_student(int $studentId, string $fromDate, string $toDate): array
{
    if (!validate_date_ymd($fromDate) || !validate_date_ymd($toDate)) {
        throw new InvalidArgumentException('Enter a valid date range.');
    }

    $statement = db()->prepare(
        assignment_select_sql() . "
         INNER JOIN student_modules sm
            ON sm.module_id = a.module_id
           AND sm.student_id = :student_id
           AND sm.status = 'ENROLLED'
         WHERE a.activity_type IN ('PRESENTATION', 'EXAM', 'PRACTICAL')
           AND a.scheduled_date IS NOT NULL
           AND a.start_time IS NOT NULL
           AND a.end_time IS NOT NULL
           AND a.status <> 'DRAFT'
           AND a.scheduled_date BETWEEN :from_date AND :to_date
         ORDER BY a.scheduled_date, a.start_time, m.module_code, a.title"
    );
    $statement->execute([
        'student_id' => $studentId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ]);

    return $statement->fetchAll();
}

/**
 * Calendar-ready PRESENTATION / EXAM / PRACTICAL for institution calendars.
 * Read-only. ASSIGNMENT never returned. Does not create lecture_sessions.
 *
 * Optional filters: lecturer_id (activity owner), module_id, course_id (via course_modules).
 * Lecture session status / batch filters are intentionally not applied here.
 *
 * @param array{lecturer_id?: int|null, module_id?: int|null, course_id?: int|null} $filters
 * @return list<array<string, mixed>>
 */
function list_calendar_coursework_for_institution(string $fromDate, string $toDate, array $filters = []): array
{
    if (!validate_date_ymd($fromDate) || !validate_date_ymd($toDate)) {
        throw new InvalidArgumentException('Enter a valid date range.');
    }

    $sql = assignment_select_sql() . "
         WHERE a.activity_type IN ('PRESENTATION', 'EXAM', 'PRACTICAL')
           AND a.scheduled_date IS NOT NULL
           AND a.start_time IS NOT NULL
           AND a.end_time IS NOT NULL
           AND a.status <> 'DRAFT'
           AND a.scheduled_date BETWEEN :from_date AND :to_date";
    $params = [
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ];

    if (!empty($filters['lecturer_id'])) {
        $sql .= ' AND a.lecturer_id = :lecturer_id';
        $params['lecturer_id'] = (int) $filters['lecturer_id'];
    }
    if (!empty($filters['module_id'])) {
        $sql .= ' AND a.module_id = :module_id';
        $params['module_id'] = (int) $filters['module_id'];
    }
    if (!empty($filters['course_id'])) {
        $sql .= " AND EXISTS (
            SELECT 1 FROM course_modules cm
            WHERE cm.module_id = a.module_id
              AND cm.course_id = :course_id
              AND cm.status = 'ACTIVE'
         )";
        $params['course_id'] = (int) $filters['course_id'];
    }

    $sql .= ' ORDER BY a.scheduled_date, a.start_time, m.module_code, a.title';
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * Unified read-only My Results for the signed-in student.
 * ASSIGNMENT/PRESENTATION → graded assignment_submissions
 * EXAM/PRACTICAL → assignment_results
 * Does not include legacy marks table rows.
 *
 * @return list<array<string, mixed>>
 */
function list_unified_student_results(int $studentId): array
{
    $rows = [];

    foreach (list_graded_coursework_results_for_student($studentId) as $row) {
        if ((int) ($row['student_id'] ?? 0) !== $studentId) {
            continue;
        }
        $assignment = get_coursework_assignment((int) $row['assignment_id']);
        $type = strtoupper(trim((string) ($assignment['activity_type'] ?? 'ASSIGNMENT')));
        $rows[] = [
            'source' => 'submission',
            'assignment_id' => (int) $row['assignment_id'],
            'module_id' => (int) $row['module_id'],
            'module_code' => $row['module_code'],
            'module_name' => $row['module_name'],
            'title' => $row['title'],
            'activity_type' => $type,
            'activity_label' => assignment_activity_type_label($type),
            'result_display' => $row['grade_display'],
            'max_marks' => $row['max_marks'],
            'marks_obtained' => $row['grade'],
            'percentage' => $row['percentage'],
            'feedback_or_remarks' => $row['feedback'] ?? null,
            'sort_key' => (string) $row['module_code'] . '|' . (string) $row['title'],
        ];
    }

    foreach (list_direct_assignment_results_for_student($studentId) as $row) {
        if ((int) ($row['student_id'] ?? 0) !== $studentId) {
            continue;
        }
        $rows[] = [
            'source' => 'direct_result',
            'assignment_id' => (int) $row['assignment_id'],
            'module_id' => (int) $row['module_id'],
            'module_code' => $row['module_code'],
            'module_name' => $row['module_name'],
            'title' => $row['title'],
            'activity_type' => strtoupper(trim((string) $row['activity_type'])),
            'activity_label' => assignment_activity_type_label((string) $row['activity_type']),
            'result_display' => $row['grade_display'],
            'max_marks' => $row['max_marks'],
            'marks_obtained' => $row['marks_obtained'],
            'percentage' => $row['percentage'],
            'feedback_or_remarks' => $row['remarks'] ?? null,
            'sort_key' => (string) $row['module_code'] . '|' . (string) $row['title'],
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp($a['sort_key'], $b['sort_key']);
    });

    return $rows;
}

