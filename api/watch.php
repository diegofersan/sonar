<?php
/**
 * Watch / unwatch a task.
 * POST — toggle watch status for a task.
 */
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    require_once __DIR__ . '/../includes/session.php';
    require_once __DIR__ . '/../includes/database.php';

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

    require_csrf();

    $workspace = get_workspace();
    if (!$workspace) {
        http_response_code(400);
        echo json_encode(['error' => 'No workspace selected']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $taskId = $input['task_id'] ?? null;
    $action = $input['action'] ?? null;

    if (empty($taskId) || !in_array($action, ['watch', 'unwatch'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid task_id / action']);
        exit;
    }

    $workspaceId = $workspace['id'];

    if ($action === 'watch') {
        db_watch_task($taskId, $workspaceId);
    } else {
        db_unwatch_task($taskId, $workspaceId);
    }

    echo json_encode([
        'success' => true,
        'watched' => $action === 'watch',
    ]);

} catch (\Throwable $e) {
    error_log('Sonar watch error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An internal error occurred']);
}
