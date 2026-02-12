<?php
/**
 * Player Rankings Page
 * Display and manage player power rankings
 */

ob_start();
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/team_logo.php';

$rankingsFile = __DIR__ . '/rankings.json';
$configFile = __DIR__ . '/rankings_config.json';

// Check if user has edit permission
$canEdit = false;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt
            FROM ws_user_roles ur
            JOIN ws_role_permissions rp ON ur.role_id = rp.role_id
            JOIN ws_permissions p ON rp.permission_id = p.permission_id
            WHERE ur.user_id = ? AND p.permission_name = ?
        ");
        $stmt->execute([$_SESSION['user_id'], 'edit player, team, stats']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $canEdit = $result && $result['cnt'] > 0;
    } catch (PDOException $e) {
        // Silent fail
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    if (!$canEdit) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';
    
    if ($action === 'move') {
        $fromIndex = $input['from'] ?? -1;
        $toIndex = $input['to'] ?? -1;
        
        $rankings = json_decode(file_get_contents($rankingsFile), true);
        
        if ($fromIndex >= 0 && $fromIndex < count($rankings) && 
            $toIndex >= 0 && $toIndex < count($rankings)) {
            
            // Remove player from old position
            $player = array_splice($rankings, $fromIndex, 1)[0];
            // When dragging down, indices shift after removal
            $insertIndex = ($fromIndex < $toIndex) ? $toIndex - 1 : $toIndex;
            array_splice($rankings, $insertIndex, 0, [$player]);
            
            // Re-number ranks
            foreach ($rankings as $i => &$p) {
                $p['rank'] = $i + 1;
            }
            
            file_put_contents($rankingsFile, json_encode($rankings, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
            exit;
        }
        
        echo json_encode(['success' => false, 'error' => 'Invalid indices']);
        exit;
    }
    
    if ($action === 'update') {
        $rankings = $input['rankings'] ?? [];
        if (!empty($rankings)) {
            file_put_contents($rankingsFile, json_encode($rankings, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
            exit;
        }
        echo json_encode(['success' => false, 'error' => 'No rankings provided']);
        exit;
    }
    
    if ($action === 'save_code_tiers') {
        $config = [
            'codeS' => ['minRank' => (int)($input['codeS_min'] ?? 1), 'maxRank' => (int)($input['codeS_max'] ?? 24)],
            'codeA' => ['minRank' => (int)($input['codeA_min'] ?? 25), 'maxRank' => (int)($input['codeA_max'] ?? 36)],
            'codeB' => ['minRank' => (int)($input['codeB_min'] ?? 37), 'maxRank' => (int)($input['codeB_max'] ?? 46)]
        ];
        $result = @file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        if ($result === false) {
            echo json_encode(['success' => false, 'error' => 'Could not save config. Check that rankings folder is writable.']);
            exit;
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update_name') {
        $index = isset($input['index']) ? (int) $input['index'] : -1;
        $name = isset($input['name']) ? trim((string) $input['name']) : '';
        $rankings = json_decode(file_get_contents($rankingsFile), true);
        if (!is_array($rankings) || $index < 0 || $index >= count($rankings)) {
            echo json_encode(['success' => false, 'error' => 'Invalid index']);
            exit;
        }
        if ($name === '') {
            echo json_encode(['success' => false, 'error' => 'Name cannot be empty']);
            exit;
        }
        $rankings[$index]['name'] = $name;
        file_put_contents($rankingsFile, json_encode($rankings, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// Load rankings
$rankings = [];
if (file_exists($rankingsFile)) {
    $rankings = json_decode(file_get_contents($rankingsFile), true) ?? [];
}

// Season and all-time W-L (sets) per player for display
$playerRecords = [];
$currentSeason = null;
try {
    $row = $db->query("SELECT MAX(season) as s FROM fsl_matches")->fetch(PDO::FETCH_ASSOC);
    $currentSeason = $row && isset($row['s']) ? (int) $row['s'] : null;
} catch (PDOException $e) {
    // leave $currentSeason null
}
if (!empty($rankings) && $currentSeason !== null) {
    $names = array_unique(array_filter(array_map(function ($p) { return trim((string)($p['name'] ?? '')); }, $rankings)));
    if (!empty($names)) {
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $stmt = $db->prepare("SELECT Player_ID, Real_Name FROM Players WHERE Real_Name IN ($placeholders)");
        $stmt->execute(array_values($names));
        $nameToPid = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $nameToPid[trim($row['Real_Name'])] = (int) $row['Player_ID'];
            $nameToPid[strtolower(trim($row['Real_Name']))] = (int) $row['Player_ID'];
        }
        foreach ($names as $name) {
            $pid = $nameToPid[$name] ?? $nameToPid[strtolower($name)] ?? null;
            if ($pid === null) continue;
            $playerRecords[$name] = [
                'season_sw' => 0, 'season_sl' => 0, 'season_mw' => 0, 'season_ml' => 0,
                'alltime_sw' => 0, 'alltime_sl' => 0, 'alltime_mw' => 0, 'alltime_ml' => 0
            ];
            $stmt = $db->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN winner_player_id = ? THEN 1 ELSE 0 END), 0) as sets_w,
                    COALESCE(SUM(CASE WHEN loser_player_id = ? THEN 1 ELSE 0 END), 0) as sets_l,
                    COALESCE(SUM(CASE WHEN winner_player_id = ? THEN map_win ELSE map_loss END), 0) as maps_w,
                    COALESCE(SUM(CASE WHEN winner_player_id = ? THEN map_loss ELSE map_win END), 0) as maps_l
                FROM fsl_matches WHERE season = ? AND (winner_player_id = ? OR loser_player_id = ?)
            ");
            $stmt->execute([$pid, $pid, $pid, $pid, $currentSeason, $pid, $pid]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $playerRecords[$name]['season_sw'] = (int)($r['sets_w'] ?? 0);
            $playerRecords[$name]['season_sl'] = (int)($r['sets_l'] ?? 0);
            $playerRecords[$name]['season_mw'] = (int)($r['maps_w'] ?? 0);
            $playerRecords[$name]['season_ml'] = (int)($r['maps_l'] ?? 0);
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
            $playerRecords[$name]['alltime_sw'] = (int)($r['sets_w'] ?? 0);
            $playerRecords[$name]['alltime_sl'] = (int)($r['sets_l'] ?? 0);
            $playerRecords[$name]['alltime_mw'] = (int)($r['maps_w'] ?? 0);
            $playerRecords[$name]['alltime_ml'] = (int)($r['maps_l'] ?? 0);
        }
    }
}

// Load code tier config (Code S, A, B rank ranges)
$totalPlayers = count($rankings);
$third = max(1, (int) ceil($totalPlayers / 3));
$codeTiers = [
    'codeS' => ['minRank' => 1, 'maxRank' => $third],
    'codeA' => ['minRank' => $third + 1, 'maxRank' => 2 * $third],
    'codeB' => ['minRank' => 2 * $third + 1, 'maxRank' => $totalPlayers]
];
if (file_exists($configFile)) {
    $loaded = json_decode(file_get_contents($configFile), true);
    if ($loaded) {
        foreach (['codeS', 'codeA', 'codeB'] as $tier) {
            if (isset($loaded[$tier]['minRank'], $loaded[$tier]['maxRank'])) {
                $codeTiers[$tier] = ['minRank' => (int)$loaded[$tier]['minRank'], 'maxRank' => (int)$loaded[$tier]['maxRank']];
            } elseif (isset($loaded[$tier]['minGroup'], $loaded[$tier]['maxGroup'])) {
                $codeTiers[$tier] = ['minRank' => ($loaded[$tier]['minGroup'] - 1) * 4 + 1, 'maxRank' => $loaded[$tier]['maxGroup'] * 4];
            }
        }
    }
}

$raceIcons = [
    'T' => 'terran_icon.png',
    'P' => 'protoss_icon.png',
    'Z' => 'zerg_icon.png',
    'R' => 'random_icon.png'
];

// Team logo per player (by ranking name)
$playerTeamLogo = [];
if (!empty($rankings)) {
    $names = array_unique(array_filter(array_map(function ($p) { return trim((string)($p['name'] ?? '')); }, $rankings)));
    if (!empty($names)) {
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $stmt = $db->prepare("SELECT p.Real_Name, t.Team_Name FROM Players p JOIN Teams t ON p.Team_ID = t.Team_ID WHERE p.Real_Name IN ($placeholders)");
        $stmt->execute(array_values($names));
        $nameToTeam = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $nameToTeam[trim($row['Real_Name'])] = trim($row['Team_Name']);
            $nameToTeam[strtolower(trim($row['Real_Name']))] = trim($row['Team_Name']);
        }
        foreach ($names as $name) {
            $teamName = $nameToTeam[$name] ?? $nameToTeam[strtolower($name)] ?? null;
            if ($teamName) {
                $logo = getTeamLogo($teamName, '256px');
                if ($logo) {
                    $playerTeamLogo[$name] = $logo;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../images/favicon.png" type="image/png">
    <title>Player Rankings - FSL</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&family=Exo+2:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        body {
            background: linear-gradient(135deg, #0a0a0f 0%, #1a1a2e 50%, #0a0a0f 100%);
            min-height: 100vh;
            color: #fff;
        }
        
        .rankings-container {
            width: 100%;
            max-width: 1000px;
            margin-left: auto;
            margin-right: auto;
            padding: 2rem;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .rankings-header {
            width: 100%;
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(108, 92, 231, 0.3);
        }
        
        .rankings-header h1 {
            font-family: 'Rajdhani', sans-serif;
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-transform: uppercase;
            letter-spacing: 3px;
            margin: 0;
        }
        
        .rankings-header p {
            color: #888;
            margin-top: 0.5rem;
        }
        
        .rankings-list {
            width: max-content;
            max-width: 100%;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 10px;
            padding: 1rem;
        }
        .rankings-rows {
            min-width: 0;
            padding-left: var(--rankings-row-pad, 0.25rem);
        }
        
        .player-row {
            display: flex;
            align-items: center;
            padding: 0.5rem 0.75rem;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
            margin-bottom: 0.35rem;
            transition: all 0.2s ease;
            position: relative;
            overflow: visible;
            box-sizing: border-box;
        }
        
        .player-row:hover {
            background: rgba(108, 92, 231, 0.15);
        }
        
        /* Skill tier band - left edge indicator */
        .skill-band {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 10px;
            border-radius: 8px 0 0 8px;
        }
        
        /* Group colors - gradient from bright to dark */
        .skill-band.g1  { background: linear-gradient(180deg, #00d4aa, #00b894); }
        .skill-band.g2  { background: linear-gradient(180deg, #00b894, #00a187); }
        .skill-band.g3  { background: linear-gradient(180deg, #00a187, #008f7a); }
        .skill-band.g4  { background: linear-gradient(180deg, #55a3ff, #4a90e2); }
        .skill-band.g5  { background: linear-gradient(180deg, #4a90e2, #3d7bc7); }
        .skill-band.g6  { background: linear-gradient(180deg, #3d7bc7, #3066ac); }
        .skill-band.g7  { background: linear-gradient(180deg, #9b7fd4, #8b6fc4); }
        .skill-band.g8  { background: linear-gradient(180deg, #8b6fc4, #7b5fb4); }
        .skill-band.g9  { background: linear-gradient(180deg, #7b5fb4, #6b4fa4); }
        .skill-band.g10 { background: linear-gradient(180deg, #6b6b7b, #5b5b6b); }
        .skill-band.g11 { background: linear-gradient(180deg, #5b5b6b, #4b4b5b); }
        .skill-band.g12 { background: linear-gradient(180deg, #4b4b5b, #3b3b4b); }
        
        .player-row.dragging {
            opacity: 0.5;
            background: rgba(108, 92, 231, 0.3);
        }
        
        .player-row.drag-over {
            border-top: 2px solid #6c5ce7;
        }
        
        .rank-badge {
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            border-radius: 50%;
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.75rem;
            font-weight: 700;
            color: #fff;
            margin-right: 0.5rem;
            flex-shrink: 0;
        }
        
        .player-name {
            font-family: 'Exo 2', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            flex: 1;
            min-width: 0;
            max-width: 180px;
        }
        
        .player-name a {
            color: #fff;
            text-decoration: none;
        }
        
        .player-name a:hover {
            color: #a29bfe;
        }
        
        .player-name a.edit-name-trigger {
            cursor: text;
        }
        
        .player-name input.edit-name-input {
            width: 100%;
            max-width: 220px;
            padding: 0.2rem 0.4rem;
            font-family: inherit;
            font-size: 1.2rem;
            font-weight: 600;
            background: rgba(108, 92, 231, 0.2);
            border: 1px solid rgba(108, 92, 231, 0.5);
            border-radius: 4px;
            color: #fff;
        }
        
        .race-icon {
            width: 22px;
            height: 22px;
            margin-right: 0.5rem;
            flex-shrink: 0;
        }

        /* Right side: Group, Team, All (sets + games stacked) */
        .player-row-right {
            display: flex;
            align-items: center;
            flex-shrink: 0;
            gap: 0;
        }
        .group-badge {
            font-size: 0.75rem;
            color: #6c5ce7;
            background: rgba(108, 92, 231, 0.2);
            padding: 0.15rem 0.4rem;
            border-radius: 10px;
            width: 2rem;
            text-align: center;
            flex-shrink: 0;
        }
        .player-row .team-logo-cell {
            width: 28px;
            height: 28px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-left: 0.5rem;
        }
        .player-row .team-logo-cell img {
            width: 24px;
            height: 24px;
            object-fit: contain;
            border-radius: 4px;
            border: 1px solid rgba(108, 92, 231, 0.3);
        }
        /* Season and All-time: each column has label then games, sets */
        .player-records-block {
            display: flex;
            gap: 0.75rem;
            margin-left: 0.5rem;
            font-size: 0.7rem;
            color: #888;
            flex-shrink: 0;
        }
        .player-records-col {
            text-align: left;
            min-width: 3.5rem;
        }
        .player-records-col .record-col-label {
            display: block;
            color: #666;
            font-size: 0.65rem;
            margin-bottom: 0.1rem;
        }
        .player-records-block .record-line {
            display: block;
            white-space: nowrap;
        }
        .player-records-block .record-num {
            color: #b8b8b8;
            font-weight: 600;
        }

        /* Header: inside .rankings-list-content so strip is to its left; padding matches .rankings-rows */
        .rankings-list-header {
            display: flex;
            align-items: center;
            padding: 0.4rem 0.75rem 0.25rem var(--rankings-row-pad, 0.25rem);
            margin-bottom: 0;
            color: #666;
            font-size: 0.7rem;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            box-sizing: border-box;
        }
        .rankings-list-header .drag-handle-header {
            width: 24px;
            margin-right: 0.5rem;
            flex-shrink: 0;
        }
        .rankings-list-header .rank-badge-header {
            width: 26px;
            min-width: 26px;
            margin-right: 0.5rem;
            flex-shrink: 0;
            text-align: center;
            font-size: 0.65rem;
        }
        .rankings-list-header .race-icon-header {
            width: 22px;
            min-width: 22px;
            height: 22px;
            margin-right: 0.5rem;
            flex-shrink: 0;
            text-align: center;
            font-size: 0.65rem;
        }
        .rankings-list-header .player-name-header {
            flex: 1;
            min-width: 0;
            max-width: 180px;
        }
        .rankings-list-header .group-badge-header {
            width: 2rem;
            flex-shrink: 0;
        }
        .rankings-list-header .team-logo-header {
            width: 28px;
            margin-left: 0.5rem;
            flex-shrink: 0;
        }
        .rankings-list-header .player-records-block {
            margin-left: 0.5rem;
            gap: 0.75rem;
        }
        .rankings-list-header .player-records-block .player-records-col {
            min-width: 3.5rem;
        }
        .rankings-list-header .record-sublabel {
            color: #555;
            font-size: 0.6rem;
        }

        @media (max-width: 768px) {
            .rankings-container { padding: 0.75rem; }
            .rankings-list { padding: 0.5rem; }
            .player-row { padding: 0.4rem 0.5rem; margin-bottom: 0.25rem; }
            .rank-badge { width: 22px; height: 22px; font-size: 0.65rem; margin-right: 0.35rem; }
            .race-icon { width: 18px; height: 18px; margin-right: 0.35rem; }
            .player-name { font-size: 0.9rem; }
            .group-badge { width: 1.75rem; font-size: 0.65rem; padding: 0.1rem 0.25rem; }
            .player-row .team-logo-cell { width: 24px; height: 24px; margin-left: 0.35rem; }
            .player-row .team-logo-cell img { width: 20px; height: 20px; }
            .player-records-block { font-size: 0.65rem; margin-left: 0.35rem; gap: 0.5rem; }
            .player-records-col { min-width: 2.75rem; }
            .player-records-col .record-col-label { font-size: 0.6rem; }
            .rankings-list-header { padding: 0.35rem 0.5rem 0.25rem var(--rankings-row-pad, 0.25rem); font-size: 0.65rem; }
            .rankings-list-header .rank-badge-header { width: 22px; margin-right: 0.35rem; }
            .rankings-list-header .race-icon-header { width: 18px; margin-right: 0.35rem; }
            .rankings-list-header .group-badge-header { width: 1.75rem; }
            .rankings-list-header .team-logo-header { width: 24px; margin-left: 0.35rem; }
            .rankings-list-header .player-records-block { margin-left: 0.35rem; gap: 0.5rem; }
            .rankings-list-header .player-records-block .player-records-col { min-width: 2.75rem; }
            .move-buttons { display: none; }
        }

        .move-buttons {
            display: flex;
            gap: 0.25rem;
            flex-shrink: 0;
        }
        
        .move-btn {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(108, 92, 231, 0.2);
            border: 1px solid rgba(108, 92, 231, 0.4);
            border-radius: 5px;
            color: #a29bfe;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .move-btn:hover {
            background: rgba(108, 92, 231, 0.4);
            color: #fff;
        }
        
        .move-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        
        .drag-handle {
            cursor: grab;
            padding: 0 0.5rem;
            color: #555;
            margin-right: 0.5rem;
            width: 24px;
            flex-shrink: 0;
            display: inline-block;
            box-sizing: border-box;
        }
        /* View mode: drag-handle keeps 24px so columns align; hidden via visibility. Edit mode: #rankingsList.edit-mode shows it. */
        .drag-handle.edit-only { visibility: hidden; }
        #rankingsList.edit-mode .drag-handle.edit-only { visibility: visible; }
        
        .drag-handle:active {
            cursor: grabbing;
        }
        
        .edit-mode-toggle {
            width: 100%;
            text-align: center;
            margin-bottom: 1rem;
        }
        
        .edit-btn {
            padding: 0.5rem 1.5rem;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            border: none;
            border-radius: 5px;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .edit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(108, 92, 231, 0.4);
        }
        
        .rankings-with-indicator {
            display: flex;
            gap: 0;
            align-items: stretch;
            min-height: 0;
        }

        .rankings-list-content {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            --rankings-row-pad: 0.25rem;
        }

        .code-tier-strip {
            flex: 0 0 28px;
            width: 28px;
            min-width: 28px;
            align-self: stretch;
            min-height: 100%;
            border-radius: 8px 0 0 8px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .code-zone {
            flex: 1;
            min-height: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        
        .code-zone.code-s { background: linear-gradient(180deg, #ffd700, #ffb347); }
        .code-zone.code-a { background: linear-gradient(180deg, #c0c0c0, #a8a8a8); }
        .code-zone.code-b { background: linear-gradient(180deg, #cd7f32, #a0522d); }
        
        .code-zone-label {
            display: block;
            text-align: center;
            font-size: 1rem;
            font-weight: 800;
            color: rgba(0,0,0,0.85);
            line-height: 1.15;
            letter-spacing: 0.5px;
        }
        
        .code-tier-edit-btn {
            margin-left: 0.5rem;
            padding: 0.25rem 0.5rem;
            font-size: 0.85rem;
            background: rgba(108, 92, 231, 0.2);
            border: 1px solid rgba(108, 92, 231, 0.4);
            border-radius: 4px;
            color: #a29bfe;
            cursor: pointer;
        }
        
        .code-tier-edit-btn:hover { background: rgba(108, 92, 231, 0.4); color: #fff; }
        
        .code-tier-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .code-tier-modal.show { display: flex; }
        
        .code-tier-modal-inner {
            background: #1a1a2e;
            padding: 1.5rem;
            border-radius: 10px;
            border: 1px solid rgba(108, 92, 231, 0.3);
            min-width: 320px;
        }
        
        .code-tier-modal h3 { margin-top: 0; color: #a29bfe; }
        
        .code-tier-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; }
        
        .code-tier-row label { min-width: 70px; color: #ccc; }
        
        .code-tier-row input { width: 50px; padding: 0.35rem; background: #0a0a0f; border: 1px solid #333; color: #fff; border-radius: 4px; }
        
        .code-tier-modal-actions { margin-top: 1.5rem; display: flex; gap: 0.5rem; justify-content: flex-end; }
        
        .player-row[data-code="S"] { border-left: 3px solid #ffd700; }
        .player-row[data-code="A"] { border-left: 3px solid #c0c0c0; }
        .player-row[data-code="B"] { border-left: 3px solid #cd7f32; }
        
        .save-indicator {
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            padding: 0.5rem 1rem;
            background: rgba(0, 184, 148, 0.9);
            border-radius: 5px;
            color: #fff;
            display: none;
        }
        
        .save-indicator.show {
            display: block;
            animation: fadeInOut 2s forwards;
        }
        
        @keyframes fadeInOut {
            0% { opacity: 0; transform: translateY(10px); }
            20% { opacity: 1; transform: translateY(0); }
            80% { opacity: 1; }
            100% { opacity: 0; }
        }
        
        
    </style>
</head>
<body>
    <?php include_once '../includes/nav.php'; ?>
    
    <div class="rankings-container">
        <div class="rankings-header">
            <h1>KJ's Power Rankings</h1>
            <p>Code S · Code A · Code B
                <?php if ($canEdit): ?>
                <button type="button" class="code-tier-edit-btn" onclick="openCodeTierModal()" title="Edit Code tier ranges"><i class="fas fa-cog"></i> Edit ranges</button>
                <?php endif; ?>
            </p>
        </div>
        
        <?php if ($canEdit): ?>
        <div class="edit-mode-toggle">
            <button class="edit-btn" id="editModeBtn" onclick="toggleEditMode()">
                <i class="fas fa-edit"></i> Enable Edit Mode
            </button>
        </div>
        <?php endif; ?>
        
        <?php
        $sRows = $aRows = $bRows = 0;
        foreach ($rankings as $player) {
            $rank = (int) $player['rank'];
            if ($rank >= $codeTiers['codeS']['minRank'] && $rank <= $codeTiers['codeS']['maxRank']) $sRows++;
            elseif ($rank >= $codeTiers['codeA']['minRank'] && $rank <= $codeTiers['codeA']['maxRank']) $aRows++;
            else $bRows++;
        }
        ?>
        <div class="rankings-list">
            <!-- Layout: strip then header+rows so strip is left of both -->
            <div class="rankings-with-indicator">
                <div class="code-tier-strip" title="Code S: #<?= $codeTiers['codeS']['minRank'] ?>–<?= $codeTiers['codeS']['maxRank'] ?> · Code A: #<?= $codeTiers['codeA']['minRank'] ?>–<?= $codeTiers['codeA']['maxRank'] ?> · Code B: #<?= $codeTiers['codeB']['minRank'] ?>–<?= $codeTiers['codeB']['maxRank'] ?>">
                    <div class="code-zone code-s" style="flex: <?= $sRows ?>"><span class="code-zone-label"><?= implode('<br>', str_split('Code')) ?><br><br>S</span></div>
                    <div class="code-zone code-a" style="flex: <?= $aRows ?>"><span class="code-zone-label"><?= implode('<br>', str_split('Code')) ?><br><br>A</span></div>
                    <div class="code-zone code-b" style="flex: <?= $bRows ?>"><span class="code-zone-label"><?= implode('<br>', str_split('Code')) ?><br><br>B</span></div>
                </div>
                <div class="rankings-list-content">
                    <div class="rankings-list-header" data-layout="strip-left-of-header">
                        <?php if ($canEdit): ?><span class="drag-handle-header"></span><?php endif; ?>
                        <span class="rank-badge-header">Rank</span>
                        <span class="race-icon-header">Race</span>
                        <span class="player-name-header">Player</span>
                        <span class="group-badge-header">G</span>
                        <span class="team-logo-header">Team</span>
                        <div class="player-records-block header-records">
                            <div class="player-records-col">
                                <span class="record-col-label">Season:</span>
                                <span class="record-line record-sublabel">games, sets</span>
                            </div>
                            <div class="player-records-col">
                                <span class="record-col-label">All-time:</span>
                                <span class="record-line record-sublabel">games, sets</span>
                            </div>
                        </div>
                    </div>
                    <div class="rankings-rows" id="rankingsList">
            <?php
            foreach ($rankings as $index => $player): 
                $rank = (int) $player['rank'];
                $group = (int) ceil($rank / 4);
                $code = ($rank >= $codeTiers['codeS']['minRank'] && $rank <= $codeTiers['codeS']['maxRank']) ? 'S' : 
                    (($rank >= $codeTiers['codeA']['minRank'] && $rank <= $codeTiers['codeA']['maxRank']) ? 'A' : 'B');
            ?>
                <div class="player-row" data-index="<?= $index ?>" data-code="<?= $code ?>" draggable="false">
                    <div class="skill-band g<?= $group ?>"></div>
                    <?php if ($canEdit): ?>
                    <span class="drag-handle edit-only"><i class="fas fa-grip-vertical"></i></span>
                    <?php endif; ?>
                    <span class="rank-badge"><?= $player['rank'] ?></span>
                    <img src="../images/<?= $raceIcons[$player['race']] ?? 'random_icon.png' ?>" alt="<?= $player['race'] ?>" class="race-icon">
                    <span class="player-name" data-index="<?= $index ?>">
                        <a href="../view_player.php?name=<?= urlencode($player['name']) ?>" class="player-name-link"><?= htmlspecialchars($player['name']) ?></a>
                    </span>
                    <div class="player-row-right">
                        <span class="group-badge">G<?= $group ?></span>
                        <div class="team-logo-cell">
                            <?php
                            $pname = isset($player['name']) ? trim($player['name']) : '';
                            $logo = $pname ? ($playerTeamLogo[$pname] ?? $playerTeamLogo[strtolower($pname)] ?? null) : null;
                            if ($logo):
                            ?><img src="../<?= htmlspecialchars($logo) ?>" alt=""><?php endif; ?>
                        </div>
                        <?php
                        $rec = isset($player['name']) ? ($playerRecords[trim($player['name'])] ?? $playerRecords[strtolower(trim($player['name']))] ?? null) : null;
                        $rec = $rec ?? [
                            'season_sw' => 0, 'season_sl' => 0, 'season_mw' => 0, 'season_ml' => 0,
                            'alltime_sw' => 0, 'alltime_sl' => 0, 'alltime_mw' => 0, 'alltime_ml' => 0
                        ];
                        ?>
                        <div class="player-records-block" title="Season and all-time: games (W-L), sets (W-L)">
                            <div class="player-records-col">
                                <span class="record-col-label">Season:</span>
                                <span class="record-line"><span class="record-num"><?= $rec['season_mw'] ?>-<?= $rec['season_ml'] ?></span></span>
                                <span class="record-line"><span class="record-num"><?= $rec['season_sw'] ?>-<?= $rec['season_sl'] ?></span></span>
                            </div>
                            <div class="player-records-col">
                                <span class="record-col-label">All-time:</span>
                                <span class="record-line"><span class="record-num"><?= $rec['alltime_mw'] ?>-<?= $rec['alltime_ml'] ?></span></span>
                                <span class="record-line"><span class="record-num"><?= $rec['alltime_sw'] ?>-<?= $rec['alltime_sl'] ?></span></span>
                            </div>
                        </div>
                    </div>
                    <?php if ($canEdit): ?>
                    <div class="move-buttons edit-only" style="display: none;">
                        <button class="move-btn move-up" data-index="<?= $index ?>" <?= $index === 0 ? 'disabled' : '' ?>>
                            <i class="fas fa-chevron-up"></i>
                        </button>
                        <button class="move-btn move-down" data-index="<?= $index ?>" <?= $index === count($rankings) - 1 ? 'disabled' : '' ?>>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="code-tier-modal" id="codeTierModal">
        <div class="code-tier-modal-inner">
            <h3>Code Tier Ranges</h3>
            <p class="text-muted" style="font-size: 0.9rem; margin-bottom: 1rem;">Use player rank numbers (1–<?= $totalPlayers ?>). Code S ends at rank 24 = players ranked 1–24.</p>
            <div class="code-tier-row">
                <label>Code S:</label>
                <span>rank</span><input type="number" id="codeS_min" min="1" max="<?= $totalPlayers ?>" value="<?= $codeTiers['codeS']['minRank'] ?>">
                <span>to</span><input type="number" id="codeS_max" min="1" max="<?= $totalPlayers ?>" value="<?= $codeTiers['codeS']['maxRank'] ?>">
            </div>
            <div class="code-tier-row">
                <label>Code A:</label>
                <span>rank</span><input type="number" id="codeA_min" min="1" max="<?= $totalPlayers ?>" value="<?= $codeTiers['codeA']['minRank'] ?>">
                <span>to</span><input type="number" id="codeA_max" min="1" max="<?= $totalPlayers ?>" value="<?= $codeTiers['codeA']['maxRank'] ?>">
            </div>
            <div class="code-tier-row">
                <label>Code B:</label>
                <span>rank</span><input type="number" id="codeB_min" min="1" max="<?= $totalPlayers ?>" value="<?= $codeTiers['codeB']['minRank'] ?>">
                <span>to</span><input type="number" id="codeB_max" min="1" max="<?= $totalPlayers ?>" value="<?= $codeTiers['codeB']['maxRank'] ?>">
            </div>
            <div class="code-tier-modal-actions">
                <button type="button" class="edit-btn" style="background: rgba(255,255,255,0.1);" onclick="closeCodeTierModal()">Cancel</button>
                <button type="button" class="edit-btn" onclick="saveCodeTiers()"><i class="fas fa-save"></i> Save</button>
            </div>
        </div>
    </div>
    
    <div class="save-indicator" id="saveIndicator">
        <i class="fas fa-check"></i> Saved
    </div>
    
    <script>
        let editMode = false;
        const codeTiers = <?= json_encode($codeTiers) ?>;
        
        function getCodeForRank(rank) {
            if (rank >= codeTiers.codeS.minRank && rank <= codeTiers.codeS.maxRank) return 'S';
            if (rank >= codeTiers.codeA.minRank && rank <= codeTiers.codeA.maxRank) return 'A';
            return 'B';
        }
        
        function toggleEditMode() {
            editMode = !editMode;
            const btn = document.getElementById('editModeBtn');
            const listEl = document.getElementById('rankingsList');
            const editElements = document.querySelectorAll('.edit-only');
            const rows = document.querySelectorAll('.player-row');
            
            if (editMode) {
                btn.innerHTML = '<i class="fas fa-eye"></i> View Mode';
                btn.style.background = 'linear-gradient(135deg, #00b894, #55efc4)';
                listEl.classList.add('edit-mode');
                editElements.forEach(el => {
                    if (el.classList.contains('move-buttons')) el.style.display = 'flex';
                    else { el.style.removeProperty('display'); el.style.removeProperty('visibility'); }
                });
                rows.forEach(row => {
                    row.draggable = true;
                    row.addEventListener('dragstart', handleDragStart);
                    row.addEventListener('dragend', handleDragEnd);
                    row.addEventListener('dragover', handleDragOver);
                    row.addEventListener('drop', handleDrop);
                    row.addEventListener('dragleave', handleDragLeave);
                });
                document.getElementById('rankingsList').addEventListener('click', handleMoveButtonClick);
                document.getElementById('rankingsList').addEventListener('click', handleNameClick);
            } else {
                btn.innerHTML = '<i class="fas fa-edit"></i> Enable Edit Mode';
                btn.style.background = 'linear-gradient(135deg, #6c5ce7, #a29bfe)';
                listEl.classList.remove('edit-mode');
                editElements.forEach(el => {
                    if (el.classList.contains('move-buttons')) el.style.display = 'none';
                });
                rows.forEach(row => {
                    row.draggable = false;
                    row.removeEventListener('dragstart', handleDragStart);
                    row.removeEventListener('dragend', handleDragEnd);
                    row.removeEventListener('dragover', handleDragOver);
                    row.removeEventListener('drop', handleDrop);
                    row.removeEventListener('dragleave', handleDragLeave);
                });
                document.getElementById('rankingsList').removeEventListener('click', handleMoveButtonClick);
                document.getElementById('rankingsList').removeEventListener('click', handleNameClick);
            }
        }
        
        function handleNameClick(e) {
            if (!editMode) return;
            const link = e.target.closest('.player-name-link');
            if (!link) return;
            e.preventDefault();
            const row = link.closest('.player-row');
            const nameSpan = row.querySelector('.player-name');
            if (!nameSpan || nameSpan.querySelector('.edit-name-input')) return;
            const index = parseInt(nameSpan.dataset.index, 10);
            const currentName = link.textContent.trim();
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'edit-name-input';
            input.value = currentName;
            input.dataset.index = index;
            nameSpan.innerHTML = '';
            nameSpan.appendChild(input);
            input.focus();
            input.select();
            function save() {
                if (!document.contains(input)) return;
                const newName = input.value.trim();
                if (newName === '') { input.value = currentName; return; }
                if (newName === currentName) {
                    replaceWithLink(nameSpan, index, currentName);
                    return;
                }
                fetch('', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'update_name', index: index, name: newName })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showSaveIndicator();
                        replaceWithLink(nameSpan, index, newName);
                    }
                });
            }
            input.addEventListener('blur', save, { once: true });
            input.addEventListener('keydown', function(ev) {
                if (ev.key === 'Enter') { ev.preventDefault(); input.blur(); }
                if (ev.key === 'Escape') { replaceWithLink(nameSpan, index, currentName); }
            });
        }
        
        function replaceWithLink(nameSpan, index, name) {
            const a = document.createElement('a');
            a.href = '../view_player.php?name=' + encodeURIComponent(name);
            a.className = 'player-name-link';
            a.textContent = name;
            nameSpan.innerHTML = '';
            nameSpan.appendChild(a);
        }
        
        function handleMoveButtonClick(e) {
            const upBtn = e.target.closest('.move-up');
            const downBtn = e.target.closest('.move-down');
            if (upBtn && !upBtn.disabled) movePlayer(parseInt(upBtn.dataset.index), -1);
            if (downBtn && !downBtn.disabled) movePlayer(parseInt(downBtn.dataset.index), 1);
        }
        
        let draggedIndex = null;
        
        function handleDragStart(e) {
            draggedIndex = parseInt(this.dataset.index);
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', draggedIndex);
        }
        
        function handleDragEnd(e) {
            this.classList.remove('dragging');
            document.querySelectorAll('.player-row').forEach(row => {
                row.classList.remove('drag-over');
            });
        }
        
        function handleDragOver(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            this.classList.add('drag-over');
        }
        
        function handleDragLeave(e) {
            this.classList.remove('drag-over');
        }
        
        function handleDrop(e) {
            e.preventDefault();
            const row = e.currentTarget;
            const toIndex = parseInt(row.dataset.index);
            if (draggedIndex !== null && draggedIndex !== toIndex) {
                movePlayerByIndex(draggedIndex, toIndex);
            }
            row.classList.remove('drag-over');
        }
        
        function movePlayer(fromIndex, direction) {
            movePlayerByIndex(fromIndex, fromIndex + direction);
        }
        
        function movePlayerByIndex(fromIndex, toIndex) {
            if (fromIndex === toIndex) return;
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'move',
                    from: fromIndex,
                    to: toIndex
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showSaveIndicator();
                    updateDOMAfterMove(fromIndex, toIndex);
                }
            });
        }
        
        function updateDOMAfterMove(fromIndex, toIndex) {
            const list = document.getElementById('rankingsList');
            const rows = Array.from(list.querySelectorAll('.player-row'));
            const movedRow = rows[fromIndex];
            rows.splice(fromIndex, 1);
            rows.splice(toIndex, 0, movedRow);
            list.innerHTML = '';
            rows.forEach(row => list.appendChild(row));
            rows.forEach((row, i) => {
                row.dataset.index = i;
                const nameSpan = row.querySelector('.player-name');
                if (nameSpan) nameSpan.dataset.index = i;
                const rank = i + 1;
                const group = Math.ceil(rank / 4);
                row.querySelector('.rank-badge').textContent = rank;
                row.querySelector('.group-badge').textContent = 'G' + group;
                const band = row.querySelector('.skill-band');
                band.className = 'skill-band g' + group;
                row.dataset.code = getCodeForRank(rank);
                const moveBtns = row.querySelectorAll('.move-btn');
                if (moveBtns[0]) { moveBtns[0].disabled = (i === 0); moveBtns[0].dataset.index = i; }
                if (moveBtns[1]) { moveBtns[1].disabled = (i === rows.length - 1); moveBtns[1].dataset.index = i; }
            });
        }
        
        function showSaveIndicator() {
            const indicator = document.getElementById('saveIndicator');
            indicator.classList.remove('show');
            void indicator.offsetWidth; // Trigger reflow
            indicator.classList.add('show');
        }
        
        function openCodeTierModal() {
            document.getElementById('codeTierModal').classList.add('show');
        }
        
        function closeCodeTierModal() {
            document.getElementById('codeTierModal').classList.remove('show');
        }
        
        function saveCodeTiers() {
            const data = {
                action: 'save_code_tiers',
                codeS_min: parseInt(document.getElementById('codeS_min').value) || 1,
                codeS_max: parseInt(document.getElementById('codeS_max').value) || 24,
                codeA_min: parseInt(document.getElementById('codeA_min').value) || 25,
                codeA_max: parseInt(document.getElementById('codeA_max').value) || 36,
                codeB_min: parseInt(document.getElementById('codeB_min').value) || 37,
                codeB_max: parseInt(document.getElementById('codeB_max').value) || 46
            };
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    showSaveIndicator();
                    closeCodeTierModal();
                    location.reload();
                }
            });
        }
        
        document.getElementById('codeTierModal').addEventListener('click', function(e) {
            if (e.target === this) closeCodeTierModal();
        });
    </script>
</body>
</html>
