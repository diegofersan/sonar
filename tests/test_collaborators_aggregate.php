<?php
/**
 * F01 — Pure-helper tests for includes/collaborators.php.
 *
 * No I/O, no DB, no HTTP. Exercises:
 *   - collab_status thresholds
 *   - collab_month_window corner cases around month + week boundaries
 *   - collab_iso_weeks enumeration
 *   - collab_aggregate_weeks bucketing + total + status
 *
 * Exit 0 on success, 1 on failure.
 * Usage: php tests/test_collaborators_aggregate.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/collaborators.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $details = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ $label\n"; }
    else     { $fail++; echo "  ✗ $label" . ($details !== '' ? " — $details" : '') . "\n"; }
}

// ---------------------------------------------------------------------------
// 1. collab_status thresholds
// ---------------------------------------------------------------------------
echo "1. collab_status\n";

// weekly = 40h → under < 32, ok 32..44, over > 44
check('0h / 40w  → under',   collab_status(0.0, 40)  === 'under');
check('31.9h    → under',    collab_status(31.9, 40) === 'under');
check('32h exact → ok',       collab_status(32.0, 40) === 'ok');
check('40h exact → ok',       collab_status(40.0, 40) === 'ok');
check('44h exact → ok',       collab_status(44.0, 40) === 'ok');
check('44.01h  → over',       collab_status(44.01, 40) === 'over');
check('100h   → over',        collab_status(100.0, 40) === 'over');

// weekly = 0 → degenerate: any work → over, no work → under
check('0h / 0w → under',      collab_status(0.0, 0) === 'under');
check('5h / 0w → over',       collab_status(5.0, 0) === 'over');

// ---------------------------------------------------------------------------
// 2. collab_month_window
// ---------------------------------------------------------------------------
echo "\n2. collab_month_window\n";

$tz = new DateTimeZone('Europe/Lisbon');

// April 2026: Wed 2026-04-01 → Thu 2026-04-30. Expect window Mar 30 → May 4.
$now = new DateTimeImmutable('2026-04-22 15:00:00', $tz);
[$ws, $we] = collab_month_window($tz, $now);
check('April 2026: window start = 2026-03-30 (Mon)',
    $ws->format('Y-m-d N') === '2026-03-30 1',
    $ws->format('c'));
check('April 2026: window end   = 2026-05-04 (Mon)',
    $we->format('Y-m-d N') === '2026-05-04 1',
    $we->format('c'));

// Month that starts on a Monday: Feb 2027 (Mon Feb 1 → Sun Feb 28).
$now = new DateTimeImmutable('2027-02-15', $tz);
[$ws, $we] = collab_month_window($tz, $now);
check('Feb 2027 starts on Mon → start = Feb 1',
    $ws->format('Y-m-d') === '2027-02-01');
check('Feb 2027 ends on Sun → end = Mar 1 (next Mon)',
    $we->format('Y-m-d') === '2027-03-01');

// Month that ends on a Sunday: May 2026 (Fri 1 → Sun 31).
$now = new DateTimeImmutable('2026-05-10', $tz);
[$ws, $we] = collab_month_window($tz, $now);
check('May 2026 end-on-Sun → end = Jun 1 (next Mon)',
    $we->format('Y-m-d') === '2026-06-01',
    $we->format('Y-m-d'));

// January with ISO year rollover: Jan 2027 — first day is Fri Jan 1 (ISO year 2026 W53).
$now = new DateTimeImmutable('2027-01-10', $tz);
[$ws, $we] = collab_month_window($tz, $now);
check('Jan 2027: start = Mon 2026-12-28',
    $ws->format('Y-m-d') === '2026-12-28',
    $ws->format('Y-m-d'));

// ---------------------------------------------------------------------------
// 3. collab_iso_weeks
// ---------------------------------------------------------------------------
echo "\n3. collab_iso_weeks\n";

$now = new DateTimeImmutable('2026-04-22', $tz);
[$ws, $we] = collab_month_window($tz, $now);
$weeks = collab_iso_weeks($ws, $we);

check('April 2026 covers 5 ISO weeks', count($weeks) === 5, (string) count($weeks));
check('first week: Mar 30 / W14 / 2026',
    $weeks[0]['monday']->format('Y-m-d') === '2026-03-30'
    && $weeks[0]['week'] === 14 && $weeks[0]['year'] === 2026);
check('last week: Apr 27 / W18',
    $weeks[4]['monday']->format('Y-m-d') === '2026-04-27'
    && $weeks[4]['week'] === 18);

// Rollover sanity: Jan 2027 first week is 2026-W53
$now = new DateTimeImmutable('2027-01-15', $tz);
[$ws, $we] = collab_month_window($tz, $now);
$weeks = collab_iso_weeks($ws, $we);
check('Jan 2027 first week: ISO year 2026 W53',
    $weeks[0]['year'] === 2026 && $weeks[0]['week'] === 53,
    json_encode([$weeks[0]['year'], $weeks[0]['week']]));

// ---------------------------------------------------------------------------
// 4. collab_aggregate_weeks
// ---------------------------------------------------------------------------
echo "\n4. collab_aggregate_weeks\n";

// Build a 2-week window: Mar 30 → Apr 13 (2 weeks).
$ws = new DateTimeImmutable('2026-03-30', $tz);
$we = new DateTimeImmutable('2026-04-13', $tz);
$weeks = collab_iso_weeks($ws, $we);

// Helper to build an entry row from a Lisbon-local date + hours.
function mkEntry(string $localDateTime, float $hours, DateTimeZone $tz): array {
    $dt = new DateTimeImmutable($localDateTime, $tz);
    return [
        'start_ms'    => $dt->getTimestamp() * 1000,
        'duration_ms' => (int) round($hours * 3_600_000),
    ];
}

// Week 1 (Mar 30 → Apr 5): Mon 8h, Tue 4h, Fri 10h → 22h (under 40)
$entries = [
    mkEntry('2026-03-30 09:00', 8.0, $tz), // Mon
    mkEntry('2026-03-31 10:00', 4.0, $tz), // Tue
    mkEntry('2026-04-03 08:00', 10.0, $tz), // Fri
    // Week 2 (Apr 6 → Apr 12): Mon 9h, Wed 9h, Thu 9h, Fri 9h, Sat 5h → 41h (ok)
    mkEntry('2026-04-06 09:00', 9.0, $tz),
    mkEntry('2026-04-08 09:00', 9.0, $tz),
    mkEntry('2026-04-09 09:00', 9.0, $tz),
    mkEntry('2026-04-10 09:00', 9.0, $tz),
    mkEntry('2026-04-11 10:00', 5.0, $tz),
    // Outside the window: should be dropped
    mkEntry('2026-04-20 09:00', 40.0, $tz),
];

$agg = collab_aggregate_weeks($entries, 40, $weeks, $tz);
check('aggregate returns 2 weeks', count($agg) === 2, (string) count($agg));

$w1 = $agg[0];
check('week 1 days.mon = 8',  $w1['days']['mon'] === 8.0, json_encode($w1['days']));
check('week 1 days.tue = 4',  $w1['days']['tue'] === 4.0);
check('week 1 days.fri = 10', $w1['days']['fri'] === 10.0);
check('week 1 days.sun = 0',  $w1['days']['sun'] === 0.0);
check('week 1 total = 22h',   $w1['total_hours'] === 22.0);
check('week 1 status = under (22/40 = 55%)', $w1['status'] === 'under');

$w2 = $agg[1];
check('week 2 total = 41h',   $w2['total_hours'] === 41.0, (string) $w2['total_hours']);
check('week 2 status = ok (41/40 = 102%)', $w2['status'] === 'ok');

// Out-of-window entry was NOT added to either week's total
$sumAllDays = 0.0;
foreach ($agg as $w) $sumAllDays += $w['total_hours'];
check('out-of-window entry dropped (total = 22+41=63, not 63+40)',
    $sumAllDays === 63.0, (string) $sumAllDays);

// Empty entries → each week has all zeros and 'under' status
$empty = collab_aggregate_weeks([], 40, $weeks, $tz);
check('empty entries → 2 weeks, all totals 0.0, status under',
    count($empty) === 2
    && $empty[0]['total_hours'] === 0.0
    && $empty[1]['total_hours'] === 0.0
    && $empty[0]['status'] === 'under');

// Over-threshold: 50h in one week vs weekly=40 (50/40 = 125% > 110%)
$over = [
    mkEntry('2026-03-30 08:00', 10.0, $tz),
    mkEntry('2026-03-31 08:00', 10.0, $tz),
    mkEntry('2026-04-01 08:00', 10.0, $tz),
    mkEntry('2026-04-02 08:00', 10.0, $tz),
    mkEntry('2026-04-03 08:00', 10.0, $tz),
];
$aggOver = collab_aggregate_weeks($over, 40, $weeks, $tz);
check('50h week vs 40 weekly → over',
    $aggOver[0]['total_hours'] === 50.0 && $aggOver[0]['status'] === 'over',
    $aggOver[0]['status']);

// ---------------------------------------------------------------------------
echo "\nPassed: $pass\nFailed: $fail\n";
exit($fail === 0 ? 0 : 1);
