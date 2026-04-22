<?php
/**
 * F01 — Test get_weekly_hours() helper.
 *
 * Exit 0 on success, 1 on failure.
 * Usage: php tests/test_weekly_hours.php
 */

declare(strict_types=1);

// Pre-define constants so config.php's local-load does not override them.
define('DEPARTMENT_HEAD_USER_ID', '473908');
define('WEEKLY_HOURS_PER_USER', [
    '473908'  => 40,
    '4727509' => 30,
    '99999'   => 0, // intentional zero — not the same as "unset"
]);
define('DEFAULT_WEEKLY_HOURS', 35);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $details = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ $label\n"; }
    else     { $fail++; echo "  ✗ $label" . ($details !== '' ? " — $details" : '') . "\n"; }
}

$h1 = get_weekly_hours('473908');
check('mapped user returns configured value (40)', $h1 === 40, "got $h1");

$h2 = get_weekly_hours('4727509');
check('second mapped user (30)', $h2 === 30, "got $h2");

$h3 = get_weekly_hours('12345');
check('unmapped user falls back to default (35)', $h3 === 35, "got $h3");

$h4 = get_weekly_hours('');
check('empty user_id falls back to default (35)', $h4 === 35, "got $h4");

$h5 = get_weekly_hours('99999');
check('user mapped to 0 returns 0 (not default)', $h5 === 0, "got $h5");

echo "\nPassed: $pass\nFailed: $fail\n";
exit($fail === 0 ? 0 : 1);
