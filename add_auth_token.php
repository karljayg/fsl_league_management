<?php
try {
    $conn = new PDO(
        "mysql:host=localhost;dbname=psistorm;charset=utf8mb4",
        "root",
        "password",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Check if column exists
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'auth_token'");
    if ($stmt->rowCount() == 0) {
        $sql = "ALTER TABLE users ADD COLUMN auth_token VARCHAR(64) UNIQUE";
        $conn->exec($sql);
        echo "Successfully added auth_token column to users table\n";
    } else {
        echo "auth_token column already exists\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?> 