<?php
/**
 * F01 — Test is_department_head() helper.
 *
 * Exit 0 on success, 1 on failure.
 * Usage: php tests/test_is_department_head.php
 */

declare(strict_types=1);

// Pre-define constants so config.php's local-load does not override them.
define('DEPARTMENT_HEAD_USER_ID', '473908');
// Stub the other F01 constants too, to stay isolated from config.local.php.
define('WEEKLY_HOURS_PER_USER', []);
define('DEFAULT_WEEKLY_HOURS', 40);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session.php';

// Bypass init_session()'s session_set_cookie_params(secure=true) dance in CLI
// by starting the session ourselves first. init_session() then sees STATUS=ACTIVE
// and skips its setup branch.
@session_start();

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $details = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ $label\n"; }
    else     { $fail++; echo "  ✗ $label" . ($details !== '' ? " — $details" : '') . "\n"; }
}

// 1. Unauthenticated → false
unset($_SESSION['clickup_user']);
check('unauthenticated session → false', is_department_head() === false);

// 2. User is head, id as int (how ClickUp returns it)
$_SESSION['clickup_user'] = ['id' => 473908, 'username' => 'Diego Ferreira'];
check('user_id 473908 (int) → true', is_department_head() === true);

// 3. User is head, id as string
$_SESSION['clickup_user'] = ['id' => '473908'];
check('user_id "473908" (string) → true', is_department_head() === true);

// 4. Different user
$_SESSION['clickup_user'] = ['id' => 99999];
check('user_id 99999 → false', is_department_head() === false);

// 5. Missing id in user payload
$_SESSION['clickup_user'] = ['username' => 'ghost'];
check('user without id → false', is_department_head() === false);

echo "\nPassed: $pass\nFailed: $fail\n";
exit($fail === 0 ? 0 : 1);
