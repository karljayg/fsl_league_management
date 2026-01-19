<?php
/**
 * Public Player Suggestions Endpoint
 * Returns player suggestions for autocomplete without authentication
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include database connection
require_once 'includes/db.php';

// Set content type to JSON
header('Content-Type: application/json');

// Get search query
$query = $_GET['q'] ?? '';

if (strlen($query) < 3) {
    echo json_encode([]);
    exit;
}

try {
    // Search for players with spider chart data
    $stmt = $db->prepare("
        SELECT DISTINCT 
            p.Player_ID as id,
            p.Real_Name as name,
            t.Team_Name as team,
            GROUP_CONCAT(DISTINCT pa.division ORDER BY pa.division) as divisions
        FROM Players p
        LEFT JOIN Teams t ON p.Team_ID = t.Team_ID
        JOIN Player_Attributes pa ON p.Player_ID = pa.player_id
        WHERE p.Status = 'active' 
        AND p.Real_Name LIKE ?
        GROUP BY p.Player_ID, p.Real_Name, t.Team_Name
        ORDER BY p.Real_Name
        LIMIT 10
    ");
    
    $stmt->execute(['%' . $query . '%']);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the response
    $suggestions = [];
    foreach ($players as $player) {
        $suggestions[] = [
            'id' => $player['id'],
            'name' => $player['name'],
            'team' => $player['team'] ?? 'N/A',
            'divisions' => $player['divisions']
        ];
    }
    
    echo json_encode($suggestions);
    
} catch (PDOException $e) {
    // Log error and return empty array
    error_log("Player suggestions error: " . $e->getMessage());
    echo json_encode([]);
}
?> 