<?php
/**
 * Player Statistics Page
 * Displays comprehensive player statistics including aliases, race, division, and team information
 */

// Start session
session_start();

// Include database connection
require_once 'includes/db.php';

// Include the new JSON processing file
require_once 'includes/championship_json_processor.php';

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get URL parameters for filtering
$playerFilter = isset($_GET['player']) ? htmlspecialchars($_GET['player']) : '';
$divisionFilter = isset($_GET['division']) ? htmlspecialchars($_GET['division']) : 'all';
$raceFilter = isset($_GET['race']) ? htmlspecialchars($_GET['race']) : 'all';
$teamFilter = isset($_GET['team']) ? htmlspecialchars($_GET['team']) : 'all';

// Add sorting parameter to URL parameters
$sortField = isset($_GET['sort']) ? htmlspecialchars($_GET['sort']) : 'MapsW';
$sortDirection = isset($_GET['dir']) ? htmlspecialchars($_GET['dir']) : 'desc';

// Adjust sortField for SQL query if necessary
$validSortFields = ['Player_Name', 'Alias_Name', 'Division', 'Race', 'MapsW', 'SetsW', 'Current_Team_Name'];
if (!in_array($sortField, $validSortFields)) {
    $sortField = 'MapsW'; // Default to MapsW if invalid
}

// Get player statistics with the specified query
$playerStatsQuery = "
    SELECT 
        p.Player_ID,
        p.Real_Name AS Player_Name,
        a.Alias_ID,
        a.Alias_Name,
        s.Division,
        s.Race,
        s.MapsW,
        s.MapsL,
        s.SetsW,
        s.SetsL,
        t.Team_ID AS Current_Team_ID,
        t.Team_Name AS Current_Team_Name,
        p.Championship_Record,
        p.TeamLeague_Championship_Record,
        p.Teams_History AS Past_Team_History
    FROM Players p
    LEFT JOIN Player_Aliases a ON p.Player_ID = a.Player_ID
    LEFT JOIN FSL_STATISTICS s ON p.Player_ID = s.Player_ID AND a.Alias_ID = s.Alias_ID
    LEFT JOIN Teams t ON p.Team_ID = t.Team_ID
    ORDER BY $sortField $sortDirection, p.Real_Name, t.Team_Name, s.Division, s.Race
";

$playerStats = $db->query($playerStatsQuery)->fetchAll(PDO::FETCH_ASSOC);

// Calculate additional statistics
foreach ($playerStats as &$player) {
    // Calculate win rates
    $totalMaps = ($player['MapsW'] ?? 0) + ($player['MapsL'] ?? 0);
    $player['MapWinRate'] = $totalMaps > 0 ? round(($player['MapsW'] / $totalMaps) * 100, 1) : 0;
    
    $totalSets = ($player['SetsW'] ?? 0) + ($player['SetsL'] ?? 0);
    $player['SetWinRate'] = $totalSets > 0 ? round(($player['SetsW'] / $totalSets) * 100, 1) : 0;
}

// Set page title
$pageTitle = "Player Statistics";

// Include header
include_once 'includes/header.php';

/**
 * Parse Championship Record JSON into a readable format
 * @param string|null $jsonData The JSON data to parse
 * @return string Formatted championship record
 */
function formatChampionshipRecord($jsonData, $outputMode = 2) {
    echo "<!-- Raw JSON: $jsonData -->"; // Debugging line
    $output = processChampionshipJSON($jsonData, $outputMode);
    echo "<!-- Processed Output: $output -->"; // Debugging line
    return $output;
}

/**
 * Parse Teams History JSON into a readable format
 * @param string|null $jsonData The JSON data to parse
 * @return string Formatted teams history
 */
function formatTeamsHistory($jsonData) {
    if (empty($jsonData) || $jsonData === 'null' || $jsonData === 'None') {
        return 'No team history';
    }
    
    try {
        // Try to decode the JSON
        $data = json_decode($jsonData, true);
        
        // If it's valid JSON, format it nicely
        if (json_last_error() === JSON_ERROR_NONE && !empty($data)) {
            $output = '';
            
            foreach ($data as $season => $team) {
                $output .= "Season " . $season . ": ";
                
                if (is_array($team)) {
                    if (isset($team['name'])) {
                        $output .= $team['name'];
                    } else {
                        $output .= json_encode($team);
                    }
                } else {
                    $output .= $team;
                }
                
                $output .= "\n";
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

// Use the new function for formatting team championship records
function formatTeamChampionshipRecord($jsonData, $outputMode = 2) {
    return processChampionshipJSON($jsonData, $outputMode);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FSL Player Statistics</title>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
            color: #e0e0e0;
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }
        .container {
            max-width: 100%;
            margin: 20px auto;
            padding: 20px;
            overflow-x: auto;
        }
        h1 {
            text-align: center;
            color: #00d4ff;
            text-shadow: 0 0 15px #00d4ff;
            font-size: 2.8em;
            margin-bottom: 40px;
        }
        .stats-container {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
        }
        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        .filter-group label {
            margin-bottom: 5px;
            font-weight: 600;
            color: #00d4ff;
        }
        .filter-group select, .filter-group input {
            padding: 8px 12px;
            border-radius: 4px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            background: rgba(0, 0, 0, 0.3);
            color: #e0e0e0;
        }
        .filter-actions {
            display: flex;
            align-items: flex-end;
            gap: 10px;
        }
        .btn {
            padding: 8px 16px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-clear {
            background-color: #ff6f61;
            color: white;
        }
        .btn-clear:hover {
            background-color: #ff5a4a;
        }
        table {
            width: 100%;
            table-layout: auto;
        }
        th, td {
            padding: 12px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-align: left;
            word-wrap: break-word;
        }
        th {
            background-color: rgba(0, 0, 0, 0.3);
            color: #00d4ff;
            position: sticky;
            top: 0;
            z-index: 10;
            cursor: pointer;
            user-select: none;
        }
        th.sortable {
            padding-right: 25px;
            position: relative;
        }
        th.sortable:after {
            content: "↕";
            position: absolute;
            right: 8px;
            color: rgba(255, 255, 255, 0.5);
        }
        th.sortable.asc:after {
            content: "↑";
            color: #00ff9d;
        }
        th.sortable.desc:after {
            content: "↓";
            color: #00ff9d;
        }
        tr:nth-child(even) {
            background-color: rgba(255, 255, 255, 0.05);
        }
        tr:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        .win-rate {
            font-weight: bold;
        }
        .high-win-rate {
            color: #00ff9d;
        }
        .medium-win-rate {
            color: #ffcc00;
        }
        .low-win-rate {
            color: #ff6f61;
        }
        .team-name {
            font-weight: bold;
            color: #ff6f61;
        }
        .championship-record {
            max-width: 300px;
            padding: 10px;
            font-size: 0.9em;
            line-height: 1.4;
            white-space: normal;
            overflow-wrap: break-word;
            background-color: rgba(0, 0, 0, 0.2);
            border-radius: 5px;
        }
        .table-responsive {
            overflow-x: auto;
            max-width: 100%;
            box-sizing: border-box;
        }
        .race-icon {
            width: 20px;
            height: 20px;
            margin-right: 5px;
            vertical-align: middle;
        }
        .division-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: bold;
        }
        .division-S {
            background-color: #FFD700;
            color: #000;
        }
        .division-A {
            background-color: #C0C0C0;
            color: #000;
        }
        .division-B {
            background-color: #CD7F32;
            color: #000;
        }
        .tooltip {
            position: relative;
            display: inline-block;
            cursor: pointer;
            color: #00d4ff;
            text-decoration: underline;
        }
        .tooltip-content {
            display: none;
            position: absolute;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            background-color: rgba(0, 0, 0, 0.9);
            color: #fff;
            text-align: left;
            border-radius: 6px;
            padding: 10px;
            width: 300px;
            z-index: 1;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5);
            white-space: normal;
            font-size: 0.9em;
            line-height: 1.4;
        }
        .tooltip:hover .tooltip-content {
            display: block;
        }
        
        @media (max-width: 768px) {
            .filters {
                flex-direction: column;
            }
            th, td {
                padding: 8px;
                font-size: 0.9em;
            }
            h1 {
                font-size: 2em;
            }
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
        .team-link {
            color: #00d4ff;
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        .team-link:hover {
            color: #ff6f61;
            text-shadow: 0 0 5px rgba(255, 111, 97, 0.5);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>FSL Player Statistics</h1>
        
        <div class="stats-container">
            <div class="filters">
                <div class="filter-group">
                    <label for="division-filter">Division:</label>
                    <select id="division-filter">
                        <option value="all" <?= $divisionFilter === 'all' ? 'selected' : '' ?>>All Divisions</option>
                        <option value="S" <?= $divisionFilter === 'S' ? 'selected' : '' ?>>Code S</option>
                        <option value="A" <?= $divisionFilter === 'A' ? 'selected' : '' ?>>Code A</option>
                        <option value="B" <?= $divisionFilter === 'B' ? 'selected' : '' ?>>Code B</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="race-filter">Race:</label>
                    <select id="race-filter">
                        <option value="all" <?= $raceFilter === 'all' ? 'selected' : '' ?>>All Races</option>
                        <option value="T" <?= $raceFilter === 'T' ? 'selected' : '' ?>>Terran</option>
                        <option value="P" <?= $raceFilter === 'P' ? 'selected' : '' ?>>Protoss</option>
                        <option value="Z" <?= $raceFilter === 'Z' ? 'selected' : '' ?>>Zerg</option>
                        <option value="R" <?= $raceFilter === 'R' ? 'selected' : '' ?>>Random</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="team-filter">Team:</label>
                    <select id="team-filter">
                        <option value="all" <?= $teamFilter === 'all' ? 'selected' : '' ?>>All Teams</option>
                        <option value="None" <?= $teamFilter === 'None' ? 'selected' : '' ?>>None</option>
                        <?php
                        // Get unique teams
                        $teams = [];
                        foreach ($playerStats as $player) {
                            if (!empty($player['Current_Team_Name']) && $player['Current_Team_Name'] !== 'None' && !in_array($player['Current_Team_Name'], $teams)) {
                                $teams[] = $player['Current_Team_Name'];
                            }
                        }
                        sort($teams);
                        foreach ($teams as $team) {
                            $selected = ($teamFilter === $team) ? 'selected' : '';
                            echo '<option value="' . htmlspecialchars($team) . '" ' . $selected . '>' . htmlspecialchars($team) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="player-search">Search Player:</label>
                    <input type="text" id="player-search" placeholder="Enter player name..." value="<?= $playerFilter ?>">
                </div>
                <div class="filter-actions">
                    <button id="clear-filters" class="btn btn-clear">Clear Filters</button>
                </div>
            </div>
            
            <div class="table-responsive">
                <table id="player-stats-table">
                    <thead>
                        <tr>
                            <th class="sortable <?= $sortField === 'Player_Name' ? ($sortDirection === 'asc' ? 'asc' : 'desc') : '' ?>" 
                                data-sort="Player_Name">Player Name</th>
                            <th class="sortable <?= $sortField === 'Alias_Name' ? ($sortDirection === 'asc' ? 'asc' : 'desc') : '' ?>" 
                                data-sort="Alias_Name">Alias</th>
                            <th class="sortable <?= $sortField === 'Division' ? ($sortDirection === 'asc' ? 'asc' : 'desc') : '' ?>" 
                                data-sort="Division">Division</th>
                            <th class="sortable <?= $sortField === 'Race' ? ($sortDirection === 'asc' ? 'asc' : 'desc') : '' ?>" 
                                data-sort="Race">Race</th>
                            <th class="sortable <?= $sortField === 'MapsW' ? ($sortDirection === 'asc' ? 'asc' : 'desc') : '' ?>" 
                                data-sort="MapsW">Maps W-L</th>
                            <th class="sortable <?= $sortField === 'MapWinRate' ? ($sortDirection === 'asc' ? 'asc' : 'desc') : '' ?>" 
                                data-sort="MapWinRate">Map Win %</th>
                            <th class="sortable <?= $sortField === 'SetsW' ? ($sortDirection === 'asc' ? 'asc' : 'desc') : '' ?>" 
                                data-sort="SetsW">Sets W-L</th>
                            <th class="sortable <?= $sortField === 'SetWinRate' ? ($sortDirection === 'asc' ? 'asc' : 'desc') : '' ?>" 
                                data-sort="SetWinRate">Set Win %</th>
                            <th class="sortable <?= $sortField === 'Current_Team_Name' ? ($sortDirection === 'asc' ? 'asc' : 'desc') : '' ?>" 
                                data-sort="Current_Team_Name">Current Team</th>
                            <th>Championship Record</th>
                            <th>Team Championship Record</th>
                            <th>Past Teams</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($playerStats as $player): ?>
                            <tr class="player-row" 
                                data-division="<?= htmlspecialchars($player['Division'] ?? '') ?>"
                                data-race="<?= htmlspecialchars($player['Race'] ?? '') ?>"
                                data-team="<?= htmlspecialchars($player['Current_Team_Name'] ?? '') ?>"
                                data-player-name="<?= htmlspecialchars($player['Player_Name'] ?? '') ?>"
                                data-alias-name="<?= htmlspecialchars($player['Alias_Name'] ?? '') ?>"
                                data-maps-w="<?= $player['MapsW'] ?? 0 ?>"
                                data-maps-l="<?= $player['MapsL'] ?? 0 ?>"
                                data-map-win-rate="<?= $player['MapWinRate'] ?? 0 ?>"
                                data-sets-w="<?= $player['SetsW'] ?? 0 ?>"
                                data-sets-l="<?= $player['SetsL'] ?? 0 ?>"
                                data-set-win-rate="<?= $player['SetWinRate'] ?? 0 ?>">
                                <td><a href="view_player.php?name=<?= urlencode($player['Player_Name']) ?>" class="player-link"><?= htmlspecialchars($player['Player_Name']) ?></a></td>
                                <td><?= htmlspecialchars($player['Alias_Name'] ?? 'N/A') ?></td>
                                <td>
                                    <?php if (!empty($player['Division'])): ?>
                                        <span class="division-badge division-<?= htmlspecialchars($player['Division']) ?>">
                                            Code <?= htmlspecialchars($player['Division']) ?>
                                        </span>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($player['Race'])): ?>
                                        <?php 
                                        $raceIcon = '';
                                        switch ($player['Race']) {
                                            case 'T':
                                                $raceIcon = 'images/terran_icon.png';
                                                break;
                                            case 'P':
                                                $raceIcon = 'images/protoss_icon.png';
                                                break;
                                            case 'Z':
                                                $raceIcon = 'images/zerg_icon.png';
                                                break;
                                            case 'R':
                                                $raceIcon = 'images/random_icon.png';
                                                break;
                                        }
                                        
                                        if (!empty($raceIcon)) {
                                            echo '<img src="' . $raceIcon . '" alt="' . htmlspecialchars($player['Race']) . '" class="race-icon">';
                                        }
                                        
                                        // Display full race name
                                        $raceName = '';
                                        switch ($player['Race']) {
                                            case 'T':
                                                $raceName = 'Terran';
                                                break;
                                            case 'P':
                                                $raceName = 'Protoss';
                                                break;
                                            case 'Z':
                                                $raceName = 'Zerg';
                                                break;
                                            case 'R':
                                                $raceName = 'Random';
                                                break;
                                            default:
                                                $raceName = $player['Race'];
                                        }
                                        // do not display raceName
                                        $raceName = '';
                                        ?>
                                        <?= htmlspecialchars($raceName) ?>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td><?= $player['MapsW'] ?? 0 ?>-<?= $player['MapsL'] ?? 0 ?></td>
                                <td class="win-rate <?= getWinRateClass($player['MapWinRate']) ?>">
                                    <span class="MapWinRate" style="display:none;"><?= $player['MapWinRate'] ?></span>
                                    <?= $player['MapWinRate'] ?>%
                                </td>
                                <td><?= $player['SetsW'] ?? 0 ?>-<?= $player['SetsL'] ?? 0 ?></td>
                                <td class="win-rate <?= getWinRateClass($player['SetWinRate']) ?>">
                                    <span class="SetWinRate" style="display:none;"><?= $player['SetWinRate'] ?></span>
                                    <?= $player['SetWinRate'] ?>%
                                </td>
                                <td class="team-name">
                                    <?php if (!empty($player['Current_Team_Name']) && $player['Current_Team_Name'] !== 'None'): ?>
                                        <a href="view_team.php?name=<?= urlencode($player['Current_Team_Name']) ?>" class="team-link">
                                            <?= htmlspecialchars($player['Current_Team_Name']) ?>
                                        </a>
                                    <?php else: ?>
                                        None
                                    <?php endif; ?>
                                </td>
                                <td class="championship-record">
                                    <?php if (!empty($player['Championship_Record']) && $player['Championship_Record'] !== 'None' && $player['Championship_Record'] !== 'null'): ?>
                                        <?= formatChampionshipRecord($player['Championship_Record']) ?>
                                    <?php else: ?>
                                        
                                    <?php endif; ?>
                                </td>
                                <td class="championship-record">
                                    <?php if (!empty($player['TeamLeague_Championship_Record']) && $player['TeamLeague_Championship_Record'] !== 'None' && $player['TeamLeague_Championship_Record'] !== 'null'): ?>
                                        <?= formatTeamChampionshipRecord($player['TeamLeague_Championship_Record']) ?>
                                    <?php else: ?>
                                        
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($player['Past_Team_History']) && $player['Past_Team_History'] !== 'None' && $player['Past_Team_History'] !== 'null'): ?>
                                        <div class="tooltip">
                                            <span>View History</span>
                                            <div class="tooltip-content">
                                                <?= formatTeamsHistory($player['Past_Team_History']) ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Filter functions
            const divisionFilter = document.getElementById('division-filter');
            const raceFilter = document.getElementById('race-filter');
            const teamFilter = document.getElementById('team-filter');
            const playerSearch = document.getElementById('player-search');
            const clearFiltersBtn = document.getElementById('clear-filters');
            const playerRows = document.querySelectorAll('.player-row');
            
            // Debug function to check data attributes
            function logRowAttributes() {
                console.log("Checking row attributes:");
                playerRows.forEach((row, index) => {
                    console.log(`Row ${index}:`, {
                        division: row.dataset.division,
                        race: row.dataset.race,
                        team: row.dataset.team
                    });
                });
            }
            
            // Call once to check attributes in console
            logRowAttributes();
            
            function applyFilters() {
                const divisionValue = divisionFilter.value;
                const raceValue = raceFilter.value;
                const teamValue = teamFilter.value;
                const searchValue = playerSearch.value.toLowerCase();
                
                console.log("Applying filters:", {
                    division: divisionValue,
                    race: raceValue,
                    team: teamValue,
                    search: searchValue
                });
                
                playerRows.forEach(row => {
                    const playerName = row.querySelector('td:first-child').textContent.toLowerCase();
                    const aliasName = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
                    const division = row.dataset.division;
                    const race = row.dataset.race;
                    const team = row.dataset.team;
                    
                    // Check if race cell contains the selected race text
                    let raceCell = row.querySelector('td:nth-child(4)');
                    let raceText = raceCell ? raceCell.textContent.trim() : '';
                    
                    // Check if team cell contains the selected team text
                    let teamCell = row.querySelector('td:nth-child(9)');
                    let teamText = teamCell ? teamCell.textContent.trim() : '';
                    
                    const divisionMatch = divisionValue === 'all' || division === divisionValue;
                    const raceMatch = raceValue === 'all' || 
                                     race === raceValue || 
                                     raceText.includes(raceValue);
                    const teamMatch = teamValue === 'all' || 
                                     team === teamValue || 
                                     teamText.includes(teamValue);
                    const searchMatch = searchValue === '' || 
                                       playerName.includes(searchValue) || 
                                       aliasName.includes(searchValue);
                    
                    if (divisionMatch && raceMatch && teamMatch && searchMatch) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                // Update URL with current filters
                updateURL();
            }
            
            // Function to update URL with current filter values
            function updateURL() {
                const url = new URL(window.location);
                
                // Clear existing filter parameters
                url.searchParams.delete('player');
                url.searchParams.delete('division');
                url.searchParams.delete('race');
                url.searchParams.delete('team');
                
                // Add current filter values
                if (playerSearch.value) {
                    url.searchParams.set('player', playerSearch.value);
                }
                
                if (divisionFilter.value !== 'all') {
                    url.searchParams.set('division', divisionFilter.value);
                }
                
                if (raceFilter.value !== 'all') {
                    url.searchParams.set('race', raceFilter.value);
                }
                
                if (teamFilter.value !== 'all') {
                    url.searchParams.set('team', teamFilter.value);
                }
                
                // Preserve sort parameters
                const currentSort = url.searchParams.get('sort');
                const currentDir = url.searchParams.get('dir');
                
                if (currentSort) {
                    url.searchParams.set('sort', currentSort);
                }
                
                if (currentDir) {
                    url.searchParams.set('dir', currentDir);
                }
                
                // Update browser history without reloading the page
                window.history.replaceState({}, '', url.toString());
            }
            
            // Function to clear all filters
            function clearFilters() {
                divisionFilter.value = 'all';
                raceFilter.value = 'all';
                teamFilter.value = 'all';
                playerSearch.value = '';
                
                // Preserve sort parameters when clearing filters
                const url = new URL(window.location);
                const currentSort = url.searchParams.get('sort');
                const currentDir = url.searchParams.get('dir');
                
                // Clear all parameters
                url.search = '';
                
                // Add back sort parameters if they exist
                if (currentSort) {
                    url.searchParams.set('sort', currentSort);
                }
                
                if (currentDir) {
                    url.searchParams.set('dir', currentDir);
                }
                
                window.location.href = url.toString();
            }
            
            // Add event listeners
            divisionFilter.addEventListener('change', applyFilters);
            raceFilter.addEventListener('change', applyFilters);
            teamFilter.addEventListener('change', applyFilters);
            playerSearch.addEventListener('input', applyFilters);
            clearFiltersBtn.addEventListener('click', clearFilters);
            
            // Initial filter application
            applyFilters();

            // Sorting functionality
            const sortableHeaders = document.querySelectorAll('th.sortable');
            
            sortableHeaders.forEach(header => {
                header.addEventListener('click', function() {
                    const sortField = this.getAttribute('data-sort');
                    let sortDirection = 'asc';
                    
                    // If already sorted by this field, toggle direction
                    if (this.classList.contains('asc')) {
                        sortDirection = 'desc';
                    } else if (this.classList.contains('desc')) {
                        sortDirection = 'asc';
                    }
                    
                    // For client-side sorting (if needed)
                    if (sortField === 'MapWinRate' || sortField === 'SetWinRate') {
                        // Get all rows
                        const tbody = document.querySelector('tbody');
                        const rows = Array.from(tbody.querySelectorAll('tr.player-row'));
                        
                        // Sort rows
                        rows.sort((a, b) => {
                            const aValue = parseFloat(a.querySelector(`.${sortField}`).textContent);
                            const bValue = parseFloat(b.querySelector(`.${sortField}`).textContent);
                            
                            return sortDirection === 'asc' ? aValue - bValue : bValue - aValue;
                        });
                        
                        // Reorder rows
                        rows.forEach(row => tbody.appendChild(row));
                        
                        // Update visual indication
                        sortableHeaders.forEach(h => h.classList.remove('asc', 'desc'));
                        this.classList.add(sortDirection);
                        
                        // Update URL without reloading
                        const url = new URL(window.location);
                        url.searchParams.set('sort', sortField);
                        url.searchParams.set('dir', sortDirection);
                        window.history.pushState({}, '', url.toString());
                        
                        return; // Skip the page reload
                    }
                    
                    // Update URL with sort parameters for server-side sorting
                    const url = new URL(window.location);
                    url.searchParams.set('sort', sortField);
                    url.searchParams.set('dir', sortDirection);
                    
                    // Update the class for visual indication
                    sortableHeaders.forEach(h => h.classList.remove('asc', 'desc'));
                    this.classList.add(sortDirection);

                    window.location.href = url.toString();
                });
            });
        });
    </script>
</body>
</html>

<?php
// Include footer
include_once 'includes/footer.php';

// Helper function to determine win rate class
function getWinRateClass($winRate) {
    if ($winRate >= 60) {
        return 'high-win-rate';
    } elseif ($winRate >= 45) {
        return 'medium-win-rate';
    } else {
        return 'low-win-rate';
    }
}

// Sort the player stats based on the sort parameters
usort($playerStats, function($a, $b) use ($sortField, $sortDirection) {
    $aValue = $a[$sortField] ?? '';
    $bValue = $b[$sortField] ?? '';

    // Handle calculated fields
    if ($sortField === 'MapWinRate' || $sortField === 'SetWinRate') {
        $aValue = $a[$sortField];
        $bValue = $b[$sortField];
    }

    // Handle numeric values
    if (is_numeric($aValue) && is_numeric($bValue)) {
        $comparison = $aValue <=> $bValue;
    } else {
        // Case-insensitive string comparison
        $comparison = strcasecmp($aValue, $bValue);
    }

    // Reverse for descending order
    return $sortDirection === 'desc' ? -$comparison : $comparison;
});
?> 