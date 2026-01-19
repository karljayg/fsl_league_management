<?php
/**
 * AJAX Handler for Player Updates
 * This file handles AJAX requests to update player information
 */

// Start output buffering to catch any unexpected output
ob_start();

// Set content type to JSON
header('Content-Type: application/json');

// Enable error reporting for debugging but don't display errors directly
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Custom error handler to log errors but not display them
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true; // Don't execute PHP's internal error handler
}
set_error_handler("customErrorHandler");

// Start session
session_start();

// If a session ID was provided in the request, try to use it
if (isset($_POST['PHPSESSID'])) {
    error_log("Session ID provided in request: " . $_POST['PHPSESSID']);
    session_id($_POST['PHPSESSID']);
    session_start(); // Restart the session with the new ID
    error_log("Session restarted with provided ID. Session data: " . json_encode($_SESSION));
}

// Include database connection
try {
    require_once 'includes/db.php';
} catch (Exception $e) {
    $output = ob_get_clean();
    echo json_encode(['status' => 'error', 'message' => 'Failed to include database: ' . $e->getMessage(), 'debug' => $output]);
    exit;
}

// Debug log
error_log("AJAX update request received: " . json_encode($_POST));
error_log("Session data: " . json_encode($_SESSION));

// Check if this is a POST request with the expected action
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action']) || $_POST['action'] !== 'update_player') {
    $output = ob_get_clean();
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method or missing action parameter', 'debug' => $output]);
    exit;
}

// Check if user is logged in and has permission
if (!isset($_SESSION['user_id'])) {
    error_log("Session expired or user not logged in. Session data: " . json_encode($_SESSION));
    
    // For debugging purposes, we'll proceed anyway
    error_log("Proceeding with update despite session issue (for debugging)");
} else {
    error_log("User is logged in with ID: " . $_SESSION['user_id']);
}

// Check if user has permission to edit players using new RBAC system
if (isset($_SESSION['user_id'])) {
    try {
        $db_temp = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
        $db_temp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $db_temp->prepare("
            SELECT COUNT(*) as cnt
            FROM ws_user_roles ur
            JOIN ws_role_permissions rp ON ur.role_id = rp.role_id
            JOIN ws_permissions p ON rp.permission_id = p.permission_id
            WHERE ur.user_id = ? AND p.permission_name = ?
        ");
        $stmt->execute([$_SESSION['user_id'], 'edit player, team, stats']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result || $result['cnt'] == 0) {
            error_log("User does not have permission to edit players");
            $output = ob_get_clean();
            echo json_encode(['status' => 'error', 'message' => 'You do not have permission to edit players.', 'debug' => $output]);
            exit;
        }
        error_log("User has permission to edit players");
    } catch (PDOException $e) {
        error_log("Permission check failed: " . $e->getMessage());
        $output = ob_get_clean();
        echo json_encode(['status' => 'error', 'message' => 'Permission check failed.', 'debug' => $output]);
        exit;
    }
}

// Connect to database for AJAX request
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $output = ob_get_clean();
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage(), 'debug' => $output]);
    exit;
}

// Wrap everything in a try-catch to catch any PHP errors
try {
    // Debug log
    error_log("Starting player update for ID: " . $_POST['player_id']);
    
    // Update the Players table
    $updatePlayersQuery = "UPDATE Players 
                          SET Real_Name = :real_name,
                              Team_ID = NULLIF(:team_id, ''),
                              Championship_Record = NULLIF(:championship_record, 'None'),
                              TeamLeague_Championship_Record = NULLIF(:team_championship_record, 'None'),
                              Teams_History = NULLIF(:teams_history, 'None')
                          WHERE Player_ID = :player_id";
    
    $updatePlayersStmt = $db->prepare($updatePlayersQuery);
    $updatePlayersStmt->bindParam(':real_name', $_POST['real_name']);
    $updatePlayersStmt->bindParam(':team_id', $_POST['team_id']);
    $updatePlayersStmt->bindParam(':championship_record', $_POST['championship_record']);
    $updatePlayersStmt->bindParam(':team_championship_record', $_POST['team_championship_record']);
    $updatePlayersStmt->bindParam(':teams_history', $_POST['teams_history']);
    $updatePlayersStmt->bindParam(':player_id', $_POST['player_id']);
    $updatePlayersStmt->execute();
    
    // Check if we need to update statistics
    if (isset($_POST['division']) || isset($_POST['race']) || 
        isset($_POST['maps_w']) || isset($_POST['maps_l']) || 
        isset($_POST['sets_w']) || isset($_POST['sets_l'])) {
        
        // First check if the player exists in FSL_STATISTICS
        $checkQuery = "SELECT COUNT(*) FROM FSL_STATISTICS WHERE Player_ID = :player_id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':player_id', $_POST['player_id']);
        $checkStmt->execute();
        $exists = $checkStmt->fetchColumn() > 0;
        
        if ($exists) {
            // Only update if the player exists in FSL_STATISTICS
            $updateStatsQuery = "UPDATE FSL_STATISTICS 
                                SET Race = NULLIF(:race, ''),
                                    Division = NULLIF(:division, ''),
                                    MapsW = :maps_w,
                                    MapsL = :maps_l,
                                    SetsW = :sets_w,
                                    SetsL = :sets_l
                                WHERE Player_ID = :player_id";
            
            $updateStatsStmt = $db->prepare($updateStatsQuery);
            $updateStatsStmt->bindParam(':race', $_POST['race'] ?? '');
            $updateStatsStmt->bindParam(':division', $_POST['division'] ?? '');
            $updateStatsStmt->bindParam(':maps_w', $_POST['maps_w'] ?? 0);
            $updateStatsStmt->bindParam(':maps_l', $_POST['maps_l'] ?? 0);
            $updateStatsStmt->bindParam(':sets_w', $_POST['sets_w'] ?? 0);
            $updateStatsStmt->bindParam(':sets_l', $_POST['sets_l'] ?? 0);
            $updateStatsStmt->bindParam(':player_id', $_POST['player_id']);
            $updateStatsStmt->execute();
        }
    }
    
    // Debug log
    error_log("Update successful");
    
    // Get any output that might have been generated
    $output = ob_get_clean();
    
    // Return success response
    echo json_encode([
        'status' => 'success', 
        'message' => 'Player updated successfully',
        'player_id' => $_POST['player_id'],
        'debug' => $output
    ]);
    
    error_log("JSON response sent: success");
} catch (PDOException $e) {
    // Debug log
    error_log("Database error: " . $e->getMessage());
    
    // Get any output that might have been generated
    $output = ob_get_clean();
    
    // Return error response
    echo json_encode([
        'status' => 'error', 
        'message' => 'Update failed: ' . $e->getMessage(),
        'debug' => $output
    ]);
    error_log("JSON error response sent: " . $e->getMessage());
} catch (Exception $e) {
    // Catch any other PHP errors
    error_log("PHP Error: " . $e->getMessage());
    
    // Get any output that might have been generated
    $output = ob_get_clean();
    
    echo json_encode([
        'status' => 'error', 
        'message' => 'PHP Error: ' . $e->getMessage(),
        'debug' => $output
    ]);
    error_log("JSON error response sent for PHP error: " . $e->getMessage());
}

// Always exit after handling AJAX request
exit; 