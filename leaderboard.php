<?php
/**
 * Leaderboard Page
 * Displays match results and player statistics
 */

// Start session
session_start();

// Include database connection
require_once 'includes/db.php';

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get completed matches with results
$matchesQuery = "
    SELECT 
        m.id, 
        m.title, 
        m.match_type, 
        m.date, 
        m.winning_team, 
        m.result_description,
        u.username AS pro_name
    FROM 
        matches m
    JOIN 
        users u ON m.pro_id = u.id
    WHERE 
        m.status = 'completed' 
        AND m.match_completed = TRUE
    ORDER BY 
        m.date DESC, m.time DESC
";

$matches = $db->query($matchesQuery)->fetchAll(PDO::FETCH_ASSOC);

// Get player statistics
$playerStatsQuery = "
    SELECT 
        u.id,
        u.username,
        u.role,
        u.mmr,
        u.race_preference,
        COUNT(DISTINCT mp.match_id) AS matches_played,
        SUM(CASE WHEN m.winning_team = mp.team_id THEN 1 ELSE 0 END) AS wins
    FROM 
        users u
    LEFT JOIN 
        match_players mp ON u.id = mp.user_id
    LEFT JOIN 
        matches m ON mp.match_id = m.id AND m.status = 'completed' AND m.match_completed = TRUE
    WHERE 
        u.username LIKE 'ProGamer%' OR u.username LIKE 'Player%'
    GROUP BY 
        u.id
    ORDER BY 
        u.role DESC, wins DESC, matches_played DESC
";

$playerStats = $db->query($playerStatsQuery)->fetchAll(PDO::FETCH_ASSOC);

// Set page title
$pageTitle = "Leaderboard";

// Add additional CSS files
$additionalCss = ["css/leaderboard.css"];

// Include header
include_once 'includes/header.php';
?>

<h1 class="text-center">FSL Pros and Joes Leaderboard</h1>

<!-- Player Statistics -->
<section class="mb-5">
    <h2>Player Rankings</h2>
    <div class="table-responsive">
        <table class="table ranking-table">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Player</th>
                    <th>Type</th>
                    <th>MMR</th>
                    <th>Race</th>
                    <th>Matches</th>
                    <th>Wins</th>
                    <th>Win Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $rank = 1;
                foreach ($playerStats as $player): 
                    $winRate = $player['matches_played'] > 0 ? round(($player['wins'] / $player['matches_played']) * 100) : 0;
                ?>
                <tr>
                    <td data-label="Rank"><?= $rank++ ?></td>
                    <td data-label="Player"><?= htmlspecialchars($player['username']) ?></td>
                    <td data-label="Type"><?= ucfirst(htmlspecialchars($player['role'])) ?></td>
                    <td data-label="MMR"><?= htmlspecialchars($player['mmr']) ?></td>
                    <td data-label="Race"><?= htmlspecialchars($player['race_preference']) ?></td>
                    <td data-label="Matches"><?= $player['matches_played'] ?></td>
                    <td data-label="Wins"><?= $player['wins'] ?></td>
                    <td data-label="Win Rate"><?= $winRate ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Recent Match Results -->
<section class="mb-5">
    <h2>Recent Match Results</h2>
    <div class="match-results">
        <?php foreach ($matches as $match): ?>
            <div class="match-card">
                <div class="match-card-header">
                    <h3><?= htmlspecialchars($match['title']) ?></h3>
                    <div class="match-info">
                        <?= htmlspecialchars($match['match_type']) ?> • 
                        <?= date('F j, Y', strtotime($match['date'])) ?>
                    </div>
                </div>
                <div class="match-card-body">
                    <?php
                    // Get players for this match
                    $playersQuery = "
                        SELECT 
                            mp.team_id,
                            u.username,
                            u.role,
                            mp.is_pro
                        FROM 
                            match_players mp
                        JOIN 
                            users u ON mp.user_id = u.id
                        WHERE 
                            mp.match_id = ?
                        ORDER BY 
                            mp.team_id, mp.is_pro DESC
                    ";
                    $stmt = $db->prepare($playersQuery);
                    $stmt->execute([$match['id']]);
                    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Group players by team
                    $teams = [];
                    foreach ($players as $player) {
                        $teams[$player['team_id']][] = $player;
                    }
                    
                    // Display teams
                    echo '<div class="match-teams">';
                    foreach ($teams as $teamId => $teamPlayers) {
                        $isWinner = $match['winning_team'] == $teamId;
                        echo '<div class="team ' . ($isWinner ? 'winner' : '') . '">';
                        echo '<span class="team-name">Team ' . $teamId . ($isWinner ? ' (Winner)' : '') . ':</span> ';
                        
                        $playerNames = [];
                        foreach ($teamPlayers as $player) {
                            $playerType = $player['is_pro'] ? 'Pro' : 'Amateur';
                            $playerNames[] = htmlspecialchars($player['username']) . ' (' . $playerType . ')';
                        }
                        
                        echo implode(', ', $playerNames);
                        echo '</div>';
                    }
                    echo '</div>';
                    ?>
                    
                    <p class="match-result"><?= htmlspecialchars($match['result_description']) ?></p>
                    <p class="match-host">Hosted by: <?= htmlspecialchars($match['pro_name']) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php
// Include footer
include_once 'includes/footer.php';
?> 