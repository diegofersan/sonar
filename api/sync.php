<?php
/**
 * Sync endpoint.
 * POST = start sync (responds immediately, continues processing in background)
 * GET  = check sync status for polling
 */
ini_set('display_errors', '0');
ini_set('max_execution_time', '120');
error_reporting(E_ALL & ~E_DEPRECATED);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    require_once __DIR__ . '/../includes/session.php';
    require_once __DIR__ . '/../includes/database.php';
    require_once __DIR__ . '/../includes/clickup.php';

    init_session();

    if (!is_authenticated()) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $workspace = get_workspace();
        $user = get_user();
        if (!$workspace) {
            echo json_encode(['status' => 'no_workspace']);
            exit;
        }
        $currentUserId = $user['id'] ?? null;
        $lastSync = db_get_last_sync($workspace['id'], null, $currentUserId, 'tasks');
        $stmt = db()->prepare(
            "SELECT * FROM sync_log WHERE workspace_id = ? AND user_id = ? AND scope = 'tasks' AND status = 'running' ORDER BY started_at DESC LIMIT 1"
        );
        $stmt->execute([$workspace['id'], $currentUserId]);
        $running = $stmt->fetch();

        echo json_encode([
            'running' => $running ? true : false,
            'started_at' => $running ? $running['started_at'] : null,
            'progress' => $running ? ($running['progress'] ?? null) : null,
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

    $rawBody = file_get_contents('php://input');
    $input = json_decode($rawBody, true) ?: [];

    // CSRF validation
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

    // Mark stale syncs (running > 5 min) as failed — scoped to tasks so we
    // don't touch a concurrent time_entries sync.
    $staleTimeout = time() - 300;
    $stmtStale = db()->prepare(
        "UPDATE sync_log SET status = 'error', error_message = 'Timeout' WHERE workspace_id = ? AND scope = 'tasks' AND status = 'running' AND started_at < ?"
    );
    $stmtStale->execute([$workspaceId, $staleTimeout]);

    // Force sync: cancel any running tasks sync (not time_entries)
    $force = !empty($input['force']);
    $stmtRunning = db()->prepare(
        "SELECT id FROM sync_log WHERE workspace_id = ? AND scope = 'tasks' AND status = 'running' LIMIT 1"
    );
    $stmtRunning->execute([$workspaceId]);
    if ($stmtRunning->fetch()) {
        if (!$force) {
            http_response_code(429);
            echo json_encode(['error' => 'A sync is already running. Please wait or force a new sync.']);
            exit;
        }
        $stmtCancel = db()->prepare(
            "UPDATE sync_log SET status = 'cancelled', error_message = 'Cancelled by user' WHERE workspace_id = ? AND scope = 'tasks' AND status = 'running'"
        );
        $stmtCancel->execute([$workspaceId]);
    }

    $listId = $input['list_id'] ?? '46726233';
    $userId = $user['id'] ?? '';

    $logId = db_log_sync_start($workspaceId, $userId, $listId, 'tasks');

    // Release session lock so polling requests aren't blocked
    session_write_close();

    // Send response immediately, then continue processing
    ignore_user_abort(true);

    echo json_encode([
        'success' => true,
        'message' => 'Sync started',
        'log_id' => $logId,
    ]);

    // Flush output to client
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        header('Connection: close');
        header('Content-Length: ' . ob_get_length());
        ob_end_flush();
        flush();
    }

    // ---- Client has received the response. Now do the sync inline. ----

    // Remove all assignee entries for this user so unassigned tasks disappear
    $stmtClear = db()->prepare('DELETE FROM task_assignees WHERE user_id = ?');
    $stmtClear->execute([$userId]);

    db_sync_progress($logId, 'A importar tarefas...');

    $totalTasks = 0;
    $page = 0;
    $hasMore = true;
    $taskIds = [];

    while ($hasMore) {
        // Try team endpoint with assignee filter first
        $endpoint = "/team/{$workspaceId}/task?"
            . "assignees[]={$userId}"
            . "&list_ids[]={$listId}"
            . "&subtasks=true"
            . "&include_closed=false"
            . "&page={$page}";

        $result = clickup_api_get($endpoint, $token);

        // If team endpoint fails (common for guest users), try list endpoint
        if (!$result['ok']) {
            $endpoint = "/list/{$listId}/task?"
                . "assignees[]={$userId}"
                . "&subtasks=true"
                . "&include_closed=false"
                . "&page={$page}";

            $result = clickup_api_get($endpoint, $token);

            if (!$result['ok']) {
                db_log_sync_end($logId, 'error', $totalTasks, $result['error'] ?? 'API error');
                exit;
            }
        }

        $tasks = $result['body']['tasks'] ?? [];
        $count = count($tasks);

        if ($count > 0) {
            db_upsert_tasks($tasks, $workspaceId);
            foreach ($tasks as $t) {
                $taskIds[] = $t['id'];
            }
            $totalTasks += $count;
            db_sync_progress($logId, "A importar tarefas... ({$totalTasks})");
        }

        unset($result, $tasks);
        $hasMore = $count >= 100;
        $page++;
        if ($page >= 20) break;
    }

    // Fetch parent chain for breadcrumbs (skip already cached parents)
    db_sync_progress($logId, 'A carregar contexto...');

    $parentIds = [];
    foreach ($taskIds as $tid) {
        $task = db_get_task($tid);
        if ($task && !empty($task['parent_id']) && !in_array($task['parent_id'], $taskIds)) {
            $parentIds[] = $task['parent_id'];
        }
    }

    $fetched = $taskIds;
    $toFetch = array_unique($parentIds);
    $maxDepth = 10;
    $parentCount = 0;

    while (!empty($toFetch) && $maxDepth > 0) {
        $nextFetch = [];
        foreach ($toFetch as $pid) {
            if (in_array($pid, $fetched)) continue;

            // Skip if already in DB
            $existing = db_get_task($pid);
            if ($existing) {
                $fetched[] = $pid;
                if (!empty($existing['parent_id']) && !in_array($existing['parent_id'], $fetched)) {
                    $nextFetch[] = $existing['parent_id'];
                }
                continue;
            }

            $res = clickup_api_get("/task/{$pid}", $token);
            if ($res['ok'] && !empty($res['body'])) {
                db_upsert_task($res['body'], $workspaceId);
                $fetched[] = $pid;
                $parentCount++;
                db_sync_progress($logId, "A carregar contexto... ({$parentCount})");

                $pp = $res['body']['parent'] ?? null;
                if ($pp && !in_array($pp, $fetched)) {
                    $nextFetch[] = $pp;
                }
            }
            unset($res);
        }
        $toFetch = array_unique($nextFetch);
        $maxDepth--;
    }

    // Fetch sibling subtasks (Copy/Design) — deduplicate by post ID
    db_sync_progress($logId, 'A carregar subtarefas...');

    $allFetched = $fetched;
    $processedPosts = [];
    $siblingCount = 0;

    foreach ($taskIds as $tid) {
        $task = db_get_task($tid);
        if (!$task) continue;

        $taskNameLower = strtolower($task['name']);
        $isSubtask = (strpos($taskNameLower, 'design') !== false || strpos($taskNameLower, 'copy') !== false);
        $postId = $isSubtask ? $task['parent_id'] : $task['id'];
        if (!$postId || in_array($postId, $processedPosts)) continue;
        $processedPosts[] = $postId;
        db_sync_progress($logId, 'A carregar subtarefas... (' . count($processedPosts) . ')');

        $res = clickup_api_get("/task/{$postId}?include_subtasks=true", $token);
        if ($res['ok'] && !empty($res['body']['subtasks'])) {
            foreach ($res['body']['subtasks'] as $sub) {
                $subNameLower = strtolower($sub['name'] ?? '');
                $isCopy = strpos($subNameLower, 'copy') !== false;

                if ($isCopy || !in_array($sub['id'], $allFetched)) {
                    if ($isCopy) {
                        $fullSub = clickup_api_get("/task/{$sub['id']}", $token);
                        if ($fullSub['ok'] && !empty($fullSub['body'])) {
                            db_upsert_task($fullSub['body'], $workspaceId);
                            $allFetched[] = $sub['id'];
                            $siblingCount++;
                            continue;
                        }
                    }
                    db_upsert_task($sub, $workspaceId);
                    $allFetched[] = $sub['id'];
                    $siblingCount++;
                }
            }
        }
        unset($res);
    }


    // Save list config
    $stmt = db()->prepare('SELECT list_name FROM tasks WHERE list_id = ? LIMIT 1');
    $stmt->execute([$listId]);
    $row = $stmt->fetch();
    db_save_list_config($listId, $row ? $row['list_name'] : 'Content', $workspaceId);

    db_cleanup_notifications($workspaceId);

    db_log_sync_end($logId, 'success', $totalTasks);

} catch (\Throwable $e) {
    error_log('Sonar sync error: ' . $e->getMessage());
    if (isset($logId)) {
        db_log_sync_end($logId, 'error', 0, $e->getMessage());
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'An internal error occurred']);
    }
}
