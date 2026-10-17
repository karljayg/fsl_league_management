<?php
/**
 * Spider Chart Player Matchup View
 * Compact view for head-to-head matchup display - no FSL stats, bigger chart.
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once 'includes/db.php';
require_once 'config.php';

$chart_min = $config['spider_chart']['chart_min'] ?? 2;
$chart_max = $config['spider_chart']['chart_max'] ?? 10;

try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$playerName = isset($_GET['name']) ? htmlspecialchars($_GET['name']) : '';
$division   = isset($_GET['division']) ? htmlspecialchars($_GET['division']) : '';
$greenscreen = isset($_GET['greenscreen']) && $_GET['greenscreen'] === 'yes';

if (empty($playerName)) die("Player name is required");
if (empty($division))   die("Division parameter is required (e.g., ?name=PlayerName&division=S)");

$playerQuery = "SELECT 
    p.Player_ID,
    p.Real_Name,
    fs.Division,
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
    die("Player '{$playerName}' not found in division '{$division}'.");
}

$playerId       = $playerInfo['Player_ID'];
$spiderChartData = null;

try {
    $stmt = $db->prepare("SELECT * FROM Player_Attributes WHERE player_id = ? AND division = ?");
    $stmt->execute([$playerId, $division]);
    $spiderChartData = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Spider chart query failed: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($playerName) ?> Matchup - Spider Chart</title>
    <style>
        * {
            text-shadow:
                -1px -1px 0 #000,
                 1px -1px 0 #000,
                -1px  1px 0 #000,
                 1px  1px 0 #000;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: <?= $greenscreen ? '#00FF00' : '#000000' ?>;
            color: #ffffff;
            margin: 0;
            padding: 10px;
            line-height: 1.2;
            min-height: 100vh;
        }

        .spider-chart-container {
            max-width: 960px;
            margin: 0 auto;
            background: transparent;
            padding: 10px;
        }

        /* ── Team header ── */
        .team-header {
            text-align: center;
            margin-bottom: 10px;
        }

        .team-header h1 {
            color: #ff6f61;
            margin: 0;
            font-size: 1.6em;
            text-shadow:
                -1px -1px 0 #000,
                 1px -1px 0 #000,
                -1px  1px 0 #000,
                 1px  1px 0 #000,
                0 0 10px rgba(255, 111, 97, 0.7);
        }

        .team-role-badge {
            display: inline-block;
            padding: 3px 10px;
            margin-left: 6px;
            border-radius: 4px;
            font-size: 0.55em;
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

        /* ── Chart ── */
        .chart-wrapper {
            position: relative;
            height: 540px;
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
            background: transparent;
            padding: 5px;
            box-sizing: border-box;
        }

        .chart-wrapper canvas {
            border: 2px solid #000000;
            border-radius: 8px;
            filter: drop-shadow(1px 1px 2px rgba(0, 0, 0, 0.8));
        }

        .no-data-label {
            text-align: center;
            margin: 0 0 6px;
            color: #8a8a8a;
            font-size: 1.7em;
            font-weight: 700;
            letter-spacing: 0.22em;
            text-shadow: none;
        }
    </style>
</head>
<body>
    <div class="spider-chart-container">

        <div class="team-header">
            <h1>
                <?= !empty($playerInfo['Team_Name']) ? htmlspecialchars($playerInfo['Team_Name']) : 'No Team' ?>
                <?php if (!empty($playerInfo['Team_Role'])): ?>
                    <span class="team-role-badge <?= strtolower(str_replace('-', '', $playerInfo['Team_Role'])) === 'cocaptain' ? 'co-captain' : strtolower($playerInfo['Team_Role']) ?>">
                        <?= htmlspecialchars($playerInfo['Team_Role']) ?>
                    </span>
                <?php endif; ?>
            </h1>
        </div>

        <?php if (!$spiderChartData): ?>
            <div class="no-data-label">NO DATA YET</div>
        <?php endif; ?>
        <div class="chart-wrapper">
            <canvas id="spiderChart" width="900" height="540"></canvas>
        </div>

    </div>

    <?php
    $hasChartData = (bool) $spiderChartData;
    if ($hasChartData) {
        $chartScores = [
            (float) $spiderChartData['micro'],
            (float) $spiderChartData['macro'],
            (float) $spiderChartData['clutch'],
            (float) $spiderChartData['creativity'],
            (float) $spiderChartData['aggression'],
            (float) $spiderChartData['strategy'],
        ];
        $chartScoreLabels = array_map(function ($score) {
            return number_format($score, 1) . '/10';
        }, $chartScores);
    } else {
        $chartScores = [5, 5, 5, 5, 5, 5];
        $chartScoreLabels = ['5.0/10', '5.0/10', '5.0/10', '5.0/10', '5.0/10', '5.0/10'];
    }
    ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const hasChartData = <?= $hasChartData ? 'true' : 'false' ?>;
        const chartScores = <?= json_encode($chartScores) ?>;
        const chartScoreLabels = <?= json_encode($chartScoreLabels) ?>;
        const labelColor = hasChartData ? '#FFD700' : '#8a8a8a';
        const scoreColor = hasChartData ? '#ffffff' : '#8a8a8a';

        // ── Custom plugin: two-tone point labels using public Chart.js API ──
        const twoToneLabelsPlugin = {
            id: 'twoToneRadarLabels',
            afterDraw(chart) {
                const ctx   = chart.ctx;
                const scale = chart.scales.r;

                const attrNames  = ['Micro', 'Macro', 'Clutch', 'Creativity', 'Aggression', 'Strategy'];
                const attrScores = chartScoreLabels;

                // Label radius: just past the outermost ring (extra offset for 3× attribute text)
                const labelRadius = scale.drawingArea + 72;

                attrNames.forEach((name, i) => {
                    const angle = scale.getIndexAngle(i) - Math.PI / 2;
                    const x = scale.xCenter + Math.cos(angle) * labelRadius;
                    const y = scale.yCenter + Math.sin(angle) * labelRadius;

                    // Horizontal alignment based on position
                    const cosA = Math.cos(angle);
                    const align = cosA > 0.1 ? 'left' : cosA < -0.1 ? 'right' : 'center';

                    ctx.save();
                    ctx.textAlign    = align;
                    ctx.shadowColor  = '#000000';
                    ctx.shadowBlur   = 4;

                    // Attribute name — yellow when scored, gray when empty
                    ctx.font         = 'bold 22px Inter, sans-serif';
                    ctx.fillStyle    = labelColor;
                    ctx.textBaseline = 'bottom';
                    ctx.fillText(name, x, y);

                    // Score — white when scored, gray when empty
                    ctx.font         = '25px Inter, sans-serif';
                    ctx.fillStyle    = scoreColor;
                    ctx.textBaseline = 'top';
                    ctx.fillText(attrScores[i], x, y);

                    ctx.restore();
                });
            }
        };

        const ctx = document.getElementById('spiderChart').getContext('2d');

        const data = {
            // Labels hidden — drawn manually by plugin above
            labels: ['', '', '', '', '', ''],
            datasets: [{
                label: '<?= htmlspecialchars($playerName) ?>',
                data: chartScores,
                backgroundColor: hasChartData ? 'rgba(0, 212, 255, 0.2)' : 'rgba(150, 150, 150, 0.16)',
                borderColor: hasChartData ? '#00d4ff' : 'rgba(176, 176, 176, 0.8)',
                borderWidth: hasChartData ? 4 : 3,
                borderDash: hasChartData ? [] : [2, 6],
                borderCapStyle: 'round',
                pointBackgroundColor: hasChartData ? '#00d4ff' : 'rgba(176, 176, 176, 0.7)',
                pointBorderColor: hasChartData ? '#000000' : 'rgba(176, 176, 176, 0.4)',
                pointBorderWidth: hasChartData ? 3 : 1,
                pointRadius: hasChartData ? 7 : 4
            }]
        };

        const config = {
            type: 'radar',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    // Extra left/right so long labels (e.g. Aggression) stay inside the canvas
                    padding: { top: 110, right: 130, bottom: 110, left: 130 }
                },
                scales: {
                    r: {
                        beginAtZero: false,
                        max: <?= $chart_max ?>,
                        min: <?= $chart_min ?>,
                        ticks: {
                            stepSize: 2,
                            color: '#ffffff',
                            font: { weight: 'bold', size: 11 },
                            backdropColor: 'rgba(0, 0, 0, 0.75)',
                            backdropPadding: 3
                        },
                        grid: {
                            color: hasChartData ? 'rgba(255, 255, 255, 0.45)' : 'rgba(255, 255, 255, 0.22)',
                            lineWidth: 1.5
                        },
                        angleLines: {
                            color: hasChartData ? 'rgba(255, 255, 255, 0.45)' : 'rgba(255, 255, 255, 0.22)',
                            lineWidth: 1.5
                        },
                        pointLabels: {
                            display: false   // rendered by twoToneLabelsPlugin
                        }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            },
            plugins: [twoToneLabelsPlugin]
        };

        new Chart(ctx, config);
    });
    </script>
</body>
</html>
