<?php
/**
 * FSL Season Standings and Schedule Page
 * Displays team standings and match schedule for current season
 * Uses file-based caching (15 min TTL) to reduce database load
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

require_once 'includes/team_logo.php';

// Cache configuration
define('CACHE_FILE', __DIR__ . '/cache/fsl_schedule.json');
define('CACHE_TTL', 900); // 15 minutes

// Check for valid cached data
$cachedData = null;
$cacheStatus = '';
$cacheTime = '';

if (file_exists(CACHE_FILE)) {
    $cacheTime = date('Y-m-d H:i:s', filemtime(CACHE_FILE));
    $cacheAge = time() - filemtime(CACHE_FILE);
    
    if ($cacheAge < CACHE_TTL) {
        $cachedData = json_decode(file_get_contents(CACHE_FILE), true);
        $cacheStatus = "CACHE_FRESH (age: {$cacheAge}s, created: {$cacheTime})";
    }
}

if ($cachedData === null) {
    // Include database connection
    require_once 'includes/db.php';
    require_once 'includes/season_utils.php';

    try {
        // Connect to database
        $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $currentSeason = getCurrentSeason($db);
    // Get current season schedule from database
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
            COALESCE(t1.Team_Name, 'TBD') as team1_name,
            COALESCE(t2.Team_Name, 'TBD') as team2_name,
            tw.Team_Name as winner_name
        FROM fsl_schedule s
        LEFT JOIN Teams t1 ON s.team1_id = t1.Team_ID
        LEFT JOIN Teams t2 ON s.team2_id = t2.Team_ID
        LEFT JOIN Teams tw ON s.winner_team_id = tw.Team_ID
        WHERE s.season = ?
        ORDER BY s.week_number
    ";

        $stmt = $db->prepare($scheduleQuery);
        $stmt->execute([$currentSeason]);
        $season9Schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Pre-fetch all match data for all schedule entries at once (batch query)
    $allScheduleIds = array_column($season9Schedule, 'schedule_id');
    $scheduleMatchMap = [];
    $allMatchIds = [];
    
    if (!empty($allScheduleIds)) {
        $placeholders = str_repeat('?,', count($allScheduleIds) - 1) . '?';
        $matchIdsQuery = "SELECT schedule_id, fsl_match_id, match_type FROM fsl_schedule_matches WHERE schedule_id IN ($placeholders)";
        $stmt = $db->prepare($matchIdsQuery);
        $stmt->execute($allScheduleIds);
        $matchRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($matchRows as $row) {
            $scheduleMatchMap[$row['schedule_id']][] = $row['fsl_match_id'];
            $allMatchIds[] = $row['fsl_match_id'];
        }
    }

    // Fetch all match details in one query
    $allMatchDetails = [];
    if (!empty($allMatchIds)) {
        $allMatchIds = array_values(array_unique($allMatchIds));
        $placeholders = str_repeat('?,', count($allMatchIds) - 1) . '?';
        $detailsQuery = "SELECT 
            fm.fsl_match_id,
            fm.t_code,
            fm.season_extra_info,
            fm.map_win,
            fm.map_loss,
            fm.notes,
            fm.vod,
            p_w.Real_Name AS winner_name,
            p_w.Team_ID AS winner_team_id,
            p_l.Real_Name AS loser_name,
            p_l.Team_ID AS loser_team_id,
            fm.winner_race,
            fm.loser_race,
            fsm.match_type,
            fsm.schedule_id
        FROM fsl_matches fm
        JOIN Players p_w ON fm.winner_player_id = p_w.Player_ID
        JOIN Players p_l ON fm.loser_player_id = p_l.Player_ID
        JOIN fsl_schedule_matches fsm ON fm.fsl_match_id = fsm.fsl_match_id
        WHERE fm.fsl_match_id IN ($placeholders)";
        
        $stmt = $db->prepare($detailsQuery);
        $stmt->execute($allMatchIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($rows as $row) {
            $allMatchDetails[$row['schedule_id']][] = $row;
        }
    }

        // Save to cache
        $cachedData = [
            'currentSeason' => $currentSeason,
            'schedule' => $season9Schedule,
            'matchMap' => $scheduleMatchMap,
            'matchDetails' => $allMatchDetails
        ];
        
        if (!is_dir(dirname(CACHE_FILE))) {
            mkdir(dirname(CACHE_FILE), 0755, true);
        }
        file_put_contents(CACHE_FILE, json_encode($cachedData));
        $cacheStatus = "DB_LIVE (cache refreshed: " . date('Y-m-d H:i:s') . ")";
        
    } catch (PDOException $e) {
        // DB failed - try stale cache as fallback
        if (file_exists(CACHE_FILE)) {
            $cachedData = json_decode(file_get_contents(CACHE_FILE), true);
            $cacheStatus = "CACHE_STALE_FALLBACK (DB unreachable, using cache from: {$cacheTime})";
        } else {
            die("Database unavailable and no cache exists: " . $e->getMessage());
        }
    }
}

if ($cachedData !== null) {
    if (empty($season9Schedule)) {
        $season9Schedule = $cachedData['schedule'];
        $scheduleMatchMap = $cachedData['matchMap'];
        $allMatchDetails = $cachedData['matchDetails'];
    }
    $currentSeason = $cachedData['currentSeason'] ?? ($season9Schedule[0]['season'] ?? 9);
}

// Helper functions (use cached data instead of DB queries)
function getScheduleMatchIds($db, $scheduleId) {
    global $scheduleMatchMap;
    return $scheduleMatchMap[$scheduleId] ?? [];
}

function getMatchDetails($db, $matchIds) {
    global $allMatchDetails;
    // This now returns from cache - find by matching matchIds
    // We iterate through allMatchDetails to find rows matching the matchIds
    $result = [];
    foreach ($allMatchDetails as $scheduleId => $matches) {
        foreach ($matches as $match) {
            if (in_array($match['fsl_match_id'], $matchIds)) {
                $result[] = $match;
            }
        }
    }
    return $result;
}

// Function to extract YouTube video ID from URL
function getYouTubeVideoId($url) {
    if (empty($url)) return null;
    
    // Handle youtube.com/watch?v= format
    if (preg_match('/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/', $url, $matches)) {
        return $matches[1];
    }
    
    // Handle youtu.be/ format
    if (preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
        return $matches[1];
    }
    
    // Handle youtube.com/embed/ format
    if (preg_match('/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
        return $matches[1];
    }
    
    // Handle youtube.com/live/ format (live streams)
    if (preg_match('/youtube\.com\/live\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
        return $matches[1];
    }
    
    return null;
}

// Find the next match (first non-completed match)
$nextMatchWeek = null;
foreach ($season9Schedule as $match) {
    if ($match['status'] !== 'completed') {
        $nextMatchWeek = $match['week_number'];
        break;
    }
}

// Set page title
$pageTitle = "FSL Season {$currentSeason} Standings and Schedule";

// Include header
include 'includes/header.php';
echo "<!-- Data: $cacheStatus -->\n";
?>

<div class="container mt-4">
    <h1>Season <?= (int) $currentSeason ?> Standings and Schedule</h1>
    
    <!-- Week Navigation Bar -->
    <div class="week-navigation">
        <?php if ($nextMatchWeek !== null): ?>
            <a href="#week<?= $nextMatchWeek ?>" class="btn-next-match">
                Next Match
            </a>
        <?php endif; ?>
        <div class="week-links">
            <?php foreach ($season9Schedule as $match): ?>
                <a href="#week<?= $match['week_number'] ?>" class="week-link <?= $match['status'] === 'completed' ? 'completed' : '' ?>">
                    <?= $match['week_number'] ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="schedule-container">
        <!-- SEASON STANDINGS - Moved to top and made prominent -->
        <div class="season-standings">
            <h2>Current Season Standings</h2>
            <div class="standings-table">
                <?php
                // Calculate standings from results - include all teams
                $standings = [];
                
                // Initialize all teams with 0-0 records (skip placeholder TBD when both teams null)
                foreach ($season9Schedule as $match) {
                    $n1 = $match['team1_name'] ?? null;
                    $n2 = $match['team2_name'] ?? null;
                    if ($n1 && $n1 !== 'TBD' && !isset($standings[$n1])) {
                        $standings[$n1] = ['wins' => 0, 'losses' => 0];
                    }
                    if ($n2 && $n2 !== 'TBD' && !isset($standings[$n2])) {
                        $standings[$n2] = ['wins' => 0, 'losses' => 0];
                    }
                }
                
                // Add wins and losses from completed matches
                foreach ($season9Schedule as $match) {
                    if ($match['status'] === 'completed' && $match['winner_team_id']) {
                        $winner = $match['winner_name'];
                        $loser = ($match['winner_team_id'] == $match['team1_id']) ? $match['team2_name'] : $match['team1_name'];
                        if ($winner && $loser && isset($standings[$winner]) && isset($standings[$loser])) {
                            $standings[$winner]['wins']++;
                            $standings[$loser]['losses']++;
                        }
                    }
                }
                
                // Sort by wins desc, losses asc
                uasort($standings, function($a, $b) {
                    if ($a['wins'] == $b['wins']) {
                        return $a['losses'] - $b['losses'];
                    }
                    return $b['wins'] - $a['wins'];
                });
                
                // Function to get team record
                function getTeamRecord($teamName, $standings) {
                    if (isset($standings[$teamName])) {
                        return '(' . $standings[$teamName]['wins'] . '-' . $standings[$teamName]['losses'] . ')';
                    }
                    return '(0-0)';
                }
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
                            <?php $standingsLogo = getTeamLogo($teamName); ?>
                            <tr class="<?= $rank <= 3 ? 'top-team' : '' ?>">
                                <td class="rank"><?= $rank ?></td>
                                <td class="team-cell">
                                    <?php if ($standingsLogo): ?>
                                    <a href="view_team.php?name=<?= urlencode($teamName) ?>">
                                        <img src="<?= htmlspecialchars($standingsLogo) ?>" alt="<?= htmlspecialchars($teamName) ?>" class="standings-team-logo">
                                    </a>
                                    <?php endif; ?>
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
                <div class="team-match-card" id="week<?= $match['week_number'] ?>">
                    <div class="match-header">
                        <div class="week-info">
                            <h3>Week <?= $match['week_number'] ?></h3>
                            <p class="match-date">
                                <?php 
                                if ($match['match_date']) {
                                    $matchDateTime = new DateTime($match['match_date']);
                                    echo $matchDateTime->format('l, F j, Y \a\t g:i A') . ' <span class="timezone">US Eastern</span>';
                                } else {
                                    echo 'TBD';
                                }
                                ?>
                            </p>
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
                        
                        <?php
                        $team1_name = $match['team1_name'];
                        $team2_name = $match['team2_name'];
                        $team1_is_placeholder = empty($match['team1_id']);
                        $team2_is_placeholder = empty($match['team2_id']);
                        $team1Logo = getTeamLogo($team1_name);
                        $team2Logo = getTeamLogo($team2_name);
                        ?>
                        <div class="team-side <?= $team1_class ?>">
                            <?php if ($team1Logo): ?>
                            <a href="view_team.php?name=<?= urlencode($team1_name) ?>">
                                <img src="<?= htmlspecialchars($team1Logo) ?>" alt="<?= htmlspecialchars($team1_name) ?>" class="matchup-team-logo">
                            </a>
                            <?php endif; ?>
                            <h4>
                                <?php if ($team1_is_placeholder): ?>
                                    <span class="team-link"><?= htmlspecialchars($team1_name) ?></span>
                                <?php else: ?>
                                    <a href="view_team.php?name=<?= urlencode($team1_name) ?>" class="team-link">
                                        <?= htmlspecialchars($team1_name) ?> <span class="team-record"><?= getTeamRecord($team1_name, $standings) ?></span>
                                    </a>
                                <?php endif; ?>
                            </h4>
                            <div class="team-score"><?= $team1_score ?></div>
                        </div>
                        
                        <div class="vs-divider">
                            <span>VS</span>
                        </div>
                        
                        <div class="team-side <?= $team2_class ?>">
                            <?php if ($team2Logo): ?>
                            <a href="view_team.php?name=<?= urlencode($team2_name) ?>">
                                <img src="<?= htmlspecialchars($team2Logo) ?>" alt="<?= htmlspecialchars($team2_name) ?>" class="matchup-team-logo">
                            </a>
                            <?php endif; ?>
                            <h4>
                                <?php if ($team2_is_placeholder): ?>
                                    <span class="team-link"><?= htmlspecialchars($team2_name) ?></span>
                                <?php else: ?>
                                    <a href="view_team.php?name=<?= urlencode($team2_name) ?>" class="team-link">
                                        <?= htmlspecialchars($team2_name) ?> <span class="team-record"><?= getTeamRecord($team2_name, $standings) ?></span>
                                    </a>
                                <?php endif; ?>
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
                                
                                // Debug: Show what matches we got
                                if ($match['week_number'] == 5) {
                                    echo "<!-- DEBUG: Week 5 has " . count($matchDetails) . " matches -->";
                                    foreach ($matchDetails as $debug) {
                                        echo "<!-- DEBUG: Match " . $debug['fsl_match_id'] . " - t_code: " . $debug['t_code'] . " - match_type: " . $debug['match_type'] . " - season_extra_info: " . $debug['season_extra_info'] . " -->";
                                    }
                                }
                                
                                foreach ($matchDetails as $detail): 
                                    // Skip 2v2 matches since we have manual entry for those
                                    if ($detail['t_code'] === '2v2') continue;
                                    
                                    // Determine the correct match type to display
                                    $displayType = $detail['t_code'];
                                    if ($detail['match_type'] === 'ACE' || 
                                        $detail['t_code'] === 'ACE' || 
                                        $detail['season_extra_info'] === 'Ace match' ||
                                        stripos($detail['notes'], 'ace') !== false) {
                                        $displayType = 'ACE';
                                    }
                                ?>
                                    <div class="individual-match">
                                        <div class="match-code">
                                            <strong><?= htmlspecialchars($displayType) ?>:</strong>
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
                        <?php endif; ?>
                        
                        <?php 
                        // Show VODs for completed matches
                        if ($match['status'] === 'completed' && !empty($matchIds)) {
                            // Get match details if not already fetched
                            if (!isset($matchDetails)) {
                                $matchDetails = getMatchDetails($db, $matchIds);
                            }
                            
                            $uniqueVods = [];
                            
                            // Collect unique VOD URLs
                            foreach ($matchDetails as $detail) {
                                if (!empty($detail['vod']) && !in_array($detail['vod'], $uniqueVods)) {
                                    $uniqueVods[] = $detail['vod'];
                                }
                            }
                            
                            // Display YouTube embeds for unique VODs
                            if (!empty($uniqueVods)) {
                                echo '<div class="vod-section">';
                                echo '<h5><i class="fab fa-youtube"></i> Watch the Match:</h5>';
                                echo '<div class="vod-container">';
                                
                                foreach ($uniqueVods as $vodUrl) {
                                    $videoId = getYouTubeVideoId($vodUrl);
                                    if ($videoId) {
                                        echo '<div class="vod-player">';
                                        echo '<iframe width="320" height="180" ';
                                        echo 'src="https://www.youtube.com/embed/' . htmlspecialchars($videoId) . '" ';
                                        echo 'title="YouTube video player" frameborder="0" ';
                                        echo 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" ';
                                        echo 'allowfullscreen></iframe>';
                                        echo '</div>';
                                    }
                                }
                                
                                echo '</div>';
                                echo '</div>';
                            }
                        }
                        ?>
                        
                        <?php if (empty($matchIds) && empty($match['team_2v2_results'])): ?>
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
    html {
        scroll-behavior: smooth;
    }
    
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
    
    /* Week Navigation Bar */
    .week-navigation {
        background: rgba(0, 0, 0, 0.4);
        border: 2px solid rgba(0, 212, 255, 0.3);
        border-radius: 10px;
        padding: 15px 20px;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
        position: sticky;
        top: 20px;
        z-index: 100;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
    }
    
    .btn-next-match {
        background: linear-gradient(135deg, #28a745, #20c997);
        color: white;
        padding: 10px 20px;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 700;
        font-size: 1.1em;
        white-space: nowrap;
        transition: all 0.3s ease;
        box-shadow: 0 2px 10px rgba(40, 167, 69, 0.4);
    }
    
    .btn-next-match:hover {
        background: linear-gradient(135deg, #20c997, #28a745);
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.6);
        color: white;
        text-decoration: none;
    }
    
    .week-links {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        flex: 1;
        justify-content: center;
    }
    
    .week-link {
        background: rgba(0, 212, 255, 0.2);
        color: #00d4ff;
        padding: 8px 15px;
        border-radius: 6px;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.95em;
        transition: all 0.3s ease;
        border: 1px solid rgba(0, 212, 255, 0.3);
    }
    
    .week-link:hover {
        background: rgba(0, 212, 255, 0.4);
        color: #ffffff;
        transform: translateY(-2px);
        box-shadow: 0 2px 8px rgba(0, 212, 255, 0.4);
        text-decoration: none;
    }
    
    .week-link.completed {
        background: rgba(40, 167, 69, 0.2);
        border-color: rgba(40, 167, 69, 0.4);
        color: #28a745;
    }
    
    .week-link.completed:hover {
        background: rgba(40, 167, 69, 0.3);
        color: #ffffff;
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
        padding: 15px 12px;
        text-align: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        vertical-align: middle;
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
    
    .team-cell {
        display: flex;
        align-items: center;
        gap: 15px;
        justify-content: flex-start;
        text-align: left;
    }
    
    .standings-team-logo {
        width: 64px;
        height: 64px;
        border-radius: 10px;
        object-fit: cover;
        border: 2px solid rgba(0, 212, 255, 0.3);
        transition: all 0.3s ease;
        flex-shrink: 0;
    }
    
    .standings-team-logo:hover {
        border-color: #00d4ff;
        box-shadow: 0 0 12px rgba(0, 212, 255, 0.5);
        transform: scale(1.05);
    }
    
    .matchup-team-logo {
        width: 140px;
        height: 140px;
        border-radius: 15px;
        object-fit: cover;
        border: 3px solid rgba(0, 212, 255, 0.4);
        margin-bottom: 15px;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
    }
    
    .matchup-team-logo:hover {
        border-color: #00d4ff;
        box-shadow: 0 0 25px rgba(0, 212, 255, 0.6);
        transform: scale(1.03);
    }
    
    .team-side.winner .matchup-team-logo {
        border-color: rgba(40, 167, 69, 0.7);
        box-shadow: 0 0 20px rgba(40, 167, 69, 0.3);
    }
    
    .team-side.loser .matchup-team-logo {
        border-color: rgba(220, 53, 69, 0.5);
        opacity: 0.85;
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
        scroll-margin-top: 20px;
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
    
    .timezone {
        color: #00d4ff;
        font-size: 0.9em;
        font-weight: 600;
        text-shadow: 0 0 3px #00d4ff;
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
        padding: 25px 20px;
        border-radius: 12px;
        min-width: 220px;
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
    
    .team-record {
        font-size: 0.85em;
        color: #ccc;
        font-weight: normal;
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
    
    /* VOD Section Styles */
    .vod-section {
        background: rgba(255, 0, 0, 0.1);
        border-left: 4px solid #ff0000;
        border-radius: 8px;
        padding: 15px;
        margin-top: 15px;
    }
    
    .vod-section h5 {
        color: #ff0000;
        margin: 0 0 15px 0;
        font-size: 1.1em;
    }
    
    .vod-container {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        justify-content: flex-start;
    }
    
    .vod-player {
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
    }
    
    .vod-player iframe {
        border-radius: 8px;
    }
    
    @media (max-width: 768px) {
        .container {
            padding: 10px;
        }
        
        h1 {
            font-size: 1.8em;
            margin-bottom: 20px;
        }
        
        .week-navigation {
            padding: 12px 15px;
            gap: 12px;
            position: relative;
            top: 0;
        }
        
        .btn-next-match {
            padding: 8px 16px;
            font-size: 1em;
            width: 100%;
            text-align: center;
        }
        
        .week-links {
            width: 100%;
            gap: 6px;
        }
        
        .week-link {
            padding: 6px 12px;
            font-size: 0.85em;
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
        
        .standings-team-logo {
            width: 48px;
            height: 48px;
        }
        
        .team-cell {
            gap: 10px;
        }
        
        .matchup-team-logo {
            width: 100px;
            height: 100px;
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
        
        .team-record {
            font-size: 0.8em;
        }
        
        /* VOD Section Mobile Styles */
        .vod-section {
            padding: 12px;
            margin-top: 12px;
        }
        
        .vod-section h5 {
            font-size: 1em;
            margin-bottom: 10px;
        }
        
        .vod-container {
            gap: 10px;
            justify-content: center;
        }
        
        .vod-player iframe {
            width: 280px;
            height: 158px;
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
        
        .week-navigation {
            padding: 10px 12px;
            gap: 10px;
        }
        
        .btn-next-match {
            padding: 7px 14px;
            font-size: 0.9em;
        }
        
        .week-links {
            gap: 5px;
        }
        
        .week-link {
            padding: 5px 10px;
            font-size: 0.8em;
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
        
        .standings-team-logo {
            width: 36px;
            height: 36px;
        }
        
        .team-cell {
            gap: 8px;
        }
        
        .matchup-team-logo {
            width: 80px;
            height: 80px;
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
        
        /* VOD Section Small Mobile Styles */
        .vod-section {
            padding: 8px;
            margin-top: 8px;
        }
        
        .vod-section h5 {
            font-size: 0.9em;
            margin-bottom: 8px;
        }
        
        .vod-container {
            gap: 8px;
        }
        
        .vod-player iframe {
            width: 240px;
            height: 135px;
        }
    }
</style>

<?php include 'includes/footer.php'; ?> 