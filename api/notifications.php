<?php
/**
 * Notifications endpoint.
 * GET  — list notifications or get unread count.
 * POST — mark notifications as read.
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

    $workspace = get_workspace();
    if (!$workspace) {
        http_response_code(400);
        echo json_encode(['error' => 'No workspace selected']);
        exit;
    }

    $workspaceId = $workspace['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['unread_count'])) {
            echo json_encode(['count' => db_get_unread_count($workspaceId)]);
        } else {
            echo json_encode(['notifications' => db_get_notifications($workspaceId)]);
        }
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $input['action'] ?? null;

        if ($action === 'mark_read') {
            $id = isset($input['id']) ? (int) $input['id'] : null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing notification id']);
                exit;
            }
            db_mark_notification_read($id);
            echo json_encode(['success' => true]);
        } elseif ($action === 'mark_all_read') {
            db_mark_all_notifications_read($workspaceId);
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (\Throwable $e) {
    error_log('Sonar notifications error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An internal error occurred']);
}
