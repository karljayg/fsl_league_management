<?php
// Set the content type to JSON
header('Content-Type: application/json');

// Prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Define the file path
$file = 'chat_data.json';

try {
    // Read incoming data
    $input = file_get_contents('php://input');
    if (empty($input)) {
        throw new Exception("No input data received");
    }
    
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON: " . json_last_error_msg());
    }
    
    // Check if data is valid
    if (!$data || !isset($data['user']) || !isset($data['message'])) {
        throw new Exception("Missing required fields: user and message");
    }
    
    // Sanitize inputs
    $user = htmlspecialchars(trim($data['user']), ENT_QUOTES, 'UTF-8');
    $message = htmlspecialchars(trim($data['message']), ENT_QUOTES, 'UTF-8');
    
    if (empty($user) || empty($message)) {
        throw new Exception("User or message cannot be empty");
    }
    
    // Read existing messages
    $messages = [];
    if (file_exists($file) && filesize($file) > 0) {
        $fileContent = file_get_contents($file);
        if ($fileContent === false) {
            throw new Exception("Failed to read chat file");
        }
        
        $messages = json_decode($fileContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // If JSON is invalid, start with an empty array
            $messages = [];
        }
    }
    
    // Ensure $messages is an array
    if (!is_array($messages)) {
        $messages = [];
    }
    
    // Add new message
    $messages[] = [
        'id' => uniqid(),
        'user' => $user,
        'message' => $message,
        'timestamp' => time()
    ];
    
    // Limit the number of messages (keep the last 100)
    if (count($messages) > 100) {
        $messages = array_slice($messages, -100);
    }
    
    // Save back to JSON file
    $jsonData = json_encode($messages, JSON_PRETTY_PRINT);
    if ($jsonData === false) {
        throw new Exception("Failed to encode messages to JSON");
    }
    
    $writeResult = file_put_contents($file, $jsonData);
    if ($writeResult === false) {
        throw new Exception("Failed to write to chat file");
    }

    // Return success response
    echo json_encode([
        "status" => "success",
        "message" => "Message saved successfully"
    ]);
    
} catch (Exception $e) {
    // Return error response
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
?> 