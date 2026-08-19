<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Coursework assignments (not lecturer-to-module teaching assignment).
 *
 * Due-date rule (v1):
 * Students may submit or replace a file only while the assignment status is
 * PUBLISHED and app_now() is on or before due_date (APP_TIMEZONE).
 * After the due date, new submissions and replacements are blocked.
 * Existing rows with submitted_at > due_date are shown as LATE.
 */

function assignment_statuses(): array
{
    return ['DRAFT', 'PUBLISHED', 'CLOSED'];
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

function student_coursework_display_status(array $assignment, ?array $submission): string
{
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
                   a.due_date, a.max_marks, a.status, a.created_at, a.updated_at,
                   m.module_code, m.module_name, m.status AS module_status,
                   c.course_code,
                   l.staff_no, l.first_name AS lecturer_first_name, l.last_name AS lecturer_last_name
            FROM assignments a
            INNER JOIN modules m ON m.module_id = a.module_id
            INNER JOIN courses c ON c.course_id = m.course_id
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
        $submission = get_assignment_submission_for_student((int) $row['assignment_id'], $studentId);
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
 * @param array{module_id: int, title: string, description: ?string, due_date: string, max_marks: float, status: string, file_path: ?string} $data
 */
function create_coursework_assignment(int $lecturerId, array $data): int
{
    if (!lecturer_is_assigned_to_module($lecturerId, $data['module_id'])) {
        throw new InvalidArgumentException('You can only create assignments for modules you teach.');
    }

    assignment_assert_payload($data);

    $statement = db()->prepare(
        'INSERT INTO assignments (module_id, lecturer_id, title, description, file_path, due_date, max_marks, status)
         VALUES (:module_id, :lecturer_id, :title, :description, :file_path, :due_date, :max_marks, :status)'
    );
    $statement->execute([
        'module_id' => $data['module_id'],
        'lecturer_id' => $lecturerId,
        'title' => $data['title'],
        'description' => $data['description'],
        'file_path' => $data['file_path'],
        'due_date' => $data['due_date'],
        'max_marks' => $data['max_marks'],
        'status' => $data['status'],
    ]);

    return (int) db()->lastInsertId();
}

/**
 * @param array{module_id: int, title: string, description: ?string, due_date: string, max_marks: float, status: string, file_path: ?string} $data
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

    assignment_assert_payload($data);

    $statement = db()->prepare(
        'UPDATE assignments
         SET module_id = :module_id,
             title = :title,
             description = :description,
             file_path = :file_path,
             due_date = :due_date,
             max_marks = :max_marks,
             status = :status
         WHERE assignment_id = :assignment_id AND lecturer_id = :lecturer_id'
    );
    $statement->execute([
        'module_id' => $data['module_id'],
        'title' => $data['title'],
        'description' => $data['description'],
        'file_path' => $data['file_path'],
        'due_date' => $data['due_date'],
        'max_marks' => $data['max_marks'],
        'status' => $data['status'],
        'assignment_id' => $assignmentId,
        'lecturer_id' => $lecturerId,
    ]);
}

/**
 * @param array{module_id: int, title: string, description: ?string, due_date: string, max_marks: float, status: string} $data
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
