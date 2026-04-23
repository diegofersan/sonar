<?php
/**
 * F01 — Sync endpoint for ClickUp time entries of the design department.
 *
 * POST = start sync (responds immediately, continues processing in background).
 * GET  = poll sync status for the current user.
 *
 * Gated by is_department_head(): non-heads get 403.
 *
 * Writes to sync_log with scope='time_entries' so it can run in parallel with
 * the tasks sync without either clobbering the other's state.
 */

ini_set('display_errors', '0');
// 600s (10 min) é folga confortável para meses com mais volume — 139+
// subtasks × ~2 API calls cada, ao rate limit do ClickUp, passa de 120s.
// Ver specs/20-done/B01-sync-timeout.
ini_set('max_execution_time', '600');
error_reporting(E_ALL & ~E_DEPRECATED);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    require_once __DIR__ . '/../includes/session.php';
    require_once __DIR__ . '/../includes/database.php';
    require_once __DIR__ . '/../includes/clickup.php';
    require_once __DIR__ . '/../includes/collaborators.php';
    require_once __DIR__ . '/../includes/time_entries.php';

    init_session();

    if (!is_authenticated()) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }

    if (!is_department_head()) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    // -- GET: status polling -----------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $workspace = get_workspace();
        $user      = get_user();

        if (!$workspace) {
            echo json_encode(['status' => 'no_workspace']);
            exit;
        }

        $currentUserId = $user['id'] ?? null;
        $lastSync = db_get_last_sync($workspace['id'], null, $currentUserId, 'time_entries');

        $stmt = db()->prepare(
            "SELECT * FROM sync_log
             WHERE workspace_id = ? AND user_id = ?
               AND scope = 'time_entries' AND status = 'running'
             ORDER BY started_at DESC LIMIT 1"
        );
        $stmt->execute([$workspace['id'], $currentUserId]);
        $running = $stmt->fetch();

        echo json_encode([
            'running'    => $running ? true : false,
            'started_at' => $running ? $running['started_at'] : null,
            'progress'   => $running ? ($running['progress'] ?? null) : null,
            'last_sync'  => $lastSync ? $lastSync['completed_at']  : null,
            'last_count' => $lastSync ? $lastSync['task_count']    : null,
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    // -- POST: kick off a sync ---------------------------------------------
    $rawBody = file_get_contents('php://input');
    $input   = json_decode($rawBody, true) ?: [];

    // CSRF (same inline pattern as api/sync.php)
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_csrf'] ?? null);
    if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid or missing CSRF token.']);
        exit;
    }

    $token     = get_token();
    $workspace = get_workspace();
    $user      = get_user();

    if (!$workspace) {
        http_response_code(400);
        echo json_encode(['error' => 'No workspace selected']);
        exit;
    }

    $workspaceId = $workspace['id'];
    $userId      = $user['id'] ?? '';

    // Optional month target (?month=YYYY-MM or JSON body field). Null = current month.
    $tz = new DateTimeZone('Europe/Lisbon');
    try {
        $monthAnchor = collab_parse_month_param(
            $_GET['month'] ?? ($input['month'] ?? null),
            $tz
        );
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid month']);
        exit;
    }

    // Stale timeout, scoped to time_entries so a tasks sync isn't affected.
    $staleTimeout = time() - 300;
    db()->prepare(
        "UPDATE sync_log SET status = 'error', error_message = 'Timeout'
         WHERE workspace_id = ? AND scope = 'time_entries'
           AND status = 'running' AND started_at < ?"
    )->execute([$workspaceId, $staleTimeout]);

    // Running check, scoped.
    $force = !empty($input['force']);
    $stmtRunning = db()->prepare(
        "SELECT id FROM sync_log
         WHERE workspace_id = ? AND scope = 'time_entries'
           AND status = 'running' LIMIT 1"
    );
    $stmtRunning->execute([$workspaceId]);
    if ($stmtRunning->fetch()) {
        if (!$force) {
            http_response_code(429);
            echo json_encode(['error' => 'A time-entries sync is already running. Please wait or force.']);
            exit;
        }
        db()->prepare(
            "UPDATE sync_log SET status = 'cancelled', error_message = 'Cancelled by user'
             WHERE workspace_id = ? AND scope = 'time_entries' AND status = 'running'"
        )->execute([$workspaceId]);
    }

    $logId = db_log_sync_start($workspaceId, $userId, null, 'time_entries');

    // Release session lock so polling requests aren't blocked.
    session_write_close();

    ignore_user_abort(true);

    echo json_encode([
        'success' => true,
        'message' => 'Time-entries sync started',
        'log_id'  => $logId,
    ]);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        header('Connection: close');
        header('Content-Length: ' . ob_get_length());
        ob_end_flush();
        flush();
    }

    // ---- Client has response. Do the sync inline. ------------------------

    db_sync_progress($logId, 'A identificar grupo de design...');

    $group = clickup_find_design_group($token, $workspaceId);
    if (!$group) {
        db_log_sync_end($logId, 'error', 0, 'Design group not found in workspace');
        exit;
    }

    $memberIds = clickup_group_member_ids($group);
    if (!$memberIds) {
        db_log_sync_end($logId, 'error', 0, 'Design group has no members');
        exit;
    }

    // Window covers every ISO week that has at least one day in the target
    // month (Europe/Lisbon). Matches what api/collaborators.php aggregates,
    // so the sync does not leave boundary-straddling weeks half-populated.
    // $monthAnchor (parsed above) selects a past month; null = current.
    [$windowStart, $windowEnd] = collab_month_window($tz, $monthAnchor);
    $startMs = $windowStart->getTimestamp() * 1000;
    $endMs   = $windowEnd->getTimestamp()   * 1000;

    db_sync_progress($logId, 'A importar time entries do mês...');

    $res = clickup_get_time_entries($token, $workspaceId, $startMs, $endMs, $memberIds);
    if (empty($res['ok'])) {
        db_log_sync_end($logId, 'error', 0, $res['error'] ?? 'API error');
        exit;
    }

    $entries = $res['body']['data'] ?? [];
    if (!is_array($entries)) {
        $entries = [];
    }

    // F03 — resolve post parent for Design/Copy subtasks before the upsert,
    // so each row lands on disk with post_name/post_url filled in. Only
    // candidates (name contains design|copy) trigger API calls; parent names
    // are cached within this sync run so siblings share the same fetch.
    $fetchTask = function (string $id) use ($token) {
        $res = clickup_get_task($token, $id);
        return (is_array($res) && !empty($res['ok']) && is_array($res['body']))
            ? $res['body']
            : null;
    };
    $parentMap = time_entries_resolve_parents(
        $entries,
        $fetchTask,
        function (int $done, int $total) use ($logId) {
            db_sync_progress($logId, "A resolver posts... ({$done}/{$total})");
        }
    );

    db_sync_progress($logId, 'A gravar time entries...');
    $inserted = db_upsert_time_entries($entries, $workspaceId, $parentMap);

    db_log_sync_end($logId, 'success', $inserted);

} catch (\Throwable $e) {
    error_log('Sonar time-entries sync error: ' . $e->getMessage());
    if (isset($logId)) {
        db_log_sync_end($logId, 'error', 0, $e->getMessage());
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'An internal error occurred']);
    }
}
