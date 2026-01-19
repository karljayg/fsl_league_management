<?php
/**
 * FSL Player Profiles with Spider Chart Scores
 * Shows aggregated voting results for each player
 */

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

$message = '';
$error = '';

// Get all players who have votes
$players_with_votes = $db->query("
    SELECT DISTINCT 
        p.Player_ID,
        p.Real_Name,
        p.Team_ID,
        t.Team_Name,
        COUNT(DISTINCT pav.fsl_match_id) as matches_voted_on,
        COUNT(pav.id) as total_votes_received
    FROM Players p
    LEFT JOIN Teams t ON p.Team_ID = t.Team_ID
    INNER JOIN Player_Attribute_Votes pav ON (p.Player_ID = pav.player1_id OR p.Player_ID = pav.player2_id)
    WHERE p.Status = 'active'
    GROUP BY p.Player_ID, p.Real_Name, p.Team_ID, t.Team_Name
    ORDER BY total_votes_received DESC, p.Real_Name
")->fetchAll(PDO::FETCH_ASSOC);

// Get aggregated scores for each player
$player_scores = [];
foreach ($players_with_votes as $player) {
    $stmt = $db->prepare("
        SELECT 
            pa.division,
            pa.micro,
            pa.macro,
            pa.clutch,
            pa.creativity,
            pa.aggression,
            pa.strategy
        FROM Player_Attributes pa
        WHERE pa.player_id = ?
        ORDER BY pa.division
    ");
    $stmt->execute([$player['Player_ID']]);
    $scores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $player_scores[$player['Player_ID']] = $scores;
}

// Set page title
$pageTitle = "FSL Player Profiles - Spider Chart Scores";

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <div class="admin-header">
        <h1>FSL Player Profiles</h1>
        <div class="admin-user-info">
            <a href="spider_chart_admin.php" class="btn btn-secondary">← Back to Dashboard</a>
            <span>Logged in as: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></span>
            <a href="logout.php" class="btn btn-logout">Logout</a>
        </div>
    </div>
    
    <div class="page-subtitle">
        <p>Spider chart scores based on aggregated reviewer votes</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="stats-summary">
        <div class="row">
            <div class="col-md-3">
                <div class="stat-card">
                    <h3><?= count($players_with_votes) ?></h3>
                    <p>Players with Votes</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <h3><?= array_sum(array_column($players_with_votes, 'total_votes_received')) ?></h3>
                    <p>Total Votes Received</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <h3><?= array_sum(array_column($players_with_votes, 'matches_voted_on')) ?></h3>
                    <p>Total Matches Voted On</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <h3><?= count(array_unique(array_column($players_with_votes, 'Team_ID'))) ?></h3>
                    <p>Teams Represented</p>
                </div>
            </div>
        </div>
    </div>

    <div class="players-grid">
        <?php foreach ($players_with_votes as $player): ?>
            <div class="player-profile-card">
                <div class="player-header">
                    <h3><?= htmlspecialchars($player['Real_Name']) ?></h3>
                    <div class="player-team">
                        <?php if ($player['Team_Name']): ?>
                            <span class="team-badge"><?= htmlspecialchars($player['Team_Name']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="player-stats">
                    <div class="stat">
                        <span class="label">Matches Voted On:</span>
                        <span class="value"><?= $player['matches_voted_on'] ?></span>
                    </div>
                    <div class="stat">
                        <span class="label">Total Votes Received:</span>
                        <span class="value"><?= $player['total_votes_received'] ?></span>
                    </div>
                </div>

                <?php if (isset($player_scores[$player['Player_ID']]) && !empty($player_scores[$player['Player_ID']])): ?>
                    <div class="spider-charts">
                        <?php foreach ($player_scores[$player['Player_ID']] as $division_scores): ?>
                            <div class="division-chart">
                                <h4>Division: <?= htmlspecialchars($division_scores['division']) ?></h4>
                                <canvas id="chart_<?= $player['Player_ID'] ?>_<?= $division_scores['division'] ?>" width="300" height="300"></canvas>
                                
                                <div class="attribute-scores">
                                    <div class="score-item">
                                        <span class="attribute">Micro:</span>
                                        <span class="score"><?= number_format($division_scores['micro'], 1) ?>/10</span>
                                    </div>
                                    <div class="score-item">
                                        <span class="attribute">Macro:</span>
                                        <span class="score"><?= number_format($division_scores['macro'], 1) ?>/10</span>
                                    </div>
                                    <div class="score-item">
                                        <span class="attribute">Clutch:</span>
                                        <span class="score"><?= number_format($division_scores['clutch'], 1) ?>/10</span>
                                    </div>
                                    <div class="score-item">
                                        <span class="attribute">Creativity:</span>
                                        <span class="score"><?= number_format($division_scores['creativity'], 1) ?>/10</span>
                                    </div>
                                    <div class="score-item">
                                        <span class="attribute">Aggression:</span>
                                        <span class="score"><?= number_format($division_scores['aggression'], 1) ?>/10</span>
                                    </div>
                                    <div class="score-item">
                                        <span class="attribute">Strategy:</span>
                                        <span class="score"><?= number_format($division_scores['strategy'], 1) ?>/10</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-scores">
                        <p>No aggregated scores available yet. Votes are still being processed.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<style>
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

.page-subtitle {
    text-align: center;
    margin-bottom: 2rem;
    padding: 1rem;
    background: rgba(0, 0, 0, 0.2);
    border-radius: 8px;
    border: 1px solid rgba(0, 212, 255, 0.1);
}

.page-subtitle p {
    margin: 0;
    color: #ccc;
    font-size: 1.1rem;
}

.stats-summary {
    margin-bottom: 3rem;
}

.stat-card {
    background: white;
    padding: 1.5rem;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    text-align: center;
    margin-bottom: 1rem;
}

.stat-card h3 {
    font-size: 2.5rem;
    color: #667eea;
    margin: 0;
}

.stat-card p {
    margin: 0.5rem 0 0 0;
    color: #666;
    font-weight: 500;
}

.players-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 2rem;
    margin-top: 2rem;
}

.player-profile-card {
    background: white;
    border-radius: 15px;
    padding: 2rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    transition: transform 0.2s ease;
}

.player-profile-card:hover {
    transform: translateY(-5px);
}

.player-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #f0f0f0;
}

.player-header h3 {
    margin: 0;
    color: #333;
    font-size: 1.5rem;
}

.team-badge {
    background: #667eea;
    color: white;
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 500;
}

.player-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem;
    background: #f8f9fa;
    border-radius: 8px;
}

.stat .label {
    font-weight: 500;
    color: #666;
}

.stat .value {
    font-weight: bold;
    color: #333;
}

.spider-charts {
    margin-top: 2rem;
}

.division-chart {
    margin-bottom: 2rem;
    padding: 1.5rem;
    background: #f8f9fa;
    border-radius: 10px;
}

.division-chart h4 {
    margin: 0 0 1rem 0;
    color: #333;
    text-align: center;
}

.attribute-scores {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.75rem;
    margin-top: 1rem;
}

.score-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem;
    background: white;
    border-radius: 5px;
}

.score-item .attribute {
    font-weight: 500;
    color: #666;
}

.score-item .score {
    font-weight: bold;
    color: #667eea;
}

.no-scores {
    text-align: center;
    padding: 2rem;
    color: #666;
    font-style: italic;
}

canvas {
    max-width: 100%;
    height: auto;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Chart.js configuration
    const chartConfig = {
        type: 'radar',
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
                    pointLabels: {
                        font: {
                            size: 12,
                            weight: 'bold'
                        }
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    };

    // Create charts for each player
    <?php foreach ($players_with_votes as $player): ?>
        <?php if (isset($player_scores[$player['Player_ID']]) && !empty($player_scores[$player['Player_ID']])): ?>
            <?php foreach ($player_scores[$player['Player_ID']] as $division_scores): ?>
                const ctx_<?= $player['Player_ID'] ?>_<?= $division_scores['division'] ?> = document.getElementById('chart_<?= $player['Player_ID'] ?>_<?= $division_scores['division'] ?>').getContext('2d');
                
                new Chart(ctx_<?= $player['Player_ID'] ?>_<?= $division_scores['division'] ?>, {
                    ...chartConfig,
                    data: {
                        labels: ['Micro', 'Macro', 'Clutch', 'Creativity', 'Aggression', 'Strategy'],
                        datasets: [{
                            label: '<?= htmlspecialchars($player['Real_Name']) ?>',
                            data: [
                                <?= $division_scores['micro'] ?>,
                                <?= $division_scores['macro'] ?>,
                                <?= $division_scores['clutch'] ?>,
                                <?= $division_scores['creativity'] ?>,
                                <?= $division_scores['aggression'] ?>,
                                <?= $division_scores['strategy'] ?>
                            ],
                            backgroundColor: 'rgba(102, 126, 234, 0.2)',
                            borderColor: 'rgba(102, 126, 234, 1)',
                            borderWidth: 2,
                            pointBackgroundColor: 'rgba(102, 126, 234, 1)',
                            pointBorderColor: '#fff',
                            pointHoverBackgroundColor: '#fff',
                            pointHoverBorderColor: 'rgba(102, 126, 234, 1)'
                        }]
                    }
                });
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endforeach; ?>
});
</script>

<?php include 'includes/footer.php'; ?> 