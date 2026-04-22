<?php
/**
 * Session management helpers for ClickUp OAuth Dashboard
 */

/**
 * Start session if not already started, with hardened cookie settings.
 */
function init_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => true,
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    // Absolute timeout: destroy sessions older than 8 hours
    if (isset($_SESSION['created_at']) && (time() - $_SESSION['created_at']) > 28800) {
        clear_session();
        return;
    }

    // Inactivity timeout: destroy sessions idle for more than 2 hours
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 7200) {
        clear_session();
        return;
    }

    // Set created_at on first session creation
    if (!isset($_SESSION['created_at'])) {
        $_SESSION['created_at'] = time();
    }

    // Update last activity timestamp
    $_SESSION['last_activity'] = time();
}

/**
 * Check whether the current session holds a valid access token.
 */
function is_authenticated(): bool
{
    init_session();
    return !empty($_SESSION['clickup_token']);
}

/**
 * Return the stored access token, or null.
 */
function get_token(): ?string
{
    init_session();
    return $_SESSION['clickup_token'] ?? null;
}

/**
 * Store access token and user info in the session.
 *
 * @param string $token  ClickUp access token
 * @param array  $user   User info array from ClickUp API
 */
function set_auth(string $token, array $user): void
{
    init_session();
    session_regenerate_id(true);
    $_SESSION['clickup_token'] = $token;
    $_SESSION['clickup_user']  = $user;
}

/**
 * Return user info stored in the session, or null.
 */
function get_user(): ?array
{
    init_session();
    return $_SESSION['clickup_user'] ?? null;
}

/**
 * Store the currently selected workspace.
 */
function set_workspace(string $workspace_id, string $workspace_name): void
{
    init_session();
    $_SESSION['clickup_workspace'] = [
        'id'   => $workspace_id,
        'name' => $workspace_name,
    ];
}

/**
 * Return the selected workspace, or null.
 */
function get_workspace(): ?array
{
    init_session();
    return $_SESSION['clickup_workspace'] ?? null;
}

/**
 * Destroy the session completely.
 */
function clear_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

/**
 * Return the CSRF token for the current session, generating one if needed.
 */
function csrf_token(): string
{
    init_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a CSRF token against the session token.
 */
function validate_csrf(?string $token): bool
{
    init_session();
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Is the authenticated user the department head? (F01 — Colaboradores).
 *
 * Server-side gate for the admin-only "Colaboradores" view and its endpoints.
 * Cast both sides to string because ClickUp returns user.id as int but the
 * configured constant is a string.
 */
function is_department_head(): bool
{
    $user = get_user();
    if (empty($user['id'])) {
        return false;
    }
    $headId = defined('DEPARTMENT_HEAD_USER_ID') ? DEPARTMENT_HEAD_USER_ID : '';
    if ($headId === '') {
        return false;
    }
    return (string) $user['id'] === (string) $headId;
}

/**
 * Weekly hours configured for a given ClickUp user_id (F01).
 *
 * Looks up WEEKLY_HOURS_PER_USER; falls back to DEFAULT_WEEKLY_HOURS (40 if
 * unset) for user_ids not in the map.
 */
function get_weekly_hours(string $user_id): int
{
    $map = defined('WEEKLY_HOURS_PER_USER') ? WEEKLY_HOURS_PER_USER : [];
    if ($user_id !== '' && isset($map[$user_id])) {
        return (int) $map[$user_id];
    }
    return defined('DEFAULT_WEEKLY_HOURS') ? (int) DEFAULT_WEEKLY_HOURS : 40;
}

/**
 * Daily task-count target configured for a given ClickUp user_id (F02).
 *
 * Looks up DAILY_TASKS_PER_USER; falls back to DEFAULT_DAILY_TASKS (5 if unset)
 * for user_ids not in the map. A user explicitly mapped to 0 returns 0 (not
 * the default) — useful to mark non-producing members without removing them
 * from the group.
 */
function get_daily_tasks_target(string $user_id): int
{
    $map = defined('DAILY_TASKS_PER_USER') ? DAILY_TASKS_PER_USER : [];
    if ($user_id !== '' && isset($map[$user_id])) {
        return (int) $map[$user_id];
    }
    return defined('DEFAULT_DAILY_TASKS') ? (int) DEFAULT_DAILY_TASKS : 5;
}

/**
 * Read and validate the CSRF token from the X-CSRF-Token header or JSON body
 * field `_csrf`. Sends 403 and exits if validation fails.
 */
function require_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (empty($token)) {
        $body = json_decode(file_get_contents('php://input'), true);
        $token = $body['_csrf'] ?? null;
    }

    if (!validate_csrf($token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid or missing CSRF token.']);
        exit;
    }
}
