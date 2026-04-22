<?php
/**
 * F02 — Test get_daily_tasks_target() helper.
 *
 * Exit 0 on success, 1 on failure.
 * Usage: php tests/test_daily_tasks_target.php
 */

declare(strict_types=1);

// Pre-define constants so config.php's local-load does not override them.
define('DEPARTMENT_HEAD_USER_ID', '473908');
define('DAILY_TASKS_PER_USER', [
    '473908'  => 5,
    '4727509' => 3,
    '99999'   => 0, // intentional zero — not the same as "unset"
]);
define('DEFAULT_DAILY_TASKS', 4);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $details = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ $label\n"; }
    else     { $fail++; echo "  ✗ $label" . ($details !== '' ? " — $details" : '') . "\n"; }
}

$t1 = get_daily_tasks_target('473908');
check('mapped user returns configured value (5)', $t1 === 5, "got $t1");

$t2 = get_daily_tasks_target('4727509');
check('second mapped user (3)', $t2 === 3, "got $t2");

$t3 = get_daily_tasks_target('12345');
check('unmapped user falls back to default (4)', $t3 === 4, "got $t3");

$t4 = get_daily_tasks_target('');
check('empty user_id falls back to default (4)', $t4 === 4, "got $t4");

$t5 = get_daily_tasks_target('99999');
check('user mapped to 0 returns 0 (not default)', $t5 === 0, "got $t5");

echo "\nPassed: $pass\nFailed: $fail\n";
exit($fail === 0 ? 0 : 1);
