<?php
/**
 * Admin AJAX Endpoint
 * Handles admin actions: start, pause, resume, skip, undo, etc.
 */

// Suppress PHP notices/warnings from breaking JSON output
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/data.php';
require_once __DIR__ . '/../includes/draft_logic.php';
require_once __DIR__ . '/../includes/auth.php';

// === Helper Functions ===

function import_players(string $csv): array {
    $lines = array_filter(array_map('trim', explode("\n", $csv)));
    $players = [];
    $teams = get_teams();
    $id = 1;
    
    // Build team name lookup (case-insensitive)
    $teamLookup = [];
    foreach ($teams as $team) {
        $teamLookup[strtolower($team['name'])] = $team['id'];
    }
    
    foreach ($lines as $line) {
        // Skip header
        if (stripos($line, 'rank') !== false && stripos($line, 'name') !== false) {
            continue;
        }
        
        $parts = str_getcsv($line);
        if (count($parts) < 2) continue;
        
        $ranking = intval(trim($parts[0]));
        $name = trim($parts[1]);
        $race = strtoupper(trim($parts[2] ?? 'R'));
        $notes = trim($parts[3] ?? '');
        
        if (!$ranking || !$name) continue;
        
        // Validate race
        if (!in_array($race, ['T', 'P', 'Z', 'R'])) {
            $race = 'R';
        }
        
        // Check for Captain or Protected assignment in notes
        $role = null;
        $team_id = null;
        $status = 'available';
        
        if (preg_match('/^Captain\s*-\s*(.+)$/i', $notes, $matches)) {
            $teamName = strtolower(trim($matches[1]));
            if (isset($teamLookup[$teamName])) {
                $role = 'captain';
                $team_id = $teamLookup[$teamName];
                $status = 'drafted';
            }
        } elseif (preg_match('/^Protected\s*-\s*(.+)$/i', $notes, $matches)) {
            $teamName = strtolower(trim($matches[1]));
            if (isset($teamLookup[$teamName])) {
                $role = 'protected';
                $team_id = $teamLookup[$teamName];
                $status = 'drafted';
            }
        }
        
        $players[] = [
            'id' => $id++,
            'display_name' => $name,
            'ranking' => $ranking,
            'bucket_index' => calculate_bucket($ranking),
            'race' => $race,
            'notes' => $notes,
            'status' => $status,
            'team_id' => $team_id,
            'role' => $role
        ];
    }
    
    if (empty($players)) {
        return ['success' => false, 'error' => 'No valid players found'];
    }
    
    // Sort by ranking
    usort($players, fn($a, $b) => $a['ranking'] - $b['ranking']);
    
    // Re-assign IDs after sort
    foreach ($players as $i => &$p) {
        $p['id'] = $i + 1;
    }
    
    save_players($players);
    
    // Create events for pre-assigned players (Captain/Protected)
    $events = [];
    foreach ($players as $player) {
        if ($player['team_id'] && $player['role']) {
            $events[] = [
                'id' => count($events) + 1,
                'pick_number' => null,
                'team_id' => $player['team_id'],
                'player_id' => $player['id'],
                'result' => 'ADMIN_ASSIGN',
                'skip_reason' => null,
                'made_by' => 'ADMIN',
                'made_at' => date('c'),
                'note' => ucfirst($player['role']) . ' assignment'
            ];
        }
    }
    if (!empty($events)) {
        save_events($events);
    }
    
    add_audit('ADMIN', 'IMPORT_PLAYERS', ['count' => count($players)]);
    
    // Count captains and protected
    $captains = count(array_filter($players, fn($p) => $p['role'] === 'captain'));
    $protected = count(array_filter($players, fn($p) => $p['role'] === 'protected'));
    
    return ['success' => true, 'count' => count($players), 'captains' => $captains, 'protected' => $protected];
}

function update_team_names(array $names, array $logos = []): array {
    $teams = get_teams();
    
    foreach ($teams as &$team) {
        if (isset($names[$team['id']])) {
            $team['name'] = trim($names[$team['id']]);
        }
        if (isset($logos[$team['id']])) {
            $team['logo'] = trim($logos[$team['id']]);
        }
    }
    
    save_teams($teams);
    add_audit('ADMIN', 'UPDATE_TEAMS', $names);
    
    return ['success' => true];
}

// === Main Logic ===

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['token'] ?? '';
    $action = $input['action'] ?? '';

    // Admin access - no token required
    $response = ['success' => false];

    switch ($action) {
        case 'start':
            $response['success'] = start_draft();
            break;
            
        case 'pause':
            $response['success'] = pause_draft();
            break;
            
        case 'resume':
            $response['success'] = resume_draft();
            break;
            
        case 'restart_timer':
            $response['success'] = restart_timer();
            break;
            
        case 'skip':
            $response['success'] = skip_team('ADMIN_SKIP', 'ADMIN');
            break;
            
        case 'undo':
            $result = undo_last();
            $response = $result;
            break;
            
        case 'end':
            $response['success'] = end_draft();
            break;
            
        case 'import_players':
            $data = $input['data'] ?? '';
            $response = import_players($data);
            break;
            
        case 'update_teams':
            $teamNames = $input['teams'] ?? [];
            $teamLogos = $input['logos'] ?? [];
            $response = update_team_names($teamNames, $teamLogos);
            break;
            
        case 'update_draft_name':
            $draftName = trim($input['draft_name'] ?? '');
            if (empty($draftName)) {
                $response['error'] = 'Draft name cannot be empty';
            } else {
                $session = get_session();
                if ($session) {
                    $session['name'] = $draftName;
                    save_session($session);
                    add_audit('ADMIN', 'UPDATE_DRAFT_NAME', ['name' => $draftName]);
                    $response = ['success' => true];
                } else {
                    $response['error'] = 'No active session';
                }
            }
            break;
            
        case 'reset':
            // Delete all data files to reset draft
            $dataDir = __DIR__ . '/../data/';
            array_map('unlink', glob($dataDir . '*.json'));
            $response = ['success' => true, 'message' => 'Draft reset'];
            break;
            
        default:
            $response['error'] = 'Unknown action';
    }

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
