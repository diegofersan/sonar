<?php
/**
 * Background sync worker - runs as CLI process.
 * Fetches ONLY tasks assigned to the user (not all list tasks).
 *
 * Usage: CLICKUP_TOKEN=xxx php sync_worker.php <workspace_id> <list_id> <user_id> <log_id>
 * Legacy: php sync_worker.php <token> <workspace_id> <list_id> <user_id> <log_id>
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('max_execution_time', '120');

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../includes/clickup.php';
require_once __DIR__ . '/../includes/database.php';

// Token is passed via temp file (first arg is file path)
$tokenSource = $argv[1] ?? '';
$workspaceId = $argv[2] ?? '';
$listId      = $argv[3] ?? '';
$userId      = $argv[4] ?? '';
$logId       = (int) ($argv[5] ?? 0);

// Read token from file, then delete the file
$token = '';
if ($tokenSource && is_file($tokenSource)) {
    $token = trim(file_get_contents($tokenSource));
    unlink($tokenSource);
} elseif ($tokenSource) {
    // Legacy fallback: token passed directly as arg
    $token = $tokenSource;
}

if (!$token || !$workspaceId || !$listId || !$logId) {
    exit(1);
}

try {
    // Remove all assignee entries for this user so unassigned tasks disappear
    if ($userId) {
        $stmtClear = db()->prepare('DELETE FROM task_assignees WHERE user_id = ?');
        $stmtClear->execute([$userId]);
    }

    $totalTasks = 0;
    $page = 0;
    $hasMore = true;
    $taskIds = []; // track fetched task IDs
    $useListEndpoint = false;

    // Fetch only tasks assigned to this user
    // Try team-level endpoint first, fall back to list-level for guest users
    while ($hasMore) {
        if ($useListEndpoint) {
            $endpoint = "/list/{$listId}/task?"
                . "assignees[]={$userId}"
                . "&subtasks=true"
                . "&include_closed=false"
                . "&page={$page}";
        } else {
            $endpoint = "/team/{$workspaceId}/task?"
                . "assignees[]={$userId}"
                . "&list_ids[]={$listId}"
                . "&subtasks=true"
                . "&include_closed=false"
                . "&page={$page}";
        }

        $result = clickup_api_get($endpoint, $token);

        if (!$result['ok']) {
            if (!$useListEndpoint) {
                $useListEndpoint = true;
                $page = 0;
                unset($result);
                continue;
            }
            db_log_sync_end($logId, 'error', $totalTasks, $result['error'] ?? 'API error');
            exit(1);
        }

        $tasks = $result['body']['tasks'] ?? [];

        if (count($tasks) === 0 && $page === 0 && !$useListEndpoint) {
            $useListEndpoint = true;
            unset($result, $tasks);
            continue;
        }

        // ClickUp may ignore assignee filter for guest users — filter in PHP
        $tasks = array_filter($tasks, function ($t) use ($userId) {
            foreach ($t['assignees'] ?? [] as $a) {
                if ((string) ($a['id'] ?? '') === (string) $userId) return true;
            }
            return false;
        });
        $tasks = array_values($tasks);

        $count = count($tasks);

        if ($count > 0) {
            db_upsert_tasks($tasks, $workspaceId);
            foreach ($tasks as $t) {
                $taskIds[] = $t['id'];
            }
            $totalTasks += $count;
        }

        unset($result, $tasks);
        $hasMore = $count >= 100;
        $page++;
        if ($page >= 20) break;
    }

    // For each task, fetch its parent chain for breadcrumbs
    // (parents might not be assigned to this user)
    $parentIds = [];
    foreach ($taskIds as $tid) {
        $task = db_get_task($tid);
        if ($task && !empty($task['parent_id']) && !in_array($task['parent_id'], $taskIds)) {
            $parentIds[] = $task['parent_id'];
        }
    }

    // Fetch missing parent tasks (walk up the tree)
    $fetched = $taskIds;
    $toFetch = array_unique($parentIds);
    $maxDepth = 10;

    while (!empty($toFetch) && $maxDepth > 0) {
        $nextFetch = [];
        foreach ($toFetch as $pid) {
            if (in_array($pid, $fetched)) continue;

            $res = clickup_api_get("/task/{$pid}", $token);
            if ($res['ok'] && !empty($res['body'])) {
                db_upsert_task($res['body'], $workspaceId);
                $fetched[] = $pid;

                // Check if this parent also has a parent
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

    // Fetch sibling subtasks (Copy/Design) for each post the user is assigned to.
    // These may not be assigned to the user but we need them for context.
    $allFetched = $fetched;
    foreach ($taskIds as $tid) {
        $task = db_get_task($tid);
        if (!$task) continue;

        // Determine the post ID: if this is a Design/Copy subtask, post is the parent
        $taskNameLower = strtolower($task['name']);
        $isSubtask = (strpos($taskNameLower, 'design') !== false || strpos($taskNameLower, 'copy') !== false);
        $postId = $isSubtask ? $task['parent_id'] : $task['id'];
        if (!$postId) continue;

        // Fetch children of the post (Copy, Design siblings)
        $res = clickup_api_get("/task/{$postId}?include_subtasks=true", $token);
        if ($res['ok'] && !empty($res['body']['subtasks'])) {
            foreach ($res['body']['subtasks'] as $sub) {
                $subNameLower = strtolower($sub['name'] ?? '');
                $isCopy = strpos($subNameLower, 'copy') !== false;

                if ($isCopy || !in_array($sub['id'], $allFetched)) {
                    // Fetch Copy subtasks individually to get their description
                    if ($isCopy) {
                        $fullSub = clickup_api_get("/task/{$sub['id']}", $token);
                        if ($fullSub['ok'] && !empty($fullSub['body'])) {
                            db_upsert_task($fullSub['body'], $workspaceId);
                            $allFetched[] = $sub['id'];
                            continue;
                        }
                    }
                    db_upsert_task($sub, $workspaceId);
                    $allFetched[] = $sub['id'];
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

    db_log_sync_end($logId, 'success', $totalTasks);

} catch (\Throwable $e) {
    db_log_sync_end($logId, 'error', 0, $e->getMessage());
    exit(1);
}
