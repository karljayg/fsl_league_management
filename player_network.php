<?php
/**
 * FSL Player Network - Interactive 3D Visualization
 * Shows all players as nodes connected by their match history
 * 
 * Uses file-based caching (15 min TTL) to reduce database load
 */

// Start session
session_start();

// Cache configuration
define('CACHE_FILE', __DIR__ . '/cache/player_network.json');
define('CACHE_TTL', 900); // 15 minutes in seconds

// Check if we have valid cached data
$useCache = false;
$cachedData = null;
$cacheStatus = '';
$cacheTime = '';

if (file_exists(CACHE_FILE)) {
    $cacheTime = date('Y-m-d H:i:s', filemtime(CACHE_FILE));
    $cacheAge = time() - filemtime(CACHE_FILE);
    
    if ($cacheAge < CACHE_TTL) {
        $cachedData = json_decode(file_get_contents(CACHE_FILE), true);
        if ($cachedData && isset($cachedData['players']) && isset($cachedData['matches'])) {
            $useCache = true;
            $cacheStatus = "CACHE_FRESH (age: {$cacheAge}s, created: {$cacheTime})";
        }
    }
}

if ($useCache) {
    // Use cached data
    $players = $cachedData['players'];
    $matches = $cachedData['matches'];
} else {
    // Include database connection and fetch fresh data
    require_once 'includes/db.php';
    require_once 'config.php';

    try {
        // Connect to database
        $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Get all players with their stats (including inactive)
        $playersQuery = "
            SELECT 
                p.Player_ID,
                p.Real_Name,
                p.Team_ID,
                p.Status,
                p.Intro_Url,
                t.Team_Name,
                COALESCE(SUM(fs.MapsW), 0) as total_maps_won,
                COALESCE(SUM(fs.MapsL), 0) as total_maps_lost,
                COALESCE(SUM(fs.SetsW), 0) as total_sets_won,
                COALESCE(SUM(fs.SetsL), 0) as total_sets_lost,
                GROUP_CONCAT(DISTINCT fs.Race) as races,
                GROUP_CONCAT(DISTINCT fs.Division) as divisions
            FROM Players p
            LEFT JOIN Teams t ON p.Team_ID = t.Team_ID
            LEFT JOIN FSL_STATISTICS fs ON p.Player_ID = fs.Player_ID
            GROUP BY p.Player_ID, p.Real_Name, p.Team_ID, p.Status, p.Intro_Url, t.Team_Name
            HAVING (total_maps_won + total_maps_lost) > 0
            ORDER BY (total_maps_won + total_maps_lost) DESC
        ";

        $players = $db->query($playersQuery)->fetchAll(PDO::FETCH_ASSOC);

        // Get match connections (who played against whom and how many times)
        $matchesQuery = "
            SELECT 
                winner_player_id,
                loser_player_id,
                COUNT(*) as match_count,
                SUM(map_win) as total_maps
            FROM fsl_matches
            GROUP BY winner_player_id, loser_player_id
        ";

        $matches = $db->query($matchesQuery)->fetchAll(PDO::FETCH_ASSOC);
        
        // Save to cache
        $cacheData = [
            'players' => $players,
            'matches' => $matches,
            'generated' => date('Y-m-d H:i:s')
        ];
        
        // Ensure cache directory exists
        $cacheDir = dirname(CACHE_FILE);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        file_put_contents(CACHE_FILE, json_encode($cacheData));
        $cacheStatus = "DB_LIVE (cache refreshed: " . date('Y-m-d H:i:s') . ")";
        
    } catch (PDOException $e) {
        // DB failed - try stale cache as fallback
        if (file_exists(CACHE_FILE)) {
            $cachedData = json_decode(file_get_contents(CACHE_FILE), true);
            $players = $cachedData['players'];
            $matches = $cachedData['matches'];
            $cacheStatus = "CACHE_STALE_FALLBACK (DB unreachable, using cache from: {$cacheTime})";
        } else {
            die("Database unavailable and no cache exists: " . $e->getMessage());
        }
    }
}

// Build player lookup and connections
$playerMap = [];
foreach ($players as $player) {
    $playerMap[$player['Player_ID']] = $player;
}

// Build links (connections between players)
$links = [];
$linkCounts = [];

foreach ($matches as $match) {
    $p1 = min($match['winner_player_id'], $match['loser_player_id']);
    $p2 = max($match['winner_player_id'], $match['loser_player_id']);
    
    // Only include if both players are in our active player list
    if (!isset($playerMap[$p1]) || !isset($playerMap[$p2])) continue;
    
    $key = "{$p1}-{$p2}";
    if (!isset($linkCounts[$key])) {
        $linkCounts[$key] = [
            'source' => $p1,
            'target' => $p2,
            'value' => 0,
            'maps' => 0
        ];
    }
    $linkCounts[$key]['value'] += $match['match_count'];
    $linkCounts[$key]['maps'] += $match['total_maps'];
}

$links = array_values($linkCounts);

// Team colors
$teamColors = [
    1 => '#e74c3c', // PulledTheBoys - Red
    2 => '#3498db', // Angry Space Hares - Blue
    3 => '#2ecc71', // Infinite Cyclists - Green
    4 => '#9b59b6', // Rages Raiders - Purple
    5 => '#f39c12', // Cheesy Nachos - Orange/Yellow
];

// Gray shades for inactive players
$inactiveColors = [
    1 => '#7d4f4f', // PulledTheBoys - Muted Red
    2 => '#4a6a7d', // Angry Space Hares - Muted Blue
    3 => '#4a7d5a', // Infinite Cyclists - Muted Green
    4 => '#6a5a7d', // Rages Raiders - Muted Purple
    5 => '#7d6a4a', // Cheesy Nachos - Muted Orange
];

// Calculate summary stats
$totalPlayers = count($players);
$totalMatches = array_sum(array_column($matches, 'match_count'));
$totalMaps = 0;
$totalWins = 0;
foreach ($players as $p) {
    $totalMaps += $p['total_maps_won'] + $p['total_maps_lost'];
    $totalWins += $p['total_maps_won'];
}

// Get team counts
$teamCounts = [];
foreach ($players as $p) {
    $teamName = $p['Team_Name'] ?? 'Free Agent';
    $teamCounts[$teamName] = ($teamCounts[$teamName] ?? 0) + 1;
}

// Prepare nodes data for JavaScript
$nodes = [];
$activeCount = 0;
$inactiveCount = 0;

foreach ($players as $player) {
    $totalMapsPlayer = $player['total_maps_won'] + $player['total_maps_lost'];
    $winRate = $totalMapsPlayer > 0 ? round(($player['total_maps_won'] / $totalMapsPlayer) * 100, 1) : 0;
    
    $isActive = ($player['Status'] === 'active');
    if ($isActive) {
        $activeCount++;
        $color = $teamColors[$player['Team_ID']] ?? '#95a5a6';
    } else {
        $inactiveCount++;
        // Use muted/gray colors for inactive players
        $color = $inactiveColors[$player['Team_ID']] ?? '#555555';
    }
    
    // Check if thumbnail exists (does NOT generate - run generate_thumbnails.php for that)
    // Thumbnails are named by sanitized player name (spaces -> underscores, special chars removed)
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', str_replace(' ', '_', $player['Real_Name']));
    $thumbnailPathPng = 'images/player_thumbnails/' . $safeName . '.png';
    $thumbnailPathJpg = 'images/player_thumbnails/' . $safeName . '.jpg';
    if (file_exists(__DIR__ . '/' . $thumbnailPathPng)) {
        $thumbnail = $thumbnailPathPng;
    } elseif (file_exists(__DIR__ . '/' . $thumbnailPathJpg)) {
        $thumbnail = $thumbnailPathJpg;
    } else {
        $thumbnail = null;
    }
    
    $nodes[] = [
        'id' => $player['Player_ID'],
        'name' => $player['Real_Name'],
        'team' => $player['Team_Name'] ?? 'Free Agent',
        'teamId' => $player['Team_ID'],
        'status' => $player['Status'],
        'isActive' => $isActive,
        'races' => $player['races'] ?? 'Unknown',
        'divisions' => $player['divisions'] ?? 'N/A',
        'mapsWon' => (int)$player['total_maps_won'],
        'mapsLost' => (int)$player['total_maps_lost'],
        'setsWon' => (int)$player['total_sets_won'],
        'setsLost' => (int)$player['total_sets_lost'],
        'winRate' => $winRate,
        'totalMaps' => $totalMapsPlayer,
        'color' => $color,
        'thumbnail' => $thumbnail
    ];
}

// Include header
include 'includes/header.php';
?>
<!-- Data: <?= $cacheStatus ?> -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FSL Player Network - Interactive 3D Visualization</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #0f0c29;
            color: #e0e0e0;
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        /* Main viewing area - full viewport, allows overflow for immersive feel */
        .main-view {
            width: 100%;
            height: calc(100vh - 80px);
            position: relative;
            background: linear-gradient(135deg, #0f0c29, #1a1a2e);
            overflow: visible; /* Allow nodes to extend beyond edges */
        }
        
        /* Compact header overlay */
        .header-overlay {
            position: absolute;
            top: 10px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 200;
            text-align: center;
            pointer-events: none;
        }
        
        .header-overlay h1 {
            color: #00d4ff;
            font-size: 1.4em;
            text-shadow: 0 0 20px rgba(0, 212, 255, 0.8), 0 2px 10px rgba(0,0,0,0.8);
            margin: 0;
            white-space: nowrap;
        }
        
        /* Controls panel - top left overlay, flush to edge */
        .controls-panel {
            position: fixed;
            top: 80px; /* Below header */
            left: 0;
            z-index: 200;
            background: rgba(0, 0, 0, 0.85);
            border-radius: 0 10px 10px 0;
            border: 1px solid rgba(0, 212, 255, 0.4);
            border-left: none;
            padding: 10px 12px 10px 8px;
            backdrop-filter: blur(5px);
        }
        
        .controls-row {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }
        
        .controls-row:last-child {
            margin-bottom: 0;
        }
        
        .control-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .control-item label {
            color: #00d4ff;
            font-size: 0.75em;
            font-weight: 600;
            min-width: 45px;
        }
        
        .control-item select {
            padding: 4px 8px;
            border: 1px solid rgba(0, 212, 255, 0.5);
            border-radius: 4px;
            background: rgba(0, 0, 0, 0.6);
            color: #e0e0e0;
            cursor: pointer;
            font-size: 0.8em;
            max-width: 120px;
        }
        
        .control-btn {
            padding: 4px 10px;
            border: 1px solid #00d4ff;
            border-radius: 4px;
            background: rgba(0, 212, 255, 0.15);
            color: #00d4ff;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.75em;
            font-weight: 600;
        }
        
        .control-btn:hover {
            background: rgba(0, 212, 255, 0.3);
        }
        
        .search-row {
            margin-top: 4px;
            padding-top: 8px;
            border-top: 1px solid rgba(0, 212, 255, 0.3);
        }
        
        .search-item {
            flex: 1;
        }
        
        .search-item input {
            padding: 5px 8px;
            border: 1px solid rgba(0, 212, 255, 0.5);
            border-radius: 4px;
            background: rgba(0, 0, 0, 0.6);
            color: #e0e0e0;
            font-size: 0.8em;
            width: 120px;
        }
        
        .search-item input:focus {
            outline: none;
            border-color: #00d4ff;
            box-shadow: 0 0 5px rgba(0, 212, 255, 0.5);
        }
        
        .search-item input::placeholder {
            color: #888;
        }
        
        /* Stats bar - bottom overlay */
        .stats-bar {
            position: absolute;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 200;
            display: flex;
            gap: 15px;
            background: rgba(0, 0, 0, 0.8);
            padding: 8px 20px;
            border-radius: 20px;
            border: 1px solid rgba(0, 212, 255, 0.3);
            backdrop-filter: blur(5px);
        }
        
        .stat-item {
            text-align: center;
            position: relative;
            cursor: help;
            padding: 4px 8px;
            border-radius: 6px;
            transition: background 0.2s ease;
        }
        
        .stat-item:hover {
            background: rgba(0, 212, 255, 0.15);
        }
        
        .stat-item .value {
            color: #00d4ff;
            font-size: 1.1em;
            font-weight: 700;
        }
        
        .stat-item .label {
            color: #888;
            font-size: 0.65em;
            text-transform: uppercase;
        }
        
        /* Tooltip for stat items */
        .stat-item::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.95);
            color: #e0e0e0;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.75em;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
            pointer-events: none;
            border: 1px solid rgba(0, 212, 255, 0.4);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5);
            z-index: 300;
            margin-bottom: 8px;
        }
        
        .stat-item:hover::after {
            opacity: 1;
            visibility: visible;
        }
        
        /* Graph fills the view */
        .graph-container {
            width: 100vw;
            height: 100vh;
            background: transparent;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
        
        #3d-graph {
            width: 100%;
            height: 100%;
            display: block;
        }
        
        #3d-graph canvas {
            display: block !important;
            width: 100% !important;
            height: 100% !important;
        }
        
        /* Legend - top right, compact */
        .legend {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0, 0, 0, 0.85);
            padding: 10px;
            border-radius: 8px;
            border: 1px solid rgba(0, 212, 255, 0.3);
            z-index: 200;
            font-size: 0.7em;
            backdrop-filter: blur(5px);
        }
        
        .legend h3 {
            color: #00d4ff;
            margin-bottom: 6px;
            font-size: 0.85em;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 3px;
        }
        
        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        /* Instructions - bottom left, compact */
        .instructions {
            position: absolute;
            bottom: 60px;
            left: 10px;
            background: rgba(0, 0, 0, 0.85);
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid rgba(0, 212, 255, 0.3);
            z-index: 200;
            font-size: 0.7em;
            backdrop-filter: blur(5px);
        }
        
        .instructions h4 {
            color: #00d4ff;
            margin-bottom: 5px;
            font-size: 0.9em;
        }
        
        .instructions p {
            margin-bottom: 2px;
            color: #b0b0b0;
        }
        
        /* Guide panel - collapsible, positioned smartly */
        .guide-panel {
            position: absolute;
            top: 10px;
            right: 180px;
            background: rgba(0, 0, 0, 0.9);
            padding: 0;
            border-radius: 8px;
            border: 1px solid rgba(0, 212, 255, 0.3);
            z-index: 200;
            font-size: 0.75em;
            max-width: 280px;
            max-height: 70vh;
            overflow: hidden;
            backdrop-filter: blur(5px);
        }
        
        .guide-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            cursor: pointer;
            background: rgba(0, 212, 255, 0.1);
            border-bottom: 1px solid rgba(0, 212, 255, 0.2);
        }
        
        .guide-header:hover {
            background: rgba(0, 212, 255, 0.15);
        }
        
        .guide-header h4 {
            color: #00d4ff;
            margin: 0;
            font-size: 0.9em;
        }
        
        .guide-toggle {
            color: #00d4ff;
            font-size: 0.8em;
            transition: transform 0.3s ease;
        }
        
        .guide-toggle.collapsed {
            transform: rotate(-90deg);
        }
        
        .guide-content {
            padding: 10px 12px;
            overflow-y: auto;
            max-height: 60vh;
            transition: max-height 0.3s ease, padding 0.3s ease;
        }
        
        .guide-content.collapsed {
            max-height: 0;
            padding: 0 12px;
            overflow: hidden;
        }
        
        .guide-section {
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .guide-section:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        
        .guide-section h5 {
            color: #ff6f61;
            margin-bottom: 4px;
            font-size: 0.9em;
        }
        
        .guide-section p {
            color: #b0b0b0;
            margin-bottom: 4px;
            line-height: 1.3;
        }
        
        .guide-section ul {
            margin: 0;
            padding-left: 15px;
            color: #999;
        }
        
        .guide-section ul li {
            margin-bottom: 2px;
            line-height: 1.3;
        }
        
        .tooltip-card {
            background: rgba(15, 12, 41, 0.95) !important;
            border: 2px solid #00d4ff !important;
            border-radius: 10px !important;
            padding: 15px !important;
            color: #e0e0e0 !important;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
            box-shadow: 0 5px 30px rgba(0, 212, 255, 0.3) !important;
            max-width: 280px;
        }
        
        .tooltip-card h3 {
            color: #00d4ff;
            margin-bottom: 10px;
            font-size: 1.2em;
        }
        
        .tooltip-card .team-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .tooltip-card .stats-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 0.9em;
        }
        
        .tooltip-card .stats-row .label {
            color: #b0b0b0;
        }
        
        .tooltip-card .stats-row .value {
            font-weight: 600;
        }
        
        .tooltip-card .click-hint {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(0, 212, 255, 0.3);
            font-size: 0.8em;
            color: #00d4ff;
            text-align: center;
        }
        
        @media (max-width: 900px) {
            .main-view {
                height: calc(100vh - 70px);
            }
            
            .legend, .guide-panel {
                display: none;
            }
            
            .controls-panel {
                padding: 6px;
                font-size: 0.85em;
            }
            
            .control-item select {
                max-width: 90px;
            }
            
            .stats-bar {
                flex-wrap: wrap;
                gap: 10px;
                padding: 6px 12px;
            }
            
            .stat-item .value {
                font-size: 0.95em;
            }
            
            .stat-item .label {
                font-size: 0.55em;
            }
            
            .instructions {
                display: none;
            }
        }
        
        @media (max-width: 600px) {
            .main-view {
                height: calc(100vh - 60px);
            }
            
            .header-overlay h1 {
                font-size: 1em;
            }
            
            .controls-panel {
                left: 5px;
                top: 40px;
            }
            
            .controls-row {
                flex-direction: column;
                gap: 5px;
            }
            
            .stats-bar {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Controls panel - fixed position outside main-view for proper left:0 -->
    <div class="controls-panel">
        <div class="controls-row">
            <div class="control-item">
                <label>Color:</label>
                <select id="colorMode">
                    <option value="team">Team</option>
                    <option value="race">Race</option>
                    <option value="division">Division</option>
                </select>
            </div>
            <div class="control-item">
                <label>Size:</label>
                <select id="sizeMode">
                    <option value="winrate" selected>Win Rate</option>
                    <option value="matches">Matches</option>
                    <option value="equal">Equal</option>
                </select>
            </div>
        </div>
        <div class="controls-row">
            <div class="control-item">
                <label>Team:</label>
                <select id="teamFilter">
                    <option value="all">All</option>
                    <?php foreach ($teamCounts as $team => $count): ?>
                    <option value="<?= htmlspecialchars($team) ?>"><?= htmlspecialchars($team) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="control-item">
                <label>Status:</label>
                <select id="statusFilter">
                    <option value="all">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>
        <div class="controls-row">
            <button class="control-btn" id="resetBtn">🔄 Reset</button>
            <button class="control-btn" id="rotateBtn">⏸️ Pause</button>
        </div>
        <div class="controls-row search-row">
            <div class="control-item search-item">
                <label>🔍 Find:</label>
                <input type="text" id="playerSearch" placeholder="Player name..." list="playerList" autocomplete="off">
                <datalist id="playerList">
                    <?php foreach ($nodes as $node): ?>
                    <option value="<?= htmlspecialchars($node['name']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
        </div>
    </div>

    <!-- Main fullscreen viewing area -->
    <div class="main-view">
        <div class="header-overlay">
            <h1>🌐 FSL Player Network</h1>
        </div>
        
        <!-- Graph container - fullscreen -->
        <div class="graph-container">
            <div id="3d-graph"></div>
            
            <div class="legend">
                <h3>Teams (Active)</h3>
                <?php 
                $teamNames = [1 => 'PulledTheBoys', 2 => 'Angry Space Hares', 3 => 'Infinite Cyclists', 4 => 'Rages Raiders', 5 => 'Cheesy Nachos'];
                foreach ($teamColors as $id => $color): 
                ?>
                <div class="legend-item">
                    <div class="legend-color" style="background: <?= $color ?>"></div>
                    <span><?= $teamNames[$id] ?? 'Unknown' ?></span>
                </div>
                <?php endforeach; ?>
                <div class="legend-item">
                    <div class="legend-color" style="background: #95a5a6"></div>
                    <span>Free Agent</span>
                </div>
                <h3 style="margin-top: 12px;">Inactive</h3>
                <div class="legend-item">
                    <div class="legend-color" style="background: #555555; opacity: 0.7;"></div>
                    <span style="color: #888;">Muted colors</span>
                </div>
            </div>
            
            <div class="instructions">
                <h4>🎮 Controls</h4>
                <p>🖱️ <strong>Drag</strong> - Rotate view</p>
                <p>🔍 <strong>Scroll</strong> - Zoom in/out</p>
                <p>👆 <strong>Click node</strong> - View player</p>
                <p>📌 <strong>Hover</strong> - See stats</p>
                <p>✋ <strong>Drag node</strong> - Move player</p>
            </div>
            
            <div class="guide-panel">
                <div class="guide-header" onclick="toggleGuide()">
                    <h4>📖 Guide</h4>
                    <span class="guide-toggle" id="guideToggle">▼</span>
                </div>
                <div class="guide-content" id="guideContent">
                    <div class="guide-section">
                        <h5>🔵 Nodes (Spheres)</h5>
                        <p>Each sphere represents a <strong>player</strong> in the FSL league.</p>
                        <ul>
                            <li><strong>Color</strong> - Team affiliation (or race/division/winrate based on filter)</li>
                            <li><strong>Size</strong> - Number of matches played (larger = more active)</li>
                            <li><strong>Opacity</strong> - Active players are brighter; inactive are muted gray</li>
                        </ul>
                    </div>
                    <div class="guide-section">
                        <h5>🔗 Links (Lines)</h5>
                        <p>Lines connect players who have <strong>faced each other</strong> in matches.</p>
                        <ul>
                            <li><strong>Thickness</strong> - Number of times they've played (thicker = more games)</li>
                            <li><strong>Color</strong> - Cyan glow indicating competitive history</li>
                        </ul>
                    </div>
                    <div class="guide-section">
                        <h5>✨ Animated Particles</h5>
                        <p>Flowing particles appear on links between <strong>frequent rivals</strong> (5+ matches).</p>
                        <ul>
                            <li>Particles travel along the connection line</li>
                            <li>Indicates strong competitive relationship</li>
                        </ul>
                    </div>
                    <div class="guide-section">
                        <h5>🎨 Color Modes</h5>
                        <ul>
                            <li><strong>Team</strong> - Red, Blue, Green, Purple, Orange by team</li>
                            <li><strong>Race</strong> - Blue (Terran), Purple (Zerg), Gold (Protoss), Teal (Random)</li>
                            <li><strong>Win Rate</strong> - Green (60%+), Yellow (50%+), Orange (40%+), Red (&lt;40%)</li>
                            <li><strong>Division</strong> - Gold (S), Silver (A), Bronze (B), Red (S+)</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Stats bar - bottom center overlay with hover tooltips -->
        <div class="stats-bar">
            <div class="stat-item" data-tooltip="Total players who have played in FSL matches">
                <div class="value"><?= $totalPlayers ?></div>
                <div class="label">Players</div>
            </div>
            <div class="stat-item" data-tooltip="Currently active players: <?= $activeCount ?> active, <?= $inactiveCount ?> inactive">
                <div class="value"><?= $activeCount ?></div>
                <div class="label">Active</div>
            </div>
            <div class="stat-item" data-tooltip="Total matches played across all seasons">
                <div class="value"><?= number_format($totalMatches) ?></div>
                <div class="label">Matches</div>
            </div>
            <div class="stat-item" data-tooltip="Total individual maps played: <?= number_format($totalMaps) ?>">
                <div class="value"><?= number_format($totalMaps) ?></div>
                <div class="label">Maps</div>
            </div>
            <div class="stat-item" data-tooltip="Unique player matchups (who has played whom)">
                <div class="value"><?= count($links) ?></div>
                <div class="label">Rivalries</div>
            </div>
            <div class="stat-item" data-tooltip="<?php foreach($teamCounts as $t => $c) echo htmlspecialchars($t) . ': ' . $c . ' | '; ?>">
                <div class="value"><?= count($teamCounts) ?></div>
                <div class="label">Teams</div>
            </div>
        </div>
    </div>

    <!-- Three.js and 3D Force Graph (standalone version uses external THREE) -->
    <script src="https://unpkg.com/three@0.128.0/build/three.min.js"></script>
    <script>
        // Suppress Three.js duplicate warning since 3d-force-graph bundles its own
        const originalWarn = console.warn;
        console.warn = function(...args) {
            if (args[0] && args[0].includes && args[0].includes('Multiple instances of Three.js')) return;
            originalWarn.apply(console, args);
        };
    </script>
    <script src="https://unpkg.com/3d-force-graph@1.70.0/dist/3d-force-graph.min.js"></script>
    
    <script>
        // Data from PHP
        const nodesData = <?= json_encode($nodes) ?>;
        const linksData = <?= json_encode($links) ?>;
        
        // Team colors
        const teamColors = {
            1: '#e74c3c',
            2: '#3498db', 
            3: '#2ecc71',
            4: '#9b59b6',
            5: '#f39c12',
            null: '#95a5a6'
        };
        
        // Division colors
        const divisionColors = {
            'S': '#ffd700',
            'A': '#c0c0c0',
            'B': '#cd7f32',
            'S+': '#ff6b6b'
        };
        
        // Race colors (StarCraft II) - distinct and easy to see
        // Database stores as single letters: T, Z, P, R
        const raceColors = {
            'T': '#22cc22',        // Terran - Green
            'Z': '#ff4444',        // Zerg - Red
            'P': '#4488ff',        // Protoss - Blue
            'R': '#ffffff',        // Random - White
            'Terran': '#22cc22',
            'Zerg': '#ff4444',
            'Protoss': '#4488ff',
            'Random': '#ffffff'
        };
        
        function getRaceColor(races) {
            if (!races) return '#95a5a6';
            const race = races.split(',')[0].trim();
            return raceColors[race] || '#95a5a6';
        }
        
        // Win rate color scale
        function getWinRateColor(rate) {
            if (rate >= 60) return '#2ecc71';
            if (rate >= 50) return '#f39c12';
            if (rate >= 40) return '#e67e22';
            return '#e74c3c';
        }
        
        // Auto-rotation state
        let isRotating = true;
        let angle = 0;
        let Graph = null;
        
        // Current display modes
        let currentColorMode = 'team';
        let currentSizeMode = 'winrate';
        
        // Texture cache for thumbnails
        const textureCache = {};
        const textureLoader = new THREE.TextureLoader();
        
        // Function to get or load texture
        function getTexture(url) {
            if (!url) return null;
            if (textureCache[url]) return textureCache[url];
            textureCache[url] = textureLoader.load(url);
            return textureCache[url];
        }
        
        // Get node color based on current mode
        function getNodeColor(node) {
            if (currentColorMode === 'team') return node.color;
            if (currentColorMode === 'race') return getRaceColor(node.races);
            if (currentColorMode === 'division') {
                const div = node.divisions ? node.divisions.split(',')[0] : '';
                return divisionColors[div] || '#95a5a6';
            }
            return node.color;
        }
        
        // Get node size based on current mode
        function getNodeSize(node) {
            let baseSize;
            if (currentSizeMode === 'matches') baseSize = Math.max(8, Math.sqrt(node.totalMaps) * 2.5);
            else if (currentSizeMode === 'winrate') baseSize = Math.max(8, node.winRate / 4);
            else baseSize = 10;
            return node.isActive ? baseSize : baseSize * 0.7;
        }
        
        // Initialize graph
        const graphContainer = document.getElementById('3d-graph');
        
        // Use full window dimensions for immersive overflow on all sides
        Graph = ForceGraph3D()
            (graphContainer)
            .width(window.innerWidth)
            .height(window.innerHeight)
            .graphData({nodes: nodesData, links: linksData})
            .nodeId('id')
            .nodeLabel(node => {
                const statusBadge = node.isActive 
                    ? '<span style="color: #2ecc71; font-size: 0.8em;">● Active</span>'
                    : '<span style="color: #7f8c8d; font-size: 0.8em;">○ Inactive</span>';
                return `
                    <div class="tooltip-card">
                        <h3>${node.name} ${statusBadge}</h3>
                        <div class="team-badge" style="background: ${node.color}">${node.team}</div>
                        <div class="stats-row">
                            <span class="label">Race:</span>
                            <span class="value">${node.races}</span>
                        </div>
                        <div class="stats-row">
                            <span class="label">Division:</span>
                            <span class="value">Code ${node.divisions}</span>
                        </div>
                        <div class="stats-row">
                            <span class="label">Maps:</span>
                            <span class="value">${node.mapsWon}W - ${node.mapsLost}L</span>
                        </div>
                        <div class="stats-row">
                            <span class="label">Sets:</span>
                            <span class="value">${node.setsWon}W - ${node.setsLost}L</span>
                        </div>
                        <div class="stats-row">
                            <span class="label">Win Rate:</span>
                            <span class="value" style="color: ${getWinRateColor(node.winRate)}">${node.winRate}%</span>
                        </div>
                        <div class="click-hint">🖱️ Click to view full profile</div>
                    </div>
                `;
            })
            .nodeThreeObject(node => {
                const size = getNodeSize(node);
                const color = getNodeColor(node);
                
                // If player has thumbnail, use sprites only (all face camera, no blocking)
                if (node.thumbnail) {
                    const texture = getTexture(node.thumbnail);
                    if (texture) {
                        const group = new THREE.Group();
                        
                        // Create a colored circle sprite as background
                        // Use a canvas to draw a filled circle
                        const bgCanvas = document.createElement('canvas');
                        bgCanvas.width = 64;
                        bgCanvas.height = 64;
                        const ctx = bgCanvas.getContext('2d');
                        ctx.beginPath();
                        ctx.arc(32, 32, 30, 0, Math.PI * 2);
                        ctx.fillStyle = color;
                        ctx.fill();
                        // Add ring border
                        ctx.lineWidth = 4;
                        ctx.strokeStyle = color;
                        ctx.stroke();
                        
                        const bgTexture = new THREE.CanvasTexture(bgCanvas);
                        const bgMat = new THREE.SpriteMaterial({
                            map: bgTexture,
                            transparent: true,
                            opacity: node.isActive ? 0.9 : 0.4
                        });
                        const bgSprite = new THREE.Sprite(bgMat);
                        bgSprite.scale.set(size * 1.1, size * 1.1, 1);
                        bgSprite.renderOrder = 0;
                        group.add(bgSprite);
                        
                        // Add the thumbnail sprite on top
                        const spriteMat = new THREE.SpriteMaterial({
                            map: texture,
                            transparent: true,
                            opacity: node.isActive ? 1.0 : 0.5
                        });
                        const sprite = new THREE.Sprite(spriteMat);
                        sprite.scale.set(size, size, 1);
                        sprite.renderOrder = 1;
                        group.add(sprite);
                        
                        return group;
                    }
                }
                
                // Fallback: colored sphere for players without thumbnails
                const geometry = new THREE.SphereGeometry(size / 2, 16, 16);
                const material = new THREE.MeshLambertMaterial({
                    color: color,
                    transparent: true,
                    opacity: node.isActive ? 0.9 : 0.5
                });
                return new THREE.Mesh(geometry, material);
            })
            .nodeThreeObjectExtend(false)
            .backgroundColor('#0f0c29')
            .linkSource('source')
            .linkTarget('target')
            .linkWidth(link => Math.sqrt(link.value) * 0.5)
            .linkColor(() => 'rgba(0, 212, 255, 0.4)')
            .linkOpacity(0.8)
            .linkDirectionalParticles(link => link.value > 5 ? 2 : 0)
            .linkDirectionalParticleSpeed(0.005)
            .linkDirectionalParticleWidth(2)
            .linkDirectionalParticleColor(() => '#00d4ff')
            .onNodeClick(node => {
                window.open(`view_player.php?name=${encodeURIComponent(node.name)}`, '_blank');
            })
            .onNodeDragEnd(node => {
                node.fx = node.x;
                node.fy = node.y;
                node.fz = node.z;
            })
            .enableNodeDrag(true)
            .enableNavigationControls(true)
            .showNavInfo(false);
        
        // Debug: Log data counts
        console.log('Nodes:', nodesData.length, 'Links:', linksData.length);
        
        // Set initial camera position after a brief delay
        setTimeout(() => {
            Graph.cameraPosition({ x: 0, y: 0, z: 400 }, { x: 0, y: 0, z: 0 }, 1000);
        }, 500);
        
        // Auto-rotation animation (preserves current zoom distance)
        function animate() {
            if (isRotating && Graph) {
                angle += 0.0005; // Slow rotation
                // Get current camera position to preserve zoom level
                const currentPos = Graph.cameraPosition();
                const currentDistance = Math.sqrt(currentPos.x * currentPos.x + currentPos.z * currentPos.z);
                const distance = currentDistance || 400;
                Graph.cameraPosition({
                    x: distance * Math.sin(angle),
                    y: currentPos.y, // preserve Y
                    z: distance * Math.cos(angle)
                });
            }
            requestAnimationFrame(animate);
        }
        animate();
        
        // Stop rotation on scroll (zoom)
        graphContainer.addEventListener('wheel', () => {
            isRotating = false;
            document.getElementById('rotateBtn').textContent = '▶️ Rotate';
        });
        
        // Button event listeners
        document.getElementById('resetBtn').addEventListener('click', function() {
            if (Graph) {
                Graph.cameraPosition({ x: 0, y: 0, z: 400 }, { x: 0, y: 0, z: 0 }, 1000);
                angle = 0;
            }
        });
        
        document.getElementById('rotateBtn').addEventListener('click', function() {
            isRotating = !isRotating;
            this.textContent = isRotating ? '⏸️ Pause' : '▶️ Rotate';
        });
        
        // Color mode switching - refresh nodes with new colors
        document.getElementById('colorMode').addEventListener('change', function() {
            if (!Graph) return;
            currentColorMode = this.value;
            // Force rebuild all node objects by refreshing graph data
            const data = Graph.graphData();
            Graph.graphData({nodes: [], links: []});
            setTimeout(() => Graph.graphData(data), 10);
        });
        
        // Size mode switching - refresh nodes with new sizes
        document.getElementById('sizeMode').addEventListener('change', function() {
            if (!Graph) return;
            currentSizeMode = this.value;
            // Force rebuild all node objects by refreshing graph data
            const data = Graph.graphData();
            Graph.graphData({nodes: [], links: []});
            setTimeout(() => Graph.graphData(data), 10);
        });
        
        // Combined filter function
        function applyFilters() {
            if (!Graph) return;
            
            const team = document.getElementById('teamFilter').value;
            const status = document.getElementById('statusFilter').value;
            
            let filteredNodes = nodesData;
            
            // Apply team filter
            if (team !== 'all') {
                filteredNodes = filteredNodes.filter(n => n.team === team);
            }
            
            // Apply status filter
            if (status === 'active') {
                filteredNodes = filteredNodes.filter(n => n.isActive);
            } else if (status === 'inactive') {
                filteredNodes = filteredNodes.filter(n => !n.isActive);
            }
            
            // Filter links to only include visible nodes
            const nodeIds = new Set(filteredNodes.map(n => n.id));
            const filteredLinks = linksData.filter(l => {
                const sourceId = typeof l.source === 'object' ? l.source.id : l.source;
                const targetId = typeof l.target === 'object' ? l.target.id : l.target;
                return nodeIds.has(sourceId) && nodeIds.has(targetId);
            });
            
            Graph.graphData({nodes: filteredNodes, links: filteredLinks});
        }
        
        // Team filter
        document.getElementById('teamFilter').addEventListener('change', applyFilters);
        
        // Status filter
        document.getElementById('statusFilter').addEventListener('change', applyFilters);
        
        // Stop rotation on user interaction
        graphContainer.addEventListener('mousedown', () => {
            isRotating = false;
            document.getElementById('rotateBtn').textContent = '▶️ Rotate';
        });
        
        // Player search functionality
        const playerSearchInput = document.getElementById('playerSearch');
        
        playerSearchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            if (searchTerm.length < 2) return;
            
            // Find matching node
            const matchedNode = nodesData.find(n => 
                n.name.toLowerCase() === searchTerm || 
                n.name.toLowerCase().startsWith(searchTerm)
            );
            
            if (matchedNode && Graph) {
                focusOnNode(matchedNode);
            }
        });
        
        playerSearchInput.addEventListener('change', function() {
            const searchTerm = this.value.toLowerCase().trim();
            
            // Find exact match
            const matchedNode = nodesData.find(n => 
                n.name.toLowerCase() === searchTerm
            );
            
            if (matchedNode && Graph) {
                focusOnNode(matchedNode);
                this.blur(); // Remove focus from input
            }
        });
        
        // Track the currently highlighted node for cleanup
        let highlightedNode = null;
        
        // Focus camera on a specific node and pull it out from cluster
        function focusOnNode(node) {
            // Stop rotation
            isRotating = false;
            document.getElementById('rotateBtn').textContent = '▶️ Rotate';
            
            // Get node's current position in the graph
            const graphData = Graph.graphData();
            const graphNode = graphData.nodes.find(n => n.id === node.id);
            
            if (graphNode && graphNode.x !== undefined) {
                // Reset previous highlighted node position if any
                if (highlightedNode && highlightedNode !== graphNode) {
                    highlightedNode.fx = undefined;
                    highlightedNode.fy = undefined;
                    highlightedNode.fz = undefined;
                }
                
                // Calculate direction from center to node
                const nodeDistance = Math.hypot(graphNode.x, graphNode.y, graphNode.z);
                const pullOutDistance = 80; // How far to pull out the node
                
                // Normalize and extend position to pull node out
                const scale = (nodeDistance + pullOutDistance) / (nodeDistance || 1);
                const newX = graphNode.x * scale;
                const newY = graphNode.y * scale;
                const newZ = graphNode.z * scale;
                
                // Fix the node at the pulled-out position
                graphNode.fx = newX;
                graphNode.fy = newY;
                graphNode.fz = newZ;
                highlightedNode = graphNode;
                
                // Reheat simulation briefly to animate the pull
                Graph.d3Force('charge').strength(-150);
                Graph.numDimensions(3); // Trigger update
                
                // Reset charge strength after animation
                setTimeout(() => {
                    Graph.d3Force('charge').strength(-80);
                }, 500);
                
                // Calculate camera position to focus on the new position
                const cameraDistance = 120; // How close to zoom
                const distRatio = 1 + cameraDistance / Math.hypot(newX, newY, newZ);
                
                Graph.cameraPosition(
                    { 
                        x: newX * distRatio, 
                        y: newY * distRatio, 
                        z: newZ * distRatio 
                    },
                    { x: newX, y: newY, z: newZ }, // Look at the new node position
                    1500 // Animation duration
                );
                
                // Update angle for rotation to match new position
                angle = Math.atan2(newX * distRatio, newZ * distRatio);
            }
        }
        
        // Clear highlighted node when clicking reset
        document.getElementById('resetBtn').addEventListener('click', function() {
            if (highlightedNode) {
                highlightedNode.fx = undefined;
                highlightedNode.fy = undefined;
                highlightedNode.fz = undefined;
                highlightedNode = null;
            }
            document.getElementById('playerSearch').value = '';
        });
        
        // Guide panel toggle
        function toggleGuide(event) {
            if (event) event.stopPropagation();
            const content = document.getElementById('guideContent');
            const toggle = document.getElementById('guideToggle');
            content.classList.toggle('collapsed');
            toggle.classList.toggle('collapsed');
        }
        
        // Close guide when clicking elsewhere on the page
        document.addEventListener('click', function(event) {
            const guidePanel = document.querySelector('.guide-panel');
            const guideContent = document.getElementById('guideContent');
            
            // If guide is open and click is outside the guide panel, close it
            if (guidePanel && !guidePanel.contains(event.target) && !guideContent.classList.contains('collapsed')) {
                guideContent.classList.add('collapsed');
                document.getElementById('guideToggle').classList.add('collapsed');
            }
        });
        
        // Guide starts expanded (no collapsed class added)
        
        // Handle window resize
        window.addEventListener('resize', () => {
            if (Graph) {
                Graph.width(window.innerWidth).height(window.innerHeight);
            }
        });
    </script>
</body>
</html>

<?php include 'includes/footer.php'; ?>
