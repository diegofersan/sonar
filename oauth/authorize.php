<?php
/**
 * Redirect the user to ClickUp's OAuth authorization page.
 */

require_once __DIR__ . '/../config.php';

if (empty(CLICKUP_CLIENT_ID)) {
    http_response_code(500);
    echo 'CLICKUP_CLIENT_ID is not configured. Check config.php or config.local.php.';
    exit;
}

$params = http_build_query([
    'client_id'    => CLICKUP_CLIENT_ID,
    'redirect_uri' => CLICKUP_REDIRECT_URI,
]);

$authorizeUrl = CLICKUP_AUTH_URL . '?' . $params;

header('Location: ' . $authorizeUrl);
exit;
