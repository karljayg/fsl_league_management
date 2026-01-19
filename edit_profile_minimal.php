<?php
// Set maximum execution time and memory limit
set_time_limit(30);
ini_set('memory_limit', '64M');

// Basic error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start with just the basic page rendering, no database or session operations
echo "<!DOCTYPE html>
<html>
<head>
    <title>Edit Profile - Test Page</title>
</head>
<body>
    <h1>Edit Profile - Test Page</h1>
    <p>This is a minimal test page to verify the server is responding correctly.</p>
    <p>PHP Version: " . phpversion() . "</p>
    <p>Time: " . date('Y-m-d H:i:s') . "</p>
    
    <h2>Server Information:</h2>
    <ul>
        <li>Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "</li>
        <li>Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "</li>
        <li>Script Filename: " . $_SERVER['SCRIPT_FILENAME'] . "</li>
    </ul>
    
    <h2>Test Database Connection:</h2>";

// Test database connection without session or complex logic
try {
    require_once 'config.php';
    echo "<p>Config file loaded successfully.</p>";
    
    echo "<p>Database settings from config:</p>
    <ul>
        <li>Host: " . htmlspecialchars($config['db_host']) . "</li>
        <li>Database: " . htmlspecialchars($config['db_name']) . "</li>
        <li>User: " . htmlspecialchars($config['db_user']) . "</li>
    </ul>";
    
    // Connect to database
    $db = new PDO("mysql:host={$config['db_host']};dbname={$config['db_name']}", 
                 $config['db_user'], $config['db_pass']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p style='color: green;'>Database connection successful!</p>";
    
    // Test a simple query
    $stmt = $db->query("SELECT COUNT(*) FROM users");
    $count = $stmt->fetchColumn();
    echo "<p>Number of users in database: " . $count . "</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>Conclusion</h2>
<p>If you can see this message, the script executed completely without timing out.</p>
</body>
</html>";
?> 