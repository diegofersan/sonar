<?php
/**
 * API endpoint -- returns the authenticated user's ClickUp workspaces as JSON.
 */

// Ensure no HTML errors leak into JSON response
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED);
header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../includes/session.php';
    require_once __DIR__ . '/../includes/clickup.php';

    init_session();

    if (!is_authenticated()) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }

    $token  = get_token();
    $result = clickup_get_workspaces($token);

    if (!$result['ok']) {
        http_response_code($result['status'] ?: 502);
        echo json_encode([
            'error' => $result['error'] ?? 'Failed to fetch workspaces',
            'status' => $result['status'],
        ]);
        exit;
    }

    // ClickUp returns { teams: [...] }
    $teams = $result['body']['teams'] ?? [];
    echo json_encode(['workspaces' => $teams]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
