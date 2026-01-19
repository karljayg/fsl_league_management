<?php
/**
 * FSL Season Standings and Schedule Page
 * Displays team standings and match schedule for Season 9
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

// Get Season 9 schedule from database
$scheduleQuery = "
    SELECT 
        s.schedule_id,
        s.season,
        s.week_number,
        s.match_date,
        s.team1_id,
        s.team2_id,
        s.team1_score,
        s.team2_score,
        s.winner_team_id,
        s.status,
        s.notes,
        s.team_2v2_results,
        t1.Team_Name as team1_name,
        t2.Team_Name as team2_name,
        tw.Team_Name as winner_name
    FROM fsl_schedule s
    JOIN Teams t1 ON s.team1_id = t1.Team_ID
    JOIN Teams t2 ON s.team2_id = t2.Team_ID
    LEFT JOIN Teams tw ON s.winner_team_id = tw.Team_ID
    WHERE s.season = 9
    ORDER BY s.week_number
";

try {
    $season9Schedule = $db->query($scheduleQuery)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Schedule query failed: " . $e->getMessage());
}

// Get individual match IDs for each schedule entry
function getScheduleMatchIds($db, $scheduleId) {
    $query = "SELECT fsl_match_id FROM fsl_schedule_matches WHERE schedule_id = ? ORDER BY 
        CASE match_type 
            WHEN 'S' THEN 1 
            WHEN 'A' THEN 2 
            WHEN 'B' THEN 3 
            WHEN '2v2' THEN 4 
            WHEN 'ACE' THEN 5 
            ELSE 6 
        END";
    $stmt = $db->prepare($query);
    $stmt->execute([$scheduleId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get individual match details for weeks that have data
function getMatchDetails($db, $matchIds) {
    if (empty($matchIds)) return [];
    
    $placeholders = str_repeat('?,', count($matchIds) - 1) . '?';
    $query = "SELECT 
        fm.fsl_match_id,
        fm.t_code,
        fm.map_win,
        fm.map_loss,
        fm.notes,
        p_w.Real_Name AS winner_name,
        p_w.Team_ID AS winner_team_id,
        p_l.Real_Name AS loser_name,
        p_l.Team_ID AS loser_team_id,
        fm.winner_race,
        fm.loser_race
    FROM fsl_matches fm
    JOIN Players p_w ON fm.winner_player_id = p_w.Player_ID
    JOIN Players p_l ON fm.loser_player_id = p_l.Player_ID
    WHERE fm.fsl_match_id IN ($placeholders)
    ORDER BY 
        CASE fm.t_code 
            WHEN 'S' THEN 1 
            WHEN 'A' THEN 2 
            WHEN 'B' THEN 3 
            WHEN '2v2' THEN 4 
            WHEN 'ACE' THEN 5 
            ELSE 6 
        END";
    
    $stmt = $db->prepare($query);
    $stmt->execute($matchIds);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Set page title
$pageTitle = "FSL Season 9 Standings and Schedule";

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <h1>Season 9 Standings and Schedule</h1>
    
    <div class="schedule-container">
        <!-- SEASON STANDINGS - Moved to top and made prominent -->
        <div class="season-standings">
            <h2>Current Season Standings</h2>
            <div class="standings-table">
                <?php
                // Calculate standings from results
                $standings = [];
                foreach ($season9Schedule as $match) {
                    if ($match['status'] === 'completed') {
                        $winner = $match['winner_name'];
                        $loser_id = ($match['winner_team_id'] == $match['team1_id']) ? $match['team2_id'] : $match['team1_id'];
                        $loser = ($match['winner_team_id'] == $match['team1_id']) ? $match['team2_name'] : $match['team1_name'];
                        
                        if (!isset($standings[$winner])) {
                            $standings[$winner] = ['wins' => 0, 'losses' => 0];
                        }
                        if (!isset($standings[$loser])) {
                            $standings[$loser] = ['wins' => 0, 'losses' => 0];
                        }
                        
                        $standings[$winner]['wins']++;
                        $standings[$loser]['losses']++;
                    }
                }
                
                // Sort by wins desc, losses asc
                uasort($standings, function($a, $b) {
                    if ($a['wins'] == $b['wins']) {
                        return $a['losses'] - $b['losses'];
                    }
                    return $b['wins'] - $a['wins'];
                });
                ?>
                
                <table class="standings">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Team</th>
                            <th>Wins</th>
                            <th>Losses</th>
                            <th>Win %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        foreach ($standings as $teamName => $record): 
                            $totalGames = $record['wins'] + $record['losses'];
                            $winPercentage = $totalGames > 0 ? round(($record['wins'] / $totalGames) * 100, 1) : 0;
                        ?>
                            <tr class="<?= $rank <= 3 ? 'top-team' : '' ?>">
                                <td class="rank"><?= $rank ?></td>
                                <td>
                                    <a href="view_team.php?name=<?= urlencode($teamName) ?>" class="team-link">
                                        <?= htmlspecialchars($teamName) ?>
                                    </a>
                                </td>
                                <td class="wins"><?= $record['wins'] ?></td>
                                <td class="losses"><?= $record['losses'] ?></td>
                                <td class="win-percentage"><?= $winPercentage ?>%</td>
                            </tr>
                        <?php 
                        $rank++;
                        endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SEASON FORMAT INFO -->
        <div class="season-info">
            <h2>Season Format</h2>
            <p>Each team match is <strong>best of 9</strong> with the following format:</p>
            <ul>
                <li><strong>Code S:</strong> Best of 2</li>
                <li><strong>Code A:</strong> Best of 2</li>
                <li><strong>Code B:</strong> Best of 2</li>
                <li><strong>2v2:</strong> Best of 2</li>
                <li><strong>Ace Match:</strong> Best of 1 (if tied 4-4)</li>
            </ul>
        </div>

        <!-- MATCH SCHEDULE -->
        <div class="match-schedule">
            <h2>Match Schedule & Results</h2>
            
            <?php foreach ($season9Schedule as $match): 
                $matchIds = getScheduleMatchIds($db, $match['schedule_id']);
            ?>
                <div class="team-match-card">
                    <div class="match-header">
                        <div class="week-info">
                            <h3>Week <?= $match['week_number'] ?></h3>
                            <p class="match-date"><?= htmlspecialchars($match['match_date']) ?></p>
                        </div>
                        <div class="match-status <?= $match['status'] ?>">
                            <?= ucfirst($match['status']) ?>
                        </div>
                    </div>

                    <div class="team-matchup">
                        <?php
                        // Determine styling based on match status
                        $team1_class = '';
                        $team2_class = '';
                        
                        if ($match['status'] === 'completed' && $match['winner_team_id']) {
                            $team1_class = ($match['winner_team_id'] == $match['team1_id']) ? 'winner' : 'loser';
                            $team2_class = ($match['winner_team_id'] == $match['team2_id']) ? 'winner' : 'loser';
                        } else {
                            $team1_class = 'scheduled';
                            $team2_class = 'scheduled';
                        }
                        
                        // Display scores or placeholders
                        $team1_score = ($match['team1_score'] !== null) ? $match['team1_score'] : '?';
                        $team2_score = ($match['team2_score'] !== null) ? $match['team2_score'] : '?';
                        ?>
                        
                        <div class="team-side <?= $team1_class ?>">
                            <h4>
                                <a href="view_team.php?name=<?= urlencode($match['team1_name']) ?>" class="team-link">
                                    <?= htmlspecialchars($match['team1_name']) ?>
                                </a>
                            </h4>
                            <div class="team-score"><?= $team1_score ?></div>
                        </div>
                        
                        <div class="vs-divider">
                            <span>VS</span>
                        </div>
                        
                        <div class="team-side <?= $team2_class ?>">
                            <h4>
                                <a href="view_team.php?name=<?= urlencode($match['team2_name']) ?>" class="team-link">
                                    <?= htmlspecialchars($match['team2_name']) ?>
                                </a>
                            </h4>
                            <div class="team-score"><?= $team2_score ?></div>
                        </div>
                    </div>

                    <?php if (!empty($match['notes'])): ?>
                        <div class="match-notes">
                            <em><?= htmlspecialchars($match['notes']) ?></em>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($matchIds) || !empty($match['team_2v2_results'])): ?>
                        <div class="individual-matches">
                            <h5>Individual Match Results:</h5>
                            <?php 
                            // Show individual matches from database
                            if (!empty($matchIds)) {
                                $matchDetails = getMatchDetails($db, $matchIds);
                                foreach ($matchDetails as $detail): 
                                    // Skip 2v2 matches since we have manual entry for those
                                    if ($detail['t_code'] === '2v2') continue;
                                ?>
                                    <div class="individual-match">
                                        <div class="match-code">
                                            <strong><?= htmlspecialchars($detail['t_code']) ?>:</strong>
                                        </div>
                                        <div class="match-result">
                                            <a href="view_player.php?name=<?= urlencode($detail['winner_name']) ?>" class="player-link winner">
                                                <?= htmlspecialchars($detail['winner_name']) ?> (<?= htmlspecialchars($detail['winner_race']) ?>)
                                            </a>
                                            <span class="score"><?= $detail['map_win'] ?>-<?= $detail['map_loss'] ?></span>
                                            <a href="view_player.php?name=<?= urlencode($detail['loser_name']) ?>" class="player-link loser">
                                                <?= htmlspecialchars($detail['loser_name']) ?> (<?= htmlspecialchars($detail['loser_race']) ?>)
                                            </a>
                                        </div>
                                        <div class="match-link">
                                            <a href="view_match.php?id=<?= $detail['fsl_match_id'] ?>" class="btn-match-details">
                                                View Details
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; 
                            }
                            
                            // Show 2v2 results if available
                            if (!empty($match['team_2v2_results'])): ?>
                                <div class="individual-match team-2v2-match">
                                    <div class="match-code">
                                        <strong>2v2:</strong>
                                    </div>
                                    <div class="match-result">
                                        <?= htmlspecialchars($match['team_2v2_results'] ?? '') ?>
                                    </div>
                                    <div class="match-link">
                                        <span class="no-details">Manual Entry</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-details">
                            <?php if ($match['status'] === 'scheduled'): ?>
                                <p><em>Match not yet played - individual results will be available after completion</em></p>
                            <?php else: ?>
                                <p><em>Individual match details not available yet</em></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
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
    
    h1 {
        text-align: center;
        color: #00d4ff;
        text-shadow: 0 0 15px #00d4ff;
        font-size: 2.4em;
        margin-bottom: 30px;
    }
    
    .schedule-container {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 25px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
    }
    
    /* PROMINENT SEASON STANDINGS STYLING */
    .season-standings {
        background: linear-gradient(135deg, rgba(0, 212, 255, 0.15), rgba(0, 212, 255, 0.05));
        border: 2px solid rgba(0, 212, 255, 0.3);
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 40px;
        box-shadow: 0 8px 25px rgba(0, 212, 255, 0.1);
    }
    
    .season-standings h2 {
        color: #00d4ff;
        text-align: center;
        font-size: 2em;
        margin-bottom: 25px;
        text-shadow: 0 0 10px #00d4ff;
    }
    
    .standings {
        width: 100%;
        border-collapse: collapse;
        font-size: 1.1em;
    }
    
    .standings th {
        background: rgba(0, 212, 255, 0.2);
        color: #ffffff;
        font-weight: 700;
        padding: 15px 12px;
        text-align: center;
        border-bottom: 2px solid rgba(0, 212, 255, 0.4);
        text-shadow: 0 0 5px #00d4ff;
    }
    
    .standings td {
        padding: 12px;
        text-align: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .standings tr.top-team {
        background: rgba(255, 215, 0, 0.1);
        border-left: 4px solid #ffd700;
    }
    
    .standings .rank {
        font-weight: bold;
        color: #00d4ff;
        font-size: 1.2em;
    }
    
    .standings .wins {
        color: #28a745;
        font-weight: bold;
    }
    
    .standings .losses {
        color: #dc3545;
        font-weight: bold;
    }
    
    .standings .win-percentage {
        color: #00d4ff;
        font-weight: bold;
    }
    
    /* Match schedule section */
    .match-schedule h2 {
        color: #00d4ff;
        text-align: center;
        font-size: 1.8em;
        margin-bottom: 25px;
        border-bottom: 2px solid rgba(0, 212, 255, 0.3);
        padding-bottom: 10px;
    }
    
    .season-info {
        background: rgba(0, 0, 0, 0.2);
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 30px;
    }
    
    .season-info h2 {
        color: #00d4ff;
        margin-bottom: 15px;
    }
    
    .season-info ul {
        margin-left: 20px;
    }
    
    .team-match-card {
        background: rgba(0, 0, 0, 0.3);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 25px;
        border: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .match-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .week-info h3 {
        color: #00d4ff;
        margin: 0;
        font-size: 1.5em;
    }
    
    .match-date {
        color: #ccc;
        margin: 5px 0 0 0;
    }
    
    .match-status {
        padding: 5px 15px;
        border-radius: 15px;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.9em;
    }
    
    .match-status.completed {
        background: #28a745;
        color: white;
    }
    
    .match-status.scheduled {
        background: #6c757d;
        color: white;
    }
    
    .team-matchup {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }
    
    .team-side {
        flex: 1;
        text-align: center;
        padding: 15px;
        border-radius: 8px;
        min-width: 200px;
    }
    
    .team-side.winner {
        background: rgba(40, 167, 69, 0.2);
        border: 2px solid #28a745;
    }
    
    .team-side.loser {
        background: rgba(220, 53, 69, 0.2);
        border: 2px solid #dc3545;
    }
    
    .team-side.scheduled {
        background: rgba(108, 117, 125, 0.2);
        border: 2px solid #6c757d;
    }
    
    .team-side h4 {
        margin: 0 0 10px 0;
        font-size: 1.2em;
    }
    
    .team-score {
        font-size: 2em;
        font-weight: bold;
        color: #00d4ff;
    }
    
    .vs-divider {
        padding: 0 20px;
        font-weight: bold;
        font-size: 1.2em;
        color: #00d4ff;
    }
    
    .match-notes {
        text-align: center;
        margin-bottom: 15px;
        color: #ccc;
    }
    
    .individual-matches {
        background: rgba(0, 0, 0, 0.2);
        border-radius: 8px;
        padding: 15px;
    }
    
    .individual-matches h5 {
        color: #00d4ff;
        margin: 0 0 15px 0;
    }
    
    .individual-match {
        display: flex;
        align-items: center;
        padding: 10px;
        margin-bottom: 10px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 5px;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .match-code {
        min-width: 60px;
        color: #00d4ff;
    }
    
    .match-result {
        flex: 1;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .score {
        font-weight: bold;
        color: #00d4ff;
        padding: 0 10px;
    }
    
    .no-details {
        text-align: center;
        padding: 20px;
        color: #888;
    }
    
    .team-2v2-match {
        background: rgba(255, 193, 7, 0.1) !important;
        border-left: 4px solid #ffc107;
    }
    
    .team-2v2-match .match-result {
        color: #ffc107;
        font-style: italic;
    }
    
    .team-link,
    .player-link {
        color: #00d4ff;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    
    .team-link:hover,
    .player-link:hover {
        color: #ffffff;
        text-shadow: 0 0 5px #00d4ff;
    }
    
    .player-link.winner {
        font-weight: bold;
    }
    
    .player-link.loser {
        opacity: 0.8;
    }
    
    .btn-match-details {
        background: #00d4ff;
        color: #0f0c29;
        padding: 5px 10px;
        border-radius: 4px;
        text-decoration: none;
        font-size: 0.9em;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    
    .btn-match-details:hover {
        background: #ffffff;
        color: #0f0c29;
        text-decoration: none;
    }
    
    @media (max-width: 768px) {
        .container {
            padding: 10px;
        }
        
        h1 {
            font-size: 1.8em;
            margin-bottom: 20px;
        }
        
        .schedule-container {
            padding: 15px;
        }
        
        .season-standings {
            padding: 15px;
            margin-bottom: 25px;
        }
        
        .season-standings h2 {
            font-size: 1.5em;
            margin-bottom: 15px;
        }
        
        /* Make standings table mobile-friendly */
        .standings-table {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .standings {
            min-width: 100%;
            font-size: 0.85em;
        }
        
        .standings th,
        .standings td {
            padding: 8px 4px;
            font-size: 0.85em;
            white-space: nowrap;
        }
        
        .standings th:first-child,
        .standings td:first-child {
            padding-left: 8px;
        }
        
        .standings th:last-child,
        .standings td:last-child {
            padding-right: 8px;
        }
        
        /* Adjust team name column for mobile */
        .standings th:nth-child(2),
        .standings td:nth-child(2) {
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Season info section */
        .season-info {
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .season-info h2 {
            font-size: 1.4em;
            margin-bottom: 10px;
        }
        
        .season-info p {
            font-size: 0.9em;
        }
        
        .season-info ul {
            margin-left: 15px;
        }
        
        .season-info li {
            font-size: 0.9em;
            margin-bottom: 5px;
        }
        
        /* Match schedule section */
        .match-schedule h2 {
            font-size: 1.4em;
        }
        
        .team-match-card {
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .match-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        
        .week-info h3 {
            font-size: 1.3em;
        }
        
        .match-date {
            font-size: 0.9em;
        }
        
        .match-status {
            padding: 4px 12px;
            font-size: 0.8em;
        }
        
        .team-matchup {
            flex-direction: column;
            gap: 10px;
        }
        
        .team-side {
            min-width: auto;
            padding: 12px;
        }
        
        .team-side h4 {
            font-size: 1.1em;
            margin-bottom: 8px;
        }
        
        .team-score {
            font-size: 1.5em;
        }
        
        .vs-divider {
            padding: 5px 0;
            font-size: 1em;
        }
        
        /* Individual matches section */
        .individual-matches {
            padding: 12px;
        }
        
        .individual-matches h5 {
            font-size: 1.1em;
            margin-bottom: 10px;
        }
        
        .individual-match {
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            padding: 8px;
        }
        
        .match-code {
            min-width: auto;
            font-size: 0.9em;
        }
        
        .match-result {
            width: 100%;
            flex-direction: column;
            align-items: flex-start;
            gap: 5px;
        }
        
        .match-result .player-link {
            font-size: 0.9em;
        }
        
        .score {
            font-size: 0.9em;
            padding: 0 5px;
        }
        
        .match-link {
            align-self: flex-end;
        }
        
        .btn-match-details {
            font-size: 0.8em;
            padding: 4px 8px;
        }
        
        .team-2v2-match .match-result {
            font-size: 0.9em;
        }
        
        .no-details {
            padding: 15px;
            font-size: 0.9em;
        }
        
        .match-notes {
            font-size: 0.9em;
            margin-bottom: 10px;
        }
    }
    
    @media (max-width: 480px) {
        .container {
            padding: 5px;
        }
        
        h1 {
            font-size: 1.5em;
            margin-bottom: 15px;
        }
        
        .schedule-container {
            padding: 10px;
        }
        
        .season-standings {
            padding: 10px;
        }
        
        .season-standings h2 {
            font-size: 1.3em;
        }
        
        .standings {
            font-size: 0.8em;
        }
        
        .standings th,
        .standings td {
            padding: 6px 3px;
        }
        
        .standings th:nth-child(2),
        .standings td:nth-child(2) {
            max-width: 100px;
        }
        
        .season-info {
            padding: 10px;
        }
        
        .season-info h2 {
            font-size: 1.2em;
        }
        
        .season-info p,
        .season-info li {
            font-size: 0.85em;
        }
        
        .match-schedule h2 {
            font-size: 1.2em;
        }
        
        .team-match-card {
            padding: 10px;
        }
        
        .week-info h3 {
            font-size: 1.1em;
        }
        
        .match-date {
            font-size: 0.8em;
        }
        
        .match-status {
            padding: 3px 8px;
            font-size: 0.75em;
        }
        
        .team-side {
            padding: 10px;
        }
        
        .team-side h4 {
            font-size: 1em;
        }
        
        .team-score {
            font-size: 1.3em;
        }
        
        .vs-divider {
            font-size: 0.9em;
        }
        
        .individual-matches {
            padding: 8px;
        }
        
        .individual-matches h5 {
            font-size: 1em;
        }
        
        .individual-match {
            padding: 6px;
        }
        
        .match-code {
            font-size: 0.8em;
        }
        
        .match-result .player-link {
            font-size: 0.8em;
        }
        
        .score {
            font-size: 0.8em;
        }
        
        .btn-match-details {
            font-size: 0.75em;
            padding: 3px 6px;
        }
        
        .no-details {
            padding: 10px;
            font-size: 0.8em;
        }
        
        .match-notes {
            font-size: 0.8em;
        }
    }
</style>

<?php include 'includes/footer.php'; ?> 