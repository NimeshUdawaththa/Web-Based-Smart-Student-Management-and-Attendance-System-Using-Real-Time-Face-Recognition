<?php

declare(strict_types=1);

/**
 * Shared lecture calendar view helpers (Lecturer My Calendar + institution Lecture Calendar).
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
 * Resolve month/week/today date ranges for lecture_sessions queries.
 *
 * @return array{view: string, anchor_date: string, range_from: string, range_to: string, heading: string, prev_anchor: string, next_anchor: string, grid_days: list<string>}
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
