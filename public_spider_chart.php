<?php
/**
 * Public FSL Spider Chart Viewer
 * Allows anyone to search and view player spider charts without authentication
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include database connection
require_once 'includes/db.php';
require_once 'config.php';

// Get spider chart configuration
$chart_min = $config['spider_chart']['chart_min'] ?? 2;
$chart_max = $config['spider_chart']['chart_max'] ?? 10;

// Get URL parameters
$selected_player = $_GET['player'] ?? null;
$selected_division = $_GET['division'] ?? null;

// Get all players with spider chart data
$players_with_data = $db->query("
    SELECT DISTINCT 
        p.Player_ID,
        p.Real_Name,
        p.Team_ID,
        t.Team_Name,
        GROUP_CONCAT(DISTINCT pa.division ORDER BY pa.division) as divisions
    FROM Players p
    LEFT JOIN Teams t ON p.Team_ID = t.Team_ID
    JOIN Player_Attributes pa ON p.Player_ID = pa.player_id
    WHERE p.Status = 'active'
    GROUP BY p.Player_ID, p.Real_Name, p.Team_ID, t.Team_Name
    ORDER BY p.Real_Name
")->fetchAll(PDO::FETCH_ASSOC);

// Get player scores if selected
$player_scores = null;
$player_info = null;
$vote_stats = null;

if ($selected_player) {
    // Get player info first (regardless of division)
    $stmt = $db->prepare("
        SELECT 
            p.Player_ID,
            p.Real_Name,
            p.Team_ID,
            t.Team_Name,
            p.Status
        FROM Players p
        LEFT JOIN Teams t ON p.Team_ID = t.Team_ID
        WHERE p.Player_ID = ?
    ");
    $stmt->execute([$selected_player]);
    $player_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If division is also selected, get scores and stats
    if ($selected_division) {
        // Get player attributes
        $stmt = $db->prepare("
            SELECT * FROM Player_Attributes 
            WHERE player_id = ? AND division = ?
        ");
        $stmt->execute([$selected_player, $selected_division]);
        $player_scores = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($player_scores) {
            // Get voting statistics
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
            $stmt->execute([$selected_player, $selected_player, $selected_division]);
            $vote_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
}

// Get top players by division for the report
$divisions = $db->query("SELECT DISTINCT division FROM Player_Attributes ORDER BY division")->fetchAll(PDO::FETCH_COLUMN);
$top_players_by_division = [];

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
        LEFT JOIN fsl_matches fm ON pav.fsl_match_id = fm.fsl_match_id AND fm.t_code = pa.division
        WHERE pa.division = ? AND p.Status = 'active'
        GROUP BY pa.player_id, pa.division, p.Real_Name, p.Team_ID, t.Team_Name, pa.micro, pa.macro, pa.clutch, pa.creativity, pa.aggression, pa.strategy
        ORDER BY avg_score DESC
        LIMIT 10
    ");
    $stmt->execute([$div]);
    $top_players_by_division[$div] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Set page title
$pageTitle = "FSL Public Spider Chart Viewer";

// Get cool stats for the header
$stats = [];

// Total reviewers
$stmt = $db->query("SELECT COUNT(DISTINCT reviewer_id) as total_reviewers FROM Player_Attribute_Votes");
$stats['total_reviewers'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_reviewers'];

// Total votes
$stmt = $db->query("SELECT COUNT(*) as total_votes FROM Player_Attribute_Votes");
$stats['total_votes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_votes'];

// Total players with spider chart data
$stmt = $db->query("SELECT COUNT(DISTINCT player_id) as total_players FROM Player_Attributes");
$stats['total_players'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_players'];

// Players rated (have votes)
$stmt = $db->query("SELECT COUNT(DISTINCT CASE WHEN pav.player1_id IS NOT NULL THEN pav.player1_id END + CASE WHEN pav.player2_id IS NOT NULL THEN pav.player2_id END) as players_rated FROM Player_Attribute_Votes pav");
$stats['players_rated'] = $stmt->fetch(PDO::FETCH_ASSOC)['players_rated'];

// Total matches voted on
$stmt = $db->query("SELECT COUNT(DISTINCT fsl_match_id) as total_matches FROM Player_Attribute_Votes");
$stats['total_matches'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_matches'];

// Average votes per player
$stmt = $db->query("SELECT ROUND(AVG(vote_count), 1) as avg_votes_per_player FROM (
    SELECT COUNT(*) as vote_count 
    FROM Player_Attribute_Votes pav 
    GROUP BY CASE WHEN pav.player1_id IS NOT NULL THEN pav.player1_id ELSE pav.player2_id END
) as vote_counts");
$stats['avg_votes_per_player'] = $stmt->fetch(PDO::FETCH_ASSOC)['avg_votes_per_player'];

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <div class="public-header">
        <h1>FSL Public Spider Chart Viewer</h1>
        <div class="public-subtitle">
            <p>Search and view player spider chart analysis from FSL voting data <a href="voting_guide.php" target="_blank" class="whats-this-link">What's this?</a></p>
        </div>
    </div>

    <!-- Cool Stats Section -->
    <div class="stats-section">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-content">
                    <div class="stat-number"><?= number_format($stats['total_reviewers']) ?></div>
                    <div class="stat-label">Total Reviewers</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🗳️</div>
                <div class="stat-content">
                    <div class="stat-number"><?= number_format($stats['total_votes']) ?></div>
                    <div class="stat-label">Total Votes</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🏆</div>
                <div class="stat-content">
                    <div class="stat-number"><?= number_format($stats['total_players']) ?></div>
                    <div class="stat-label">Players in System</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⭐</div>
                <div class="stat-content">
                    <div class="stat-number"><?= number_format($stats['players_rated']) ?></div>
                    <div class="stat-label">Players Rated</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🎮</div>
                <div class="stat-content">
                    <div class="stat-number"><?= number_format($stats['total_matches']) ?></div>
                    <div class="stat-label">Matches Analyzed</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-content">
                    <div class="stat-number"><?= $stats['avg_votes_per_player'] ?></div>
                    <div class="stat-label">Avg Votes/Player</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Player Search Section -->
    <div class="search-section">
        <div class="search-container">
            <div class="form-group">
                <label for="player-search">Search for a player: <span class="search-description">Start typing player name...</span></label>
                <input type="text" id="player-search" placeholder="Start typing player name..." class="form-control" 
                       value="<?= $selected_player && $player_info ? htmlspecialchars($player_info['Real_Name']) : '' ?>">
                <div id="player-suggestions" class="suggestions-dropdown"></div>
            </div>
            
            <?php if ($selected_player): ?>
            <div class="form-group">
                <label for="division-select">Select Division:</label>
                <select id="division-select" class="form-control">
                    <option value="">Choose division...</option>
                    <?php 
                    $player_divisions = [];
                    foreach ($players_with_data as $player) {
                        if ($player['Player_ID'] == $selected_player) {
                            $player_divisions = explode(',', $player['divisions']);
                            break;
                        }
                    }
                    foreach ($player_divisions as $div): 
                    ?>
                        <option value="<?= $div ?>" <?= $selected_division == $div ? 'selected' : '' ?>>
                            Code <?= $div ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Player Chart Section -->
    <?php if ($player_scores && $player_info): ?>
    <div class="player-chart-section">
        <div class="chart-container">
            <div class="chart-wrapper">
                <canvas id="spiderChart" width="600" height="600"></canvas>
            </div>
            
            <div class="player-info">
                <h2><?= htmlspecialchars($player_info['Real_Name']) ?></h2>
                <p><strong>Division:</strong> Code <?= $selected_division ?></p>
                <?php if ($player_info['Team_Name']): ?>
                    <p><strong>Team:</strong> <?= htmlspecialchars($player_info['Team_Name']) ?></p>
                <?php endif; ?>
                
                <div class="vote-stats">
                    <p><strong>Total Votes:</strong> <?= $vote_stats['total_votes'] ?></p>
                    <p><strong>Matches Voted On:</strong> <?= $vote_stats['matches_voted_on'] ?></p>
                    <p><strong>Unique Reviewers:</strong> <?= $vote_stats['unique_reviewers'] ?></p>
                </div>
                
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
                
                <div class="player-actions">
                    <a href="view_player.php?name=<?= urlencode($player_info['Real_Name']) ?>" 
                       class="btn btn-primary">View Full Profile</a>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>

    <?php endif; ?>

    <!-- Players by Division Report -->
    <div class="division-report-section">
        <div class="section-header">
            <h2>Top Players by Division</h2>
        </div>
        
        <div class="divisions-grid">
            <?php foreach ($divisions as $div): ?>
            <div class="division-card">
                <h3>Code <?= $div ?> Top 10</h3>
                <table class="division-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Player</th>
                            <th>Team</th>
                            <th>Avg Score</th>
                            <th>Votes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_players_by_division[$div] as $index => $player): ?>
                            <tr class="<?= $selected_player == $player['player_id'] && $selected_division == $div ? 'selected-player' : '' ?>">
                                <td><?= $index + 1 ?></td>
                                <td>
                                    <a href="?player=<?= $player['player_id'] ?>&division=<?= $div ?>" class="player-link">
                                        <?= htmlspecialchars($player['Real_Name']) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($player['Team_Name'] ?? 'N/A') ?></td>
                                <td><?= round($player['avg_score'], 2) ?></td>
                                <td><?= $player['total_votes'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
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

.public-header {
    text-align: center;
    margin-bottom: 40px;
    padding: 30px;
    background: rgba(0, 0, 0, 0.3);
    border-radius: 15px;
    border: 1px solid rgba(0, 212, 255, 0.2);
}

.public-header h1 {
    color: #00d4ff;
    margin-bottom: 10px;
    font-size: 2.5rem;
}

.public-subtitle p {
    color: #ccc;
    font-size: 1.1rem;
    margin: 0;
}

/* Stats Section Styles */
.stats-section {
    margin-bottom: 40px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 15px;
    margin-top: 20px;
}

.stat-card {
    background: linear-gradient(135deg, rgba(0, 212, 255, 0.1), rgba(48, 43, 99, 0.3));
    border: 1px solid rgba(0, 212, 255, 0.3);
    border-radius: 10px;
    padding: 15px;
    text-align: center;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0, 212, 255, 0.2);
    border-color: rgba(0, 212, 255, 0.5);
}

.stat-icon {
    font-size: 2rem;
    margin-bottom: 8px;
    display: block;
}

.stat-content {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.stat-number {
    font-size: 1.5rem;
    font-weight: bold;
    color: #00d4ff;
    margin-bottom: 3px;
    text-shadow: 0 0 10px rgba(0, 212, 255, 0.5);
}

.stat-label {
    font-size: 0.8rem;
    color: #ccc;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.search-section {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 15px;
    padding: 30px;
    margin-bottom: 40px;
}

.search-container {
    display: flex;
    gap: 20px;
    align-items: end;
    flex-wrap: wrap;
}

.form-group {
    display: flex;
    flex-direction: column;
    position: relative;
}

.form-group label {
    color: #00d4ff;
    font-weight: 600;
    margin-bottom: 8px;
}

.search-description {
    color: #ccc;
    font-weight: 400;
    font-size: 0.9rem;
}

.form-control {
    padding: 12px 16px;
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    background: rgba(0, 0, 0, 0.3);
    color: #e0e0e0;
    font-size: 1rem;
    min-width: 250px;
}

.form-control:focus {
    outline: none;
    border-color: #00d4ff;
    box-shadow: 0 0 0 2px rgba(0, 212, 255, 0.2);
}

/* Specific styling for select dropdowns */
select.form-control {
    min-width: 150px;
    padding: 12px 16px;
    line-height: 1.2;
    height: auto;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6,9 12,15 18,9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 8px center;
    background-size: 16px;
    padding-right: 35px;
}

.suggestions-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: rgba(0, 0, 0, 0.9);
    border: 1px solid rgba(0, 212, 255, 0.3);
    border-radius: 8px;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
}

.suggestion-item {
    padding: 10px 16px;
    cursor: pointer;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    transition: background-color 0.2s;
}

.suggestion-item:hover {
    background: rgba(0, 212, 255, 0.2);
}

.suggestion-item:last-child {
    border-bottom: none;
}

.player-chart-section {
    margin-bottom: 50px;
}

.chart-container {
    display: grid;
    grid-template-columns: 1fr 350px;
    gap: 30px;
    background: rgba(0, 0, 0, 0.3);
    border-radius: 15px;
    padding: 30px;
    border: 1px solid rgba(0, 212, 255, 0.2);
}

.chart-wrapper {
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
    padding: 25px;
}

.player-info h2 {
    color: #00d4ff;
    margin-bottom: 20px;
    font-size: 1.8rem;
}

.vote-stats {
    background: rgba(0, 0, 0, 0.2);
    border-radius: 8px;
    padding: 15px;
    margin: 20px 0;
}

.vote-stats p {
    margin: 8px 0;
    color: #ccc;
}

.scores-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin: 20px 0;
}

.score-item {
    display: flex;
    justify-content: space-between;
    padding: 8px 12px;
    background: rgba(0, 0, 0, 0.2);
    border-radius: 6px;
    font-size: 0.9rem;
}

.score-label {
    color: #00d4ff;
    font-weight: 600;
}

.score-value {
    font-weight: bold;
}

.average-score {
    text-align: center;
    padding: 15px;
    background: rgba(40, 167, 69, 0.2);
    border-radius: 8px;
    margin: 20px 0;
    font-size: 1.2em;
    font-weight: bold;
}

.player-actions {
    margin-top: 20px;
}

.btn {
    padding: 10px 20px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    text-decoration: none;
    text-align: center;
    transition: all 0.3s ease;
    display: inline-block;
}

.btn-primary {
    background-color: #00d4ff;
    color: #0f0c29;
}

.btn-primary:hover {
    background-color: #00b8e6;
    transform: translateY(-2px);
}

.no-selection {
    text-align: center;
    padding: 60px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 15px;
    margin-bottom: 40px;
}

.division-report-section {
    margin-top: 50px;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
}

.section-header h2 {
    color: #00d4ff;
    margin: 0;
    font-size: 2rem;
}

.whats-this-link {
    color: #00d4ff;
    text-decoration: none;
    font-size: 0.9em;
    padding: 4px 8px;
    border: 1px solid #00d4ff;
    border-radius: 4px;
    transition: all 0.3s ease;
    background: rgba(0, 212, 255, 0.1);
    margin-left: 10px;
    display: inline-block;
    vertical-align: middle;
}

.whats-this-link:hover {
    background: rgba(0, 212, 255, 0.2);
    color: #ffffff;
    text-shadow: 0 0 5px #00d4ff;
}

.divisions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 25px;
}

.division-card {
    background: rgba(0, 0, 0, 0.3);
    border-radius: 12px;
    padding: 25px;
    border: 1px solid rgba(0, 212, 255, 0.2);
}

.division-card h3 {
    color: #00d4ff;
    text-align: center;
    margin-bottom: 20px;
    font-size: 1.3rem;
}

.division-table {
    width: 100%;
    border-collapse: collapse;
}

.division-table th,
.division-table td {
    padding: 10px 8px;
    text-align: left;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.division-table th {
    background: rgba(0, 212, 255, 0.2);
    color: #ffffff;
    font-weight: 700;
    font-size: 0.9rem;
}

.division-table td {
    font-size: 0.9rem;
}

.player-link {
    color: #00d4ff;
    text-decoration: none;
    font-weight: 600;
}

.player-link:hover {
    text-decoration: underline;
}

.selected-player {
    background: rgba(0, 212, 255, 0.2);
}

@media (max-width: 768px) {
    .container {
        padding: 10px;
    }
    
    .public-header h1 {
        font-size: 2rem;
    }
    
    .search-container {
        flex-direction: column;
        gap: 15px;
    }
    
    .form-control {
        min-width: auto;
        width: 100%;
    }
    
    .chart-container {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .section-header {
        flex-direction: column;
        align-items: center;
        gap: 15px;
        text-align: center;
    }
    
    .divisions-grid {
        grid-template-columns: 1fr;
    }
    
    .division-table th,
    .division-table td {
        padding: 8px 6px;
        font-size: 0.8rem;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const playerSearch = document.getElementById('player-search');
    const playerSuggestions = document.getElementById('player-suggestions');
    const divisionSelect = document.getElementById('division-select');
    
    // Player search autocomplete
    playerSearch.addEventListener('input', function() {
        const query = this.value.trim();
        
        if (query.length < 3) {
            playerSuggestions.style.display = 'none';
            return;
        }
        
        // Fetch player suggestions
        fetch(`get_player_suggestions_public.php?q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                playerSuggestions.innerHTML = '';
                
                if (data.length > 0) {
                    data.forEach(player => {
                        const item = document.createElement('div');
                        item.className = 'suggestion-item';
                        item.innerHTML = `
                            <div style="font-weight: bold;">${player.name}</div>
                            <div style="font-size: 0.8em; color: #ccc;">
                                ${player.team} • Divisions: ${player.divisions}
                            </div>
                        `;
                        item.addEventListener('click', function() {
                            playerSearch.value = player.name;
                            playerSuggestions.style.display = 'none';
                            
                            // Redirect to player page
                            window.location.href = `?player=${player.id}`;
                        });
                        playerSuggestions.appendChild(item);
                    });
                    playerSuggestions.style.display = 'block';
                } else {
                    playerSuggestions.style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Error fetching suggestions:', error);
            });
    });
    
    // Hide suggestions when clicking outside
    document.addEventListener('click', function(e) {
        if (!playerSearch.contains(e.target) && !playerSuggestions.contains(e.target)) {
            playerSuggestions.style.display = 'none';
        }
    });
    
    // Division select change
    if (divisionSelect) {
        divisionSelect.addEventListener('change', function() {
            const playerId = new URLSearchParams(window.location.search).get('player');
            if (playerId && this.value) {
                window.location.href = `?player=${playerId}&division=${this.value}`;
            }
        });
    }
});
</script>

<?php if ($player_scores): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('spiderChart').getContext('2d');
    
    const data = {
        labels: ['Micro', 'Macro', 'Clutch', 'Creativity', 'Aggression', 'Strategy'],
        datasets: [{
            label: '<?= htmlspecialchars($player_info['Real_Name']) ?>',
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