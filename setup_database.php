<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    echo "Connecting to MySQL...\n";
    $pdo = new PDO(
        "mysql:host=localhost;dbname=psistorm;charset=utf8mb4",
        "root",
        "password",
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    echo "Connected successfully!\n\n";

    // Drop existing tables if they exist
    echo "Dropping existing tables...\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $tables = ['bids', 'matches', 'users', 'payments', 'payouts'];
    foreach ($tables as $table) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
            echo "Dropped table $table if it existed\n";
        } catch (PDOException $e) {
            echo "Error dropping table $table: " . $e->getMessage() . "\n";
        }
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "Tables dropped!\n\n";

    // Create tables
    echo "Creating tables...\n";
    $schema = file_get_contents('schema.sql');
    if ($schema === false) {
        throw new Exception("Could not read schema.sql");
    }
    echo "Schema content:\n" . $schema . "\n\n";
    
    // Execute each statement separately
    $statements = array_filter(explode(';', $schema));
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            try {
                $pdo->exec($statement);
                echo "Executed: " . substr($statement, 0, 50) . "...\n";
            } catch (PDOException $e) {
                echo "Error executing statement: " . $statement . "\n";
                echo "Error: " . $e->getMessage() . "\n";
            }
        }
    }
    echo "Tables created!\n\n";

    // Import test data
    echo "Importing test data...\n";
    $data = file_get_contents('test_data.sql');
    if ($data === false) {
        throw new Exception("Could not read test_data.sql");
    }
    echo "Test data content:\n" . $data . "\n\n";
    
    // Execute each statement separately
    $statements = array_filter(explode(';', $data));
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            try {
                $pdo->exec($statement);
                echo "Executed: " . substr($statement, 0, 50) . "...\n";
            } catch (PDOException $e) {
                echo "Error executing statement: " . $statement . "\n";
                echo "Error: " . $e->getMessage() . "\n";
            }
        }
    }
    echo "Test data imported!\n\n";

    // Verify data
    echo "Verifying data...\n";
    foreach ($tables as $table) {
        if ($table === 'payments' || $table === 'payouts') continue;
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            echo "Table '$table' has $count records\n";
            
            if ($count > 0) {
                $rows = $pdo->query("SELECT * FROM `$table` LIMIT 1")->fetch();
                echo "Sample record:\n";
                print_r($rows);
                echo "\n";
            }
        } catch (PDOException $e) {
            echo "Error checking table $table: " . $e->getMessage() . "\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Error Code: " . ($e instanceof PDOException ? $e->getCode() : 'N/A') . "\n";
    echo "Stack Trace:\n" . $e->getTraceAsString() . "\n";
} 