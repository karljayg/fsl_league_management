<?php
/**
 * Standalone Spider Chart Player View
 * Displays only player spider chart analysis for embedding
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

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get player name and division from URL parameters
$playerName = isset($_GET['name']) ? htmlspecialchars($_GET['name']) : '';
$division = isset($_GET['division']) ? htmlspecialchars($_GET['division']) : '';
$greenscreen = isset($_GET['greenscreen']) && $_GET['greenscreen'] === 'yes';

if (empty($playerName)) {
    die("Player name is required");
}

if (empty($division)) {
    die("Division parameter is required (e.g., ?name=PlayerName&division=S)");
}

// Get player information for specific division
$playerQuery = "SELECT 
    p.Player_ID,
    p.Real_Name,
    fs.Division,
    fs.Race,
    fs.MapsW,
    fs.MapsL,
    fs.SetsW,
    fs.SetsL,
    t.Team_Name,
    p.Status,
    CASE 
        WHEN t.Captain_ID = p.Player_ID THEN 'Captain'
        WHEN t.Co_Captain_ID = p.Player_ID THEN 'Co-Captain'
        ELSE NULL
    END AS Team_Role
FROM Players p
LEFT JOIN FSL_STATISTICS fs ON p.Player_ID = fs.Player_ID AND fs.Division = :division
LEFT JOIN Teams t ON p.Team_ID = t.Team_ID
WHERE p.Real_Name = :playerName
LIMIT 1";

try {
    $stmt = $db->prepare($playerQuery);
    $stmt->execute(['playerName' => $playerName, 'division' => $division]);
    $playerInfo = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Query failed: " . $e->getMessage());
}

if (!$playerInfo || !$playerInfo['Player_ID']) {
    die("Player '{$playerName}' not found in division '{$division}'. Please check the player name and division code.");
}

// Get player spider chart data
$playerId = $playerInfo['Player_ID'];
$spiderChartData = null;
$voteStats = null;

try {
    // Get player attributes for specific division
    $attributesQuery = "SELECT * FROM Player_Attributes WHERE player_id = ? AND division = ?";
    $stmt = $db->prepare($attributesQuery);
    $stmt->execute([$playerId, $division]);
    $spiderChartData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($spiderChartData) {
        // Get voting statistics for specific division
        $voteStatsQuery = "
            SELECT 
                COUNT(DISTINCT pav.id) as total_votes,
                COUNT(DISTINCT pav.reviewer_id) as unique_reviewers,
                COUNT(DISTINCT pav.fsl_match_id) as matches_voted_on
            FROM Player_Attribute_Votes pav
            JOIN fsl_matches fm ON pav.fsl_match_id = fm.fsl_match_id
            WHERE (pav.player1_id = ? OR pav.player2_id = ?) 
            AND fm.t_code = ?
        ";
        $stmt = $db->prepare($voteStatsQuery);
        $stmt->execute([$playerId, $playerId, $division]);
        $voteStats = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Silently fail - spider chart data is optional
    error_log("Spider chart query failed: " . $e->getMessage());
}

// Calculate win rates
$totalMapsW = $playerInfo['MapsW'] ?? 0;
$totalMapsL = $playerInfo['MapsL'] ?? 0;
$totalSetsW = $playerInfo['SetsW'] ?? 0;
$totalSetsL = $playerInfo['SetsL'] ?? 0;

$mapWinRate = $totalMapsW + $totalMapsL > 0 ? round(($totalMapsW / ($totalMapsW + $totalMapsL)) * 100, 1) : 0;
$setWinRate = $totalSetsW + $totalSetsL > 0 ? round(($totalSetsW / ($totalSetsW + $totalSetsL)) * 100, 1) : 0;

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($playerName) ?> (Division <?= htmlspecialchars($division) ?>) - Spider Chart</title>
    <style>
        /* Global text outline for all text elements */
        * {
            text-shadow: 
                -1px -1px 0 #000,
                1px -1px 0 #000,
                -1px 1px 0 #000,
                1px 1px 0 #000;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: <?= $greenscreen ? '#00FF00' : '#000000' ?>; /* Green for chroma key, black by default */
            color: #ffffff;
            margin: 0;
            padding: 10px;
            line-height: 1.2;
            min-height: 100vh;
        }

        .spider-chart-container {
            max-width: 800px;
            margin: 0 auto;
            background: transparent;
            padding: 15px;
        }

        .player-header {
            text-align: center;
            margin-bottom: 15px;
        }

        .player-header h1 {
            color: #00d4ff;
            margin: 0;
            font-size: 2em;
            text-shadow: 
                -1px -1px 0 #000,
                1px -1px 0 #000,
                -1px 1px 0 #000,
                1px 1px 0 #000,
                0 0 10px rgba(0, 212, 255, 0.8);
        }

        .team-role-badge {
            display: inline-block;
            padding: 3px 8px;
            margin-left: 5px;
            border-radius: 4px;
            font-size: 0.4em;
            font-weight: bold;
            vertical-align: middle;
            border: 2px solid #000;
        }
        
        .team-role-badge.captain {
            background-color: #ffc107;
            color: #000;
        }
        
        .team-role-badge.co-captain {
            background-color: #6c757d;
            color: #ffffff;
        }

        .chart-content {
            display: grid;
            grid-template-columns: 1fr 0.8fr;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .chart-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            background: transparent;
            padding: 15px;
            min-height: 420px;
        }

        .chart-wrapper canvas {
            border: 2px solid #000000;
            border-radius: 8px;
            filter: drop-shadow(1px 1px 2px rgba(0, 0, 0, 0.8));
        }

        .info-stats {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }

        .stats-section {
            background: transparent;
            border: 2px solid #00d4ff;
            border-radius: 8px;
            padding: 8px;
        }

        .stats-section h3 {
            color: #00d4ff;
            margin: 0 0 4px 0;
            font-size: 1.1em;
            text-align: center;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px;
        }

        .fsl-info-grid {
            grid-template-columns: 1fr 1fr 1fr;
            grid-template-rows: repeat(2, 1fr);
        }

        .stat-item {
            padding: 4px;
            background: transparent;
            border: 1px solid #ffffff;
            border-radius: 4px;
            text-align: center;
        }

        .stat-item label {
            display: block;
            color: #00d4ff;
            font-weight: 600;
            margin-bottom: 2px;
            font-size: 0.8em;
        }

        .stat-value {
            color: #ffffff;
            font-size: 0.9em;
            font-weight: 600;
        }

        .no-data {
            text-align: center;
            padding: 30px;
            color: #ffffff;
            font-size: 1em;
        }

        .team-link {
            color: #ff6f61;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .team-link:hover {
            color: #ff8577;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .spider-chart-container {
                padding: 10px;
            }
            
            .player-header h1 {
                font-size: 1.8em;
            }
            
            .chart-content {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }
            
            .chart-wrapper {
                min-height: 300px;
                padding: 10px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 3px;
            }

            .fsl-info-grid {
                grid-template-columns: 1fr 1fr 1fr;
                grid-template-rows: repeat(2, 1fr);
            }
            
            .stat-item {
                padding: 3px;
            }
            
            .stat-item label {
                font-size: 0.7em;
            }
            
            .stat-value {
                font-size: 0.8em;
            }
        }

        @media (max-width: 480px) {
            .spider-chart-container {
                padding: 8px;
            }
            
            .player-header h1 {
                font-size: 1.5em;
            }
            
            .chart-wrapper {
                min-height: 250px;
                padding: 8px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 2px;
            }

            .fsl-info-grid {
                grid-template-columns: 1fr 1fr;
                grid-template-rows: repeat(3, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="spider-chart-container">
        <div class="player-header">
            <h1>
                <?= htmlspecialchars($playerName) ?>
                <span style="color: #ffc107; font-size: 0.6em;">(Division <?= htmlspecialchars($division) ?>)</span>
                <?php if (!empty($playerInfo['Team_Role'])): ?>
                    <span class="team-role-badge <?= strtolower($playerInfo['Team_Role']) ?>">
                        <?= htmlspecialchars($playerInfo['Team_Role']) ?>
                    </span>
                <?php endif; ?>
            </h1>
        </div>

        <?php if ($spiderChartData): ?>
            <div class="chart-content">
                <div class="chart-wrapper">
                    <canvas id="spiderChart" width="480" height="480"></canvas>
                </div>
                
                <div class="info-stats">
                    <div class="stats-section">
                        <h3>Analysis Stats</h3>
                        <div class="stats-grid">
                            <div class="stat-item">
                                <label>Total Votes:</label>
                                <span class="stat-value"><?= $voteStats['total_votes'] ?? 0 ?></span>
                            </div>
                            <div class="stat-item">
                                <label>Reviewers:</label>
                                <span class="stat-value"><?= $voteStats['unique_reviewers'] ?? 0 ?></span>
                            </div>
                            <div class="stat-item">
                                <label>Matches:</label>
                                <span class="stat-value"><?= $voteStats['matches_voted_on'] ?? 0 ?></span>
                            </div>
                            <div class="stat-item">
                                <label>Updated:</label>
                                <span class="stat-value"><?= date('M j', strtotime($spiderChartData['last_updated'])) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="stats-section">
                        <h3>FSL Info</h3>
                        <div class="stats-grid fsl-info-grid">
                            <div class="stat-item">
                                <label>Team:</label>
                                <span class="stat-value">
                                    <?php if (!empty($playerInfo['Team_Name'])): ?>
                                        <a href="view_team.php?name=<?= urlencode($playerInfo['Team_Name']) ?>" class="team-link">
                                            <?= htmlspecialchars($playerInfo['Team_Name']) ?>
                                        </a>
                                    <?php else: ?>
                                        None
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="stat-item">
                                <label>Race:</label>
                                <span class="stat-value"><?= htmlspecialchars($playerInfo['Race'] ?? 'N/A') ?></span>
                            </div>
                            <div class="stat-item">
                                <label>Maps:</label>
                                <span class="stat-value"><?= $totalMapsW ?>-<?= $totalMapsL ?></span>
                            </div>
                            <div class="stat-item">
                                <label>Map Win%:</label>
                                <span class="stat-value"><?= $mapWinRate ?>%</span>
                            </div>
                            <div class="stat-item">
                                <label>Sets:</label>
                                <span class="stat-value"><?= $totalSetsW ?>-<?= $totalSetsL ?></span>
                            </div>
                            <div class="stat-item">
                                <label>Set Win%:</label>
                                <span class="stat-value"><?= $setWinRate ?>%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <div class="no-data">
                <h2>No Spider Chart Data Available</h2>
                <p><?= htmlspecialchars($playerName) ?> has not been analyzed in Division <?= htmlspecialchars($division) ?> yet or has no voting data for this division.</p>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($spiderChartData): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('spiderChart').getContext('2d');
        
        const data = {
            labels: [
                'Micro <?= number_format($spiderChartData['micro'], 1) ?>/10',
                'Macro <?= number_format($spiderChartData['macro'], 1) ?>/10',
                'Clutch <?= number_format($spiderChartData['clutch'], 1) ?>/10',
                'Creativity <?= number_format($spiderChartData['creativity'], 1) ?>/10',
                'Aggression <?= number_format($spiderChartData['aggression'], 1) ?>/10',
                'Strategy <?= number_format($spiderChartData['strategy'], 1) ?>/10'
            ],
            datasets: [{
                label: '<?= htmlspecialchars($playerName) ?>',
                data: [
                    <?= $spiderChartData['micro'] ?>,
                    <?= $spiderChartData['macro'] ?>,
                    <?= $spiderChartData['clutch'] ?>,
                    <?= $spiderChartData['creativity'] ?>,
                    <?= $spiderChartData['aggression'] ?>,
                    <?= $spiderChartData['strategy'] ?>
                ],
                            backgroundColor: 'rgba(0, 212, 255, 0.2)',
            borderColor: '#00d4ff',
            borderWidth: 4,
            pointBackgroundColor: '#00d4ff',
            pointBorderColor: '#000000',
            pointBorderWidth: 3,
            pointRadius: 7
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
                            },
                            backdropColor: 'rgba(0, 0, 0, 0.9)',
                            backdropPadding: 3
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
                                size: 13,
                                weight: 'bold'
                            },
                            backdropColor: 'rgba(0, 0, 0, 0.9)',
                            backdropPadding: 6
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
        
        new Chart(ctx, config);
    });
    </script>
    <?php endif; ?>
</body>
</html>