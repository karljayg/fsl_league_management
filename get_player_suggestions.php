<?php
/**
 * AJAX endpoint for player name suggestions
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include database connection first
require_once 'includes/db.php';

// Set required permission
$required_permission = 'manage spider charts';

// Include permission check
require_once 'includes/check_permission.php';

// Set content type to JSON
header('Content-Type: application/json');

// Get the search query
$query = $_POST['query'] ?? '';

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

try {
    // Get player suggestions from database
    $stmt = $db->prepare("
        SELECT DISTINCT Real_Name 
        FROM Players 
        WHERE Status = 'active' 
        AND Real_Name LIKE ? 
        ORDER BY Real_Name 
        LIMIT 10
    ");
    
    $stmt->execute(['%' . $query . '%']);
    $players = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Return JSON response
    echo json_encode($players);
    
} catch (Exception $e) {
    // Return empty array on error
    echo json_encode([]);
}
?> 