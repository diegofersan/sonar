<?php
/**
 * F01 — Test pure helpers for user-group picking / member extraction.
 *
 * Does NOT hit the network. Exercises _clickup_pick_design_group and
 * clickup_group_member_ids against fixtures that mirror real ClickUp payloads
 * observed in the spike.
 *
 * Exit 0 on success, 1 on failure.
 * Usage: php tests/test_clickup_design_group.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/clickup.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $details = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ $label\n"; }
    else     { $fail++; echo "  ✗ $label" . ($details !== '' ? " — $details" : '') . "\n"; }
}

// --- pick_design_group -----------------------------------------------------
echo "1. _clickup_pick_design_group\n";

$fixture = [
    ['id' => 'a', 'name' => 'Suporte'],
    ['id' => 'b', 'name' => 'design'],
    ['id' => 'c', 'name' => 'financeiro'],
];
$picked = _clickup_pick_design_group($fixture);
check('matches exact lowercase "design"',
    ($picked['id'] ?? null) === 'b',
    json_encode($picked));

$fixture2 = [
    ['id' => 'a', 'name' => 'Design Team'],
    ['id' => 'b', 'name' => 'outra'],
];
$picked = _clickup_pick_design_group($fixture2);
check('matches case-insensitive substring "Design Team"',
    ($picked['id'] ?? null) === 'a');

$fixture3 = [
    ['id' => 'a', 'name' => 'marketing'],
    ['id' => 'b', 'name' => 'gestão'],
];
check('returns null when no group matches',
    _clickup_pick_design_group($fixture3) === null);

check('empty input returns null',
    _clickup_pick_design_group([]) === null);

$fixture4 = [
    ['id' => 'a'], // no 'name' key
    ['id' => 'b', 'name' => 12345], // non-string name
    ['id' => 'c', 'name' => 'Design'],
];
$picked = _clickup_pick_design_group($fixture4);
check('tolerates missing/non-string names and still matches later',
    ($picked['id'] ?? null) === 'c');

$fixture5 = [
    ['id' => 'first', 'name' => 'design ops'],
    ['id' => 'second', 'name' => 'design'],
];
check('picks first hit when multiple match',
    (_clickup_pick_design_group($fixture5)['id'] ?? null) === 'first');

// --- group_member_ids ------------------------------------------------------
echo "\n2. clickup_group_member_ids\n";

// Spike shape: members: [{id, username, …}]
$teamsPulse = [
    'id' => 'grp-design',
    'members' => [
        ['id' => 473908,   'username' => 'Diego'],
        ['id' => 4727509,  'username' => 'Ruben'],
        ['id' => 54230261, 'username' => 'Karllos'],
        ['id' => 87600945, 'username' => 'Carolina'],
    ],
];
$ids = clickup_group_member_ids($teamsPulse);
check('extracts 4 member IDs as strings from Teams-Pulse shape',
    $ids === ['473908', '4727509', '54230261', '87600945'],
    json_encode($ids));

// Classic v2 shape (hypothetical): userid as array of scalars
$classic = ['id' => 'grp-x', 'userid' => [100, 200, 300]];
check('extracts IDs from legacy userid[] shape',
    clickup_group_member_ids($classic) === ['100', '200', '300']);

// Classic v2 shape (hypothetical): userid as a single scalar
$classicScalar = ['id' => 'grp-y', 'userid' => 555];
check('extracts ID from userid scalar',
    clickup_group_member_ids($classicScalar) === ['555']);

// Duplicates get collapsed
$dup = ['members' => [['id' => 1], ['id' => 1], ['id' => 2]]];
check('dedupes duplicate IDs',
    clickup_group_member_ids($dup) === ['1', '2']);

// No members at all
check('no members → []',
    clickup_group_member_ids(['id' => 'empty']) === []);

echo "\nPassed: $pass\nFailed: $fail\n";
exit($fail === 0 ? 0 : 1);
