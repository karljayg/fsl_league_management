<?php
/**
 * FSL Matches Editor Handler
 * Handles AJAX requests for the FSL Matches Editor
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

// Include database connection
try {
    require_once 'includes/db.php';
} catch (Exception $e) {
    $output = ob_get_clean();
    echo json_encode([
        'status' => 'error', 
        'message' => 'Failed to include database: ' . $e->getMessage(), 
        'debug' => $output
    ]);
    exit;
}

// Check permission
$required_permission = 'manage fsl schedule';
try {
    include 'includes/check_permission_updated.php';
} catch (Exception $e) {
    $output = ob_get_clean();
    echo json_encode([
        'status' => 'error', 
        'message' => 'Permission check failed: ' . $e->getMessage(), 
        'debug' => $output
    ]);
    exit;
}

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $output = ob_get_clean();
    echo json_encode([
        'status' => 'error', 
        'message' => 'Database connection failed: ' . $e->getMessage(), 
        'debug' => $output
    ]);
    exit;
}

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $output = ob_get_clean();
    echo json_encode([
        'status' => 'error', 
        'message' => 'Invalid request method. Expected POST.', 
        'debug' => $output
    ]);
    exit;
}

// Wrap everything in a try-catch to catch any PHP errors
try {
    // Get the action
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    
    // Log the request
    error_log("FSL Matches Handler request: " . $action . " - " . json_encode($_POST));
    
    // Handle different actions
    switch ($action) {
        case 'update_match':
            handleUpdateMatch($db);
            break;
            
        case 'add_match':
            handleAddMatch($db);
            break;
            
        case 'delete_match':
            handleDeleteMatch($db);
            break;
            
        default:
            throw new Exception("Unknown action: " . $action);
    }
} catch (PDOException $e) {
    // Database error
    error_log("Database error: " . $e->getMessage());
    $output = ob_get_clean();
    echo json_encode([
        'status' => 'error', 
        'message' => 'Database error: ' . $e->getMessage(), 
        'debug' => $output
    ]);
} catch (Exception $e) {
    // General error
    error_log("Error: " . $e->getMessage());
    $output = ob_get_clean();
    echo json_encode([
        'status' => 'error', 
        'message' => $e->getMessage(), 
        'debug' => $output
    ]);
}

/**
 * Handle updating an existing match
 */
function handleUpdateMatch($db) {
    // Check if we have a match_id
    if (!isset($_POST['match_id']) || empty($_POST['match_id'])) {
        throw new Exception("Missing match_id parameter");
    }
    
    $matchId = $_POST['match_id'];
    
    // Validate required fields
    $requiredFields = ['season', 'player_a_id', 'player_a_race', 'player_b_id', 'player_b_race', 'best_of', 'score_a', 'score_b'];
    foreach ($requiredFields as $field) {
        if (!isset($_POST[$field]) || $_POST[$field] === '') {
            throw new Exception("Missing required field: $field");
        }
    }
    
    // Validate numeric fields
    $numericFields = ['season', 'player_a_id', 'player_b_id', 'best_of', 'score_a', 'score_b'];
    foreach ($numericFields as $field) {
        if (!is_numeric($_POST[$field]) || $_POST[$field] < 0) {
            throw new Exception("Invalid value for field: $field");
        }
    }
    
    // Validate that Player A and Player B are different players
    if ($_POST['player_a_id'] == $_POST['player_b_id']) {
        throw new Exception("Player A and Player B must be different players");
    }
    
    // Calculate winner/loser from scores
    $scoreA = intval($_POST['score_a']);
    $scoreB = intval($_POST['score_b']);
    
    if ($scoreA > $scoreB) {
        // Player A wins
        $winner_player_id = $_POST['player_a_id'];
        $winner_race = $_POST['player_a_race'];
        $loser_player_id = $_POST['player_b_id'];
        $loser_race = $_POST['player_b_race'];
        $map_win = $scoreA;
        $map_loss = $scoreB;
    } elseif ($scoreA < $scoreB) {
        // Player B wins
        $winner_player_id = $_POST['player_b_id'];
        $winner_race = $_POST['player_b_race'];
        $loser_player_id = $_POST['player_a_id'];
        $loser_race = $_POST['player_a_race'];
        $map_win = $scoreB;
        $map_loss = $scoreA;
    } else {
        // Tie: Player A wins by default (maintains current behavior)
        $winner_player_id = $_POST['player_a_id'];
        $winner_race = $_POST['player_a_race'];
        $loser_player_id = $_POST['player_b_id'];
        $loser_race = $_POST['player_b_race'];
        $map_win = $scoreA;
        $map_loss = $scoreB;
    }
    
    // Begin transaction
    $db->beginTransaction();
    
    try {
        $winner_team_id = !empty($_POST['winner_team_id']) ? $_POST['winner_team_id'] : null;
        $loser_team_id = !empty($_POST['loser_team_id']) ? $_POST['loser_team_id'] : null;

        // Update the match
        $updateQuery = "UPDATE fsl_matches SET 
                        season = :season,
                        season_extra_info = :season_extra_info,
                        notes = :notes,
                        t_code = :t_code,
                        winner_player_id = :winner_player_id,
                        winner_race = :winner_race,
                        best_of = :best_of,
                        map_win = :map_win,
                        map_loss = :map_loss,
                        loser_player_id = :loser_player_id,
                        loser_race = :loser_race,
                        winner_team_id = :winner_team_id,
                        loser_team_id = :loser_team_id,
                        source = :source,
                        vod = :vod
                        WHERE fsl_match_id = :match_id";
        
        $stmt = $db->prepare($updateQuery);
        $stmt->bindParam(':match_id', $matchId);
        $stmt->bindParam(':season', $_POST['season']);
        $stmt->bindParam(':season_extra_info', $_POST['season_extra_info']);
        $stmt->bindParam(':notes', $_POST['notes']);
        $stmt->bindParam(':t_code', $_POST['t_code']);
        $stmt->bindParam(':winner_player_id', $winner_player_id);
        $stmt->bindParam(':winner_race', $winner_race);
        $stmt->bindParam(':best_of', $_POST['best_of']);
        $stmt->bindParam(':map_win', $map_win);
        $stmt->bindParam(':map_loss', $map_loss);
        $stmt->bindParam(':loser_player_id', $loser_player_id);
        $stmt->bindParam(':loser_race', $loser_race);
        $stmt->bindParam(':winner_team_id', $winner_team_id);
        $stmt->bindParam(':loser_team_id', $loser_team_id);
        $stmt->bindParam(':source', $_POST['source']);
        $stmt->bindParam(':vod', $_POST['vod']);
        $stmt->execute();
        
        // Check if any rows were affected
        if ($stmt->rowCount() === 0) {
            throw new Exception("No match found with ID: $matchId");
        }
        
        // Commit transaction
        $db->commit();
        
        error_log("Successfully updated match ID: $matchId");
        
        $output = ob_get_clean();
        echo json_encode([
            'status' => 'success', 
            'message' => 'Match updated successfully',
            'debug' => $output
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * Handle adding a new match
 */
function handleAddMatch($db) {
    // Validate required fields
    $requiredFields = ['season', 'player_a_id', 'player_a_race', 'player_b_id', 'player_b_race', 'best_of', 'score_a', 'score_b'];
    foreach ($requiredFields as $field) {
        if (!isset($_POST[$field]) || $_POST[$field] === '') {
            throw new Exception("Missing required field: $field");
        }
    }
    
    // Validate numeric fields
    $numericFields = ['season', 'player_a_id', 'player_b_id', 'best_of', 'score_a', 'score_b'];
    foreach ($numericFields as $field) {
        if (!is_numeric($_POST[$field]) || $_POST[$field] < 0) {
            throw new Exception("Invalid value for field: $field");
        }
    }
    
    // Validate that Player A and Player B are different players
    if ($_POST['player_a_id'] == $_POST['player_b_id']) {
        throw new Exception("Player A and Player B must be different players");
    }
    
    // Calculate winner/loser from scores
    $scoreA = intval($_POST['score_a']);
    $scoreB = intval($_POST['score_b']);
    
    if ($scoreA > $scoreB) {
        // Player A wins
        $winner_player_id = $_POST['player_a_id'];
        $winner_race = $_POST['player_a_race'];
        $loser_player_id = $_POST['player_b_id'];
        $loser_race = $_POST['player_b_race'];
        $map_win = $scoreA;
        $map_loss = $scoreB;
    } elseif ($scoreA < $scoreB) {
        // Player B wins
        $winner_player_id = $_POST['player_b_id'];
        $winner_race = $_POST['player_b_race'];
        $loser_player_id = $_POST['player_a_id'];
        $loser_race = $_POST['player_a_race'];
        $map_win = $scoreB;
        $map_loss = $scoreA;
    } else {
        // Tie: Player A wins by default (maintains current behavior)
        $winner_player_id = $_POST['player_a_id'];
        $winner_race = $_POST['player_a_race'];
        $loser_player_id = $_POST['player_b_id'];
        $loser_race = $_POST['player_b_race'];
        $map_win = $scoreA;
        $map_loss = $scoreB;
    }

    $team_a_id = !empty($_POST['team_a_id']) ? $_POST['team_a_id'] : null;
    $team_b_id = !empty($_POST['team_b_id']) ? $_POST['team_b_id'] : null;
    if ($scoreA > $scoreB) {
        $winner_team_id = $team_a_id;
        $loser_team_id = $team_b_id;
    } else {
        $winner_team_id = $team_b_id;
        $loser_team_id = $team_a_id;
    }
    
    // Begin transaction
    $db->beginTransaction();
    
    try {
        // Insert the new match
        $insertQuery = "INSERT INTO fsl_matches 
                        (season, season_extra_info, notes, t_code, winner_player_id, winner_race, 
                         best_of, map_win, map_loss, loser_player_id, loser_race, winner_team_id, loser_team_id, source, vod)
                        VALUES 
                        (:season, :season_extra_info, :notes, :t_code, :winner_player_id, :winner_race,
                         :best_of, :map_win, :map_loss, :loser_player_id, :loser_race, :winner_team_id, :loser_team_id, :source, :vod)";
        
        $stmt = $db->prepare($insertQuery);
        $stmt->bindParam(':season', $_POST['season']);
        $stmt->bindParam(':season_extra_info', $_POST['season_extra_info']);
        $stmt->bindParam(':notes', $_POST['notes']);
        $stmt->bindParam(':t_code', $_POST['t_code']);
        $stmt->bindParam(':winner_player_id', $winner_player_id);
        $stmt->bindParam(':winner_race', $winner_race);
        $stmt->bindParam(':best_of', $_POST['best_of']);
        $stmt->bindParam(':map_win', $map_win);
        $stmt->bindParam(':map_loss', $map_loss);
        $stmt->bindParam(':loser_player_id', $loser_player_id);
        $stmt->bindParam(':loser_race', $loser_race);
        $stmt->bindParam(':winner_team_id', $winner_team_id);
        $stmt->bindParam(':loser_team_id', $loser_team_id);
        $stmt->bindParam(':source', $_POST['source']);
        $stmt->bindParam(':vod', $_POST['vod']);
        $stmt->execute();
        
        $newMatchId = $db->lastInsertId();
        
        // Commit transaction
        $db->commit();
        
        error_log("Successfully added new match ID: $newMatchId");
        
        $output = ob_get_clean();
        echo json_encode([
            'status' => 'success', 
            'message' => 'Match added successfully',
            'match_id' => $newMatchId,
            'debug' => $output
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * Handle deleting a match
 */
function handleDeleteMatch($db) {
    // Check if we have a match_id
    if (!isset($_POST['match_id']) || empty($_POST['match_id'])) {
        throw new Exception("Missing match_id parameter");
    }
    
    $matchId = $_POST['match_id'];
    
    // Begin transaction
    $db->beginTransaction();
    
    try {
        // Delete the match
        $deleteQuery = "DELETE FROM fsl_matches WHERE fsl_match_id = :match_id";
        $stmt = $db->prepare($deleteQuery);
        $stmt->bindParam(':match_id', $matchId);
        $stmt->execute();
        
        // Check if any rows were affected
        if ($stmt->rowCount() === 0) {
            throw new Exception("No match found with ID: $matchId");
        }
        
        // Commit transaction
        $db->commit();
        
        error_log("Successfully deleted match ID: $matchId");
        
        $output = ob_get_clean();
        echo json_encode([
            'status' => 'success', 
            'message' => 'Match deleted successfully',
            'debug' => $output
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}
?> 