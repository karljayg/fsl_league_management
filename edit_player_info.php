<?php
/**
 * Player Information Editor
 * Allows administrators to edit player information in the Players and Player_Aliases tables
 */

// Start session
session_start();

// Enable error logging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Create a log file for debugging
function logError($message) {
    error_log(date('[Y-m-d H:i:s] ') . $message . "\n", 3, 'player_info_error.log');
    echo "<p class='error'><strong>Error:</strong> " . htmlspecialchars($message) . "</p>";
}

function logInfo($message) {
    error_log(date('[Y-m-d H:i:s] ') . $message . "\n", 3, 'player_info_error.log');
    // Don't display info to users
}

// Check permission
$required_permission = 'edit player, team, stats';
include 'includes/check_permission.php';

// Include database connection
require_once 'includes/db.php';

// Include utility files
// Note: Required functions are defined in this file

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    logInfo("Database connection established successfully");
} catch (PDOException $e) {
    logError("Database connection failed: " . $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}

// Process form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();
        
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'update_player':
                    if (!isset($_POST['player_id']) || empty($_POST['player_id'])) {
                        throw new Exception("Player ID is required");
                    }
                    
                    $playerId = $_POST['player_id'];
                    $realName = $_POST['real_name'] ?? '';
                    $teamId = !empty($_POST['team_id']) ? $_POST['team_id'] : null;
                    $status = $_POST['status'] ?? 'active';
                    $introUrl = $_POST['intro_url'] ?? '';
                    $userId = !empty($_POST['user_id']) ? $_POST['user_id'] : null;
                    
                    // Process JSON fields
                    $championshipRecord = $_POST['championship_record'] ?? '';
                    $teamLeagueChampionshipRecord = $_POST['teamleague_championship_record'] ?? '';
                    $teamsHistory = $_POST['teams_history'] ?? '';
                    
                    // Validate JSON fields
                    $championshipRecordValid = true;
                    $teamLeagueChampionshipRecordValid = true;
                    $teamsHistoryValid = true;
                    
                    if (!empty($championshipRecord)) {
                        $championshipRecordValid = json_decode($championshipRecord) !== null;
                        if (!$championshipRecordValid) {
                            throw new Exception("Championship Record is not valid JSON");
                        }
                    }
                    
                    if (!empty($teamLeagueChampionshipRecord)) {
                        $teamLeagueChampionshipRecordValid = json_decode($teamLeagueChampionshipRecord) !== null;
                        if (!$teamLeagueChampionshipRecordValid) {
                            throw new Exception("Team League Championship Record is not valid JSON");
                        }
                    }
                    
                    if (!empty($teamsHistory)) {
                        $teamsHistoryValid = json_decode($teamsHistory) !== null;
                        if (!$teamsHistoryValid) {
                            throw new Exception("Teams History is not valid JSON");
                        }
                    }
                    
                    // Update player information
                    $updateQuery = "UPDATE Players SET 
                                    Real_Name = :real_name,
                                    Team_ID = :team_id,
                                    Status = :status,
                                    Intro_Url = :intro_url,
                                    User_ID = :user_id";
                    
                    // Add JSON fields to query if they're valid
                    if ($championshipRecordValid && !empty($championshipRecord)) {
                        $updateQuery .= ", Championship_Record = :championship_record";
                    }
                    
                    if ($teamLeagueChampionshipRecordValid && !empty($teamLeagueChampionshipRecord)) {
                        $updateQuery .= ", TeamLeague_Championship_Record = :teamleague_championship_record";
                    }
                    
                    if ($teamsHistoryValid && !empty($teamsHistory)) {
                        $updateQuery .= ", Teams_History = :teams_history";
                    }
                    
                    $updateQuery .= " WHERE Player_ID = :player_id";
                    
                    $updateStmt = $db->prepare($updateQuery);
                    $updateStmt->bindParam(':real_name', $realName);
                    $updateStmt->bindParam(':team_id', $teamId);
                    $updateStmt->bindParam(':status', $status);
                    $updateStmt->bindParam(':intro_url', $introUrl);
                    $updateStmt->bindParam(':user_id', $userId);
                    
                    // Bind JSON fields if they're valid
                    if ($championshipRecordValid && !empty($championshipRecord)) {
                        $updateStmt->bindParam(':championship_record', $championshipRecord);
                    }
                    
                    if ($teamLeagueChampionshipRecordValid && !empty($teamLeagueChampionshipRecord)) {
                        $updateStmt->bindParam(':teamleague_championship_record', $teamLeagueChampionshipRecord);
                    }
                    
                    if ($teamsHistoryValid && !empty($teamsHistory)) {
                        $updateStmt->bindParam(':teams_history', $teamsHistory);
                    }
                    
                    $updateStmt->bindParam(':player_id', $playerId);
                    $updateStmt->execute();
                    
                    $message = "Player information updated successfully";
                    $messageType = "success";
                    break;
                    
                case 'add_alias':
                    if (!isset($_POST['player_id']) || empty($_POST['player_id'])) {
                        throw new Exception("Player ID is required");
                    }
                    
                    if (!isset($_POST['alias_name']) || empty($_POST['alias_name'])) {
                        throw new Exception("Alias name is required");
                    }
                    
                    $playerId = $_POST['player_id'];
                    $aliasName = $_POST['alias_name'];
                    
                    // Check if alias already exists
                    $checkQuery = "SELECT COUNT(*) FROM Player_Aliases WHERE Alias_Name = :alias_name";
                    $checkStmt = $db->prepare($checkQuery);
                    $checkStmt->bindParam(':alias_name', $aliasName);
                    $checkStmt->execute();
                    
                    if ($checkStmt->fetchColumn() > 0) {
                        throw new Exception("Alias name already exists");
                    }
                    
                    // Add new alias
                    $insertQuery = "INSERT INTO Player_Aliases (Player_ID, Alias_Name) VALUES (:player_id, :alias_name)";
                    $insertStmt = $db->prepare($insertQuery);
                    $insertStmt->bindParam(':player_id', $playerId);
                    $insertStmt->bindParam(':alias_name', $aliasName);
                    $insertStmt->execute();
                    
                    $message = "Alias added successfully";
                    $messageType = "success";
                    break;
                    
                case 'update_alias':
                    if (!isset($_POST['alias_id']) || empty($_POST['alias_id'])) {
                        throw new Exception("Alias ID is required");
                    }
                    
                    if (!isset($_POST['alias_name']) || empty($_POST['alias_name'])) {
                        throw new Exception("Alias name is required");
                    }
                    
                    $aliasId = $_POST['alias_id'];
                    $aliasName = $_POST['alias_name'];
                    
                    // Check if alias already exists (excluding current alias)
                    $checkQuery = "SELECT COUNT(*) FROM Player_Aliases WHERE Alias_Name = :alias_name AND Alias_ID != :alias_id";
                    $checkStmt = $db->prepare($checkQuery);
                    $checkStmt->bindParam(':alias_name', $aliasName);
                    $checkStmt->bindParam(':alias_id', $aliasId);
                    $checkStmt->execute();
                    
                    if ($checkStmt->fetchColumn() > 0) {
                        throw new Exception("Alias name already exists");
                    }
                    
                    // Update alias
                    $updateQuery = "UPDATE Player_Aliases SET Alias_Name = :alias_name WHERE Alias_ID = :alias_id";
                    $updateStmt = $db->prepare($updateQuery);
                    $updateStmt->bindParam(':alias_name', $aliasName);
                    $updateStmt->bindParam(':alias_id', $aliasId);
                    $updateStmt->execute();
                    
                    $message = "Alias updated successfully";
                    $messageType = "success";
                    break;
                    
                case 'delete_alias':
                    if (!isset($_POST['alias_id']) || empty($_POST['alias_id'])) {
                        throw new Exception("Alias ID is required");
                    }
                    
                    $aliasId = $_POST['alias_id'];
                    
                    // Check if this is the only alias for the player
                    $checkQuery = "SELECT p.Player_ID, COUNT(pa.Alias_ID) as alias_count 
                                  FROM Player_Aliases pa 
                                  JOIN Players p ON pa.Player_ID = p.Player_ID 
                                  WHERE pa.Alias_ID = :alias_id 
                                  GROUP BY p.Player_ID";
                    $checkStmt = $db->prepare($checkQuery);
                    $checkStmt->bindParam(':alias_id', $aliasId);
                    $checkStmt->execute();
                    $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($result && $result['alias_count'] <= 1) {
                        throw new Exception("Cannot delete the only alias for a player");
                    }
                    
                    // Delete alias
                    $deleteQuery = "DELETE FROM Player_Aliases WHERE Alias_ID = :alias_id";
                    $deleteStmt = $db->prepare($deleteQuery);
                    $deleteStmt->bindParam(':alias_id', $aliasId);
                    $deleteStmt->execute();
                    
                    $message = "Alias deleted successfully";
                    $messageType = "success";
                    break;
                    
                case 'add_player':
                    if (!isset($_POST['real_name']) || empty($_POST['real_name'])) {
                        throw new Exception("Player name is required");
                    }
                    
                    if (!isset($_POST['alias_name']) || empty($_POST['alias_name'])) {
                        throw new Exception("Alias name is required");
                    }
                    
                    $realName = $_POST['real_name'];
                    $aliasName = $_POST['alias_name'];
                    $teamId = !empty($_POST['team_id']) ? $_POST['team_id'] : null;
                    $status = $_POST['status'] ?? 'active';
                    $introUrl = $_POST['intro_url'] ?? '';
                    $userId = !empty($_POST['user_id']) ? $_POST['user_id'] : null;
                    
                    // Process JSON fields
                    $championshipRecord = $_POST['championship_record'] ?? '';
                    $teamLeagueChampionshipRecord = $_POST['teamleague_championship_record'] ?? '';
                    $teamsHistory = $_POST['teams_history'] ?? '';
                    
                    // Validate JSON fields
                    $championshipRecordValid = true;
                    $teamLeagueChampionshipRecordValid = true;
                    $teamsHistoryValid = true;
                    
                    if (!empty($championshipRecord)) {
                        $championshipRecordValid = json_decode($championshipRecord) !== null;
                        if (!$championshipRecordValid) {
                            throw new Exception("Championship Record is not valid JSON");
                        }
                    }
                    
                    if (!empty($teamLeagueChampionshipRecord)) {
                        $teamLeagueChampionshipRecordValid = json_decode($teamLeagueChampionshipRecord) !== null;
                        if (!$teamLeagueChampionshipRecordValid) {
                            throw new Exception("Team League Championship Record is not valid JSON");
                        }
                    }
                    
                    if (!empty($teamsHistory)) {
                        $teamsHistoryValid = json_decode($teamsHistory) !== null;
                        if (!$teamsHistoryValid) {
                            throw new Exception("Teams History is not valid JSON");
                        }
                    }
                    
                    // Check if player name already exists
                    $checkQuery = "SELECT COUNT(*) FROM Players WHERE Real_Name = :real_name";
                    $checkStmt = $db->prepare($checkQuery);
                    $checkStmt->bindParam(':real_name', $realName);
                    $checkStmt->execute();
                    
                    if ($checkStmt->fetchColumn() > 0) {
                        throw new Exception("Player name already exists");
                    }
                    
                    // Check if alias name already exists
                    $checkQuery = "SELECT COUNT(*) FROM Player_Aliases WHERE Alias_Name = :alias_name";
                    $checkStmt = $db->prepare($checkQuery);
                    $checkStmt->bindParam(':alias_name', $aliasName);
                    $checkStmt->execute();
                    
                    if ($checkStmt->fetchColumn() > 0) {
                        throw new Exception("Alias name already exists");
                    }
                    
                    // Check if user ID is valid
                    if (!empty($userId)) {
                        $checkQuery = "SELECT COUNT(*) FROM users WHERE id = :user_id";
                        $checkStmt = $db->prepare($checkQuery);
                        $checkStmt->bindParam(':user_id', $userId);
                        $checkStmt->execute();
                        
                        if ($checkStmt->fetchColumn() == 0) {
                            throw new Exception("User ID does not exist");
                        }
                    }
                    
                    // Add new player
                    $insertQuery = "INSERT INTO Players (Real_Name, Team_ID, Status, Intro_Url, User_ID";
                    
                    // Add JSON fields to query if they're valid
                    if ($championshipRecordValid && !empty($championshipRecord)) {
                        $insertQuery .= ", Championship_Record";
                    }
                    
                    if ($teamLeagueChampionshipRecordValid && !empty($teamLeagueChampionshipRecord)) {
                        $insertQuery .= ", TeamLeague_Championship_Record";
                    }
                    
                    if ($teamsHistoryValid && !empty($teamsHistory)) {
                        $insertQuery .= ", Teams_History";
                    }
                    
                    $insertQuery .= ") VALUES (:real_name, :team_id, :status, :intro_url, :user_id";
                    
                    // Add JSON fields to values if they're valid
                    if ($championshipRecordValid && !empty($championshipRecord)) {
                        $insertQuery .= ", :championship_record";
                    }
                    
                    if ($teamLeagueChampionshipRecordValid && !empty($teamLeagueChampionshipRecord)) {
                        $insertQuery .= ", :teamleague_championship_record";
                    }
                    
                    if ($teamsHistoryValid && !empty($teamsHistory)) {
                        $insertQuery .= ", :teams_history";
                    }
                    
                    $insertQuery .= ")";
                    
                    $insertStmt = $db->prepare($insertQuery);
                    $insertStmt->bindParam(':real_name', $realName);
                    $insertStmt->bindParam(':team_id', $teamId);
                    $insertStmt->bindParam(':status', $status);
                    $insertStmt->bindParam(':intro_url', $introUrl);
                    $insertStmt->bindParam(':user_id', $userId);
                    
                    // Bind JSON fields if they're valid
                    if ($championshipRecordValid && !empty($championshipRecord)) {
                        $insertStmt->bindParam(':championship_record', $championshipRecord);
                    }
                    
                    if ($teamLeagueChampionshipRecordValid && !empty($teamLeagueChampionshipRecord)) {
                        $insertStmt->bindParam(':teamleague_championship_record', $teamLeagueChampionshipRecord);
                    }
                    
                    if ($teamsHistoryValid && !empty($teamsHistory)) {
                        $insertStmt->bindParam(':teams_history', $teamsHistory);
                    }
                    
                    $insertStmt->execute();
                    
                    $playerId = $db->lastInsertId();
                    
                    // Add alias for the new player
                    $insertQuery = "INSERT INTO Player_Aliases (Player_ID, Alias_Name) VALUES (:player_id, :alias_name)";
                    $insertStmt = $db->prepare($insertQuery);
                    $insertStmt->bindParam(':player_id', $playerId);
                    $insertStmt->bindParam(':alias_name', $aliasName);
                    $insertStmt->execute();
                    
                    $message = "Player and alias added successfully";
                    $messageType = "success";
                    break;
                    
                default:
                    throw new Exception("Unknown action");
            }
        }
        
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        $message = $e->getMessage();
        $messageType = "error";
        logError($message);
    }
}

// Get teams for dropdown
$teamsQuery = "SELECT Team_ID, Team_Name FROM Teams ORDER BY Team_Name";
$teamsStmt = $db->query($teamsQuery);
$teams = $teamsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$teams = ['' => 'No Team'] + $teams; // Add empty option

// Get users for dropdown
$usersQuery = "SELECT id, username FROM users ORDER BY username";
$usersStmt = $db->query($usersQuery);
$users = $usersStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$users = ['' => 'No User'] + $users; // Add empty option

// Process search query
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$searchCondition = '';
$params = [];

if (!empty($searchQuery)) {
    $searchCondition = "WHERE p.Real_Name LIKE :search OR pa.Alias_Name LIKE :search";
    $params[':search'] = "%{$searchQuery}%";
}

// Get players with their aliases
$query = "
    SELECT 
        p.Player_ID,
        p.Real_Name,
        p.Team_ID,
        p.Status,
        p.Intro_Url,
        p.User_ID,
        p.Championship_Record,
        p.TeamLeague_Championship_Record,
        p.Teams_History,
        pa.Alias_ID,
        pa.Alias_Name,
        t.Team_Name,
        u.username as User_Name
    FROM 
        Players p
    LEFT JOIN 
        Player_Aliases pa ON p.Player_ID = pa.Player_ID
    LEFT JOIN 
        Teams t ON p.Team_ID = t.Team_ID
    LEFT JOIN
        users u ON p.User_ID = u.id
    $searchCondition
    ORDER BY 
        p.Real_Name, pa.Alias_Name
";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group results by player
$players = [];
foreach ($results as $row) {
    $playerId = $row['Player_ID'];
    
    if (!isset($players[$playerId])) {
        $players[$playerId] = [
            'Player_ID' => $playerId,
            'Real_Name' => $row['Real_Name'],
            'Team_ID' => $row['Team_ID'],
            'Team_Name' => $row['Team_Name'],
            'Status' => $row['Status'],
            'Intro_Url' => $row['Intro_Url'],
            'User_ID' => $row['User_ID'],
            'User_Name' => $row['User_Name'],
            'Championship_Record' => $row['Championship_Record'],
            'TeamLeague_Championship_Record' => $row['TeamLeague_Championship_Record'],
            'Teams_History' => $row['Teams_History'],
            'aliases' => []
        ];
    }
    
    if (!empty($row['Alias_ID'])) {
        $aliasId = $row['Alias_ID'];
        
        if (!isset($players[$playerId]['aliases'][$aliasId])) {
            $players[$playerId]['aliases'][$aliasId] = [
                'Alias_ID' => $aliasId,
                'Alias_Name' => $row['Alias_Name']
            ];
        }
    }
}

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <div class="admin-header">
        <h1><i class="fas fa-user-edit"></i> Player Information Editor</h1>
        <div class="admin-user-info">
            <span>Logged in as: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></span>
            <a href="logout.php" class="btn btn-logout">Logout</a>
        </div>
    </div>
    
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    <?php endif; ?>
    
    <!-- Search Form -->
    <form method="GET" action="" class="mb-4">
        <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="Search by player name or alias" value="<?php echo htmlspecialchars($searchQuery); ?>">
            <div class="input-group-append">
                <button type="submit" class="btn btn-primary">Search</button>
                <a href="edit_player_info.php" class="btn btn-secondary">Clear</a>
            </div>
        </div>
    </form>
    
    <!-- Add New Player Form -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Add New Player</h5>
        </div>
        <div class="card-body">
            <form method="POST" action="" id="add-player-form">
                <input type="hidden" name="action" value="add_player">
                
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="new-real-name">Player Name</label>
                        <input type="text" class="form-control" id="new-real-name" name="real_name" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="new-alias-name">Alias Name</label>
                        <input type="text" class="form-control" id="new-alias-name" name="alias_name" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label for="new-team-id">Team</label>
                        <select class="form-control" id="new-team-id" name="team_id">
                            <?php foreach ($teams as $id => $name): ?>
                                <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label for="new-status">Status</label>
                        <select class="form-control" id="new-status" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="banned">Banned</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label for="new-intro-url">Intro URL</label>
                        <input type="url" class="form-control" id="new-intro-url" name="intro_url" placeholder="https://example.com/intro">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group col-md-12">
                        <label for="new-user-id">User Account</label>
                        <select class="form-control" id="new-user-id" name="user_id">
                            <?php foreach ($users as $id => $name): ?>
                                <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">Link this player to a user account</small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group col-md-12">
                        <label for="new-championship-record">Championship Record (JSON)</label>
                        <textarea class="form-control json-editor" id="new-championship-record" name="championship_record" rows="3" placeholder='{"season1":"1st", "season2":"2nd", ...}'></textarea>
                        <small class="form-text text-muted">Enter valid JSON for championship records (optional)</small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group col-md-12">
                        <label for="new-teamleague-championship-record">Team League Championship Record (JSON)</label>
                        <textarea class="form-control json-editor" id="new-teamleague-championship-record" name="teamleague_championship_record" rows="3" placeholder='{"season1":"1st", "season2":"2nd", ...}'></textarea>
                        <small class="form-text text-muted">Enter valid JSON for team league championship records (optional)</small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group col-md-12">
                        <label for="new-teams-history">Teams History (JSON)</label>
                        <textarea class="form-control json-editor" id="new-teams-history" name="teams_history" rows="3" placeholder='{"team1":"2020-2021", "team2":"2021-2022", ...}'></textarea>
                        <small class="form-text text-muted">Enter valid JSON for teams history (optional)</small>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-success">Add Player</button>
            </form>
        </div>
    </div>
    
    <!-- Player List -->
    <div class="card">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0">Player List</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($players)): ?>
                <div class="alert alert-info m-3">No players found.</div>
            <?php else: ?>
                <div class="accordion" id="playerAccordion">
                    <?php foreach ($players as $player): ?>
                        <div class="card">
                            <div class="card-header" id="heading<?php echo $player['Player_ID']; ?>">
                                <h2 class="mb-0">
                                    <button class="btn btn-link btn-block text-left" type="button" data-toggle="collapse" data-target="#collapse<?php echo $player['Player_ID']; ?>" aria-expanded="true" aria-controls="collapse<?php echo $player['Player_ID']; ?>">
                                        <?php echo htmlspecialchars($player['Real_Name']); ?>
                                        <span class="badge badge-<?php echo $player['Status'] === 'active' ? 'success' : ($player['Status'] === 'inactive' ? 'warning' : 'danger'); ?> ml-2">
                                            <?php echo ucfirst($player['Status']); ?>
                                        </span>
                                        <?php if (!empty($player['Team_Name'])): ?>
                                            <span class="badge badge-info ml-2"><?php echo htmlspecialchars($player['Team_Name']); ?></span>
                                        <?php endif; ?>
                                        <small class="text-muted ml-2">(<?php echo count($player['aliases']); ?> aliases)</small>
                                    </button>
                                </h2>
                            </div>
                            
                            <div id="collapse<?php echo $player['Player_ID']; ?>" class="collapse" aria-labelledby="heading<?php echo $player['Player_ID']; ?>" data-parent="#playerAccordion">
                                <div class="card-body">
                                    <!-- Player Edit Form -->
                                    <form method="POST" action="" class="mb-4 player-form">
                                        <input type="hidden" name="action" value="update_player">
                                        <input type="hidden" name="player_id" value="<?php echo $player['Player_ID']; ?>">
                                        
                                        <h5 class="border-bottom pb-2 mb-3">Player Information</h5>
                                        
                                        <div class="form-row">
                                            <div class="form-group col-md-6">
                                                <label for="real-name-<?php echo $player['Player_ID']; ?>">Player Name</label>
                                                <input type="text" class="form-control" id="real-name-<?php echo $player['Player_ID']; ?>" name="real_name" value="<?php echo htmlspecialchars($player['Real_Name']); ?>" required>
                                            </div>
                                            <div class="form-group col-md-6">
                                                <label for="team-id-<?php echo $player['Player_ID']; ?>">Team</label>
                                                <select class="form-control" id="team-id-<?php echo $player['Player_ID']; ?>" name="team_id">
                                                    <?php foreach ($teams as $id => $name): ?>
                                                        <option value="<?php echo $id; ?>" <?php echo ($player['Team_ID'] == $id) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($name); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="form-row">
                                            <div class="form-group col-md-6">
                                                <label for="status-<?php echo $player['Player_ID']; ?>">Status</label>
                                                <select class="form-control" id="status-<?php echo $player['Player_ID']; ?>" name="status">
                                                    <option value="active" <?php echo ($player['Status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                                    <option value="inactive" <?php echo ($player['Status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                                    <option value="banned" <?php echo ($player['Status'] === 'banned') ? 'selected' : ''; ?>>Banned</option>
                                                    <option value="other" <?php echo ($player['Status'] === 'other') ? 'selected' : ''; ?>>Other</option>
                                                </select>
                                            </div>
                                            <div class="form-group col-md-6">
                                                <label for="intro-url-<?php echo $player['Player_ID']; ?>">Intro URL</label>
                                                <input type="url" class="form-control" id="intro-url-<?php echo $player['Player_ID']; ?>" name="intro_url" value="<?php echo htmlspecialchars($player['Intro_Url'] ?? ''); ?>" placeholder="https://example.com/intro">
                                            </div>
                                        </div>
                                        
                                        <div class="form-row">
                                            <div class="form-group col-md-12">
                                                <label for="user-id-<?php echo $player['Player_ID']; ?>">User Account</label>
                                                <select class="form-control" id="user-id-<?php echo $player['Player_ID']; ?>" name="user_id">
                                                    <?php foreach ($users as $id => $name): ?>
                                                        <option value="<?php echo $id; ?>" <?php echo ($player['User_ID'] == $id) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($name); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="form-text text-muted">Link this player to a user account</small>
                                            </div>
                                        </div>
                                        
                                        <h5 class="border-bottom pb-2 mb-3 mt-4">Championship Records (JSON)</h5>
                                        
                                        <div class="form-row">
                                            <div class="form-group col-md-12">
                                                <label for="championship-record-<?php echo $player['Player_ID']; ?>">Championship Record</label>
                                                <textarea class="form-control json-editor" id="championship-record-<?php echo $player['Player_ID']; ?>" name="championship_record" rows="4" placeholder='{"season1":"1st", "season2":"2nd", ...}'><?php echo htmlspecialchars($player['Championship_Record'] ?? ''); ?></textarea>
                                                <small class="form-text text-muted">Enter valid JSON for championship records</small>
                                            </div>
                                        </div>
                                        
                                        <div class="form-row">
                                            <div class="form-group col-md-12">
                                                <label for="teamleague-championship-record-<?php echo $player['Player_ID']; ?>">Team League Championship Record</label>
                                                <textarea class="form-control json-editor" id="teamleague-championship-record-<?php echo $player['Player_ID']; ?>" name="teamleague_championship_record" rows="4" placeholder='{"season1":"1st", "season2":"2nd", ...}'><?php echo htmlspecialchars($player['TeamLeague_Championship_Record'] ?? ''); ?></textarea>
                                                <small class="form-text text-muted">Enter valid JSON for team league championship records</small>
                                            </div>
                                        </div>
                                        
                                        <div class="form-row">
                                            <div class="form-group col-md-12">
                                                <label for="teams-history-<?php echo $player['Player_ID']; ?>">Teams History</label>
                                                <textarea class="form-control json-editor" id="teams-history-<?php echo $player['Player_ID']; ?>" name="teams_history" rows="4" placeholder='{"team1":"2020-2021", "team2":"2021-2022", ...}'><?php echo htmlspecialchars($player['Teams_History'] ?? ''); ?></textarea>
                                                <small class="form-text text-muted">Enter valid JSON for teams history</small>
                                            </div>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-primary">Update Player</button>
                                    </form>
                                    
                                    <!-- Aliases Section -->
                                    <h5 class="border-bottom pb-2 mb-3">Aliases</h5>
                                    
                                    <div class="table-responsive mb-3">
                                        <table class="table table-bordered table-striped">
                                            <thead class="thead-dark">
                                                <tr>
                                                    <th>Alias ID</th>
                                                    <th>Alias Name</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($player['aliases'] as $alias): ?>
                                                    <tr>
                                                        <td><?php echo $alias['Alias_ID']; ?></td>
                                                        <td>
                                                            <form method="POST" action="" class="alias-form">
                                                                <input type="hidden" name="action" value="update_alias">
                                                                <input type="hidden" name="alias_id" value="<?php echo $alias['Alias_ID']; ?>">
                                                                <div class="input-group">
                                                                    <input type="text" class="form-control" name="alias_name" value="<?php echo htmlspecialchars($alias['Alias_Name']); ?>" required>
                                                                    <div class="input-group-append">
                                                                        <button type="submit" class="btn btn-outline-primary">Update</button>
                                                                    </div>
                                                                </div>
                                                            </form>
                                                        </td>
                                                        <td>
                                                            <form method="POST" action="" class="delete-alias-form d-inline">
                                                                <input type="hidden" name="action" value="delete_alias">
                                                                <input type="hidden" name="alias_id" value="<?php echo $alias['Alias_ID']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this alias?');">Delete</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <!-- Add Alias Form -->
                                    <form method="POST" action="" class="add-alias-form">
                                        <input type="hidden" name="action" value="add_alias">
                                        <input type="hidden" name="player_id" value="<?php echo $player['Player_ID']; ?>">
                                        
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="alias_name" placeholder="New Alias Name" required>
                                            <div class="input-group-append">
                                                <button type="submit" class="btn btn-success">Add Alias</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    // Form submission handling
    document.addEventListener('DOMContentLoaded', function() {
        // Add player form
        const addPlayerForm = document.getElementById('add-player-form');
        if (addPlayerForm) {
            addPlayerForm.addEventListener('submit', function(e) {
                const realName = document.getElementById('new-real-name').value.trim();
                const aliasName = document.getElementById('new-alias-name').value.trim();
                
                if (!realName || !aliasName) {
                    e.preventDefault();
                    alert('Player name and alias name are required');
                    return;
                }
                
                // Validate JSON fields
                if (!validateJsonField('new-championship-record') || 
                    !validateJsonField('new-teamleague-championship-record') || 
                    !validateJsonField('new-teams-history')) {
                    e.preventDefault();
                    return;
                }
            });
        }
        
        // Player update forms
        const playerForms = document.querySelectorAll('.player-form');
        playerForms.forEach(form => {
            form.addEventListener('submit', function(e) {
                const realNameInput = form.querySelector('input[name="real_name"]');
                if (!realNameInput || !realNameInput.value.trim()) {
                    e.preventDefault();
                    alert('Player name is required');
                    return;
                }
                
                // Get all JSON editors in this form
                const jsonEditors = form.querySelectorAll('.json-editor');
                for (let editor of jsonEditors) {
                    if (!validateJsonField(editor.id)) {
                        e.preventDefault();
                        return;
                    }
                }
            });
        });
        
        // Alias update forms
        const aliasForms = document.querySelectorAll('.alias-form');
        aliasForms.forEach(form => {
            form.addEventListener('submit', function(e) {
                const aliasNameInput = form.querySelector('input[name="alias_name"]');
                if (!aliasNameInput || !aliasNameInput.value.trim()) {
                    e.preventDefault();
                    alert('Alias name is required');
                }
            });
        });
        
        // Add alias forms
        const addAliasForms = document.querySelectorAll('.add-alias-form');
        addAliasForms.forEach(form => {
            form.addEventListener('submit', function(e) {
                const aliasNameInput = form.querySelector('input[name="alias_name"]');
                if (!aliasNameInput || !aliasNameInput.value.trim()) {
                    e.preventDefault();
                    alert('Alias name is required');
                }
            });
        });
        
        // Function to validate JSON fields
        function validateJsonField(fieldId) {
            const field = document.getElementById(fieldId);
            if (!field) return true;
            
            const value = field.value.trim();
            if (!value) return true; // Empty is valid
            
            try {
                JSON.parse(value);
                return true;
            } catch (e) {
                alert(`Invalid JSON in field: ${field.name}. Error: ${e.message}`);
                field.focus();
                return false;
            }
        }
        
        // Add JSON formatting helpers
        document.querySelectorAll('.json-editor').forEach(editor => {
            // Add a format button next to each JSON editor
            const formatBtn = document.createElement('button');
            formatBtn.type = 'button';
            formatBtn.className = 'btn btn-sm btn-outline-secondary mt-1';
            formatBtn.textContent = 'Format JSON';
            formatBtn.onclick = function() {
                const value = editor.value.trim();
                if (!value) return;
                
                try {
                    const formatted = JSON.stringify(JSON.parse(value), null, 2);
                    editor.value = formatted;
                } catch (e) {
                    alert(`Cannot format: Invalid JSON. Error: ${e.message}`);
                }
            };
            
            editor.parentNode.insertBefore(formatBtn, editor.nextSibling);
        });
    });
</script>

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
    
    .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
    }
    
    .admin-user-info {
        display: flex;
        align-items: center;
        gap: 15px;
        color: #ccc;
    }
    
    h1 {
        color: #00d4ff;
        text-shadow: 0 0 15px #00d4ff;
        font-size: 2.4em;
        margin: 0;
    }
    
    h5 {
        color: #00d4ff;
        margin-bottom: 15px;
    }
    
    .alert {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 5px;
    }
    
    .alert-success {
        background: rgba(40, 167, 69, 0.2);
        border: 1px solid #28a745;
        color: #28a745;
    }
    
    .alert-danger {
        background: rgba(220, 53, 69, 0.2);
        border: 1px solid #dc3545;
        color: #dc3545;
    }
    
    .alert-info {
        background: rgba(33, 150, 243, 0.2);
        border: 1px solid #2196f3;
        color: #2196f3;
    }
    
    .card {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        margin-bottom: 20px;
        border: 1px solid rgba(0, 212, 255, 0.2);
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
    }
    
    .card-header {
        background: rgba(0, 212, 255, 0.1);
        border-bottom: 1px solid rgba(0, 212, 255, 0.2);
        color: #e0e0e0;
        padding: 15px 20px;
        border-radius: 10px 10px 0 0;
    }
    
    .card-body {
        padding: 20px;
    }
    
    .card-header .btn-link {
        color: #e0e0e0;
        text-decoration: none;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        padding: 0;
        border: none;
        background: none;
    }
    
    .card-header .btn-link:hover {
        color: #00d4ff;
        text-decoration: none;
    }
    
    .form-control, .form-select {
        background-color: rgba(0, 0, 0, 0.5);
        border: 1px solid rgba(0, 212, 255, 0.3);
        color: #e0e0e0;
        border-radius: 5px;
    }
    
    .form-control:focus, .form-select:focus {
        background-color: rgba(0, 0, 0, 0.5);
        border-color: #00d4ff;
        color: #e0e0e0;
        box-shadow: 0 0 10px rgba(0, 212, 255, 0.3);
        outline: none;
    }
    
    .form-control::placeholder {
        color: rgba(224, 224, 224, 0.6);
    }
    
    .form-text {
        color: #ccc;
    }
    
    label {
        color: #00d4ff;
        font-weight: 600;
        margin-bottom: 5px;
    }
    
    .btn {
        padding: 8px 16px;
        border-radius: 5px;
        border: none;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-block;
    }
    
    .btn-primary {
        background: #00d4ff;
        color: #0f0c29;
    }
    
    .btn-secondary {
        background: #6c757d;
        color: white;
    }
    
    .btn-success {
        background: #28a745;
        color: white;
    }
    
    .btn-danger {
        background: #dc3545;
        color: white;
    }
    
    .btn-outline-primary {
        background: transparent;
        color: #00d4ff;
        border: 1px solid #00d4ff;
    }
    
    .btn-outline-secondary {
        background: transparent;
        color: #6c757d;
        border: 1px solid #6c757d;
    }
    
    .btn-outline-primary:hover {
        background: #00d4ff;
        color: #0f0c29;
    }
    
    .btn:hover {
        opacity: 0.8;
        transform: translateY(-1px);
    }
    
    .btn-sm {
        padding: 4px 8px;
        font-size: 0.8em;
    }
    
    .btn-logout {
        background: #dc3545;
        color: white;
    }
    
    .table {
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
        border: 1px solid rgba(0, 212, 255, 0.2);
        border-radius: 5px;
        overflow: hidden;
    }
    
    .table th {
        background: rgba(0, 212, 255, 0.1);
        color: #00d4ff;
        border-color: rgba(0, 212, 255, 0.2);
        font-weight: bold;
    }
    
    .table td {
        border-color: rgba(0, 212, 255, 0.2);
        vertical-align: middle;
    }
    
    .table-striped tbody tr:nth-of-type(odd) {
        background-color: rgba(0, 212, 255, 0.05);
    }
    
    .table-bordered {
        border: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .table-bordered th,
    .table-bordered td {
        border: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .json-editor {
        font-family: 'Courier New', monospace;
        font-size: 0.9rem;
        background-color: rgba(0, 0, 0, 0.7);
        color: #e0e0e0;
    }
    
    .input-group-text {
        background-color: rgba(0, 212, 255, 0.1);
        border-color: rgba(0, 212, 255, 0.3);
        color: #00d4ff;
    }
    
    .input-group .form-control {
        border-right: none;
    }
    
    .input-group .btn {
        border-left: none;
    }
    
    .badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 0.75em;
        font-weight: 600;
    }
    
    .badge-success {
        background: #28a745;
        color: white;
    }
    
    .badge-warning {
        background: #ffc107;
        color: #212529;
    }
    
    .badge-danger {
        background: #dc3545;
        color: white;
    }
    
    .badge-info {
        background: #17a2b8;
        color: white;
    }
    
    .border-bottom {
        border-bottom: 1px solid rgba(0, 212, 255, 0.3) !important;
    }
    
    .text-muted {
        color: #888 !important;
    }
    
    .text-left {
        text-align: left !important;
    }
    
    .accordion .card {
        background: rgba(0, 0, 0, 0.3);
        border: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .collapse:not(.show) {
        display: none;
    }
    
    .collapse.show {
        display: block;
    }
    
    /* Custom scrollbar for better dark theme */
    ::-webkit-scrollbar {
        width: 8px;
    }
    
    ::-webkit-scrollbar-track {
        background: rgba(0, 0, 0, 0.3);
    }
    
    ::-webkit-scrollbar-thumb {
        background: rgba(0, 212, 255, 0.5);
        border-radius: 4px;
    }
    
    ::-webkit-scrollbar-thumb:hover {
        background: rgba(0, 212, 255, 0.7);
    }
</style>

<?php include 'includes/footer.php'; ?> 