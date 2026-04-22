<?php
/**
 * F01 — Smoke test for the time_entries table + helpers.
 *
 * Runs against a throwaway SQLite file (never touches data/sonar.db).
 * Exit 0 on success, 1 on failure.
 *
 * Usage: php tests/test_time_entries_db.php
 */

declare(strict_types=1);

// Point the db() singleton at a temp file BEFORE including database.php.
$tmpDb = tempnam(sys_get_temp_dir(), 'sonar_test_') . '.sqlite';
$GLOBALS['SONAR_DB_PATH'] = $tmpDb;

require_once __DIR__ . '/../includes/database.php';

// Delete the temp DB and its WAL siblings on exit.
register_shutdown_function(function () use ($tmpDb) {
    foreach ([$tmpDb, $tmpDb . '-wal', $tmpDb . '-shm'] as $f) {
        if (is_file($f)) @unlink($f);
    }
});

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $details = ''): void {
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✓ $label\n";
    } else {
        $fail++;
        echo "  ✗ $label" . ($details !== '' ? " — $details" : '') . "\n";
    }
}

// --- 1. db_migrate runs and creates the schema -----------------------------
echo "1. migrate + schema\n";
db(); // triggers db_migrate()

$cols = db()->query("PRAGMA table_info(time_entries)")->fetchAll();
$colNames = array_column($cols, 'name');
check('time_entries table exists with expected columns',
    $cols && count(array_intersect(
        ['id','user_id','task_id','workspace_id','start_ms','duration_ms','synced_at'],
        $colNames
    )) === 7,
    'got: ' . implode(',', $colNames)
);

$indexes = db()->query("PRAGMA index_list(time_entries)")->fetchAll();
$indexNames = array_column($indexes, 'name');
check('lookup index idx_time_entries_lookup exists',
    in_array('idx_time_entries_lookup', $indexNames, true),
    'got: ' . implode(',', $indexNames)
);

$syncCols = db()->query("PRAGMA table_info(sync_log)")->fetchAll();
$syncColNames = array_column($syncCols, 'name');
check("sync_log has 'scope' column",
    in_array('scope', $syncColNames, true),
    'got: ' . implode(',', $syncColNames)
);

// Migration is idempotent
try {
    db_migrate();
    check('db_migrate is idempotent (no error on second call)', true);
} catch (\Throwable $e) {
    check('db_migrate is idempotent', false, $e->getMessage());
}

// --- 2. upsert 3 entries ---------------------------------------------------
echo "\n2. upsert\n";
$wsId = '2590506';
$userA = '473908';
$userB = '4727509';

// Monday 2026-04-20 10:00 UTC, Tuesday 2026-04-21 14:00 UTC, Monday 2026-04-27 09:00 UTC
$monMs = (new DateTimeImmutable('2026-04-20 10:00:00', new DateTimeZone('UTC')))->getTimestamp() * 1000;
$tueMs = (new DateTimeImmutable('2026-04-21 14:00:00', new DateTimeZone('UTC')))->getTimestamp() * 1000;
$nextMonMs = (new DateTimeImmutable('2026-04-27 09:00:00', new DateTimeZone('UTC')))->getTimestamp() * 1000;

$entries = [
    [ // has task → kept
        'id' => 'te_1', 'user' => ['id' => $userA], 'task' => ['id' => 'tsk_1'],
        'start' => (string) $monMs, 'duration' => '3600000', 'wid' => $wsId,
    ],
    [ // has task → kept
        'id' => 'te_2', 'user' => ['id' => $userA], 'task' => ['id' => 'tsk_2'],
        'start' => (string) $tueMs, 'duration' => '5400000', 'wid' => $wsId,
    ],
    [ // different user, inside same week
        'id' => 'te_3', 'user' => ['id' => $userB], 'task' => ['id' => 'tsk_3'],
        'start' => (string) $tueMs, 'duration' => '1800000', 'wid' => $wsId,
    ],
    [ // no task → should be skipped
        'id' => 'te_skip', 'user' => ['id' => $userA], 'task' => null,
        'start' => (string) $monMs, 'duration' => '1000', 'wid' => $wsId,
    ],
    [ // next week, user A
        'id' => 'te_4', 'user' => ['id' => $userA], 'task' => ['id' => 'tsk_4'],
        'start' => (string) $nextMonMs, 'duration' => '7200000', 'wid' => $wsId,
    ],
];

$inserted = db_upsert_time_entries($entries, $wsId);
check('upsert returns count ignoring task-less entries (4 of 5)',
    $inserted === 4, "got $inserted");

$total = (int) db()->query("SELECT COUNT(*) FROM time_entries")->fetchColumn();
check('time_entries rowcount = 4 (task-less row skipped)',
    $total === 4, "got $total");

// idempotent upsert
db_upsert_time_entries($entries, $wsId);
$total2 = (int) db()->query("SELECT COUNT(*) FROM time_entries")->fetchColumn();
check('upsert is idempotent (INSERT OR REPLACE, still 4 rows)',
    $total2 === 4, "got $total2");

// --- 3. query by range + users --------------------------------------------
echo "\n3. query\n";

// week = Mon 2026-04-20 00:00 UTC → Mon 2026-04-27 00:00 UTC
$weekStart = (new DateTimeImmutable('2026-04-20 00:00:00', new DateTimeZone('UTC')))->getTimestamp() * 1000;
$weekEnd   = (new DateTimeImmutable('2026-04-27 00:00:00', new DateTimeZone('UTC')))->getTimestamp() * 1000;

$weekRows = db_get_time_entries($wsId, [$userA, $userB], $weekStart, $weekEnd);
check('query returns 3 rows in week window (userA 2 + userB 1)',
    count($weekRows) === 3, 'got ' . count($weekRows));

// half-open window: next week's Monday entry must NOT be included
$ids = array_column($weekRows, 'id');
sort($ids);
check('next-week entry excluded by half-open end_ms',
    $ids === ['te_1','te_2','te_3'],
    'got: ' . implode(',', $ids));

// only user A
$aRows = db_get_time_entries($wsId, [$userA], $weekStart, $weekEnd);
check('filter by single user works',
    count($aRows) === 2 && array_column($aRows, 'user_id') === [$userA, $userA],
    'got: ' . json_encode(array_column($aRows, 'id'))
);

// empty user list short-circuits
check('empty user_ids returns []',
    db_get_time_entries($wsId, [], 0, PHP_INT_MAX) === []);

// result is ordered ascending
$allRows = db_get_time_entries($wsId, [$userA, $userB], 0, PHP_INT_MAX);
$starts  = array_map('intval', array_column($allRows, 'start_ms'));
$sorted  = $starts;
sort($sorted);
check('results ordered by start_ms ASC',
    $starts === $sorted,
    'got: ' . implode(',', $starts));

// wrong workspace → no rows
check('wrong workspace_id returns []',
    db_get_time_entries('other_ws', [$userA], 0, PHP_INT_MAX) === []);

// --- 4. count per weekday (sanity of the date math we'll do in endpoint) --
echo "\n4. counts per weekday (Mon/Tue only in week window)\n";
$byDow = [];
foreach ($weekRows as $r) {
    $dow = (int) gmdate('N', (int) ($r['start_ms'] / 1000)); // 1=Mon..7=Sun
    $byDow[$dow] = ($byDow[$dow] ?? 0) + 1;
}
check('Monday (dow=1) has 1 entry',  ($byDow[1] ?? 0) === 1, json_encode($byDow));
check('Tuesday (dow=2) has 2 entries', ($byDow[2] ?? 0) === 2, json_encode($byDow));

// --- Summary ---------------------------------------------------------------
echo "\n";
echo str_repeat('=', 50) . "\n";
echo "Passed: $pass\nFailed: $fail\n";
exit($fail === 0 ? 0 : 1);
