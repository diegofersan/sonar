<?php
/**
 * Session management helpers for ClickUp OAuth Dashboard
 */

/**
 * Start session if not already started.
 */
function init_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
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
    init_session();
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
