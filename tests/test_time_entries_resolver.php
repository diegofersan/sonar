<?php
/**
 * F03 — Unit tests for the pure parent resolver used by the time-entries
 * sync. Exercises the candidate filter + the in-run parent-name cache with
 * a mocked fetcher, no real HTTP.
 *
 * Exit 0 on success, 1 on failure.
 * Usage: php tests/test_time_entries_resolver.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/time_entries.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $details = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ $label\n"; }
    else     { $fail++; echo "  ✗ $label" . ($details !== '' ? " — $details" : '') . "\n"; }
}

// ---------------------------------------------------------------------------
// 1. Candidate filter
// ---------------------------------------------------------------------------
echo "1. time_entry_is_subtask_candidate\n";

check('"Design"      → true',  time_entry_is_subtask_candidate('Design') === true);
check('"design"      → true',  time_entry_is_subtask_candidate('design') === true);
check('"DESIGN"      → true',  time_entry_is_subtask_candidate('DESIGN') === true);
check('"Copy"        → true',  time_entry_is_subtask_candidate('Copy') === true);
check('"copy final"  → true',  time_entry_is_subtask_candidate('copy final') === true);
check('"Design / v2" → true',  time_entry_is_subtask_candidate('Design / v2') === true);
check('"Revisão"     → false', time_entry_is_subtask_candidate('Revisão') === false);
check('null          → false', time_entry_is_subtask_candidate(null) === false);
check('""            → false', time_entry_is_subtask_candidate('') === false);
// Unicode + acentos: "Copy" with accented char must still match because
// we preserve ASCII 'copy' in the lowercased name.
check('"Cópia e Design" → true', time_entry_is_subtask_candidate('Cópia e Design') === true);

// ---------------------------------------------------------------------------
// 2. time_entries_resolve_parents — only candidates get resolved
// ---------------------------------------------------------------------------
echo "\n2. time_entries_resolve_parents\n";

$entries = [
    // Subtask → should be resolved.
    ['task' => ['id' => 'T_DESIGN_1', 'name' => 'Design']],
    // Duplicate of above — should NOT trigger a second lookup.
    ['task' => ['id' => 'T_DESIGN_1', 'name' => 'Design']],
    // Post itself — filtered out by the candidate check.
    ['task' => ['id' => 'T_POST_A',   'name' => 'Carousel sobre X']],
    // Another subtask but with a DIFFERENT parent.
    ['task' => ['id' => 'T_COPY_2',   'name' => 'Copy']],
    // Another subtask sharing the SAME parent as T_DESIGN_1 — parent name
    // must come from cache (no extra fetchTask call for the parent id).
    ['task' => ['id' => 'T_COPY_1',   'name' => 'Copy']],
    // Malformed: no task id → skip silently.
    ['task' => []],
];

$calls = [];
$fetchTask = function (string $id) use (&$calls) {
    $calls[] = $id;
    $responses = [
        'T_DESIGN_1' => ['id' => 'T_DESIGN_1', 'name' => 'Design', 'parent' => 'T_POST_A'],
        'T_COPY_1'   => ['id' => 'T_COPY_1',   'name' => 'Copy',   'parent' => 'T_POST_A'],
        'T_COPY_2'   => ['id' => 'T_COPY_2',   'name' => 'Copy',   'parent' => 'T_POST_B'],
        'T_POST_A'   => ['id' => 'T_POST_A',   'name' => 'Carousel sobre X'],
        'T_POST_B'   => ['id' => 'T_POST_B',   'name' => 'Reels sobre Y'],
    ];
    return $responses[$id] ?? null;
};

$map = time_entries_resolve_parents($entries, $fetchTask);

check('map has 3 candidate keys', count($map) === 3, (string) count($map));
check('T_DESIGN_1 resolves to T_POST_A',
    ($map['T_DESIGN_1']['parent_task_id'] ?? '') === 'T_POST_A');
check('T_DESIGN_1 parent name = "Carousel sobre X"',
    ($map['T_DESIGN_1']['parent_name'] ?? '') === 'Carousel sobre X');
check('T_COPY_1 shares parent T_POST_A',
    ($map['T_COPY_1']['parent_task_id'] ?? '') === 'T_POST_A'
    && ($map['T_COPY_1']['parent_name'] ?? '') === 'Carousel sobre X');
check('T_COPY_2 resolves to T_POST_B',
    ($map['T_COPY_2']['parent_task_id'] ?? '') === 'T_POST_B'
    && ($map['T_COPY_2']['parent_name'] ?? '') === 'Reels sobre Y');

// Call accounting: 3 subtask fetches (dedup) + 2 parent fetches (T_POST_A
// cached the second time) = 5 total. Post itself never fetched.
check('fetchTask called exactly 5 times',
    count($calls) === 5, 'got ' . count($calls) . ' — ' . implode(',', $calls));
check('T_POST_A only fetched once',
    count(array_keys($calls, 'T_POST_A')) === 1);
check('T_POST_A (itself non-candidate) never fetched as subtask',
    !in_array('T_POST_A_AS_SUBTASK', $calls, true));

// ---------------------------------------------------------------------------
// 3. Top-level post with no `parent` key → no entry in map
// ---------------------------------------------------------------------------
echo "\n3. Candidates without parent\n";

$entries2 = [
    ['task' => ['id' => 'T_ORPHAN', 'name' => 'Design standalone']],
];
$fetchTask2 = function (string $id) {
    // Returns a task that explicitly has parent = null.
    if ($id === 'T_ORPHAN') return ['id' => 'T_ORPHAN', 'name' => 'Design standalone', 'parent' => null];
    return null;
};
$map2 = time_entries_resolve_parents($entries2, $fetchTask2);
check('orphan candidate produces empty map', empty($map2), json_encode($map2));

// ---------------------------------------------------------------------------
// 4. Fetcher returns null (API error) → skip gracefully
// ---------------------------------------------------------------------------
echo "\n4. fetchTask returns null\n";

$entries3 = [['task' => ['id' => 'T_404', 'name' => 'Design']]];
$fetchTask3 = function (string $id) { return null; };
$map3 = time_entries_resolve_parents($entries3, $fetchTask3);
check('API failure → empty map (no exception)', empty($map3));

// ---------------------------------------------------------------------------
// 5. Progress callback fires
// ---------------------------------------------------------------------------
echo "\n5. Progress callback\n";

$entries4 = [
    ['task' => ['id' => 'T_D', 'name' => 'Design']],
    ['task' => ['id' => 'T_C', 'name' => 'Copy']],
];
$fetchTask4 = function (string $id) {
    return ['id' => $id, 'parent' => 'P1', 'name' => $id === 'P1' ? 'Post' : $id];
};
$progress = [];
$onProgress = function (int $done, int $total) use (&$progress) {
    $progress[] = [$done, $total];
};
time_entries_resolve_parents($entries4, $fetchTask4, $onProgress);
// 2 candidate fetches + 1 parent fetch (P1 cached) = 3 progress calls.
check('progress called 3 times (2 candidates + 1 parent)',
    count($progress) === 3, 'got ' . count($progress));
check('progress totals reflect candidate count',
    $progress[0][1] === 2 && $progress[count($progress)-1][1] === 2);

// ---------------------------------------------------------------------------
echo "\nPassed: $pass\nFailed: $fail\n";
exit($fail === 0 ? 0 : 1);
