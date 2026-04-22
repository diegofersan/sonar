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
 *   days_posts: array<string, list<array{post_id:?string, post_name:?string, post_url:?string, duration_ms:int, hours:float}>>,
 *   total_hours:float, status:string
 * }>
 */
function collab_aggregate_weeks(
    array $entries,
    int $weekly_hours,
    array $weeks,
    DateTimeZone $tz
): array {
    // Bucket: "YYYY-WW" → per-day totals in ms (1=Mon..7=Sun) plus per-post
    // breakdown per weekday for the click-to-detail popup. The grouping key
    // is post_id (parent_task_id || task_id), so multiple subtasks of the
    // same post (Design + Copy) collapse into a single row with summed
    // duration — matching the Linha Editorial view's parent-bubble rule.
    //
    //   days  → [dow => total_ms]
    //   posts → [dow => [post_id => ['post_id','post_name','post_url','duration_ms']]]
    //           entries without task_id fall under sentinel '_no_task'.
    $buckets = [];
    foreach ($weeks as $w) {
        $key = sprintf('%04d-%02d', $w['year'], $w['week']);
        $buckets[$key] = [
            'meta'  => $w,
            'days'  => array_fill(1, 7, 0),
            'posts' => array_fill(1, 7, []),
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

        // Resolve post context: parent if this entry's task is a Design/Copy
        // subtask, otherwise the task itself.
        $taskId       = isset($e['task_id'])        && $e['task_id']        !== '' ? (string) $e['task_id']        : '';
        $taskName     = isset($e['task_name'])      && $e['task_name']      !== '' ? (string) $e['task_name']      : null;
        $taskUrl      = isset($e['task_url'])       && $e['task_url']       !== '' ? (string) $e['task_url']       : null;
        $parentId     = isset($e['parent_task_id']) && $e['parent_task_id'] !== '' ? (string) $e['parent_task_id'] : '';
        $parentName   = isset($e['parent_name'])    && $e['parent_name']    !== '' ? (string) $e['parent_name']    : null;

        $postId   = $parentId !== '' ? $parentId   : $taskId;
        $postName = $parentId !== '' ? $parentName : $taskName;
        $postUrl  = $parentId !== ''
            ? 'https://app.clickup.com/t/' . $parentId
            : $taskUrl;

        $groupKey = $postId !== '' ? $postId : '_no_task';
        if (!isset($buckets[$key]['posts'][$dow][$groupKey])) {
            $buckets[$key]['posts'][$dow][$groupKey] = [
                'post_id'     => $postId !== '' ? $postId : null,
                'post_name'   => $postName,
                'post_url'    => $postUrl,
                'duration_ms' => 0,
            ];
        }
        $buckets[$key]['posts'][$dow][$groupKey]['duration_ms'] += $dur;
        // Upgrade missing name/url if a later entry supplies them.
        if (
            $buckets[$key]['posts'][$dow][$groupKey]['post_name'] === null
            && $postName !== null
        ) {
            $buckets[$key]['posts'][$dow][$groupKey]['post_name'] = $postName;
        }
        if (
            $buckets[$key]['posts'][$dow][$groupKey]['post_url'] === null
            && $postUrl !== null
        ) {
            $buckets[$key]['posts'][$dow][$groupKey]['post_url'] = $postUrl;
        }
    }

    $dayNames = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
    $out = [];

    foreach ($buckets as $b) {
        $daysHours = [];
        $daysPosts = [];
        $totalMs   = 0;
        foreach ($dayNames as $dow => $name) {
            $ms = $b['days'][$dow];
            $daysHours[$name] = round($ms / 3_600_000, 2);
            $totalMs += $ms;

            // Flatten the post map into a list, sorted by duration desc so
            // most-time-first reads naturally in the UI.
            $list = array_values($b['posts'][$dow]);
            usort($list, function ($a, $b) {
                return ($b['duration_ms'] <=> $a['duration_ms']);
            });
            foreach ($list as &$row) {
                $row['hours'] = round($row['duration_ms'] / 3_600_000, 2);
            }
            unset($row);
            $daysPosts[$name] = $list;
        }
        $totalHours = round($totalMs / 3_600_000, 2);
        $out[] = [
            'year'        => $b['meta']['year'],
            'week_number' => $b['meta']['week'],
            'week_start'  => $b['meta']['monday']->format('Y-m-d'),
            'days'        => $daysHours,
            'days_posts'  => $daysPosts,
            'total_hours' => $totalHours,
            'status'      => collab_status($totalHours, $weekly_hours),
        ];
    }
    return $out;
}
