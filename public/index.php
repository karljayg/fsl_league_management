<?php
/**
 * Draft Public View
 * Read-only spectator page - optimized for broadcast display
 */

require_once __DIR__ . '/../includes/data.php';
require_once __DIR__ . '/../includes/draft_logic.php';

$session = get_session();

if (!$session) {
    echo '<h1>No Draft Available</h1><p>No draft session has been created yet.</p>';
    exit;
}

// Check timer
check_timer();
$session = get_session();

$teams = get_teams();
$players = get_players();
$events = get_events();

// Get current team info
$currentTeamName = '';
$currentTeamLogo = '';
if ($session['current_team_id']) {
    $currentTeam = get_team_by_id($session['current_team_id']);
    $currentTeamName = $currentTeam ? $currentTeam['name'] : '';
    $currentTeamLogo = $currentTeam ? ($currentTeam['logo'] ?? '') : '';
}

// Sort players by ranking
usort($players, fn($a, $b) => $a['ranking'] - $b['ranking']);
$availablePlayers = array_filter($players, fn($p) => $p['status'] === 'available');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($session['name']) ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&family=Exo+2:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../css/styles.css">
    <style>
        :root {
            --team-1: #e74c3c;
            --team-2: #3498db;
            --team-3: #2ecc71;
            --team-4: #f39c12;
        }
        
        body {
            background: linear-gradient(135deg, #0a0a0f 0%, #1a1a2e 50%, #0a0a0f 100%);
            min-height: 100vh;
            padding-bottom: 80px; /* Space for ticker */
        }
        
        .public-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 1rem 2rem;
        }
        
        /* Header */
        .draft-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1.5rem;
            padding: 1rem 2rem;
            border-bottom: 2px solid rgba(108, 92, 231, 0.3);
            margin-bottom: 1.5rem;
        }
        
        .draft-header img {
            height: 70px;
        }
        
        .draft-header h1 {
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
        
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 10px rgba(40, 167, 69, 0.3); }
            50% { box-shadow: 0 0 25px rgba(40, 167, 69, 0.6); }
        }
        
        /* Timer Section */
        .timer-section {
            text-align: center;
            padding: 1.5rem;
            margin-bottom: 2rem;
            background: rgba(0, 0, 0, 0.4);
            border-radius: 15px;
            border: 1px solid rgba(108, 92, 231, 0.3);
        }
        
        .on-the-clock-label {
            font-family: 'Exo 2', sans-serif;
            font-size: 1.4rem;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 3px;
            margin-bottom: 0.5rem;
        }
        
        .on-clock-team {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1.5rem;
            margin: 0.5rem 0;
        }
        
        .on-clock-logo {
            width: 200px;
            height: 200px;
            border-radius: 16px;
            object-fit: cover;
            box-shadow: 0 0 50px rgba(108, 92, 231, 0.5);
            animation: logo-pulse 2s ease-in-out infinite;
        }
        
        @keyframes logo-pulse {
            0%, 100% { box-shadow: 0 0 30px rgba(108, 92, 231, 0.4); }
            50% { box-shadow: 0 0 50px rgba(108, 92, 231, 0.7); }
        }
        
        .current-team-name {
            font-family: 'Rajdhani', sans-serif;
            font-size: 2.5rem;
            font-weight: 700;
            color: #fff;
            text-shadow: 0 0 30px rgba(108, 92, 231, 0.5);
        }
        
        .timer-display {
            font-family: 'Rajdhani', sans-serif;
            font-size: 6rem;
            font-weight: 700;
            color: #00b894;
            text-shadow: 0 0 40px rgba(0, 184, 148, 0.5);
            line-height: 1;
        }
        
        .timer-display.urgent {
            color: #e74c3c;
            text-shadow: 0 0 40px rgba(231, 76, 60, 0.7);
            animation: timer-pulse 0.5s infinite;
        }
        
        @keyframes timer-pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.8; transform: scale(1.02); }
        }
        
        /* Main Layout */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 2rem;
        }
        
        @media (max-width: 1200px) {
            .main-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Teams Grid */
        .teams-section h2 {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.8rem;
            font-weight: 600;
            color: #a29bfe;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid rgba(108, 92, 231, 0.3);
        }
        
        .teams-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
        }
        
        @media (max-width: 1400px) {
            .teams-grid { grid-template-columns: repeat(2, 1fr); }
        }
        
        @media (max-width: 800px) {
            .teams-grid { grid-template-columns: 1fr; }
        }
        
        .team-panel {
            background: rgba(0, 0, 0, 0.5);
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }
        
        .team-panel:nth-child(1) { border-color: var(--team-1); }
        .team-panel:nth-child(2) { border-color: var(--team-2); }
        .team-panel:nth-child(3) { border-color: var(--team-3); }
        .team-panel:nth-child(4) { border-color: var(--team-4); }
        
        .team-panel.on-clock {
            box-shadow: 0 0 30px rgba(0, 184, 148, 0.4);
            border-color: #00b894 !important;
            transform: scale(1.02);
        }
        
        .team-header {
            padding: 1rem 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .team-panel:nth-child(1) .team-header { background: linear-gradient(135deg, rgba(231, 76, 60, 0.3), transparent); }
        .team-panel:nth-child(2) .team-header { background: linear-gradient(135deg, rgba(52, 152, 219, 0.3), transparent); }
        .team-panel:nth-child(3) .team-header { background: linear-gradient(135deg, rgba(46, 204, 113, 0.3), transparent); }
        .team-panel:nth-child(4) .team-header { background: linear-gradient(135deg, rgba(243, 156, 18, 0.3), transparent); }
        
        .team-header .team-logo {
            width: 96px;
            height: 96px;
            border-radius: 12px;
            object-fit: cover;
            margin-right: 1rem;
            flex-shrink: 0;
        }
        
        .team-header .team-name {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: #fff;
            text-decoration: none;
            flex: 1;
        }
        
        .team-header .team-name:hover {
            color: #a29bfe;
        }
        
        .team-header .pick-count {
            font-family: 'Exo 2', sans-serif;
            font-size: 0.9rem;
            color: #888;
            background: rgba(0, 0, 0, 0.3);
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
        }
        
        .team-roster-list {
            padding: 0.75rem 1.25rem 1.25rem;
            min-height: 120px;
        }
        
        .roster-player {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.6rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        
        .roster-player:last-child {
            border-bottom: none;
        }
        
        .roster-player .player-name {
            font-family: 'Exo 2', sans-serif;
            font-size: 1.1rem;
            color: #fff;
            text-decoration: none;
        }
        
        .roster-player .player-name:hover {
            color: #a29bfe;
        }
        
        .roster-player .player-meta {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .roster-player .player-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .roster-player .rank {
            width: 24px;
            height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            border-radius: 50%;
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.8rem;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
        }
        
        .roster-player .group-badge {
            font-size: 0.8rem;
            color: #6c5ce7;
            background: rgba(108, 92, 231, 0.15);
            padding: 0.15rem 0.5rem;
            border-radius: 4px;
        }
        
        .empty-roster {
            color: #555;
            font-style: italic;
            text-align: center;
            padding: 2rem 0;
        }
        
        /* Role markers */
        .role-marker {
            font-weight: 700;
            font-size: 0.85em;
            margin-left: 0.25rem;
        }
        .role-marker.captain { color: #ffd700; }
        .role-marker.protected { color: #00d4ff; }
        
        /* Sidebar - Available Players Only */
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        .sidebar-panel {
            background: rgba(0, 0, 0, 0.4);
            border-radius: 12px;
            border: 1px solid rgba(108, 92, 231, 0.2);
            overflow: hidden;
        }
        
        .sidebar-panel h3 {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.3rem;
            font-weight: 600;
            color: #a29bfe;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 1rem 1.25rem;
            margin: 0;
            background: rgba(108, 92, 231, 0.1);
            border-bottom: 1px solid rgba(108, 92, 231, 0.2);
        }
        
        .sidebar-panel h3 .count {
            color: #00b894;
            font-weight: 700;
        }
        
        /* Available Players */
        .players-list {
            max-height: 600px;
            overflow-y: auto;
            padding: 0.5rem;
        }
        
        .players-list::-webkit-scrollbar { width: 6px; }
        .players-list::-webkit-scrollbar-track { background: rgba(0, 0, 0, 0.2); }
        .players-list::-webkit-scrollbar-thumb { background: #6c5ce7; border-radius: 3px; }
        
        .player-row {
            display: flex;
            align-items: center;
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            margin-bottom: 0.25rem;
            transition: background 0.2s;
        }
        
        .player-row:hover {
            background: rgba(108, 92, 231, 0.1);
        }
        
        .player-row.drafted {
            opacity: 0.35;
        }
        
        .player-row .rank {
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            border-radius: 50%;
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.85rem;
            font-weight: 700;
            color: #fff;
            margin-right: 0.75rem;
            flex-shrink: 0;
        }
        
        .player-row .name {
            flex: 1;
            font-family: 'Exo 2', sans-serif;
            font-size: 0.95rem;
            color: #fff;
            text-decoration: none;
        }
        
        .player-row .name:hover {
            color: #a29bfe;
        }
        
        .player-row .race-icon {
            width: 20px;
            height: 20px;
            margin-left: 0.5rem;
        }
        
        .player-row .group {
            font-size: 0.75rem;
            color: #888;
            background: rgba(255, 255, 255, 0.1);
            padding: 0.1rem 0.4rem;
            border-radius: 8px;
            margin-left: 0.5rem;
        }
        
        /* ==================== */
        /* TICKER TAPE - ESPN STYLE */
        /* ==================== */
        .ticker-wrapper {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 70px;
            background: linear-gradient(180deg, #1a1a2e 0%, #0d0d15 100%);
            border-top: 3px solid #6c5ce7;
            z-index: 1000;
            display: flex;
            align-items: center;
            overflow: hidden;
        }
        
        .ticker-label {
            flex-shrink: 0;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            height: 100%;
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
            color: #fff;
            text-transform: uppercase;
            letter-spacing: 2px;
            border-right: 3px solid rgba(255, 255, 255, 0.2);
        }
        
        .ticker-content {
            flex: 1;
            overflow: hidden;
            position: relative;
            height: 100%;
        }
        
        .ticker-track {
            display: flex;
            align-items: center;
            height: 100%;
            animation: ticker-scroll 30s linear infinite;
            width: max-content;
        }
        
        .ticker-track:hover {
            animation-play-state: paused;
        }
        
        @keyframes ticker-scroll {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
        
        .ticker-item {
            display: flex;
            align-items: center;
            padding: 0 2rem;
            height: 100%;
            border-right: 1px solid rgba(108, 92, 231, 0.3);
            white-space: nowrap;
        }
        
        .ticker-item .pick-badge {
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            color: #fff;
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.2rem;
            font-weight: 700;
            padding: 0.3rem 0.7rem;
            border-radius: 6px;
            margin-right: 1rem;
        }
        
        .ticker-item .team-name {
            font-family: 'Exo 2', sans-serif;
            font-size: 1rem;
            color: #6c5ce7;
            margin-right: 0.5rem;
        }
        
        .ticker-item .arrow {
            color: #00b894;
            font-size: 1.2rem;
            margin: 0 0.5rem;
        }
        
        .ticker-item .player-name {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.3rem;
            font-weight: 600;
            color: #fff;
        }
        
        .ticker-item .race-icon {
            width: 22px;
            height: 22px;
            margin-left: 0.5rem;
        }
        
        .ticker-item.skip .player-name {
            color: #e74c3c;
            font-style: italic;
        }
        
        .ticker-empty {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            width: 100%;
            color: #555;
            font-family: 'Exo 2', sans-serif;
            font-size: 1.1rem;
            font-style: italic;
        }
        
        /* Latest pick highlight */
        .ticker-item.latest {
            background: rgba(0, 184, 148, 0.1);
        }
        
        .ticker-item.latest .pick-badge {
            background: linear-gradient(135deg, #00b894, #55efc4);
            animation: glow-badge 1.5s ease-in-out infinite;
        }
        
        @keyframes glow-badge {
            0%, 100% { box-shadow: 0 0 5px rgba(0, 184, 148, 0.5); }
            50% { box-shadow: 0 0 20px rgba(0, 184, 148, 0.8); }
        }
    </style>
</head>
<body>
    <div class="public-container">
        <!-- Header -->
        <div class="draft-header">
            <a href="../../index.php"><img src="../../images/fsl_sc2_logo.png" alt="FSL"></a>
            <h1><?= htmlspecialchars($session['name']) ?></h1>
        </div>

        <!-- Timer Section -->
        <?php if ($session['status'] === 'live'): ?>
        <div class="timer-section">
            <div class="on-the-clock-label">On The Clock</div>
            <div class="on-clock-team">
                <?php if (!empty($currentTeamLogo)): ?>
                <img src="../../<?= htmlspecialchars($currentTeamLogo) ?>" alt="" class="on-clock-logo">
                <?php endif; ?>
                <div class="current-team-name"><?= htmlspecialchars($currentTeamName) ?></div>
            </div>
            <div class="timer-display" id="timer">--:--</div>
        </div>
        <?php elseif ($session['status'] === 'paused'): ?>
        <div class="timer-section" style="padding: 1rem;">
            <div class="on-the-clock-label" style="color: #ffc107; font-size: 1.2rem;">⏸ Paused</div>
        </div>
        <?php elseif ($session['status'] === 'setup'): ?>
        <div class="timer-section" style="padding: 1rem;">
            <div class="on-the-clock-label" style="font-size: 1.2rem;">Waiting to Start...</div>
        </div>
        <?php elseif ($session['status'] === 'completed'): ?>
        <div class="timer-section" style="padding: 1rem;">
            <div class="on-the-clock-label" style="color: #00b894; font-size: 1.2rem;">🏆 Draft Complete!</div>
        </div>
        <?php endif; ?>

        <!-- Main Content -->
        <div class="main-grid">
            <!-- Teams Section -->
            <div class="teams-section">
                <h2>Team Rosters</h2>
                <div class="teams-grid">
                    <?php foreach ($teams as $team): ?>
                        <?php $roster = get_team_roster($team['id']); ?>
                        <div class="team-panel <?= $team['id'] === $session['current_team_id'] ? 'on-clock' : '' ?>">
                            <div class="team-header">
                                <?php if (!empty($team['logo'])): ?>
                                <img src="../../<?= htmlspecialchars($team['logo']) ?>" alt="" class="team-logo">
                                <?php endif; ?>
                                <a href="../../view_team.php?name=<?= urlencode($team['name']) ?>" class="team-name" target="_blank"><?= htmlspecialchars($team['name']) ?></a>
                                <span class="pick-count"><?= count($roster) ?> picks</span>
                            </div>
                            <div class="team-roster-list">
                                <?php if (empty($roster)): ?>
                                    <div class="empty-roster">No picks yet</div>
                                <?php else: ?>
                                    <?php foreach ($roster as $p): ?>
                                        <div class="roster-player">
                                            <span class="player-info">
                                                <span class="rank"><?= $p['ranking'] ?></span>
                                                <a href="../../view_player.php?name=<?= urlencode($p['display_name']) ?>" class="player-name" target="_blank"><?= htmlspecialchars($p['display_name']) ?></a><?= get_role_marker($p) ?>
                                            </span>
                                            <span class="player-meta">
                                                <img src="../../images/<?= ['T' => 'terran_icon.png', 'P' => 'protoss_icon.png', 'Z' => 'zerg_icon.png', 'R' => 'random_icon.png'][$p['race']] ?? 'random_icon.png' ?>" alt="<?= $p['race'] ?>" style="width: 18px; height: 18px;">
                                                <span class="group-badge">G<?= $p['bucket_index'] ?></span>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Sidebar - Available Players -->
            <div class="sidebar">
                <div class="sidebar-panel">
                    <h3>Available Players <span class="count">(<?= count($availablePlayers) ?>)</span></h3>
                    <div class="players-list">
                        <?php foreach ($players as $player): ?>
                            <div class="player-row <?= $player['status'] !== 'available' ? 'drafted' : '' ?>">
                                <span class="rank"><?= $player['ranking'] ?></span>
                                <a href="../../view_player.php?name=<?= urlencode($player['display_name']) ?>" class="name" target="_blank"><?= htmlspecialchars($player['display_name']) ?></a>
                                <?php
                                $raceIcons = ['T' => 'terran_icon.png', 'P' => 'protoss_icon.png', 'Z' => 'zerg_icon.png', 'R' => 'random_icon.png'];
                                $icon = $raceIcons[$player['race']] ?? 'random_icon.png';
                                ?>
                                <img src="../../images/<?= $icon ?>" alt="<?= $player['race'] ?>" class="race-icon">
                                <span class="group">G<?= $player['bucket_index'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Ticker Tape - Pick History -->
    <div class="ticker-wrapper">
        <div class="ticker-label">
            <i class="fas fa-history" style="margin-right: 0.5rem;"></i> PICKS
        </div>
        <div class="ticker-content">
            <?php if (empty($events)): ?>
                <div class="ticker-empty">Waiting for first pick...</div>
            <?php else: ?>
                <div class="ticker-track">
                    <?php 
                    // Show picks in order (oldest to newest for scrolling effect)
                    $totalEvents = count($events);
                    foreach ($events as $index => $event): 
                        $team = get_team_by_id($event['team_id']);
                        $player = $event['player_id'] ? get_player_by_id($event['player_id']) : null;
                        $isLatest = ($index === $totalEvents - 1);
                        $raceIcons = ['T' => 'terran_icon.png', 'P' => 'protoss_icon.png', 'Z' => 'zerg_icon.png', 'R' => 'random_icon.png'];
                    ?>
                        <div class="ticker-item <?= $event['result'] === 'SKIP' ? 'skip' : '' ?> <?= $isLatest ? 'latest' : '' ?>">
                            <span class="pick-badge">#<?= $event['pick_number'] ?? '-' ?></span>
                            <span class="team-name"><?= htmlspecialchars($team['name'] ?? '?') ?></span>
                            <span class="arrow">→</span>
                            <?php if ($event['result'] === 'SKIP'): ?>
                                <span class="player-name">SKIPPED</span>
                            <?php elseif ($player): ?>
                                <span class="player-name"><?= htmlspecialchars($player['display_name']) ?></span>
                                <img src="../../images/<?= $raceIcons[$player['race']] ?? 'random_icon.png' ?>" alt="<?= $player['race'] ?>" class="race-icon">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <!-- Duplicate for seamless loop -->
                    <?php foreach ($events as $index => $event): 
                        $team = get_team_by_id($event['team_id']);
                        $player = $event['player_id'] ? get_player_by_id($event['player_id']) : null;
                        $raceIcons = ['T' => 'terran_icon.png', 'P' => 'protoss_icon.png', 'Z' => 'zerg_icon.png', 'R' => 'random_icon.png'];
                    ?>
                        <div class="ticker-item <?= $event['result'] === 'SKIP' ? 'skip' : '' ?>">
                            <span class="pick-badge">#<?= $event['pick_number'] ?? '-' ?></span>
                            <span class="team-name"><?= htmlspecialchars($team['name'] ?? '?') ?></span>
                            <span class="arrow">→</span>
                            <?php if ($event['result'] === 'SKIP'): ?>
                                <span class="player-name">SKIPPED</span>
                            <?php elseif ($player): ?>
                                <span class="player-name"><?= htmlspecialchars($player['display_name']) ?></span>
                                <img src="../../images/<?= $raceIcons[$player['race']] ?? 'random_icon.png' ?>" alt="<?= $player['race'] ?>" class="race-icon">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const deadline = <?= $session['pick_deadline_at'] ? "'" . $session['pick_deadline_at'] . "'" : 'null' ?>;
        const status = '<?= $session['status'] ?>';
        
        function updateTimer() {
            const timerEl = document.getElementById('timer');
            if (!timerEl || !deadline) return;
            
            const now = Date.now();
            const end = new Date(deadline).getTime();
            const diff = Math.max(0, Math.floor((end - now) / 1000));
            
            const mins = Math.floor(diff / 60);
            const secs = diff % 60;
            timerEl.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
            
            if (diff <= 10) {
                timerEl.classList.add('urgent');
            } else {
                timerEl.classList.remove('urgent');
            }
            
            if (diff === 0 && status === 'live') {
                setTimeout(() => location.reload(), 1500);
            }
        }
        
        if (deadline) {
            updateTimer();
            setInterval(updateTimer, 1000);
        }
        
        // Smart refresh - only reload when data changes
        let currentVersion = '<?= get_data_version() ?>';
        
        async function checkForUpdates() {
            try {
                const response = await fetch('../ajax/state.php?check_only=1');
                const data = await response.json();
                if (data.version && data.version !== currentVersion) {
                    location.reload();
                }
            } catch (e) {
                // Silent fail
            }
        }
        
        setInterval(checkForUpdates, 2000);
    </script>
</body>
</html>
