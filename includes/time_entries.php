<?php
/**
 * F03 — Pure helpers for time-entries post-context resolution.
 *
 * Kept separate from workload.php so the parent-resolution flow is easy to
 * unit-test without loading DB/HTTP code.
 */

declare(strict_types=1);

/**
 * True if the task name looks like an editorial subtask — i.e. its parent is
 * probably a post. Case-insensitive + unicode-safe, same rule used by
 * api/tasks.php:109-149 when deciding whether to bubble up the parent title
 * in the Linha Editorial view.
 */
function time_entry_is_subtask_candidate(?string $name): bool
{
    if ($name === null || $name === '') return false;
    $lower = mb_strtolower($name, 'UTF-8');
    return (mb_strpos($lower, 'design') !== false)
        || (mb_strpos($lower, 'copy')   !== false);
}

/**
 * Build the [task_id => {parent_task_id, parent_name}] map from a batch of
 * raw ClickUp time entries. Only looks up tasks whose name matches the
 * Design/Copy pattern — everything else stays out of the map (no parent).
 *
 * The two ClickUp callers are injected so this helper is unit-testable:
 *   $fetchTask(string $id): ?array — returns the /task/{id} response body
 *                                    (or null on error). The caller is
 *                                    responsible for rate-limit handling.
 *   $onProgress(int $done, int $total): void — optional progress hook.
 *
 * @return array<string, array{parent_task_id:string, parent_name:?string}>
 */
function time_entries_resolve_parents(
    array $entries,
    callable $fetchTask,
    ?callable $onProgress = null
): array {
    // Collect unique (task_id → task_name) pairs, filtered to candidates.
    $candidates = [];
    foreach ($entries as $e) {
        $tid   = $e['task']['id']   ?? null;
        $tname = $e['task']['name'] ?? null;
        if (!$tid) continue;
        $tid = (string) $tid;
        if (isset($candidates[$tid])) continue;
        if (!time_entry_is_subtask_candidate($tname)) continue;
        $candidates[$tid] = true;
    }

    $out = [];
    $parentCache = []; // [parent_id => parent_name] to dedupe the 2nd call.
    $total = count($candidates);
    $done  = 0;

    foreach (array_keys($candidates) as $taskId) {
        $task = $fetchTask($taskId);
        $done++;
        if ($onProgress) $onProgress($done, $total);
        if (!is_array($task)) continue;

        // ClickUp returns either `parent` (string id) or nothing when the
        // task is a top-level post. `top_level_parent` may differ when there
        // are intermediate subtasks — we follow `parent` only (one level).
        $parentId = isset($task['parent']) && $task['parent'] !== null && $task['parent'] !== ''
            ? (string) $task['parent']
            : null;
        if ($parentId === null) continue;

        if (!array_key_exists($parentId, $parentCache)) {
            $parent = $fetchTask($parentId);
            $done++;
            if ($onProgress) $onProgress($done, $total);
            $parentCache[$parentId] = (is_array($parent) && isset($parent['name']))
                ? (string) $parent['name']
                : null;
        }

        $out[$taskId] = [
            'parent_task_id' => $parentId,
            'parent_name'    => $parentCache[$parentId],
        ];
    }

    return $out;
}
