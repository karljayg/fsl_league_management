<?php
/**
 * Draft Team View
 * Pick interface for team captains - Redesigned for optimal drafting UX
 */

require_once __DIR__ . '/../includes/data.php';
require_once __DIR__ . '/../includes/draft_logic.php';
require_once __DIR__ . '/../includes/auth.php';

$token = $_GET['token'] ?? '';
$team = get_team_by_token($token);

if (!$team) {
    http_response_code(403);
    echo '<h1>Access Denied</h1><p>Invalid team token.</p>';
    exit;
}

$session = get_session();

if (!$session) {
    echo '<h1>No Draft Available</h1>';
    exit;
}

// Check timer
check_timer();
$session = get_session();

$teams = get_teams();
$players = get_players();
$events = get_events();

// Is it this team's turn?
$isMyTurn = ($session['status'] === 'live' && $session['current_team_id'] === $team['id']);

// Get eligible players for this team
$eligiblePlayers = get_eligible_players_for_team($team['id']);
$eligibleIds = array_map(fn($p) => $p['id'], $eligiblePlayers);

// Get current team info
$currentTeamName = '';
$currentTeamLogo = '';
if ($session['current_team_id']) {
    $currentTeam = get_team_by_id($session['current_team_id']);
    $currentTeamName = $currentTeam ? $currentTeam['name'] : '';
    $currentTeamLogo = $currentTeam ? ($currentTeam['logo'] ?? '') : '';
}

$myRoster = get_team_roster($team['id']);
$bucketsUsed = get_team_buckets_used($team['id']);

// Sort players
usort($players, fn($a, $b) => $a['ranking'] - $b['ranking']);
$availableCount = count(array_filter($players, fn($p) => $p['status'] === 'available'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($team['name']) ?> - <?= htmlspecialchars($session['name']) ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&family=Exo+2:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../css/styles.css">
    <style>
        :root {
            --my-team-color: #6c5ce7;
            --pick-highlight: #00b894;
            --warning: #e74c3c;
        }
        
        body {
            background: linear-gradient(135deg, #0a0a0f 0%, #1a1a2e 50%, #0a0a0f 100%);
            min-height: 100vh;
            margin: 0;
            font-family: 'Exo 2', sans-serif;
            color: #fff;
        }
        
        /* Top Header Bar */
        .top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 2rem;
            background: rgba(0, 0, 0, 0.5);
            border-bottom: 2px solid rgba(108, 92, 231, 0.3);
        }
        
        .my-team-identity {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .my-team-logo {
            width: 140px;
            height: 140px;
            border-radius: 14px;
            border: 3px solid var(--my-team-color);
            box-shadow: 0 0 30px rgba(108, 92, 231, 0.4);
        }
        
        .my-team-info h2 {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
            color: #fff;
        }
        
        .my-team-info .picks-count {
            font-size: 0.9rem;
            color: #888;
        }
        
        /* Center Timer */
        .timer-center {
            text-align: center;
            flex: 1;
        }
        
        .status-label {
            font-family: 'Exo 2', sans-serif;
            font-size: 0.9rem;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        
        .timer-value {
            font-family: 'Rajdhani', sans-serif;
            font-size: 4rem;
            font-weight: 700;
            line-height: 1;
            color: #00b894;
            text-shadow: 0 0 30px rgba(0, 184, 148, 0.5);
        }
        
        .timer-value.urgent {
            color: #e74c3c;
            text-shadow: 0 0 30px rgba(231, 76, 60, 0.7);
            animation: pulse-timer 0.5s infinite;
        }
        
        @keyframes pulse-timer {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .current-picker {
            font-size: 1rem;
            color: #888;
            margin-top: 0.25rem;
        }
        
        .current-picker.my-turn {
            color: var(--pick-highlight);
            font-weight: 700;
            font-size: 1.2rem;
            animation: glow-text 1.5s infinite;
        }
        
        @keyframes glow-text {
            0%, 100% { text-shadow: 0 0 10px rgba(0, 184, 148, 0.5); }
            50% { text-shadow: 0 0 25px rgba(0, 184, 148, 0.9); }
        }
        
        /* FSL Logo */
        .fsl-logo {
            height: 70px;
        }
        
        /* My Turn Banner */
        .my-turn-banner {
            background: linear-gradient(135deg, rgba(0, 184, 148, 0.3), rgba(0, 184, 148, 0.1));
            border: 2px solid var(--pick-highlight);
            padding: 1rem 2rem;
            text-align: center;
            animation: banner-pulse 2s infinite;
        }
        
        @keyframes banner-pulse {
            0%, 100% { box-shadow: inset 0 0 30px rgba(0, 184, 148, 0.2); }
            50% { box-shadow: inset 0 0 50px rgba(0, 184, 148, 0.4); }
        }
        
        .my-turn-banner h3 {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--pick-highlight);
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 3px;
        }
        
        .my-turn-banner p {
            margin: 0.25rem 0 0;
            color: #aaa;
        }
        
        /* Main Content */
        .main-content {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 1.5rem;
            padding: 1.5rem 2rem;
            max-width: 1600px;
            margin: 0 auto;
        }
        
        @media (max-width: 1100px) {
            .main-content {
                grid-template-columns: 1fr;
            }
        }
        
        /* Players Section */
        .players-section {
            background: rgba(0, 0, 0, 0.4);
            border-radius: 12px;
            border: 1px solid rgba(108, 92, 231, 0.2);
            overflow: hidden;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            background: rgba(108, 92, 231, 0.1);
            border-bottom: 1px solid rgba(108, 92, 231, 0.2);
        }
        
        .section-header h2 {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.4rem;
            font-weight: 600;
            color: #a29bfe;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .section-header .count {
            color: var(--pick-highlight);
            font-weight: 700;
        }
        
        .players-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 0.75rem;
            padding: 1rem;
            max-height: 600px;
            overflow-y: auto;
        }
        
        .players-grid::-webkit-scrollbar { width: 8px; }
        .players-grid::-webkit-scrollbar-track { background: rgba(0, 0, 0, 0.2); }
        .players-grid::-webkit-scrollbar-thumb { background: #6c5ce7; border-radius: 4px; }
        
        /* Player Card */
        .player-card {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            transition: all 0.2s ease;
            cursor: default;
        }
        
        .player-card.eligible {
            border-color: rgba(0, 184, 148, 0.3);
            background: rgba(0, 184, 148, 0.05);
            cursor: pointer;
        }
        
        .player-card.eligible:hover {
            border-color: var(--pick-highlight);
            background: rgba(0, 184, 148, 0.15);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 184, 148, 0.2);
        }
        
        .player-card.ineligible {
            opacity: 0.5;
        }
        
        .player-card.drafted {
            opacity: 0.25;
            text-decoration: line-through;
        }
        
        .player-rank {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            border-radius: 50%;
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.9rem;
            font-weight: 700;
            color: #fff;
            margin-right: 0.75rem;
            flex-shrink: 0;
        }
        
        .player-details {
            flex: 1;
            min-width: 0;
        }
        
        .player-name {
            font-family: 'Exo 2', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            color: #fff;
            text-decoration: none;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .player-name:hover {
            color: #a29bfe;
        }
        
        .player-meta-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.25rem;
        }
        
        .race-icon {
            width: 18px;
            height: 18px;
        }
        
        .group-tag {
            font-size: 0.75rem;
            color: #888;
            background: rgba(255, 255, 255, 0.1);
            padding: 0.1rem 0.4rem;
            border-radius: 4px;
        }
        
        .player-notes {
            font-size: 0.7rem;
            color: #666;
            font-style: italic;
            margin-top: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .pick-button {
            background: linear-gradient(135deg, #00b894, #55efc4);
            color: #000;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.9rem;
            font-weight: 700;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.2s;
            margin-left: 0.75rem;
            flex-shrink: 0;
        }
        
        .pick-button:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 15px rgba(0, 184, 148, 0.4);
        }
        
        /* Sidebar */
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .sidebar-panel {
            background: rgba(0, 0, 0, 0.4);
            border-radius: 12px;
            border: 1px solid rgba(108, 92, 231, 0.2);
            overflow: hidden;
        }
        
        .sidebar-panel h3 {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.1rem;
            font-weight: 600;
            color: #a29bfe;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 0.75rem 1rem;
            margin: 0;
            background: rgba(108, 92, 231, 0.1);
            border-bottom: 1px solid rgba(108, 92, 231, 0.2);
        }
        
        .sidebar-panel-content {
            padding: 1rem;
        }
        
        /* My Roster */
        .my-roster-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .my-roster-item:last-child {
            border-bottom: none;
        }
        
        .my-roster-item .player-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .my-roster-item .rank {
            width: 22px;
            height: 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            border-radius: 50%;
            font-size: 0.7rem;
            font-weight: 700;
        }
        
        .my-roster-item .name {
            color: #fff;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .my-roster-item .name:hover {
            color: #a29bfe;
        }
        
        .empty-roster {
            color: #555;
            font-style: italic;
            text-align: center;
            padding: 1rem;
        }
        
        /* Groups Grid */
        .groups-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 0.4rem;
        }
        
        .group-chip {
            text-align: center;
            padding: 0.35rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.1);
            color: #888;
        }
        
        .group-chip.used {
            background: rgba(231, 76, 60, 0.3);
            color: #e74c3c;
            border: 1px solid rgba(231, 76, 60, 0.5);
        }
        
        .group-chip.available {
            background: rgba(0, 184, 148, 0.15);
            color: #00b894;
        }
        
        /* Other Teams */
        .other-team {
            padding: 0.75rem;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }
        
        .other-team:last-child {
            margin-bottom: 0;
        }
        
        .other-team-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .other-team-logo {
            width: 48px;
            height: 48px;
            border-radius: 6px;
        }
        
        .other-team-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: #fff;
            text-decoration: none;
            flex: 1;
        }
        
        .other-team-name:hover {
            color: #a29bfe;
        }
        
        .other-team-count {
            font-size: 0.8rem;
            color: #888;
        }
        
        .other-team-roster {
            font-size: 0.8rem;
            color: #888;
        }
        
        .other-team-roster span {
            display: inline-block;
            margin-right: 0.5rem;
        }
        
        /* Role markers */
        .role-marker {
            font-weight: 700;
            font-size: 0.75em;
            margin-left: 0.25rem;
        }
        .role-marker.captain { color: #ffd700; }
        .role-marker.protected { color: #00d4ff; }
        
        /* Toast */
        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            padding: 1rem 2rem;
            background: rgba(0, 0, 0, 0.9);
            border-radius: 8px;
            font-size: 1rem;
            z-index: 1000;
            border: 2px solid var(--pick-highlight);
        }
        
        .toast.error {
            border-color: var(--warning);
        }
        
        /* Waiting/Paused States */
        .status-overlay {
            text-align: center;
            padding: 3rem;
            color: #888;
        }
        
        .status-overlay h2 {
            font-family: 'Rajdhani', sans-serif;
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .status-overlay.completed h2 {
            color: var(--pick-highlight);
        }
    </style>
</head>
<body>
    <!-- Top Bar -->
    <div class="top-bar">
        <div class="my-team-identity">
            <?php if (!empty($team['logo'])): ?>
            <img src="../../<?= htmlspecialchars($team['logo']) ?>" alt="" class="my-team-logo">
            <?php endif; ?>
            <div class="my-team-info">
                <h2><?= htmlspecialchars($team['name']) ?></h2>
                <div class="picks-count"><?= count($myRoster) ?> player<?= count($myRoster) !== 1 ? 's' : '' ?> drafted</div>
            </div>
        </div>
        
        <div class="timer-center">
            <?php if ($session['status'] === 'live'): ?>
                <div class="status-label"><?= $isMyTurn ? 'Your Time' : 'On The Clock' ?></div>
                <div class="timer-value <?= $isMyTurn ? '' : '' ?>" id="timer">--:--</div>
                <div class="current-picker <?= $isMyTurn ? 'my-turn' : '' ?>">
                    <?= $isMyTurn ? '⚡ YOUR PICK!' : htmlspecialchars($currentTeamName) ?>
                </div>
            <?php elseif ($session['status'] === 'paused'): ?>
                <div class="status-label" style="color: #e74c3c;">Paused</div>
                <div class="timer-value" style="color: #555;">--:--</div>
            <?php elseif ($session['status'] === 'setup'): ?>
                <div class="status-label">Waiting</div>
                <div class="timer-value" style="color: #555; font-size: 2rem;">Starting Soon</div>
            <?php elseif ($session['status'] === 'completed'): ?>
                <div class="status-label" style="color: #00b894;">Complete</div>
                <div class="timer-value" style="color: #00b894; font-size: 2rem;">🏆 Done!</div>
            <?php endif; ?>
        </div>
        
        <a href="../../index.php"><img src="../../images/fsl_sc2_logo.png" alt="FSL" class="fsl-logo"></a>
    </div>
    
    <?php if ($isMyTurn): ?>
    <div class="my-turn-banner">
        <h3><i class="fas fa-bolt"></i> It's Your Turn! <i class="fas fa-bolt"></i></h3>
        <p>Select a player from the list below to draft them to your team</p>
    </div>
    <?php endif; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Players List -->
        <div class="players-section">
            <div class="section-header">
                <h2>Available Players <span class="count">(<?= $availableCount ?>)</span></h2>
                <?php if ($isMyTurn): ?>
                <span style="color: var(--pick-highlight);"><i class="fas fa-hand-pointer"></i> Click to draft</span>
                <?php endif; ?>
            </div>
            <div class="players-grid">
                <?php foreach ($players as $player): 
                    $isEligible = in_array($player['id'], $eligibleIds);
                    $isDrafted = $player['status'] !== 'available';
                    $cardClass = $isDrafted ? 'drafted' : ($isEligible ? 'eligible' : 'ineligible');
                    
                    $raceIcons = ['T' => 'terran_icon.png', 'P' => 'protoss_icon.png', 'Z' => 'zerg_icon.png', 'R' => 'random_icon.png'];
                    $icon = $raceIcons[$player['race']] ?? 'random_icon.png';
                    
                    $notes = $player['notes'] ?? '';
                    $notes = preg_replace('/\b(Captain|Protected)\s*-\s*[^,;]+[,;]?\s*/i', '', $notes);
                    $notes = trim($notes, " \t\n\r\0\x0B,;");
                ?>
                    <div class="player-card <?= $cardClass ?>"
                         <?php if ($isMyTurn && $isEligible && !$isDrafted): ?>
                         onclick="pickPlayer(<?= $player['id'] ?>, '<?= htmlspecialchars($player['display_name'], ENT_QUOTES) ?>')"
                         <?php endif; ?>>
                        <span class="player-rank"><?= $player['ranking'] ?></span>
                        <div class="player-details">
                            <a href="../../view_player.php?name=<?= urlencode($player['display_name']) ?>" class="player-name" target="_blank" onclick="event.stopPropagation();"><?= htmlspecialchars($player['display_name']) ?></a>
                            <div class="player-meta-row">
                                <img src="../../images/<?= $icon ?>" alt="<?= $player['race'] ?>" class="race-icon">
                                <span class="group-tag">G<?= $player['bucket_index'] ?></span>
                            </div>
                            <?php if (!empty($notes)): ?>
                            <div class="player-notes"><?= htmlspecialchars($notes) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php if ($isMyTurn && $isEligible && !$isDrafted): ?>
                        <button class="pick-button" onclick="event.stopPropagation(); pickPlayer(<?= $player['id'] ?>, '<?= htmlspecialchars($player['display_name'], ENT_QUOTES) ?>')">Draft</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Sidebar -->
        <div class="sidebar">
            <!-- My Roster -->
            <div class="sidebar-panel">
                <h3>Your Roster (<?= count($myRoster) ?>)</h3>
                <div class="sidebar-panel-content">
                    <?php if (empty($myRoster)): ?>
                        <div class="empty-roster">No players drafted yet</div>
                    <?php else: ?>
                        <?php foreach ($myRoster as $p): ?>
                            <div class="my-roster-item">
                                <div class="player-info">
                                    <span class="rank"><?= $p['ranking'] ?></span>
                                    <a href="../../view_player.php?name=<?= urlencode($p['display_name']) ?>" class="name" target="_blank"><?= htmlspecialchars($p['display_name']) ?></a><?= get_role_marker($p) ?>
                                </div>
                                <span class="group-tag">G<?= $p['bucket_index'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Groups Used -->
            <div class="sidebar-panel">
                <h3>Groups</h3>
                <div class="sidebar-panel-content">
                    <div class="groups-grid">
                        <?php for ($g = 1; $g <= 12; $g++): 
                            $isUsed = in_array($g, $bucketsUsed);
                        ?>
                            <div class="group-chip <?= $isUsed ? 'used' : 'available' ?>">G<?= $g ?></div>
                        <?php endfor; ?>
                    </div>
                    <div style="margin-top: 0.75rem; font-size: 0.75rem; color: #666; text-align: center;">
                        <span style="color: #00b894;">●</span> Available 
                        <span style="color: #e74c3c; margin-left: 0.5rem;">●</span> Used
                    </div>
                </div>
            </div>
            
            <!-- Other Teams -->
            <div class="sidebar-panel">
                <h3>Other Teams</h3>
                <div class="sidebar-panel-content" style="max-height: 250px; overflow-y: auto;">
                    <?php foreach ($teams as $t): ?>
                        <?php if ($t['id'] === $team['id']) continue; ?>
                        <?php $roster = get_team_roster($t['id']); ?>
                        <div class="other-team">
                            <div class="other-team-header">
                                <?php if (!empty($t['logo'])): ?>
                                <img src="../../<?= htmlspecialchars($t['logo']) ?>" alt="" class="other-team-logo">
                                <?php endif; ?>
                                <a href="../../view_team.php?name=<?= urlencode($t['name']) ?>" class="other-team-name" target="_blank"><?= htmlspecialchars($t['name']) ?></a>
                                <span class="other-team-count"><?= count($roster) ?></span>
                            </div>
                            <div class="other-team-roster">
                                <?php foreach ($roster as $p): ?>
                                    <span><?= htmlspecialchars($p['display_name']) ?></span>
                                <?php endforeach; ?>
                                <?php if (empty($roster)): ?>
                                    <span style="font-style: italic;">No picks</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div id="toast" class="toast" style="display: none;"></div>

    <script>
        const teamToken = '<?= $token ?>';
        const teamId = <?= $team['id'] ?>;
        const deadline = <?= $session['pick_deadline_at'] ? "'" . $session['pick_deadline_at'] . "'" : 'null' ?>;
        const status = '<?= $session['status'] ?>';
        const isMyTurn = <?= $isMyTurn ? 'true' : 'false' ?>;
        
        function updateTimer() {
            const timerEl = document.getElementById('timer');
            if (!timerEl || !deadline) return;
            
            const now = Date.now();
            const end = new Date(deadline).getTime();
            const diff = Math.max(0, Math.floor((end - now) / 1000));
            
            const mins = Math.floor(diff / 60);
            const secs = diff % 60;
            timerEl.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
            
            if (diff <= 15 && isMyTurn) {
                timerEl.classList.add('urgent');
            }
            
            if (diff === 0 && status === 'live') {
                setTimeout(() => location.reload(), 1500);
            }
        }
        
        if (deadline) {
            updateTimer();
            setInterval(updateTimer, 1000);
        }
        
        function showToast(msg, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = msg;
            toast.className = 'toast ' + type;
            toast.style.display = 'block';
            setTimeout(() => toast.style.display = 'none', 3000);
        }
        
        function pickPlayer(playerId, playerName) {
            if (!confirm(`Draft ${playerName}?`)) return;
            
            fetch('../ajax/pick.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ 
                    token: teamToken,
                    player_id: playerId
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(`Drafted ${playerName}!`);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.error || 'Pick failed', 'error');
                }
            })
            .catch(() => showToast('Network error', 'error'));
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
