<?php
// Start session
session_start();

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'You must be logged in to delete messages'
    ]);
    exit;
}

// Include database connection
require_once 'includes/db.php';

try {
    // Connect to database
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if user has 'chat admin' permission
    $stmt = $db->prepare("
        SELECT COUNT(*) AS cnt
        FROM ws_user_roles ur
        JOIN ws_role_permissions rp ON ur.role_id = rp.role_id
        JOIN ws_permissions p ON rp.permission_id = p.permission_id
        WHERE ur.user_id = ? AND p.permission_name = 'chat admin'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['cnt'] == 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'You do not have permission to delete messages'
        ]);
        exit;
    }
    
    // Get the message ID from the request
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || !isset($data['messageId'])) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid request: missing message ID'
        ]);
        exit;
    }
    
    $messageId = $data['messageId'];
    
    // Read the chat data file
    $chatFile = 'chat_data.json';
    if (!file_exists($chatFile)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Chat data file not found'
        ]);
        exit;
    }
    
    $chatData = json_decode(file_get_contents($chatFile), true);
    if (!is_array($chatData)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid chat data format'
        ]);
        exit;
    }
    
    // Find and remove the message
    $messageFound = false;
    $newChatData = [];
    
    foreach ($chatData as $message) {
        // Skip the message to be deleted
        if (isset($message['id']) && $message['id'] == $messageId) {
            $messageFound = true;
            continue;
        }
        
        // Also check by timestamp if id is not available
        if (isset($message['timestamp']) && (string)$message['timestamp'] === (string)$messageId) {
            $messageFound = true;
            continue;
        }
        
        // Keep all other messages
        $newChatData[] = $message;
    }
    
    if (!$messageFound) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Message not found'
        ]);
        exit;
    }
    
    // Write the updated data back to the file
    if (file_put_contents($chatFile, json_encode($newChatData, JSON_PRETTY_PRINT)) === false) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to update chat data file'
        ]);
        exit;
    }
    
    // Return success response
    echo json_encode([
        'status' => 'success',
        'message' => 'Message deleted successfully'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?> 