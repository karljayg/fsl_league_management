<?php
/**
 * Apply Leaderboard Test Data
 * This script applies permanent test data for the leaderboard page
 */

// Include database connection
require_once 'includes/db.php';

// Set headers for better readability in browser
header('Content-Type: text/plain');

echo "=================================================\n";
echo "StormClash Leaderboard Test Data\n";
echo "=================================================\n\n";

try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to database successfully.\n\n";
    
    // Read SQL from file
    $sql = file_get_contents('leaderboard_data_fixed2.sql');
    
    // Execute the SQL as a single transaction
    echo "Applying test data...\n";
    
    $db->beginTransaction();
    $db->exec($sql);
    $db->commit();
    
    // Count the data that was inserted
    $userCount = $db->query("SELECT COUNT(*) FROM users WHERE username LIKE 'ProGamer%' OR username LIKE 'Player%'")->fetchColumn();
    $matchCount = $db->query("SELECT COUNT(*) FROM matches WHERE status = 'completed'")->fetchColumn();
    $playerCount = $db->query("SELECT COUNT(*) FROM match_players")->fetchColumn();
    $bidCount = $db->query("SELECT COUNT(*) FROM bids WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'Player%')")->fetchColumn();
    
    echo "\nTest data applied successfully!\n";
    echo "--------------------------------\n";
    echo "Added $userCount test users\n";
    echo "Added $matchCount completed matches\n";
    echo "Added $playerCount match players\n";
    echo "Added $bidCount bids\n";
    
    echo "\nYou can now view this data on your leaderboard page.\n";
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    
    // If there was an error, roll back the transaction
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    echo "\nTest data could not be applied. Please check the error message above.\n";
}

echo "\n=================================================\n";
echo "Data insertion completed\n";
echo "=================================================\n"; 