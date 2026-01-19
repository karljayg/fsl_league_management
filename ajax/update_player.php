<?php
/**
 * AJAX Handler for Player Updates
 * This file handles AJAX requests to update player information
 */

// Start output buffering to catch any unexpected output
ob_start();

// Set content type to JSON
header('Content-Type: application/json');

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include database connection
try {
    require_once '../includes/db.php';
    error_log("Successfully included db.php");
} catch (Exception $e) {
    error_log("Error including db.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error including database connection: ' . $e->getMessage()]);
    exit;
}

// Debug log
error_log("AJAX update request received: " . json_encode($_POST));

// Check if this is a POST request with the expected action
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action']) || $_POST['action'] !== 'update_player') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method or missing action parameter']);
    exit;
}

// Check if user is logged in and has permission
if (!isset($_SESSION['user_id'])) {
    error_log("Session expired or user not logged in");
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please refresh the page and log in again.']);
    exit;
}

// Check if user has permission to edit players
if (!isset($_SESSION['permissions']) || !in_array('edit player', $_SESSION['permissions'])) {
    error_log("User does not have permission to edit players");
    echo json_encode(['status' => 'error', 'message' => 'You do not have permission to edit players.']);
    exit;
}

// Connect to database for AJAX request
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Wrap everything in a try-catch to catch any PHP errors
try {
    try {
        // Debug log
        error_log("Starting player update for ID: " . $_POST['player_id']);
        
        // Prepare update statement for player
        $updateQuery = "UPDATE Players p
            LEFT JOIN FSL_STATISTICS s ON p.Player_ID = s.Player_ID
            SET 
                p.Real_Name = :real_name,
                p.Team_ID = NULLIF(:team_id, ''),
                s.Race = NULLIF(:race, ''),
                s.Division = NULLIF(:division, ''),
                s.MapsW = :maps_w,
                s.MapsL = :maps_l,
                s.SetsW = :sets_w,
                s.SetsL = :sets_l,
                p.Championship_Record = NULLIF(:championship_record, 'None'),
                p.TeamLeague_Championship_Record = NULLIF(:team_championship_record, 'None'),
                p.Teams_History = NULLIF(:teams_history, 'None')
            WHERE p.Player_ID = :player_id";
        
        $stmt = $db->prepare($updateQuery);
        
        // Bind parameters
        $stmt->bindParam(':real_name', $_POST['real_name']);
        $stmt->bindParam(':team_id', $_POST['team_id']);
        $stmt->bindParam(':race', $_POST['race']);
        $stmt->bindParam(':division', $_POST['division']);
        $stmt->bindParam(':maps_w', $_POST['maps_w']);
        $stmt->bindParam(':maps_l', $_POST['maps_l']);
        $stmt->bindParam(':sets_w', $_POST['sets_w']);
        $stmt->bindParam(':sets_l', $_POST['sets_l']);
        $stmt->bindParam(':championship_record', $_POST['championship_record']);
        $stmt->bindParam(':team_championship_record', $_POST['team_championship_record']);
        $stmt->bindParam(':teams_history', $_POST['teams_history']);
        $stmt->bindParam(':player_id', $_POST['player_id']);
        
        // Debug log
        error_log("Executing update query");
        
        $stmt->execute();
        
        // Debug log
        error_log("Update successful");
        
        // Check for any unexpected output
        $output = ob_get_clean();
        if (!empty($output)) {
            error_log("Unexpected output before JSON response: " . $output);
        }
        
        // Return success response
        echo json_encode(['status' => 'success', 'message' => 'Player updated successfully']);
        error_log("JSON response sent: " . json_encode(['status' => 'success', 'message' => 'Player updated successfully']));
    } catch (PDOException $e) {
        // Debug log
        error_log("Database error: " . $e->getMessage());
        
        // Check for any unexpected output
        $output = ob_get_clean();
        if (!empty($output)) {
            error_log("Unexpected output before JSON error response: " . $output);
        }
        
        // Return error response
        echo json_encode(['status' => 'error', 'message' => 'Update failed: ' . $e->getMessage()]);
        error_log("JSON error response sent: " . json_encode(['status' => 'error', 'message' => 'Update failed: ' . $e->getMessage()]));
    }
} catch (Exception $e) {
    // Catch any other PHP errors
    error_log("PHP Error: " . $e->getMessage());
    
    // Check for any unexpected output
    $output = ob_get_clean();
    if (!empty($output)) {
        error_log("Unexpected output before JSON error response: " . $output);
    }
    
    echo json_encode(['status' => 'error', 'message' => 'PHP Error: ' . $e->getMessage()]);
    error_log("JSON error response sent for PHP error: " . json_encode(['status' => 'error', 'message' => 'PHP Error: ' . $e->getMessage()]));
}

// Always exit after handling AJAX request
exit; 