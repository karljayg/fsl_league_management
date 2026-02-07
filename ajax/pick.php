<?php
/**
 * Pick AJAX Endpoint
 * Handles team picks
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/data.php';
require_once __DIR__ . '/../includes/draft_logic.php';
require_once __DIR__ . '/../includes/auth.php';

$input = json_decode(file_get_contents('php://input'), true);
$token = $input['token'] ?? '';
$playerId = intval($input['player_id'] ?? 0);

// Validate team token
$team = get_team_by_token($token);
if (!$team) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid team token']);
    exit;
}

// Get session
$session = get_session();
if (!$session) {
    echo json_encode(['error' => 'No draft session']);
    exit;
}

// Check draft is live
if ($session['status'] !== 'live') {
    echo json_encode(['error' => 'Draft is not live']);
    exit;
}

// Check it's this team's turn
if ($session['current_team_id'] !== $team['id']) {
    echo json_encode(['error' => 'Not your turn']);
    exit;
}

// Check timer hasn't expired
if ($session['pick_deadline_at']) {
    $deadline = strtotime($session['pick_deadline_at']);
    if (time() >= $deadline) {
        check_timer(); // Process timeout
        echo json_encode(['error' => 'Time expired']);
        exit;
    }
}

// Make the pick
$result = make_pick($team['id'], $playerId, 'TEAM');

echo json_encode($result);
