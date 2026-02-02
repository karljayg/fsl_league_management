<?php
// Start session and include database connection
session_start();
require_once 'includes/db.php';
require_once 'includes/team_logo.php';
require_once 'includes/season_utils.php';

$currentSeason = getCurrentSeason($db);

// Get teams and their players; include Status for active/defunct when column exists. LEFT JOIN so defunct teams with no active players still appear.
// Pick race from FSL_STATISTICS row with most maps played (MapsW + MapsL) when player has multiple rows
$teamsQueryWithStatus = "
    SELECT 
        t.Team_ID,
        t.Team_Name,
        t.Captain_ID,
        t.Co_Captain_ID,
        COALESCE(t.Status, 'active') AS Status,
        p.Player_ID,
        p.Real_Name as Player_Name,
        s.Race,
        s.Division
    FROM Teams t
    LEFT JOIN Players p ON p.Team_ID = t.Team_ID AND p.Status = 'active'
    LEFT JOIN FSL_STATISTICS s ON p.Player_ID = s.Player_ID
        AND s.Player_Record_ID = (
            SELECT s2.Player_Record_ID FROM FSL_STATISTICS s2
            WHERE s2.Player_ID = p.Player_ID
            ORDER BY (s2.MapsW + s2.MapsL) DESC
            LIMIT 1
        )
    ORDER BY COALESCE(t.Status, 'active') ASC, t.Team_Name,
             CASE WHEN p.Player_ID = t.Captain_ID THEN 1 WHEN p.Player_ID = t.Co_Captain_ID THEN 2 ELSE 3 END,
             s.Division, p.Real_Name
";
$teamsQueryFallback = "
    SELECT 
        t.Team_ID,
        t.Team_Name,
        t.Captain_ID,
        t.Co_Captain_ID,
        'active' AS Status,
        p.Player_ID,
        p.Real_Name as Player_Name,
        s.Race,
        s.Division
    FROM Teams t
    LEFT JOIN Players p ON p.Team_ID = t.Team_ID AND p.Status = 'active'
    LEFT JOIN FSL_STATISTICS s ON p.Player_ID = s.Player_ID
        AND s.Player_Record_ID = (
            SELECT s2.Player_Record_ID FROM FSL_STATISTICS s2
            WHERE s2.Player_ID = p.Player_ID
            ORDER BY (s2.MapsW + s2.MapsL) DESC
            LIMIT 1
        )
    ORDER BY t.Team_Name,
             CASE WHEN p.Player_ID = t.Captain_ID THEN 1 WHEN p.Player_ID = t.Co_Captain_ID THEN 2 ELSE 3 END,
             s.Division, p.Real_Name
";

try {
    try {
        $teamPlayers = $db->query($teamsQueryWithStatus)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $teamPlayers = $db->query($teamsQueryFallback)->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $teams = [];
    $teamStatus = [];
    $seenPlayer = [];
    foreach ($teamPlayers as $player) {
        $name = $player['Team_Name'];
        if (!isset($teams[$name])) {
            $teams[$name] = [];
            $teamStatus[$name] = $player['Status'] ?? 'active';
            $seenPlayer[$name] = [];
        }
        if (!empty($player['Player_ID'])) {
            $pid = (int) $player['Player_ID'];
            if (empty($seenPlayer[$name][$pid])) {
                $seenPlayer[$name][$pid] = true;
                $teams[$name][] = $player;
            }
        }
    }
    $activeTeams = array_filter(array_keys($teams), function ($name) use ($teamStatus) {
        return ($teamStatus[$name] ?? 'active') === 'active';
    });
    $defunctTeams = array_filter(array_keys($teams), function ($name) use ($teamStatus) {
        return ($teamStatus[$name] ?? 'active') === 'defunct';
    });
    sort($activeTeams, SORT_FLAG_CASE | SORT_STRING);
    sort($defunctTeams, SORT_FLAG_CASE | SORT_STRING);
    
    // Get season records for all teams (used for both active and defunct)
    $seasonRecordsQuery = "
        SELECT 
            t.Team_ID,
            t.Team_Name,
            SUM(CASE WHEN s.winner_team_id = t.Team_ID THEN 1 ELSE 0 END) as wins,
            SUM(CASE WHEN (s.team1_id = t.Team_ID OR s.team2_id = t.Team_ID) AND s.winner_team_id != t.Team_ID AND s.winner_team_id IS NOT NULL THEN 1 ELSE 0 END) as losses
        FROM Teams t
        LEFT JOIN fsl_schedule s ON (s.team1_id = t.Team_ID OR s.team2_id = t.Team_ID) AND s.season = :currentSeason AND s.status = 'completed'
        GROUP BY t.Team_ID, t.Team_Name
    ";
    
    $stmt = $db->prepare($seasonRecordsQuery);
    $stmt->execute(['currentSeason' => $currentSeason]);
    $seasonRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize season records by team name
    $teamRecords = [];
    foreach ($seasonRecords as $record) {
        $teamRecords[$record['Team_Name']] = [
            'wins' => $record['wins'] ?? 0,
            'losses' => $record['losses'] ?? 0
        ];
    }
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Include header
include_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FSL Team Roster - All Teams in One Row</title>
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
      max-width: 1400px;
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
    .teams-section-heading {
      color: #00d4ff;
      font-size: 1.5rem;
      margin-top: 2rem;
      margin-bottom: 1rem;
      border-bottom: 1px solid rgba(0, 212, 255, 0.3);
      padding-bottom: 0.5rem;
      text-align: center;
    }
    .teams-section-heading--inactive {
      color: #888;
      border-bottom-color: rgba(255, 255, 255, 0.15);
      text-align: center;
    }
    .teams-container {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 20px;
      margin-bottom: 30px;
    }
    .team {
      flex: 0 1 280px;
      min-width: 260px;
      max-width: 320px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 10px;
      padding: 20px;
      box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
      transition: transform 0.3s ease;
      border-left: 5px solid #ff6f61;
    }
    .team:hover {
      transform: scale(1.02);
    }
    .team-logo-container {
      text-align: center;
      margin-bottom: 10px;
    }
    .team-logo {
      width: 80px;
      height: 80px;
      border-radius: 10px;
      object-fit: cover;
      border: 2px solid rgba(255, 111, 97, 0.5);
      transition: all 0.3s ease;
    }
    .team-logo:hover {
      border-color: #00d4ff;
      box-shadow: 0 0 15px rgba(0, 212, 255, 0.5);
      transform: scale(1.05);
    }
    .team h2 {
      text-align: center;
      margin-top: 0;
      font-size: 1.8em;
      color: #ff6f61;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
    }
    th, td {
      border: 1px solid rgba(255, 255, 255, 0.2);
      padding: 8px;
      text-align: left;
    }
    th {
      background-color: rgba(0, 0, 0, 0.3);
      color: #00d4ff;
    }
    tr:nth-child(even) {
      background-color: rgba(255, 255, 255, 0.05);
    }
    tr:hover {
      background-color: rgba(255, 255, 255, 0.1);
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
    footer {
      text-align: center;
      padding: 20px;
      font-size: 0.9em;
      color: #b0b0b0;
      border-top: 1px solid rgba(255, 255, 255, 0.1);
      margin-top: 40px;
    }
    @media (max-width: 768px) {
      .team {
        flex: 1 1 100%;
        max-width: none;
      }
      h1 {
        font-size: 2em;
      }
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
      text-decoration: none;
      color: #ff6f61;
      transition: all 0.3s ease;
    }
    
    .team-link:hover {
      color: #00d4ff;
      text-shadow: 0 0 5px #00d4ff;
    }
  </style>
</head>
<body>
  <div class="container">
    <h1>FSL Team Roster</h1>

    <?php
    $teamCard = function ($teamName, $players) use ($teams, $teamRecords, $currentSeason) {
        $teamLogo = getTeamLogo($teamName);
        $wins = $teamRecords[$teamName]['wins'] ?? 0;
        $losses = $teamRecords[$teamName]['losses'] ?? 0;
        $first = $teams[$teamName][0] ?? null;
        $captainId = $first['Captain_ID'] ?? null;
        $coCaptainId = $first['Co_Captain_ID'] ?? null;
        ?>
      <div class="team">
        <?php if ($teamLogo): ?>
        <div class="team-logo-container">
          <a href="view_team.php?name=<?= urlencode($teamName) ?>">
            <img src="<?= htmlspecialchars($teamLogo) ?>" alt="<?= htmlspecialchars($teamName) ?>" class="team-logo">
          </a>
        </div>
        <?php endif; ?>
        <h2><a href="view_team.php?name=<?= urlencode($teamName) ?>" class="team-link"><?= htmlspecialchars($teamName) ?></a></h2>
        <div style="text-align: center; margin-bottom: 5px; color: #b0b0b0; font-size: 0.9em;">
          <?= count($players) ?> player<?= count($players) === 1 ? '' : 's' ?>
        </div>
        <div style="text-align: center; margin-bottom: 15px; color: #00d4ff;">
          <strong><a href="fsl_schedule.php" style="color:rgb(46, 111, 124); text-decoration: none;">Season <?= $currentSeason ?>: <font size="+2" color="#00d4ff"><?= $wins ?> - <?= $losses ?></font></a></strong>
        </div>
        <table>
          <thead>
            <tr>
              <th>Player</th>
              <th>Race</th>
              <th>Level</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($players as $player): ?>
            <tr>
              <td><a href="view_player.php?name=<?= urlencode($player['Player_Name']) ?>" class="player-link" title="<?= $player['Player_ID'] == $captainId ? 'Captain' : ($player['Player_ID'] == $coCaptainId ? 'Co-captain' : '') ?>">
                  <?= htmlspecialchars($player['Player_Name']) ?>
                  <?php if ($player['Player_ID'] == $captainId): ?>
                      <strong>(C)</strong>
                  <?php elseif ($player['Player_ID'] == $coCaptainId): ?>
                      <small>(c)</small>
                  <?php endif; ?>
              </a></td>
              <td>
                <?php if (!empty($player['Race'])): ?>
                    <?php
                    $raceIcon = '';
                    switch ($player['Race']) {
                        case 'T': $raceIcon = 'images/terran_icon.png'; break;
                        case 'P': $raceIcon = 'images/protoss_icon.png'; break;
                        case 'Z': $raceIcon = 'images/zerg_icon.png'; break;
                        case 'R': $raceIcon = 'images/random_icon.png'; break;
                    }
                    if (!empty($raceIcon)) {
                        echo '<img src="' . $raceIcon . '" alt="' . htmlspecialchars($player['Race']) . '" class="race-icon">';
                    }
                    ?>
                <?php else: ?>
                    N/A
                <?php endif; ?>
              </td>
              <td class="division-<?= htmlspecialchars($player['Division'] ?? '') ?>">
                <?= htmlspecialchars($player['Division'] ?? 'N/A') ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
        <?php
    };
    ?>

    <h2 class="teams-section-heading">Active Teams</h2>
    <div class="teams-container">
      <?php foreach ($activeTeams as $teamName): ?>
        <?php $teamCard($teamName, $teams[$teamName]); ?>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($defunctTeams)): ?>
    <h2 class="teams-section-heading teams-section-heading--inactive">Inactive Teams</h2>
    <div class="teams-container teams-container--inactive">
      <?php foreach ($defunctTeams as $teamName): ?>
        <?php $teamCard($teamName, $teams[$teamName]); ?>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</body>
</html>

<?php
// Include footer
include_once 'includes/footer.php';
?>

