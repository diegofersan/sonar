<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/session.php';
init_session();

if (!is_authenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Read body once (php://input can only be read once)
$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);

// CSRF validation for state-changing request
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_csrf'] ?? null);
if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or missing CSRF token.']);
    exit;
}

if (empty($input['workspace_id'])) {
    // Clear workspace selection
    if (($input['workspace_id'] ?? false) === null) {
        $_SESSION['clickup_workspace'] = null;
        echo json_encode(['success' => true]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Missing workspace_id']);
    exit;
}

set_workspace($input['workspace_id'], $input['workspace_name'] ?? 'Workspace');

echo json_encode(['success' => true]);
