<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @return list<string> */
function attendance_report_roles(): array
{
    return ['LECTURER', 'ACADEMIC_STAFF', 'ADMIN'];
}

function user_can_view_staff_attendance_reports(): bool
{
    $user = current_user();

    return $user !== null && in_array($user['role'], attendance_report_roles(), true);
}

/**
 * Lecturers are always locked to their own lecture_sessions.lecturer_id.
 * Query parameters cannot widen this.
 */
function attendance_report_forced_lecturer_id(): ?int
{
    $user = current_user();
    if ($user === null || $user['role'] !== 'LECTURER') {
        return null;
    }
    $lecturer = current_lecturer_profile();

    return $lecturer !== null ? (int) $lecturer['lecturer_id'] : 0;
}

function attendance_report_like(string $value): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function attendance_report_sanitize_filters(array $input, ?int $forcedLecturerId = null, ?int $forcedStudentId = null): array
{
    $moduleId = positive_int($input['module_id'] ?? null);
    $batchId = positive_int($input['batch_id'] ?? null);
    $sessionId = positive_int($input['session_id'] ?? null);
    $requestedLecturerId = positive_int($input['lecturer_id'] ?? null);
    $from = trim((string) ($input['from'] ?? ''));
    $to = trim((string) ($input['to'] ?? ''));
    $status = strtoupper(trim((string) ($input['status'] ?? '')));
    $leftEarly = strtolower(trim((string) ($input['left_early'] ?? '')));
    $search = trim((string) ($input['search'] ?? ''));
    if (strlen($search) > 80) {
        $search = substr($search, 0, 80);
    }

    $lecturerId = $forcedLecturerId;
    if ($forcedLecturerId === null && $requestedLecturerId !== null) {
        $lecturerId = $requestedLecturerId;
    }

    return [
        'module_id' => $moduleId,
        'batch_id' => $batchId,
        'session_id' => $sessionId,
        'lecturer_id' => $lecturerId,
        'student_id' => $forcedStudentId,
        'from' => validate_date_ymd($from) ? $from : null,
        'to' => validate_date_ymd($to) ? $to : null,
        'status' => in_array($status, ['PRESENT', 'LATE', 'ABSENT'], true) ? $status : null,
        'left_early' => in_array($leftEarly, ['yes', 'no', '1', '0'], true)
            ? (in_array($leftEarly, ['yes', '1'], true) ? 1 : 0)
            : null,
        'search' => $search !== '' ? $search : null,
    ];
}

/**
 * @param array<string, mixed> $filters
 * @return array{sql: string, params: array<string, mixed>}
 */
function attendance_report_where(array $filters): array
{
    $sql = " FROM attendance_records r
             INNER JOIN lecture_sessions ls ON ls.session_id = r.session_id
             INNER JOIN students s ON s.student_id = r.student_id
             INNER JOIN modules m ON m.module_id = ls.module_id
             INNER JOIN batches b ON b.batch_id = ls.batch_id
             INNER JOIN lecturers l ON l.lecturer_id = ls.lecturer_id
             WHERE ls.status = 'COMPLETED'";
    $params = [];

    if (isset($filters['lecturer_id']) && $filters['lecturer_id'] !== null) {
        $sql .= ' AND ls.lecturer_id = :lecturer_id';
        $params['lecturer_id'] = (int) $filters['lecturer_id'];
    }
    if (!empty($filters['student_id'])) {
        $sql .= ' AND r.student_id = :student_id';
        $params['student_id'] = (int) $filters['student_id'];
    }
    if (!empty($filters['module_id'])) {
        $sql .= ' AND ls.module_id = :module_id';
        $params['module_id'] = (int) $filters['module_id'];
    }
    if (!empty($filters['batch_id'])) {
        $sql .= ' AND ls.batch_id = :batch_id';
        $params['batch_id'] = (int) $filters['batch_id'];
    }
    if (!empty($filters['session_id'])) {
        $sql .= ' AND ls.session_id = :session_id';
        $params['session_id'] = (int) $filters['session_id'];
    }
    if (!empty($filters['from'])) {
        $sql .= ' AND ls.session_date >= :date_from';
        $params['date_from'] = $filters['from'];
    }
    if (!empty($filters['to'])) {
        $sql .= ' AND ls.session_date <= :date_to';
        $params['date_to'] = $filters['to'];
    }
    if (!empty($filters['status'])) {
        $sql .= ' AND r.status = :record_status';
        $params['record_status'] = $filters['status'];
    }
    if (isset($filters['left_early']) && $filters['left_early'] !== null) {
        $sql .= ' AND r.left_early = :left_early';
        $params['left_early'] = (int) $filters['left_early'];
    }
    if (!empty($filters['search'])) {
        $sql .= ' AND (s.registration_no LIKE :search ESCAPE \'\\\\\'
                    OR s.first_name LIKE :search ESCAPE \'\\\\\'
                    OR s.last_name LIKE :search ESCAPE \'\\\\\'
                    OR CONCAT(s.first_name, \' \', s.last_name) LIKE :search ESCAPE \'\\\\\')';
        $params['search'] = attendance_report_like((string) $filters['search']);
    }

    return ['sql' => $sql, 'params' => $params];
}

/**
 * @param array<string, mixed> $filters
 * @return list<array<string, mixed>>
 */
function list_attendance_report_rows(array $filters): array
{
    $where = attendance_report_where($filters);
    $sql = "SELECT r.attendance_id, r.student_id, r.session_id, r.first_entry, r.last_exit,
                   r.total_present_minutes, r.teaching_minutes, r.attendance_percent,
                   r.status, r.left_early, r.finalized_at,
                   s.registration_no, s.first_name, s.last_name,
                   ls.session_date, ls.scheduled_start, ls.scheduled_end, ls.status AS session_status,
                   ls.module_id, ls.lecturer_id, ls.batch_id,
                   m.module_code, m.module_name,
                   b.batch_name,
                   l.first_name AS lecturer_first_name, l.last_name AS lecturer_last_name"
        . $where['sql']
        . ' ORDER BY ls.session_date DESC, ls.scheduled_start DESC, m.module_code, s.last_name, s.first_name';
    $statement = db()->prepare($sql);
    $statement->execute($where['params']);

    return $statement->fetchAll();
}

/**
 * @param array<string, mixed> $filters
 * @return array{
 *   records: int,
 *   students: int,
 *   sessions: int,
 *   present: int,
 *   late: int,
 *   absent: int,
 *   left_early: int,
 *   average_percent: float
 * }
 */
function summarize_attendance_report(array $filters): array
{
    $where = attendance_report_where($filters);
    $sql = 'SELECT COUNT(*) AS records,
                   COUNT(DISTINCT r.student_id) AS students,
                   COUNT(DISTINCT r.session_id) AS sessions,
                   SUM(CASE WHEN r.status = \'PRESENT\' THEN 1 ELSE 0 END) AS present,
                   SUM(CASE WHEN r.status = \'LATE\' THEN 1 ELSE 0 END) AS late,
                   SUM(CASE WHEN r.status = \'ABSENT\' THEN 1 ELSE 0 END) AS absent,
                   SUM(CASE WHEN r.left_early = 1 THEN 1 ELSE 0 END) AS left_early,
                   AVG(r.attendance_percent) AS average_percent'
        . $where['sql'];
    $statement = db()->prepare($sql);
    $statement->execute($where['params']);
    $row = $statement->fetch() ?: [];

    $records = (int) ($row['records'] ?? 0);
    $average = $records > 0 ? (float) ($row['average_percent'] ?? 0) : 0.0;

    return [
        'records' => $records,
        'students' => (int) ($row['students'] ?? 0),
        'sessions' => (int) ($row['sessions'] ?? 0),
        'present' => (int) ($row['present'] ?? 0),
        'late' => (int) ($row['late'] ?? 0),
        'absent' => (int) ($row['absent'] ?? 0),
        'left_early' => (int) ($row['left_early'] ?? 0),
        'average_percent' => round($average, 2),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function list_attendance_report_modules(?int $lecturerId = null): array
{
    $sql = "SELECT DISTINCT m.module_id, m.module_code, m.module_name
            FROM modules m
            INNER JOIN lecture_sessions ls ON ls.module_id = m.module_id
            WHERE ls.status = 'COMPLETED'";
    $params = [];
    if ($lecturerId !== null) {
        $sql .= ' AND ls.lecturer_id = :lecturer_id';
        $params['lecturer_id'] = $lecturerId;
    }
    $sql .= ' ORDER BY m.module_code';
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function list_attendance_report_sessions(?int $lecturerId = null, ?int $moduleId = null): array
{
    $sql = "SELECT ls.session_id, ls.session_date, ls.scheduled_start, ls.scheduled_end,
                   m.module_code, b.batch_name
            FROM lecture_sessions ls
            INNER JOIN modules m ON m.module_id = ls.module_id
            INNER JOIN batches b ON b.batch_id = ls.batch_id
            WHERE ls.status = 'COMPLETED'";
    $params = [];
    if ($lecturerId !== null) {
        $sql .= ' AND ls.lecturer_id = :lecturer_id';
        $params['lecturer_id'] = $lecturerId;
    }
    if ($moduleId !== null) {
        $sql .= ' AND ls.module_id = :module_id';
        $params['module_id'] = $moduleId;
    }
    $sql .= ' ORDER BY ls.session_date DESC, ls.scheduled_start DESC';
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function list_attendance_report_batches(?int $lecturerId = null): array
{
    $sql = "SELECT DISTINCT b.batch_id, b.batch_name, c.course_code
            FROM batches b
            INNER JOIN courses c ON c.course_id = b.course_id
            INNER JOIN lecture_sessions ls ON ls.batch_id = b.batch_id
            WHERE ls.status = 'COMPLETED'";
    $params = [];
    if ($lecturerId !== null) {
        $sql .= ' AND ls.lecturer_id = :lecturer_id';
        $params['lecturer_id'] = $lecturerId;
    }
    $sql .= ' ORDER BY c.course_code, b.batch_name';
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function list_attendance_report_lecturers(): array
{
    $statement = db()->query(
        "SELECT DISTINCT l.lecturer_id, l.first_name, l.last_name, l.staff_no
         FROM lecturers l
         INNER JOIN lecture_sessions ls ON ls.lecturer_id = l.lecturer_id
         WHERE ls.status = 'COMPLETED'
         ORDER BY l.last_name, l.first_name"
    );

    return $statement->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function get_attendance_report_record(int $attendanceId, ?int $forcedLecturerId = null, ?int $forcedStudentId = null): ?array
{
    $sql = "SELECT r.attendance_id, r.student_id, r.session_id, r.first_entry, r.last_exit,
                   r.total_present_minutes, r.teaching_minutes, r.attendance_percent,
                   r.status, r.left_early, r.late_minutes, r.finalized_at,
                   s.registration_no, s.first_name, s.last_name,
                   ls.session_date, ls.scheduled_start, ls.scheduled_end, ls.status AS session_status,
                   ls.module_id, ls.lecturer_id, ls.batch_id,
                   m.module_code, m.module_name,
                   b.batch_name,
                   l.first_name AS lecturer_first_name, l.last_name AS lecturer_last_name
            FROM attendance_records r
            INNER JOIN lecture_sessions ls ON ls.session_id = r.session_id
            INNER JOIN students s ON s.student_id = r.student_id
            INNER JOIN modules m ON m.module_id = ls.module_id
            INNER JOIN batches b ON b.batch_id = ls.batch_id
            INNER JOIN lecturers l ON l.lecturer_id = ls.lecturer_id
            WHERE r.attendance_id = :attendance_id
              AND ls.status = 'COMPLETED'";
    $params = ['attendance_id' => $attendanceId];
    if ($forcedLecturerId !== null) {
        $sql .= ' AND ls.lecturer_id = :lecturer_id';
        $params['lecturer_id'] = $forcedLecturerId;
    }
    if ($forcedStudentId !== null) {
        $sql .= ' AND r.student_id = :student_id';
        $params['student_id'] = $forcedStudentId;
    }
    $sql .= ' LIMIT 1';
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

function attendance_report_session_notice(?int $sessionId, ?int $forcedLecturerId = null): ?string
{
    if ($sessionId === null) {
        return null;
    }
    $session = get_lecture_session($sessionId);
    if ($session === null) {
        return 'Lecture session not found.';
    }
    if ($forcedLecturerId !== null && (int) $session['lecturer_id'] !== $forcedLecturerId) {
        return 'You can only view attendance for your own lecture sessions.';
    }
    if (($session['status'] ?? '') !== 'COMPLETED') {
        return 'This session is not finalized. Reports show completed attendance records only.';
    }

    return null;
}

/**
 * @param list<array<string, mixed>> $rows
 * @param resource $out
 */
function attendance_report_write_csv($out, array $rows): void
{
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Registration No',
        'Student',
        'Module',
        'Date',
        'Scheduled Start',
        'Scheduled End',
        'First In',
        'Last Out',
        'Attended Minutes',
        'Teaching Minutes',
        'Attendance %',
        'Status',
        'Left Early',
    ]);
    foreach ($rows as $row) {
        fputcsv($out, [
            (string) ($row['registration_no'] ?? ''),
            trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
            (string) ($row['module_code'] ?? ''),
            (string) ($row['session_date'] ?? ''),
            format_time_display(isset($row['scheduled_start']) ? (string) $row['scheduled_start'] : null),
            format_time_display(isset($row['scheduled_end']) ? (string) $row['scheduled_end'] : null),
            (string) ($row['first_entry'] ?? ''),
            (string) ($row['last_exit'] ?? ''),
            (string) ($row['total_present_minutes'] ?? '0'),
            (string) ($row['teaching_minutes'] ?? '0'),
            number_format((float) ($row['attendance_percent'] ?? 0), 2, '.', ''),
            (string) ($row['status'] ?? ''),
            ((int) ($row['left_early'] ?? 0) === 1) ? 'Yes' : 'No',
        ]);
    }
}

/**
 * @param list<array<string, mixed>> $rows
 */
function attendance_report_output_csv(array $rows, string $filename = 'attendance-report.csv'): void
{
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
    }
    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }
    attendance_report_write_csv($out, $rows);
    fclose($out);
}
