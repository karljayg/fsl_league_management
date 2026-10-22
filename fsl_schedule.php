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
require_once 'includes/fsl_schedule_permission.php';
require_once 'includes/fsl_schedule_staff.php';

$canEditSchedule = false;
$scheduleDb = null;
if (isset($_SESSION['user_id']) && fsl_valid_user_id($_SESSION['user_id'])) {
    try {
        require_once 'config.php';
        $scheduleDb = new PDO(
            'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
            $config['db_user'],
            $config['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $canEditSchedule = fsl_can_manage_schedule($scheduleDb, $_SESSION['user_id']);
    } catch (Throwable $e) {
        $canEditSchedule = false;
        $scheduleDb = null;
    }
}

$scheduleLayout = fsl_schedule_layout_value($_GET['layout'] ?? 'list');
$editMode = $canEditSchedule && (($_GET['edit'] ?? '') === '1');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEditSchedule && $scheduleDb instanceof PDO) {
    fsl_schedule_handle_post($scheduleDb);
}

if ($canEditSchedule && $scheduleDb instanceof PDO && isset($_GET['add_broadcast'])) {
    $addSeason = (int) ($_GET['season'] ?? 0);
    $addLayout = fsl_schedule_layout_value($_GET['layout'] ?? 'list');
    $addScheduleId = (int) $_GET['add_broadcast'];
    if ($addSeason > 0 && $addScheduleId > 0 && fsl_schedule_broadcasts_schema_ready($scheduleDb)) {
        $err = fsl_schedule_add_broadcast_slot($scheduleDb, $addScheduleId, $addSeason);
        if ($err !== null) {
            fsl_schedule_flash('danger', $err);
        } else {
            fsl_schedule_bust_cache($addSeason);
            fsl_schedule_flash('success', 'Broadcast slot added.');
        }
        $weekStmt = $scheduleDb->prepare('SELECT week_number FROM fsl_schedule WHERE schedule_id = ? AND season = ?');
        $weekStmt->execute([$addScheduleId, $addSeason]);
        $addWeek = (int) $weekStmt->fetchColumn();
        fsl_schedule_redirect_edit($addSeason, $addLayout, $addWeek);
    }
}

$scheduleFlash = $_SESSION['fsl_schedule_flash'] ?? null;
unset($_SESSION['fsl_schedule_flash']);
$staffSchema = false;
$defaultProduction = null;
$registeredPlayers = [];
$productionRoster = [];
$scheduleTeams = [];

// Cache configuration
define('CACHE_TTL', 900); // 15 minutes

// Determine requested season from GET param (anchors like #week1&season9 are
// rewritten to ?season=9#week1 by the JS snippet at the bottom of the page)
$requestedSeason = isset($_GET['season']) ? (int) $_GET['season'] : null;

// Per-season cache file; pass null to get the "latest season" default cache
function getCacheFile($season = null) {
    if ($season === null) {
        return __DIR__ . '/cache/fsl_schedule_latest.json';
    }
    return __DIR__ . '/cache/fsl_schedule_s' . (int)$season . '.json';
}

// Check for valid cached data
$cachedData = null;
$cacheStatus = '';
$cacheTime = '';

$cacheFile = getCacheFile($requestedSeason);
if (file_exists($cacheFile)) {
    $cacheTime = date('Y-m-d H:i:s', filemtime($cacheFile));
    $cacheAge = time() - filemtime($cacheFile);
    if ($cacheAge < CACHE_TTL) {
        $cachedData = json_decode(file_get_contents($cacheFile), true);
        $cacheStatus = "CACHE_FRESH (age: {$cacheAge}s, created: {$cacheTime})";
    }
}

if (is_array($cachedData) && (int) ($cachedData['cacheVersion'] ?? 0) !== FSL_SCHEDULE_CACHE_VERSION) {
    $cachedData = null;
}
if ($editMode) {
    $cachedData = null;
}
if (is_array($cachedData) && empty($cachedData['staffSchema'])) {
    $probe = $scheduleDb;
    if (!$probe instanceof PDO) {
        try {
            if (!isset($config) || !is_array($config)) {
                require_once 'config.php';
            }
            $probe = new PDO(
                'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
                $config['db_user'],
                $config['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (Throwable $e) {
            $probe = null;
        }
    }
    if ($probe instanceof PDO && fsl_schedule_staff_schema_ready($probe)) {
        $scheduleDb = $probe;
        $cachedData = null;
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

        $latestSeason = getCurrentSeason($db);
        $currentSeason = ($requestedSeason !== null) ? $requestedSeason : $latestSeason;

        // Which seasons have rows in fsl_schedule (these get ?season=N links)
        $scheduledSeasons = $db->query("SELECT DISTINCT season FROM fsl_schedule ORDER BY season")
                              ->fetchAll(PDO::FETCH_COLUMN);
        $scheduledSeasons = array_map('intval', $scheduledSeasons);

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
            " . fsl_schedule_staff_select_sql($db) . "
        FROM fsl_schedule s
        LEFT JOIN Teams t1 ON s.team1_id = t1.Team_ID
        LEFT JOIN Teams t2 ON s.team2_id = t2.Team_ID
        LEFT JOIN Teams tw ON s.winner_team_id = tw.Team_ID
        " . fsl_schedule_staff_join_sql($db) . "
        WHERE s.season = ?
        ORDER BY s.week_number
    ";

        $stmt = $db->prepare($scheduleQuery);
        $stmt->execute([$currentSeason]);
        $season9Schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
        fsl_schedule_attach_broadcasts($db, $season9Schedule);

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

        $staffSchema = fsl_schedule_staff_schema_ready($db);
        $defaultProduction = $staffSchema ? fsl_schedule_default_production($db) : null;
        if ($editMode && $staffSchema) {
            $registeredPlayers = fsl_schedule_registered_players($db);
            $productionRoster = fsl_schedule_production_roster($db);
            $scheduleTeams = $db->query('SELECT Team_ID, Team_Name FROM Teams ORDER BY Team_Name')->fetchAll(PDO::FETCH_ASSOC);
        }

        // Save to per-season cache
        $cachedData = [
            'cacheVersion'    => FSL_SCHEDULE_CACHE_VERSION,
            'staffSchema'     => $staffSchema,
            'defaultProduction' => $defaultProduction,
            'currentSeason'   => $currentSeason,
            'latestSeason'    => $latestSeason,
            'scheduledSeasons'=> $scheduledSeasons,
            'schedule'        => $season9Schedule,
            'matchMap'        => $scheduleMatchMap,
            'matchDetails'    => $allMatchDetails
        ];

        $cacheDir = __DIR__ . '/cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $encoded = json_encode($cachedData);
        file_put_contents(getCacheFile($currentSeason), $encoded);
        // Also refresh the "latest" cache whenever we're on the current season
        if ($currentSeason === $latestSeason) {
            file_put_contents(getCacheFile(null), $encoded);
        }
        $cacheStatus = "DB_LIVE (cache refreshed: " . date('Y-m-d H:i:s') . ")";
        
    } catch (PDOException $e) {
        // DB failed - try stale per-season cache as fallback
        $staleCacheFile = getCacheFile($requestedSeason);
        if (file_exists($staleCacheFile)) {
            $cachedData = json_decode(file_get_contents($staleCacheFile), true);
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
    $currentSeason    = $cachedData['currentSeason']    ?? ($season9Schedule[0]['season'] ?? 9);
    $latestSeason     = $cachedData['latestSeason']     ?? $currentSeason;
    $scheduledSeasons = $cachedData['scheduledSeasons'] ?? [$currentSeason];
    $staffSchema = !empty($cachedData['staffSchema']);
    $defaultProduction = $cachedData['defaultProduction'] ?? null;
}

if (!empty($season9Schedule)) {
    try {
        if (!isset($db) || !$db instanceof PDO) {
            require_once 'includes/db.php';
        }
        if (isset($db) && $db instanceof PDO && fsl_schedule_broadcasts_schema_ready($db)) {
            $needsBroadcastAttach = false;
            foreach ($season9Schedule as $schedRow) {
                if (!array_key_exists('broadcasts', $schedRow)) {
                    $needsBroadcastAttach = true;
                    break;
                }
            }
            if ($needsBroadcastAttach) {
                fsl_schedule_attach_broadcasts($db, $season9Schedule);
            }
        }
    } catch (Throwable $e) {
        // Cache-only path: skip attach if DB unavailable.
    }
}

// If a specific season was requested but has no schedule data, send to matches page
if ($requestedSeason !== null && !in_array($requestedSeason, $scheduledSeasons, true)) {
    header("Location: fsl_matches.php?season={$requestedSeason}");
    exit;
}

// Helper functions (use cached data instead of DB queries)
function getScheduleMatchIds($scheduleId) {
    global $scheduleMatchMap;
    return $scheduleMatchMap[$scheduleId] ?? [];
}

function getMatchDetails($matchIds) {
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

/** S11: Friday and/or Saturday play window (times fixed; dates from Saturday anchor in match_date). S12 stores the broadcast itself. */
function formatSchedulePlayWindow($season, $matchDateStr) {
    if ((int) $season !== 11 || empty($matchDateStr)) {
        return null;
    }
    $sat = new DateTime($matchDateStr);
    $fri = clone $sat;
    $fri->modify('-1 day');
    return [
        'fri_label' => $fri->format('l, F j, Y'),
        'sat_label' => $sat->format('l, F j, Y'),
    ];
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
    <?php if (!empty($scheduleFlash['text'])): ?>
        <div class="alert alert-<?= ($scheduleFlash['type'] ?? '') === 'success' ? 'success' : 'danger' ?>" role="alert">
            <?= fsl_schedule_h($scheduleFlash['text']) ?>
        </div>
    <?php endif; ?>
    
    <!-- Season / Week Navigation -->
    <div class="schedule-nav">
        <div class="schedule-nav-top">
            <!-- Season picker -->
            <div class="season-picker-wrap">
                <label class="season-picker-label" for="seasonSelect">Season</label>
                <div class="season-select-box">
                    <select id="seasonSelect" onchange="
                        var v = this.value;
                        if (v) window.location.href = v;
                    ">
                        <?php for ($s = $latestSeason; $s >= 1; $s--): ?>
                            <?php
                            $hasSchedule = in_array($s, $scheduledSeasons, true);
                            $href = $hasSchedule ? fsl_schedule_href($s, $scheduleLayout, $editMode) : "fsl_matches.php?season={$s}";
                            $selected = ($s == $currentSeason) ? 'selected' : '';
                            $label = "Season {$s}";
                            if ($s == $latestSeason) $label .= ' (Current)';
                            if (!$hasSchedule)       $label .= ' — Match History';
                            ?>
                            <option value="<?= $href ?>" <?= $selected ?>><?= $label ?></option>
                        <?php endfor; ?>
                    </select>
                    <span class="select-arrow">&#9660;</span>
                </div>
            </div>

            <div class="schedule-view-toggle">
                <a href="<?= fsl_schedule_h(fsl_schedule_href((int) $currentSeason, 'cards', $editMode)) ?>" class="schedule-view-link<?= $scheduleLayout === 'cards' ? ' active' : '' ?>">Cards</a>
                <a href="<?= fsl_schedule_h(fsl_schedule_href((int) $currentSeason, 'list', $editMode)) ?>" class="schedule-view-link<?= $scheduleLayout === 'list' ? ' active' : '' ?>">Broadcast list</a>
                <?php if ($canEditSchedule && $editMode && $staffSchema): ?>
                    <button type="submit" form="schedule-edit-form" class="schedule-view-link schedule-save-btn">Save</button>
                <?php elseif ($canEditSchedule): ?>
                    <a href="<?= fsl_schedule_h(fsl_schedule_href((int) $currentSeason, $scheduleLayout, true)) ?>" class="schedule-view-link<?= $editMode ? ' active' : '' ?>">Edit</a>
                <?php endif; ?>
            </div>
            <?php if ($nextMatchWeek !== null): ?>
                <a href="#week<?= $nextMatchWeek ?>" class="btn-next-match">
                    <span class="btn-next-icon">&#9658;</span> Next Match
                </a>
            <?php endif; ?>
        </div>

        <!-- Week stepper -->
        <?php if (!empty($season9Schedule)): ?>
        <div class="week-stepper">
            <?php foreach ($season9Schedule as $i => $match):
                $isCompleted = $match['status'] === 'completed';
                $isNext      = $match['week_number'] == $nextMatchWeek;
                $stepClass   = $isCompleted ? 'done' : ($isNext ? 'next' : 'upcoming');
            ?>
                <?php if ($i > 0): ?>
                    <div class="step-connector <?= $isCompleted ? 'done' : '' ?>"></div>
                <?php endif; ?>
                <a href="#week<?= $match['week_number'] ?>" class="step <?= $stepClass ?>" title="Week <?= $match['week_number'] ?><?= $isCompleted ? ' — Completed' : ($isNext ? ' — Next Match' : '') ?>">
                    <div class="step-bubble">
                        <?php if ($isCompleted): ?>
                            <svg viewBox="0 0 12 12" width="12" height="12"><polyline points="1.5,6 4.5,9.5 10.5,2.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <?php else: ?>
                            <?= $match['week_number'] ?>
                        <?php endif; ?>
                    </div>
                    <div class="step-label">Wk <?= $match['week_number'] ?></div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
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
                        $standings[$n1] = ['wins' => 0, 'losses' => 0, 'total_wins' => 0];
                    }
                    if ($n2 && $n2 !== 'TBD' && !isset($standings[$n2])) {
                        $standings[$n2] = ['wins' => 0, 'losses' => 0, 'total_wins' => 0];
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

                // Total wins = map wins from completed team matches (2nd tiebreaker)
                foreach ($season9Schedule as $match) {
                    if ($match['status'] !== 'completed') {
                        continue;
                    }
                    if ($match['team1_score'] === null || $match['team2_score'] === null) {
                        continue;
                    }
                    $n1 = $match['team1_name'] ?? null;
                    $n2 = $match['team2_name'] ?? null;
                    if ($n1 && $n1 !== 'TBD' && isset($standings[$n1])) {
                        $standings[$n1]['total_wins'] += (int) $match['team1_score'];
                    }
                    if ($n2 && $n2 !== 'TBD' && isset($standings[$n2])) {
                        $standings[$n2]['total_wins'] += (int) $match['team2_score'];
                    }
                }
                
                // Sort by team wins (desc), total wins (desc), losses (asc), then alphabetical
                uksort($standings, function($nameA, $nameB) use ($standings) {
                    $a = $standings[$nameA];
                    $b = $standings[$nameB];
                    if ($a['wins'] != $b['wins']) return $b['wins'] - $a['wins'];
                    if ($a['total_wins'] != $b['total_wins']) return $b['total_wins'] - $a['total_wins'];
                    if ($a['losses'] != $b['losses']) return $a['losses'] - $b['losses'];
                    return strcasecmp($nameA, $nameB);
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
                            <th>Map Wins</th>
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
                                <td class="map-wins"><?= $record['total_wins'] ?></td>
                            </tr>
                        <?php 
                        $rank++;
                        endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="season-info">
            <h2>Season Format</h2>
            <?php if ((int) $currentSeason === 11): ?>
            <p class="season-play-window-note">Each team match may be played on <strong>Friday and/or Saturday</strong> (7:00 PM Friday or 12:00 PM Saturday, <span class="timezone">US Eastern</span>).</p>
            <?php elseif ((int) $currentSeason === 12): ?>
            <p class="season-play-window-note">One matchup per week. On-air times and casters are set per <strong>broadcast slot</strong> (often Friday 7:00 PM and Saturday 12:00 PM <span class="timezone">US Eastern</span>).</p>
            <?php endif; ?>
            <?php if ((int) $currentSeason === 12): ?>
            <div class="season-format-content">
                <a href="images/FSL_s12_team_league_format.png" target="_blank" class="season-format-image-wrap">
                    <img src="images/FSL_s12_team_league_format.png" alt="FSL Season 12 team league format" class="season-format-image">
                </a>
                <div class="season-format-text">
                    <p>The group is a <strong>double round robin</strong>. All four teams play each other twice, home and away, across six rounds (12 matches). A is Special Tactics, B is HiveMind, C is PSIOP Gaming, and D is PulledTheBoys. Standings from those matches set Seeds 1 through 4.</p>
                    <p>Playoffs are two semifinals (Seed 1 vs Seed 4, Seed 2 vs Seed 3), a third-place match, and the grand final. Thanksgiving, the December 4 weekend (we are in Philly), Christmas, and New Year's weekends are off.</p>
                </div>
            </div>
            <?php elseif ((int) $currentSeason === 9): ?>
            <div class="season-format-content">
                <a href="images/FSL_s9_team_league_format.png" target="_blank" class="season-format-image-wrap">
                    <img src="images/FSL_s9_team_league_format.png" alt="FSL Season 9 team league format" class="season-format-image">
                </a>
                <div class="season-format-text">
                    <p>Five teams played a <strong>double round robin</strong>: 10 rounds, 20 matches, one bye each round. A is PulledTheBoys, B is Angry Space Hares, C is Infinite Cyclists, D is Rages Raiders, and E is Cheesy Nachos.</p>
                    <p>The top three advanced: Seed 2 vs Seed 3 in the semifinal, then Seed 1 vs that winner in the final. 22 matches in all.</p>
                </div>
            </div>
            <?php else: ?>
            <div class="season-format-content">
                <a href="images/FSL_GSL_format_season10.png" target="_blank" class="season-format-image-wrap">
                    <img src="images/FSL_GSL_format_season10.png" alt="FSL GSL Format Season 10" class="season-format-image">
                </a>
                <div class="season-format-text">
                    <p>Teams first compete in a <strong>GSL-style group</strong> to determine Seeds 1 through 4, using opening matches, a winners match, a losers match, and a final elimination match.</p>
                    <p>Those seeds then feed into a <strong>ladder-style playoff</strong>: Seeds 3 and 4 start in Week 1, Seed 2 enters in Week 2, and Seed 1 advances straight to the Grand Finals.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- MATCH SCHEDULE -->
        <div class="match-schedule">
            <h2><?= $scheduleLayout === 'list' ? 'Broadcast list' : 'Match Schedule & Results' ?></h2>
            <?php if ($editMode): ?>
                <?php if ($staffSchema): ?>
                    <?php
                    fsl_schedule_render_production_manager(
                        $productionRoster,
                        $registeredPlayers,
                        fsl_schedule_edit_token(),
                        (int) $currentSeason,
                        $scheduleLayout
                    );
                    ?>
                <?php else: ?>
                    <div class="alert alert-warning" role="alert">
                        Caster and production editing needs the broadcast staff tables. Run the 2026-10-17 section in schema_changes.sql, then reload.
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($editMode && $staffSchema): ?>
            <form id="schedule-edit-form" method="post" action="<?= fsl_schedule_h(fsl_schedule_href((int) $currentSeason, $scheduleLayout, false)) ?>">
                <?= fsl_schedule_edit_hiddens(fsl_schedule_edit_token(), (int) $currentSeason, $scheduleLayout, 'save_all_broadcasts') ?>
            <?php endif; ?>

            <?php if ($scheduleLayout === 'list'): ?>
            <div class="broadcast-list broadcast-list-grouped">
                <?php foreach ($season9Schedule as $match):
                    $weekNum = (int) $match['week_number'];
                    $team1_name = $match['team1_name'];
                    $team2_name = $match['team2_name'];
                    $team1_is_placeholder = empty($match['team1_id']);
                    $team2_is_placeholder = empty($match['team2_id']);
                    $team1Logo = getTeamLogo($team1_name);
                    $team2Logo = getTeamLogo($team2_name);
                    $hasScore = $match['team1_score'] !== null && $match['team2_score'] !== null;
                    $listSlots = fsl_schedule_match_uses_broadcast_slots($match)
                        ? $match['broadcasts']
                        : [null];
                    $playWindow = formatSchedulePlayWindow($match['season'], $match['match_date']);
                    $showCrewColumn = $staffSchema && (
                        fsl_schedule_match_uses_broadcast_slots($match)
                        || $playWindow === null
                    );
                    $weekGroupClass = 'week-group';
                    if ($editMode && $staffSchema) {
                        $weekGroupClass .= ' week-group-editing';
                    }
                    $matchStatus = (string) ($match['status'] ?? 'scheduled');
                    $matchNoteLabel = fsl_schedule_match_note_label($match);
                ?>
                <section class="<?= fsl_schedule_h($weekGroupClass) ?>" id="week<?= $weekNum ?>">
                    <div class="week-group-summary broadcast-list-row no-staff">
                        <div class="broadcast-when">
                            <div class="broadcast-week-heading">
                                <span class="broadcast-week">Week <?= $weekNum ?></span>
                                <?php if ($matchNoteLabel !== ''): ?>
                                    <span class="broadcast-match-phase"><?= fsl_schedule_h($matchNoteLabel) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="match-status <?= fsl_schedule_h($matchStatus) ?>"><?= fsl_schedule_h(ucfirst(str_replace('_', ' ', $matchStatus))) ?></div>
                        </div>
                        <div class="broadcast-teams">
                            <div class="broadcast-team">
                                <?php if ($team1Logo): ?>
                                    <?php if ($team1_is_placeholder): ?>
                                        <img src="<?= fsl_schedule_h($team1Logo) ?>" alt="" class="broadcast-team-logo">
                                    <?php else: ?>
                                        <a href="view_team.php?name=<?= urlencode($team1_name) ?>"><img src="<?= fsl_schedule_h($team1Logo) ?>" alt="" class="broadcast-team-logo"></a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <div>
                                    <?php if ($team1_is_placeholder): ?>
                                        <span class="broadcast-team-name"><?= fsl_schedule_h($team1_name) ?></span>
                                    <?php else: ?>
                                        <a class="broadcast-team-name" href="view_team.php?name=<?= urlencode($team1_name) ?>"><?= fsl_schedule_h($team1_name) ?></a>
                                        <span class="team-record"><?= getTeamRecord($team1_name, $standings) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="broadcast-vs"><?= $hasScore ? ((int) $match['team1_score'] . ' – ' . (int) $match['team2_score']) : 'vs' ?></div>
                            <div class="broadcast-team">
                                <?php if ($team2Logo): ?>
                                    <?php if ($team2_is_placeholder): ?>
                                        <img src="<?= fsl_schedule_h($team2Logo) ?>" alt="" class="broadcast-team-logo">
                                    <?php else: ?>
                                        <a href="view_team.php?name=<?= urlencode($team2_name) ?>"><img src="<?= fsl_schedule_h($team2Logo) ?>" alt="" class="broadcast-team-logo"></a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <div>
                                    <?php if ($team2_is_placeholder): ?>
                                        <span class="broadcast-team-name"><?= fsl_schedule_h($team2_name) ?></span>
                                    <?php else: ?>
                                        <a class="broadcast-team-name" href="view_team.php?name=<?= urlencode($team2_name) ?>"><?= fsl_schedule_h($team2_name) ?></a>
                                        <span class="team-record"><?= getTeamRecord($team2_name, $standings) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="week-group-body">
                        <table class="slot-table">
                            <colgroup>
                                <col class="col-slot-day">
                                <col class="col-slot-when">
                                <?php if ($showCrewColumn): ?><col class="col-slot-crew"><?php endif; ?>
                            </colgroup>
                            <thead>
                                <tr>
                                    <th scope="col">Day</th>
                                    <th scope="col">On air</th>
                                    <?php if ($showCrewColumn): ?><th scope="col">Crew</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($listSlots as $broadcastRow):
                                    $slotPlayWindow = ($broadcastRow === null) ? $playWindow : null;
                                    $crewSource = $broadcastRow ?? $match;
                                    $slotCancelled = $broadcastRow !== null && (int) ($broadcastRow['cancelled'] ?? 0) === 1;
                                    $crewHtml = ($staffSchema && !$slotCancelled && ($broadcastRow !== null || $playWindow === null))
                                        ? fsl_schedule_crew_html($crewSource, $defaultProduction, $editMode)
                                        : '';
                                    $dayLabel = 'Broadcast';
                                    if ($broadcastRow !== null) {
                                        $dayLabel = trim((string) ($broadcastRow['slot_label'] ?? '')) ?: 'Broadcast';
                                    } elseif ($slotPlayWindow) {
                                        $dayLabel = 'Window';
                                    }
                                ?>
                                <tr class="slot-table-row<?= $slotCancelled ? ' slot-cancelled' : '' ?>">
                                    <td class="slot-day" data-label="Day">
                                        <span class="slot-day-badge"><?= fsl_schedule_h($dayLabel) ?></span>
                                    </td>
                                    <td class="slot-when" data-label="On air">
                                        <?php if ($slotCancelled): ?>
                                            <span class="text-muted">No stream</span>
                                        <?php elseif ($broadcastRow !== null && !empty($broadcastRow['broadcast_at'])): ?>
                                            <?php $slotDt = new DateTime($broadcastRow['broadcast_at']); ?>
                                            <span class="slot-date"><?= fsl_schedule_h($slotDt->format('F j, Y')) ?></span>
                                            <span class="slot-time"><?= fsl_schedule_h($slotDt->format('g:i A')) ?> <span class="timezone">US Eastern</span></span>
                                        <?php elseif ($slotPlayWindow): ?>
                                            <span class="slot-date"><?= fsl_schedule_h($slotPlayWindow['fri_label']) ?> · <?= fsl_schedule_h($slotPlayWindow['sat_label']) ?></span>
                                            <span class="slot-time">7:00 PM Friday · 12:00 PM Saturday <span class="timezone">US Eastern</span></span>
                                        <?php elseif (!empty($match['match_date'])): ?>
                                            <?php $matchDateTime = new DateTime($match['match_date']); ?>
                                            <span class="slot-date"><?= fsl_schedule_h($matchDateTime->format('l, F j, Y')) ?></span>
                                            <span class="slot-time"><?= fsl_schedule_h($matchDateTime->format('g:i A')) ?> <span class="timezone">US Eastern</span></span>
                                        <?php else: ?>
                                            <span class="slot-date">TBD</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($showCrewColumn): ?>
                                    <td class="slot-crew" data-label="Crew">
                                        <?php if ($slotPlayWindow && $broadcastRow === null): ?>
                                            <span class="broadcast-staff-empty">Play window only (no per-slot crew)</span>
                                        <?php elseif ($crewHtml !== ''): ?>
                                            <div class="broadcast-staff slot-crew-staff"><?= $crewHtml ?></div>
                                        <?php else: ?>
                                            <span class="broadcast-staff-empty"><?= $slotCancelled ? '—' : 'Staff not set' ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if ($editMode && $staffSchema): ?>
                        <div class="week-group-edit">
                            <?php fsl_schedule_render_broadcast_form($match, $scheduleTeams, $registeredPlayers, $productionRoster, $defaultProduction, fsl_schedule_edit_token(), $scheduleLayout); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <?php foreach ($season9Schedule as $match): 
                $matchIds = getScheduleMatchIds($match['schedule_id']);
                

            ?>
                <div class="team-match-card" id="week<?= $match['week_number'] ?>">
                    <div class="match-header">
                        <div class="week-info">
                            <h3>Week <?= $match['week_number'] ?></h3>
                            <?php $playWindow = formatSchedulePlayWindow($match['season'], $match['match_date']); ?>
                            <?php if ($playWindow): ?>
                            <p class="match-date play-window">
                                <span class="play-window-label">Play window · <span class="timezone">US Eastern</span></span>
                                <span class="play-window-times">Friday and/or Saturday</span>
                                <span class="play-window-times-detail">7:00 PM Friday · 12:00 PM Saturday</span>
                                <span class="play-window-days"><?= htmlspecialchars($playWindow['fri_label']) ?> · <?= htmlspecialchars($playWindow['sat_label']) ?></span>
                            </p>
                            <?php else: ?>
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
                            <?php endif; ?>
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

                    <?php
                    $cardNoteLabel = fsl_schedule_match_note_label($match);
                    if ($cardNoteLabel !== ''):
                    ?>
                        <div class="match-notes">
                            <em><?= htmlspecialchars($cardNoteLabel) ?></em>
                        </div>
                    <?php endif; ?>

                    <?php if ($staffSchema): ?>
                        <?php if (fsl_schedule_match_uses_broadcast_slots($match)): ?>
                            <?php foreach ($match['broadcasts'] as $broadcastRow): ?>
                                <?php if ((int) ($broadcastRow['cancelled'] ?? 0) === 1) {
                                    continue;
                                } ?>
                                <?php $slotCrew = fsl_schedule_crew_html($broadcastRow, $defaultProduction, $editMode); ?>
                                <?php if ($slotCrew !== '' || $editMode): ?>
                                <div class="broadcast-crew broadcast-slot-crew">
                                    <?php if (!empty($broadcastRow['broadcast_at']) || trim((string) ($broadcastRow['slot_label'] ?? '')) !== ''): ?>
                                    <div class="broadcast-slot-heading">
                                        <?php
                                        $slotLabel = trim((string) ($broadcastRow['slot_label'] ?? ''));
                                        if (!empty($broadcastRow['broadcast_at'])) {
                                            $slotDt = new DateTime($broadcastRow['broadcast_at']);
                                            echo fsl_schedule_h($slotLabel !== '' ? $slotLabel . ' · ' : '');
                                            echo fsl_schedule_h($slotDt->format('l, F j, Y \a\t g:i A'));
                                            echo ' <span class="timezone">US Eastern</span>';
                                        } else {
                                            echo fsl_schedule_h($slotLabel !== '' ? $slotLabel : 'Broadcast');
                                        }
                                        ?>
                                    </div>
                                    <?php endif; ?>
                                    <?= $slotCrew ?>
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($editMode): ?>
                                <?php fsl_schedule_render_broadcast_form($match, $scheduleTeams, $registeredPlayers, $productionRoster, $defaultProduction, fsl_schedule_edit_token(), $scheduleLayout); ?>
                            <?php endif; ?>
                        <?php else: ?>
                        <?php $crewHtml = fsl_schedule_crew_html($match, $defaultProduction, $editMode); ?>
                        <?php if ($crewHtml !== '' || $editMode): ?>
                        <div class="broadcast-crew">
                            <?= $crewHtml ?>
                            <?php if ($editMode): ?>
                                <?php fsl_schedule_render_broadcast_form($match, $scheduleTeams, $registeredPlayers, $productionRoster, $defaultProduction, fsl_schedule_edit_token(), $scheduleLayout); ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($matchIds) || !empty($match['team_2v2_results'])): ?>
                        <div class="individual-matches">
                            <h5>Individual Match Results:</h5>
                            <?php 
                            // Show individual matches from database
                            if (!empty($matchIds)) {
                                $matchDetails = getMatchDetails($matchIds);
                                
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
                                $matchDetails = getMatchDetails($matchIds);
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
            <?php endif; ?>
            <?php if ($editMode && $staffSchema): ?>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="staffIntroModal" class="staff-intro-modal" hidden>
    <div class="staff-intro-backdrop" data-intro-close="1"></div>
    <div class="staff-intro-dialog" role="dialog" aria-modal="true" aria-labelledby="staffIntroTitle">
        <div class="staff-intro-bar">
            <span id="staffIntroTitle">Intro</span>
            <button type="button" class="staff-intro-close" data-intro-close="1">Close</button>
        </div>
        <div class="staff-intro-stage">
            <video id="staffIntroVideo" muted playsinline></video>
            <canvas id="staffIntroCanvas"></canvas>
        </div>
    </div>
</div>

<script>
// Rewrite legacy hash links like #week1&season9 → ?season=9#week1
(function () {
    var hash = window.location.hash.replace(/^#/, '');
    if (!hash) return;
    var parts = hash.split('&');
    var weekPart = null, seasonPart = null;
    parts.forEach(function (p) {
        if (/^week\d+$/.test(p)) weekPart = p;
        if (/^season\d+$/.test(p)) seasonPart = p;
    });
    if (seasonPart) {
        var seasonNum = seasonPart.replace('season', '');
        var url = '?season=' + seasonNum + (weekPart ? '#' + weekPart : '');
        window.location.replace(url);
    }
})();

(function () {
    var modal = document.getElementById('staffIntroModal');
    var video = document.getElementById('staffIntroVideo');
    var canvas = document.getElementById('staffIntroCanvas');
    var title = document.getElementById('staffIntroTitle');
    if (!modal || !video || !canvas) return;
    var ctx = canvas.getContext('2d');
    var generation = 0;
    var threshold = 100;
    var smoothing = 10;

    function closeIntro() {
        generation += 1;
        video.pause();
        video.removeAttribute('src');
        video.load();
        modal.hidden = true;
    }

    function draw(gen) {
        if (gen !== generation) return;
        if (!video.paused && !video.ended && video.readyState >= 2) {
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var data = imageData.data;
            for (var i = 0; i < data.length; i += 4) {
                var r = data[i];
                var g = data[i + 1];
                var b = data[i + 2];
                if (g > r + threshold && g > b + threshold) {
                    data[i + 3] = 0;
                } else {
                    var greenDominance = g - Math.max(r, b);
                    if (greenDominance > 0) {
                        data[i + 3] = Math.max(0, 255 - (greenDominance * (255 / smoothing)));
                    }
                }
            }
            ctx.putImageData(imageData, 0, 0);
        }
        requestAnimationFrame(function () { draw(gen); });
    }

    function openIntro(url, name) {
        if (!url) return;
        generation += 1;
        var gen = generation;
        title.textContent = name || 'Intro';
        modal.hidden = false;
        video.onloadedmetadata = function () {
            if (gen !== generation) return;
            canvas.width = video.videoWidth || 640;
            canvas.height = video.videoHeight || 360;
            video.play();
            draw(gen);
        };
        video.src = url;
        video.load();
    }

    document.addEventListener('click', function (event) {
        var opener = event.target.closest ? event.target.closest('.staff-portrait-btn') : null;
        if (opener) {
            event.preventDefault();
            openIntro(opener.getAttribute('data-intro-url'), opener.getAttribute('data-intro-name'));
            return;
        }
        var closer = event.target.closest ? event.target.closest('[data-intro-close]') : null;
        if (closer) closeIntro();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) closeIntro();
    });
})();
</script>

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
    
    /* ── Schedule Navigation ── */
    .schedule-nav {
        background: rgba(0, 0, 0, 0.45);
        border: 1px solid rgba(0, 212, 255, 0.25);
        border-radius: 12px;
        padding: 16px 22px 18px;
        margin-bottom: 28px;
        position: sticky;
        top: 16px;
        z-index: 100;
        box-shadow: 0 6px 24px rgba(0, 0, 0, 0.35);
        backdrop-filter: blur(6px);
    }

    .schedule-nav-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 18px;
        flex-wrap: wrap;
    }

    /* Season picker */
    .season-picker-wrap {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .season-picker-label {
        color: #aaa;
        font-size: 0.78em;
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .season-select-box {
        position: relative;
        display: inline-flex;
        align-items: center;
    }

    .season-select-box select {
        appearance: none;
        -webkit-appearance: none;
        background: rgba(0, 212, 255, 0.1);
        border: 1px solid rgba(0, 212, 255, 0.35);
        border-radius: 8px;
        color: #00d4ff;
        font-size: 0.95em;
        font-weight: 700;
        padding: 7px 34px 7px 14px;
        cursor: pointer;
        outline: none;
        transition: background 0.2s, border-color 0.2s;
        min-width: 180px;
    }

    .season-select-box select:hover,
    .season-select-box select:focus {
        background: rgba(0, 212, 255, 0.18);
        border-color: rgba(0, 212, 255, 0.65);
    }

    .season-select-box select option {
        background: #1a1a2e;
        color: #e0e0e0;
        font-weight: 400;
    }

    .select-arrow {
        position: absolute;
        right: 11px;
        color: #00d4ff;
        font-size: 0.7em;
        pointer-events: none;
        opacity: 0.8;
    }

    /* Next match button */
    .btn-next-match {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        background: linear-gradient(135deg, #28a745, #20c997);
        color: white;
        padding: 8px 18px;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 700;
        font-size: 0.95em;
        white-space: nowrap;
        transition: all 0.25s ease;
        box-shadow: 0 2px 10px rgba(40, 167, 69, 0.35);
    }

    .btn-next-match:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 16px rgba(40, 167, 69, 0.55);
        color: white;
        text-decoration: none;
    }

    .btn-next-icon {
        font-size: 0.8em;
    }

    /* Week stepper */
    .week-stepper {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0;
        overflow-x: auto;
        padding: 4px 2px 6px;
        scrollbar-width: none;
    }

    .week-stepper::-webkit-scrollbar { display: none; }

    .step-connector {
        flex: 1;
        height: 2px;
        background: rgba(255, 255, 255, 0.12);
        min-width: 12px;
        max-width: 48px;
        transition: background 0.3s;
    }

    .step-connector.done {
        background: rgba(40, 167, 69, 0.55);
    }

    .step {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 5px;
        text-decoration: none;
        flex-shrink: 0;
        transition: transform 0.2s;
    }

    .step:hover { transform: translateY(-3px); text-decoration: none; }

    .step-bubble {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.9em;
        border: 2px solid transparent;
        transition: all 0.25s ease;
    }

    .step.done .step-bubble {
        background: rgba(40, 167, 69, 0.25);
        border-color: #28a745;
        color: #28a745;
    }

    .step.done:hover .step-bubble {
        background: rgba(40, 167, 69, 0.45);
        box-shadow: 0 0 12px rgba(40, 167, 69, 0.5);
    }

    .step.next .step-bubble {
        background: rgba(0, 212, 255, 0.2);
        border-color: #00d4ff;
        color: #00d4ff;
        box-shadow: 0 0 10px rgba(0, 212, 255, 0.4);
        animation: pulse-next 2s ease-in-out infinite;
    }

    @keyframes pulse-next {
        0%, 100% { box-shadow: 0 0 8px rgba(0, 212, 255, 0.4); }
        50%       { box-shadow: 0 0 18px rgba(0, 212, 255, 0.75); }
    }

    .step.upcoming .step-bubble {
        background: rgba(255, 255, 255, 0.04);
        border-color: rgba(255, 255, 255, 0.18);
        color: #888;
    }

    .step.upcoming:hover .step-bubble {
        border-color: rgba(0, 212, 255, 0.4);
        color: #aaa;
    }

    .step-label {
        font-size: 0.68em;
        font-weight: 600;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .step.done     .step-label { color: #28a745; }
    .step.next     .step-label { color: #00d4ff; }
    .step.upcoming .step-label { color: #555; }
    
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

    .standings .map-wins {
        color: #ffc107;
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
        background: linear-gradient(135deg, rgba(0, 212, 255, 0.08), rgba(0, 0, 0, 0.2));
        border: 1px solid rgba(0, 212, 255, 0.2);
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 35px;
    }
    
    .season-info h2 {
        color: #00d4ff;
        text-align: center;
        margin: 0 0 20px 0;
        font-size: 1.5em;
    }

    .season-play-window-note {
        text-align: center;
        margin: 0 0 18px 0;
        color: #ccc;
        line-height: 1.5;
    }

    .season-play-window-note strong {
        color: #00d4ff;
    }
    
    .season-format-content {
        display: flex;
        align-items: flex-start;
        gap: 30px;
        flex-wrap: wrap;
    }
    
    .season-format-image-wrap {
        flex-shrink: 0;
        display: block;
        border-radius: 10px;
        overflow: hidden;
        border: 2px solid rgba(0, 212, 255, 0.3);
        transition: box-shadow 0.3s ease, transform 0.3s ease;
    }
    
    .season-format-image-wrap:hover {
        box-shadow: 0 0 20px rgba(0, 212, 255, 0.4);
        transform: scale(1.02);
    }
    
    .season-format-image {
        display: block;
        max-width: 300px;
        height: auto;
    }
    
    .season-format-text {
        flex: 1;
        min-width: 280px;
    }
    
    .season-format-text p {
        margin: 0 0 12px 0;
        line-height: 1.6;
        color: #e0e0e0;
    }
    
    .season-format-text p:last-child {
        margin-bottom: 0;
    }
    
    .season-format-text strong {
        color: #00d4ff;
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

    .match-date.play-window {
        display: flex;
        flex-direction: column;
        gap: 4px;
        line-height: 1.45;
    }

    .play-window-label {
        color: #00d4ff;
        font-size: 0.85em;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .play-window-times {
        color: #e8e8e8;
        font-weight: 600;
    }

    .play-window-times-detail {
        color: #bbb;
        font-size: 0.92em;
    }

    .play-window-days {
        color: #aaa;
        font-size: 0.95em;
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
        
        .schedule-nav {
            padding: 12px 14px 14px;
            position: relative;
            top: 0;
        }

        .schedule-nav-top {
            margin-bottom: 14px;
        }

        .season-select-box select {
            min-width: 0;
            width: 100%;
            font-size: 0.9em;
        }

        .season-picker-wrap {
            flex: 1;
            min-width: 0;
        }

        .btn-next-match {
            padding: 8px 14px;
            font-size: 0.9em;
        }

        .step-bubble {
            width: 30px;
            height: 30px;
            font-size: 0.8em;
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
        
        .season-format-content {
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }
        
        .season-format-image {
            max-width: 100%;
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
        
        .schedule-nav {
            padding: 10px 10px 12px;
        }

        .step-bubble {
            width: 26px;
            height: 26px;
            font-size: 0.75em;
        }

        .step-label {
            font-size: 0.6em;
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
        
        .season-format-content {
            flex-direction: column;
            gap: 15px;
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

    .schedule-view-toggle {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    .schedule-view-link {
        display: inline-block;
        color: #00d4ff;
        border: 1px solid rgba(0, 212, 255, 0.35);
        background: rgba(0, 212, 255, 0.08);
        border-radius: 8px;
        padding: 7px 12px;
        font-size: 0.85em;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
    }

    .schedule-view-link:hover,
    .schedule-view-link.active {
        color: #041018;
        background: #00d4ff;
        text-decoration: none;
    }

    button.schedule-save-btn {
        appearance: none;
        cursor: pointer;
        font-family: inherit;
        background: linear-gradient(135deg, #28a745, #20c997);
        color: #fff;
        border-color: transparent;
    }

    button.schedule-save-btn:hover {
        color: #fff;
        background: linear-gradient(135deg, #34d058, #20c997);
    }

    fieldset.broadcast-edit-form {
        border: 0;
        margin: 0;
        padding: 0;
        min-width: 0;
    }

    fieldset.matchup-edit-form,
    fieldset.broadcast-slot-edit-form {
        border: 1px solid rgba(0, 212, 255, 0.25);
        border-radius: 8px;
        padding: 12px 14px;
        margin: 10px 0 0;
    }

    fieldset.matchup-edit-form legend,
    fieldset.broadcast-slot-edit-form legend {
        color: #00d4ff;
        font-size: 0.95rem;
        width: auto;
        padding: 0 6px;
    }

    .broadcast-slot-heading {
        color: #aaa;
        font-size: 0.9rem;
        margin-bottom: 8px;
    }

    .broadcast-slot-crew + .broadcast-slot-crew {
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px solid rgba(255, 255, 255, 0.08);
    }

    .broadcast-list {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .broadcast-list-grouped {
        gap: 16px;
    }

    .week-group {
        border: 1px solid rgba(0, 212, 255, 0.2);
        border-radius: 10px;
        overflow: hidden;
        background: rgba(0, 0, 0, 0.3);
        scroll-margin-top: 20px;
    }

    .week-group-summary.broadcast-list-row {
        border: 0;
        border-radius: 0;
        margin: 0;
        border-bottom: 1px solid rgba(0, 212, 255, 0.15);
    }

    .week-group-summary .broadcast-week-heading {
        display: flex;
        flex-wrap: wrap;
        align-items: baseline;
        gap: 4px 8px;
        line-height: 1.25;
    }

    .week-group-summary .broadcast-match-phase {
        color: #aaa;
        font-size: 0.95em;
        font-weight: 600;
    }

    .week-group-summary .broadcast-match-phase::before {
        content: '·';
        margin-right: 8px;
        color: #666;
        font-weight: 400;
    }

    .week-group-summary .broadcast-when .match-status {
        display: inline-block;
        margin-top: 8px;
        font-size: 0.75em;
    }

    .week-group-summary .broadcast-teams {
        display: flex;
        flex-wrap: nowrap;
        align-items: center;
        justify-content: center;
        gap: 12px 20px;
        align-self: center;
        width: 100%;
        min-height: 64px;
    }

    .week-group-summary .broadcast-team {
        flex: 1 1 0;
        min-width: 0;
        align-items: center;
    }

    .week-group-summary .broadcast-team:first-child {
        justify-content: flex-end;
    }

    .week-group-summary .broadcast-team:last-child {
        justify-content: flex-start;
    }

    .week-group-summary .broadcast-team > a {
        line-height: 0;
        flex-shrink: 0;
    }

    .week-group-summary .broadcast-team > div {
        display: flex;
        flex-direction: column;
        justify-content: center;
        min-width: 0;
    }

    .week-group-summary .broadcast-vs {
        flex: 0 0 auto;
        align-self: center;
        line-height: 1;
        padding: 0 6px;
    }

    .broadcast-list-grouped .slot-table {
        table-layout: auto;
        width: 100%;
    }

    .slot-table {
        width: 100%;
        margin: 0;
        border-collapse: collapse;
    }

    .slot-table th {
        text-align: left;
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #888;
        padding: 8px 16px;
        background: rgba(0, 0, 0, 0.2);
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }

    .slot-table td {
        padding: 10px 16px;
        vertical-align: middle;
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }

    .broadcast-list-grouped .col-slot-day {
        width: 1%;
        white-space: nowrap;
    }

    .broadcast-list-grouped .col-slot-when {
        width: auto;
    }

    .broadcast-list-grouped .col-slot-crew {
        width: 1%;
        white-space: nowrap;
    }

    .broadcast-list-grouped .slot-crew {
        width: 1%;
        white-space: nowrap;
    }

    .slot-table tr:last-child td {
        border-bottom: 0;
    }

    .slot-day-badge {
        display: inline-block;
        background: rgba(0, 212, 255, 0.15);
        color: #00d4ff;
        padding: 3px 10px;
        border-radius: 6px;
        font-size: 0.85rem;
        font-weight: 700;
    }

    .slot-cancelled .slot-day-badge {
        opacity: 0.55;
    }

    .broadcast-list-grouped .slot-when {
        display: flex;
        flex-wrap: nowrap;
        align-items: baseline;
        justify-content: flex-start;
        gap: 0 10px;
        width: 100%;
        min-width: 260px;
    }

    .broadcast-list-grouped .slot-when .slot-date {
        color: #bbb;
        font-size: 0.98em;
        font-weight: 500;
        flex: 0 1 auto;
        min-width: 0;
    }

    .broadcast-list-grouped .slot-when .slot-date::after {
        content: ' ·';
        color: #666;
        font-weight: 400;
    }

    .broadcast-list-grouped .slot-when .slot-time {
        color: #fff;
        font-size: 1.08em;
        font-weight: 700;
        flex: 0 0 auto;
        text-align: left;
        white-space: nowrap;
    }

    .slot-when .slot-date {
        display: block;
        color: #ccc;
    }

    .slot-when .slot-time {
        display: block;
        color: #fff;
        font-weight: 600;
        margin-top: 2px;
    }

    .broadcast-list-grouped .slot-table th:last-child {
        text-align: right;
    }

    .slot-crew {
        text-align: right;
    }

    .slot-crew-staff.broadcast-staff {
        flex-direction: row;
        flex-wrap: nowrap;
        align-items: center;
        justify-content: flex-end;
        gap: 8px 14px;
        margin-left: auto;
    }

    .slot-crew-staff .staff-chip {
        min-width: 0;
        gap: 6px;
        flex: 0 1 auto;
    }

    .slot-crew-staff .staff-portrait,
    .slot-crew-staff .staff-tbd-mark {
        width: 28px;
        height: 28px;
        border-width: 1px;
    }

    .slot-crew-staff .staff-role {
        font-size: 0.62em;
        line-height: 1.1;
    }

    .slot-crew-staff .staff-name {
        font-size: 0.82em;
        line-height: 1.15;
    }

    .slot-crew .broadcast-staff-empty {
        font-size: 0.85em;
    }

    .week-group-edit {
        padding: 12px 16px 16px;
        border-top: 1px dashed rgba(0, 212, 255, 0.25);
        background: rgba(0, 0, 0, 0.15);
    }

    .week-group-editing .week-group-edit .broadcast-edit-form {
        width: 100%;
    }

    @media (max-width: 768px) {
        .broadcast-list-grouped .week-group-summary.broadcast-list-row {
            grid-template-columns: 1fr;
            gap: 12px;
            padding: 12px 14px;
        }

        .broadcast-list-grouped .week-group-summary .broadcast-when {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 8px 12px;
            width: 100%;
        }

        .broadcast-list-grouped .week-group-summary .broadcast-when .match-status {
            margin-top: 0;
        }

        .broadcast-list-grouped .week-group-summary .broadcast-teams {
            flex-direction: column;
            align-items: stretch;
            gap: 6px;
            min-height: 0;
            width: 100%;
        }

        .broadcast-list-grouped .week-group-summary .broadcast-team,
        .broadcast-list-grouped .week-group-summary .broadcast-team:first-child,
        .broadcast-list-grouped .week-group-summary .broadcast-team:last-child {
            flex: 0 0 auto;
            justify-content: center;
        }

        .broadcast-list-grouped .week-group-summary .broadcast-vs {
            align-self: center;
            padding: 2px 0;
        }

        .broadcast-list-grouped .week-group-summary .broadcast-team-logo {
            width: 52px;
            height: 52px;
        }

        .broadcast-list-grouped .week-group-summary .broadcast-team-name {
            font-size: 0.95em;
        }

        .broadcast-list-grouped .slot-table,
        .broadcast-list-grouped .slot-table tbody {
            display: block;
            width: 100%;
        }

        .broadcast-list-grouped .slot-table colgroup {
            display: none;
        }

        .broadcast-list-grouped .slot-table thead {
            display: none;
        }

        .broadcast-list-grouped .slot-table tr {
            display: block;
            width: 100%;
            box-sizing: border-box;
            padding: 10px 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        .broadcast-list-grouped .slot-table tr:last-child {
            border-bottom: 0;
        }

        .broadcast-list-grouped .slot-table td {
            display: block;
            width: 100%;
            max-width: none;
            border: 0;
            padding: 0;
            box-sizing: border-box;
        }

        .broadcast-list-grouped .slot-table td::before {
            content: attr(data-label);
            display: block;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #777;
            margin-bottom: 4px;
        }

        .broadcast-list-grouped .slot-table td.slot-day,
        .broadcast-list-grouped .slot-table td.slot-when {
            display: block;
        }

        .broadcast-list-grouped .slot-table td.slot-day::before,
        .broadcast-list-grouped .slot-table td.slot-when::before {
            display: none;
        }

        .broadcast-list-grouped .slot-table td.slot-day {
            margin-bottom: 4px;
        }

        .broadcast-list-grouped .slot-when {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            justify-content: flex-start;
            gap: 0 8px;
            width: 100%;
        }

        .broadcast-list-grouped .slot-when .slot-date {
            font-size: 0.92em;
            flex: 0 1 auto;
            min-width: 0;
        }

        .broadcast-list-grouped .slot-when .slot-time {
            margin-top: 0;
            text-align: left;
            font-size: 1em;
            flex: 0 0 auto;
            white-space: nowrap;
        }

        .broadcast-list-grouped .slot-table td.slot-crew {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            text-align: left;
        }

        .broadcast-list-grouped .col-slot-crew,
        .broadcast-list-grouped .slot-crew {
            width: 100%;
            white-space: normal;
        }

        .broadcast-list-grouped .slot-crew-staff.broadcast-staff {
            flex-wrap: wrap;
            justify-content: flex-start;
            margin-left: 0;
            gap: 6px 10px;
        }

        .broadcast-list-grouped .slot-when {
            min-width: 0;
        }
    }

    .broadcast-list-row {
        display: grid;
        grid-template-columns: minmax(180px, 230px) minmax(240px, 1fr) minmax(150px, 200px);
        gap: 14px 18px;
        align-items: center;
        background: rgba(0, 0, 0, 0.3);
        border: 1px solid rgba(0, 212, 255, 0.2);
        border-radius: 10px;
        padding: 16px;
        scroll-margin-top: 20px;
    }

    .broadcast-list-row.is-editing {
        grid-template-columns: 1fr;
        align-items: start;
    }

    .broadcast-list-row.no-staff {
        grid-template-columns: minmax(180px, 240px) minmax(240px, 1fr);
    }

    .broadcast-week {
        color: #00d4ff;
        font-weight: 700;
        font-size: 1.15em;
    }

    .broadcast-date,
    .broadcast-time,
    .broadcast-notes,
    .broadcast-staff-empty {
        color: #ccc;
    }

    .broadcast-time {
        margin-bottom: 8px;
    }

    .broadcast-teams {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
        gap: 8px 12px;
        align-items: center;
    }

    .broadcast-team {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }

    .broadcast-team-logo {
        width: 64px;
        height: 64px;
        border-radius: 10px;
        object-fit: cover;
        border: 2px solid rgba(0, 212, 255, 0.35);
        flex-shrink: 0;
    }

    .broadcast-team-name {
        color: #00d4ff;
        font-weight: 700;
        text-decoration: none;
        display: block;
    }

    a.broadcast-team-name:hover {
        color: #fff;
        text-decoration: none;
    }

    .broadcast-vs {
        color: #fff;
        font-weight: 700;
        text-align: center;
    }

    .broadcast-notes {
        grid-column: 1 / -1;
        font-size: 0.9em;
    }

    .broadcast-staff,
    .broadcast-crew,
    .production-roster-list {
        display: flex;
        flex-wrap: wrap;
        gap: 12px 16px;
        align-items: center;
    }

    .broadcast-staff {
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
    }

    .broadcast-staff .staff-chip {
        min-width: 0;
        gap: 6px;
    }

    .broadcast-staff .staff-portrait {
        width: 28px;
        height: 28px;
        border-width: 1px;
    }

    .broadcast-staff .staff-role {
        font-size: 0.62em;
        line-height: 1.1;
    }

    .broadcast-staff .staff-name {
        font-size: 0.82em;
        line-height: 1.15;
    }

    .broadcast-crew {
        margin-top: 16px;
        padding-top: 14px;
        border-top: 1px solid rgba(0, 212, 255, 0.15);
    }

    .broadcast-crew .broadcast-edit-form,
    .broadcast-staff .broadcast-edit-form {
        flex: 1 1 100%;
    }

    .staff-chip {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 140px;
    }

    .staff-portrait {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid rgba(0, 212, 255, 0.45);
        background: #111;
    }

    .staff-portrait-btn {
        padding: 0;
        border: 0;
        background: transparent;
        line-height: 0;
        cursor: pointer;
        border-radius: 50%;
    }

    .staff-portrait-btn:hover .staff-portrait,
    .staff-chip a:hover .staff-portrait {
        border-color: #00d4ff;
        box-shadow: 0 0 12px rgba(0, 212, 255, 0.45);
    }

    .staff-role {
        display: block;
        color: #8fdfff;
        font-size: 0.72em;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .staff-default {
        color: #ffc107;
        text-transform: none;
        letter-spacing: 0;
        font-weight: 600;
    }

    .staff-name {
        color: #fff;
        font-weight: 700;
        text-decoration: none;
    }

    .staff-name:hover {
        color: #00d4ff;
        text-decoration: none;
    }

    .staff-chip-tbd .staff-name {
        color: #ffc107;
    }

    .staff-tbd-mark {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #ffc107;
        font-size: 0.85em;
        font-weight: 700;
        border-style: dashed;
        border-color: rgba(255, 193, 7, 0.8);
    }

    .production-roster h3 {
        color: #00d4ff;
        margin: 0 0 8px 0;
        font-size: 1.3em;
    }

    .production-roster-note {
        color: #bbb;
        margin-bottom: 12px;
    }

    .production-roster-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex: 1 1 320px;
        padding: 8px 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }

    .production-roster-actions {
        display: flex;
        gap: 8px;
        flex-shrink: 0;
    }

    .production-add-form {
        margin-top: 14px;
    }

    .broadcast-edit-form label {
        color: #9adcf0;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .staff-intro-modal[hidden] {
        display: none;
    }

    .staff-intro-modal {
        position: fixed;
        inset: 0;
        z-index: 2000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .staff-intro-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, 0.72);
    }

    .staff-intro-dialog {
        position: relative;
        width: min(720px, 100%);
        background: #0c1020;
        border: 1px solid rgba(0, 212, 255, 0.35);
        border-radius: 12px;
        overflow: hidden;
    }

    .staff-intro-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 14px;
        color: #00d4ff;
        font-weight: 700;
    }

    .staff-intro-close {
        background: transparent;
        color: #fff;
        border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 6px;
        padding: 4px 10px;
    }

    .staff-intro-stage {
        position: relative;
        height: 320px;
        background: #000;
    }

    #staffIntroVideo {
        display: none;
    }

    #staffIntroCanvas {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    @media (max-width: 800px) {
        .broadcast-list-row:not(.week-group-summary),
        .broadcast-list-row.no-staff:not(.week-group-summary) {
            grid-template-columns: 1fr;
        }

        .broadcast-list-row:not(.week-group-summary) .broadcast-teams {
            grid-template-columns: 1fr;
        }

        .broadcast-vs {
            text-align: left;
        }

        .schedule-nav-top {
            align-items: stretch;
        }
    }
</style>

<?php include 'includes/footer.php'; ?> 