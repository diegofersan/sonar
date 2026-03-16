<?php
/**
 * SQLite database layer for the Sonar ClickUp dashboard cache.
 *
 * Provides connection management (singleton), schema migrations, and query
 * helpers for tasks, sync logging, and list configuration.
 *
 * Database file: /Users/dgfrsn/Dev/sonar/data/sonar.db
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Connection & migration
// ---------------------------------------------------------------------------

/**
 * Return the singleton PDO instance, creating the database on first call.
 *
 * - Creates the data/ directory if it does not exist.
 * - Enables WAL journal mode and foreign keys.
 * - Runs migrations (CREATE TABLE IF NOT EXISTS) on first invocation.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0700, true);
    }

    $dbPath = $dataDir . '/sonar.db';
    $pdo = new PDO('sqlite:' . $dbPath);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    db_migrate();

    return $pdo;
}

/**
 * Create all tables and indexes if they do not already exist.
 */
function db_migrate(): void
{
    $pdo = db();

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS tasks (
            id              TEXT PRIMARY KEY,
            custom_id       TEXT,
            name            TEXT NOT NULL,
            description     TEXT,
            status_name     TEXT,
            status_color    TEXT,
            priority_id     INTEGER,
            priority_label  TEXT,
            due_date        INTEGER,
            start_date      INTEGER,
            parent_id       TEXT,
            list_id         TEXT,
            list_name       TEXT,
            folder_id       TEXT,
            folder_name     TEXT,
            workspace_id    TEXT,
            task_type       TEXT,
            assignees       TEXT,
            tags            TEXT,
            url             TEXT,
            date_created    TEXT,
            date_updated    TEXT,
            synced_at       INTEGER NOT NULL DEFAULT (strftime('%s','now'))
        );

        CREATE INDEX IF NOT EXISTS idx_tasks_parent         ON tasks(parent_id);
        CREATE INDEX IF NOT EXISTS idx_tasks_list            ON tasks(list_id);
        CREATE INDEX IF NOT EXISTS idx_tasks_workspace       ON tasks(workspace_id);
        CREATE INDEX IF NOT EXISTS idx_tasks_priority_due    ON tasks(priority_id, due_date);

        CREATE TABLE IF NOT EXISTS task_assignees (
            task_id  TEXT NOT NULL,
            user_id  TEXT NOT NULL,
            PRIMARY KEY (task_id, user_id)
        );

        CREATE INDEX IF NOT EXISTS idx_task_assignees_user ON task_assignees(user_id);

        CREATE TABLE IF NOT EXISTS sync_log (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            workspace_id  TEXT NOT NULL,
            user_id       TEXT,
            list_id       TEXT,
            started_at    INTEGER NOT NULL,
            completed_at  INTEGER,
            task_count    INTEGER DEFAULT 0,
            status        TEXT DEFAULT 'running',
            error_message TEXT
        );

        CREATE TABLE IF NOT EXISTS config_lists (
            id            TEXT PRIMARY KEY,
            name          TEXT,
            workspace_id  TEXT NOT NULL,
            enabled       INTEGER DEFAULT 1
        );

        CREATE TABLE IF NOT EXISTS watched_tasks (
            task_id       TEXT NOT NULL,
            workspace_id  TEXT NOT NULL,
            created_at    INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            PRIMARY KEY (task_id, workspace_id)
        );

        CREATE TABLE IF NOT EXISTS task_notifications (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id       TEXT NOT NULL,
            task_name     TEXT,
            workspace_id  TEXT NOT NULL,
            change_type   TEXT NOT NULL,
            old_value     TEXT,
            new_value     TEXT,
            seen          INTEGER NOT NULL DEFAULT 0,
            created_at    INTEGER NOT NULL DEFAULT (strftime('%s','now'))
        );

        CREATE INDEX IF NOT EXISTS idx_notifications_unseen
            ON task_notifications(workspace_id, seen, created_at DESC);

    SQL);

    // Add progress column if it doesn't exist
    try {
        $pdo->exec('ALTER TABLE sync_log ADD COLUMN progress TEXT');
    } catch (\Throwable $e) {
        // Column already exists — ignore
    }
}

/**
 * Update the progress message for a running sync.
 */
function db_sync_progress(int $logId, string $message): void
{
    $stmt = db()->prepare('UPDATE sync_log SET progress = ? WHERE id = ?');
    $stmt->execute([$message, $logId]);
}

// ---------------------------------------------------------------------------
// Task helpers
// ---------------------------------------------------------------------------

/**
 * Insert or replace a single task from the raw ClickUp API response.
 *
 * Also maintains the task_assignees lookup table.
 *
 * @param array  $task         Raw ClickUp task object (associative array).
 * @param string $workspace_id Workspace / team ID the task belongs to.
 */
function db_upsert_task(array $task, string $workspace_id): void
{
    $pdo = db();

    // -- Extract fields from the ClickUp task payload ----------------------

    $id            = $task['id'] ?? null;
    $custom_id     = $task['custom_id'] ?? null;
    $name          = $task['name'] ?? '';
    $description   = $task['text_content'] ?? ($task['description'] ?? null);
    $status_name   = $task['status']['status'] ?? null;
    $status_color  = $task['status']['color'] ?? null;
    $priority_id   = isset($task['priority']['id']) ? (int) $task['priority']['id'] : null;
    $priority_label = $task['priority']['priority'] ?? null;
    $due_date      = isset($task['due_date']) ? (int) $task['due_date'] : null;
    $start_date    = isset($task['start_date']) ? (int) $task['start_date'] : null;
    $parent_id     = $task['parent'] ?? null;
    $list_id       = $task['list']['id'] ?? null;
    $list_name     = $task['list']['name'] ?? null;
    $folder_id     = $task['folder']['id'] ?? null;
    $folder_name   = $task['folder']['name'] ?? null;

    // task_type: prefer the explicit type field, fall back to custom field
    $task_type = $task['type'] ?? null;
    if ($task_type === null && !empty($task['custom_fields'])) {
        foreach ($task['custom_fields'] as $cf) {
            if (strtolower($cf['name'] ?? '') === 'task type') {
                $task_type = $cf['value'] ?? ($cf['type_config']['options'][0]['name'] ?? null);
                break;
            }
        }
    }

    $assignees_raw = $task['assignees'] ?? [];
    $assignees     = json_encode($assignees_raw, JSON_UNESCAPED_UNICODE);
    $tags          = json_encode($task['tags'] ?? [], JSON_UNESCAPED_UNICODE);
    $url           = $task['url'] ?? null;
    $date_created  = $task['date_created'] ?? null;
    $date_updated  = $task['date_updated'] ?? null;
    $synced_at     = time();

    // -- Detect changes on watched tasks ------------------------------------
    // Check if this task OR its parent post is watched

    $watchedTaskId = null;
    if (db_is_task_watched($id, $workspace_id)) {
        $watchedTaskId = $id;
    } elseif ($parent_id && db_is_task_watched($parent_id, $workspace_id)) {
        $watchedTaskId = $parent_id;
    }

    if ($watchedTaskId) {
        $oldTask = db_get_task($id);
        if ($oldTask !== null) {
            // Build notification name: "Post Name → Copy" for subtasks
            $notifName = $name;
            if ($watchedTaskId !== $id) {
                $parentTask = db_get_task($parent_id);
                if ($parentTask) {
                    $notifName = $parentTask['name'] . ' → ' . $name;
                }
            }

            $fields = [
                'status'   => ['old' => $oldTask['status_name'],    'new' => $status_name],
                'priority' => ['old' => $oldTask['priority_label'],  'new' => $priority_label],
                'due_date' => ['old' => $oldTask['due_date'],        'new' => $due_date],
                'assignee' => ['old' => $oldTask['assignees'],       'new' => $assignees],
                'name'     => ['old' => $oldTask['name'],            'new' => $name],
            ];
            $stmtNotif = $pdo->prepare(
                'INSERT INTO task_notifications (task_id, task_name, workspace_id, change_type, old_value, new_value)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($fields as $changeType => $f) {
                $oldVal = (string)($f['old'] ?? '');
                $newVal = (string)($f['new'] ?? '');
                if ($oldVal !== $newVal) {
                    $stmtNotif->execute([$watchedTaskId, $notifName, $workspace_id, $changeType, $f['old'], $f['new']]);
                }
            }
        }
    }

    // -- Upsert the task row -----------------------------------------------

    $stmt = $pdo->prepare(<<<'SQL'
        INSERT OR REPLACE INTO tasks (
            id, custom_id, name, description,
            status_name, status_color,
            priority_id, priority_label,
            due_date, start_date,
            parent_id,
            list_id, list_name,
            folder_id, folder_name,
            workspace_id, task_type,
            assignees, tags, url,
            date_created, date_updated,
            synced_at
        ) VALUES (
            :id, :custom_id, :name, :description,
            :status_name, :status_color,
            :priority_id, :priority_label,
            :due_date, :start_date,
            :parent_id,
            :list_id, :list_name,
            :folder_id, :folder_name,
            :workspace_id, :task_type,
            :assignees, :tags, :url,
            :date_created, :date_updated,
            :synced_at
        )
    SQL);

    $stmt->execute([
        ':id'             => $id,
        ':custom_id'      => $custom_id,
        ':name'           => $name,
        ':description'    => $description,
        ':status_name'    => $status_name,
        ':status_color'   => $status_color,
        ':priority_id'    => $priority_id,
        ':priority_label' => $priority_label,
        ':due_date'       => $due_date,
        ':start_date'     => $start_date,
        ':parent_id'      => $parent_id,
        ':list_id'        => $list_id,
        ':list_name'      => $list_name,
        ':folder_id'      => $folder_id,
        ':folder_name'    => $folder_name,
        ':workspace_id'   => $workspace_id,
        ':task_type'      => $task_type,
        ':assignees'      => $assignees,
        ':tags'           => $tags,
        ':url'            => $url,
        ':date_created'   => $date_created,
        ':date_updated'   => $date_updated,
        ':synced_at'      => $synced_at,
    ]);

    // -- Maintain task_assignees lookup ------------------------------------

    $pdo->prepare('DELETE FROM task_assignees WHERE task_id = ?')->execute([$id]);

    if (!empty($assignees_raw)) {
        $ins = $pdo->prepare(
            'INSERT OR IGNORE INTO task_assignees (task_id, user_id) VALUES (?, ?)'
        );
        foreach ($assignees_raw as $a) {
            $uid = (string) ($a['id'] ?? '');
            if ($uid !== '') {
                $ins->execute([$id, $uid]);
            }
        }
    }
}

/**
 * Batch-upsert an array of tasks inside a single transaction.
 *
 * @param array  $tasks        Array of raw ClickUp task objects.
 * @param string $workspace_id Workspace / team ID.
 */
function db_upsert_tasks(array $tasks, string $workspace_id): void
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        foreach ($tasks as $task) {
            db_upsert_task($task, $workspace_id);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// Task queries
// ---------------------------------------------------------------------------

/**
 * Return tasks assigned to a specific user in a workspace.
 *
 * Uses the task_assignees lookup table for efficient filtering.
 * Results are ordered by priority (urgent first, NULL last) then by due date
 * (earliest first, NULL last).
 *
 * @param string      $workspace_id Workspace / team ID.
 * @param int         $user_id      ClickUp numeric user ID.
 * @param string|null $list_id      Optional list filter.
 * @return array                    Array of task rows.
 */
function db_get_user_tasks(string $workspace_id, $user_id, ?string $list_id = null): array
{
    $sql = <<<'SQL'
        SELECT t.*
        FROM tasks t
        JOIN task_assignees ta ON ta.task_id = t.id
        WHERE ta.user_id = :user_id
          AND t.workspace_id = :workspace_id
    SQL;

    $params = [
        ':user_id'      => (string) $user_id,
        ':workspace_id' => $workspace_id,
    ];

    if ($list_id !== null) {
        $sql .= ' AND t.list_id = :list_id';
        $params[':list_id'] = $list_id;
    }

    $sql .= <<<'SQL'

        ORDER BY
            CASE WHEN t.priority_id IS NULL THEN 1 ELSE 0 END,
            t.priority_id ASC,
            CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END,
            t.due_date ASC
    SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Fetch a single task by its ClickUp ID.
 *
 * @param string $task_id ClickUp task ID.
 * @return array|null     Task row or null if not found.
 */
function db_get_task(string $task_id): ?array
{
    $stmt = db()->prepare('SELECT * FROM tasks WHERE id = ?');
    $stmt->execute([$task_id]);
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

/**
 * Get the direct children (subtasks) of a task.
 *
 * @param string $parent_id Parent task ID.
 * @return array            Array of child task rows.
 */
function db_get_task_children(string $parent_id): array
{
    $stmt = db()->prepare('SELECT * FROM tasks WHERE parent_id = ?');
    $stmt->execute([$parent_id]);

    return $stmt->fetchAll();
}

/**
 * Walk up the parent_id chain and return ancestors from root to immediate parent.
 *
 * @param string $task_id Starting task ID.
 * @return array          Ancestors ordered root-first.
 */
function db_get_task_ancestors(string $task_id): array
{
    $ancestors = [];
    $current   = db_get_task($task_id);

    if ($current === null) {
        return [];
    }

    $visited = [$task_id => true]; // guard against cycles

    while (!empty($current['parent_id'])) {
        $pid = $current['parent_id'];

        if (isset($visited[$pid])) {
            break; // cycle detected
        }
        $visited[$pid] = true;

        $parent = db_get_task($pid);
        if ($parent === null) {
            break;
        }

        $ancestors[] = $parent;
        $current     = $parent;
    }

    // Reverse so the root ancestor comes first.
    return array_reverse($ancestors);
}

/**
 * Return every task that belongs to a given list.
 *
 * @param string $list_id ClickUp list ID.
 * @return array          Array of task rows.
 */
function db_get_all_tasks_in_list(string $list_id): array
{
    $stmt = db()->prepare('SELECT * FROM tasks WHERE list_id = ?');
    $stmt->execute([$list_id]);

    return $stmt->fetchAll();
}

/**
 * Delete all tasks (and their assignee lookups via CASCADE) for a list.
 *
 * Intended to be called before a full re-sync of the list.
 *
 * @param string $list_id ClickUp list ID.
 */
function db_clear_list_tasks(string $list_id): void
{
    $pdo = db();
    // Clear assignees for tasks in this list first (no CASCADE)
    $pdo->exec('DELETE FROM task_assignees WHERE task_id IN (SELECT id FROM tasks WHERE list_id = ' . $pdo->quote($list_id) . ')');
    $pdo->prepare('DELETE FROM tasks WHERE list_id = ?')->execute([$list_id]);
}

// ---------------------------------------------------------------------------
// Sync log
// ---------------------------------------------------------------------------

/**
 * Record the start of a sync operation.
 *
 * @param string      $workspace_id Workspace / team ID.
 * @param string|null $user_id      Optional user scope.
 * @param string|null $list_id      Optional list scope.
 * @return int                      The new sync_log row ID.
 */
function db_log_sync_start(string $workspace_id, ?string $user_id, ?string $list_id): int
{
    $stmt = db()->prepare(<<<'SQL'
        INSERT INTO sync_log (workspace_id, user_id, list_id, started_at, status)
        VALUES (:workspace_id, :user_id, :list_id, :started_at, 'running')
    SQL);

    $stmt->execute([
        ':workspace_id' => $workspace_id,
        ':user_id'      => $user_id,
        ':list_id'      => $list_id,
        ':started_at'   => time(),
    ]);

    return (int) db()->lastInsertId();
}

/**
 * Finalize a sync_log entry.
 *
 * @param int         $log_id     Row ID returned by db_log_sync_start().
 * @param string      $status     Final status: 'success' or 'error'.
 * @param int         $task_count Number of tasks synced.
 * @param string|null $error      Error message (if status is 'error').
 */
function db_log_sync_end(int $log_id, string $status, int $task_count = 0, ?string $error = null): void
{
    $stmt = db()->prepare(<<<'SQL'
        UPDATE sync_log
        SET completed_at  = :completed_at,
            status        = :status,
            task_count    = :task_count,
            error_message = :error_message
        WHERE id = :id
    SQL);

    $stmt->execute([
        ':completed_at'  => time(),
        ':status'        => $status,
        ':task_count'    => $task_count,
        ':error_message' => $error,
        ':id'            => $log_id,
    ]);
}

/**
 * Return the most recent successful sync log entry for a workspace (and
 * optionally a specific list).
 *
 * @param string      $workspace_id Workspace / team ID.
 * @param string|null $list_id      Optional list filter.
 * @param string|null $user_id      Optional user filter.
 * @return array|null               Sync log row or null.
 */
function db_get_last_sync(string $workspace_id, ?string $list_id = null, ?string $user_id = null): ?array
{
    $sql = <<<'SQL'
        SELECT *
        FROM sync_log
        WHERE workspace_id = :workspace_id
          AND status = 'success'
    SQL;

    $params = [':workspace_id' => $workspace_id];

    if ($list_id !== null) {
        $sql .= ' AND list_id = :list_id';
        $params[':list_id'] = $list_id;
    }

    if ($user_id !== null) {
        $sql .= ' AND user_id = :user_id';
        $params[':user_id'] = $user_id;
    }

    $sql .= ' ORDER BY completed_at DESC LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

// ---------------------------------------------------------------------------
// List configuration
// ---------------------------------------------------------------------------

/**
 * Insert or update a list in config_lists.
 *
 * @param string $id           ClickUp list ID.
 * @param string $name         Human-readable list name.
 * @param string $workspace_id Workspace / team ID.
 */
function db_save_list_config(string $id, string $name, string $workspace_id): void
{
    $stmt = db()->prepare(<<<'SQL'
        INSERT OR REPLACE INTO config_lists (id, name, workspace_id, enabled)
        VALUES (
            :id,
            :name,
            :workspace_id,
            COALESCE((SELECT enabled FROM config_lists WHERE id = :id2), 1)
        )
    SQL);

    $stmt->execute([
        ':id'           => $id,
        ':name'         => $name,
        ':workspace_id' => $workspace_id,
        ':id2'          => $id,
    ]);
}

/**
 * Return all enabled lists for a workspace.
 *
 * @param string $workspace_id Workspace / team ID.
 * @return array               Array of config_lists rows.
 */
function db_get_enabled_lists(string $workspace_id): array
{
    $stmt = db()->prepare(
        'SELECT * FROM config_lists WHERE workspace_id = ? AND enabled = 1'
    );
    $stmt->execute([$workspace_id]);

    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Watched tasks
// ---------------------------------------------------------------------------

/**
 * Start watching a task in a workspace.
 *
 * @param string $task_id      ClickUp task ID.
 * @param string $workspace_id Workspace / team ID.
 */
function db_watch_task(string $task_id, string $workspace_id): void
{
    $stmt = db()->prepare(
        'INSERT OR IGNORE INTO watched_tasks (task_id, workspace_id) VALUES (?, ?)'
    );
    $stmt->execute([$task_id, $workspace_id]);
}

/**
 * Stop watching a task in a workspace.
 *
 * @param string $task_id      ClickUp task ID.
 * @param string $workspace_id Workspace / team ID.
 */
function db_unwatch_task(string $task_id, string $workspace_id): void
{
    $stmt = db()->prepare(
        'DELETE FROM watched_tasks WHERE task_id = ? AND workspace_id = ?'
    );
    $stmt->execute([$task_id, $workspace_id]);
}

/**
 * Check whether a task is being watched in a workspace.
 *
 * @param string $task_id      ClickUp task ID.
 * @param string $workspace_id Workspace / team ID.
 * @return bool
 */
function db_is_task_watched(string $task_id, string $workspace_id): bool
{
    $stmt = db()->prepare(
        'SELECT 1 FROM watched_tasks WHERE task_id = ? AND workspace_id = ?'
    );
    $stmt->execute([$task_id, $workspace_id]);

    return $stmt->fetch() !== false;
}

/**
 * Return all watched task IDs for a workspace.
 *
 * @param string $workspace_id Workspace / team ID.
 * @return array               Array of task ID strings.
 */
function db_get_watched_task_ids(string $workspace_id): array
{
    $stmt = db()->prepare(
        'SELECT task_id FROM watched_tasks WHERE workspace_id = ?'
    );
    $stmt->execute([$workspace_id]);

    return array_column($stmt->fetchAll(), 'task_id');
}

// ---------------------------------------------------------------------------
// Notifications
// ---------------------------------------------------------------------------

/**
 * Return recent notifications for a workspace.
 *
 * @param string $workspace_id Workspace / team ID.
 * @param int    $limit        Maximum number of rows to return.
 * @return array               Array of notification rows.
 */
function db_get_notifications(string $workspace_id, int $limit = 50): array
{
    $stmt = db()->prepare(
        'SELECT * FROM task_notifications WHERE workspace_id = ? ORDER BY created_at DESC LIMIT ?'
    );
    $stmt->execute([$workspace_id, $limit]);

    return $stmt->fetchAll();
}

/**
 * Return the count of unread notifications for a workspace.
 *
 * @param string $workspace_id Workspace / team ID.
 * @return int
 */
function db_get_unread_count(string $workspace_id): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM task_notifications WHERE workspace_id = ? AND seen = 0'
    );
    $stmt->execute([$workspace_id]);

    return (int) $stmt->fetchColumn();
}

/**
 * Mark a single notification as read.
 *
 * @param int $id Notification row ID.
 */
function db_mark_notification_read(int $id): void
{
    $stmt = db()->prepare(
        'UPDATE task_notifications SET seen = 1 WHERE id = ?'
    );
    $stmt->execute([$id]);
}

/**
 * Mark all notifications as read for a workspace.
 *
 * @param string $workspace_id Workspace / team ID.
 */
function db_mark_all_notifications_read(string $workspace_id): void
{
    $stmt = db()->prepare(
        'UPDATE task_notifications SET seen = 1 WHERE workspace_id = ?'
    );
    $stmt->execute([$workspace_id]);
}

/**
 * Clean up old notifications for a workspace.
 *
 * - Delete seen notifications older than 30 days.
 * - Delete all notifications older than 90 days.
 * - Keep at most 500 notifications (delete oldest beyond that).
 *
 * @param string $workspace_id Workspace / team ID.
 */
function db_cleanup_notifications(string $workspace_id): void
{
    $pdo = db();
    $now = time();

    // Delete seen notifications older than 30 days
    $pdo->prepare(
        'DELETE FROM task_notifications WHERE workspace_id = ? AND seen = 1 AND created_at < ?'
    )->execute([$workspace_id, $now - (30 * 86400)]);

    // Delete all notifications older than 90 days
    $pdo->prepare(
        'DELETE FROM task_notifications WHERE workspace_id = ? AND created_at < ?'
    )->execute([$workspace_id, $now - (90 * 86400)]);

    // Keep at most 500 notifications
    $stmt = $pdo->prepare(
        'SELECT id FROM task_notifications WHERE workspace_id = ? ORDER BY created_at DESC LIMIT 1 OFFSET 500'
    );
    $stmt->execute([$workspace_id]);
    $cutoff = $stmt->fetch();

    if ($cutoff) {
        $pdo->prepare(
            'DELETE FROM task_notifications WHERE workspace_id = ? AND id <= ?'
        )->execute([$workspace_id, $cutoff['id']]);
    }
}
