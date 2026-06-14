<?php
/**
 * State AJAX Endpoint
 * Returns current draft state for polling
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/data.php';
require_once __DIR__ . '/../includes/draft_logic.php';

// Check timer first
check_timer();

// If only checking version (lightweight poll)
if (isset($_GET['check_only'])) {
    echo json_encode(['version' => get_data_version()]);
    exit;
}

$session = get_session();
if (!$session) {
    echo json_encode(['error' => 'No draft session']);
    exit;
}

$teams = get_teams();
$players = get_players();
$events = get_events();

// Build team rosters
$rosters = [];
foreach ($teams as $team) {
    $rosters[$team['id']] = get_team_roster($team['id']);
}

// Current team name
$onClockTeamId = get_on_clock_team_id();
$currentTeamName = get_on_clock_team_name();

echo json_encode([
    'version' => get_data_version(),
    'status' => $session['status'],
    'current_pick_number' => $session['current_pick_number'],
    'active_pick_number' => get_active_pick_number(),
    'current_team_id' => $onClockTeamId,
    'current_team_name' => $currentTeamName,
    'pick_deadline_at' => $session['pick_deadline_at'],
    'teams' => $teams,
    'players' => $players,
    'events' => $events,
    'rosters' => $rosters,
    'available_count' => count(array_filter($players, fn($p) => $p['status'] === 'available'))
]);
