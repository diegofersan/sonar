<?php
/**
 * OAuth callback -- exchange the authorization code for an access token,
 * fetch user info, store both in the session, then redirect to the dashboard.
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/clickup.php';

init_session();

// Verify the state parameter to prevent CSRF attacks
$state = $_GET['state'] ?? null;
$expectedState = $_SESSION['oauth_state'] ?? null;

if (empty($state) || empty($expectedState) || !hash_equals($expectedState, $state)) {
    unset($_SESSION['oauth_state']);
    header('Location: ../login.php?error=' . urlencode('Invalid OAuth state. Please try again.'));
    exit;
}

// State validated -- remove it so it cannot be reused
unset($_SESSION['oauth_state']);

// ClickUp sends the code as a query parameter
$code = $_GET['code'] ?? null;

if (empty($code)) {
    $errorMsg = isset($_GET['error']) ? htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8') : 'No authorization code received.';
    header('Location: ../login.php?error=' . urlencode($errorMsg));
    exit;
}

// Validate the code format (alphanumeric string)
if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $code)) {
    header('Location: ../login.php?error=' . urlencode('Invalid authorization code.'));
    exit;
}

// Exchange code for token
$tokenResult = clickup_exchange_token($code);

if (!$tokenResult['ok']) {
    $error = $tokenResult['error'] ?? 'Token exchange failed.';
    header('Location: ../login.php?error=' . urlencode($error));
    exit;
}

$token = $tokenResult['token'];

// Fetch user profile
$userResult = clickup_get_user($token);

$user = [];
if ($userResult['ok'] && isset($userResult['body']['user'])) {
    $user = $userResult['body']['user'];
}

// Store in session
set_auth($token, $user);

// Fetch workspaces and auto-select if only one
$wsResult = clickup_get_workspaces($token);
$workspaces = [];
if ($wsResult['ok']) {
    $workspaces = $wsResult['body']['teams'] ?? $wsResult['body'] ?? [];
}

if (count($workspaces) === 1) {
    // Only one workspace — select it automatically
    set_workspace($workspaces[0]['id'], $workspaces[0]['name']);
    header('Location: /dashboard.php');
} else {
    // Multiple workspaces — show selector
    header('Location: /workspace.php');
}
exit;
