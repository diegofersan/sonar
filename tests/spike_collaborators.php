<?php
/**
 * F01 — Spike: viabilidade da API ClickUp para Colaboradores.
 *
 * Usage:
 *   php tests/spike_collaborators.php <token> <team_id>
 *
 * <token> pode ser uma personal API key (pk_...) ou um OAuth access token —
 * a API ClickUp aceita ambos no header Authorization sem prefixo "Bearer ".
 *
 * Nunca escreve. Nunca toca em data/sonar.db. Só faz GETs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/clickup.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tests/spike_collaborators.php <token> <team_id>\n");
    exit(1);
}

$token  = $argv[1];
$teamId = $argv[2];

$GLOBALS['spike_req_count']   = 0;
$GLOBALS['spike_rate_errors'] = 0;
$GLOBALS['spike_started_at']  = microtime(true);

function spike_call(string $endpoint, string $token): array {
    $GLOBALS['spike_req_count']++;
    $res = clickup_api_get($endpoint, $token);
    if (($res['status'] ?? 0) === 429) {
        $GLOBALS['spike_rate_errors']++;
    }
    return $res;
}

function spike_header(string $title): void {
    echo "\n" . str_repeat('=', 70) . "\n$title\n" . str_repeat('=', 70) . "\n";
}

function spike_dump($data): void {
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

/**
 * Search recursively for keys related to hours/capacity/schedule.
 * Returns an array of ['path' => 'a.b.c', 'value' => …].
 */
function spike_find_hour_fields($payload, string $path = ''): array {
    $hits = [];
    if (!is_array($payload)) return $hits;
    foreach ($payload as $k => $v) {
        $full = $path === '' ? (string)$k : "$path.$k";
        if (is_string($k)) {
            $lc = strtolower($k);
            if (str_contains($lc, 'hour') || str_contains($lc, 'weekly')
                || str_contains($lc, 'capacity') || str_contains($lc, 'schedule')
                || str_contains($lc, 'workload')) {
                $hits[] = [
                    'path'  => $full,
                    'value' => is_scalar($v) ? $v : '(' . gettype($v) . ')',
                ];
            }
        }
        if (is_array($v)) {
            $hits = array_merge($hits, spike_find_hour_fields($v, $full));
        }
    }
    return $hits;
}

// === 1. GET /user ===========================================================
spike_header('1. GET /user — current user payload');
$userRes = spike_call('/user', $token);
echo "HTTP {$userRes['status']}\n";
$currentUserId = null;
if ($userRes['ok']) {
    $u = $userRes['body']['user'] ?? $userRes['body'];
    $currentUserId = (string)($u['id'] ?? '');
    spike_dump($u);
    $hits = spike_find_hour_fields($u);
    echo "\nHour/capacity-like fields:\n";
    if ($hits) {
        foreach ($hits as $h) echo "  - {$h['path']} = " . var_export($h['value'], true) . "\n";
    } else {
        echo "  (none)\n";
    }
} else {
    echo "Error: " . ($userRes['error'] ?? '?') . "\n";
}

// === 2. GET /team/{id} ======================================================
spike_header("2. GET /team/{$teamId} — workspace + members");
$teamRes = spike_call("/team/{$teamId}", $token);
echo "HTTP {$teamRes['status']}\n";
if ($teamRes['ok']) {
    $team = $teamRes['body']['team'] ?? $teamRes['body'];
    $members = $team['members'] ?? [];
    echo "Workspace name: " . ($team['name'] ?? '?') . "\n";
    echo "Member count:   " . count($members) . "\n\n";
    echo "First 2 members (raw):\n";
    foreach (array_slice($members, 0, 2) as $m) spike_dump($m);
    if ($members) {
        $hits = spike_find_hour_fields($members[0]);
        echo "\nHour/capacity-like fields in first member:\n";
        if ($hits) {
            foreach ($hits as $h) echo "  - {$h['path']} = " . var_export($h['value'], true) . "\n";
        } else {
            echo "  (none)\n";
        }
    }
} else {
    echo "Error: " . ($teamRes['error'] ?? '?') . "\n";
}

// === 3. Groups (v2 "user groups") ==========================================
spike_header("3. User groups (v2) — two endpoint candidates");

$designGroup = null;
$memberIds   = [];

foreach (["/team/{$teamId}/group", "/group?team_id={$teamId}"] as $endpoint) {
    echo "\n-> {$endpoint}\n";
    $res = spike_call($endpoint, $token);
    echo "   HTTP {$res['status']}\n";
    if (!$res['ok']) {
        echo "   Error: " . ($res['error'] ?? '?') . "\n";
        continue;
    }
    $groups = $res['body']['groups'] ?? $res['body'] ?? [];
    if (!is_array($groups)) $groups = [];
    echo "   " . count($groups) . " group(s):\n";
    foreach ($groups as $g) {
        $gid  = $g['id']   ?? '?';
        $name = $g['name'] ?? '?';
        $mem  = isset($g['members']) ? count($g['members'])
              : (isset($g['userid']) ? count((array)$g['userid']) : '?');
        echo "     - id={$gid}  name=\"{$name}\"  members={$mem}\n";
        if ($designGroup === null && is_string($name) && stripos($name, 'design') !== false) {
            $designGroup = $g;
        }
    }
    if ($designGroup) break;
}

if ($designGroup) {
    echo "\nIdentified 'design' group (raw):\n";
    spike_dump($designGroup);

    $rawMembers = $designGroup['members'] ?? $designGroup['userid'] ?? [];
    foreach ((array)$rawMembers as $m) {
        if (is_array($m)) {
            $id = $m['id'] ?? ($m['user']['id'] ?? null);
        } else {
            $id = $m;
        }
        if ($id !== null) $memberIds[] = (string)$id;
    }
    $memberIds = array_values(array_unique(array_filter($memberIds)));
    echo "Member IDs: " . implode(',', $memberIds) . "\n";

    $hits = spike_find_hour_fields($designGroup);
    echo "\nHour/capacity-like fields anywhere in group payload:\n";
    if ($hits) {
        foreach ($hits as $h) echo "  - {$h['path']} = " . var_export($h['value'], true) . "\n";
    } else {
        echo "  (none)\n";
    }
} else {
    echo "\nNo v2 user group matched 'design'. Possibilities:\n";
    echo "  - The 'design' team lives only in the new Teams-Pulse feature (UUID in URL).\n";
    echo "  - The v2 API does not surface it. Fallback decision needed.\n";
}

// === 4. GET /team/{id}/time_entries =========================================
spike_header("4. GET /team/{$teamId}/time_entries — current month");

$tz      = new DateTimeZone('UTC');
$first   = new DateTimeImmutable('first day of this month 00:00:00', $tz);
$last    = new DateTimeImmutable('first day of next month 00:00:00', $tz);
$startMs = $first->getTimestamp() * 1000;
$endMs   = $last->getTimestamp() * 1000;

echo "Range (UTC): {$first->format('c')} -> {$last->format('c')}\n";
echo "           ms: {$startMs} -> {$endMs}\n";

// Choose assignees: design group if we have it, else current user
$assigneeArg = '';
if ($memberIds) {
    $assigneeArg = implode(',', array_slice($memberIds, 0, 2));
    echo "Using assignee (comma-separated, from design group): {$assigneeArg}\n";
} elseif ($currentUserId) {
    $assigneeArg = $currentUserId;
    echo "Using assignee (current user fallback): {$assigneeArg}\n";
}

if ($assigneeArg === '') {
    echo "No assignee IDs available — skipping.\n";
} else {
    $url = "/team/{$teamId}/time_entries?start_date={$startMs}&end_date={$endMs}&assignee={$assigneeArg}";
    $teRes = spike_call($url, $token);
    echo "HTTP {$teRes['status']}\n";
    if ($teRes['ok']) {
        $entries = $teRes['body']['data'] ?? $teRes['body'] ?? [];
        if (!is_array($entries)) $entries = [];
        echo "Returned " . count($entries) . " entries.\n";

        echo "\nFirst 3 entries (raw):\n";
        foreach (array_slice($entries, 0, 3) as $e) spike_dump($e);

        // Quick integrity checks
        $noTask   = 0;
        $uniqueAss = [];
        foreach ($entries as $e) {
            if (empty($e['task']) && empty($e['task_id'])) $noTask++;
            $uid = (string)($e['user']['id'] ?? $e['assignee'] ?? '');
            if ($uid !== '') $uniqueAss[$uid] = true;
        }
        echo "\nEntries without task: {$noTask}/" . count($entries) . "\n";
        echo "Unique assignees in response: " . count($uniqueAss)
           . " (" . implode(',', array_keys($uniqueAss)) . ")\n";
        echo "(If >1, comma-separated assignee param works.)\n";
    } else {
        echo "Error: " . ($teRes['error'] ?? '?') . "\n";
    }
}

// === Summary ================================================================
$elapsed = microtime(true) - $GLOBALS['spike_started_at'];
spike_header('Summary');
printf("Total requests: %d\n", $GLOBALS['spike_req_count']);
printf("429 responses:  %d\n", $GLOBALS['spike_rate_errors']);
printf("Elapsed:        %.2fs\n", $elapsed);
