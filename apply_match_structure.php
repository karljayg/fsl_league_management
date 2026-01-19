<?php
/**
 * Apply Match Structure Changes
 * This script applies the necessary database structure changes for matches
 */

// Include database connection
require_once 'includes/db.php';

// Set headers for better readability in browser
header('Content-Type: text/plain');

echo "=================================================\n";
echo "StormClash Match Structure Update\n";
echo "=================================================\n\n";

try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to database successfully.\n\n";
    
    // Read SQL from file
    $sql = file_get_contents('match_structure.sql');
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map(
            'trim',
            explode(';', $sql)
        ),
        function($statement) {
            return !empty($statement) && strpos($statement, '--') !== 0;
        }
    );
    
    // Execute each statement
    echo "Applying database changes:\n";
    foreach ($statements as $statement) {
        if (empty(trim($statement))) continue;
        
        echo "- Executing: " . substr(trim($statement), 0, 60) . "...\n";
        $db->exec($statement);
    }
    
    echo "\nAll database changes applied successfully!\n";
    echo "\nYou can now use the new match structure in your application.\n";
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "\nDatabase changes could not be applied. Please check the error message above.\n";
}

echo "\n=================================================\n";
echo "Update completed\n";
echo "=================================================\n"; 