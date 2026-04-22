<?php
/**
 * Report API — returns overdue tasks across all users, grouped by linha editorial.
 *
 * GET /api/report.php
 *   Returns: { linhas_editoriais: [...], tasks: [...] }
 *
 * Only accessible by admin users (Diego Ferreira).
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
    $user = get_user();

    if (!$workspace) {
        http_response_code(400);
        echo json_encode(['error' => 'No workspace selected']);
        exit;
    }

    // Admin check: only Diego Ferreira
    $rawName = $user['username'] ?? $user['name'] ?? '';
    $isAdmin = stripos($rawName, 'diego ferreira') !== false || stripos($rawName, 'diego') !== false;
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    $workspaceId = $workspace['id'];
    $nowMs = time() * 1000;

    // Get all tasks in workspace (not filtered by user)
    $tasks = db_get_workspace_tasks($workspaceId);

    $enrichedTasks = [];
    $seenPostIds = [];
    $linhasEditoriais = [];

    foreach ($tasks as $task) {
        $ancestors = db_get_task_ancestors($task['id']);

        // Determine linha editorial
        $linhaEditorial = null;
        $leCancelled = false;
        foreach ($ancestors as $a) {
            if (stripos($a['name'], 'linha editorial') !== false) {
                $linhaEditorial = $a['name'];
            }
            if (strtolower(trim($a['status_name'] ?? '')) === 'linha editorial cancelada') {
                $leCancelled = true;
            }
        }
        if (!$linhaEditorial && !empty($ancestors)) {
            $linhaEditorial = $ancestors[0]['name'];
        }

        if ($leCancelled) continue;

        // Skip hidden statuses
        $taskStatus = strtolower(trim($task['status_name'] ?? ''));
        if (in_array($taskStatus, ['published', 'pending', 'scheduled', 'linha editorial cancelada', 'post cancelado', 'cancelado', 'cancelled'])) continue;

        // Determine if subtask
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
            $task['status_name'] = $parent['status_name'];
        } else {
            $task['post_name'] = $task['name'];
            $task['post_id'] = $task['id'];
            $task['task_role'] = null;
            $task['post_due_date'] = $task['due_date'];
            $task['post_status'] = $task['status_name'];
            $task['post_url'] = $task['url'];

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

        // Deduplicate by post
        if (in_array($task['post_id'], $seenPostIds)) continue;
        $seenPostIds[] = $task['post_id'];

        $task['linha_editorial'] = $linhaEditorial;

        // Only include overdue tasks (due_date in the past)
        $dueDate = (int)($task['due_date'] ?? 0);
        if (!$dueDate || $dueDate >= $nowMs) continue;

        // Calculate days overdue
        $task['days_overdue'] = (int) round(($nowMs - $dueDate) / (1000 * 60 * 60 * 24));

        // Get assignees from the post or subtask
        $assignees = json_decode($task['assignees'] ?: '[]', true);
        // Also check parent assignees if subtask
        if ($isSubtask && $parent) {
            $parentAssignees = json_decode($parent['assignees'] ?: '[]', true);
            $assignees = array_merge($assignees, $parentAssignees);
        }
        // Deduplicate assignees
        $seen = [];
        $uniqueAssignees = [];
        foreach ($assignees as $a) {
            $aid = $a['id'] ?? '';
            if ($aid && !isset($seen[$aid])) {
                $seen[$aid] = true;
                $uniqueAssignees[] = $a;
            }
        }
        $task['all_assignees'] = $uniqueAssignees;

        // Collect unique linhas editoriais
        if ($linhaEditorial && !in_array($linhaEditorial, $linhasEditoriais)) {
            $linhasEditoriais[] = $linhaEditorial;
        }

        $enrichedTasks[] = $task;
    }

    // Sort by days overdue descending (most overdue first)
    usort($enrichedTasks, function ($a, $b) {
        return $b['days_overdue'] - $a['days_overdue'];
    });

    sort($linhasEditoriais);

    echo json_encode([
        'linhas_editoriais' => $linhasEditoriais,
        'tasks' => $enrichedTasks,
        'total' => count($enrichedTasks),
    ]);

} catch (\Throwable $e) {
    error_log('Sonar report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An internal error occurred']);
}
