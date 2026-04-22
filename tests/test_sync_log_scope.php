<?php
/**
 * F01 — Verify db_log_sync_start / db_get_last_sync scope filtering
 * so the tasks and time_entries syncs don't collide.
 *
 * Exit 0 on success, 1 on failure.
 * Usage: php tests/test_sync_log_scope.php
 */

declare(strict_types=1);

$tmpDb = tempnam(sys_get_temp_dir(), 'sonar_test_') . '.sqlite';
$GLOBALS['SONAR_DB_PATH'] = $tmpDb;
require_once __DIR__ . '/../includes/database.php';
register_shutdown_function(function () use ($tmpDb) {
    foreach ([$tmpDb, $tmpDb . '-wal', $tmpDb . '-shm'] as $f) {
        if (is_file($f)) @unlink($f);
    }
});

db(); // triggers migrate

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $details = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ $label\n"; }
    else     { $fail++; echo "  ✗ $label" . ($details !== '' ? " — $details" : '') . "\n"; }
}

$ws  = 'ws1';
$uid = '473908';

// --- default scope is 'tasks' ---------------------------------------------
$logA = db_log_sync_start($ws, $uid, 'listX'); // no scope arg
$row  = db()->query("SELECT scope FROM sync_log WHERE id = $logA")->fetch();
check("default scope when omitted is 'tasks'",
    ($row['scope'] ?? null) === 'tasks',
    var_export($row['scope'] ?? null, true));
db_log_sync_end($logA, 'success', 7);

// --- explicit 'time_entries' scope ----------------------------------------
$logB = db_log_sync_start($ws, $uid, null, 'time_entries');
$row  = db()->query("SELECT scope FROM sync_log WHERE id = $logB")->fetch();
check("explicit 'time_entries' scope is persisted",
    ($row['scope'] ?? null) === 'time_entries');
db_log_sync_end($logB, 'success', 30);

// --- db_get_last_sync isolates by scope -----------------------------------
$tasksLast = db_get_last_sync($ws, null, $uid, 'tasks');
check("last sync with scope='tasks' finds the tasks row",
    $tasksLast !== null && (int) $tasksLast['id'] === $logA,
    'got id ' . ($tasksLast['id'] ?? 'null'));

$teLast = db_get_last_sync($ws, null, $uid, 'time_entries');
check("last sync with scope='time_entries' finds the time_entries row",
    $teLast !== null && (int) $teLast['id'] === $logB);

// --- running-filter SQL the endpoints use ---------------------------------
$logC = db_log_sync_start($ws, $uid, null, 'time_entries');
$stmt = db()->prepare(
    "SELECT id FROM sync_log
     WHERE workspace_id = ? AND scope = 'tasks'
       AND status = 'running' LIMIT 1"
);
$stmt->execute([$ws]);
check("tasks-scoped running check does NOT see a running time_entries sync",
    $stmt->fetch() === false);

$stmt = db()->prepare(
    "SELECT id FROM sync_log
     WHERE workspace_id = ? AND scope = 'time_entries'
       AND status = 'running' LIMIT 1"
);
$stmt->execute([$ws]);
$row = $stmt->fetch();
check("time_entries-scoped running check sees its own running sync",
    $row !== false && (int) $row['id'] === $logC);

echo "\nPassed: $pass\nFailed: $fail\n";
exit($fail === 0 ? 0 : 1);
