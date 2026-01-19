<?php
/**
 * AJAX endpoint for reviewer name suggestions
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include database connection first (needed for permission check)
require_once 'includes/db.php';
require_once 'includes/reviewer_functions.php';

// Set required permission
$required_permission = 'manage spider charts';

// Include permission check
require_once 'includes/check_permission.php';

// Set content type to JSON
header('Content-Type: application/json');

// Reviewer functions now handled by database

// Get the search query
$query = $_POST['query'] ?? '';

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

// Get all reviewers
$reviewers = getReviewers('active');

// Filter reviewers by query
$suggestions = [];
foreach ($reviewers as $reviewer) {
    if ($reviewer['status'] === 'active' && 
        stripos($reviewer['name'], $query) !== false) {
        $suggestions[] = $reviewer['name'];
    }
}

// Limit to 10 suggestions
$suggestions = array_slice($suggestions, 0, 10);

// Return JSON response
echo json_encode($suggestions);
?> 