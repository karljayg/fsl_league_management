<?php
/**
 * Client-side Error Logger
 * Logs JavaScript errors from the client side
 */

// Set content type to JSON
header('Content-Type: application/json');

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Log the error
$timestamp = date('[Y-m-d H:i:s]');
$errorMessage = isset($_POST['error_message']) ? $_POST['error_message'] : 'Unknown error';
$function = isset($_POST['function']) ? $_POST['function'] : 'Unknown function';
$playerId = isset($_POST['player_id']) ? $_POST['player_id'] : 'Unknown player';
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown user agent';
$ipAddress = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'Unknown IP';

$logMessage = "$timestamp Client Error: $errorMessage in function $function for player $playerId | $userAgent | $ipAddress\n";
error_log($logMessage, 3, 'client_errors.log');

// Return success response
echo json_encode([
    'status' => 'success',
    'message' => 'Error logged successfully'
]); 