<?php
/**
 * Apply Upcoming Matches Data
 * This script applies the upcoming matches data to the database for FSL Pros and Joes
 */

// Include database connection
require_once 'includes/db.php';

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h1>Applying Upcoming Matches Data</h1>";
    
    // Read the SQL file
    $sql = file_get_contents('upcoming_matches.sql');
    
    // Execute the SQL
    $db->exec($sql);
    
    echo "<p>Upcoming matches data has been successfully applied to the database.</p>";
    echo "<p>You can now view the upcoming matches on the <a href='matches.php'>Matches page</a>.</p>";
    
} catch (PDOException $e) {
    echo "<h1>Error</h1>";
    echo "<p>Database error: " . $e->getMessage() . "</p>";
}
?> 