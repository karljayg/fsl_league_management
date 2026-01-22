<?php
/**
 * Player Profile View Page
 * Displays player information and recent matches
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
require_once 'includes/team_logo.php';

// No permission required - this page is public

// Get spider chart configuration
$chart_min = $config['spider_chart']['chart_min'] ?? 2;
$chart_max = $config['spider_chart']['chart_max'] ?? 10;

// Include the new JSON processing file
require_once 'includes/championship_json_processor.php';

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get player name from URL parameter
$playerName = isset($_GET['name']) ? trim($_GET['name']) : '';

if (empty($playerName)) {
    die("Player name is required");
}

// Check if the name is an alias and redirect to the canonical Real_Name
$aliasCheckQuery = "SELECT p.Real_Name 
    FROM Players p 
    JOIN Player_Aliases pa ON p.Player_ID = pa.Player_ID 
    WHERE pa.Alias_Name = :aliasName AND p.Real_Name != :aliasName";
try {
    $stmt = $db->prepare($aliasCheckQuery);
    $stmt->execute(['aliasName' => $playerName]);
    $realNameResult = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($realNameResult) {
        // Redirect to the canonical Real_Name URL
        header("Location: view_player.php?name=" . urlencode($realNameResult['Real_Name']));
        exit;
    }
} catch (PDOException $e) {
    // Continue with original name if alias check fails
}

// Sanitize for display after alias check
$playerName = htmlspecialchars($playerName);

// Get player information
$playerQuery = "SELECT 
    p.Player_ID,
    p.Real_Name,
    u.username,
    u.email,
    fs.Division,
    fs.Race,
    fs.MapsW,
    fs.MapsL,
    fs.SetsW,
    fs.SetsL,
    pa.Alias_Name,
    t.Team_ID,
    t.Team_Name,
    p.Championship_Record,
    p.TeamLeague_Championship_Record,
    p.Teams_History,
    p.Status,
    CASE 
        WHEN t.Captain_ID = p.Player_ID THEN 'Captain'
        WHEN t.Co_Captain_ID = p.Player_ID THEN 'Co-Captain'
        ELSE NULL
    END AS Team_Role
FROM
    Players p
        LEFT JOIN
    users u ON p.User_ID = u.id
        LEFT JOIN
    FSL_STATISTICS fs ON p.Player_ID = fs.Player_ID
        LEFT JOIN
    Player_Aliases pa ON fs.Alias_ID = pa.Alias_ID
        LEFT JOIN
    Teams t ON p.Team_ID = t.Team_ID
WHERE
    p.Real_Name = :playerName";

try {
    $stmt = $db->prepare($playerQuery);
    $stmt->execute(['playerName' => $playerName]);
    $playerInfo = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Query failed: " . $e->getMessage());
}

if (empty($playerInfo)) {
    die("Player not found");
}

// Get ALL aliases for this player (directly from Player_Aliases table)
$playerId = $playerInfo[0]['Player_ID'];
$aliasQuery = "SELECT Alias_Name FROM Player_Aliases WHERE Player_ID = :playerId AND Alias_Name != :realName ORDER BY Alias_Name";
try {
    $stmt = $db->prepare($aliasQuery);
    $stmt->execute(['playerId' => $playerId, 'realName' => $playerInfo[0]['Real_Name']]);
    $allAliases = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $allAliases = [];
}

// Get recent matches
$matchesQuery = "SELECT 
    fm.*,
    p_w.Real_Name AS winner_name,
    pa_w.Alias_Name AS winner_alias,
    p_l.Real_Name AS loser_name,
    pa_l.Alias_Name AS loser_alias
FROM fsl_matches fm
JOIN Players p_w ON fm.winner_player_id = p_w.Player_ID
JOIN Players p_l ON fm.loser_player_id = p_l.Player_ID
LEFT JOIN Player_Aliases pa_w ON p_w.Player_ID = pa_w.Player_ID
LEFT JOIN Player_Aliases pa_l ON p_l.Player_ID = pa_l.Player_ID
WHERE winner_player_id = (SELECT Player_ID FROM Players WHERE Real_Name = :playerName)
   OR loser_player_id = (SELECT Player_ID FROM Players WHERE Real_Name = :playerName)
ORDER BY fsl_match_id DESC
LIMIT 5";

try {
    $stmt = $db->prepare($matchesQuery);
    $stmt->execute(['playerName' => $playerName]);
    $recentMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Matches query failed: " . $e->getMessage());
}

// Get player spider chart data with division support
$playerId = $playerInfo[0]['Player_ID'];
$spiderChartData = null;
$voteStats = null;
$availableDivisions = [];
$selectedDivision = null;

try {
    // Get all available divisions for this player with reviewed match counts
    $divisionsQuery = "
        SELECT 
            fm.t_code as division,
            COUNT(DISTINCT pav.fsl_match_id) as match_count,
            COUNT(DISTINCT pav.id) as vote_count
        FROM Player_Attribute_Votes pav
        JOIN fsl_matches fm ON pav.fsl_match_id = fm.fsl_match_id
        WHERE (pav.player1_id = ? OR pav.player2_id = ?)
        GROUP BY fm.t_code
        ORDER BY match_count DESC, vote_count DESC
    ";
    $stmt = $db->prepare($divisionsQuery);
    $stmt->execute([$playerId, $playerId]);
    $availableDivisions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Determine selected division (URL parameter or most active)
    $requestedDivision = $_GET['division'] ?? null;
    if ($requestedDivision && in_array($requestedDivision, array_column($availableDivisions, 'division'))) {
        $selectedDivision = $requestedDivision;
    } elseif (!empty($availableDivisions)) {
        $selectedDivision = $availableDivisions[0]['division']; // Most active division
    }
    
    if ($selectedDivision) {
        // Get player attributes for selected division
        $attributesQuery = "SELECT * FROM Player_Attributes WHERE player_id = ? AND division = ?";
        $stmt = $db->prepare($attributesQuery);
        $stmt->execute([$playerId, $selectedDivision]);
        $spiderChartData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($spiderChartData) {
            // Get voting statistics for selected division
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
            $stmt->execute([$playerId, $playerId, $selectedDivision]);
            $voteStats = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $e) {
    // Silently fail - spider chart data is optional
    error_log("Spider chart query failed: " . $e->getMessage());
}

// Calculate total statistics
$totalMapsW = 0;
$totalMapsL = 0;
$totalSetsW = 0;
$totalSetsL = 0;

foreach ($playerInfo as $stat) {
    $totalMapsW += $stat['MapsW'] ?? 0;
    $totalMapsL += $stat['MapsL'] ?? 0;
    $totalSetsW += $stat['SetsW'] ?? 0;
    $totalSetsL += $stat['SetsL'] ?? 0;
}

$mapWinRate = $totalMapsW + $totalMapsL > 0 ? round(($totalMapsW / ($totalMapsW + $totalMapsL)) * 100, 1) : 0;
$setWinRate = $totalSetsW + $totalSetsL > 0 ? round(($totalSetsW / ($totalSetsW + $totalSetsL)) * 100, 1) : 0;

// Set page title
$pageTitle = "Player Profile - " . htmlspecialchars($playerName);

// Include header
include 'includes/header.php';

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
function formatChampionshipRecord($jsonData, $outputMode = 2) {
    return processChampionshipJSON($jsonData, $outputMode);
}

function formatTeamChampionshipRecord($jsonData, $outputMode = 2) {
    return processChampionshipJSON($jsonData, $outputMode);
}

// Add helper function for team history (shows only PREVIOUS teams, not current)
function formatTeamsHistory($jsonData, $currentTeamId = null) {
    global $db;
    
    if (empty($jsonData) || $jsonData === 'null' || $jsonData === 'None') {
        return 'No previous teams';
    }
    
    try {
        // Try to decode the JSON
        $data = json_decode($jsonData, true);
        
        // If it's valid JSON, format it nicely
        if (json_last_error() === JSON_ERROR_NONE && !empty($data)) {
            
            $output = '';
            $hasPreviousTeams = false;
            
            // Handle three different JSON formats:
            // Format 1: {"8": "Team Name", "7": "Previous Team"}
            // Format 2: [{"season":8,"team_id":"1"}]
            // Format 3: {"history": [{"season": 8, "team_id": "1"}]}
            
            // Check for Format 3 first
            if (isset($data['history']) && is_array($data['history'])) {
                // Format 3: Object with "history" key containing array
                $historyData = $data['history'];
                
                // Sort by season ascending (oldest first)
                usort($historyData, function($a, $b) {
                    return ($a['season'] ?? 0) - ($b['season'] ?? 0);
                });
                
                foreach ($historyData as $record) {
                    $season = $record['season'];
                    $teamId = $record['team_id'];
                    
                    // Skip if this is the current team
                    if ($currentTeamId && $teamId == $currentTeamId) {
                        continue;
                    }
                    
                    $hasPreviousTeams = true;
                    
                    // Look up team name
                    $teamQuery = "SELECT Team_Name FROM Teams WHERE Team_ID = :teamId";
                    $stmt = $db->prepare($teamQuery);
                    $stmt->execute(['teamId' => $teamId]);
                    $teamResult = $stmt->fetch(PDO::FETCH_ASSOC);
                    $teamName = $teamResult ? $teamResult['Team_Name'] : 'Unknown Team';
                    
                    $output .= "S" . $season . ": ";
                    $output .= "<a href='view_team.php?name=" . urlencode($teamName) . "' class='team-link'>" . htmlspecialchars($teamName) . "</a>";
                    $output .= "\n";
                }
            } elseif (is_array($data) && isset($data[0]['season'])) {
                // Format 2: Array of objects with season and team_id
                // Sort by season ascending (oldest first)
                usort($data, function($a, $b) {
                    return ($a['season'] ?? 0) - ($b['season'] ?? 0);
                });
                
                foreach ($data as $record) {
                    $season = $record['season'];
                    $teamId = $record['team_id'];
                    
                    // Skip if this is the current team
                    if ($currentTeamId && $teamId == $currentTeamId) {
                        continue;
                    }
                    
                    $hasPreviousTeams = true;
                    
                    // Look up team name
                    $teamQuery = "SELECT Team_Name FROM Teams WHERE Team_ID = :teamId";
                    $stmt = $db->prepare($teamQuery);
                    $stmt->execute(['teamId' => $teamId]);
                    $teamResult = $stmt->fetch(PDO::FETCH_ASSOC);
                    $teamName = $teamResult ? $teamResult['Team_Name'] : 'Unknown Team';
                    
                    $output .= "S" . $season . ": ";
                    $output .= "<a href='view_team.php?name=" . urlencode($teamName) . "' class='team-link'>" . htmlspecialchars($teamName) . "</a>";
                    $output .= "\n";
                }
            } else {
                // Format 1: Object with season as key and team name as value
                // Sort by season number ascending (oldest first)
                ksort($data);
                
                foreach ($data as $season => $team) {
                    if (is_array($team)) {
                        if (isset($team['name'])) {
                            $teamName = $team['name'];
                        } else {
                            $teamName = json_encode($team);
                        }
                    } else {
                        $teamName = $team;
                    }
                    
                    $hasPreviousTeams = true;
                    $output .= "S" . $season . ": ";
                    $output .= "<a href='view_team.php?name=" . urlencode($teamName) . "' class='team-link'>" . htmlspecialchars($teamName) . "</a>";
                    $output .= "\n";
                }
            }
            
            // Return "No previous teams" if all entries were current team
            if (!$hasPreviousTeams || empty(trim($output))) {
                return 'No previous teams';
            }
            
            return nl2br(trim($output));
        }
        
        // If it's not valid JSON, clean up the raw string
        $cleaned = $jsonData;
        $cleaned = str_replace(['{', '}', '"', '[', ']'], '', $cleaned);
        $cleaned = preg_replace('/,\s*/', "\n", $cleaned);
        $cleaned = preg_replace('/:/', ': ', $cleaned);
        
        return nl2br(trim($cleaned));
    } catch (Exception $e) {
        // If any error occurs, clean up the raw string
        $cleaned = $jsonData;
        $cleaned = str_replace(['{', '}', '"', '[', ']'], '', $cleaned);
        $cleaned = preg_replace('/,\s*/', "\n", $cleaned);
        $cleaned = preg_replace('/:/', ': ', $cleaned);
        
        return nl2br(trim($cleaned));
    }
}
?>

<div class="container mt-4">
    <h1><center>
        <?= htmlspecialchars($playerName) ?>
        <?php if (!empty($playerInfo[0]['Team_Role'])): ?>
            <span class="team-role-badge <?= strtolower($playerInfo[0]['Team_Role']) ?>">
                <?= htmlspecialchars($playerInfo[0]['Team_Role']) ?>
            </span>
        <?php endif; ?>
    </center></h1>
    <div style="width: 100%; height: 200px; position: relative; background: rgb(0, 0, 0); border-radius: 10px; overflow: hidden; margin-bottom: 30px; box-shadow: 0 5px 15px rgba(0,0,0,0.3);">
        <?php 
        $introPlayerName = $playerName;
        include 'view_player_intro.php';
        ?>
    </div>
    
    <div class="profile-container">
        <!-- Player Information -->
        <div class="player-info">
            <h2>Player Information</h2>
            <div class="info-grid">
                <div class="info-item team-info-item">
                    <label>Current Team:</label>
                    <span class="team-display">
                        <?php if (!empty($playerInfo[0]['Team_Name'])): ?>
                            <?php $playerTeamLogo = getTeamLogo($playerInfo[0]['Team_Name']); ?>
                            <?php if ($playerTeamLogo): ?>
                            <a href="view_team.php?name=<?= urlencode($playerInfo[0]['Team_Name']) ?>">
                                <img src="<?= htmlspecialchars($playerTeamLogo) ?>" alt="<?= htmlspecialchars($playerInfo[0]['Team_Name']) ?>" class="player-team-logo">
                            </a>
                            <?php endif; ?>
                            <a href="view_team.php?name=<?= urlencode($playerInfo[0]['Team_Name']) ?>" class="team-link">
                                <?= htmlspecialchars($playerInfo[0]['Team_Name']) ?>
                            </a>
                            <?php if (!empty($playerInfo[0]['Team_Role'])): ?>
                                <span class="team-role-badge <?= strtolower($playerInfo[0]['Team_Role']) ?>">
                                    <?= htmlspecialchars($playerInfo[0]['Team_Role']) ?>
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            None
                        <?php endif; ?>
                    </span>
                </div>
                <div class="info-item">
                    <label>Division:</label>
                    <span><?= !empty($playerInfo[0]['Division']) ? 'Code ' . htmlspecialchars($playerInfo[0]['Division']) : 'N/A' ?></span>
                </div>
                <div class="info-item">
                    <label>Race:</label>
                    <span><?= htmlspecialchars($playerInfo[0]['Race'] ?? 'N/A') ?></span>
                </div>
                <div class="info-item">
                    <label>Total Maps:</label>
                    <span><?= $totalMapsW ?>-<?= $totalMapsL ?> (<?= $mapWinRate ?>%)</span>
                </div>
                <div class="info-item">
                    <label>Total Sets:</label>
                    <span><?= $totalSetsW ?>-<?= $totalSetsL ?> (<?= $setWinRate ?>%)</span>
                </div>
                <div class="info-item">
                    <label>Status:</label>
                    <span><?= htmlspecialchars($playerInfo[0]['Status']) ?></span>
                </div>
                <div class="championship-container">
                    <?php if (!empty($playerInfo[0]['Championship_Record']) && $playerInfo[0]['Championship_Record'] !== 'None' && $playerInfo[0]['Championship_Record'] !== 'null'): ?>
                    <div class="info-item championship-record">
                        <label>Championship Record:</label>
                        <span><?= formatChampionshipRecord($playerInfo[0]['Championship_Record']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($playerInfo[0]['TeamLeague_Championship_Record']) && $playerInfo[0]['TeamLeague_Championship_Record'] !== 'None' && $playerInfo[0]['TeamLeague_Championship_Record'] !== 'null'): ?>
                    <div class="info-item championship-record">
                        <label>Team Championship Record:</label>
                        <span><?= formatTeamChampionshipRecord($playerInfo[0]['TeamLeague_Championship_Record']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($allAliases)): ?>
                <div class="info-item">
                    <label>Aliases:</label>
                    <span><?= htmlspecialchars(implode(', ', $allAliases)) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($playerInfo[0]['Teams_History']) && $playerInfo[0]['Teams_History'] !== 'None' && $playerInfo[0]['Teams_History'] !== 'null'): ?>
                <?php $teamHistoryOutput = formatTeamsHistory($playerInfo[0]['Teams_History'], $playerInfo[0]['Team_ID']); ?>
                <?php if ($teamHistoryOutput !== 'No previous teams'): ?>
                <div class="info-item team-history">
                    <label>Previous Teams:</label>
                    <span><?= $teamHistoryOutput ?></span>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Spider Chart Section -->
        <?php if ($spiderChartData): ?>
        <div class="spider-chart-section">
            <div class="spider-chart-header">
                <div class="header-left">
                    <h2>Player Attributes Analysis</h2>
                    <a href="voting_guide.php" target="_blank" class="whats-this-link">What's this?</a>
                </div>
                <?php if (count($availableDivisions) > 1): ?>
                <div class="division-selector">
                    <label for="divisionSelect">Division:</label>
                    <select id="divisionSelect" onchange="changeDivision(this.value)">
                        <?php foreach ($availableDivisions as $divData): ?>
                            <option value="<?= htmlspecialchars($divData['division']) ?>" 
                                    <?= $selectedDivision === $divData['division'] ? 'selected' : '' ?>>
                                Code <?= htmlspecialchars($divData['division']) ?> 
                                (<?= $divData['match_count'] ?> reviewed)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <div class="spider-chart-container">
                <div class="chart-wrapper">
                    <canvas id="spiderChart" width="400" height="400"></canvas>
                </div>
                <div class="chart-stats">
                    <div class="stats-grid">
                        <div class="stat-item">
                            <label>Division:</label>
                            <span class="stat-value">Code <?= htmlspecialchars($selectedDivision) ?></span>
                        </div>
                        <div class="stat-item">
                            <label>Total Votes:</label>
                            <span class="stat-value"><?= $voteStats['total_votes'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <label>Reviewers:</label>
                            <span class="stat-value"><?= $voteStats['unique_reviewers'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <label>Matches Analyzed:</label>
                            <span class="stat-value"><?= $voteStats['matches_voted_on'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <label>Last Updated:</label>
                            <span class="stat-value"><?= date('M j, Y', strtotime($spiderChartData['last_updated'])) ?></span>
                        </div>
                    </div>
                    <?php if (!empty($voteStats['reviewer_names'])): ?>
                    <div class="reviewers-list">
                        <label>Reviewers:</label>
                        <span class="reviewer-names"><?= htmlspecialchars($voteStats['reviewer_names']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="attribute-scores">
                <h3>Attribute Scores</h3>
                <div class="scores-grid">
                    <div class="score-item">
                        <span class="attribute-name">Micro</span>
                        <span class="score-value"><?= number_format($spiderChartData['micro'], 1) ?>/10</span>
                    </div>
                    <div class="score-item">
                        <span class="attribute-name">Macro</span>
                        <span class="score-value"><?= number_format($spiderChartData['macro'], 1) ?>/10</span>
                    </div>
                    <div class="score-item">
                        <span class="attribute-name">Clutch</span>
                        <span class="score-value"><?= number_format($spiderChartData['clutch'], 1) ?>/10</span>
                    </div>
                    <div class="score-item">
                        <span class="attribute-name">Creativity</span>
                        <span class="score-value"><?= number_format($spiderChartData['creativity'], 1) ?>/10</span>
                    </div>
                    <div class="score-item">
                        <span class="attribute-name">Aggression</span>
                        <span class="score-value"><?= number_format($spiderChartData['aggression'], 1) ?>/10</span>
                    </div>
                    <div class="score-item">
                        <span class="attribute-name">Strategy</span>
                        <span class="score-value"><?= number_format($spiderChartData['strategy'], 1) ?>/10</span>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
            <?php if (!empty($availableDivisions)): ?>
                <!-- Show message for divisions without current data -->
                <div class="spider-chart-section">
                    <div class="spider-chart-header">
                        <div class="header-left">
                            <h2>Player Attributes Analysis</h2>
                            <a href="voting_guide.php" target="_blank" class="whats-this-link">What's this?</a>
                        </div>
                        <div class="division-selector">
                            <label for="divisionSelect">Division:</label>
                            <select id="divisionSelect" onchange="changeDivision(this.value)">
                                <?php foreach ($availableDivisions as $divData): ?>
                                    <option value="<?= htmlspecialchars($divData['division']) ?>" 
                                            <?= $selectedDivision === $divData['division'] ? 'selected' : '' ?>>
                                        Code <?= htmlspecialchars($divData['division']) ?> 
                                        (<?= $divData['match_count'] ?> reviewed)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="no-spider-data">
                        <h3>No Analysis Data Available</h3>
                        <p><?= htmlspecialchars($playerName) ?> has not been analyzed in Division <?= htmlspecialchars($selectedDivision) ?> yet, or there are no completed votes for this division.</p>
                        <?php if (count($availableDivisions) > 1): ?>
                            <p>Try selecting a different division above to view available analysis data.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Recent Matches -->
        <div class="recent-matches">
            <h2>Recent Matches</h2>
            <div class="matches-grid">
                <?php foreach ($recentMatches as $match): ?>
                    <div class="match-card">
                        <div class="match-header">
                            <span class="season">Season <?= htmlspecialchars($match['season']) ?></span>
                            <a href="view_match.php?id=<?= htmlspecialchars($match['fsl_match_id']) ?>" class="match-id-link">
                                #<?= htmlspecialchars($match['fsl_match_id']) ?>
                            </a>
                        </div>
                        <div class="match-content">
                            <?php
                            // Determine if current player is winner or loser
                            $isWinner = $match['winner_name'] === $playerName;
                            
                            // Set current player's info
                            $playerMatchInfo = [
                                'name' => $playerName,
                                'race' => $isWinner ? $match['winner_race'] : $match['loser_race'],
                                'score' => $isWinner ? $match['map_win'] : $match['map_loss']
                            ];
                            
                            // Set opponent's info
                            $opponentInfo = [
                                'name' => $isWinner ? $match['loser_name'] : $match['winner_name'],
                                'race' => $isWinner ? $match['loser_race'] : $match['winner_race'],
                                'score' => $isWinner ? $match['map_loss'] : $match['map_win']
                            ];
                            ?>
                            <div class="player <?= $isWinner ? 'winner' : 'loser' ?> highlight">
                                <a href="view_player.php?name=<?= urlencode($playerMatchInfo['name']) ?>" class="player-link">
                                    <span class="name"><?= htmlspecialchars($playerMatchInfo['name']) ?></span>
                                </a>
                                <span class="race"><?= htmlspecialchars($playerMatchInfo['race']) ?></span>
                            </div>
                            <div class="score">
                                <?= $playerMatchInfo['score'] ?>-<?= $opponentInfo['score'] ?>
                            </div>
                            <div class="player <?= $isWinner ? 'loser' : 'winner' ?>">
                                <a href="view_player.php?name=<?= urlencode($opponentInfo['name']) ?>" class="player-link">
                                    <span class="name"><?= htmlspecialchars($opponentInfo['name']) ?></span>
                                </a>
                                <span class="race"><?= htmlspecialchars($opponentInfo['race']) ?></span>
                            </div>
                        </div>
                        <div class="match-footer">
                            <?php if (!empty($match['source'])): ?>
                                <a href="<?= htmlspecialchars($match['source']) ?>" target="_blank" class="match-link">
                                    <?= htmlspecialchars(getDomainFromUrl($match['source'])) ?>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($match['vod'])): ?>
                                <a href="<?= htmlspecialchars($match['vod']) ?>" target="_blank" class="match-link">
                                    <?= htmlspecialchars(getDomainFromUrl($match['vod'])) ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- View All Matches Link -->
            <div class="view-all-matches">
                <a href="fsl_matches.php?player=<?= urlencode($playerName) ?>" class="view-all-link">
                    View All Matches for <?= htmlspecialchars($playerName) ?>
                </a>
            </div>
        </div>
    </div>
</div>

<style>
    .profile-container {
        display: grid;
        gap: 2rem;
        padding: 20px;
    }

    .player-info, .recent-matches {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
    }

    /* Spider Chart Section Styles */
    .spider-chart-section {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
    }

    .spider-chart-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .header-left {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .spider-chart-header h2 {
        margin: 0;
        color: #00d4ff;
        font-size: 1.8em;
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
    }

    .whats-this-link:hover {
        background: rgba(0, 212, 255, 0.2);
        color: #ffffff;
        text-shadow: 0 0 5px #00d4ff;
    }

    .division-selector {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .division-selector label {
        color: #00d4ff;
        font-weight: 600;
        font-size: 1.1em;
    }

    .division-selector select {
        padding: 8px 12px;
        border: 1px solid rgba(0, 212, 255, 0.5);
        border-radius: 5px;
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
        font-size: 1em;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .division-selector select:hover {
        border-color: #00d4ff;
        background: rgba(0, 212, 255, 0.1);
    }

    .division-selector select:focus {
        outline: none;
        border-color: #00d4ff;
        box-shadow: 0 0 5px rgba(0, 212, 255, 0.5);
    }

    .no-spider-data {
        text-align: center;
        padding: 40px 20px;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 10px;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .no-spider-data h3 {
        color: #00d4ff;
        margin-bottom: 15px;
        font-size: 1.5em;
    }

    .no-spider-data p {
        color: #e0e0e0;
        margin: 10px 0;
        font-size: 1.1em;
        line-height: 1.5;
    }

    .spider-chart-container {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2rem;
        margin-bottom: 2rem;
    }

    .chart-wrapper {
        display: flex;
        justify-content: center;
        align-items: center;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 10px;
        padding: 20px;
        min-height: 400px;
    }

    .chart-stats {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
    }

    .stat-item {
        padding: 10px;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 8px;
        border-left: 3px solid #00d4ff;
    }

    .stat-item label {
        display: block;
        color: #00d4ff;
        font-weight: 600;
        margin-bottom: 5px;
        font-size: 0.9em;
    }

    .stat-value {
        color: #e0e0e0;
        font-size: 1.1em;
        font-weight: 600;
    }

    .reviewers-list {
        padding: 15px;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 8px;
        border-left: 3px solid #00d4ff;
    }

    .reviewers-list label {
        display: block;
        color: #00d4ff;
        font-weight: 600;
        margin-bottom: 8px;
    }

    .reviewer-names {
        color: #e0e0e0;
        font-size: 0.95em;
        line-height: 1.4;
    }

    .attribute-scores {
        background: rgba(0, 0, 0, 0.2);
        border-radius: 8px;
        padding: 20px;
    }

    .attribute-scores h3 {
        color: #00d4ff;
        margin-bottom: 15px;
        text-align: center;
        font-size: 1.4em;
    }

    .scores-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
    }

    .score-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 15px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 6px;
        border: 1px solid rgba(255, 255, 255, 0.1);
        transition: all 0.2s ease;
    }

    .score-item:hover {
        background: rgba(0, 212, 255, 0.1);
        border-color: #00d4ff;
        transform: translateX(3px);
    }

    .attribute-name {
        color: #00d4ff;
        font-weight: 600;
        font-size: 1em;
    }

    .score-value {
        color: #e0e0e0;
        font-weight: 700;
        font-size: 1.1em;
    }

    h2 {
        color: #00d4ff;
        margin-bottom: 20px;
        font-size: 1.8em;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1rem;
    }

    .info-item {
        padding: 10px;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 8px;
    }

    .info-item label {
        display: block;
        color: #00d4ff;
        font-weight: 600;
        margin-bottom: 5px;
    }

    .team-info-item {
        grid-column: 1 / -1;
    }

    .team-display {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }

    .player-team-logo {
        width: 48px;
        height: 48px;
        border-radius: 8px;
        object-fit: cover;
        border: 2px solid rgba(255, 111, 97, 0.5);
        transition: all 0.3s ease;
    }

    .player-team-logo:hover {
        border-color: #00d4ff;
        box-shadow: 0 0 10px rgba(0, 212, 255, 0.5);
        transform: scale(1.05);
    }

    .matches-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 1rem;
    }

    .match-card {
        background: rgba(0, 0, 0, 0.2);
        border-radius: 8px;
        overflow: hidden;
    }

    .match-header {
        background: rgba(0, 0, 0, 0.3);
        padding: 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .match-content {
        padding: 15px;
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        gap: 1rem;
        align-items: center;
    }

    .player {
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    .player.highlight {
        color: #00d4ff;
        font-weight: bold;
    }

    .score {
        font-size: 1.2em;
        font-weight: bold;
        color: #00d4ff;
    }

    .match-footer {
        padding: 10px;
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }

    .match-link {
        color: #00d4ff;
        text-decoration: none;
        padding: 4px 8px;
        border: 1px solid #00d4ff;
        border-radius: 4px;
        transition: all 0.3s ease;
    }

    .match-link:hover {
        background-color: rgba(255, 255, 255, 0.2);
    }

    .team-link {
        color: #ff6f61;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    
    .team-link:hover {
        color: #ff8577;
        text-shadow: 0 0 5px #ff6f61;
    }

    .player-link {
        color: #e0e0e0;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    
    .player-link:hover {
        color: #00d4ff;
        text-shadow: 0 0 5px #00d4ff;
    }

    .match-card .player-link {
        display: inline-block;
        margin-bottom: 4px;
    }

    .match-card .highlight .player-link {
        color: #00d4ff;
    }

    .match-card .player .race {
        display: block;
        font-size: 0.9em;
        opacity: 0.8;
    }

    .match-id-link {
        color: #00d4ff;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    
    .match-id-link:hover {
        color: #ffffff;
        text-shadow: 0 0 5px #00d4ff;
    }

    @media (max-width: 768px) {
        .profile-container {
            padding: 10px;
        }

        .info-grid {
            grid-template-columns: 1fr;
        }

        .matches-grid {
            grid-template-columns: 1fr;
        }

        .spider-chart-header {
            flex-direction: column;
            align-items: stretch;
            gap: 15px;
        }

        .header-left {
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .spider-chart-header h2 {
            text-align: center;
            font-size: 1.5em;
        }

        .division-selector {
            justify-content: center;
        }

        .division-selector select {
            flex: 1;
            max-width: 300px;
        }

        .no-spider-data {
            padding: 30px 15px;
        }

        .no-spider-data h3 {
            font-size: 1.3em;
        }

        .no-spider-data p {
            font-size: 1em;
        }
    }

    /* Team Role Badge Styles */
    .team-role-badge {
        display: inline-block;
        padding: 3px 8px;
        margin-left: 8px;
        border-radius: 4px;
        font-size: 0.8em;
        font-weight: bold;
        color: white;
    }
    
    .team-role-badge.captain {
        background-color: #ffc107; /* Gold for Captain */
        color: #000;
    }
    
    .team-role-badge.co-captain {
        background-color: #6c757d; /* Silver for Co-Captain */
    }
    
    /* Make the badge in the title larger */
    h1 .team-role-badge {
        font-size: 0.5em;
        vertical-align: middle;
        padding: 5px 10px;
    }

    .championship-container {
        grid-column: 1 / -1;
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .championship-record {
        flex: 1;
        min-width: 300px;
        max-width: calc(50% - 0.5rem);
        padding: 15px;
        font-size: 0.9em;
        line-height: 1.4;
        white-space: normal;
        overflow-wrap: break-word;
        background-color: rgba(0, 0, 0, 0.2);
        border-radius: 5px;
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
    }

    @media (max-width: 768px) {
        .championship-record {
            max-width: 100%;
        }
    }

    /* View All Matches Link Styles */
    .view-all-matches {
        margin-top: 20px;
        text-align: center;
        padding-top: 15px;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }

    .view-all-link {
        display: inline-block;
        color: #00d4ff;
        text-decoration: none;
        padding: 12px 24px;
        border: 2px solid #00d4ff;
        border-radius: 8px;
        font-weight: 600;
        font-size: 1.1em;
        transition: all 0.3s ease;
        background: rgba(0, 212, 255, 0.1);
        box-shadow: 0 2px 10px rgba(0, 212, 255, 0.2);
    }

    .view-all-link:hover {
        background: rgba(0, 212, 255, 0.2);
        color: #ffffff;
        text-shadow: 0 0 8px #00d4ff;
        box-shadow: 0 4px 20px rgba(0, 212, 255, 0.4);
        transform: translateY(-1px);
    }

    /* Team History Styles */
    .team-history {
        grid-column: 1 / -1;
    }

    .team-history span {
        display: block;
        font-size: 0.95em;
        line-height: 1.5;
        margin-top: 5px;
    }

    /* Mobile Responsive for Spider Chart */
    @media (max-width: 768px) {
        .spider-chart-container {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        
        .chart-wrapper {
            min-height: 300px;
        }
        
        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        }
        
        .scores-grid {
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        }
    }
</style>

<?php if ($spiderChartData): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('spiderChart').getContext('2d');
    
    const data = {
        labels: ['Micro', 'Macro', 'Clutch', 'Creativity', 'Aggression', 'Strategy'],
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

<script>
// Division selector function (available for all cases)
function changeDivision(division) {
    const currentUrl = new URL(window.location);
    currentUrl.searchParams.set('division', division);
    window.location.href = currentUrl.toString();
}
</script>

<?php include 'includes/footer.php'; ?> 
