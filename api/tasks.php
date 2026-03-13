<?php
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED);
header('Content-Type: application/json; charset=utf-8');

function score_due_date($dueDateMs, $maxPoints) {
    if (!$dueDateMs) return 0;
    $now = time() * 1000;
    $diffDays = ($dueDateMs - $now) / (1000 * 60 * 60 * 24);

    if ($diffDays <= -7) return $maxPoints;              // overdue 7+ days
    if ($diffDays <= 0)  return $maxPoints * 0.8 + ($maxPoints * 0.2 * (1 - $diffDays / -7)); // overdue 0-7 days
    if ($diffDays <= 3)  return $maxPoints * 0.6 + ($maxPoints * 0.2 * (1 - $diffDays / 3));  // due in 0-3 days
    if ($diffDays <= 7)  return $maxPoints * 0.4 + ($maxPoints * 0.2 * (1 - ($diffDays - 3) / 4)); // 4-7 days
    if ($diffDays <= 14) return $maxPoints * 0.2 + ($maxPoints * 0.2 * (1 - ($diffDays - 7) / 7)); // 8-14 days
    if ($diffDays <= 30) return $maxPoints * 0.1 * (1 - ($diffDays - 14) / 16); // 15-30 days
    return 0;
}

function calculate_urgency($task) {
    // Design due date: 0-50 points
    $designScore = score_due_date($task['due_date'] ?? null, 50);

    // Priority: 0-30 points
    $priorityMap = [1 => 30, 2 => 20, 3 => 10, 4 => 0];
    $priorityScore = $priorityMap[$task['priority_id'] ?? 0] ?? 5;

    // Parent/post due date: 0-20 points
    $parentScore = score_due_date($task['post_due_date'] ?? null, 20);

    return (int) round($designScore + $priorityScore + $parentScore);
}

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
    $user = get_user();

    if (!$workspace) {
        http_response_code(400);
        echo json_encode(['error' => 'No workspace selected']);
        exit;
    }

    $workspaceId = $workspace['id'];
    $userId = $user['id'] ?? null;

    if (!$userId) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID not found in session']);
        exit;
    }

    $listId = $_GET['list_id'] ?? null;
    $tasks = db_get_user_tasks($workspaceId, $userId, $listId);

    // Enrich each task: find the post it belongs to and the linha editorial
    $enrichedTasks = [];
    $seenPostIds = [];
    foreach ($tasks as $task) {
        $ancestors = db_get_task_ancestors($task['id']);

        // Determine linha editorial (first ancestor that contains "linha editorial")
        $linhaEditorial = null;
        $leCancelled = false;
        foreach ($ancestors as $a) {
            if (stripos($a['name'], 'linha editorial') !== false) {
                $linhaEditorial = $a['name'];
            }
            // Check if any ancestor has cancelled LE status
            if (strtolower(trim($a['status_name'] ?? '')) === 'linha editorial cancelada') {
                $leCancelled = true;
            }
        }
        // Fallback: first ancestor
        if (!$linhaEditorial && !empty($ancestors)) {
            $linhaEditorial = $ancestors[0]['name'];
        }

        // Skip tasks from cancelled Linhas Editoriais
        if ($leCancelled) continue;

        // Skip published/pending tasks
        $taskStatus = strtolower(trim($task['status_name'] ?? ''));
        if ($taskStatus === 'published' || $taskStatus === 'pending') continue;

        // Determine if this task IS a post or a subtask of a post (Copy/Design)
        $parent = $task['parent_id'] ? db_get_task($task['parent_id']) : null;
        $taskNameLower = strtolower($task['name']);
        $isSubtask = $parent && (
            strpos($taskNameLower, 'design') !== false ||
            strpos($taskNameLower, 'copy') !== false
        );

        if ($isSubtask) {
            $task['post_name'] = $parent['name'];
            $task['post_id'] = $parent['id'];
            $task['task_role'] = $task['name'];
            $task['post_due_date'] = $parent['due_date'];
            $task['post_status'] = $parent['status_name'];
            $task['post_url'] = $parent['url'];
            // Use post status for classification (not the subtask's own status)
            $task['status_name'] = $parent['status_name'];
        } else {
            $task['post_name'] = $task['name'];
            $task['post_id'] = $task['id'];
            $task['task_role'] = null;
            $task['post_due_date'] = $task['due_date'];
            $task['post_status'] = $task['status_name'];
            $task['post_url'] = $task['url'];

            // If this post has a Design/Copy subtask, use that subtask's due_date instead
            $children = db_get_task_children($task['id']);
            foreach ($children as $child) {
                $childName = strtolower($child['name']);
                if (strpos($childName, 'design') !== false || strpos($childName, 'copy') !== false) {
                    if (!empty($child['due_date'])) {
                        $task['due_date'] = $child['due_date'];
                        break;
                    }
                }
            }
        }

        // Deduplicate: only one entry per post
        if (in_array($task['post_id'], $seenPostIds)) continue;
        $seenPostIds[] = $task['post_id'];

        $task['linha_editorial'] = $linhaEditorial;

        // Find the Copy subtask and check if it has content
        $postChildrenId = $isSubtask ? $task['parent_id'] : $task['id'];
        $postChildren = $postChildrenId ? db_get_task_children($postChildrenId) : [];

        $task['copy_ready'] = false;
        $task['subtasks'] = [];

        foreach ($postChildren as $child) {
            $childNameLower = strtolower($child['name']);

            // Check if Copy has text in description
            if (strpos($childNameLower, 'copy') !== false) {
                $task['copy_ready'] = !empty(trim($child['description'] ?? ''));
            }

            // Add as subtask context (excluding current task)
            if ($child['id'] !== $task['id']) {
                $task['subtasks'][] = [
                    'id' => $child['id'],
                    'name' => $child['name'],
                    'status_name' => $child['status_name'],
                    'due_date' => $child['due_date'],
                    'assignees' => json_decode($child['assignees'] ?: '[]', true),
                ];
            }
        }

        // Calculate urgency score (0-100, higher = more urgent)
        $task['urgency_score'] = calculate_urgency($task);

        $enrichedTasks[] = $task;
    }

    // Sort by urgency score descending (most urgent first)
    usort($enrichedTasks, function ($a, $b) {
        return $b['urgency_score'] - $a['urgency_score'];
    });

    $lastSync = db_get_last_sync($workspaceId);

    echo json_encode([
        'tasks' => $enrichedTasks,
        'total' => count($enrichedTasks),
        'last_sync' => $lastSync ? ($lastSync['completed_at'] ?? null) : null,
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
