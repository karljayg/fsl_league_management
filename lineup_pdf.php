<?php
/**
 * Print/PDF view for lineup. GET: teamA, teamB, rule, playersPerGroup, slots (JSON array of {playerA, playerB}).
 * Enhanced styling with team logos, rank, group, race, team and player records. Print → Save as PDF.
 */
require_once __DIR__ . '/includes/team_logo.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/season_utils.php';

$teamA = isset($_GET['teamA']) ? trim((string) $_GET['teamA']) : '';
$teamB = isset($_GET['teamB']) ? trim((string) $_GET['teamB']) : '';
$rule = isset($_GET['rule']) ? (int) $_GET['rule'] : 2;
$playersPerGroup = isset($_GET['playersPerGroup']) ? (int) $_GET['playersPerGroup'] : 4;
$slotsJson = isset($_GET['slots']) ? $_GET['slots'] : '[]';
$slots = json_decode($slotsJson, true);
if (!is_array($slots)) {
    $slots = [];
}
$slots = array_slice($slots, 0, 6);

$logoA = $teamA ? getTeamLogo($teamA, '256px') : null;
$logoB = $teamB ? getTeamLogo($teamB, '256px') : null;

$currentSeason = getCurrentSeason($db);
$displaySeason = isset($_GET['season']) ? (int) $_GET['season'] : $currentSeason;
$lineupDateRaw = isset($_GET['date']) ? trim((string) $_GET['date']) : date('Y-m-d');
$lineupDate = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $lineupDateRaw)) ? $lineupDateRaw : date('Y-m-d');
$lineupDateFormatted = date('F j, Y', strtotime($lineupDate));
$teamRecordA = ['season' => ['w' => 0, 'l' => 0], 'overall' => ['w' => 0, 'l' => 0]];
$teamRecordB = ['season' => ['w' => 0, 'l' => 0], 'overall' => ['w' => 0, 'l' => 0]];
$playerRecords = [];

try {
    if ($teamA || $teamB) {
        $teamNames = array_filter([$teamA, $teamB]);
        $placeholders = implode(',', array_fill(0, count($teamNames), '?'));
        $stmt = $db->prepare("SELECT Team_ID, Team_Name FROM Teams WHERE Team_Name IN ($placeholders)");
        $stmt->execute(array_values($teamNames));
        $teamIds = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $teamIds[$row['Team_Name']] = (int) $row['Team_ID'];
        }
        $tidA = $teamIds[$teamA] ?? null;
        $tidB = $teamIds[$teamB] ?? null;

        if ($tidA !== null) {
            $stmt = $db->prepare("
                SELECT SUM(CASE WHEN s.winner_team_id = ? THEN 1 ELSE 0 END) as wins, SUM(CASE WHEN (s.team1_id = ? OR s.team2_id = ?) AND s.winner_team_id IS NOT NULL AND s.winner_team_id != ? THEN 1 ELSE 0 END) as losses
                FROM fsl_schedule s WHERE s.status = 'completed' AND s.season = ?
            ");
            $stmt->execute([$tidA, $tidA, $tidA, $tidA, $displaySeason]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $teamRecordA['season'] = ['w' => (int)($r['wins'] ?? 0), 'l' => (int)($r['losses'] ?? 0)];
            $stmt = $db->prepare("
                SELECT SUM(CASE WHEN s.winner_team_id = ? THEN 1 ELSE 0 END) as wins, SUM(CASE WHEN (s.team1_id = ? OR s.team2_id = ?) AND s.winner_team_id IS NOT NULL AND s.winner_team_id != ? THEN 1 ELSE 0 END) as losses
                FROM fsl_schedule s WHERE s.status = 'completed'
            ");
            $stmt->execute([$tidA, $tidA, $tidA, $tidA]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $teamRecordA['overall'] = ['w' => (int)($r['wins'] ?? 0), 'l' => (int)($r['losses'] ?? 0)];
        }
        if ($tidB !== null) {
            $stmt = $db->prepare("
                SELECT SUM(CASE WHEN s.winner_team_id = ? THEN 1 ELSE 0 END) as wins, SUM(CASE WHEN (s.team1_id = ? OR s.team2_id = ?) AND s.winner_team_id IS NOT NULL AND s.winner_team_id != ? THEN 1 ELSE 0 END) as losses
                FROM fsl_schedule s WHERE s.status = 'completed' AND s.season = ?
            ");
            $stmt->execute([$tidB, $tidB, $tidB, $tidB, $displaySeason]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $teamRecordB['season'] = ['w' => (int)($r['wins'] ?? 0), 'l' => (int)($r['losses'] ?? 0)];
            $stmt = $db->prepare("
                SELECT SUM(CASE WHEN s.winner_team_id = ? THEN 1 ELSE 0 END) as wins, SUM(CASE WHEN (s.team1_id = ? OR s.team2_id = ?) AND s.winner_team_id IS NOT NULL AND s.winner_team_id != ? THEN 1 ELSE 0 END) as losses
                FROM fsl_schedule s WHERE s.status = 'completed'
            ");
            $stmt->execute([$tidB, $tidB, $tidB, $tidB]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $teamRecordB['overall'] = ['w' => (int)($r['wins'] ?? 0), 'l' => (int)($r['losses'] ?? 0)];
        }
    }

    $playerNames = [];
    foreach ($slots as $s) {
        $type = isset($s['type']) ? trim((string) $s['type']) : '1vs1';
        if ($type === '2v2') {
            foreach (['playerA1', 'playerA2', 'playerB1', 'playerB2'] as $key) {
                $name = isset($s[$key]) ? trim((string) $s[$key]) : '';
                if ($name !== '') $playerNames[$name] = true;
            }
        } else {
            $a = isset($s['playerA']) ? trim((string) $s['playerA']) : '';
            $b = isset($s['playerB']) ? trim((string) $s['playerB']) : '';
            if ($a !== '') $playerNames[$a] = true;
            if ($b !== '') $playerNames[$b] = true;
        }
    }
    $playerNames = array_keys($playerNames);
    if (!empty($playerNames)) {
        $placeholders = implode(',', array_fill(0, count($playerNames), '?'));
        $stmt = $db->prepare("SELECT Player_ID, Real_Name FROM Players WHERE Real_Name IN ($placeholders)");
        $stmt->execute(array_values($playerNames));
        $nameToPid = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $nameToPid[$row['Real_Name']] = (int) $row['Player_ID'];
            $nameToPid[strtolower(trim($row['Real_Name']))] = (int) $row['Player_ID'];
        }
        foreach ($playerNames as $name) {
            $pid = $nameToPid[$name] ?? $nameToPid[strtolower(trim($name))] ?? null;
            if ($pid === null) continue;
            $playerRecords[$name] = [
                'season' => ['sets_w' => 0, 'sets_l' => 0, 'maps_w' => 0, 'maps_l' => 0],
                'overall' => ['sets_w' => 0, 'sets_l' => 0, 'maps_w' => 0, 'maps_l' => 0]
            ];
            $stmt = $db->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN winner_player_id = ? THEN 1 ELSE 0 END), 0) as sets_w,
                    COALESCE(SUM(CASE WHEN loser_player_id = ? THEN 1 ELSE 0 END), 0) as sets_l,
                    COALESCE(SUM(CASE WHEN winner_player_id = ? THEN map_win ELSE map_loss END), 0) as maps_w,
                    COALESCE(SUM(CASE WHEN winner_player_id = ? THEN map_loss ELSE map_win END), 0) as maps_l
                FROM fsl_matches WHERE season = ? AND (winner_player_id = ? OR loser_player_id = ?)
            ");
            $stmt->execute([$pid, $pid, $pid, $pid, $displaySeason, $pid, $pid]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $playerRecords[$name]['season'] = [
                'sets_w' => (int)($r['sets_w'] ?? 0), 'sets_l' => (int)($r['sets_l'] ?? 0),
                'maps_w' => (int)($r['maps_w'] ?? 0), 'maps_l' => (int)($r['maps_l'] ?? 0)
            ];
            $stmt = $db->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN winner_player_id = ? THEN 1 ELSE 0 END), 0) as sets_w,
                    COALESCE(SUM(CASE WHEN loser_player_id = ? THEN 1 ELSE 0 END), 0) as sets_l,
                    COALESCE(SUM(CASE WHEN winner_player_id = ? THEN map_win ELSE map_loss END), 0) as maps_w,
                    COALESCE(SUM(CASE WHEN winner_player_id = ? THEN map_loss ELSE map_win END), 0) as maps_l
                FROM fsl_matches WHERE winner_player_id = ? OR loser_player_id = ?
            ");
            $stmt->execute([$pid, $pid, $pid, $pid, $pid, $pid]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $playerRecords[$name]['overall'] = [
                'sets_w' => (int)($r['sets_w'] ?? 0), 'sets_l' => (int)($r['sets_l'] ?? 0),
                'maps_w' => (int)($r['maps_w'] ?? 0), 'maps_l' => (int)($r['maps_l'] ?? 0)
            ];
        }
    }
} catch (PDOException $e) {
    // leave records at defaults
}

$rankingsFile = __DIR__ . '/rankings/rankings.json';
$rankings = [];
if (file_exists($rankingsFile)) {
    $rankings = json_decode(file_get_contents($rankingsFile), true) ?? [];
}
$nameToPlayer = [];
foreach ($rankings as $p) {
    $name = isset($p['name']) ? trim($p['name']) : '';
    if ($name) {
        $nameToPlayer[$name] = [
            'rank' => (int) ($p['rank'] ?? 0),
            'race' => isset($p['race']) ? $p['race'] : 'R',
        ];
        $nameToPlayer[strtolower($name)] = $nameToPlayer[$name];
    }
}
function getPlayerForPdf($name, $nameToPlayer) {
    if (!$name) return null;
    $n = trim($name);
    return $nameToPlayer[$n] ?? $nameToPlayer[strtolower($n)] ?? null;
}
function groupForRankPdf($rank, $ppg) {
    if (!$rank || $ppg < 1) return null;
    return (int) ceil($rank / $ppg);
}

$raceIcons = ['T' => 'terran_icon.png', 'P' => 'protoss_icon.png', 'Z' => 'zerg_icon.png', 'R' => 'random_icon.png'];
$title = $teamA && $teamB ? htmlspecialchars($teamA) . ' vs ' . htmlspecialchars($teamB) . ' – Lineup' : 'FSL Lineup';

$outputHtml = isset($_GET['output']) && $_GET['output'] === 'html';
// Always use absolute base URL so images load in print window and in saved HTML
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$script = $_SERVER['SCRIPT_NAME'] ?? '/';
$imgBase = $protocol . '://' . $host . rtrim(dirname($script), '/') . '/';

if ($outputHtml) {
    $downloadFilename = 'fsl-lineup-' . preg_replace('/[^0-9-]/', '', $lineupDate) . '.html';
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&family=Exo+2:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Exo 2', sans-serif;
            background: linear-gradient(135deg, #0a0a0f 0%, #1a1a2e 50%, #0a0a0f 100%);
            color: #fff;
            padding: 2rem;
            max-width: 720px;
            margin: 0 auto;
            min-height: 100vh;
        }
        .lineup-pdf-header {
            text-align: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(108, 92, 231, 0.3);
        }
        .lineup-pdf-header h1 {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.75rem;
            font-weight: 700;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 0.75rem;
        }
        .lineup-pdf-teams {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }
        .lineup-pdf-team {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.35rem;
        }
        .lineup-pdf-team img {
            width: 64px;
            height: 64px;
            object-fit: contain;
            border-radius: 8px;
            border: 1px solid rgba(108, 92, 231, 0.4);
        }
        .lineup-pdf-team .team-name { font-weight: 600; font-size: 1rem; color: #e0e0e0; }
        .lineup-pdf-team .team-record { font-size: 0.8rem; color: #888; margin-top: 0.2rem; }
        .lineup-pdf-team .team-record .record-num { color: #fff; }
        .lineup-pdf-teams .vs-divider { color: #00b894; font-weight: 700; font-size: 1.25rem; font-family: 'Rajdhani', sans-serif; }
        .lineup-pdf-meta { font-size: 0.85rem; color: #888; margin-top: 0.5rem; }
        .lineup-table .player-record { font-size: 0.75rem; color: #888; margin-top: 0.15rem; }
        .lineup-table .player-record .record-num { color: #fff; }
        .lineup-table .line-type-tag { font-size: 0.7rem; color: #00b894; margin-left: 0.35rem; }
        .lineup-pdf-table-wrap { background: rgba(0, 0, 0, 0.25); border-radius: 10px; overflow: hidden; border: 1px solid rgba(108, 92, 231, 0.2); }
        table.lineup-table { width: 100%; border-collapse: collapse; }
        .lineup-table th, .lineup-table td { padding: 0.6rem 0.75rem; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .lineup-table th { background: rgba(108, 92, 231, 0.25); color: #fff; font-weight: 600; font-size: 0.9rem; }
        .lineup-table tr:last-child td { border-bottom: none; }
        .lineup-table tr:nth-child(even) td { background: rgba(255,255,255,0.03); }
        .lineup-table .col-slot { width: 2.5rem; text-align: center; color: #888; font-weight: 600; }
        .lineup-table .col-vs { width: 2.5rem; text-align: center; color: #00b894; font-weight: 700; font-size: 0.9rem; }
        .lineup-table .player-cell { vertical-align: middle; }
        .lineup-table .player-name { font-weight: 600; margin-bottom: 0.2rem; }
        .lineup-table .player-details { display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap; }
        .lineup-table .player-details .rank-badge {
            width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe); border-radius: 50%;
            font-family: 'Rajdhani', sans-serif; font-size: 0.75rem; font-weight: 700; color: #fff;
        }
        .lineup-table .player-details .race-icon { width: 18px; height: 18px; vertical-align: middle; }
        .lineup-table .player-details .group-badge {
            font-size: 0.7rem; color: #a29bfe; background: rgba(108, 92, 231, 0.25);
            padding: 0.1rem 0.4rem; border-radius: 10px;
        }
        .lineup-table tr.pdf-row-g1  { border-left: 4px solid #00b894; }
        .lineup-table tr.pdf-row-g2  { border-left: 4px solid #00a187; }
        .lineup-table tr.pdf-row-g3  { border-left: 4px solid #008f7a; }
        .lineup-table tr.pdf-row-g4  { border-left: 4px solid #4a90e2; }
        .lineup-table tr.pdf-row-g5  { border-left: 4px solid #3d7bc7; }
        .lineup-table tr.pdf-row-g6  { border-left: 4px solid #3066ac; }
        .lineup-table tr.pdf-row-g7  { border-left: 4px solid #8b6fc4; }
        .lineup-table tr.pdf-row-g8  { border-left: 4px solid #7b5fb4; }
        .lineup-table tr.pdf-row-g9  { border-left: 4px solid #6b4fa4; }
        .lineup-table tr.pdf-row-g10 { border-left: 4px solid #5b5b6b; }
        .lineup-table tr.pdf-row-g11 { border-left: 4px solid #4b4b5b; }
        .lineup-table tr.pdf-row-g12 { border-left: 4px solid #3b3b4b; }
        .no-print { margin-top: 1.5rem; text-align: center; }
        .no-print button {
            padding: 0.6rem 1.2rem;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            border: none;
            border-radius: 6px;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
        }
        @media print {
            body { padding: 1rem; background: #fff; color: #111; }
            .lineup-pdf-header h1 { -webkit-text-fill-color: #333; color: #333; }
            .lineup-pdf-meta { color: #666; }
            .lineup-pdf-team .team-name { color: #333; }
            .lineup-pdf-team .team-record { color: #555; }
            .lineup-pdf-team .team-record .record-num { color: #111; font-weight: 600; }
            .lineup-table .player-record { color: #555; }
            .lineup-table .player-record .record-num { color: #111; font-weight: 600; }
            .lineup-pdf-table-wrap { background: #f8f9fa; border-color: #dee2e6; }
            .lineup-table th { background: #e9ecef; color: #212529; }
            .lineup-table td { border-color: #dee2e6; }
            .lineup-table tr:nth-child(even) td { background: #fff; }
            .lineup-table .player-details .rank-badge { background: #6c5ce7; color: #fff; }
            .lineup-table .player-details .group-badge { color: #5a4fd6; background: #e8e6f7; }
            .lineup-table .player-record { color: #555; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="lineup-pdf-header">
        <h1>FSL Lineup</h1>
        <div class="lineup-pdf-teams">
            <div class="lineup-pdf-team">
                <?php if ($logoA): ?><img src="<?= htmlspecialchars($imgBase . $logoA) ?>" alt="<?= htmlspecialchars($teamA) ?>"><?php endif; ?>
                <span class="team-name"><?= $teamA ? htmlspecialchars($teamA) : 'Team A' ?></span>
                <span class="team-record">Season <?= $displaySeason ?>: <span class="record-num"><?= $teamRecordA['season']['w'] ?>-<?= $teamRecordA['season']['l'] ?></span> · Alltime: <span class="record-num"><?= $teamRecordA['overall']['w'] ?>-<?= $teamRecordA['overall']['l'] ?></span></span>
            </div>
            <span class="vs-divider">vs</span>
            <div class="lineup-pdf-team">
                <?php if ($logoB): ?><img src="<?= htmlspecialchars($imgBase . $logoB) ?>" alt="<?= htmlspecialchars($teamB) ?>"><?php endif; ?>
                <span class="team-name"><?= $teamB ? htmlspecialchars($teamB) : 'Team B' ?></span>
                <span class="team-record">Season <?= $displaySeason ?>: <span class="record-num"><?= $teamRecordB['season']['w'] ?>-<?= $teamRecordB['season']['l'] ?></span> · Alltime: <span class="record-num"><?= $teamRecordB['overall']['w'] ?>-<?= $teamRecordB['overall']['l'] ?></span></span>
            </div>
        </div>
        <p class="lineup-pdf-meta">Lineup date: <?= htmlspecialchars($lineupDateFormatted) ?> · Season <?= (int) $displaySeason ?> · Rule: ±<?= (int) $rule ?> groups · <?= (int) $playersPerGroup ?> players per group</p>
    </div>

    <div class="lineup-pdf-table-wrap">
    <table class="lineup-table">
        <thead>
            <tr>
                <th class="col-slot">#</th>
                <th>Team A</th>
                <th class="col-vs">vs</th>
                <th>Team B</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $i = 1;
            foreach ($slots as $s):
                $type = isset($s['type']) ? trim((string) $s['type']) : '1vs1';
                $is2v2 = ($type === '2v2');
                if ($is2v2):
                    $a1 = isset($s['playerA1']) ? trim((string) $s['playerA1']) : '';
                    $a2 = isset($s['playerA2']) ? trim((string) $s['playerA2']) : '';
                    $b1 = isset($s['playerB1']) ? trim((string) $s['playerB1']) : '';
                    $b2 = isset($s['playerB2']) ? trim((string) $s['playerB2']) : '';
                    $pA1 = getPlayerForPdf($a1, $nameToPlayer);
                    $pA2 = getPlayerForPdf($a2, $nameToPlayer);
                    $pB1 = getPlayerForPdf($b1, $nameToPlayer);
                    $pB2 = getPlayerForPdf($b2, $nameToPlayer);
                    $gA1 = $pA1 ? groupForRankPdf($pA1['rank'], $playersPerGroup) : null;
                    $gA2 = $pA2 ? groupForRankPdf($pA2['rank'], $playersPerGroup) : null;
                    $gB1 = $pB1 ? groupForRankPdf($pB1['rank'], $playersPerGroup) : null;
                    $gB2 = $pB2 ? groupForRankPdf($pB2['rank'], $playersPerGroup) : null;
                    $rowGroup = $gA1 ?: $gA2 ?: $gB1 ?: $gB2;
                    $rowClass = $rowGroup ? ' pdf-row-g' . $rowGroup : '';
                    $namesA = array_filter([$a1, $a2]);
                    $namesB = array_filter([$b1, $b2]);
                    $displayA = count($namesA) ? implode(' & ', $namesA) : '—';
                    $displayB = count($namesB) ? implode(' & ', $namesB) : '—';
            ?>
            <tr class="<?= $rowClass ?>">
                <td class="col-slot"><?= $i ?></td>
                <td class="player-cell">
                    <div class="player-name"><?= htmlspecialchars($displayA) ?><?= $is2v2 ? ' <span class="line-type-tag">2v2</span>' : '' ?></div>
                    <?php if ($pA1 || $pA2): ?>
                    <div class="player-details">
                        <?php if ($pA1): ?><span class="rank-badge"><?= $pA1['rank'] ?></span><img src="<?= htmlspecialchars($imgBase . 'images/' . ($raceIcons[$pA1['race']] ?? 'random_icon.png')) ?>" alt="" class="race-icon"><span class="group-badge">G<?= $gA1 ?></span><?php endif; ?>
                        <?php if ($pA2): ?> · <span class="rank-badge"><?= $pA2['rank'] ?></span><img src="<?= htmlspecialchars($imgBase . 'images/' . ($raceIcons[$pA2['race']] ?? 'random_icon.png')) ?>" alt="" class="race-icon"><span class="group-badge">G<?= $gA2 ?></span><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td class="col-vs">vs</td>
                <td class="player-cell">
                    <div class="player-name"><?= htmlspecialchars($displayB) ?></div>
                    <?php if ($pB1 || $pB2): ?>
                    <div class="player-details">
                        <?php if ($pB1): ?><span class="rank-badge"><?= $pB1['rank'] ?></span><img src="<?= htmlspecialchars($imgBase . 'images/' . ($raceIcons[$pB1['race']] ?? 'random_icon.png')) ?>" alt="" class="race-icon"><span class="group-badge">G<?= $gB1 ?></span><?php endif; ?>
                        <?php if ($pB2): ?> · <span class="rank-badge"><?= $pB2['rank'] ?></span><img src="<?= htmlspecialchars($imgBase . 'images/' . ($raceIcons[$pB2['race']] ?? 'random_icon.png')) ?>" alt="" class="race-icon"><span class="group-badge">G<?= $gB2 ?></span><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
                else:
                    $a = isset($s['playerA']) ? trim((string) $s['playerA']) : '';
                    $b = isset($s['playerB']) ? trim((string) $s['playerB']) : '';
                    $playerA = getPlayerForPdf($a, $nameToPlayer);
                    $playerB = getPlayerForPdf($b, $nameToPlayer);
                    $groupA = $playerA ? groupForRankPdf($playerA['rank'], $playersPerGroup) : null;
                    $groupB = $playerB ? groupForRankPdf($playerB['rank'], $playersPerGroup) : null;
                    $rowGroup = $groupA ?: $groupB;
                    $rowClass = $rowGroup ? ' pdf-row-g' . $rowGroup : '';
                    $displayA = $a !== '' ? $a : '—';
                    $displayB = $b !== '' ? $b : '—';
                    $iconA = $playerA && isset($raceIcons[$playerA['race']]) ? $raceIcons[$playerA['race']] : 'random_icon.png';
                    $iconB = $playerB && isset($raceIcons[$playerB['race']]) ? $raceIcons[$playerB['race']] : 'random_icon.png';
            ?>
            <tr class="<?= $rowClass ?>">
                <td class="col-slot"><?= $i ?></td>
                <td class="player-cell">
                    <div class="player-name"><?= htmlspecialchars($displayA) ?></div>
                    <?php if ($playerA): ?>
                    <div class="player-details">
                        <span class="rank-badge"><?= $playerA['rank'] ?></span>
                        <img src="<?= htmlspecialchars($imgBase . 'images/' . $iconA) ?>" alt="" class="race-icon">
                        <span class="group-badge">G<?= $groupA ?></span>
                    </div>
                    <?php
                    $recA = $playerRecords[$a] ?? null;
                    if ($recA): ?>
                    <div class="player-record">Season <?= $displaySeason ?>: <span class="record-num"><?= $recA['season']['sets_w'] ?>-<?= $recA['season']['sets_l'] ?></span> sets, <span class="record-num"><?= $recA['season']['maps_w'] ?>-<?= $recA['season']['maps_l'] ?></span> games <br> Alltime: <span class="record-num"><?= $recA['overall']['sets_w'] ?>-<?= $recA['overall']['sets_l'] ?></span> sets, <span class="record-num"><?= $recA['overall']['maps_w'] ?>-<?= $recA['overall']['maps_l'] ?></span> games</div>
                    <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="col-vs">vs</td>
                <td class="player-cell">
                    <div class="player-name"><?= htmlspecialchars($displayB) ?></div>
                    <?php if ($playerB): ?>
                    <div class="player-details">
                        <span class="rank-badge"><?= $playerB['rank'] ?></span>
                        <img src="<?= htmlspecialchars($imgBase . 'images/' . $iconB) ?>" alt="" class="race-icon">
                        <span class="group-badge">G<?= $groupB ?></span>
                    </div>
                    <?php
                    $recB = $playerRecords[$b] ?? null;
                    if ($recB): ?>
                    <div class="player-record">Season <?= $displaySeason ?>: <span class="record-num"><?= $recB['season']['sets_w'] ?>-<?= $recB['season']['sets_l'] ?></span> sets, <span class="record-num"><?= $recB['season']['maps_w'] ?>-<?= $recB['season']['maps_l'] ?></span> games <br> Alltime: <span class="record-num"><?= $recB['overall']['sets_w'] ?>-<?= $recB['overall']['sets_l'] ?></span> sets, <span class="record-num"><?= $recB['overall']['maps_w'] ?>-<?= $recB['overall']['maps_l'] ?></span> games</div>
                    <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; $i++; endforeach; ?>
            <?php while ($i <= 6): ?>
            <tr>
                <td class="col-slot"><?= $i ?></td>
                <td class="player-cell"><div class="player-name">—</div></td>
                <td class="col-vs">vs</td>
                <td class="player-cell"><div class="player-name">—</div></td>
            </tr>
            <?php $i++; endwhile; ?>
        </tbody>
    </table>
    </div>

    <div class="no-print">
        <button type="button" onclick="window.print();">Print / Save as PDF</button>
    </div>
    <script>
    if (window.location.search.indexOf('autoPrint') !== -1) {
        window.onload = function() { window.print(); };
    }
    </script>
</body>
</html>
