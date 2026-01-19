<?php
/**
 * Script to apply database changes for the match structure
 */

// Set content type to HTML for better browser display
header('Content-Type: text/html');
echo "<pre>";

// Include database connection
if (!file_exists('config.php')) {
    die("Error: config.php file not found. Please create it with your database configuration.");
}

require_once 'config.php';

echo "Applying database changes for match structure...\n";

try {
    // Check if config variables are set
    if (!isset($config['db_host']) || !isset($config['db_name']) || !isset($config['db_user'])) {
        die("Error: Database configuration is incomplete. Please check your config.php file.");
    }
    
    // Connect to the database
    $db = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']}", 
        $config['db_user'], 
        $config['db_pass']
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to database successfully.\n";
    
    // Check if SQL file exists
    if (!file_exists('update_match_table.sql')) {
        die("Error: update_match_table.sql file not found. Please create it with your SQL statements.");
    }
    
    // Read the SQL file
    $sql = file_get_contents('update_match_table.sql');
    
    // Split the SQL file into individual statements
    $statements = array_filter(array_map('trim', explode(';', $sql)), 'strlen');
    
    // Execute each statement
    foreach ($statements as $statement) {
        try {
            $db->exec($statement);
            echo "Executed: " . substr($statement, 0, 50) . "...\n";
        } catch (PDOException $e) {
            echo "Warning: Failed to execute statement: " . substr($statement, 0, 50) . "...\n";
            echo "Error: " . $e->getMessage() . "\n";
            // Continue with other statements
        }
    }
    
    echo "Database changes applied successfully!\n";
    
    // Verify the changes
    echo "\nVerifying changes...\n";
    
    // Check match_type column
    $stmt = $db->query("SHOW COLUMNS FROM matches WHERE Field = 'match_type'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "match_type column: " . ($column ? "EXISTS - Type: {$column['Type']}" : "MISSING") . "\n";
    
    // Check winning_team column
    $stmt = $db->query("SHOW COLUMNS FROM matches WHERE Field = 'winning_team'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "winning_team column: " . ($column ? "EXISTS - Type: {$column['Type']}" : "MISSING") . "\n";
    
    // Check match_completed column
    $stmt = $db->query("SHOW COLUMNS FROM matches WHERE Field = 'match_completed'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "match_completed column: " . ($column ? "EXISTS - Type: {$column['Type']}" : "MISSING") . "\n";
    
    // Check result_description column
    $stmt = $db->query("SHOW COLUMNS FROM matches WHERE Field = 'result_description'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "result_description column: " . ($column ? "EXISTS - Type: {$column['Type']}" : "MISSING") . "\n";
    
    // Check match_players table
    $stmt = $db->query("SHOW TABLES LIKE 'match_players'");
    $table = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "match_players table: " . ($table ? "EXISTS" : "MISSING") . "\n";
    
    if ($table) {
        // List columns in match_players table
        $stmt = $db->query("SHOW COLUMNS FROM match_players");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "match_players columns:\n";
        foreach ($columns as $column) {
            echo "  - {$column['Field']} ({$column['Type']})\n";
        }
    }
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "General Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "</pre>";
echo "<p><a href='test_match_structure.php'>Run Match Structure Tests</a></p>";
echo "<p><a href='run_tests.php'>Run All Tests</a></p>";