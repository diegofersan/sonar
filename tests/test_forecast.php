<?php
/**
 * F02 — Test pure forecast helpers.
 *
 * Exit 0 on success, 1 on failure.
 * Usage: php tests/test_forecast.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/workload.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $details = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ $label\n"; }
    else     { $fail++; echo "  ✗ $label" . ($details !== '' ? " — $details" : '') . "\n"; }
}

$tz = new DateTimeZone('Europe/Lisbon');

// Helper: Lisbon midnight → epoch ms.
function ms(string $ymd, DateTimeZone $tz): int {
    $d = new DateTimeImmutable($ymd . ' 00:00', $tz);
    return (int) ($d->getTimestamp() * 1000);
}

// ---------------------------------------------------------------------------
// 1. forecast_is_terminal
// ---------------------------------------------------------------------------
echo "\n[ forecast_is_terminal ]\n";

check('null is not terminal', forecast_is_terminal(null) === false);
check('empty string is not terminal', forecast_is_terminal('') === false);
check('"in progress" is not terminal', forecast_is_terminal('in progress') === false);
check('"published" (lower) is terminal', forecast_is_terminal('published') === true);
check('"Published" (title) is terminal — case-insensitive', forecast_is_terminal('Published') === true);
check('"PUBLISHED" (upper) is terminal', forecast_is_terminal('PUBLISHED') === true);
check('"  published  " (padded) is terminal — trim', forecast_is_terminal('  published  ') === true);
check('"Mês social terminado" terminal — unicode', forecast_is_terminal('Mês social terminado') === true);
check('"Post Cancelado" terminal', forecast_is_terminal('Post Cancelado') === true);
check('"Linha editorial cancelada" terminal', forecast_is_terminal('Linha editorial cancelada') === true);
check('"Scheduled" terminal', forecast_is_terminal('Scheduled') === true);
check('"Ready to post" terminal', forecast_is_terminal('Ready to post') === true);
check('"to do" is not terminal', forecast_is_terminal('to do') === false);

// ---------------------------------------------------------------------------
// 2. forecast_task_days
// ---------------------------------------------------------------------------
echo "\n[ forecast_task_days ]\n";

// Week reference: Mon 2026-04-20 08:00 as "now" so Mon..Fri are today/future
$now = new DateTimeImmutable('2026-04-20 08:00', $tz);

// 2a. start+due spread (Mon → Wed)
$days = forecast_task_days([
    'status_name' => 'in progress',
    'start_date'  => ms('2026-04-20', $tz),
    'due_date'    => ms('2026-04-22', $tz),
], $now, $tz);
check('start+due spread Mon-Wed = 3 weekdays',
    $days === ['2026-04-20', '2026-04-21', '2026-04-22'],
    'got ' . json_encode($days));

// 2b. span crosses weekend — only weekdays (Fri → Mon; Fri is past, Mon is today)
$days = forecast_task_days([
    'status_name' => 'in progress',
    'start_date'  => ms('2026-04-17', $tz), // Fri (past)
    'due_date'    => ms('2026-04-20', $tz), // Mon (today)
], $now, $tz);
check('span Fri→Mon skips weekend',
    $days === ['2026-04-17', '2026-04-20'],
    'got ' . json_encode($days));

// 2c. only due → fallback 1 weekday before
$days = forecast_task_days([
    'status_name' => 'in progress',
    'start_date'  => null,
    'due_date'    => ms('2026-04-22', $tz), // Wed
], $now, $tz);
check('only due Wed → fallback Tue',
    $days === ['2026-04-21'],
    'got ' . json_encode($days));

// 2d. only due, due is Monday (today) → fallback to previous Friday
$days = forecast_task_days([
    'status_name' => 'in progress',
    'start_date'  => null,
    'due_date'    => ms('2026-04-20', $tz), // Mon = today, not overdue
], $now, $tz);
check('only due Mon → fallback previous Fri',
    $days === ['2026-04-17'],
    'got ' . json_encode($days));

// 2e. only start → start day (weekday)
$days = forecast_task_days([
    'status_name' => 'in progress',
    'start_date'  => ms('2026-04-23', $tz), // Thu
    'due_date'    => null,
], $now, $tz);
check('only start Thu → [Thu]',
    $days === ['2026-04-23'],
    'got ' . json_encode($days));

// 2f. both null → skip
$days = forecast_task_days([
    'status_name' => 'in progress',
    'start_date'  => null,
    'due_date'    => null,
], $now, $tz);
check('both null → []', $days === [], 'got ' . json_encode($days));

// 2g. overdue → today (Mon 2026-04-20)
$days = forecast_task_days([
    'status_name' => 'in progress',
    'start_date'  => ms('2026-04-10', $tz),
    'due_date'    => ms('2026-04-15', $tz), // past
], $now, $tz);
check('overdue (due past, now=Mon) → today (2026-04-20)',
    $days === ['2026-04-20'],
    'got ' . json_encode($days));

// 2h. terminal → skip
$days = forecast_task_days([
    'status_name' => 'published',
    'start_date'  => ms('2026-04-20', $tz),
    'due_date'    => ms('2026-04-22', $tz),
], $now, $tz);
check('terminal status → []', $days === [], 'got ' . json_encode($days));

// 2i. start on weekend only → skip (no weekday anchor)
$days = forecast_task_days([
    'status_name' => 'in progress',
    'start_date'  => ms('2026-04-18', $tz), // Sat
    'due_date'    => null,
], $now, $tz);
check('only start on Sat → []', $days === [], 'got ' . json_encode($days));

// ---------------------------------------------------------------------------
// 3. forecast_current_week
// ---------------------------------------------------------------------------
echo "\n[ forecast_current_week ]\n";

$wd = forecast_current_week($tz, new DateTimeImmutable('2026-04-22 14:00', $tz));
check('week from Wed 2026-04-22 → Mon..Fri',
    count($wd) === 5
    && $wd[0]->format('Y-m-d') === '2026-04-20'
    && $wd[4]->format('Y-m-d') === '2026-04-24',
    'got [' . implode(',', array_map(fn($d) => $d->format('Y-m-d'), $wd)) . ']');

$wd = forecast_current_week($tz, new DateTimeImmutable('2026-04-26 14:00', $tz)); // Sun
check('week from Sun 2026-04-26 → same week Mon..Fri',
    $wd[0]->format('Y-m-d') === '2026-04-20'
    && $wd[4]->format('Y-m-d') === '2026-04-24',
    'got [' . implode(',', array_map(fn($d) => $d->format('Y-m-d'), $wd)) . ']');

$wd = forecast_current_week($tz, new DateTimeImmutable('2026-04-20 00:00', $tz)); // Mon
check('week from Mon itself → starts that Mon',
    $wd[0]->format('Y-m-d') === '2026-04-20',
    'got ' . $wd[0]->format('Y-m-d'));

// ---------------------------------------------------------------------------
// 4. forecast_status
// ---------------------------------------------------------------------------
echo "\n[ forecast_status ]\n";

check('0/5 under',    forecast_status(0, 5) === 'under');
check('3/5 under (60%)', forecast_status(3, 5) === 'under');
check('4/5 ok (80%)',  forecast_status(4, 5) === 'ok');
check('5/5 ok (100%)', forecast_status(5, 5) === 'ok');
check('5/5 -> ok then 6/5 = 120% over',
    forecast_status(6, 5) === 'over');
check('target=0, count=0 → under',
    forecast_status(0, 0) === 'under');
check('target=0, count=1 → over',
    forecast_status(1, 0) === 'over');

// ---------------------------------------------------------------------------
// 5. forecast_aggregate
// ---------------------------------------------------------------------------
echo "\n[ forecast_aggregate ]\n";

$weekdays = forecast_current_week($tz, new DateTimeImmutable('2026-04-20 08:00', $tz));
$now      = new DateTimeImmutable('2026-04-20 08:00', $tz);

// 5a. parent skipped when it has children in input
$tasks = [
    ['id' => 'P1', 'parent_id' => null, 'status_name' => 'in progress',
     'start_date' => ms('2026-04-20', $tz), 'due_date' => ms('2026-04-20', $tz)],
    ['id' => 'C1', 'parent_id' => 'P1', 'status_name' => 'in progress',
     'start_date' => ms('2026-04-20', $tz), 'due_date' => ms('2026-04-20', $tz)],
];
$agg = forecast_aggregate($tasks, 5, $weekdays, $now, $tz);
check('parent skipped when children in input — Mon count = 1 (only child)',
    $agg[0]['active_count'] === 1,
    'got ' . $agg[0]['active_count']);

// 5b. undated_count increments on today
$tasks = [
    ['id' => 'X1', 'parent_id' => null, 'status_name' => 'in progress',
     'start_date' => null, 'due_date' => null],
    ['id' => 'X2', 'parent_id' => null, 'status_name' => 'in progress',
     'start_date' => null, 'due_date' => null],
];
$agg = forecast_aggregate($tasks, 5, $weekdays, $now, $tz);
// Mon 2026-04-20 is index 0 (today)
check('undated_count bumps today',
    $agg[0]['undated_count'] === 2 && $agg[0]['active_count'] === 0,
    'got undated=' . $agg[0]['undated_count'] . ' active=' . $agg[0]['active_count']);

// 5c. overdue_count increments
$tasks = [
    ['id' => 'O1', 'parent_id' => null, 'status_name' => 'in progress',
     'start_date' => ms('2026-04-10', $tz), 'due_date' => ms('2026-04-15', $tz)], // past
];
$agg = forecast_aggregate($tasks, 5, $weekdays, $now, $tz);
// Mon = today = index 0
check('overdue pushes to today w/ overdue_count=1',
    $agg[0]['active_count'] === 1 && $agg[0]['overdue_count'] === 1,
    'got active=' . $agg[0]['active_count'] . ' overdue=' . $agg[0]['overdue_count']);

// 5d. thresholds under/ok/over in aggregate
$tasks = [];
for ($i = 0; $i < 6; $i++) {
    $tasks[] = ['id' => "T$i", 'parent_id' => null, 'status_name' => 'in progress',
        'start_date' => ms('2026-04-20', $tz), 'due_date' => ms('2026-04-20', $tz)];
}
$agg = forecast_aggregate($tasks, 5, $weekdays, $now, $tz);
check('6 tasks on Mon with target=5 → over',
    $agg[0]['active_count'] === 6 && $agg[0]['status'] === 'over',
    'got count=' . $agg[0]['active_count'] . ' status=' . $agg[0]['status']);

$tasks = [
    ['id' => 'A', 'parent_id' => null, 'status_name' => 'in progress',
     'start_date' => ms('2026-04-20', $tz), 'due_date' => ms('2026-04-20', $tz)],
    ['id' => 'B', 'parent_id' => null, 'status_name' => 'in progress',
     'start_date' => ms('2026-04-20', $tz), 'due_date' => ms('2026-04-20', $tz)],
];
$agg = forecast_aggregate($tasks, 5, $weekdays, $now, $tz);
check('2 tasks on Mon with target=5 → under',
    $agg[0]['status'] === 'under',
    'got ' . $agg[0]['status']);

// 5e. daily_target = 0 degenerates → any task = over
$tasks = [
    ['id' => 'Z', 'parent_id' => null, 'status_name' => 'in progress',
     'start_date' => ms('2026-04-20', $tz), 'due_date' => ms('2026-04-20', $tz)],
];
$agg = forecast_aggregate($tasks, 0, $weekdays, $now, $tz);
check('target=0 + 1 task → over',
    $agg[0]['status'] === 'over',
    'got ' . $agg[0]['status']);

// 5f. terminal task ignored
$tasks = [
    ['id' => 'T', 'parent_id' => null, 'status_name' => 'Published',
     'start_date' => ms('2026-04-20', $tz), 'due_date' => ms('2026-04-22', $tz)],
];
$agg = forecast_aggregate($tasks, 5, $weekdays, $now, $tz);
check('terminal task never counted',
    $agg[0]['active_count'] === 0 && $agg[1]['active_count'] === 0 && $agg[2]['active_count'] === 0,
    'got counts=' . $agg[0]['active_count'] . ',' . $agg[1]['active_count'] . ',' . $agg[2]['active_count']);

// 5g. weekday labels
check('weekday labels mon..fri',
    $agg[0]['weekday'] === 'mon'
    && $agg[1]['weekday'] === 'tue'
    && $agg[2]['weekday'] === 'wed'
    && $agg[3]['weekday'] === 'thu'
    && $agg[4]['weekday'] === 'fri');

echo "\nPassed: $pass\nFailed: $fail\n";
exit($fail === 0 ? 0 : 1);
