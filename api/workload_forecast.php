<?php
/**
 * F02 — Plano da semana endpoint (department head only).
 *
 * GET: returns per-collaborator daily forecast (Mon-Fri) of the current week
 * for every member of the ClickUp "design" group. Read-only, no writes.
 *
 * Input: nothing (workspace from session).
 * Output: week bounds + array of collaborators with daily buckets.
 *
 * Zero novas chamadas à API ClickUp: tudo sai do SQLite local (tasks +
 * task_assignees). O roster do grupo design é lido do ClickUp pelo mesmo
 * caminho do F01 (2 calls — cached no session scope da própria request).
 */

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    require_once __DIR__ . '/../includes/session.php';
    require_once __DIR__ . '/../includes/database.php';
    require_once __DIR__ . '/../includes/clickup.php';
    require_once __DIR__ . '/../includes/workload.php';

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
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $token     = get_token();
    $workspace = get_workspace();

    if (!$workspace) {
        http_response_code(400);
        echo json_encode(['error' => 'No workspace selected']);
        exit;
    }
    $workspaceId = (string) $workspace['id'];

    // Janela seg-sex da semana corrente.
    $tz       = new DateTimeZone('Europe/Lisbon');
    $now      = new DateTimeImmutable('now', $tz);
    $weekdays = forecast_current_week($tz, $now);
    $weekStart = $weekdays[0]->format('Y-m-d');
    $weekEnd   = $weekdays[4]->format('Y-m-d');

    // Design group (roster + usernames para display).
    $group = clickup_find_design_group($token, $workspaceId);
    if (!$group) {
        echo json_encode([
            'week_start'    => $weekStart,
            'week_end'      => $weekEnd,
            'collaborators' => [],
            'warning'       => 'Design group not found in workspace',
        ]);
        exit;
    }

    $memberIds   = clickup_group_member_ids($group);
    $membersById = [];
    foreach (($group['members'] ?? []) as $m) {
        if (isset($m['id'])) {
            $membersById[(string) $m['id']] = $m;
        }
    }

    if (empty($memberIds)) {
        echo json_encode([
            'week_start'    => $weekStart,
            'week_end'      => $weekEnd,
            'collaborators' => [],
        ]);
        exit;
    }

    // Query única: tasks do workspace atribuídas a membros do grupo.
    // Não filtramos status_name em SQL — FORECAST_TERMINAL_STATUSES é a única
    // fonte de verdade, resolvida em PHP com case-insensitive + unicode.
    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    $sql = "
        SELECT t.id, t.name, t.status_name, t.start_date, t.due_date,
               t.parent_id, ta.user_id
          FROM tasks t
          JOIN task_assignees ta ON ta.task_id = t.id
         WHERE t.workspace_id = ?
           AND ta.user_id IN ($placeholders)
    ";
    $params = array_merge([$workspaceId], $memberIds);
    $stmt   = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Agrupa por user_id.
    $byUser = [];
    foreach ($rows as $r) {
        $byUser[(string) $r['user_id']][] = [
            'id'          => (string) $r['id'],
            'name'        => $r['name'],
            'status_name' => $r['status_name'],
            'start_date'  => $r['start_date'] !== null ? (int) $r['start_date'] : null,
            'due_date'    => $r['due_date']   !== null ? (int) $r['due_date']   : null,
            'parent_id'   => $r['parent_id'] !== null && $r['parent_id'] !== ''
                             ? (string) $r['parent_id']
                             : null,
        ];
    }

    $collaborators = [];
    foreach ($memberIds as $memberId) {
        $m           = $membersById[$memberId] ?? ['id' => $memberId];
        $tasks       = $byUser[$memberId] ?? [];
        $dailyTarget = get_daily_tasks_target($memberId);

        $collaborators[] = [
            'user' => [
                'id'             => (string) ($m['id'] ?? $memberId),
                'username'       => $m['username']       ?? null,
                'email'          => $m['email']          ?? null,
                'initials'       => $m['initials']       ?? null,
                'color'          => $m['color']          ?? null,
                'profilePicture' => $m['profilePicture'] ?? null,
            ],
            'daily_target' => $dailyTarget,
            'days'         => forecast_aggregate($tasks, $dailyTarget, $weekdays, $now, $tz),
        ];
    }

    // Ordem estável por username.
    usort($collaborators, function ($a, $b) {
        return strcasecmp(
            (string) ($a['user']['username'] ?? ''),
            (string) ($b['user']['username'] ?? '')
        );
    });

    echo json_encode([
        'week_start'    => $weekStart,
        'week_end'      => $weekEnd,
        'collaborators' => $collaborators,
    ]);

} catch (\Throwable $e) {
    error_log('Sonar workload_forecast error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An internal error occurred']);
}
