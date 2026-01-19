<?php
/**
 * Enhanced Player Statistics Handler (eps_handler.php)
 * Handles AJAX requests for the Enhanced Player Statistics Editor
 * Properly manages composite key constraints in the FSL_STATISTICS table
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
    error_log("EPS Handler request: " . $action . " - " . json_encode($_POST));
    
    // Handle different actions
    switch ($action) {
        case 'update_statistics':
            handleUpdateStatistics($db);
            break;
            
        case 'add_player':
            handleAddPlayer($db);
            break;
            
        case 'add_alias':
            handleAddAlias($db);
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
 * Handle updating player statistics
 */
function handleUpdateStatistics($db) {
    // Check if we have a player_id
    if (!isset($_POST['player_id']) || empty($_POST['player_id'])) {
        throw new Exception("Missing player_id parameter");
    }
    
    $playerId = $_POST['player_id'];
    
    // Begin transaction
    $db->beginTransaction();
    
    try {
        // If we have alias_id, division, and race, update statistics
        if (isset($_POST['alias_id']) && !empty($_POST['alias_id']) && 
            isset($_POST['division']) && !empty($_POST['division']) && 
            isset($_POST['race']) && !empty($_POST['race'])) {
            
            $aliasId = $_POST['alias_id'];
            $division = $_POST['division'];
            $race = $_POST['race'];
            
            // Ensure numeric values are set
            $mapsW = isset($_POST['maps_w']) ? intval($_POST['maps_w']) : 0;
            $mapsL = isset($_POST['maps_l']) ? intval($_POST['maps_l']) : 0;
            $setsW = isset($_POST['sets_w']) ? intval($_POST['sets_w']) : 0;
            $setsL = isset($_POST['sets_l']) ? intval($_POST['sets_l']) : 0;
            
            // Log incoming data for debugging
            error_log("=== STATISTICS UPDATE ATTEMPT ===");
            error_log("Player ID: $playerId, Alias ID: $aliasId, Division: $division, Race: $race");
            error_log("Maps: $mapsW/$mapsL, Sets: $setsW/$setsL");
            
            // First, find any existing record for this player/alias combination
            $checkQuery = "SELECT Player_Record_ID, Division, Race FROM FSL_STATISTICS 
                          WHERE Player_ID = :player_id 
                          AND Alias_ID = :alias_id";
            
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->bindParam(':player_id', $playerId);
            $checkStmt->bindParam(':alias_id', $aliasId);
            $checkStmt->execute();
            
            $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingRecord) {
                error_log("Found existing record ID: " . $existingRecord['Player_Record_ID'] . 
                         " with Division: " . $existingRecord['Division'] . ", Race: " . $existingRecord['Race']);
            } else {
                error_log("No existing record found for Player $playerId, Alias $aliasId");
            }
            
            if ($existingRecord) {
                // Check if the division/race are actually changing
                $divisionChanged = ($existingRecord['Division'] !== $division);
                $raceChanged = ($existingRecord['Race'] !== $race);
                
                if (!$divisionChanged && !$raceChanged) {
                    // Just updating stats for same division/race - safe to UPDATE
                    $updateQuery = "UPDATE FSL_STATISTICS 
                                  SET MapsW = :maps_w,
                                      MapsL = :maps_l,
                                      SetsW = :sets_w,
                                      SetsL = :sets_l
                                  WHERE Player_Record_ID = :record_id";
                    
                    $updateStmt = $db->prepare($updateQuery);
                    $updateStmt->bindParam(':maps_w', $mapsW);
                    $updateStmt->bindParam(':maps_l', $mapsL);
                    $updateStmt->bindParam(':sets_w', $setsW);
                    $updateStmt->bindParam(':sets_l', $setsL);
                    $updateStmt->bindParam(':record_id', $existingRecord['Player_Record_ID']);
                    $updateStmt->execute();
                    
                    error_log("Updated stats for existing record ID: " . $existingRecord['Player_Record_ID']);
                } else {
                    // Division or race is changing - check for conflicts
                    $conflictQuery = "SELECT Player_Record_ID, MapsW, MapsL, SetsW, SetsL FROM FSL_STATISTICS 
                                    WHERE Player_ID = :player_id 
                                    AND Alias_ID = :alias_id 
                                    AND Division = :division 
                                    AND Race = :race";
                    
                    $conflictStmt = $db->prepare($conflictQuery);
                    $conflictStmt->bindParam(':player_id', $playerId);
                    $conflictStmt->bindParam(':alias_id', $aliasId);
                    $conflictStmt->bindParam(':division', $division);
                    $conflictStmt->bindParam(':race', $race);
                    $conflictStmt->execute();
                    
                    $conflictRecord = $conflictStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($conflictRecord) {
                        // Conflict detected! Merge stats by adding them together
                        $mergedMapsW = $conflictRecord['MapsW'] + $mapsW;
                        $mergedMapsL = $conflictRecord['MapsL'] + $mapsL;
                        $mergedSetsW = $conflictRecord['SetsW'] + $setsW;
                        $mergedSetsL = $conflictRecord['SetsL'] + $setsL;
                        
                        error_log("CONFLICT DETECTED! Merging stats:");
                        error_log("Existing: Maps {$conflictRecord['MapsW']}/{$conflictRecord['MapsL']}, Sets {$conflictRecord['SetsW']}/{$conflictRecord['SetsL']}");
                        error_log("Adding: Maps $mapsW/$mapsL, Sets $setsW/$setsL");
                        error_log("Result: Maps $mergedMapsW/$mergedMapsL, Sets $mergedSetsW/$mergedSetsL");
                        
                        // Delete the old record
                        $deleteQuery = "DELETE FROM FSL_STATISTICS WHERE Player_Record_ID = :record_id";
                        $deleteStmt = $db->prepare($deleteQuery);
                        $deleteStmt->bindParam(':record_id', $existingRecord['Player_Record_ID']);
                        $deleteStmt->execute();
                        
                        // Update the conflicting record with merged stats
                        $updateConflictQuery = "UPDATE FSL_STATISTICS 
                                              SET MapsW = :maps_w,
                                                  MapsL = :maps_l,
                                                  SetsW = :sets_w,
                                                  SetsL = :sets_l
                                              WHERE Player_Record_ID = :record_id";
                        
                        $updateConflictStmt = $db->prepare($updateConflictQuery);
                        $updateConflictStmt->bindParam(':maps_w', $mergedMapsW);
                        $updateConflictStmt->bindParam(':maps_l', $mergedMapsL);
                        $updateConflictStmt->bindParam(':sets_w', $mergedSetsW);
                        $updateConflictStmt->bindParam(':sets_l', $mergedSetsL);
                        $updateConflictStmt->bindParam(':record_id', $conflictRecord['Player_Record_ID']);
                        $updateConflictStmt->execute();
                        
                        error_log("Merged stats into existing record ID: " . $conflictRecord['Player_Record_ID']);
                    } else {
                        // No conflict - safe to delete old and insert new
                        $deleteQuery = "DELETE FROM FSL_STATISTICS WHERE Player_Record_ID = :record_id";
                        $deleteStmt = $db->prepare($deleteQuery);
                        $deleteStmt->bindParam(':record_id', $existingRecord['Player_Record_ID']);
                        $deleteStmt->execute();
                        
                        $insertQuery = "INSERT INTO FSL_STATISTICS 
                                      (Player_ID, Alias_ID, Division, Race, MapsW, MapsL, SetsW, SetsL)
                                      VALUES 
                                      (:player_id, :alias_id, :division, :race, :maps_w, :maps_l, :sets_w, :sets_l)";
                        
                        $insertStmt = $db->prepare($insertQuery);
                        $insertStmt->bindParam(':player_id', $playerId);
                        $insertStmt->bindParam(':alias_id', $aliasId);
                        $insertStmt->bindParam(':division', $division);
                        $insertStmt->bindParam(':race', $race);
                        $insertStmt->bindParam(':maps_w', $mapsW);
                        $insertStmt->bindParam(':maps_l', $mapsL);
                        $insertStmt->bindParam(':sets_w', $setsW);
                        $insertStmt->bindParam(':sets_l', $setsL);
                        $insertStmt->execute();
                        
                        $newRecordId = $db->lastInsertId();
                        error_log("Moved record from Division: {$existingRecord['Division']}, Race: {$existingRecord['Race']} to Division: $division, Race: $race");
                    }
                }
            } else {
                // Insert new record
                $insertQuery = "INSERT INTO FSL_STATISTICS 
                              (Player_ID, Alias_ID, Division, Race, MapsW, MapsL, SetsW, SetsL)
                              VALUES 
                              (:player_id, :alias_id, :division, :race, :maps_w, :maps_l, :sets_w, :sets_l)";
                
                $insertStmt = $db->prepare($insertQuery);
                $insertStmt->bindParam(':player_id', $playerId);
                $insertStmt->bindParam(':alias_id', $aliasId);
                $insertStmt->bindParam(':division', $division);
                $insertStmt->bindParam(':race', $race);
                $insertStmt->bindParam(':maps_w', $mapsW);
                $insertStmt->bindParam(':maps_l', $mapsL);
                $insertStmt->bindParam(':sets_w', $setsW);
                $insertStmt->bindParam(':sets_l', $setsL);
                $insertStmt->execute();
                
                $recordId = $db->lastInsertId();
                error_log("Inserted new statistics record ID: $recordId for Player: $playerId, Alias: $aliasId");
            }
        }
        
        // If we have real_name and team_id, update player info
        if (isset($_POST['real_name']) && !empty($_POST['real_name'])) {
            $realName = $_POST['real_name'];
            $teamId = isset($_POST['team_id']) && !empty($_POST['team_id']) ? $_POST['team_id'] : null;
            $status = isset($_POST['status']) && !empty($_POST['status']) ? $_POST['status'] : 'active'; // Default to active if not provided
            
            $updateQuery = "UPDATE Players 
                          SET Real_Name = :real_name,
                              Team_ID = :team_id,
                              Status = :status
                          WHERE Player_ID = :player_id";
            
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':real_name', $realName);
            $updateStmt->bindParam(':team_id', $teamId);
            $updateStmt->bindParam(':status', $status);
            $updateStmt->bindParam(':player_id', $playerId);
            $updateStmt->execute();
            
            error_log("Updated player info for ID: $playerId with status: $status");
        }
        
        // Commit the transaction
        $db->commit();
        error_log("Transaction committed successfully");
        
        // Return success response
        $output = ob_get_clean();
        echo json_encode([
            'status' => 'success',
            'message' => 'Statistics updated successfully',
            'player_id' => $playerId,
            'debug' => $output
        ]);
    } catch (Exception $e) {
        // Rollback the transaction on error
        $db->rollBack();
        throw $e;
    }
}

/**
 * Handle adding a new player
 */
function handleAddPlayer($db) {
    // Check required fields
    if (!isset($_POST['real_name']) || empty($_POST['real_name'])) {
        throw new Exception("Missing real_name parameter");
    }
    
    if (!isset($_POST['alias_name']) || empty($_POST['alias_name'])) {
        throw new Exception("Missing alias_name parameter");
    }
    
    $realName = $_POST['real_name'];
    $aliasName = $_POST['alias_name'];
    $teamId = isset($_POST['team_id']) && !empty($_POST['team_id']) ? $_POST['team_id'] : null;
    
    // Begin transaction
    $db->beginTransaction();
    
    try {
        // Insert new player
        $insertPlayerQuery = "INSERT INTO Players (Real_Name, Team_ID) VALUES (:real_name, :team_id)";
        $insertPlayerStmt = $db->prepare($insertPlayerQuery);
        $insertPlayerStmt->bindParam(':real_name', $realName);
        $insertPlayerStmt->bindParam(':team_id', $teamId);
        $insertPlayerStmt->execute();
        
        $playerId = $db->lastInsertId();
        error_log("Inserted new player ID: $playerId");
        
        // Insert new alias
        $insertAliasQuery = "INSERT INTO Player_Aliases (Player_ID, Alias_Name) VALUES (:player_id, :alias_name)";
        $insertAliasStmt = $db->prepare($insertAliasQuery);
        $insertAliasStmt->bindParam(':player_id', $playerId);
        $insertAliasStmt->bindParam(':alias_name', $aliasName);
        $insertAliasStmt->execute();
        
        $aliasId = $db->lastInsertId();
        error_log("Inserted new alias ID: $aliasId");
        
        // If we have division and race, insert statistics
        if (isset($_POST['division']) && !empty($_POST['division']) && 
            isset($_POST['race']) && !empty($_POST['race'])) {
            
            $division = $_POST['division'];
            $race = $_POST['race'];
            
            // Ensure numeric values are set
            $mapsW = isset($_POST['maps_w']) ? intval($_POST['maps_w']) : 0;
            $mapsL = isset($_POST['maps_l']) ? intval($_POST['maps_l']) : 0;
            $setsW = isset($_POST['sets_w']) ? intval($_POST['sets_w']) : 0;
            $setsL = isset($_POST['sets_l']) ? intval($_POST['sets_l']) : 0;
            
            // Insert statistics
            $insertStatsQuery = "INSERT INTO FSL_STATISTICS 
                               (Player_ID, Alias_ID, Division, Race, MapsW, MapsL, SetsW, SetsL)
                               VALUES 
                               (:player_id, :alias_id, :division, :race, :maps_w, :maps_l, :sets_w, :sets_l)";
            
            $insertStatsStmt = $db->prepare($insertStatsQuery);
            $insertStatsStmt->bindParam(':player_id', $playerId);
            $insertStatsStmt->bindParam(':alias_id', $aliasId);
            $insertStatsStmt->bindParam(':division', $division);
            $insertStatsStmt->bindParam(':race', $race);
            $insertStatsStmt->bindParam(':maps_w', $mapsW);
            $insertStatsStmt->bindParam(':maps_l', $mapsL);
            $insertStatsStmt->bindParam(':sets_w', $setsW);
            $insertStatsStmt->bindParam(':sets_l', $setsL);
            $insertStatsStmt->execute();
            
            error_log("Inserted statistics for player ID: $playerId, alias ID: $aliasId");
        }
        
        // Commit the transaction
        $db->commit();
        
        // Return success response
        $output = ob_get_clean();
        echo json_encode([
            'status' => 'success',
            'message' => 'Player added successfully',
            'player_id' => $playerId,
            'alias_id' => $aliasId,
            'debug' => $output
        ]);
    } catch (Exception $e) {
        // Rollback the transaction on error
        $db->rollBack();
        throw $e;
    }
}

/**
 * Handle adding a new alias for an existing player
 */
function handleAddAlias($db) {
    // Check required fields
    if (!isset($_POST['player_id']) || empty($_POST['player_id'])) {
        throw new Exception("Missing player_id parameter");
    }
    
    if (!isset($_POST['alias_name']) || empty($_POST['alias_name'])) {
        throw new Exception("Missing alias_name parameter");
    }
    
    $playerId = $_POST['player_id'];
    $aliasName = $_POST['alias_name'];
    
    // Begin transaction
    $db->beginTransaction();
    
    try {
        // Check if the player exists
        $checkPlayerQuery = "SELECT COUNT(*) FROM Players WHERE Player_ID = :player_id";
        $checkPlayerStmt = $db->prepare($checkPlayerQuery);
        $checkPlayerStmt->bindParam(':player_id', $playerId);
        $checkPlayerStmt->execute();
        
        if ($checkPlayerStmt->fetchColumn() == 0) {
            throw new Exception("Player with ID $playerId does not exist");
        }
        
        // Check if the alias already exists
        $checkAliasQuery = "SELECT COUNT(*) FROM Player_Aliases WHERE Alias_Name = :alias_name";
        $checkAliasStmt = $db->prepare($checkAliasQuery);
        $checkAliasStmt->bindParam(':alias_name', $aliasName);
        $checkAliasStmt->execute();
        
        if ($checkAliasStmt->fetchColumn() > 0) {
            throw new Exception("Alias '$aliasName' already exists");
        }
        
        // Insert new alias
        $insertAliasQuery = "INSERT INTO Player_Aliases (Player_ID, Alias_Name) VALUES (:player_id, :alias_name)";
        $insertAliasStmt = $db->prepare($insertAliasQuery);
        $insertAliasStmt->bindParam(':player_id', $playerId);
        $insertAliasStmt->bindParam(':alias_name', $aliasName);
        $insertAliasStmt->execute();
        
        $aliasId = $db->lastInsertId();
        error_log("Inserted new alias ID: $aliasId for player ID: $playerId");
        
        // Commit the transaction
        $db->commit();
        
        // Return success response
        $output = ob_get_clean();
        echo json_encode([
            'status' => 'success',
            'message' => 'Alias added successfully',
            'player_id' => $playerId,
            'alias_id' => $aliasId,
            'debug' => $output
        ]);
    } catch (Exception $e) {
        // Rollback the transaction on error
        $db->rollBack();
        throw $e;
    }
} 
