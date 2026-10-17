<?php
/**
 * Rebuild the draft board (players, keeper assignments, snake schedule) while the draft is in setup.
 *
 *   php draft/build_from_rankings.php [--dry-run]
 *
 * Players: rankings/rankings.json (active players only), bucket = ceil(rank / 4).
 * Keepers: active players on active DB teams whose name matches a draft team.
 *          Role from Teams.Captain_ID / Co_Captain_ID, otherwise protected.
 * Session id, admin token, draft order and team tokens are kept.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$dryRun = in_array('--dry-run', $argv, true);
$dataDir = __DIR__ . '/data';
$read = static function (string $file) use ($dataDir): array {
    $data = json_decode((string) file_get_contents("$dataDir/$file"), true);
    if (!is_array($data)) {
        fwrite(STDERR, "Invalid $file\n");
        exit(1);
    }
    return $data;
};

$session = $read('session.json');
$teams = $read('teams.json');
if (($session['status'] ?? '') !== 'setup') {
    fwrite(STDERR, "Draft status is '{$session['status']}'; rebuild only runs in setup.\n");
    exit(1);
}

$rankings = json_decode((string) file_get_contents(__DIR__ . '/../rankings/rankings.json'), true) ?: [];
usort($rankings, static fn($a, $b) => (int) $a['rank'] <=> (int) $b['rank']);

$draftTeamByName = [];
foreach ($teams as $t) {
    $draftTeamByName[strtolower($t['name'])] = $t;
}

$keeperRows = $db->query(
    "SELECT p.Player_ID, p.Real_Name, t.Team_Name, t.Captain_ID, t.Co_Captain_ID
     FROM Players p
     JOIN Teams t ON t.Team_ID = p.Team_ID
     WHERE p.Status = 'active' AND t.Status = 'active'"
)->fetchAll(PDO::FETCH_ASSOC);

$keepers = [];
$warnings = [];
foreach ($keeperRows as $row) {
    $team = $draftTeamByName[strtolower($row['Team_Name'])] ?? null;
    if ($team === null) {
        $warnings[] = "DB team {$row['Team_Name']} has no draft team; {$row['Real_Name']} left in pool";
        continue;
    }
    $role = 'protected';
    if ((int) $row['Player_ID'] === (int) $row['Captain_ID']) {
        $role = 'captain';
    } elseif ((int) $row['Player_ID'] === (int) $row['Co_Captain_ID']) {
        $role = 'co-captain';
    }
    $keepers[strtolower($row['Real_Name'])] = ['team' => $team, 'role' => $role];
}

$roleLabel = ['captain' => 'Captain', 'co-captain' => 'Co-captain', 'protected' => 'Protected'];
$players = [];
$rankedKeys = [];
foreach ($rankings as $i => $r) {
    $key = strtolower($r['name']);
    $rankedKeys[$key] = true;
    $keeper = $keepers[$key] ?? null;
    $players[] = [
        'id' => $i + 1,
        'display_name' => $r['name'],
        'ranking' => (int) $r['rank'],
        'bucket_index' => (int) ceil(((int) $r['rank']) / 4),
        'race' => $r['race'] ?? 'R',
        'notes' => $keeper ? $roleLabel[$keeper['role']] . ' - ' . $keeper['team']['name'] : '',
        'status' => $keeper ? 'drafted' : 'available',
        'team_id' => $keeper ? (int) $keeper['team']['id'] : null,
        'role' => $keeper['role'] ?? null,
    ];
}
foreach ($keepers as $key => $k) {
    if (!isset($rankedKeys[$key])) {
        $warnings[] = "Keeper $key ({$k['team']['name']}) is not in rankings; run Sync with roster";
    }
}

$now = date('c');
$roleOrder = ['captain' => 0, 'co-captain' => 1, 'protected' => 2];
$events = [];
foreach ($session['draft_order'] as $teamId) {
    $teamPlayers = array_values(array_filter($players, static fn($p) => $p['team_id'] === (int) $teamId));
    usort($teamPlayers, static fn($a, $b) => [$roleOrder[$a['role']], $a['ranking']] <=> [$roleOrder[$b['role']], $b['ranking']]);
    foreach ($teamPlayers as $p) {
        $events[] = [
            'id' => count($events) + 1,
            'pick_number' => null,
            'team_id' => (int) $teamId,
            'player_id' => $p['id'],
            'result' => 'ADMIN_ASSIGN',
            'skip_reason' => null,
            'made_by' => 'ADMIN',
            'made_at' => $now,
            'note' => $roleLabel[$p['role']] . ' assignment',
        ];
    }
}

$available = count(array_filter($players, static fn($p) => $p['status'] === 'available'));
$order = array_map('intval', $session['draft_order']);
$schedule = [];
for ($round = 0; count($schedule) < $available; $round++) {
    foreach ($round % 2 === 0 ? $order : array_reverse($order) as $teamId) {
        if (count($schedule) < $available) {
            $schedule[] = $teamId;
        }
    }
}

$session['pick_schedule'] = $schedule;
$session['total_picks'] = $available;
$session['current_pick_number'] = 1;
$session['current_team_id'] = null;
$session['pick_deadline_at'] = null;

$audit = [[
    'actor' => 'ADMIN',
    'action' => 'REBUILD_FROM_RANKINGS',
    'payload' => ['keepers' => count($events), 'available' => $available],
    'created_at' => $now,
]];

$perTeam = [];
foreach ($events as $e) {
    $perTeam[$e['team_id']] = ($perTeam[$e['team_id']] ?? 0) + 1;
}
echo ($dryRun ? "DRY RUN\n" : '') . 'Players: ' . count($players) . ", keepers: " . count($events) . ", available/picks: $available\n";
foreach ($teams as $t) {
    echo "  {$t['name']}: " . ($perTeam[$t['id']] ?? 0) . " keepers\n";
}
echo 'Schedule: ' . implode(',', $schedule) . "\n";
foreach ($warnings as $w) {
    echo "WARN: $w\n";
}
if ($dryRun) {
    exit(0);
}

$backupDir = __DIR__ . '/data_backup_' . date('Ymd_His');
mkdir($backupDir);
touch("$backupDir/index.php");
foreach (['session.json', 'teams.json', 'players.json', 'events.json', 'audit.json'] as $f) {
    copy("$dataDir/$f", "$backupDir/$f");
}

$write = static function (string $file, array $data) use ($dataDir): void {
    file_put_contents("$dataDir/$file", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
};
$write('players.json', $players);
$write('events.json', $events);
$write('session.json', $session);
$write('audit.json', $audit);
echo 'Written. Backup: ' . basename($backupDir) . "\n";
