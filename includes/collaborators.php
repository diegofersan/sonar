<?php
/**
 * F01 — Pure helpers for the Colaboradores page.
 *
 * Hold no state, do no I/O (no DB, no HTTP). Everything here is safe to unit
 * test in isolation.
 */

declare(strict_types=1);

// Thresholds for the weekly-load status badge. Under < 80% of weekly_hours,
// Over > 110%, Ok in the middle. Kept as constants at the top so they are
// cheap to tune later.
const COLLAB_UNDER_RATIO = 0.80;
const COLLAB_OVER_RATIO  = 1.10;

/**
 * Classify a weekly total of hours against the configured weekly_hours.
 *
 * Edge case: weekly_hours <= 0 means "capacity not configured or zero". We
 * treat any work as 'over' in that case (a visible signal the config is
 * missing), and no work as 'under'.
 */
function collab_status(float $hours, int $weekly_hours): string
{
    if ($weekly_hours <= 0) {
        return $hours > 0 ? 'over' : 'under';
    }
    $ratio = $hours / $weekly_hours;
    if ($ratio < COLLAB_UNDER_RATIO) return 'under';
    if ($ratio > COLLAB_OVER_RATIO)  return 'over';
    return 'ok';
}

/**
 * Return the [start, end) window (Mondays) that covers every ISO week with at
 * least one day in `$now`'s calendar month, interpreted in `$tz`.
 *
 * Example: April 2026 (Wed 1 → Thu 30) returns [Mon 2026-03-30, Mon 2026-05-04).
 *
 * @return DateTimeImmutable[] [$start, $end] — both at 00:00 in $tz, both Mondays.
 */
function collab_month_window(DateTimeZone $tz, ?DateTimeImmutable $now = null): array
{
    $now = $now ? $now->setTimezone($tz) : new DateTimeImmutable('now', $tz);

    $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
    $monthEnd   = $now->modify('last day of this month')->setTime(0, 0, 0);

    // ISO: Mon=1..Sun=7
    $firstDow = (int) $monthStart->format('N');
    $lastDow  = (int) $monthEnd->format('N');

    $windowStart = $monthStart->modify('-' . ($firstDow - 1) . ' days');
    $windowEnd   = $monthEnd->modify('+' . (8 - $lastDow) . ' days');

    return [$windowStart, $windowEnd];
}

/**
 * Walk Monday-by-Monday from $start to $end (exclusive) and emit one entry
 * per ISO week. Expects $start to already be a Monday.
 *
 * @return array<int, array{year:int, week:int, monday:DateTimeImmutable}>
 */
function collab_iso_weeks(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $weeks = [];
    $cursor = $start;
    while ($cursor < $end) {
        $weeks[] = [
            'year'   => (int) $cursor->format('o'),
            'week'   => (int) $cursor->format('W'),
            'monday' => $cursor,
        ];
        $cursor = $cursor->modify('+7 days');
    }
    return $weeks;
}

/**
 * Aggregate raw time_entries rows into week-by-week totals with per-day
 * breakdown (Mon..Sun). Entries that fall outside the given week set are
 * silently dropped.
 *
 * @param array $entries       Rows from db_get_time_entries (need start_ms, duration_ms).
 * @param int   $weekly_hours  Capacity used for the status classification.
 * @param array $weeks         Output of collab_iso_weeks().
 * @param DateTimeZone $tz     Zone used to bucket entries into weekdays.
 *
 * @return array<int, array{
 *   year:int, week_number:int, week_start:string,
 *   days: array<string,float>,
 *   days_tasks: array<string, list<array{task_id:?string, task_name:?string, task_url:?string, duration_ms:int, hours:float}>>,
 *   total_hours:float, status:string
 * }>
 */
function collab_aggregate_weeks(
    array $entries,
    int $weekly_hours,
    array $weeks,
    DateTimeZone $tz
): array {
    // Bucket: "YYYY-WW" → per-day totals in ms (1=Mon..7=Sun) plus per-task
    // breakdown per weekday for the click-to-detail popup. Structure:
    //   days   → [dow => total_ms]
    //   tasks  → [dow => [task_id => ['task_id','task_name','task_url','duration_ms']]]
    //            entries without task_id get grouped under the sentinel key '_no_task'.
    $buckets = [];
    foreach ($weeks as $w) {
        $key = sprintf('%04d-%02d', $w['year'], $w['week']);
        $buckets[$key] = [
            'meta'  => $w,
            'days'  => array_fill(1, 7, 0),
            'tasks' => array_fill(1, 7, []),
        ];
    }

    foreach ($entries as $e) {
        $startSec = intdiv((int) $e['start_ms'], 1000);
        $dur      = (int) $e['duration_ms'];
        $dt = (new DateTimeImmutable('@' . $startSec))->setTimezone($tz);

        $key = sprintf('%04d-%02d', (int) $dt->format('o'), (int) $dt->format('W'));
        if (!isset($buckets[$key])) continue;

        $dow = (int) $dt->format('N');
        $buckets[$key]['days'][$dow] += $dur;

        // Group multiple entries for the same task/day into one row (sum durations).
        $taskId   = isset($e['task_id']) && $e['task_id'] !== '' ? (string) $e['task_id'] : '';
        $groupKey = $taskId !== '' ? $taskId : '_no_task';
        if (!isset($buckets[$key]['tasks'][$dow][$groupKey])) {
            $buckets[$key]['tasks'][$dow][$groupKey] = [
                'task_id'     => $taskId !== '' ? $taskId : null,
                'task_name'   => isset($e['task_name']) && $e['task_name'] !== null ? (string) $e['task_name'] : null,
                'task_url'    => isset($e['task_url'])  && $e['task_url']  !== null ? (string) $e['task_url']  : null,
                'duration_ms' => 0,
            ];
        }
        $buckets[$key]['tasks'][$dow][$groupKey]['duration_ms'] += $dur;
        // If this entry has a name/url and the existing row is missing them
        // (e.g. earlier entry had no joinable task row), upgrade.
        if (
            $buckets[$key]['tasks'][$dow][$groupKey]['task_name'] === null
            && isset($e['task_name']) && $e['task_name'] !== null
        ) {
            $buckets[$key]['tasks'][$dow][$groupKey]['task_name'] = (string) $e['task_name'];
        }
        if (
            $buckets[$key]['tasks'][$dow][$groupKey]['task_url'] === null
            && isset($e['task_url']) && $e['task_url'] !== null
        ) {
            $buckets[$key]['tasks'][$dow][$groupKey]['task_url'] = (string) $e['task_url'];
        }
    }

    $dayNames = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
    $out = [];

    foreach ($buckets as $b) {
        $daysHours = [];
        $daysTasks = [];
        $totalMs   = 0;
        foreach ($dayNames as $dow => $name) {
            $ms = $b['days'][$dow];
            $daysHours[$name] = round($ms / 3_600_000, 2);
            $totalMs += $ms;

            // Flatten the task map into a list, sorted by duration desc for a
            // consistent most-time-first ordering in the UI.
            $list = array_values($b['tasks'][$dow]);
            usort($list, function ($a, $b) {
                return ($b['duration_ms'] <=> $a['duration_ms']);
            });
            // Expose hours alongside the raw ms for convenience.
            foreach ($list as &$row) {
                $row['hours'] = round($row['duration_ms'] / 3_600_000, 2);
            }
            unset($row);
            $daysTasks[$name] = $list;
        }
        $totalHours = round($totalMs / 3_600_000, 2);
        $out[] = [
            'year'        => $b['meta']['year'],
            'week_number' => $b['meta']['week'],
            'week_start'  => $b['meta']['monday']->format('Y-m-d'),
            'days'        => $daysHours,
            'days_tasks'  => $daysTasks,
            'total_hours' => $totalHours,
            'status'      => collab_status($totalHours, $weekly_hours),
        ];
    }
    return $out;
}
