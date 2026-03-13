<?php
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

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['workspace_id'])) {
    // Clear workspace selection
    if ($input['workspace_id'] === null) {
        $_SESSION['clickup_workspace'] = null;
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Missing workspace_id']);
    exit;
}

set_workspace($input['workspace_id'], $input['workspace_name'] ?? 'Workspace');

header('Content-Type: application/json');
echo json_encode(['success' => true]);
