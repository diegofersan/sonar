<?php
/**
 * F01 — Colaboradores endpoint (department head only).
 *
 * GET: returns the current month's workload per member of the ClickUp
 * "design" group, broken down by ISO week and by weekday, with a status
 * badge (under / ok / over) against the configured weekly_hours.
 *
 * No writes. Time entries themselves come from the local SQLite cache
 * (populated by api/sync_time_entries.php); member roster and usernames
 * are fetched fresh from ClickUp on each request.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    require_once __DIR__ . '/../includes/session.php';
    require_once __DIR__ . '/../includes/database.php';
    require_once __DIR__ . '/../includes/clickup.php';
    require_once __DIR__ . '/../includes/collaborators.php';

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
    $user      = get_user();

    if (!$workspace) {
        http_response_code(400);
        echo json_encode(['error' => 'No workspace selected']);
        exit;
    }
    $workspaceId = $workspace['id'];

    // Month window (ISO-week aligned, Europe/Lisbon)
    $tz  = new DateTimeZone('Europe/Lisbon');
    $now = new DateTimeImmutable('now', $tz);
    [$windowStart, $windowEnd] = collab_month_window($tz, $now);
    $weeks   = collab_iso_weeks($windowStart, $windowEnd);
    $startMs = $windowStart->getTimestamp() * 1000;
    $endMs   = $windowEnd->getTimestamp()   * 1000;

    // Last time_entries sync for this user — same scope the sync endpoint
    // records under. If null, UI shows "Ainda sem dados — clica Sync".
    $uid      = $user['id'] ?? null;
    $lastSync = db_get_last_sync($workspaceId, null, $uid, 'time_entries');

    // Fetch the design group (roster + usernames for display).
    $group = clickup_find_design_group($token, $workspaceId);
    if (!$group) {
        echo json_encode([
            'last_sync'     => $lastSync ? (int) $lastSync['completed_at'] : null,
            'month_start'   => $windowStart->format('Y-m-d'),
            'month_end'     => $windowEnd->format('Y-m-d'),
            'weeks_meta'    => array_map(fn($w) => [
                'year' => $w['year'],
                'week_number' => $w['week'],
                'week_start'  => $w['monday']->format('Y-m-d'),
            ], $weeks),
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

    // Pull all entries for the whole member roster + window in one shot.
    $allEntries = $memberIds
        ? db_get_time_entries($workspaceId, $memberIds, $startMs, $endMs)
        : [];

    // Group by user_id for per-collaborator aggregation
    $byUser = [];
    foreach ($allEntries as $e) {
        $byUser[(string) $e['user_id']][] = $e;
    }

    $collaborators = [];
    foreach ($memberIds as $memberId) {
        $m            = $membersById[$memberId] ?? ['id' => $memberId];
        $userEntries  = $byUser[$memberId] ?? [];
        $weeklyHours  = get_weekly_hours($memberId);

        $collaborators[] = [
            'user' => [
                'id'             => (string) ($m['id'] ?? $memberId),
                'username'       => $m['username']       ?? null,
                'email'          => $m['email']          ?? null,
                'initials'       => $m['initials']       ?? null,
                'color'          => $m['color']          ?? null,
                'profilePicture' => $m['profilePicture'] ?? null,
            ],
            'weekly_hours' => $weeklyHours,
            'weeks'        => collab_aggregate_weeks($userEntries, $weeklyHours, $weeks, $tz),
        ];
    }

    // Alphabetical by username for a stable order
    usort($collaborators, function ($a, $b) {
        return strcasecmp((string) ($a['user']['username'] ?? ''), (string) ($b['user']['username'] ?? ''));
    });

    echo json_encode([
        'last_sync'   => $lastSync ? (int) $lastSync['completed_at'] : null,
        'month_start' => $windowStart->format('Y-m-d'),
        'month_end'   => $windowEnd->format('Y-m-d'),
        'weeks_meta'  => array_map(fn($w) => [
            'year'        => $w['year'],
            'week_number' => $w['week'],
            'week_start'  => $w['monday']->format('Y-m-d'),
        ], $weeks),
        'collaborators' => $collaborators,
    ]);

} catch (\Throwable $e) {
    error_log('Sonar collaborators error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An internal error occurred']);
}
