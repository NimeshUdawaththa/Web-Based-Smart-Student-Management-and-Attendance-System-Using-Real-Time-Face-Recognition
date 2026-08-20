<?php

declare(strict_types=1);

/**
 * Shared calendar view helpers (Lecturer My Calendar + institution Lecture Calendar).
 * Combines lecture_sessions with Coursework & Assessments (Presentation/Exam/Practical).
 * Never creates lecture_sessions or attendance rows for coursework activities.
 */

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * @return list<string>
 */
function calendar_day_keys(string $from, string $to): array
{
    $tz = new DateTimeZone(APP_TIMEZONE);
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
    if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
        return [];
    }

    $days = [];
    for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
        $days[] = $cursor->format('Y-m-d');
    }

    return $days;
}

/**
 * @param array<string, scalar|null> $extra
 */
function calendar_url(string $path, string $view, string $date, array $extra = []): string
{
    $query = ['view' => $view, 'date' => $date];
    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $query[$key] = $value;
    }

    return app_url($path . '?' . http_build_query($query));
}

function calendar_session_status_class(string $status): string
{
    return match ($status) {
        'IN_PROGRESS' => 'lecturer-cal-event--in-progress',
        'COMPLETED' => 'lecturer-cal-event--completed',
        'CANCELLED' => 'lecturer-cal-event--cancelled',
        default => 'lecturer-cal-event--scheduled',
    };
}

function calendar_lecturer_display_name(array $session): string
{
    $name = trim(
        (string) ($session['lecturer_first_name'] ?? '') . ' ' . (string) ($session['lecturer_last_name'] ?? '')
    );

    return $name !== '' ? $name : 'Unknown lecturer';
}

/**
 * @return list<string>
 */
function calendar_event_types(): array
{
    return ['LECTURE', 'PRESENTATION', 'EXAM', 'PRACTICAL'];
}

function calendar_event_type_label(string $type): string
{
    return match (strtoupper(trim($type))) {
        'LECTURE' => 'Lecture',
        'PRESENTATION' => 'Presentation',
        'EXAM' => 'Exam',
        'PRACTICAL' => 'Practical',
        default => 'Event',
    };
}

function calendar_event_type_class(string $type): string
{
    return match (strtoupper(trim($type))) {
        'LECTURE' => 'lecturer-cal-event--type-lecture',
        'PRESENTATION' => 'lecturer-cal-event--type-presentation',
        'EXAM' => 'lecturer-cal-event--type-exam',
        'PRACTICAL' => 'lecturer-cal-event--type-practical',
        default => 'lecturer-cal-event--type-lecture',
    };
}

/**
 * Normalize a lecture_sessions row for the shared calendar renderer.
 *
 * @param array<string, mixed> $session
 * @return array<string, mixed>|null
 */
function calendar_normalize_lecture_event(array $session, string $detailUrl = ''): ?array
{
    $date = trim((string) ($session['session_date'] ?? ''));
    if ($date === '' || !validate_date_ymd($date)) {
        return null;
    }

    $startRaw = $session['scheduled_start'] ?? null;
    $endRaw = $session['scheduled_end'] ?? null;
    if ($startRaw === null || $startRaw === '' || $endRaw === null || $endRaw === '') {
        return null;
    }

    return [
        'event_type' => 'LECTURE',
        'source_id' => (int) ($session['session_id'] ?? 0),
        'title' => (string) ($session['batch_name'] ?? $session['module_name'] ?? 'Lecture'),
        'module_code' => (string) ($session['module_code'] ?? ''),
        'module_name' => (string) ($session['module_name'] ?? ''),
        'event_date' => $date,
        'start_time' => $startRaw,
        'end_time' => $endRaw,
        'status' => (string) ($session['status'] ?? 'SCHEDULED'),
        'location' => (string) ($session['room'] ?? ''),
        'detail_url' => $detailUrl,
        'batch_name' => (string) ($session['batch_name'] ?? ''),
        'lecturer_name' => calendar_lecturer_display_name($session),
        'is_lecture' => true,
        'sort_key' => $date . '|' . format_time_display($startRaw) . '|0|' . (string) ($session['module_code'] ?? ''),
    ];
}

/**
 * Normalize a Presentation/Exam/Practical assignment row.
 * Uses scheduled_* fields only (never due_date for display).
 *
 * @param array<string, mixed> $assignment
 * @return array<string, mixed>|null
 */
function calendar_normalize_coursework_event(array $assignment, string $detailUrl = ''): ?array
{
    $type = strtoupper(trim((string) ($assignment['activity_type'] ?? '')));
    if (!in_array($type, ['PRESENTATION', 'EXAM', 'PRACTICAL'], true)) {
        return null;
    }

    $date = trim((string) ($assignment['scheduled_date'] ?? ''));
    $start = $assignment['start_time'] ?? null;
    $end = $assignment['end_time'] ?? null;
    if ($date === '' || !validate_date_ymd($date) || $start === null || $start === '' || $end === null || $end === '') {
        return null;
    }

    $status = strtoupper(trim((string) ($assignment['status'] ?? '')));
    if ($status === 'DRAFT') {
        return null;
    }

    return [
        'event_type' => $type,
        'source_id' => (int) ($assignment['assignment_id'] ?? 0),
        'title' => (string) ($assignment['title'] ?? ''),
        'module_code' => (string) ($assignment['module_code'] ?? ''),
        'module_name' => (string) ($assignment['module_name'] ?? ''),
        'event_date' => $date,
        'start_time' => $start,
        'end_time' => $end,
        'status' => $status !== '' ? $status : 'PUBLISHED',
        'location' => (string) ($assignment['room'] ?? ''),
        'detail_url' => $detailUrl,
        'batch_name' => '',
        'lecturer_name' => trim(
            (string) ($assignment['lecturer_first_name'] ?? '') . ' ' . (string) ($assignment['lecturer_last_name'] ?? '')
        ),
        'is_lecture' => false,
        'sort_key' => $date . '|' . format_time_display($start) . '|1|' . (string) ($assignment['module_code'] ?? ''),
    ];
}

/**
 * @param list<array<string, mixed>> $events
 * @return list<array<string, mixed>>
 */
function calendar_sort_events(array $events): array
{
    usort($events, static function (array $a, array $b): int {
        return strcmp((string) ($a['sort_key'] ?? ''), (string) ($b['sort_key'] ?? ''));
    });

    return $events;
}

/**
 * @param list<array<string, mixed>> $events
 * @return array<string, list<array<string, mixed>>>
 */
function calendar_group_events_by_date(array $events): array
{
    $byDate = [];
    foreach (calendar_sort_events($events) as $event) {
        $day = (string) ($event['event_date'] ?? '');
        if ($day === '') {
            continue;
        }
        $byDate[$day][] = $event;
    }

    return $byDate;
}

/**
 * Resolve month/week/today date ranges for lecture_sessions queries.
 *
 * @return array{view: string, anchor_date: string, range_from: string, range_to: string, heading: string, prev_anchor: string, next_anchor: string, grid_days: list<string>, anchor: DateTimeImmutable}
 */
function calendar_resolve_view_range(string $view, string $anchorDate, string $today): array
{
    if (!in_array($view, ['month', 'week', 'today'], true)) {
        $view = 'month';
    }

    $tz = new DateTimeZone(APP_TIMEZONE);
    $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $anchorDate, $tz);
    if (!$anchor instanceof DateTimeImmutable) {
        $anchorDate = $today;
        $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $anchorDate, $tz);
    }
    if (!$anchor instanceof DateTimeImmutable) {
        throw new RuntimeException('Unable to resolve calendar anchor date.');
    }

    if ($view === 'today') {
        $rangeFrom = $anchorDate;
        $rangeTo = $anchorDate;
        $prevAnchor = $anchor->modify('-1 day')->format('Y-m-d');
        $nextAnchor = $anchor->modify('+1 day')->format('Y-m-d');
        $heading = $anchor->format('l, j F Y');
    } elseif ($view === 'week') {
        $weekStart = monday_of_week($anchorDate) ?? $anchorDate;
        $weekStartDate = DateTimeImmutable::createFromFormat('!Y-m-d', $weekStart, $tz);
        $rangeFrom = $weekStart;
        $rangeTo = $weekStartDate instanceof DateTimeImmutable
            ? $weekStartDate->modify('+6 days')->format('Y-m-d')
            : $anchorDate;
        $weekEndDate = $weekStartDate instanceof DateTimeImmutable ? $weekStartDate->modify('+6 days') : $anchor;
        $prevAnchor = $anchor->modify('-7 days')->format('Y-m-d');
        $nextAnchor = $anchor->modify('+7 days')->format('Y-m-d');
        $heading = ($weekStartDate?->format('j M Y') ?? $anchorDate) . ' – ' . $weekEndDate->format('j M Y');
    } else {
        $firstOfMonth = $anchor->modify('first day of this month');
        $lastOfMonth = $anchor->modify('last day of this month');
        $rangeFrom = monday_of_week($firstOfMonth->format('Y-m-d')) ?? $firstOfMonth->format('Y-m-d');
        $lastOffset = 7 - (int) $lastOfMonth->format('N');
        if ($lastOffset === 7) {
            $lastOffset = 0;
        }
        $rangeTo = $lastOfMonth->modify('+' . $lastOffset . ' days')->format('Y-m-d');
        $prevAnchor = $anchor->modify('-1 month')->format('Y-m-d');
        $nextAnchor = $anchor->modify('+1 month')->format('Y-m-d');
        $heading = $anchor->format('F Y');
    }

    $gridDays = $view === 'today'
        ? [$anchorDate]
        : calendar_day_keys($rangeFrom, $rangeTo);

    return [
        'view' => $view,
        'anchor_date' => $anchorDate,
        'range_from' => $rangeFrom,
        'range_to' => $rangeTo,
        'heading' => $heading,
        'prev_anchor' => $prevAnchor,
        'next_anchor' => $nextAnchor,
        'grid_days' => $gridDays,
        'anchor' => $anchor,
    ];
}
