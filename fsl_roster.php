<?php
// Start session and include database connection
session_start();
require_once 'includes/db.php';
require_once 'includes/status_badge.php';

// Check if user has any role assigned (using the function from includes/nav.php)
// Note: hasRole() function is defined in includes/nav.php which is included via header.php

// Get URL parameters for filtering
$teamId = isset($_GET['team_id']) ? $_GET['team_id'] : null;
$playerFilter = isset($_GET['player']) ? htmlspecialchars($_GET['player']) : '';
$raceFilter = isset($_GET['race']) ? htmlspecialchars($_GET['race']) : 'all';
$divisionFilter = isset($_GET['division']) ? htmlspecialchars($_GET['division']) : 'all';

// Get all players with their statistics
$rosterQuery = "
    SELECT 
        p.Player_ID,
        p.Real_Name,
        COALESCE(s.Division, 'N/A') AS Division,
        COALESCE(s.Race, 'N/A') AS Race,
        COALESCE(s.MapsW, 0) AS MapsW,
        COALESCE(s.MapsL, 0) AS MapsL,
        COALESCE(s.SetsW, 0) AS SetsW,
        COALESCE(s.SetsL, 0) AS SetsL,
        p.Status,
        t.Team_Name
    FROM Players p
    LEFT JOIN FSL_STATISTICS s ON p.Player_ID = s.Player_ID
    LEFT JOIN Teams t ON p.Team_ID = t.Team_ID
";

// Build WHERE clause with filters
$whereConditions = [];
$params = [];

if ($teamId) {
    $whereConditions[] = "p.Team_ID = :teamId";
    $params['teamId'] = $teamId;
}

if (!empty($playerFilter)) {
    $whereConditions[] = "(p.Real_Name LIKE :playerFilter OR EXISTS (
        SELECT 1 FROM Player_Aliases pa
        WHERE pa.Player_ID = p.Player_ID AND pa.Alias_Name LIKE :playerFilter
    ))";
    $params['playerFilter'] = '%' . $playerFilter . '%';
}

if ($raceFilter !== 'all') {
    $whereConditions[] = "s.Race = :raceFilter";
    $params['raceFilter'] = $raceFilter;
}

if ($divisionFilter !== 'all') {
    $whereConditions[] = "s.Division = :divisionFilter";
    $params['divisionFilter'] = $divisionFilter;
}

// Add WHERE clause if conditions exist
if (!empty($whereConditions)) {
    $rosterQuery .= " WHERE " . implode(' AND ', $whereConditions);
}

$rosterQuery .= " ORDER BY
    CASE WHEN p.Status = 'active' THEN 1 ELSE 2 END, 
    t.Team_Name, s.Division, p.Real_Name
";

$stmt = $db->prepare($rosterQuery);

// Execute with parameters
$stmt->execute($params);

$roster = $stmt->fetchAll(PDO::FETCH_ASSOC);

function fsl_roster_division_rank($division): int {
    $order = ['S' => 0, 'A' => 1, 'B' => 2];
    $key = strtoupper(trim((string) $division));
    return $order[$key] ?? 99;
}

function fsl_roster_race_html($race): string {
    $icons = [
        'T' => 'images/terran_icon.png',
        'P' => 'images/protoss_icon.png',
        'Z' => 'images/zerg_icon.png',
        'R' => 'images/random_icon.png',
    ];
    $race = trim((string) $race);
    if ($race === '' || strcasecmp($race, 'N/A') === 0) {
        return 'N/A';
    }
    $html = '';
    if (isset($icons[$race])) {
        $html .= '<img src="' . $icons[$race] . '" alt="' . htmlspecialchars($race) . '" class="race-icon">';
    }
    $html .= htmlspecialchars($race);
    return $html;
}

function fsl_roster_win_rate($mapsW, $mapsL): string {
    $mapsW = (int) $mapsW;
    $mapsL = (int) $mapsL;
    if ($mapsW + $mapsL <= 0) {
        return 'N/A';
    }
    return number_format(($mapsW / ($mapsW + $mapsL)) * 100, 1) . '%';
}

/**
 * One summary row per player: highest division and most-played race.
 * Other division/race rows stay on the expand control, with their own map totals.
 */
function fsl_roster_group_players(array $rows): array {
    $players = [];
    foreach ($rows as $row) {
        $id = $row['Player_ID'];
        if (!isset($players[$id])) {
            $players[$id] = [
                'Player_ID' => $id,
                'Real_Name' => $row['Real_Name'],
                'Status' => $row['Status'] ?? '',
                'Team_Name' => $row['Team_Name'] ?? '',
                'entries' => [],
            ];
        }
        $div = trim((string) ($row['Division'] ?? ''));
        $race = trim((string) ($row['Race'] ?? ''));
        if (strcasecmp($div, 'N/A') === 0) {
            $div = '';
        }
        if (strcasecmp($race, 'N/A') === 0) {
            $race = '';
        }
        if ($div === '' && $race === '') {
            continue;
        }
        $key = $div . '|' . $race;
        $mapsW = (int) ($row['MapsW'] ?? 0);
        $mapsL = (int) ($row['MapsL'] ?? 0);
        $maps = $mapsW + $mapsL;
        if (!isset($players[$id]['entries'][$key]) || $maps > $players[$id]['entries'][$key]['maps']) {
            $players[$id]['entries'][$key] = [
                'Division' => $div,
                'Race' => $race,
                'MapsW' => $mapsW,
                'MapsL' => $mapsL,
                'SetsW' => (int) ($row['SetsW'] ?? 0),
                'SetsL' => (int) ($row['SetsL'] ?? 0),
                'maps' => $maps,
            ];
        }
    }

    foreach ($players as &$player) {
        $entries = array_values($player['entries']);
        $bestDiv = '';
        $bestDivRank = 999;
        $raceMaps = [];
        $raceBestDiv = [];
        foreach ($entries as $entry) {
            if ($entry['Division'] !== '') {
                $rank = fsl_roster_division_rank($entry['Division']);
                if ($rank < $bestDivRank) {
                    $bestDivRank = $rank;
                    $bestDiv = $entry['Division'];
                }
            }
            if ($entry['Race'] === '') {
                continue;
            }
            $raceMaps[$entry['Race']] = ($raceMaps[$entry['Race']] ?? 0) + $entry['maps'];
            $rank = fsl_roster_division_rank($entry['Division']);
            if (!isset($raceBestDiv[$entry['Race']]) || $rank < $raceBestDiv[$entry['Race']]) {
                $raceBestDiv[$entry['Race']] = $rank;
            }
        }

        $bestRace = '';
        $bestRaceMaps = -1;
        $bestRaceDiv = 999;
        foreach ($raceMaps as $race => $maps) {
            $divRank = $raceBestDiv[$race] ?? 999;
            $betterMaps = $maps > $bestRaceMaps;
            $sameMapsBetterDiv = $maps === $bestRaceMaps && $divRank < $bestRaceDiv;
            $sameTieAlpha = $maps === $bestRaceMaps && $divRank === $bestRaceDiv && ($bestRace === '' || strcmp($race, $bestRace) < 0);
            if ($betterMaps || $sameMapsBetterDiv || $sameTieAlpha) {
                $bestRace = $race;
                $bestRaceMaps = $maps;
                $bestRaceDiv = $divRank;
            }
        }

        $matched = null;
        foreach ($entries as $entry) {
            if ($bestDiv !== '' && $bestRace !== '' && $entry['Division'] === $bestDiv && $entry['Race'] === $bestRace) {
                $matched = $entry;
                break;
            }
        }

        if ($matched) {
            $summary = $matched;
            $extras = [];
            foreach ($entries as $entry) {
                if ($entry['Division'] === $matched['Division'] && $entry['Race'] === $matched['Race']) {
                    continue;
                }
                $extras[] = $entry;
            }
        } else {
            $summary = [
                'Division' => $bestDiv,
                'Race' => $bestRace,
                'MapsW' => 0,
                'MapsL' => 0,
                'SetsW' => 0,
                'SetsL' => 0,
            ];
            foreach ($entries as $entry) {
                if ($bestRace !== '' && $entry['Race'] === $bestRace) {
                    $summary['MapsW'] += $entry['MapsW'];
                    $summary['MapsL'] += $entry['MapsL'];
                    $summary['SetsW'] += $entry['SetsW'];
                    $summary['SetsL'] += $entry['SetsL'];
                }
            }
            $extras = $entries;
        }

        usort($extras, function ($a, $b) {
            $byDiv = fsl_roster_division_rank($a['Division']) <=> fsl_roster_division_rank($b['Division']);
            if ($byDiv !== 0) {
                return $byDiv;
            }
            return strcmp($a['Race'], $b['Race']);
        });

        $lines = [$summary];
        foreach ($extras as $extra) {
            $lines[] = $extra;
        }
        $player['lines'] = $lines;
        unset($player['entries']);
    }
    unset($player);

    $grouped = array_values($players);
    usort($grouped, function ($a, $b) {
        $statusRank = function ($status) {
            return strtolower(trim((string) $status)) === 'active' ? 0 : 1;
        };
        $byStatus = $statusRank($a['Status']) <=> $statusRank($b['Status']);
        if ($byStatus !== 0) {
            return $byStatus;
        }
        $byTeam = strcasecmp((string) $a['Team_Name'], (string) $b['Team_Name']);
        if ($byTeam !== 0) {
            return $byTeam;
        }
        return strcasecmp((string) $a['Real_Name'], (string) $b['Real_Name']);
    });

    return $grouped;
}

$rosterPlayers = fsl_roster_group_players($roster);

// Calculate total statistics
$totalPlayers = count($roster);
$uniquePlayers = count($rosterPlayers);
$totalMapsW = 0;
$totalMapsL = 0;
$totalSetsW = 0;
$totalSetsL = 0;

foreach ($roster as $player) {
    $totalMapsW += $player['MapsW'] ?? 0;
    $totalMapsL += $player['MapsL'] ?? 0;
    $totalSetsW += $player['SetsW'] ?? 0;
    $totalSetsL += $player['SetsL'] ?? 0;
}

// Calculate win rates
$mapWinRate = $totalMapsW + $totalMapsL > 0 ? round(($totalMapsW / ($totalMapsW + $totalMapsL)) * 100, 1) : 0;
$setWinRate = $totalSetsW + $totalSetsL > 0 ? round(($totalSetsW / ($totalSetsW + $totalSetsL)) * 100, 1) : 0;

// Get division counts
$divisions = array_column($roster, 'Division');
$divisions = array_filter($divisions); // Remove empty/null values
$divisionCounts = array_count_values($divisions);

// Include header
include_once 'includes/header.php';
?>

<!DOCTYPE html>
<center>
<img src="images/FSL_crew_at_cheesadelphia_PSISTORMCup.png" width="100%">
</center>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FSL Player List</title>
  <style>
    body {
      font-family: 'Arial', sans-serif;
      background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
      color: #e0e0e0;
      margin: 0;
      padding: 0;
      line-height: 1.6;
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
      font-size: 2.8em;
      margin-bottom: 40px;
    }
    .stats-container {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }
    .stat-card {
      background: rgba(0, 0, 0, 0.3);
      padding: 15px;
      border-radius: 8px;
      text-align: center;
    }
    .stat-value {
      font-size: 1.8em;
      color: #00d4ff;
      margin: 10px 0;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
    }
    th, td {
      padding: 12px;
      border: 1px solid rgba(255, 255, 255, 0.2);
      text-align: left;
    }
    th {
      background-color: rgba(0,0,0,0.3);
      color: #00d4ff;
    }
    tr:nth-child(even) {
      background-color: rgba(255,255,255,0.05);
    }
    tr:hover {
      background-color: rgba(255,255,255,0.1);
    }
    .division-S {
      color: #ff6f61;
      font-weight: bold;
    }
    .division-A {
      color: #ffcc00;
      font-weight: bold;
    }
    .division-B {
      color: #00d4ff;
      font-weight: bold;
    }
    .race-icon {
      width: 20px;
      height: 20px;
      margin-right: 5px;
      vertical-align: middle;
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
    .team-link {
      color: #ff6f61;
      text-decoration: none;
      transition: all 0.3s ease;
    }
    .team-link:hover {
      color: #ff8577;
      text-shadow: 0 0 5px #ff6f61;
    }
    .spider-link {
      color: #00d4ff;
      text-decoration: none;
      font-size: 0.8em;
      transition: all 0.3s ease;
    }
    .spider-link:hover {
      color: #ffffff;
      text-shadow: 0 0 5px #00d4ff;
    }
    .win-rate {
      color: #98ff98;
    }

    .roster-toggle,
    .roster-toggle-spacer {
      display: inline-block;
      width: 1.35rem;
      margin-right: 0.2rem;
      text-align: center;
      vertical-align: middle;
    }

    .roster-toggle {
      padding: 0;
      border: 0;
      background: transparent;
      color: #00d4ff;
      font-weight: 700;
      font-size: 1.15rem;
      line-height: 1;
      cursor: pointer;
    }

    .roster-toggle:hover,
    .roster-toggle:focus {
      color: #ffffff;
      outline: none;
    }

    .player-name-line {
      white-space: nowrap;
    }

    .roster-player td {
      vertical-align: top;
    }

    .stat-line {
      min-height: 1.7rem;
      display: flex;
      align-items: center;
    }

    .stat-line.is-extra {
      display: none;
      margin-top: 0.35rem;
    }

    .roster-player.is-open .stat-line.is-extra {
      display: flex;
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
    
    .filter-group select,
    .filter-group input {
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
    
    @media (max-width: 768px) {
      .filters {
        flex-direction: column;
      }
    }
    
    <?= fsl_status_badge_css() ?>
    footer {
      text-align: center;
      padding: 20px;
      font-size: 0.9em;
      color: #b0b0b0;
      border-top: 1px solid rgba(255, 255, 255, 0.1);
      margin-top: 40px;
    }
  </style>
</head>
<body>
  <div class="container">
    <h1>FSL Player List</h1>

    <div class="filters">
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
            <label for="division-filter">Division:</label>
            <select id="division-filter">
                <option value="all" <?= $divisionFilter === 'all' ? 'selected' : '' ?>>All Divisions</option>
                <option value="S" <?= $divisionFilter === 'S' ? 'selected' : '' ?>>S</option>
                <option value="A" <?= $divisionFilter === 'A' ? 'selected' : '' ?>>A</option>
                <option value="B" <?= $divisionFilter === 'B' ? 'selected' : '' ?>>B</option>
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

    <div class="stats-container">
      <div class="stat-card">
        <h3>Unique Players</h3>
        <div class="stat-value"><?= $uniquePlayers ?></div>
      </div>
      <div class="stat-card">
        <h3>Total Players*</h3>
        <div class="stat-value"><?= $totalPlayers ?></div>
      </div>
      <!--
      <div class="stat-card">
        <h3>Total Maps</h3>
        <div class="stat-value"><?= $totalMapsW ?>-<?= $totalMapsL ?> (<?= $mapWinRate ?>%)</div>
      </div>
      <div class="stat-card">
        <h3>Total Sets</h3>
        <div class="stat-value"><?= $totalSetsW ?>-<?= $totalSetsL ?> (<?= $setWinRate ?>%)</div>
      </div>
      -->
      <?php foreach (['S', 'A', 'B'] as $division): ?>
        <?php if (isset($divisionCounts[$division])): ?>
          <div class="stat-card">
            <h3>Division <?= $division ?> Players</h3>
            <div class="stat-value division-<?= $division ?>"><?= $divisionCounts[$division] ?></div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <i>* some players have different combinations of alias, race and division</i>

    <table>
      <thead>
        <tr>
          <th>Player Name</th>
          <th>Status</th>
          <th>Team</th>
          <th>Race</th>
          <th>Division</th>
          <th>Maps W/L</th>
          <th>Sets W/L</th>
          <th>Win Rate</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rosterPlayers as $player): ?>
          <?php $lines = $player['lines']; ?>
          <tr class="roster-player">
            <td class="player-cell">
              <div class="player-name-line">
                <?php if (count($lines) > 1): ?>
                  <button type="button" class="roster-toggle" aria-expanded="false" aria-label="Show other divisions and races">+</button>
                <?php else: ?>
                  <span class="roster-toggle-spacer" aria-hidden="true"></span>
                <?php endif; ?>
                <a href="view_player.php?name=<?= urlencode($player['Real_Name']) ?>" class="player-link">
                  <?= htmlspecialchars($player['Real_Name']) ?>
                </a>
              </div>
              <?php if (isset($_SESSION['user_id']) && hasRole()): ?>
                <a href="player_analysis.php?tab=spider&player=<?= $player['Player_ID'] ?>" class="spider-link">📊 Spider Chart</a>
              <?php endif; ?>
            </td>
            <td><?= !empty($player['Status']) ? fsl_status_badge($player['Status']) : 'N/A' ?></td>
            <td class="team-cell">
              <?php if (!empty($player['Team_Name'])): ?>
                <a href="view_team.php?name=<?= urlencode($player['Team_Name']) ?>" class="team-link">
                  <?= htmlspecialchars($player['Team_Name']) ?>
                </a>
              <?php else: ?>
                Free Agent
              <?php endif; ?>
            </td>
            <td class="race-cell">
              <?php foreach ($lines as $index => $line): ?>
                <div class="stat-line<?= $index > 0 ? ' is-extra' : '' ?>" data-line="<?= $index ?>" data-race="<?= htmlspecialchars($line['Race'] !== '' ? $line['Race'] : 'N/A') ?>" data-division="<?= htmlspecialchars($line['Division'] !== '' ? $line['Division'] : 'N/A') ?>">
                  <?= fsl_roster_race_html($line['Race']) ?>
                </div>
              <?php endforeach; ?>
            </td>
            <td>
              <?php foreach ($lines as $index => $line): ?>
                <?php $divisionLabel = $line['Division'] !== '' ? $line['Division'] : 'N/A'; ?>
                <div class="stat-line<?= $index > 0 ? ' is-extra' : '' ?> division-<?= htmlspecialchars($divisionLabel) ?>" data-line="<?= $index ?>">
                  <?= htmlspecialchars($divisionLabel) ?>
                </div>
              <?php endforeach; ?>
            </td>
            <td>
              <?php foreach ($lines as $index => $line): ?>
                <div class="stat-line<?= $index > 0 ? ' is-extra' : '' ?>" data-line="<?= $index ?>">
                  <?= (int) $line['MapsW'] ?>-<?= (int) $line['MapsL'] ?>
                </div>
              <?php endforeach; ?>
            </td>
            <td>
              <?php foreach ($lines as $index => $line): ?>
                <div class="stat-line<?= $index > 0 ? ' is-extra' : '' ?>" data-line="<?= $index ?>">
                  <?= (int) $line['SetsW'] ?>-<?= (int) $line['SetsL'] ?>
                </div>
              <?php endforeach; ?>
            </td>
            <td>
              <?php foreach ($lines as $index => $line): ?>
                <div class="stat-line win-rate<?= $index > 0 ? ' is-extra' : '' ?>" data-line="<?= $index ?>">
                  <?= fsl_roster_win_rate($line['MapsW'], $line['MapsL']) ?>
                </div>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    // Filter functions
    const raceFilter = document.getElementById('race-filter');
    const divisionFilter = document.getElementById('division-filter');
    const playerSearch = document.getElementById('player-search');
    const clearFiltersBtn = document.getElementById('clear-filters');
    const playerRows = document.querySelectorAll('table tbody tr');
    
    function applyFilters() {
      const raceValue = raceFilter.value;
      const divisionValue = divisionFilter.value;
      const searchValue = playerSearch.value.toLowerCase();
      const filteringCombo = raceValue !== 'all' || divisionValue !== 'all';
      
      playerRows.forEach(row => {
        const playerName = row.querySelector('.player-cell').textContent.toLowerCase();
        const team = row.querySelector('.team-cell').textContent.toLowerCase();
        const searchMatch = searchValue === '' ||
          playerName.includes(searchValue) ||
          team.includes(searchValue);

        const raceLines = row.querySelectorAll('.race-cell .stat-line');
        let anyMatch = !filteringCombo;

        raceLines.forEach(line => {
          const lineId = line.dataset.line;
          const raceMatch = raceValue === 'all' || line.dataset.race === raceValue;
          const divisionMatch = divisionValue === 'all' || line.dataset.division === divisionValue;
          const comboMatch = raceMatch && divisionMatch;
          if (comboMatch) {
            anyMatch = true;
          }

          let visible = false;
          if (searchMatch && comboMatch) {
            visible = filteringCombo || lineId === '0' || row.classList.contains('is-open');
          }

          row.querySelectorAll('.stat-line[data-line="' + lineId + '"]').forEach(el => {
            el.style.display = visible ? 'flex' : 'none';
          });
        });

        row.style.display = searchMatch && anyMatch ? '' : 'none';
      });
      
      updateURL();
    }
    
    function updateURL() {
      const url = new URL(window.location);
      
      // Clear existing filter parameters
      url.searchParams.delete('player');
      url.searchParams.delete('race');
      url.searchParams.delete('division');
      
      // Add current filter values
      if (playerSearch.value) {
        url.searchParams.set('player', playerSearch.value);
      }
      
      if (raceFilter.value !== 'all') {
        url.searchParams.set('race', raceFilter.value);
      }
      
      if (divisionFilter.value !== 'all') {
        url.searchParams.set('division', divisionFilter.value);
      }
      
      // Preserve team_id parameter if it exists
      const teamId = url.searchParams.get('team_id');
      if (teamId) {
        url.searchParams.set('team_id', teamId);
      }
      
      window.history.replaceState({}, '', url);
    }
    
    function clearFilters() {
      raceFilter.value = 'all';
      divisionFilter.value = 'all';
      playerSearch.value = '';
      
      const url = new URL(window.location);
      const teamId = url.searchParams.get('team_id');
      
      url.search = '';
      
      if (teamId) {
        url.searchParams.set('team_id', teamId);
      }
      
      window.location.href = url.toString();
    }
    
    // Add event listeners
    raceFilter.addEventListener('change', applyFilters);
    divisionFilter.addEventListener('change', applyFilters);
    playerSearch.addEventListener('input', applyFilters);
    clearFiltersBtn.addEventListener('click', clearFilters);

    document.querySelectorAll('.roster-toggle').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const row = btn.closest('tr');
        const open = !row.classList.contains('is-open');
        row.classList.toggle('is-open', open);
        btn.textContent = open ? '\u2212' : '+';
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.setAttribute('aria-label', open ? 'Hide other divisions and races' : 'Show other divisions and races');
        applyFilters();
      });
    });
    
    // Initial filter application
    applyFilters();
  });
</script>

</body>
</html>

<?php
// Include footer
include_once 'includes/footer.php';
?>

