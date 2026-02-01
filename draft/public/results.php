<?php
/**
 * Draft Results - Broadcast View
 * Clean display of all picks for streaming/broadcast
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/data.php';
require_once __DIR__ . '/../includes/draft_logic.php';

$session = get_session();

if (!$session) {
    echo '<h1>No Draft Available</h1><p>No draft session has been created yet.</p>';
    exit;
}

check_timer();
$session = get_session();

$teams = get_teams();
$players = get_players();
$events = get_events();

// Build team lookup
$teamLookup = [];
foreach ($teams as $team) {
    $teamLookup[$team['id']] = $team;
}

// Build player lookup
$playerLookup = [];
foreach ($players as $player) {
    $playerLookup[$player['id']] = $player;
}

// Get only actual picks (not admin assigns for captain/protected)
$picks = array_filter($events, fn($e) => $e['pick_number'] !== null);

// Team colors for visual distinction
$teamColors = [
    1 => '#e74c3c', // Red
    2 => '#3498db', // Blue  
    3 => '#2ecc71', // Green
    4 => '#f39c12', // Orange
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Draft Results - <?= htmlspecialchars($session['name']) ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&family=Exo+2:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../css/styles.css">
    <style>
        :root {
            --team-1: #e74c3c;
            --team-2: #3498db;
            --team-3: #2ecc71;
            --team-4: #f39c12;
        }
        
        * {
            box-sizing: border-box;
        }
        
        html, body {
            min-height: 100%;
            margin: 0;
        }
        
        body {
            background: linear-gradient(135deg, #0a0a0f 0%, #1a1a2e 50%, #0a0a0f 100%);
            color: #fff;
            display: flex;
            flex-direction: column;
        }
        
        .results-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            max-width: 100%;
            padding: 0.75rem 1.5rem;
        }
        
        /* Header */
        .results-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            padding: 0.5rem 1rem;
            border-bottom: 2px solid rgba(108, 92, 231, 0.3);
            flex-shrink: 0;
        }
        
        .results-header img {
            height: 40px;
        }
        
        .results-header h1 {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin: 0;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .status-badge.live { background: rgba(40, 167, 69, 0.3); color: #28a745; border: 1px solid #28a745; }
        .status-badge.paused { background: rgba(255, 193, 7, 0.3); color: #ffc107; border: 1px solid #ffc107; }
        .status-badge.completed { background: rgba(108, 92, 231, 0.3); color: #a29bfe; border: 1px solid #a29bfe; }
        .status-badge.setup { background: rgba(255, 193, 7, 0.3); color: #ffc107; border: 1px solid #ffc107; }
        
        /* Stats Bar */
        .stats-bar {
            display: flex;
            justify-content: center;
            gap: 2rem;
            padding: 0.4rem;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 8px;
            margin: 0.5rem 0;
            flex-shrink: 0;
        }
        
        .stat-item {
            text-align: center;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .stat-value {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.4rem;
            font-weight: 700;
            color: #6c5ce7;
        }
        
        .stat-label {
            font-size: 0.75rem;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        /* Table Container */
        .table-container {
            flex: 1;
            overflow: auto;
            display: flex;
            flex-direction: column;
        }
        
        /* Picks Table */
        .picks-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 3px;
            table-layout: fixed;
        }
        
        .picks-table th {
            font-family: 'Exo 2', sans-serif;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #888;
            padding: 0.4rem 0.5rem;
            text-align: left;
            border-bottom: 1px solid rgba(108, 92, 231, 0.3);
            position: sticky;
            top: 0;
            background: #0a0a0f;
        }
        
        .picks-table th.center { text-align: center; }
        .picks-table th.pick-col { width: 50px; }
        .picks-table th.rank-col { width: 50px; }
        .picks-table th.race-col { width: 45px; }
        .picks-table th.group-col { width: 55px; }
        
        .picks-table td {
            padding: 0.35rem 0.5rem;
            background: rgba(0, 0, 0, 0.25);
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .picks-table tr td:first-child {
            border-radius: 4px 0 0 4px;
        }
        
        .picks-table tr td:last-child {
            border-radius: 0 4px 4px 0;
        }
        
        .picks-table tr.skip td {
            opacity: 0.5;
            font-style: italic;
        }
        
        /* Pick Number */
        .pick-number {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
            color: #6c5ce7;
            text-align: center;
        }
        
        /* Player Name */
        .player-name {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.05rem;
            font-weight: 600;
            color: #fff;
            text-decoration: none;
            transition: color 0.2s ease;
        }
        
        .player-name:hover {
            color: #a29bfe;
            text-decoration: none;
        }
        
        /* Race */
        .race-cell {
            text-align: center;
        }
        
        .race-icon {
            width: 22px;
            height: 22px;
        }
        
        /* Team */
        .team-cell {
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        
        .team-logo {
            width: 24px;
            height: 24px;
            border-radius: 4px;
            object-fit: cover;
            flex-shrink: 0;
        }
        
        .team-name {
            font-family: 'Exo 2', sans-serif;
            font-weight: 600;
            font-size: 0.85rem;
            overflow: hidden;
            text-overflow: ellipsis;
            text-decoration: none;
            transition: opacity 0.2s ease;
        }
        
        .team-name:hover {
            opacity: 0.8;
            text-decoration: none;
        }
        
        .team-1 .team-name { color: var(--team-1); }
        .team-2 .team-name { color: var(--team-2); }
        .team-3 .team-name { color: var(--team-3); }
        .team-4 .team-name { color: var(--team-4); }
        
        /* Group/Bucket */
        .group-badge {
            display: inline-block;
            padding: 0.15rem 0.5rem;
            border-radius: 12px;
            font-family: 'Rajdhani', sans-serif;
            font-weight: 700;
            font-size: 0.8rem;
            background: rgba(108, 92, 231, 0.2);
            color: #a29bfe;
            border: 1px solid rgba(108, 92, 231, 0.4);
        }
        
        /* Ranking */
        .ranking-badge {
            display: inline-block;
            width: 26px;
            height: 26px;
            line-height: 26px;
            text-align: center;
            border-radius: 50%;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            font-family: 'Rajdhani', sans-serif;
            font-weight: 700;
            font-size: 0.8rem;
            color: #fff;
        }
        
        /* Empty state */
        .no-picks {
            text-align: center;
            padding: 2rem;
            color: #888;
            font-size: 1rem;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        /* Skip row */
        .skip-text {
            color: #e74c3c;
            font-style: italic;
            font-size: 0.85rem;
        }
        
        /* Auto-refresh indicator */
        .refresh-indicator {
            position: fixed;
            bottom: 0.5rem;
            right: 0.5rem;
            padding: 0.25rem 0.6rem;
            background: rgba(0, 0, 0, 0.7);
            border-radius: 12px;
            font-size: 0.65rem;
            color: #666;
        }
        
        .refresh-indicator .dot {
            display: inline-block;
            width: 6px;
            height: 6px;
            background: #28a745;
            border-radius: 50%;
            margin-right: 0.3rem;
            animation: blink 2s infinite;
        }
        
        @keyframes blink {
            0%, 50%, 100% { opacity: 1; }
            25%, 75% { opacity: 0.3; }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .results-container { padding: 0.5rem; }
            .results-header h1 { font-size: 1.2rem; }
            .picks-table td { padding: 0.25rem; font-size: 0.8rem; }
            .player-name { font-size: 0.9rem; }
            .race-icon { width: 18px; height: 18px; }
            .team-logo { width: 20px; height: 20px; }
            .stats-bar { gap: 1rem; }
        }
    </style>
</head>
<body>
    <div class="results-container">
        <div class="results-header">
            <a href="../../index.php"><img src="../../images/fsl_sc2_logo.png" alt="FSL"></a>
            <h1><?= htmlspecialchars($session['name']) ?></h1>
            <span class="status-badge <?= $session['status'] ?>"><?= ucfirst($session['status']) ?></span>
        </div>
        
        <div class="stats-bar">
            <div class="stat-item">
                <div class="stat-value"><?= count($picks) ?></div>
                <div class="stat-label">Picks Made</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= count(array_filter($players, fn($p) => $p['status'] === 'available')) ?></div>
                <div class="stat-label">Players Left</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= $session['current_pick_number'] ?? '-' ?></div>
                <div class="stat-label">Current Pick</div>
            </div>
        </div>
        
        <?php if (empty($picks)): ?>
            <div class="no-picks">
                <p>No picks have been made yet.</p>
                <p>Waiting for the draft to begin...</p>
            </div>
        <?php else: ?>
            <div class="table-container">
            <table class="picks-table">
                <thead>
                    <tr>
                        <th class="center pick-col">#</th>
                        <th class="center rank-col">Rank</th>
                        <th>Player</th>
                        <th class="center race-col">Race</th>
                        <th>Team</th>
                        <th class="center group-col">Group</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($picks as $event): 
                        $team = $teamLookup[$event['team_id']] ?? null;
                        $player = $event['player_id'] ? ($playerLookup[$event['player_id']] ?? null) : null;
                        $isSkip = $event['result'] === 'SKIP';
                    ?>
                        <tr class="<?= $isSkip ? 'skip' : '' ?>">
                            <td>
                                <div class="pick-number"><?= $event['pick_number'] ?></div>
                            </td>
                            <td class="text-center">
                                <?php if ($player): ?>
                                    <span class="ranking-badge"><?= $player['ranking'] ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isSkip): ?>
                                    <span class="skip-text">Skipped (<?= $event['skip_reason'] ?? 'timeout' ?>)</span>
                                <?php elseif ($player): ?>
                                    <a href="../../view_player.php?name=<?= urlencode($player['display_name']) ?>" class="player-name" target="_blank"><?= htmlspecialchars($player['display_name']) ?></a>
                                <?php else: ?>
                                    <span class="skip-text">Unknown</span>
                                <?php endif; ?>
                            </td>
                            <td class="race-cell">
                                <?php if ($player): 
                                    $raceIcons = ['T' => 'terran_icon.png', 'P' => 'protoss_icon.png', 'Z' => 'zerg_icon.png', 'R' => 'random_icon.png'];
                                    $icon = $raceIcons[$player['race']] ?? 'random_icon.png';
                                ?>
                                    <img src="../../images/<?= $icon ?>" alt="<?= $player['race'] ?>" class="race-icon" title="<?= $player['race'] ?>">
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($team): ?>
                                    <div class="team-cell team-<?= $team['id'] ?>">
                                        <?php if (!empty($team['logo'])): ?>
                                            <a href="../../view_team.php?name=<?= urlencode($team['name']) ?>" target="_blank">
                                                <img src="../../<?= htmlspecialchars($team['logo']) ?>" alt="" class="team-logo">
                                            </a>
                                        <?php endif; ?>
                                        <a href="../../view_team.php?name=<?= urlencode($team['name']) ?>" class="team-name" target="_blank"><?= htmlspecialchars($team['name']) ?></a>
                                    </div>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($player): ?>
                                    <span class="group-badge">G<?= $player['bucket_index'] ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="refresh-indicator">
        <span class="dot"></span> Auto-updating
    </div>
    
    <script>
        // Auto-refresh when draft is live
        const status = '<?= $session['status'] ?>';
        let currentVersion = '';
        
        async function checkForUpdates() {
            try {
                const response = await fetch('../ajax/state.php?check_only=1');
                const data = await response.json();
                if (currentVersion && data.version !== currentVersion) {
                    location.reload();
                }
                currentVersion = data.version;
            } catch (e) {
                // Silent fail
            }
        }
        
        // Poll every 3 seconds when live or paused
        if (status === 'live' || status === 'paused') {
            checkForUpdates();
            setInterval(checkForUpdates, 3000);
        }
    </script>
</body>
</html>
