<?php
/**
 * Sync endpoint - launches background sync and returns immediately.
 * The actual sync runs as a CLI process.
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

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // GET = check sync status
        $workspace = get_workspace();
        if (!$workspace) {
            echo json_encode(['status' => 'no_workspace']);
            exit;
        }
        $lastSync = db_get_last_sync($workspace['id']);
        // Check if there's a running sync
        $stmt = db()->prepare(
            "SELECT * FROM sync_log WHERE workspace_id = ? AND status = 'running' ORDER BY started_at DESC LIMIT 1"
        );
        $stmt->execute([$workspace['id']]);
        $running = $stmt->fetch();

        echo json_encode([
            'running' => $running ? true : false,
            'started_at' => $running ? $running['started_at'] : null,
            'last_sync' => $lastSync ? $lastSync['completed_at'] : null,
            'last_count' => $lastSync ? $lastSync['task_count'] : null,
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    // Read body once (php://input can only be read once)
    $rawBody = file_get_contents('php://input');
    $input = json_decode($rawBody, true) ?: [];

    // CSRF validation for state-changing request
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_csrf'] ?? null);
    if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid or missing CSRF token.']);
        exit;
    }

    $token = get_token();
    $workspace = get_workspace();
    $user = get_user();

    if (!$workspace) {
        http_response_code(400);
        echo json_encode(['error' => 'No workspace selected']);
        exit;
    }

    $workspaceId = $workspace['id'];

    // Mark stale syncs (running > 5 min) as failed
    $staleTimeout = time() - 300;
    $stmtStale = db()->prepare(
        "UPDATE sync_log SET status = 'error', error_message = 'Timeout' WHERE workspace_id = ? AND status = 'running' AND started_at < ?"
    );
    $stmtStale->execute([$workspaceId, $staleTimeout]);

    // Force sync: cancel any running sync
    $force = !empty($input['force']);
    $stmtRunning = db()->prepare(
        "SELECT id FROM sync_log WHERE workspace_id = ? AND status = 'running' LIMIT 1"
    );
    $stmtRunning->execute([$workspaceId]);
    if ($stmtRunning->fetch()) {
        if (!$force) {
            http_response_code(429);
            echo json_encode(['error' => 'A sync is already running. Please wait or force a new sync.']);
            exit;
        }
        // Cancel all running syncs
        $stmtCancel = db()->prepare(
            "UPDATE sync_log SET status = 'cancelled', error_message = 'Cancelled by user' WHERE workspace_id = ? AND status = 'running'"
        );
        $stmtCancel->execute([$workspaceId]);
    }
    $listId = $input['list_id'] ?? '46726233';
    $userId = $user['id'] ?? '';

    // Create sync log entry
    $logId = db_log_sync_start($workspaceId, $userId, $listId);

    // Launch background sync process — pass token via environment variable
    $phpBin = PHP_BINARY;
    $script = __DIR__ . '/sync_worker.php';
    $cmd = sprintf(
        'CLICKUP_TOKEN=%s %s %s %s %s %s %d > /dev/null 2>&1 &',
        escapeshellarg($token),
        escapeshellarg($phpBin),
        escapeshellarg($script),
        escapeshellarg($workspaceId),
        escapeshellarg($listId),
        escapeshellarg($userId),
        $logId
    );

    exec($cmd);

    echo json_encode([
        'success' => true,
        'message' => 'Sync started in background',
        'log_id' => $logId,
    ]);

} catch (\Throwable $e) {
    error_log('Sonar error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An internal error occurred']);
}
