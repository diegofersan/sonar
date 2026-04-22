<?php
/**
 * F02 — Spike: medir qualidade dos dados para o plano de carga semanal.
 *
 * Usage:
 *   php tests/spike_workload.php <token> <team_id> <user_id> [list_id]
 *
 * Read-only. Não toca em data/sonar.db (usa PDO em modo `mode=ro`).
 * Faz **uma** chamada ClickUp para amostrar um payload de tasks e medir a
 * presença de `time_estimate` no campo retornado (que hoje é descartado no
 * upsert — confirmar antes de escrever a migration).
 *
 * Valida as decisões do `spec.md`:
 *   - D1 (política para estimate null)
 *   - D2 (modelo de distribuição start/due)
 *   - D4 (status terminais a ignorar)
 *   - D5 (parent vs subtask duplicação)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/clickup.php';

if ($argc < 4) {
    fwrite(STDERR, "Usage: php tests/spike_workload.php <token> <team_id> <user_id> [list_id]\n");
    fwrite(STDERR, "  <user_id> é usado como filtro de assignees na chamada ClickUp (amostra).\n");
    exit(1);
}

$token   = $argv[1];
$teamId  = $argv[2];
$userId  = $argv[3];
$listId  = $argv[4] ?? null;

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

function section(string $title): void {
    echo "\n" . str_repeat('=', 70) . "\n$title\n" . str_repeat('=', 70) . "\n";
}

function pct(int $n, int $total): string {
    if ($total === 0) return 'n/a';
    return sprintf('%5.1f%%  (%d / %d)', ($n / $total) * 100, $n, $total);
}

function verdict(string $label, bool $ok, string $detail): void {
    $tag = $ok ? '[OK]        ' : '[REAVALIAR] ';
    echo "  $tag $label — $detail\n";
}

// ----------------------------------------------------------------------------
// 1. Qualidade dos dados no SQLite local
// ----------------------------------------------------------------------------

$dbPath = __DIR__ . '/../data/sonar.db';
if (!is_file($dbPath)) {
    fwrite(STDERR, "ERRO: $dbPath não existe. Corre um sync antes do spike.\n");
    exit(2);
}

$pdo = new PDO('sqlite:' . $dbPath . '?mode=ro');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

section('1. Tasks no cache SQLite (workspace ' . $teamId . ')');

$row = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN start_date IS NOT NULL THEN 1 ELSE 0 END) AS has_start,
            SUM(CASE WHEN due_date   IS NOT NULL THEN 1 ELSE 0 END) AS has_due,
            SUM(CASE WHEN parent_id  IS NOT NULL THEN 1 ELSE 0 END) AS has_parent
       FROM tasks
      WHERE workspace_id = " . $pdo->quote($teamId)
)->fetch();

$total     = (int) ($row['total']      ?? 0);
$hasStart  = (int) ($row['has_start']  ?? 0);
$hasDue    = (int) ($row['has_due']    ?? 0);
$hasParent = (int) ($row['has_parent'] ?? 0);

echo "Total de tasks:     $total\n";
echo "Com start_date:     " . pct($hasStart,  $total) . "\n";
echo "Com due_date:       " . pct($hasDue,    $total) . "\n";
echo "Com parent_id:      " . pct($hasParent, $total) . "  (subtasks)\n";

// Tasks que SÃO parents (têm children na tabela) — candidatas a double-count D5
$parentCountRow = $pdo->query(
    "SELECT COUNT(DISTINCT parent_id) AS n
       FROM tasks
      WHERE workspace_id = " . $pdo->quote($teamId) . "
        AND parent_id IS NOT NULL"
)->fetch();
$nParents = (int) ($parentCountRow['n'] ?? 0);
echo "Tasks com subtasks: $nParents  (distintos parent_id referenciados)\n";

// ----------------------------------------------------------------------------
// 2. Status terminais (D4)
// ----------------------------------------------------------------------------

section('2. Distribuição de status_name (D4)');

$stmt = $pdo->query(
    "SELECT status_name, COUNT(*) AS n
       FROM tasks
      WHERE workspace_id = " . $pdo->quote($teamId) . "
      GROUP BY status_name
      ORDER BY n DESC"
);
$statusRows = $stmt->fetchAll();
foreach ($statusRows as $r) {
    printf("  %-30s %6d\n", (string) ($r['status_name'] ?? '(null)'), (int) $r['n']);
}
echo "\nNota: confirma com o utilizador quais destes são 'terminais' (não entram\n";
echo "no forecast). Heurística F01: qualquer status de nome 'complete', 'closed',\n";
echo "'cancelled', 'done' conta como terminal.\n";

// ----------------------------------------------------------------------------
// 3. Amostra ClickUp — presença de time_estimate no payload
// ----------------------------------------------------------------------------

section('3. Amostra ClickUp: presença de time_estimate (D1)');

$endpoint = "/team/{$teamId}/task?"
    . "assignees[]={$userId}"
    . "&subtasks=true"
    . "&include_closed=false"
    . "&page=0";
if ($listId) {
    $endpoint .= "&list_ids[]={$listId}";
}
echo "GET $endpoint\n";

$res = clickup_api_get($endpoint, $token);
if (empty($res['ok'])) {
    fwrite(STDERR, "ERRO na API: status=" . ($res['status'] ?? '?')
        . " body=" . json_encode($res['body'] ?? null) . "\n");
    exit(3);
}

$sample = $res['body']['tasks'] ?? [];
$nSample = count($sample);
echo "Sample size:  $nSample tasks (1 página, page=0)\n\n";

$withEstimate = 0;
$withStartS   = 0;
$withDueS     = 0;
$withParentS  = 0;
$terminalS    = 0;

// Para D5: parents com estimate próprio + pelo menos uma subtask também com estimate
$parentEstimates = []; // parent_id => bool (parent tem estimate > 0)
$childEstimates  = []; // parent_id => count(children com estimate > 0)
$statusIsClosed  = function (array $t): bool {
    $status = strtolower((string) ($t['status']['status'] ?? ''));
    $type   = strtolower((string) ($t['status']['type']   ?? ''));
    return $type === 'closed' || $type === 'done'
        || in_array($status, ['complete', 'closed', 'cancelled', 'done'], true);
};

foreach ($sample as $t) {
    $est = (int) ($t['time_estimate'] ?? 0);
    if ($est > 0)                                       $withEstimate++;
    if (!empty($t['start_date']))                       $withStartS++;
    if (!empty($t['due_date']))                         $withDueS++;
    if (!empty($t['parent']))                           $withParentS++;
    if ($statusIsClosed($t))                            $terminalS++;

    $myId    = (string) ($t['id'] ?? '');
    $parentId = (string) ($t['parent'] ?? '');

    if ($parentId !== '' && $est > 0) {
        $childEstimates[$parentId] = ($childEstimates[$parentId] ?? 0) + 1;
    }
    if ($est > 0) {
        $parentEstimates[$myId] = true;
    }
}

echo "Com time_estimate > 0:  " . pct($withEstimate, $nSample) . "\n";
echo "Com start_date:         " . pct($withStartS,   $nSample) . "\n";
echo "Com due_date:           " . pct($withDueS,     $nSample) . "\n";
echo "Com parent (subtask):   " . pct($withParentS,  $nSample) . "\n";
echo "Status terminal:        " . pct($terminalS,    $nSample) . "\n";

// D5: cruzar parents com estimate próprio E filhos no sample também com estimate
$doubleCountCandidates = 0;
foreach ($childEstimates as $parentId => $nChildrenWithEst) {
    if (!empty($parentEstimates[$parentId])) {
        $doubleCountCandidates++;
    }
}
echo "Parents com estimate + filhos com estimate no sample: $doubleCountCandidates\n";
echo "  (se > 0, regra D5 'subtasks ganham, parent ignora' evita double-count.)\n";

// ----------------------------------------------------------------------------
// 4. Veredicto sobre as decisões da spec
// ----------------------------------------------------------------------------

section('4. Veredicto sobre decisões da spec');

$pctEstimate = $nSample > 0 ? ($withEstimate / $nSample) * 100 : 0.0;
$pctStartS   = $nSample > 0 ? ($withStartS   / $nSample) * 100 : 0.0;

verdict('D1 (null = 0h silencioso + contador)',
    $pctEstimate >= 30.0,
    sprintf('%.1f%% das tasks têm estimate > 0 %s',
        $pctEstimate,
        $pctEstimate < 30 ? '→ considera mudar D1 para warning visual mais forte'
                         : '→ a política actual chega'));

verdict('D2 (modelo C — fallback 1 dia quando start null)',
    $pctStartS >= 20.0 || $nSample < 5,
    sprintf('%.1f%% com start_date → %s',
        $pctStartS,
        $pctStartS < 20 ? 'fallback vai ser o caminho dominante; manter modelo C é OK mas a precisão depende do due_date'
                       : 'distribuição start→due será relevante para muitas tasks'));

verdict('D4 (ignorar status terminais)',
    true,
    'lista de status acima; confirmar com utilizador quais são terminais no workspace.');

verdict('D5 (subtasks ganham em double-count)',
    true,
    sprintf('%d candidato(s) a double-count no sample. Regra de D5 protege.',
        $doubleCountCandidates));

echo "\nFim do spike.\n";
