<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Module assessment results ledger (`marks`).
 *
 * Separate from coursework assignment_submissions grades.
 *
 * Duplicate policy (application-level, no schema unique key):
 * Identity = student_id + module_id + assessment_type + assessment_name + recorded_by
 * Matching row is UPDATED; otherwise INSERT.
 */

function marks_assessment_types(): array
{
    return ['Quiz', 'Midterm', 'Exam', 'Practical', 'Presentation', 'Other'];
}

function lecturer_can_manage_module_marks(int $lecturerId, int $moduleId): bool
{
    return lecturer_is_assigned_to_module($lecturerId, $moduleId);
}

function staff_can_monitor_marks(): bool
{
    return can_manage_academic();
}

function marks_percentage(float $obtained, float $maxMarks): ?float
{
    if ($maxMarks <= 0) {
        return null;
    }

    return round(($obtained / $maxMarks) * 100, 1);
}

function format_marks_percentage(?float $percent): string
{
    if ($percent === null) {
        return '—';
    }

    return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.') . '%';
}

function format_marks_pair(mixed $obtained, mixed $maxMarks): string
{
    return rtrim(rtrim(number_format((float) $obtained, 2, '.', ''), '0'), '.')
        . ' / '
        . rtrim(rtrim(number_format((float) $maxMarks, 2, '.', ''), '0'), '.');
}

function parse_marks_max(mixed $raw): float
{
    if (is_int($raw) || is_float($raw)) {
        $text = (string) $raw;
    } elseif (is_string($raw)) {
        $text = trim($raw);
    } else {
        throw new InvalidArgumentException('Maximum marks must be a number greater than 0.');
    }

    if ($text === '' || preg_match('/^\d+(\.\d+)?$/', $text) !== 1) {
        throw new InvalidArgumentException('Maximum marks must be a number greater than 0.');
    }

    $value = (float) $text;
    if ($value <= 0) {
        throw new InvalidArgumentException('Maximum marks must be greater than 0.');
    }

    return round($value, 2);
}

/**
 * Empty string means not recorded (skip). Otherwise a numeric mark.
 *
 * @throws InvalidArgumentException
 */
function parse_marks_obtained(mixed $raw, float $maxMarks, string $context = 'Mark'): ?float
{
    if ($raw === null) {
        return null;
    }
    if (is_int($raw) || is_float($raw)) {
        $text = (string) $raw;
    } elseif (is_string($raw)) {
        $text = trim($raw);
    } else {
        throw new InvalidArgumentException($context . ' must be a number.');
    }

    if ($text === '') {
        return null;
    }
    if (preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
        throw new InvalidArgumentException($context . ' must be a number.');
    }

    $value = (float) $text;
    if ($value < 0) {
        throw new InvalidArgumentException($context . ' cannot be negative.');
    }
    if ($value > $maxMarks) {
        throw new InvalidArgumentException($context . ' cannot be greater than the maximum marks.');
    }

    return round($value, 2);
}

function normalize_assessment_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Assessment name is required.');
    }
    if (mb_strlen($name) > 150) {
        throw new InvalidArgumentException('Assessment name must be 150 characters or fewer.');
    }

    return $name;
}

function normalize_assessment_type(string $type): string
{
    $type = trim($type);
    if (!in_array($type, marks_assessment_types(), true)) {
        throw new InvalidArgumentException('Select a valid assessment type.');
    }

    return $type;
}

function normalize_mark_remarks(mixed $raw): ?string
{
    $text = is_string($raw) ? trim($raw) : '';
    if ($text === '') {
        return null;
    }
    if (mb_strlen($text) > 255) {
        throw new InvalidArgumentException('Remarks must be 255 characters or fewer.');
    }

    return $text;
}

/**
 * @return array<string, mixed>|null
 */
function find_existing_mark_row(int $studentId, int $moduleId, string $assessmentType, string $assessmentName, int $recordedBy): ?array
{
    $statement = db()->prepare(
        'SELECT mark_id, student_id, module_id, assessment_type, assessment_name,
                marks_obtained, max_marks, recorded_by, recorded_at, remarks
         FROM marks
         WHERE student_id = :student_id
           AND module_id = :module_id
           AND assessment_type = :assessment_type
           AND assessment_name = :assessment_name
           AND recorded_by = :recorded_by
         ORDER BY mark_id
         LIMIT 1'
    );
    $statement->execute([
        'student_id' => $studentId,
        'module_id' => $moduleId,
        'assessment_type' => $assessmentType,
        'assessment_name' => $assessmentName,
        'recorded_by' => $recordedBy,
    ]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * @return array<string, mixed>|null
 */
function get_mark(int $markId): ?array
{
    $statement = db()->prepare(
        "SELECT mk.mark_id, mk.student_id, mk.module_id, mk.assessment_type, mk.assessment_name,
                mk.marks_obtained, mk.max_marks, mk.recorded_by, mk.recorded_at, mk.remarks,
                s.registration_no, s.first_name, s.last_name,
                m.module_code, m.module_name,
                l.first_name AS lecturer_first_name, l.last_name AS lecturer_last_name
         FROM marks mk
         INNER JOIN students s ON s.student_id = mk.student_id
         INNER JOIN modules m ON m.module_id = mk.module_id
         INNER JOIN lecturers l ON l.lecturer_id = mk.recorded_by
         WHERE mk.mark_id = :mark_id
         LIMIT 1"
    );
    $statement->execute(['mark_id' => $markId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * @return list<array<string, mixed>>
 */
function list_marks_assessments_for_lecturer(int $lecturerId, ?int $moduleId = null): array
{
    $sql = "SELECT mk.module_id, mk.assessment_type, mk.assessment_name, mk.max_marks, mk.recorded_by,
                   MAX(mk.recorded_at) AS last_recorded_at,
                   COUNT(*) AS recorded_count,
                   m.module_code, m.module_name
            FROM marks mk
            INNER JOIN modules m ON m.module_id = mk.module_id
            INNER JOIN module_lecturers ml
                ON ml.module_id = mk.module_id AND ml.lecturer_id = mk.recorded_by
            WHERE mk.recorded_by = :lecturer_id";
    $params = ['lecturer_id' => $lecturerId];

    if ($moduleId !== null) {
        $sql .= ' AND mk.module_id = :module_id';
        $params['module_id'] = $moduleId;
    }

    $sql .= ' GROUP BY mk.module_id, mk.assessment_type, mk.assessment_name, mk.max_marks, mk.recorded_by,
                       m.module_code, m.module_name
              ORDER BY m.module_code, mk.assessment_type, mk.assessment_name';

    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * @param array{module_id?: int, lecturer_id?: int, student_id?: int, assessment_type?: string} $filters
 * @return list<array<string, mixed>>
 */
function list_marks_assessments_for_monitor(array $filters = []): array
{
    $sql = "SELECT mk.module_id, mk.assessment_type, mk.assessment_name, mk.max_marks, mk.recorded_by,
                   MAX(mk.recorded_at) AS last_recorded_at,
                   COUNT(*) AS recorded_count,
                   m.module_code, m.module_name,
                   l.first_name AS lecturer_first_name, l.last_name AS lecturer_last_name, l.staff_no
            FROM marks mk
            INNER JOIN modules m ON m.module_id = mk.module_id
            INNER JOIN lecturers l ON l.lecturer_id = mk.recorded_by
            WHERE 1=1";
    $params = [];

    if (!empty($filters['module_id'])) {
        $sql .= ' AND mk.module_id = :module_id';
        $params['module_id'] = (int) $filters['module_id'];
    }
    if (!empty($filters['lecturer_id'])) {
        $sql .= ' AND mk.recorded_by = :lecturer_id';
        $params['lecturer_id'] = (int) $filters['lecturer_id'];
    }
    if (!empty($filters['student_id'])) {
        $sql .= ' AND mk.student_id = :student_id';
        $params['student_id'] = (int) $filters['student_id'];
    }
    if (!empty($filters['assessment_type']) && in_array($filters['assessment_type'], marks_assessment_types(), true)) {
        $sql .= ' AND mk.assessment_type = :assessment_type';
        $params['assessment_type'] = $filters['assessment_type'];
    }

    $sql .= ' GROUP BY mk.module_id, mk.assessment_type, mk.assessment_name, mk.max_marks, mk.recorded_by,
                       m.module_code, m.module_name, l.first_name, l.last_name, l.staff_no
              ORDER BY m.module_code, mk.assessment_type, mk.assessment_name, l.last_name';

    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function list_marks_for_student(int $studentId): array
{
    $statement = db()->prepare(
        "SELECT mk.mark_id, mk.student_id, mk.module_id, mk.assessment_type, mk.assessment_name,
                mk.marks_obtained, mk.max_marks, mk.recorded_by, mk.recorded_at, mk.remarks,
                m.module_code, m.module_name
         FROM marks mk
         INNER JOIN modules m ON m.module_id = mk.module_id
         WHERE mk.student_id = :student_id
         ORDER BY m.module_code, mk.assessment_type, mk.assessment_name, mk.recorded_at DESC"
    );
    $statement->execute(['student_id' => $studentId]);
    $rows = $statement->fetchAll();

    foreach ($rows as &$row) {
        $row['percentage'] = marks_percentage((float) $row['marks_obtained'], (float) $row['max_marks']);
    }
    unset($row);

    return $rows;
}

/**
 * @return array{count: int, average_percentage: ?float}
 */
function summarize_student_marks(int $studentId): array
{
    $rows = list_marks_for_student($studentId);
    if ($rows === []) {
        return ['count' => 0, 'average_percentage' => null];
    }

    $total = 0.0;
    foreach ($rows as $row) {
        $total += (float) $row['percentage'];
    }

    return [
        'count' => count($rows),
        'average_percentage' => round($total / count($rows), 1),
    ];
}

/**
 * Enrolled students with optional existing mark for this assessment identity.
 *
 * @return list<array<string, mixed>>
 */
function list_module_assessment_entry_rows(
    int $moduleId,
    string $assessmentType,
    string $assessmentName,
    int $recordedBy
): array {
    $students = list_enrolled_students_for_module($moduleId);
    $rows = [];

    foreach ($students as $student) {
        $existing = find_existing_mark_row(
            (int) $student['student_id'],
            $moduleId,
            $assessmentType,
            $assessmentName,
            $recordedBy
        );
        $obtained = $existing['marks_obtained'] ?? null;
        $max = $existing['max_marks'] ?? null;
        $rows[] = [
            'student_id' => (int) $student['student_id'],
            'registration_no' => $student['registration_no'],
            'first_name' => $student['first_name'],
            'last_name' => $student['last_name'],
            'mark_id' => $existing['mark_id'] ?? null,
            'marks_obtained' => $obtained,
            'max_marks' => $max,
            'remarks' => $existing['remarks'] ?? null,
            'recorded_at' => $existing['recorded_at'] ?? null,
            'percentage' => $obtained !== null && $max !== null
                ? marks_percentage((float) $obtained, (float) $max)
                : null,
            'status' => $existing === null ? 'Not Recorded' : 'Recorded',
        ];
    }

    return $rows;
}

/**
 * Save or update marks for enrolled students. Blank marks are left Not Recorded.
 *
 * @param array<int, array{marks: mixed, remarks?: mixed}> $entries keyed by student_id
 * @return array{inserted: int, updated: int}
 */
function save_module_assessment_results(
    int $lecturerId,
    int $moduleId,
    string $assessmentType,
    string $assessmentName,
    mixed $rawMaxMarks,
    array $entries
): array {
    if (!lecturer_can_manage_module_marks($lecturerId, $moduleId)) {
        throw new InvalidArgumentException('You can only record results for modules you teach.');
    }
    if (get_module($moduleId) === null) {
        throw new InvalidArgumentException('Select a valid module.');
    }

    $type = normalize_assessment_type($assessmentType);
    $name = normalize_assessment_name($assessmentName);
    $maxMarks = parse_marks_max($rawMaxMarks);

    $enrolled = list_enrolled_students_for_module($moduleId);
    $enrolledIds = [];
    foreach ($enrolled as $student) {
        $enrolledIds[(int) $student['student_id']] = true;
    }

    foreach ($entries as $studentId => $_entry) {
        if (!isset($enrolledIds[(int) $studentId])) {
            throw new InvalidArgumentException('Marks can only be recorded for students enrolled in this module.');
        }
    }

    $existingRows = db()->prepare(
        'SELECT mark_id, student_id, marks_obtained
         FROM marks
         WHERE module_id = :module_id
           AND assessment_type = :assessment_type
           AND assessment_name = :assessment_name
           AND recorded_by = :recorded_by'
    );
    $existingRows->execute([
        'module_id' => $moduleId,
        'assessment_type' => $type,
        'assessment_name' => $name,
        'recorded_by' => $lecturerId,
    ]);
    foreach ($existingRows->fetchAll() as $row) {
        if ((float) $row['marks_obtained'] > $maxMarks) {
            throw new InvalidArgumentException('Existing recorded marks are higher than the new maximum.');
        }
    }

    $parsed = [];
    foreach ($enrolled as $student) {
        $studentId = (int) $student['student_id'];
        if (!array_key_exists($studentId, $entries) && !array_key_exists((string) $studentId, $entries)) {
            continue;
        }
        $entry = $entries[$studentId] ?? $entries[(string) $studentId];
        $obtained = parse_marks_obtained($entry['marks'] ?? '', $maxMarks, 'Mark for ' . $student['registration_no']);
        $remarks = normalize_mark_remarks($entry['remarks'] ?? null);
        if ($obtained === null) {
            continue;
        }
        $parsed[$studentId] = [
            'marks' => $obtained,
            'remarks' => $remarks,
        ];
    }

    $inserted = 0;
    $updated = 0;
    $pdo = db();
    $now = app_now_datetime();

    try {
        $pdo->beginTransaction();

        $insert = $pdo->prepare(
            'INSERT INTO marks (student_id, module_id, assessment_type, assessment_name, marks_obtained, max_marks, recorded_by, recorded_at, remarks)
             VALUES (:student_id, :module_id, :assessment_type, :assessment_name, :marks_obtained, :max_marks, :recorded_by, :recorded_at, :remarks)'
        );
        $update = $pdo->prepare(
            'UPDATE marks
             SET marks_obtained = :marks_obtained,
                 max_marks = :max_marks,
                 remarks = :remarks,
                 recorded_at = :recorded_at
             WHERE mark_id = :mark_id
               AND student_id = :student_id
               AND recorded_by = :recorded_by'
        );

        foreach ($parsed as $studentId => $entry) {
            $existing = find_existing_mark_row($studentId, $moduleId, $type, $name, $lecturerId);
            if ($existing === null) {
                $insert->execute([
                    'student_id' => $studentId,
                    'module_id' => $moduleId,
                    'assessment_type' => $type,
                    'assessment_name' => $name,
                    'marks_obtained' => $entry['marks'],
                    'max_marks' => $maxMarks,
                    'recorded_by' => $lecturerId,
                    'recorded_at' => $now,
                    'remarks' => $entry['remarks'],
                ]);
                $inserted++;
            } else {
                $update->execute([
                    'marks_obtained' => $entry['marks'],
                    'max_marks' => $maxMarks,
                    'remarks' => $entry['remarks'],
                    'recorded_at' => $now,
                    'mark_id' => (int) $existing['mark_id'],
                    'student_id' => $studentId,
                    'recorded_by' => $lecturerId,
                ]);
                $updated++;
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return ['inserted' => $inserted, 'updated' => $updated];
}

function marks_entry_query(int $moduleId, string $type, string $name, ?int $recordedBy = null): string
{
    $query = 'module_id=' . $moduleId
        . '&assessment_type=' . rawurlencode($type)
        . '&assessment_name=' . rawurlencode($name);
    if ($recordedBy !== null) {
        $query .= '&recorded_by=' . $recordedBy;
    }

    return $query;
}
