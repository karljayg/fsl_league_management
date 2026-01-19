<?php
/**
 * Team Profile Page
 * Displays comprehensive team information including roster, statistics, and match history
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include database connection
require_once 'includes/db.php';
require_once 'includes/team_logo.php';

// Include the championship JSON processor
require_once 'includes/championship_json_processor.php';

// Function to get current season
function getCurrentSeason($db) {
    try {
        $stmt = $db->query("SELECT MAX(season) as current_season FROM fsl_schedule");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['current_season'] ?? 9; // Default to 9 if no data
    } catch (PDOException $e) {
        return 9; // Default fallback
    }
}

// Get team name from URL parameter
$teamName = isset($_GET['name']) ? $_GET['name'] : '';

if (empty($teamName)) {
    die("Error: No team specified");
}

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get team information
$teamQuery = "
    SELECT 
        t.Team_ID,
        t.Team_Name,
        t.Captain_ID,
        t.Co_Captain_ID,
        t.TeamLeague_Championship_Record
    FROM Teams t
    WHERE t.Team_Name = :teamName
";

try {
    $stmt = $db->prepare($teamQuery);
    $stmt->execute(['teamName' => $teamName]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$team) {
        die("Error: Team not found");
    }
    
    // Get team captain name
    $captainName = 'Not assigned';
    if (!empty($team['Captain_ID'])) {
        $captainQuery = "SELECT Real_Name FROM Players WHERE Player_ID = :captainId";
        $stmt = $db->prepare($captainQuery);
        $stmt->execute(['captainId' => $team['Captain_ID']]);
        $captain = $stmt->fetch(PDO::FETCH_ASSOC);
        $captainName = $captain ? $captain['Real_Name'] : 'Unknown';
    }
    
    // Get co-captain name
    $coCaptainName = 'Not assigned';
    if (!empty($team['Co_Captain_ID'])) {
        $coCaptainQuery = "SELECT Real_Name FROM Players WHERE Player_ID = :coCaptainId";
        $stmt = $db->prepare($coCaptainQuery);
        $stmt->execute(['coCaptainId' => $team['Co_Captain_ID']]);
        $coCaptain = $stmt->fetch(PDO::FETCH_ASSOC);
        $coCaptainName = $coCaptain ? $coCaptain['Real_Name'] : 'Unknown';
    }
    
    // Get all players for dropdown
    $playersQuery = "SELECT Player_ID, Real_Name FROM Players WHERE Team_ID = :teamId ORDER BY Real_Name";
    $playersStmt = $db->prepare($playersQuery);
    $playersStmt->execute(['teamId' => $team['Team_ID']]);
    $allPlayers = $playersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get team roster with statistics
    $rosterQuery = "
        SELECT 
            p.Player_ID,
            p.Real_Name,
            s.Division,
            s.Race,
            s.MapsW,
            s.MapsL,
            s.SetsW,
            s.SetsL,
            p.Status
        FROM Players p
        LEFT JOIN FSL_STATISTICS s ON p.Player_ID = s.Player_ID
        WHERE p.Team_ID = :teamId AND p.Status = 'active'
        ORDER BY 
            CASE WHEN p.Player_ID = :captainId THEN 1 
                 WHEN p.Player_ID = :coCaptainId THEN 2 
                 ELSE 3 END, 
            s.Division, p.Real_Name
    ";
    
    $stmt = $db->prepare($rosterQuery);
    $stmt->execute(['teamId' => $team['Team_ID'], 'captainId' => $team['Captain_ID'], 'coCaptainId' => $team['Co_Captain_ID']]);
    $roster = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get inactive players
    $inactiveQuery = "
        SELECT 
            p.Player_ID,
            p.Real_Name,
            s.Division,
            s.Race,
            s.MapsW,
            s.MapsL,
            s.SetsW,
            s.SetsL
        FROM Players p
        LEFT JOIN FSL_STATISTICS s ON p.Player_ID = s.Player_ID
        WHERE p.Team_ID = :teamId AND p.Status = 'inactive'
        ORDER BY s.Division, p.Real_Name
    ";
    
    $stmt = $db->prepare($inactiveQuery);
    $stmt->execute(['teamId' => $team['Team_ID']]);
    $inactivePlayers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get team match history (last 10 matches)
    $matchesQuery = "
        SELECT 
            fm.fsl_match_id,
            fm.season,
            fm.season_extra_info,
            fm.map_win,
            fm.map_loss,
            p_w.Real_Name AS winner_name,
            p_w.Team_ID AS winner_team_id,
            t_w.Team_Name AS winner_team,
            fm.winner_race,
            p_l.Real_Name AS loser_name,
            p_l.Team_ID AS loser_team_id,
            t_l.Team_Name AS loser_team,
            fm.loser_race,
            fm.source,
            fm.vod
        FROM fsl_matches fm
        JOIN Players p_w ON fm.winner_player_id = p_w.Player_ID
        JOIN Players p_l ON fm.loser_player_id = p_l.Player_ID
        LEFT JOIN Teams t_w ON p_w.Team_ID = t_w.Team_ID
        LEFT JOIN Teams t_l ON p_l.Team_ID = t_l.Team_ID
        WHERE p_w.Team_ID = :teamId OR p_l.Team_ID = :teamId
        ORDER BY fm.fsl_match_id DESC
        LIMIT 10
    ";
    
    $stmt = $db->prepare($matchesQuery);
    $stmt->execute(['teamId' => $team['Team_ID']]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate team statistics
    $teamStats = [
        'totalMapsW' => 0,
        'totalMapsL' => 0,
        'totalSetsW' => 0,
        'totalSetsL' => 0,
        'players' => count($roster)
    ];
    
    foreach ($roster as $player) {
        $teamStats['totalMapsW'] += $player['MapsW'] ?? 0;
        $teamStats['totalMapsL'] += $player['MapsL'] ?? 0;
        $teamStats['totalSetsW'] += $player['SetsW'] ?? 0;
        $teamStats['totalSetsL'] += $player['SetsL'] ?? 0;
    }
    
    $teamStats['mapWinRate'] = $teamStats['totalMapsW'] + $teamStats['totalMapsL'] > 0 
        ? round(($teamStats['totalMapsW'] / ($teamStats['totalMapsW'] + $teamStats['totalMapsL'])) * 100, 1) 
        : 0;
        
    $teamStats['setWinRate'] = $teamStats['totalSetsW'] + $teamStats['totalSetsL'] > 0 
        ? round(($teamStats['totalSetsW'] / ($teamStats['totalSetsW'] + $teamStats['totalSetsL'])) * 100, 1) 
        : 0;
    
    // Get current season
    $currentSeason = getCurrentSeason($db);
    
    // Get current season record for the team
    $seasonRecordQuery = "
        SELECT 
            SUM(CASE WHEN winner_team_id = :teamId THEN 1 ELSE 0 END) as wins,
            SUM(CASE WHEN (team1_id = :teamId OR team2_id = :teamId) AND winner_team_id != :teamId AND winner_team_id IS NOT NULL THEN 1 ELSE 0 END) as losses
        FROM fsl_schedule 
        WHERE season = :currentSeason AND (team1_id = :teamId OR team2_id = :teamId) AND status = 'completed'
    ";
    
    $stmt = $db->prepare($seasonRecordQuery);
    $stmt->execute(['teamId' => $team['Team_ID'], 'currentSeason' => $currentSeason]);
    $seasonRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $seasonWins = $seasonRecord['wins'] ?? 0;
    $seasonLosses = $seasonRecord['losses'] ?? 0;
    
    // Get recent team matches (completed from fsl_schedule)
    $recentTeamMatchesQuery = "
        SELECT 
            s.schedule_id,
            s.week_number,
            s.match_date,
            s.team1_score,
            s.team2_score,
            s.winner_team_id,
            s.notes,
            COALESCE(t1.Team_Name, 'Unknown Team') as team1_name,
            COALESCE(t2.Team_Name, 'Unknown Team') as team2_name,
            COALESCE(tw.Team_Name, 'Unknown Team') as winner_name
        FROM fsl_schedule s
        LEFT JOIN Teams t1 ON s.team1_id = t1.Team_ID
        LEFT JOIN Teams t2 ON s.team2_id = t2.Team_ID
        LEFT JOIN Teams tw ON s.winner_team_id = tw.Team_ID
        WHERE s.season = :currentSeason 
        AND (s.team1_id = :teamId OR s.team2_id = :teamId)
        AND s.status = 'completed'
        ORDER BY s.week_number DESC
        LIMIT 5
    ";
    
    $stmt = $db->prepare($recentTeamMatchesQuery);
    $stmt->execute(['teamId' => $team['Team_ID'], 'currentSeason' => $currentSeason]);
    $recentTeamMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get upcoming team schedule (scheduled from fsl_schedule)
    $upcomingScheduleQuery = "
        SELECT 
            s.schedule_id,
            s.week_number,
            s.match_date,
            s.status,
            s.notes,
            COALESCE(t1.Team_Name, 'Unknown Team') as team1_name,
            COALESCE(t2.Team_Name, 'Unknown Team') as team2_name,
            CASE 
                WHEN s.team1_id = :teamId THEN COALESCE(t2.Team_Name, 'Unknown Team')
                ELSE COALESCE(t1.Team_Name, 'Unknown Team')
            END as opponent_name
        FROM fsl_schedule s
        LEFT JOIN Teams t1 ON s.team1_id = t1.Team_ID
        LEFT JOIN Teams t2 ON s.team2_id = t2.Team_ID
        WHERE s.season = :currentSeason 
        AND (s.team1_id = :teamId OR s.team2_id = :teamId)
        AND s.status IN ('scheduled', 'in_progress')
        ORDER BY s.week_number ASC
        LIMIT 5
    ";
    
    $stmt = $db->prepare($upcomingScheduleQuery);
    $stmt->execute(['teamId' => $team['Team_ID'], 'currentSeason' => $currentSeason]);
    $upcomingSchedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Handle form submission for updating captains
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Convert empty strings to NULL
        $newCaptainId = !empty($_POST['captain_id']) ? $_POST['captain_id'] : null;
        $newCoCaptainId = !empty($_POST['co_captain_id']) ? $_POST['co_captain_id'] : null;

        $updateQuery = "UPDATE Teams SET Captain_ID = :captainId, Co_Captain_ID = :coCaptainId WHERE Team_ID = :teamId";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->execute(['captainId' => $newCaptainId, 'coCaptainId' => $newCoCaptainId, 'teamId' => $team['Team_ID']]);

        // Refresh the page to show updated data
        header("Location: view_team.php?name=" . urlencode($teamName));
        exit;
    }
    
} catch (PDOException $e) {
    die("Query failed: " . $e->getMessage());
}

// Set page title
$pageTitle = "Team Profile - " . htmlspecialchars($teamName);

// Include header
include 'includes/header.php';

// Check if the user has permission using the new RBAC system
$hasPermission = false;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt
            FROM ws_user_roles ur
            JOIN ws_role_permissions rp ON ur.role_id = rp.role_id
            JOIN ws_permissions p ON rp.permission_id = p.permission_id
            WHERE ur.user_id = ? AND p.permission_name = ?
        ");
        $stmt->execute([$_SESSION['user_id'], 'edit player, team, stats']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['cnt'] > 0) {
            $hasPermission = true;
        }
    } catch (PDOException $e) {
        // Silently fail, user won't have edit permissions
        error_log("Permission check failed in view_team.php: " . $e->getMessage());
    }
}

// Add a helper function to extract domain name from URL
function getDomainFromUrl($url) {
    if (empty($url)) return '';
    
    // Parse the URL to get the host
    $parsedUrl = parse_url($url);
    
    if (isset($parsedUrl['host'])) {
        // Remove 'www.' if present
        $domain = preg_replace('/^www\./', '', $parsedUrl['host']);
        // Capitalize the first letter
        return ucfirst($domain);
    }
    
    return 'Link'; // Fallback
}

// Add helper functions for championship records
function formatTeamChampionshipRecord($jsonData, $outputMode = 2) {
    return processChampionshipJSON($jsonData, $outputMode);
}
?>

<div class="container mt-4">
    <?php $teamLogo = getTeamLogo($teamName); ?>
    <div class="team-header-container">
        <?php if ($teamLogo): ?>
        <img src="<?= htmlspecialchars($teamLogo) ?>" alt="<?= htmlspecialchars($teamName) ?>" class="team-header-logo">
        <?php endif; ?>
        <h1><?= htmlspecialchars($teamName) ?></h1>
    </div>
    
    <div class="team-profile-container">
        <!-- Team Information Card -->
        <div class="card mb-4">
            <div class="card-header bg-dark text-light">
                <h2>Team Information</h2>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-12">
                        <?php
                        // Display the form only if the user has the required permission
                        if ($hasPermission):
                            ?>
                            <form method="POST" action="">
                                <table class="table table-dark table-striped">
                                    <tr>
                                        <th>Team Captain:</th>
                                        <td>
                                            <select name="captain_id" class="form-control">
                                                <option value="">Not assigned</option>
                                                <?php foreach ($allPlayers as $player): ?>
                                                    <option value="<?= $player['Player_ID'] ?>" <?= $player['Player_ID'] == $team['Captain_ID'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($player['Real_Name'] ?? 'Unknown Player') ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Co-Captain:</th>
                                        <td>
                                            <select name="co_captain_id" class="form-control">
                                                <option value="">Not assigned</option>
                                                <?php foreach ($allPlayers as $player): ?>
                                                    <option value="<?= $player['Player_ID'] ?>" <?= $player['Player_ID'] == $team['Co_Captain_ID'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($player['Real_Name'] ?? 'Unknown Player') ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" class="text-center">
                                            <button type="submit" class="btn btn-primary">Update Captains</button>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Total Players:</th>
                                        <td><?= $teamStats['players'] ?></td>
                                    </tr>
                                    <tr>
                                        <th>Maps W-L:</th>
                                        <td><?= $teamStats['totalMapsW'] ?>-<?= $teamStats['totalMapsL'] ?> (<?= $teamStats['mapWinRate'] ?>%)</td>
                                    </tr>
                                    <tr>
                                        <th>Sets W-L:</th>
                                        <td><?= $teamStats['totalSetsW'] ?>-<?= $teamStats['totalSetsL'] ?> (<?= $teamStats['setWinRate'] ?>%)</td>
                                    </tr>
                                    <tr>
                                        <th><a href="fsl_schedule.php" style="color: inherit; text-decoration: none;">Season <?= $currentSeason ?> Record:</a></th>
                                        <td><a href="fsl_schedule.php" style="color: inherit; text-decoration: none;"><?= $seasonWins ?> - <?= $seasonLosses ?></a></td>
                                    </tr>
                                </table>
                            </form>
                            <?php
                        else:
                            ?>
                            <table class="table table-dark table-striped">
                                <tr>
                                    <th>Team Captain:</th>
                                    <td><?= htmlspecialchars($captainName) ?></td>
                                </tr>
                                <tr>
                                    <th>Co-Captain:</th>
                                    <td><?= htmlspecialchars($coCaptainName) ?></td>
                                </tr>
                                <tr>
                                    <th>Total Players:</th>
                                    <td><?= $teamStats['players'] ?></td>
                                </tr>
                                <tr>
                                    <th>Maps W-L:</th>
                                    <td><?= $teamStats['totalMapsW'] ?>-<?= $teamStats['totalMapsL'] ?> (<?= $teamStats['mapWinRate'] ?>%)</td>
                                </tr>
                                <tr>
                                    <th>Sets W-L:</th>
                                    <td><?= $teamStats['totalSetsW'] ?>-<?= $teamStats['totalSetsL'] ?> (<?= $teamStats['setWinRate'] ?>%)</td>
                                </tr>
                                <tr>
                                    <th><a href="fsl_schedule.php" style="color: inherit; text-decoration: none;">Season <?= $currentSeason ?> Record:</a></th>
                                    <td><a href="fsl_schedule.php" style="color: inherit; text-decoration: none;"><?= $seasonWins ?> - <?= $seasonLosses ?></a></td>
                                </tr>
                            </table>
                            <?php
                        endif;
                        ?>
                        
                        <!-- Championship Records -->
                        <div class="championship-container mt-4">
                            <?php if (!empty($team['TeamLeague_Championship_Record']) && $team['TeamLeague_Championship_Record'] !== 'None' && $team['TeamLeague_Championship_Record'] !== 'null'): ?>
                            <div class="championship-record">
                                <label>Team Championship Record:</label>
                                <span><?= formatTeamChampionshipRecord($team['TeamLeague_Championship_Record']) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Team Matches Card -->
        <div class="card mb-4">
            <div class="card-header bg-dark text-light">
                <h2>Recent Team Matches</h2>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-dark table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Week</th>
                                <th>Date</th>
                                <th>Opponent</th>
                                <th>Score</th>
                                <th>Result</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentTeamMatches)): ?>
                            <tr>
                                <td colspan="6" class="text-center">No recent team matches found</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($recentTeamMatches as $teamMatch): ?>
                                <?php 
                                    $isWin = $teamMatch['winner_team_id'] == $team['Team_ID'];
                                    $opponent = $teamMatch['team1_name'] == $teamName ? $teamMatch['team2_name'] : $teamMatch['team1_name'];
                                    $opponent = $opponent ?: 'Unknown Team';
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($teamMatch['week_number'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($teamMatch['match_date'] ?? 'TBD') ?></td>
                                    <td><a href="view_team.php?name=<?= urlencode($opponent) ?>" class="player-link"><?= htmlspecialchars($opponent) ?></a></td>
                                    <td>
                                        <span class="<?= $isWin ? 'match-win' : 'match-loss' ?>">
                                            <?= htmlspecialchars($teamMatch['team1_score'] ?? 0) ?>-<?= htmlspecialchars($teamMatch['team2_score'] ?? 0) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="<?= $isWin ? 'match-win' : 'match-loss' ?>">
                                            <?= $isWin ? 'WIN' : 'LOSS' ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($teamMatch['notes'] ?? '') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Upcoming Schedule Card -->
        <div class="card mb-4">
            <div class="card-header bg-dark text-light">
                <h2>Upcoming Schedule</h2>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-dark table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Week</th>
                                <th>Date</th>
                                <th>Opponent</th>
                                <th>Status</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($upcomingSchedule)): ?>
                            <tr>
                                <td colspan="5" class="text-center">No upcoming matches scheduled</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($upcomingSchedule as $upcoming): ?>
                                <tr>
                                    <td><?= htmlspecialchars($upcoming['week_number'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($upcoming['match_date'] ?? 'TBD') ?></td>
                                    <td><a href="view_team.php?name=<?= urlencode($upcoming['opponent_name'] ?? 'Unknown Team') ?>" class="player-link"><?= htmlspecialchars($upcoming['opponent_name'] ?? 'Unknown Team') ?></a></td>
                                    <td>
                                        <span class="status-badge status-<?= htmlspecialchars($upcoming['status'] ?? 'unknown') ?>">
                                            <?= htmlspecialchars(ucfirst($upcoming['status'] ?? 'unknown')) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($upcoming['notes'] ?? '') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Team Roster Card -->
        <div class="card mb-4">
            <div class="card-header bg-dark text-light">
                <h2>Team Roster</h2>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-dark table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Player</th>
                                <th>Division</th>
                                <th>Race</th>
                                <th>Maps W-L</th>
                                <th>Sets W-L</th>
                                <th>Win Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($roster)): ?>
                            <tr>
                                <td colspan="6" class="text-center">No players found</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($roster as $player): ?>
                                <?php 
                                    $mapWinRate = ($player['MapsW'] ?? 0) + ($player['MapsL'] ?? 0) > 0 
                                        ? round((($player['MapsW'] ?? 0) / (($player['MapsW'] ?? 0) + ($player['MapsL'] ?? 0))) * 100, 1) 
                                        : 0;
                                ?>
                                <tr>
                                    <td><a href="view_player.php?name=<?= urlencode($player['Real_Name'] ?? 'Unknown Player') ?>" class="player-link" title="<?= $player['Player_ID'] == $team['Captain_ID'] ? 'Captain' : ($player['Player_ID'] == $team['Co_Captain_ID'] ? 'Co-captain' : '') ?>">
                                        <?= htmlspecialchars($player['Real_Name'] ?? 'Unknown Player') ?>
                                    </a></td>
                                    <td>
                                        <?php if (!empty($player['Division'])): ?>
                                        <span class="division-badge division-<?= htmlspecialchars($player['Division']) ?>">
                                            <?= htmlspecialchars($player['Division']) ?>
                                        </span>
                                        <?php else: ?>
                                        N/A
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($player['Race'] ?? 'N/A') ?></td>
                                    <td><?= ($player['MapsW'] ?? 0) ?>-<?= ($player['MapsL'] ?? 0) ?></td>
                                    <td><?= ($player['SetsW'] ?? 0) ?>-<?= ($player['SetsL'] ?? 0) ?></td>
                                    <td><?= $mapWinRate ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Inactive Players Card -->
        <div class="card mb-4">
            <div class="card-header bg-dark text-light">
                <h2>Inactive Players</h2>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-dark table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Player</th>
                                <th>Division</th>
                                <th>Race</th>
                                <th>Maps W-L</th>
                                <th>Sets W-L</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($inactivePlayers)): ?>
                            <tr>
                                <td colspan="5" class="text-center">No inactive players found</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($inactivePlayers as $player): ?>
                                <tr>
                                    <td><a href="view_player.php?name=<?= urlencode($player['Real_Name'] ?? 'Unknown Player') ?>" class="player-link">
                                        <?= htmlspecialchars($player['Real_Name'] ?? 'Unknown Player') ?>
                                    </a></td>
                                    <td>
                                        <?php if (!empty($player['Division'])): ?>
                                        <span class="division-badge division-<?= htmlspecialchars($player['Division']) ?>">
                                            <?= htmlspecialchars($player['Division']) ?>
                                        </span>
                                        <?php else: ?>
                                        N/A
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($player['Race'] ?? 'N/A') ?></td>
                                    <td><?= ($player['MapsW'] ?? 0) ?>-<?= ($player['MapsL'] ?? 0) ?></td>
                                    <td><?= ($player['SetsW'] ?? 0) ?>-<?= ($player['SetsL'] ?? 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Recent Individual Matches Card -->
        <div class="card mb-4">
            <div class="card-header bg-dark text-light">
                <h2>Recent Individual Matches</h2>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-dark table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Match ID</th>
                                <th>Season</th>
                                <th>Winner</th>
                                <th>Loser</th>
                                <th>Score</th>
                                <th>Links</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($matches)): ?>
                            <tr>
                                <td colspan="6" class="text-center">No matches found</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($matches as $match): ?>
                                <tr>
                                    <td><?= htmlspecialchars($match['fsl_match_id'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($match['season'] ?? 'N/A') ?> <?= htmlspecialchars($match['season_extra_info'] ?? '') ?></td>
                                    <td>
                                        <a href="view_player.php?name=<?= urlencode($match['winner_name'] ?? 'Unknown Player') ?>" class="player-link">
                                            <?= htmlspecialchars($match['winner_name'] ?? 'Unknown Player') ?>
                                        </a>
                                        <small>(<?= htmlspecialchars($match['winner_race'] ?? 'N/A') ?>)</small>
                                    </td>
                                    <td>
                                        <a href="view_player.php?name=<?= urlencode($match['loser_name'] ?? 'Unknown Player') ?>" class="player-link">
                                            <?= htmlspecialchars($match['loser_name'] ?? 'Unknown Player') ?>
                                        </a>
                                        <small>(<?= htmlspecialchars($match['loser_race'] ?? 'N/A') ?>)</small>
                                    </td>
                                    <td>
                                        <span class="<?= $match['winner_team_id'] == $team['Team_ID'] ? 'match-win' : 'match-loss' ?>">
                                            <?= htmlspecialchars($match['map_win'] ?? 0) ?>-<?= htmlspecialchars($match['map_loss'] ?? 0) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($match['source'])): ?>
                                        <a href="<?= htmlspecialchars($match['source']) ?>" target="_blank" class="btn btn-sm btn-primary">
                                            <?= htmlspecialchars(getDomainFromUrl($match['source'])) ?>
                                        </a>
                                        <?php endif; ?>
                                        <?php if (!empty($match['vod'])): ?>
                                        <a href="<?= htmlspecialchars($match['vod']) ?>" target="_blank" class="btn btn-sm btn-danger">
                                            <?= htmlspecialchars(getDomainFromUrl($match['vod'])) ?>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.team-header-container {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 20px;
    margin-bottom: 20px;
}

.team-header-logo {
    width: 120px;
    height: 120px;
    border-radius: 12px;
    object-fit: cover;
    border: 3px solid rgba(0, 212, 255, 0.5);
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
}

.team-header-container h1 {
    margin: 0;
    color: #00d4ff;
    text-shadow: 0 0 15px #00d4ff;
}

.team-profile-container {
    background: rgba(0, 0, 0, 0.2);
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 30px;
}

.card {
    background: rgba(30, 30, 40, 0.7);
    border: none;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
}

.card-header {
    background: rgba(0, 0, 0, 0.4) !important;
    border-bottom: 2px solid #00d4ff;
}

.card-header h2 {
    font-size: 1.5rem;
    margin: 0;
    color: #00d4ff;
}

.player-link {
    color: #00d4ff;
    text-decoration: none;
    transition: all 0.3s ease;
}

.player-link:hover {
    color: #ffffff;
    text-shadow: 0 0 5px #00d4ff;
}

.division-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.85em;
    font-weight: bold;
}

.division-S {
    background-color: #ff6f61;
    color: #fff;
}

.division-A {
    background-color: #ffcc00;
    color: #000;
}

.division-B {
    background-color: #00d4ff;
    color: #000;
}

/* Match win/loss indicator */
.match-win {
    color: #28a745;
    font-weight: bold;
}

.match-loss {
    color: #dc3545;
    font-weight: bold;
}

/* Status badges for upcoming matches */
.status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.85em;
    font-weight: bold;
}

.status-scheduled {
    background-color: #007bff;
    color: #fff;
}

.status-in_progress {
    background-color: #ffc107;
    color: #000;
}

.status-completed {
    background-color: #28a745;
    color: #fff;
}

.status-cancelled {
    background-color: #dc3545;
    color: #fff;
}

/* Table styling - consistent across all tables */
.table-dark {
    background-color: rgba(0, 0, 0, 0.2);
    color: #e0e0e0;
}

.table-dark.table-striped tbody tr:nth-of-type(odd) {
    background-color: rgba(255, 255, 255, 0.05);
}

.table-dark.table-hover tbody tr:hover {
    background-color: rgba(255, 255, 255, 0.1);
}

/* Match links styling */
.match-links {
    white-space: nowrap;
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
    border-radius: 0.2rem;
    margin-right: 5px;
}

.btn-primary {
    background-color: #007bff;
    border-color: #007bff;
    color: white;
    text-decoration: none;
}

.btn-danger {
    background-color: #dc3545;
    border-color: #dc3545;
    color: white;
    text-decoration: none;
}

.btn-primary:hover, .btn-danger:hover {
    filter: brightness(1.2);
    transform: translateY(-1px);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
}

@media (max-width: 768px) {
    .team-header-container {
        flex-direction: column;
        gap: 15px;
    }
    
    .team-header-logo {
        width: 100px;
        height: 100px;
    }

    .team-profile-container {
        padding: 10px;
    }
    
    .card-body {
        padding: 10px;
    }
    
    .table th, .table td {
        padding: 0.5rem;
    }
}

/* Championship Records Styling */
.championship-container {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    margin-top: 20px;
}

.championship-record {
    flex: 1;
    min-width: 300px;
    max-width: calc(50% - 0.5rem);
    padding: 15px;
    background: rgba(0, 0, 0, 0.2);
    border-radius: 5px;
    margin-bottom: 10px;
}

.championship-record label {
    display: block;
    color: #00d4ff;
    font-weight: 600;
    margin-bottom: 5px;
}

.championship-record span {
    display: block;
    white-space: pre-line;
    color: #e0e0e0;
}

@media (max-width: 768px) {
    .championship-record {
        max-width: 100%;
    }
}
</style>

<?php include 'includes/footer.php'; ?>