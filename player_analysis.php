<?php
/**
 * FSL Player Analysis - Consolidated Page
 * Combines player profiles and spider chart visualization
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include database connection first
require_once 'includes/db.php';
require_once 'includes/reviewer_functions.php';
require_once 'config.php';

// Set required permission
$required_permission = 'view spider charts';

// Include permission check
require_once 'includes/check_permission.php';

// Get spider chart configuration
$attribute_offset = $config['spider_chart']['attribute_offset'] ?? 5;
$max_score = $config['spider_chart']['max_score'] ?? 10;
$chart_min = $config['spider_chart']['chart_min'] ?? 2;
$chart_max = $config['spider_chart']['chart_max'] ?? 10;

// Get URL parameters
$selected_tab = $_GET['tab'] ?? 'profiles';
$selected_player = $_GET['player'] ?? null;
$selected_division = $_GET['division'] ?? 'S';

// Get all players with scores
$players = $db->query("
    SELECT DISTINCT 
        p.Player_ID,
        p.Real_Name,
        p.Team_ID,
        t.Team_Name,
        COUNT(DISTINCT pav.id) as total_votes,
        COUNT(DISTINCT pav.fsl_match_id) as matches_voted_on,
        COUNT(DISTINCT pav.reviewer_id) as unique_reviewers
    FROM Players p
    LEFT JOIN Teams t ON p.Team_ID = t.Team_ID
    JOIN Player_Attributes pa ON p.Player_ID = pa.player_id
    LEFT JOIN Player_Attribute_Votes pav ON (p.Player_ID = pav.player1_id OR p.Player_ID = pav.player2_id)
    LEFT JOIN fsl_matches fm ON pav.fsl_match_id = fm.fsl_match_id
    WHERE p.Status = 'active'
    GROUP BY p.Player_ID, p.Real_Name, p.Team_ID, t.Team_Name
    ORDER BY p.Real_Name
")->fetchAll(PDO::FETCH_ASSOC);

// Get divisions
$divisions = $db->query("SELECT DISTINCT division FROM Player_Attributes ORDER BY division")->fetchAll(PDO::FETCH_COLUMN);

// Get player scores if selected
$player_scores = null;
if ($selected_player) {
    $stmt = $db->prepare("
        SELECT * FROM Player_Attributes 
        WHERE player_id = ? AND division = ?
    ");
    $stmt->execute([$selected_player, $selected_division]);
    $player_scores = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get top players by division for comparison
$top_players = [];
foreach ($divisions as $div) {
    $stmt = $db->prepare("
        SELECT 
            pa.*,
            p.Real_Name,
            p.Team_ID,
            t.Team_Name,
            (pa.micro + pa.macro + pa.clutch + pa.creativity + pa.aggression + pa.strategy) / 6 as avg_score,
            COUNT(DISTINCT pav.id) as total_votes,
            COUNT(DISTINCT pav.reviewer_id) as unique_reviewers
        FROM Player_Attributes pa
        JOIN Players p ON pa.player_id = p.Player_ID
        LEFT JOIN Teams t ON p.Team_ID = t.Team_ID
        LEFT JOIN Player_Attribute_Votes pav ON (p.Player_ID = pav.player1_id OR p.Player_ID = pav.player2_id)
        LEFT JOIN fsl_matches fm ON pav.fsl_match_id = fm.fsl_match_id AND fm.t_code = ?
        WHERE pa.division = ? AND p.Status = 'active'
        GROUP BY pa.player_id, pa.division, p.Real_Name, p.Team_ID, t.Team_Name, pa.micro, pa.macro, pa.clutch, pa.creativity, pa.aggression, pa.strategy
        ORDER BY avg_score DESC
        LIMIT 5
    ");
    $stmt->execute([$div, $div]);
    $top_players[$div] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get all players with votes for profiles tab (with division-specific vote counts)
$players_with_votes = $db->query("
    SELECT DISTINCT 
        p.Player_ID,
        p.Real_Name,
        p.Team_ID,
        t.Team_Name,
        GROUP_CONCAT(DISTINCT pa.division ORDER BY pa.division) as divisions
    FROM Players p
    LEFT JOIN Teams t ON p.Team_ID = t.Team_ID
    JOIN Player_Attribute_Votes pav ON (p.Player_ID = pav.player1_id OR p.Player_ID = pav.player2_id)
    LEFT JOIN Player_Attributes pa ON p.Player_ID = pa.player_id
    WHERE p.Status = 'active'
    GROUP BY p.Player_ID, p.Real_Name, p.Team_ID, t.Team_Name
    ORDER BY p.Real_Name
")->fetchAll(PDO::FETCH_ASSOC);

// Get division-specific vote statistics for each player
$players_with_votes_processed = [];
foreach ($players_with_votes as $player) {
    $player_divisions = !empty($player['divisions']) ? explode(',', $player['divisions']) : [];
    $player['division_stats'] = [];
    
    if (empty($player_divisions)) {
        // If no calculated attributes, get division from votes
        $stmt = $db->prepare("
            SELECT DISTINCT fm.t_code as division
            FROM Player_Attribute_Votes pav
            JOIN fsl_matches fm ON pav.fsl_match_id = fm.fsl_match_id
            WHERE (pav.player1_id = ? OR pav.player2_id = ?)
            ORDER BY fm.t_code
        ");
        $stmt->execute([$player['Player_ID'], $player['Player_ID']]);
        $vote_divisions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($vote_divisions as $div) {
            $stmt = $db->prepare("
                SELECT 
                    COUNT(DISTINCT pav.id) as total_votes,
                    COUNT(DISTINCT pav.fsl_match_id) as matches_voted_on,
                    COUNT(DISTINCT pav.reviewer_id) as unique_reviewers
                FROM Player_Attribute_Votes pav
                JOIN fsl_matches fm ON pav.fsl_match_id = fm.fsl_match_id
                WHERE (pav.player1_id = ? OR pav.player2_id = ?)
                AND fm.t_code = ?
            ");
            $stmt->execute([$player['Player_ID'], $player['Player_ID'], $div]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            $player['division_stats'][$div] = $stats;
        }
    } else {
        // Use calculated attributes divisions
        foreach ($player_divisions as $div) {
            $stmt = $db->prepare("
                SELECT 
                    COUNT(DISTINCT pav.id) as total_votes,
                    COUNT(DISTINCT pav.fsl_match_id) as matches_voted_on,
                    COUNT(DISTINCT pav.reviewer_id) as unique_reviewers
                FROM Player_Attribute_Votes pav
                JOIN fsl_matches fm ON pav.fsl_match_id = fm.fsl_match_id
                WHERE (pav.player1_id = ? OR pav.player2_id = ?)
                AND fm.t_code = ?
            ");
            $stmt->execute([$player['Player_ID'], $player['Player_ID'], $div]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            $player['division_stats'][$div] = $stats;
        }
    }
    
    $players_with_votes_processed[] = $player;
}

$players_with_votes = $players_with_votes_processed;

// Set page title
$pageTitle = "FSL Player Analysis";

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <div class="admin-header">
        <h1>FSL Player Analysis</h1>
        <div class="admin-user-info">
            <a href="spider_chart_admin.php" class="btn btn-secondary">← Back to Dashboard</a>
            <span>Logged in as: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></span>
            <a href="logout.php" class="btn btn-logout">Logout</a>
        </div>
    </div>
    
    <div class="analysis-subtitle">
        <p>Comprehensive player analysis with profiles and spider chart visualizations</p>
    </div>

    <!-- Breadcrumb Navigation -->
    <div class="breadcrumb">
        <a href="spider_chart_admin.php">Spider Chart Dashboard</a>
        <span class="breadcrumb-separator">→</span>
        <span class="breadcrumb-current">Player Analysis</span>
    </div>

    <!-- Tab Navigation -->
    <div class="tab-navigation">
        <button class="tab-button <?= $selected_tab === 'profiles' ? 'active' : '' ?>" 
                onclick="showTab('profiles')">Player Profiles</button>
        <button class="tab-button <?= $selected_tab === 'spider' ? 'active' : '' ?>" 
                onclick="showTab('spider')">Spider Charts</button>
    </div>

    <!-- Player Profiles Tab -->
    <div id="profiles-tab" class="tab-content <?= $selected_tab === 'profiles' ? 'active' : '' ?>">
        <div class="profiles-section">
            <h2>Player Profiles with Spider Chart Scores</h2>
            <p>View aggregated spider chart scores for all players who have received votes.</p>
            
            <?php
            // Calculate overall statistics (sum across all divisions)
            $total_players = count($players_with_votes);

            // Get total votes and matches from votes table
            $global_total_votes = $db->query("SELECT COUNT(*) FROM Player_Attribute_Votes")->fetchColumn();
            $global_unique_matches = $db->query("SELECT COUNT(DISTINCT fsl_match_id) FROM Player_Attribute_Votes")->fetchColumn();

            // Get active reviewers from database
            $active_reviewers = countReviewers('active');

            $avg_votes_per_player = $total_players > 0 ? round($global_total_votes / $total_players, 1) : 0;
            ?>
            
            <div class="overall-stats">
                <div class="stat-card">
                    <div class="stat-icon">👥</div>
                    <div class="stat-content">
                        <h3><?= $total_players ?></h3>
                        <p>Players with Votes</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-content">
                        <h3><?= $global_total_votes ?></h3>
                        <p>Total Votes</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">🎮</div>
                    <div class="stat-content">
                        <h3><?= $global_unique_matches ?></h3>
                        <p>Matches Voted On</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">👤</div>
                    <div class="stat-content">
                        <h3><?= $active_reviewers ?></h3>
                        <p>Active Reviewers</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">📈</div>
                    <div class="stat-content">
                        <h3><?= $avg_votes_per_player ?></h3>
                        <p>Avg Votes/Player</p>
                    </div>
                </div>
            </div>
            
            <div class="profiles-grid">
                <?php foreach ($players_with_votes as $player): ?>
                    <?php
                    // Get scores for this player (use first division if multiple, or determine division from votes)
                    $player_divisions = !empty($player['divisions']) ? explode(',', $player['divisions']) : [];
                    
                    if (empty($player_divisions)) {
                        // If no calculated attributes, determine division from votes
                        $stmt = $db->prepare("
                            SELECT DISTINCT fm.t_code as division
                            FROM Player_Attribute_Votes pav
                            JOIN fsl_matches fm ON pav.fsl_match_id = fm.fsl_match_id
                            WHERE (pav.player1_id = ? OR pav.player2_id = ?)
                            ORDER BY fm.t_code
                            LIMIT 1
                        ");
                        $stmt->execute([$player['Player_ID'], $player['Player_ID']]);
                        $vote_division = $stmt->fetch(PDO::FETCH_ASSOC);
                        $primary_division = $vote_division ? $vote_division['division'] : 'Unknown';
                    } else {
                        $primary_division = $player_divisions[0];
                    }
                    
                    $stmt = $db->prepare("
                        SELECT * FROM Player_Attributes 
                        WHERE player_id = ? AND division = ?
                    ");
                    $stmt->execute([$player['Player_ID'], $primary_division]);
                    $scores = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $has_scores = !empty($scores);
                    if ($has_scores) {
                        $avg_score = ($scores['micro'] + $scores['macro'] + $scores['clutch'] + 
                                     $scores['creativity'] + $scores['aggression'] + $scores['strategy']) / 6;
                    }
                    ?>
                    <div class="player-card <?= $has_scores ? 'has-scores' : 'pending-scores' ?>">
                        <div class="player-header">
                            <h3><?= htmlspecialchars($player['Real_Name']) ?></h3>
                            <?php if ($player['Team_Name']): ?>
                                <span class="team-name"><?= htmlspecialchars($player['Team_Name']) ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="player-stats">
                            <div class="stat-row">
                                <span class="stat-label">Division:</span>
                                <span class="stat-value division-<?= $primary_division ?>">Code <?= $primary_division ?></span>
                            </div>
                            <?php if ($has_scores): ?>
                            <div class="stat-row">
                                <span class="stat-label">Avg Score:</span>
                                <span class="stat-value"><?= round($avg_score, 2) ?>/10</span>
                            </div>
                            <?php else: ?>
                            <div class="stat-row">
                                <span class="stat-label">Status:</span>
                                <span class="stat-value pending-status">Pending Calculation</span>
                            </div>
                            <?php endif; ?>
                            <div class="stat-row">
                                <span class="stat-label">Total Votes:</span>
                                <span class="stat-value vote-count"><?= $player['division_stats'][$primary_division]['total_votes'] ?? 'N/A' ?></span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-label">Matches Voted On:</span>
                                <span class="stat-value"><?= $player['division_stats'][$primary_division]['matches_voted_on'] ?? 'N/A' ?></span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-label">Unique Reviewers:</span>
                                <span class="stat-value"><?= $player['division_stats'][$primary_division]['unique_reviewers'] ?? 'N/A' ?></span>
                            </div>
                        </div>
                        
                        <?php if ($has_scores): ?>
                        <div class="scores-grid">
                            <div class="score-item">
                                <span class="score-label">Micro:</span>
                                <span class="score-value"><?= $scores['micro'] ?>/10</span>
                            </div>
                            <div class="score-item">
                                <span class="score-label">Macro:</span>
                                <span class="score-value"><?= $scores['macro'] ?>/10</span>
                            </div>
                            <div class="score-item">
                                <span class="score-label">Clutch:</span>
                                <span class="score-value"><?= $scores['clutch'] ?>/10</span>
                            </div>
                            <div class="score-item">
                                <span class="score-label">Creativity:</span>
                                <span class="score-value"><?= $scores['creativity'] ?>/10</span>
                            </div>
                            <div class="score-item">
                                <span class="score-label">Aggression:</span>
                                <span class="score-value"><?= $scores['aggression'] ?>/10</span>
                            </div>
                            <div class="score-item">
                                <span class="score-label">Strategy:</span>
                                <span class="score-value"><?= $scores['strategy'] ?>/10</span>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="pending-message">
                            <p>⚠️ Scores are being calculated...</p>
                            <p>This player has received votes but their attribute scores are still being processed.</p>
                        </div>
                        <?php endif; ?>
                        
                        <div class="player-actions">
                            <a href="?tab=spider&player=<?= $player['Player_ID'] ?>&division=<?= $primary_division ?>" 
                               class="btn btn-primary">View Spider Chart</a>
                            <a href="view_player.php?name=<?= urlencode($player['Real_Name']) ?>" 
                               class="btn btn-secondary">View Profile</a>
                            <?php if (isset($_SESSION['user_id']) && hasRole()): ?>
                                <a href="voting_activity.php?player=<?= urlencode($player['Real_Name']) ?>&division=<?= $primary_division ?>" 
                                   class="btn btn-admin">Review Voting Activity</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Spider Charts Tab -->
    <div id="spider-tab" class="tab-content <?= $selected_tab === 'spider' ? 'active' : '' ?>">
        <div class="controls-section">
            <form method="get" class="player-selector">
                <input type="hidden" name="tab" value="spider">
                <div class="form-group">
                    <label>Select Player:</label>
                    <select name="player" onchange="this.form.submit()">
                        <option value="">Choose a player...</option>
                        <?php foreach ($players as $player): ?>
                            <option value="<?= $player['Player_ID'] ?>" 
                                    <?= $selected_player == $player['Player_ID'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($player['Real_Name']) ?>
                                <?php if ($player['Team_Name']): ?>
                                    (<?= htmlspecialchars($player['Team_Name']) ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Division:</label>
                    <select name="division" onchange="this.form.submit()">
                        <?php foreach ($divisions as $div): ?>
                            <option value="<?= $div ?>" <?= $selected_division == $div ? 'selected' : '' ?>>
                                Code <?= $div ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>

        <?php if ($player_scores): ?>
            <div class="chart-section">
                <div class="chart-container">
                    <canvas id="spiderChart" width="600" height="600"></canvas>
                </div>
                
                <div class="player-info">
                    <h2><?= htmlspecialchars($players[array_search($selected_player, array_column($players, 'Player_ID'))]['Real_Name']) ?></h2>
                    <p><strong>Division:</strong> Code <?= $selected_division ?></p>
                    <?php 
                    $player = $players[array_search($selected_player, array_column($players, 'Player_ID'))];
                    if ($player['Team_Name']): 
                    ?>
                        <p><strong>Team:</strong> <?= htmlspecialchars($player['Team_Name']) ?></p>
                    <?php endif; ?>
                    <?php
                    // Get division-specific stats for this player
                    $stmt = $db->prepare("
                        SELECT 
                            COUNT(DISTINCT pav.id) as total_votes,
                            COUNT(DISTINCT pav.fsl_match_id) as matches_voted_on,
                            COUNT(DISTINCT pav.reviewer_id) as unique_reviewers
                        FROM Player_Attribute_Votes pav
                        JOIN fsl_matches fm ON pav.fsl_match_id = fm.fsl_match_id
                        WHERE (pav.player1_id = ? OR pav.player2_id = ?)
                        AND fm.t_code = ?
                    ");
                    $stmt->execute([$player['Player_ID'], $player['Player_ID'], $selected_division]);
                    $div_stats = $stmt->fetch(PDO::FETCH_ASSOC);
                    ?>
                    <p><strong>Total Votes:</strong> <?= $div_stats['total_votes'] ?></p>
                    <p><strong>Matches Voted On:</strong> <?= $div_stats['matches_voted_on'] ?></p>
                    <p><strong>Unique Reviewers:</strong> <?= $div_stats['unique_reviewers'] ?></p>
                    
                    <div class="scores-grid">
                        <div class="score-item">
                            <span class="score-label">Micro:</span>
                            <span class="score-value"><?= $player_scores['micro'] ?>/10</span>
                        </div>
                        <div class="score-item">
                            <span class="score-label">Macro:</span>
                            <span class="score-value"><?= $player_scores['macro'] ?>/10</span>
                        </div>
                        <div class="score-item">
                            <span class="score-label">Clutch:</span>
                            <span class="score-value"><?= $player_scores['clutch'] ?>/10</span>
                        </div>
                        <div class="score-item">
                            <span class="score-label">Creativity:</span>
                            <span class="score-value"><?= $player_scores['creativity'] ?>/10</span>
                        </div>
                        <div class="score-item">
                            <span class="score-label">Aggression:</span>
                            <span class="score-value"><?= $player_scores['aggression'] ?>/10</span>
                        </div>
                        <div class="score-item">
                            <span class="score-label">Strategy:</span>
                            <span class="score-value"><?= $player_scores['strategy'] ?>/10</span>
                        </div>
                    </div>
                    
                    <div class="average-score">
                        <strong>Average Score:</strong> 
                        <?= round(($player_scores['micro'] + $player_scores['macro'] + $player_scores['clutch'] + 
                                  $player_scores['creativity'] + $player_scores['aggression'] + $player_scores['strategy']) / 6, 2) ?>/10
                    </div>
                    
                    <?php if (isset($_SESSION['user_id']) && hasRole()): ?>
                        <div class="player-actions">
                            <a href="voting_activity.php?player=<?= urlencode($player['Real_Name']) ?>&division=<?= $selected_division ?>" 
                               class="btn btn-admin">Review Voting Activity</a>
                            <a href="view_player.php?name=<?= urlencode($player['Real_Name']) ?>" 
                               class="btn btn-secondary">View Profile</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="no-selection">
                <h2>Select a player to view their spider chart</h2>
                <p>Choose a player from the dropdown above to see their attribute scores visualized.</p>
            </div>
        <?php endif; ?>

        <div class="leaderboards-section">
            <h2>Top Players by Division</h2>
            
            <div class="leaderboards-grid">
                <?php foreach ($divisions as $div): ?>
                    <div class="leaderboard">
                        <h3>Code <?= $div ?> Top 5</h3>
                        <table class="leaderboard-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Player</th>
                                    <th>Team</th>
                                    <th>Avg Score</th>
                                    <th>Votes</th>
                                    <th>Reviewers</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_players[$div] as $index => $player): ?>
                                    <tr class="<?= $selected_player == $player['player_id'] ? 'selected-player' : '' ?>">
                                        <td><?= $index + 1 ?></td>
                                        <td>
                                            <a href="?tab=spider&player=<?= $player['player_id'] ?>&division=<?= $div ?>" class="player-link">
                                                <?= htmlspecialchars($player['Real_Name']) ?>
                                            </a>
                                        </td>
                                        <td><?= htmlspecialchars($player['Team_Name'] ?? 'N/A') ?></td>
                                        <td><?= round($player['avg_score'], 2) ?></td>
                                        <td><?= $player['total_votes'] ?></td>
                                        <td><?= $player['unique_reviewers'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<style>
    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
        color: #e0e0e0;
        margin: 0;
        padding: 0;
        line-height: 1.4;
    }
    
    .container {
        max-width: 1200px;
        margin: 20px auto;
        padding: 20px;
    }
    
    .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        padding: 20px;
        background: rgba(0, 0, 0, 0.3);
        border-radius: 10px;
        border: 1px solid rgba(0, 212, 255, 0.2);
    }

    .admin-header h1 {
        margin: 0;
        color: #00d4ff;
        font-size: 2rem;
    }

    .admin-user-info {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .admin-user-info span {
        color: #ccc;
    }

    .analysis-subtitle {
        text-align: center;
        margin-bottom: 2rem;
        padding: 1rem;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 8px;
        border: 1px solid rgba(0, 212, 255, 0.1);
    }

    .analysis-subtitle p {
        margin: 0;
        color: #ccc;
        font-size: 1.1rem;
    }

    /* Breadcrumb Navigation */
    .breadcrumb {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 20px;
        padding: 10px 15px;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 8px;
        border: 1px solid rgba(0, 212, 255, 0.1);
    }

    .breadcrumb a {
        color: #00d4ff;
        text-decoration: none;
        transition: all 0.3s ease;
    }

    .breadcrumb a:hover {
        color: #ffffff;
        text-shadow: 0 0 5px #00d4ff;
    }

    .breadcrumb-separator {
        color: #666;
    }

    .breadcrumb-current {
        color: #ccc;
        font-weight: 600;
    }

    /* Tab Navigation */
    .tab-navigation {
        display: flex;
        margin-bottom: 30px;
        background: rgba(0, 0, 0, 0.3);
        border-radius: 10px;
        padding: 5px;
    }

    .tab-button {
        flex: 1;
        padding: 15px 20px;
        background: transparent;
        border: none;
        color: #ccc;
        font-size: 1.1rem;
        font-weight: 600;
        cursor: pointer;
        border-radius: 8px;
        transition: all 0.3s ease;
    }

    .tab-button:hover {
        background: rgba(0, 212, 255, 0.1);
        color: #00d4ff;
    }

    .tab-button.active {
        background: #00d4ff;
        color: #0f0c29;
    }

    /* Tab Content */
    .tab-content {
        display: none;
    }

    .tab-content.active {
        display: block;
    }

    /* Player Profiles Styles */
    .profiles-section h2 {
        color: #00d4ff;
        margin-bottom: 20px;
    }

    .profiles-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 20px;
    }

    .player-card {
        background: rgba(0, 0, 0, 0.3);
        border: 1px solid rgba(0, 212, 255, 0.2);
        border-radius: 10px;
        padding: 20px;
        transition: all 0.3s ease;
    }

    .player-card:hover {
        border-color: rgba(0, 212, 255, 0.4);
        transform: translateY(-2px);
    }

    .player-card.pending-scores {
        border-color: rgba(255, 193, 7, 0.4);
        background: rgba(255, 193, 7, 0.05);
    }

    .player-card.pending-scores:hover {
        border-color: rgba(255, 193, 7, 0.6);
    }

    .pending-status {
        color: #ffc107 !important;
        font-weight: 700;
    }

    .pending-message {
        background: rgba(255, 193, 7, 0.1);
        border: 1px solid rgba(255, 193, 7, 0.3);
        border-radius: 8px;
        padding: 15px;
        text-align: center;
        margin-top: 15px;
    }

    .pending-message p {
        margin: 5px 0;
        color: #ffc107;
        font-size: 0.9rem;
    }

    .pending-message p:first-child {
        font-weight: 700;
        font-size: 1rem;
    }

    .player-header {
        margin-bottom: 15px;
    }

    .player-header h3 {
        color: #00d4ff;
        margin: 0 0 5px 0;
    }

    .team-name {
        color: #ccc;
        font-size: 0.9rem;
    }

    .player-stats {
        margin-bottom: 15px;
    }

    .stat-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 5px;
    }

    .stat-label {
        color: #00d4ff;
        font-weight: 600;
    }

    .stat-value {
        font-weight: bold;
    }

    .division-S { color: #ff6f61; }
    .division-A { color: #ffcc00; }
    .division-B { color: #00d4ff; }

    .scores-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        margin-bottom: 15px;
    }

    .score-item {
        display: flex;
        justify-content: space-between;
        padding: 6px;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 4px;
        font-size: 0.9rem;
    }

    .score-label {
        color: #00d4ff;
        font-weight: 600;
    }

    .score-value {
        font-weight: bold;
    }

    .player-actions {
        display: flex;
        gap: 10px;
    }

    .btn {
        padding: 8px 16px;
        border-radius: 4px;
        border: none;
        cursor: pointer;
        font-weight: 600;
        text-decoration: none;
        text-align: center;
        transition: all 0.3s ease;
        font-size: 0.9rem;
    }

    .btn-primary {
        background-color: #00d4ff;
        color: #0f0c29;
    }

    .btn-primary:hover {
        background-color: #00b8e6;
    }

    .btn-secondary {
        background-color: rgba(255, 255, 255, 0.1);
        color: #e0e0e0;
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .btn-secondary:hover {
        background-color: rgba(255, 255, 255, 0.2);
    }

    .btn-admin {
        background-color: rgba(255, 193, 7, 0.2);
        color: #ffc107;
        border: 1px solid rgba(255, 193, 7, 0.4);
    }

    .btn-admin:hover {
        background-color: rgba(255, 193, 7, 0.3);
        color: #ffc107;
    }

    /* Spider Chart Styles */
    .controls-section {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 25px;
        margin-bottom: 30px;
    }
    
    .player-selector {
        display: flex;
        gap: 20px;
        align-items: end;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
    }
    
    .form-group label {
        color: #00d4ff;
        font-weight: 600;
        margin-bottom: 5px;
    }
    
    .form-group select {
        padding: 10px;
        border-radius: 4px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
        min-width: 200px;
    }
    
    .chart-section {
        display: grid;
        grid-template-columns: 1fr 300px;
        gap: 30px;
        margin-bottom: 40px;
    }
    
    .chart-container {
        background: rgba(0, 0, 0, 0.3);
        border-radius: 10px;
        padding: 20px;
        display: flex;
        justify-content: center;
        align-items: center;
    }
    
    .player-info {
        background: rgba(0, 212, 255, 0.1);
        border: 1px solid rgba(0, 212, 255, 0.3);
        border-radius: 10px;
        padding: 20px;
    }
    
    .player-info h2 {
        color: #00d4ff;
        margin-bottom: 15px;
    }
    
    .average-score {
        text-align: center;
        padding: 15px;
        background: rgba(40, 167, 69, 0.2);
        border-radius: 8px;
        margin-top: 15px;
        font-size: 1.2em;
    }
    
    .no-selection {
        text-align: center;
        padding: 60px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
    }
    
    .leaderboards-section {
        margin-top: 40px;
    }
    
    .leaderboards-section h2 {
        color: #00d4ff;
        text-align: center;
        margin-bottom: 30px;
    }
    
    .leaderboards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(580px, 1fr));
        gap: 30px;
        margin: 0 auto;
        max-width: 1400px;
    }
    
    .leaderboard {
        background: rgba(0, 0, 0, 0.3);
        border-radius: 10px;
        padding: 25px;
        min-width: 0;
        overflow: hidden;
    }
    
    .leaderboard h3 {
        color: #00d4ff;
        text-align: center;
        margin-bottom: 15px;
    }
    
    .leaderboard-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .leaderboard-table th,
    .leaderboard-table td {
        padding: 10px 8px;
        text-align: left;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .leaderboard-table th:nth-child(1),
    .leaderboard-table td:nth-child(1) {
        width: 50px;
        text-align: center;
    }
    
    .leaderboard-table th:nth-child(2),
    .leaderboard-table td:nth-child(2) {
        width: 130px;
        min-width: 130px;
    }
    
    .leaderboard-table th:nth-child(3),
    .leaderboard-table td:nth-child(3) {
        width: 160px;
        min-width: 160px;
        max-width: 160px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        padding-right: 10px;
    }
    
    .leaderboard-table th:nth-child(4),
    .leaderboard-table td:nth-child(4) {
        width: 85px;
        text-align: center;
        font-weight: bold;
        color: #00d4ff;
    }
    
    .leaderboard-table th:nth-child(5),
    .leaderboard-table td:nth-child(5) {
        width: 65px;
        text-align: center;
        font-size: 0.9em;
        color: #ccc;
    }
    
    .leaderboard-table th:nth-child(6),
    .leaderboard-table td:nth-child(6) {
        width: 95px;
        text-align: center;
        font-size: 0.9em;
        color: #ccc;
        white-space: nowrap;
    }
    
    .leaderboard-table th {
        background: rgba(0, 212, 255, 0.2);
        color: #ffffff;
        font-weight: 700;
    }
    
    .player-link {
        color: #00d4ff;
        text-decoration: none;
    }
    
    .player-link:hover {
        text-decoration: underline;
    }
    
    .selected-player {
        background: rgba(0, 212, 255, 0.2);
    }

    .vote-count {
        color: #00d4ff;
        font-weight: 700;
        font-size: 1.1em;
    }

    .stat-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 4px 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .stat-row:last-child {
        border-bottom: none;
    }

    .stat-label {
        color: #ccc;
        font-size: 0.9em;
    }

    .stat-value {
        color: #e0e0e0;
        font-weight: 600;
    }

    .overall-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .overall-stats .stat-card {
        background: rgba(0, 0, 0, 0.3);
        border: 1px solid rgba(0, 212, 255, 0.2);
        border-radius: 10px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        text-align: center;
    }

    .overall-stats .stat-icon {
        font-size: 2rem;
        width: 50px;
        height: 50px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(0, 212, 255, 0.2);
        border-radius: 50%;
    }

    .overall-stats .stat-content h3 {
        margin: 0;
        color: #00d4ff;
        font-size: 1.8rem;
        font-weight: 700;
    }

    .overall-stats .stat-content p {
        margin: 5px 0 0 0;
        color: #ccc;
        font-size: 0.9rem;
    }
    
    @media (max-width: 768px) {
        .container {
            padding: 10px;
        }
        
        .admin-header {
            flex-direction: column;
            gap: 15px;
        }
        
        .admin-header h1 {
            font-size: 1.8em;
        }
        
        .tab-navigation {
            flex-direction: column;
        }
        
        .player-selector {
            flex-direction: column;
            gap: 15px;
        }
        
        .chart-section {
            grid-template-columns: 1fr;
        }
        
        .scores-grid {
            grid-template-columns: 1fr;
        }
        
        .leaderboards-grid {
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        .leaderboard {
            padding: 20px;
        }
        
        .leaderboard-table th,
        .leaderboard-table td {
            padding: 8px 6px;
            font-size: 0.9em;
        }
        
        .leaderboard-table th:nth-child(2),
        .leaderboard-table td:nth-child(2) {
            width: 120px;
            min-width: 120px;
        }
        
        .leaderboard-table th:nth-child(3),
        .leaderboard-table td:nth-child(3) {
            width: auto;
            min-width: 80px;
            max-width: 100px;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        .leaderboard-table th:nth-child(5),
        .leaderboard-table td:nth-child(5) {
            width: 40px;
            font-size: 0.7em;
        }
        
        .leaderboard-table th:nth-child(6),
        .leaderboard-table td:nth-child(6) {
            width: 60px;
            font-size: 0.7em;
        }
        
        .profiles-grid {
            grid-template-columns: 1fr;
        }
        
        .player-actions {
            flex-direction: column;
        }
        
        .overall-stats {
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }
    }
    
    @media (max-width: 480px) {
        .leaderboard-table th,
        .leaderboard-table td {
            padding: 6px 4px;
            font-size: 0.8em;
        }
        
        .leaderboard-table th:nth-child(2),
        .leaderboard-table td:nth-child(2) {
            width: 110px;
            min-width: 110px;
        }
        
        .leaderboard-table th:nth-child(3),
        .leaderboard-table td:nth-child(3) {
            width: 140px;
            min-width: 140px;
            max-width: 140px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            padding-right: 8px;
        }
        
        .leaderboard {
            padding: 15px;
        }
        
        .leaderboard-table th:nth-child(5),
        .leaderboard-table td:nth-child(5) {
            width: 50px;
            font-size: 0.8em;
        }
        
        .leaderboard-table th:nth-child(6),
        .leaderboard-table td:nth-child(6) {
            width: 85px;
            font-size: 0.8em;
            white-space: nowrap;
        }
    }
    
    @media (max-width: 480px) {
        .leaderboard-table th,
        .leaderboard-table td {
            padding: 6px 4px;
            font-size: 0.8em;
        }
        
        .leaderboard-table th:nth-child(2),
        .leaderboard-table td:nth-child(2) {
            width: 90px;
            min-width: 90px;
        }
        
        .leaderboard-table th:nth-child(3),
        .leaderboard-table td:nth-child(3) {
            width: 120px;
            min-width: 120px;
            max-width: 120px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            padding-right: 6px;
        }
        
        .leaderboard-table th:nth-child(5),
        .leaderboard-table td:nth-child(5) {
            width: 40px;
            font-size: 0.7em;
        }
        
        .leaderboard-table th:nth-child(6),
        .leaderboard-table td:nth-child(6) {
            width: 75px;
            font-size: 0.7em;
            white-space: nowrap;
        }
        
        .leaderboard {
            padding: 15px;
        }
    }
</style>

<script>
function showTab(tabName) {
    // Hide all tab contents
    const tabContents = document.querySelectorAll('.tab-content');
    tabContents.forEach(content => {
        content.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    const tabButtons = document.querySelectorAll('.tab-button');
    tabButtons.forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected tab content
    document.getElementById(tabName + '-tab').classList.add('active');
    
    // Add active class to clicked button
    event.target.classList.add('active');
    
    // Update URL without page reload
    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    window.history.pushState({}, '', url);
}
</script>

<?php if ($player_scores): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('spiderChart').getContext('2d');
    
    const data = {
        labels: ['Micro', 'Macro', 'Clutch', 'Creativity', 'Aggression', 'Strategy'],
        datasets: [{
            label: '<?= htmlspecialchars($players[array_search($selected_player, array_column($players, 'Player_ID'))]['Real_Name']) ?>',
            data: [
                <?= $player_scores['micro'] ?>,
                <?= $player_scores['macro'] ?>,
                <?= $player_scores['clutch'] ?>,
                <?= $player_scores['creativity'] ?>,
                <?= $player_scores['aggression'] ?>,
                <?= $player_scores['strategy'] ?>
            ],
            backgroundColor: 'rgba(0, 212, 255, 0.2)',
            borderColor: '#00d4ff',
            borderWidth: 3,
            pointBackgroundColor: '#00d4ff',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 2,
            pointRadius: 6
        }]
    };
    
    const config = {
        type: 'radar',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                r: {
                    beginAtZero: false,
                    min: <?= $chart_min ?>,
                    max: <?= $chart_max ?>,
                    ticks: {
                        stepSize: 2,
                        color: '#000000',
                        font: {
                            weight: 'bold'
                        }
                    },
                    grid: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    },
                    angleLines: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    },
                    pointLabels: {
                        color: '#00d4ff',
                        font: {
                            size: 14,
                            weight: 'bold'
                        }
                    }
                }
            },
            plugins: {
                legend: {
                    labels: {
                        color: '#e0e0e0',
                        font: {
                            size: 16,
                            weight: 'bold'
                        }
                    }
                }
            }
        }
    };
    
    new Chart(ctx, config);
});
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?> 