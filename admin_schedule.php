<?php
/**
 * FSL Schedule Admin Page
 * Manage team matches, scores, and individual match linkings
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Handle AJAX requests first (before permission check)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_schedule_entry') {
    // Include database connection
    require_once 'includes/db.php';
    
    // Connect to database
    try {
        $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Return schedule entry data for editing
        $stmt = $db->prepare("SELECT * FROM fsl_schedule WHERE schedule_id = ?");
        $stmt->execute([$_POST['schedule_id']]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Debug logging
        error_log("Loading schedule entry for ID " . $_POST['schedule_id'] . ": " . json_encode($entry));
        
        if (!$entry) {
            error_log("No schedule entry found for ID " . $_POST['schedule_id']);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Schedule entry not found']);
            exit;
        }
        
        header('Content-Type: application/json');
        echo json_encode($entry);
        exit;
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// Set required permission for this page
$required_permission = 'manage fsl schedule';

// Include permission check (this will handle authentication and authorization)
require_once 'includes/check_permission_updated.php';

// Include database connection
require_once 'includes/db.php';

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add_schedule':
                    // Check if we're editing an existing entry
                    if (isset($_POST['editing_schedule_id']) && !empty($_POST['editing_schedule_id'])) {
                        // Update existing entry by ID
                        $stmt = $db->prepare("UPDATE fsl_schedule SET season=?, week_number=?, match_date=?, team1_id=?, team2_id=?, team1_score=?, team2_score=?, winner_team_id=?, status=?, notes=?, team_2v2_results=? WHERE schedule_id=?");
                        $stmt->execute([
                            $_POST['season'],
                            $_POST['week_number'],
                            $_POST['match_date'] !== '' ? $_POST['match_date'] : null,
                            $_POST['team1_id'],
                            $_POST['team2_id'],
                            $_POST['team1_score'] !== '' ? $_POST['team1_score'] : null,
                            $_POST['team2_score'] !== '' ? $_POST['team2_score'] : null,
                            $_POST['winner_team_id'] !== '' ? $_POST['winner_team_id'] : null,
                            $_POST['status'],
                            $_POST['notes'],
                            $_POST['team_2v2_results'],
                            $_POST['editing_schedule_id']
                        ]);
                        $message = "Schedule entry updated successfully!";
                    } else {
                        // Check if entry already exists for this season/week
                        $checkStmt = $db->prepare("SELECT schedule_id FROM fsl_schedule WHERE season = ? AND week_number = ?");
                        $checkStmt->execute([$_POST['season'], $_POST['week_number']]);
                        $existing = $checkStmt->fetch();
                        
                        if ($existing) {
                            // Update existing entry
                            $stmt = $db->prepare("UPDATE fsl_schedule SET match_date=?, team1_id=?, team2_id=?, team1_score=?, team2_score=?, winner_team_id=?, status=?, notes=?, team_2v2_results=? WHERE schedule_id=?");
                            $stmt->execute([
                                $_POST['match_date'] !== '' ? $_POST['match_date'] : null,
                                $_POST['team1_id'],
                                $_POST['team2_id'],
                                $_POST['team1_score'] !== '' ? $_POST['team1_score'] : null,
                                $_POST['team2_score'] !== '' ? $_POST['team2_score'] : null,
                                $_POST['winner_team_id'] !== '' ? $_POST['winner_team_id'] : null,
                                $_POST['status'],
                                $_POST['notes'],
                                $_POST['team_2v2_results'],
                                $existing['schedule_id']
                            ]);
                            $message = "Schedule entry updated successfully!";
                        } else {
                            // Insert new entry
                            $stmt = $db->prepare("INSERT INTO fsl_schedule (season, week_number, match_date, team1_id, team2_id, team1_score, team2_score, winner_team_id, status, notes, team_2v2_results) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([
                                $_POST['season'],
                                $_POST['week_number'],
                                $_POST['match_date'] !== '' ? $_POST['match_date'] : null,
                                $_POST['team1_id'],
                                $_POST['team2_id'],
                                $_POST['team1_score'] !== '' ? $_POST['team1_score'] : null,
                                $_POST['team2_score'] !== '' ? $_POST['team2_score'] : null,
                                $_POST['winner_team_id'] !== '' ? $_POST['winner_team_id'] : null,
                                $_POST['status'],
                                $_POST['notes'],
                                $_POST['team_2v2_results']
                            ]);
                            $message = "Schedule entry added successfully!";
                        }
                    }
                    break;
                    
                case 'edit_schedule':
                    $stmt = $db->prepare("UPDATE fsl_schedule SET season=?, week_number=?, match_date=?, team1_id=?, team2_id=?, team1_score=?, team2_score=?, winner_team_id=?, status=?, notes=?, team_2v2_results=? WHERE schedule_id=?");
                    $stmt->execute([
                        $_POST['season'],
                        $_POST['week_number'],
                        $_POST['match_date'] !== '' ? $_POST['match_date'] : null,
                        $_POST['team1_id'],
                        $_POST['team2_id'],
                        $_POST['team1_score'] !== '' ? $_POST['team1_score'] : null,
                        $_POST['team2_score'] !== '' ? $_POST['team2_score'] : null,
                        $_POST['winner_team_id'] !== '' ? $_POST['winner_team_id'] : null,
                        $_POST['status'],
                        $_POST['notes'],
                        $_POST['team_2v2_results'],
                        $_POST['schedule_id']
                    ]);
                    $message = "Schedule entry updated successfully!";
                    break;
                    
                case 'auto_link_matches':
                    // Auto-link matches for a specific schedule entry
                    $schedule_id = $_POST['schedule_id'];
                    
                    // First, remove existing links for this schedule entry
                    $stmt = $db->prepare("DELETE FROM fsl_schedule_matches WHERE schedule_id = ?");
                    $stmt->execute([$schedule_id]);
                    
                    // Get schedule details
                    $stmt = $db->prepare("SELECT team1_id, team2_id, season, week_number FROM fsl_schedule WHERE schedule_id = ?");
                    $stmt->execute([$schedule_id]);
                    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($schedule) {
                        // Find and link matches that are NOT already linked to other schedule entries
                        $stmt = $db->prepare("
                            INSERT INTO fsl_schedule_matches (schedule_id, fsl_match_id, match_type)
                            SELECT ?, fm.fsl_match_id, 
                                CASE 
                                    WHEN fm.t_code = 'ACE' OR fm.season_extra_info LIKE '%Ace%' OR fm.notes LIKE '%ACE%' OR fm.notes LIKE '%Ace%' THEN 'ACE'
                                    ELSE COALESCE(fm.t_code, 'OTHER')
                                END
                            FROM fsl_matches fm
                            JOIN Players pw ON fm.winner_player_id = pw.Player_ID  
                            JOIN Players pl ON fm.loser_player_id = pl.Player_ID
                            WHERE fm.season = ?
                              AND ((pw.Team_ID = ? AND pl.Team_ID = ?) OR (pw.Team_ID = ? AND pl.Team_ID = ?))
                              AND fm.fsl_match_id NOT IN (
                                  SELECT fsl_match_id 
                                  FROM fsl_schedule_matches 
                                  WHERE schedule_id != ?
                              )
                            ORDER BY fm.fsl_match_id
                        ");
                        $stmt->execute([
                            $schedule_id, 
                            $schedule['season'],
                            $schedule['team1_id'], 
                            $schedule['team2_id'], 
                            $schedule['team2_id'], 
                            $schedule['team1_id'],
                            $schedule_id
                        ]);
                        
                        $count = $stmt->rowCount();
                        $message = "Auto-linked $count matches to this schedule entry! (Excluded matches already linked to other weeks)";
                    }
                    break;
                    
                case 'manual_link_match':
                    $stmt = $db->prepare("INSERT IGNORE INTO fsl_schedule_matches (schedule_id, fsl_match_id, match_type) VALUES (?, ?, ?)");
                    $stmt->execute([
                        $_POST['schedule_id'],
                        $_POST['fsl_match_id'],
                        $_POST['match_type']
                    ]);
                    $message = "Match linked successfully!";
                    break;
                    
                case 'unlink_match':
                    $stmt = $db->prepare("DELETE FROM fsl_schedule_matches WHERE schedule_id = ? AND fsl_match_id = ?");
                    $stmt->execute([
                        $_POST['schedule_id'],
                        $_POST['fsl_match_id']
                    ]);
                    $message = "Match unlinked successfully!";
                    break;
                    
                case 'edit_2v2_results':
                    $stmt = $db->prepare("UPDATE fsl_schedule SET team_2v2_results = ? WHERE schedule_id = ?");
                    $stmt->execute([
                        $_POST['team_2v2_results'],
                        $_POST['schedule_id']
                    ]);
                    $message = "2v2 results updated successfully!";
                    break;
            }
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Get teams for dropdowns
$teams = $db->query("SELECT Team_ID, Team_Name FROM Teams ORDER BY Team_Name")->fetchAll(PDO::FETCH_ASSOC);

// Get current schedule
$schedule = $db->query("
    SELECT 
        s.*,
        t1.Team_Name as team1_name,
        t2.Team_Name as team2_name,
        tw.Team_Name as winner_name
    FROM fsl_schedule s
    JOIN Teams t1 ON s.team1_id = t1.Team_ID
    JOIN Teams t2 ON s.team2_id = t2.Team_ID
    LEFT JOIN Teams tw ON s.winner_team_id = tw.Team_ID
    ORDER BY s.season DESC, s.week_number
")->fetchAll(PDO::FETCH_ASSOC);

// Get available matches for linking
$availableMatches = $db->query("
    SELECT 
        fm.fsl_match_id,
        fm.season,
        fm.t_code,
        fm.notes,
        pw.Real_Name as winner_name,
        pl.Real_Name as loser_name,
        pw.Team_ID as winner_team,
        pl.Team_ID as loser_team
    FROM fsl_matches fm
    JOIN Players pw ON fm.winner_player_id = pw.Player_ID
    JOIN Players pl ON fm.loser_player_id = pl.Player_ID
    WHERE fm.season = 9
    ORDER BY fm.fsl_match_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Set page title
$pageTitle = "FSL Schedule Admin";

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <div class="admin-header">
        <h1>FSL Schedule Administration</h1>
        <div class="admin-user-info">
            <span>Logged in as: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></span>
            <a href="logout.php" class="btn btn-logout">Logout</a>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="admin-tabs">
        <button class="tab-btn active" onclick="showTab('schedule', event)">Manage Schedule</button>
        <button class="tab-btn" onclick="showTab('matches', event)">Link Matches</button>
        <button class="tab-btn" onclick="showTab('add', event)">Add New Schedule</button>
    </div>

    <!-- Schedule Management Tab -->
    <div id="schedule-tab" class="tab-content active">
        <h2>Current Schedule</h2>
        <div class="schedule-list">
            <?php foreach ($schedule as $entry): ?>
                <div class="schedule-entry">
                    <div class="entry-header">
                        <h3>Season <?= $entry['season'] ?> - Week <?= $entry['week_number'] ?></h3>
                        <span class="status <?= $entry['status'] ?>"><?= ucfirst($entry['status']) ?></span>
                    </div>
                    
                    <div class="entry-content">
                        <div class="matchup">
                            <strong><?= htmlspecialchars($entry['team1_name']) ?></strong>
                            <span class="score"><?= $entry['team1_score'] !== null ? $entry['team1_score'] : '?' ?> - <?= $entry['team2_score'] !== null ? $entry['team2_score'] : '?' ?></span>
                            <strong><?= htmlspecialchars($entry['team2_name']) ?></strong>
                        </div>
                        
                        <?php if ($entry['winner_name']): ?>
                            <div class="winner">Winner: <?= htmlspecialchars($entry['winner_name']) ?></div>
                        <?php endif; ?>
                        
                        <div class="date">Date: <?= $entry['match_date'] ?></div>
                        
                        <?php if ($entry['notes']): ?>
                            <div class="notes">Notes: <?= htmlspecialchars($entry['notes']) ?></div>
                        <?php endif; ?>
                        
                        <?php if ($entry['team_2v2_results']): ?>
                            <div class="team-2v2-results"><?= htmlspecialchars($entry['team_2v2_results'] ?? '') ?></div>
                        <?php endif; ?>
                        
                        <!-- Quick Edit 2v2 Results -->
                        <div class="edit-2v2-form" style="margin-top: 15px;">
                            <form method="post" style="display: inline-block; width: 100%;">
                                <input type="hidden" name="action" value="edit_2v2_results">
                                <input type="hidden" name="schedule_id" value="<?= $entry['schedule_id'] ?>">
                                <div style="display: flex; gap: 10px; align-items: flex-start;">
                                    <textarea name="team_2v2_results" rows="2" style="flex: 1; resize: vertical;" placeholder="Enter 2v2 match results..."><?= htmlspecialchars($entry['team_2v2_results'] ?? '') ?></textarea>
                                    <button type="submit" class="btn btn-primary" style="white-space: nowrap;">Update 2v2</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <div class="entry-actions">
                        <button onclick="editSchedule(<?= $entry['schedule_id'] ?>)" class="btn btn-edit">Edit</button>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="action" value="auto_link_matches">
                            <input type="hidden" name="schedule_id" value="<?= $entry['schedule_id'] ?>">
                            <button type="submit" class="btn btn-link">Auto-Link Matches</button>
                        </form>
                        
                        <?php
                        // Get linked matches count
                        $stmt = $db->prepare("SELECT COUNT(*) FROM fsl_schedule_matches WHERE schedule_id = ?");
                        $stmt->execute([$entry['schedule_id']]);
                        $linkedCount = $stmt->fetchColumn();
                        ?>
                        <span class="linked-count"><?= $linkedCount ?> matches linked</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Match Linking Tab -->
    <div id="matches-tab" class="tab-content">
        <h2>Link Individual Matches</h2>
        
        <div class="link-section">
            <h3>Manual Match Linking</h3>
            <form method="post" class="link-form">
                <input type="hidden" name="action" value="manual_link_match">
                
                <div class="form-group">
                    <label>Schedule Entry:</label>
                    <select name="schedule_id" required>
                        <option value="">Select schedule entry...</option>
                        <?php foreach ($schedule as $entry): ?>
                            <option value="<?= $entry['schedule_id'] ?>">
                                Season <?= $entry['season'] ?> Week <?= $entry['week_number'] ?> - 
                                <?= htmlspecialchars($entry['team1_name']) ?> vs <?= htmlspecialchars($entry['team2_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Match:</label>
                    <select name="fsl_match_id" required>
                        <option value="">Select match...</option>
                        <?php foreach ($availableMatches as $match): ?>
                            <option value="<?= $match['fsl_match_id'] ?>">
                                #<?= $match['fsl_match_id'] ?> - <?= htmlspecialchars($match['winner_name']) ?> vs <?= htmlspecialchars($match['loser_name']) ?>
                                (<?= $match['t_code'] ?: 'No Code' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Match Type:</label>
                    <select name="match_type" required>
                        <option value="S">Code S</option>
                        <option value="A">Code A</option>
                        <option value="B">Code B</option>
                        <option value="2v2">2v2</option>
                        <option value="ACE">Ace</option>
                        <option value="OTHER">Other</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary">Link Match</button>
            </form>
        </div>
    </div>

    <!-- Add Schedule Tab -->
    <div id="add-tab" class="tab-content">
        <h2>Add New Schedule Entry</h2>
        
        <form method="post" class="schedule-form">
            <input type="hidden" name="action" value="add_schedule">
            
            <div class="form-row">
                <div class="form-group">
                    <label>Season:</label>
                    <input type="number" name="season" value="9" required>
                </div>
                
                <div class="form-group">
                    <label>Week Number:</label>
                    <input type="number" name="week_number" required>
                </div>
                
                <div class="form-group">
                    <label>Match Date:</label>
                    <input type="date" name="match_date">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Team 1:</label>
                    <select name="team1_id" required>
                        <option value="">Select team...</option>
                        <?php foreach ($teams as $team): ?>
                            <option value="<?= $team['Team_ID'] ?>"><?= htmlspecialchars($team['Team_Name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Team 2:</label>
                    <select name="team2_id" required>
                        <option value="">Select team...</option>
                        <?php foreach ($teams as $team): ?>
                            <option value="<?= $team['Team_ID'] ?>"><?= htmlspecialchars($team['Team_Name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Team 1 Score:</label>
                    <input type="number" name="team1_score" min="0" max="9">
                </div>
                
                <div class="form-group">
                    <label>Team 2 Score:</label>
                    <input type="number" name="team2_score" min="0" max="9">
                </div>
                
                <div class="form-group">
                    <label>Winner:</label>
                    <select name="winner_team_id">
                        <option value="">No winner yet...</option>
                        <?php foreach ($teams as $team): ?>
                            <option value="<?= $team['Team_ID'] ?>"><?= htmlspecialchars($team['Team_Name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>Status:</label>
                <select name="status" required>
                    <option value="scheduled">Scheduled</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Notes:</label>
                <textarea name="notes" rows="3" placeholder="Optional notes about the match..."></textarea>
            </div>
            
            <div class="form-group">
                <label>2v2 Match Results:</label>
                <textarea name="team_2v2_results" rows="3" placeholder="Enter 2v2 match results (e.g., 'Team A 2v2: Player1 & Player2 defeated Player3 & Player4')"></textarea>
                <small style="color: #888;">Manual entry for 2v2 results since they're not stored as individual matches</small>
            </div>
            
            <button type="submit" class="btn btn-primary">Add Schedule Entry</button>
        </form>
    </div>
</div>

<!-- Edit Schedule Modal (basic implementation) -->
<div id="editModal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close" onclick="closeEditModal()">&times;</span>
        <h2>Edit Schedule Entry</h2>
        <div id="editForm">
            <!-- Edit form will be loaded here via JavaScript -->
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
    
    .alert-error {
        background: rgba(220, 53, 69, 0.2);
        border: 1px solid #dc3545;
        color: #dc3545;
    }
    
    .admin-tabs {
        display: flex;
        margin-bottom: 30px;
        border-bottom: 2px solid rgba(0, 212, 255, 0.2);
    }
    
    .tab-btn {
        background: transparent;
        border: none;
        color: #ccc;
        padding: 15px 25px;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        transition: all 0.3s ease;
    }
    
    .tab-btn.active,
    .tab-btn:hover {
        color: #00d4ff;
        border-bottom-color: #00d4ff;
    }
    
    .tab-content {
        display: none;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 25px;
    }
    
    .tab-content.active {
        display: block;
    }
    
    .schedule-entry {
        background: rgba(0, 0, 0, 0.3);
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        border: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .entry-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
    }
    
    .entry-header h3 {
        color: #00d4ff;
        margin: 0;
    }
    
    .status {
        padding: 5px 15px;
        border-radius: 15px;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.8em;
    }
    
    .status.completed { background: #28a745; color: white; }
    .status.scheduled { background: #6c757d; color: white; }
    .status.in_progress { background: #ffc107; color: black; }
    .status.cancelled { background: #dc3545; color: white; }
    
    .matchup {
        font-size: 1.2em;
        text-align: center;
        margin-bottom: 10px;
    }
    
    .score {
        margin: 0 15px;
        color: #00d4ff;
        font-weight: bold;
    }
    
    .entry-actions {
        display: flex;
        gap: 10px;
        align-items: center;
        margin-top: 15px;
        flex-wrap: wrap;
    }
    
    .btn {
        padding: 8px 16px;
        border-radius: 4px;
        border: none;
        cursor: pointer;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.3s ease;
        display: inline-block;
    }
    
    .btn-primary { background: #00d4ff; color: #0f0c29; }
    .btn-edit { background: #ffc107; color: #0f0c29; }
    .btn-link { background: #28a745; color: white; }
    .btn-logout { background: #dc3545; color: white; }
    
    .btn:hover {
        opacity: 0.8;
        text-decoration: none;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-row {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
    }
    
    .form-row .form-group {
        flex: 1;
        min-width: 200px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 5px;
        color: #00d4ff;
        font-weight: 600;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 10px;
        border-radius: 4px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
        box-sizing: border-box;
    }
    
    .linked-count {
        color: #28a745;
        font-size: 0.9em;
    }
    
    .team-2v2-results {
        color: #ffc107;
        font-style: italic;
        margin-top: 8px;
    }
    
    .form-group small {
        display: block;
        margin-top: 5px;
        font-size: 0.85em;
    }
    
    @media (max-width: 768px) {
        .admin-header {
            flex-direction: column;
            gap: 15px;
        }
        
        .admin-tabs {
            flex-direction: column;
        }
        
        .form-row {
            flex-direction: column;
        }
        
        .entry-actions {
            flex-direction: column;
            align-items: flex-start;
        }
    }
</style>

<script>
    function showTab(tabName, event) {
        // Hide all tabs
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.classList.remove('active');
        });
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        
        // Show selected tab
        document.getElementById(tabName + '-tab').classList.add('active');
        
        // Only update button if event is provided
        if (event && event.target) {
            event.target.classList.add('active');
        }
        
        // Only reset form if switching to add tab AND we're not currently editing
        if (tabName === 'add' && !document.querySelector('input[name="editing_schedule_id"]')) {
            resetAddForm();
        }
    }
    
    function resetAddForm() {
        console.log('resetAddForm called'); // Debug log
        
        // Reset form to add mode
        document.querySelector('#add-tab form').reset();
        document.querySelector('input[name="season"]').value = '9';
        document.querySelector('input[name="action"]').value = 'add_schedule';
        document.querySelector('#add-tab .btn-primary').textContent = 'Add Schedule Entry';
        document.querySelector('#add-tab h2').textContent = 'Add New Schedule Entry';
        
        // Remove editing state
        let editingField = document.querySelector('input[name="editing_schedule_id"]');
        if (editingField) {
            editingField.remove();
        }
        
        console.log('Form reset complete'); // Debug log
    }
    
    function editSchedule(scheduleId) {
        console.log('Editing schedule ID:', scheduleId); // Debug log
        
        // Load schedule entry data and populate edit form
        fetch('admin_schedule.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=get_schedule_entry&schedule_id=' + scheduleId
        })
        .then(response => {
            console.log('Response status:', response.status); // Debug log
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('Raw response data:', data); // Debug log
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            if (!data || !data.schedule_id) {
                throw new Error('Invalid data received from server');
            }
            
            console.log('Loaded data:', data); // Debug log
            
            // Populate the add form with existing data
            document.querySelector('input[name="season"]').value = data.season || '';
            document.querySelector('input[name="week_number"]').value = data.week_number || '';
            document.querySelector('input[name="match_date"]').value = data.match_date || '';
            document.querySelector('select[name="team1_id"]').value = data.team1_id || '';
            document.querySelector('select[name="team2_id"]').value = data.team2_id || '';
            document.querySelector('input[name="team1_score"]').value = data.team1_score !== null ? data.team1_score : '';
            document.querySelector('input[name="team2_score"]').value = data.team2_score !== null ? data.team2_score : '';
            document.querySelector('select[name="winner_team_id"]').value = data.winner_team_id || '';
            document.querySelector('select[name="status"]').value = data.status || 'scheduled';
            document.querySelector('textarea[name="notes"]').value = data.notes || '';
            document.querySelector('textarea[name="team_2v2_results"]').value = data.team_2v2_results || '';
            
            console.log('Form populated with values:'); // Debug log
            console.log('Season:', document.querySelector('input[name="season"]').value);
            console.log('Week:', document.querySelector('input[name="week_number"]').value);
            console.log('Team1 Score:', document.querySelector('input[name="team1_score"]').value);
            console.log('Team2 Score:', document.querySelector('input[name="team2_score"]').value);
            
            // Add a hidden field to track which schedule we're editing
            let hiddenId = document.querySelector('input[name="editing_schedule_id"]');
            if (!hiddenId) {
                hiddenId = document.createElement('input');
                hiddenId.type = 'hidden';
                hiddenId.name = 'editing_schedule_id';
                document.querySelector('#add-tab form').appendChild(hiddenId);
            }
            hiddenId.value = data.schedule_id;
            
            // Change form action to update
            document.querySelector('input[name="action"]').value = 'add_schedule'; // This will now handle both add/update
            
            // Switch to add tab and update button text
            showTab('add');
            document.querySelector('#add-tab .btn-primary').textContent = 'Update Schedule Entry';
            document.querySelector('#add-tab h2').textContent = 'Edit Schedule Entry';
            
            console.log('Edit form setup complete'); // Debug log
            
            // Double-check form values after tab switch
            setTimeout(() => {
                console.log('Form values after tab switch:');
                console.log('Season:', document.querySelector('input[name="season"]').value);
                console.log('Week:', document.querySelector('input[name="week_number"]').value);
                console.log('Team1 Score:', document.querySelector('input[name="team1_score"]').value);
                console.log('Team2 Score:', document.querySelector('input[name="team2_score"]').value);
                console.log('Team1 ID:', document.querySelector('select[name="team1_id"]').value);
                console.log('Team2 ID:', document.querySelector('select[name="team2_id"]').value);
                console.log('Winner ID:', document.querySelector('select[name="winner_team_id"]').value);
                console.log('Status:', document.querySelector('select[name="status"]').value);
            }, 100);
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading schedule entry data: ' + error.message);
        });
    }
    
    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }
</script>

<?php include 'includes/footer.php'; ?> 