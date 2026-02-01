<?php
/**
 * FSL Service API
 * External integration endpoint for creating reviewers and other operations
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include required files
require_once '../includes/db.php';
require_once '../config.php';

// Set content type to JSON
header('Content-Type: application/json');

// Include API components
require_once 'includes/auth.php';
require_once 'includes/validator.php';
require_once 'includes/logger.php';

// Initialize API components
$auth = new APIAuth($config['service_api']);
$validator = new APIValidator();
$logger = new APILogger($db);

// Check if API is enabled
if (!$config['service_api']['enabled']) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error' => 'service_disabled',
        'message' => 'Service API is currently disabled',
        'code' => 503
    ]);
    exit;
}

// Log the request
$logger->logRequest($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['REMOTE_ADDR']);

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'method_not_allowed',
        'message' => 'Only POST requests are allowed',
        'code' => 405
    ]);
    exit;
}

// Authenticate the request
$authResult = $auth->authenticate();
if (!$authResult['success']) {
    http_response_code($authResult['code']);
    echo json_encode($authResult);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'invalid_json',
        'message' => 'Invalid JSON format',
        'code' => 400
    ]);
    exit;
}

// Validate required fields
if (!isset($input['action'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'missing_action',
        'message' => 'Action is required',
        'code' => 400
    ]);
    exit;
}

// Route to appropriate action
$action = $input['action'];
$data = $input['data'] ?? [];

try {
    switch ($action) {
        case 'create_reviewer':
            require_once 'actions/create_reviewer.php';
            $handler = new CreateReviewerAction($db, $config);
            $result = $handler->execute($data);
            break;
            
        case 'get_reviewer_status':
            require_once 'actions/get_reviewer_status.php';
            $handler = new GetReviewerStatusAction($db);
            $result = $handler->execute($data);
            break;
            
        case 'update_reviewer_weight':
            require_once 'actions/update_reviewer_weight.php';
            $handler = new UpdateReviewerWeightAction($db);
            $result = $handler->execute($data);
            break;
            
        case 'deactivate_reviewer':
            require_once 'actions/deactivate_reviewer.php';
            $handler = new DeactivateReviewerAction($db);
            $result = $handler->execute($data);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'invalid_action',
                'message' => 'Invalid action: ' . $action,
                'code' => 400
            ]);
            exit;
    }
    
    // Return the result
    echo json_encode($result);
    
} catch (Exception $e) {
    // Log the error
    error_log("API Error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'internal_error',
        'message' => 'Internal server error',
        'code' => 500
    ]);
}
?> 