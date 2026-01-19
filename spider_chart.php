<?php
/**
 * FSL Spider Chart Visualization
 * Displays player attribute scores in a radar/spider chart
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include database connection first
require_once 'includes/db.php';
require_once 'config.php';

// Set required permission
$required_permission = 'manage spider charts';

// Include permission check
require_once 'includes/check_permission.php';

// Get spider chart configuration
$chart_min = $config['spider_chart']['chart_min'] ?? 2;
$chart_max = $config['spider_chart']['chart_max'] ?? 10;

// Get all players with scores
$players = $db->query("
    SELECT DISTINCT 
        p.Player_ID,
        p.Real_Name,
        p.Team_ID,
        t.Team_Name
    FROM Players p
    LEFT JOIN Teams t ON p.Team_ID = t.Team_ID
    JOIN Player_Attributes pa ON p.Player_ID = pa.player_id
    WHERE p.Status = 'active'
    ORDER BY p.Real_Name
")->fetchAll(PDO::FETCH_ASSOC);

// Get divisions
$divisions = $db->query("SELECT DISTINCT division FROM Player_Attributes ORDER BY division")->fetchAll(PDO::FETCH_COLUMN);

// Get selected player and division
$selected_player = $_GET['player'] ?? null;
$selected_division = $_GET['division'] ?? ($divisions[0] ?? 'S');

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
            (pa.micro + pa.macro + pa.clutch + pa.creativity + pa.aggression + pa.strategy) / 6 as avg_score
        FROM Player_Attributes pa
        JOIN Players p ON pa.player_id = p.Player_ID
        LEFT JOIN Teams t ON p.Team_ID = t.Team_ID
        WHERE pa.division = ? AND p.Status = 'active'
        ORDER BY avg_score DESC
        LIMIT 5
    ");
    $stmt->execute([$div]);
    $top_players[$div] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Set page title
$pageTitle = "FSL Spider Chart Visualization";

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <div class="admin-header">
        <h1>FSL Spider Chart Visualization</h1>
        <div class="admin-user-info">
            <a href="spider_chart_admin.php" class="btn btn-secondary">← Back to Dashboard</a>
            <span>Logged in as: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></span>
            <a href="logout.php" class="btn btn-logout">Logout</a>
        </div>
    </div>
    
    <div class="spider-subtitle">
        <p>Compare player attributes across different divisions</p>
    </div>

    <div class="controls-section">
        <form method="get" class="player-selector">
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
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_players[$div] as $index => $player): ?>
                                <tr class="<?= $selected_player == $player['player_id'] ? 'selected-player' : '' ?>">
                                    <td><?= $index + 1 ?></td>
                                    <td>
                                        <a href="?player=<?= $player['player_id'] ?>&division=<?= $div ?>" class="player-link">
                                            <?= htmlspecialchars($player['Real_Name']) ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars($player['Team_Name'] ?? 'N/A') ?></td>
                                    <td><?= round($player['avg_score'], 2) ?></td>
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

    .spider-subtitle {
        text-align: center;
        margin-bottom: 2rem;
        padding: 1rem;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 8px;
        border: 1px solid rgba(0, 212, 255, 0.1);
    }

    .spider-subtitle p {
        margin: 0;
        color: #ccc;
        font-size: 1.1rem;
    }
    
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
    
    .scores-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin: 20px 0;
    }
    
    .score-item {
        display: flex;
        justify-content: space-between;
        padding: 8px;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 4px;
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
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
    }
    
    .leaderboard {
        background: rgba(0, 0, 0, 0.3);
        border-radius: 10px;
        padding: 20px;
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
        padding: 8px;
        text-align: left;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
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
    
    @media (max-width: 768px) {
        .container {
            padding: 10px;
        }
        
        .spider-header h1 {
            font-size: 1.8em;
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
        }
    }
</style>

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
                    max: <?= $chart_max ?>,
                    min: <?= $chart_min ?>,
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