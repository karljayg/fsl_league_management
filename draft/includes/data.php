<?php
/**
 * Draft Data Access Layer
 * Handles reading/writing JSON data files with file locking
 */

define('DRAFT_DATA_DIR', __DIR__ . '/../data/');

/**
 * Read a JSON file with shared lock
 */
function draft_read_json(string $filename): ?array {
    $path = DRAFT_DATA_DIR . $filename;
    if (!file_exists($path)) {
        return null;
    }
    
    $fp = fopen($path, 'r');
    if (!$fp) return null;
    
    flock($fp, LOCK_SH);
    $content = fread($fp, filesize($path) ?: 1);
    flock($fp, LOCK_UN);
    fclose($fp);
    
    return json_decode($content, true);
}

/**
 * Write a JSON file with exclusive lock
 */
function draft_write_json(string $filename, array $data): bool {
    $path = DRAFT_DATA_DIR . $filename;
    
    // Ensure data directory exists
    if (!is_dir(DRAFT_DATA_DIR)) {
        mkdir(DRAFT_DATA_DIR, 0755, true);
    }
    
    $fp = fopen($path, 'c+');
    if (!$fp) return false;
    
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    
    return true;
}

/**
 * Append to a JSON array file (for events/audit)
 */
function draft_append_json(string $filename, array $entry): bool {
    $data = draft_read_json($filename) ?? [];
    $data[] = $entry;
    return draft_write_json($filename, $data);
}

// === Session Functions ===

function get_session(): ?array {
    return draft_read_json('session.json');
}

function save_session(array $session): bool {
    return draft_write_json('session.json', $session);
}

function init_session(string $name, int $seconds_per_pick = 120): array {
    $session = [
        'id' => uniqid('draft-'),
        'name' => $name,
        'status' => 'setup',
        'seconds_per_pick' => $seconds_per_pick,
        'current_pick_number' => 1,
        'current_team_id' => null,
        'pick_deadline_at' => null,
        'draft_order' => [1, 2, 3, 4],
        'admin_token' => bin2hex(random_bytes(16)),
        'created_at' => date('c')
    ];
    save_session($session);
    return $session;
}

// === Teams Functions ===

function get_teams(): array {
    return draft_read_json('teams.json') ?? [];
}

function save_teams(array $teams): bool {
    return draft_write_json('teams.json', $teams);
}

function get_team_by_id(int $id): ?array {
    $teams = get_teams();
    foreach ($teams as $team) {
        if ($team['id'] === $id) return $team;
    }
    return null;
}

function get_team_by_token(string $token): ?array {
    $teams = get_teams();
    foreach ($teams as $team) {
        if ($team['token'] === $token) return $team;
    }
    return null;
}

function generate_team_token(): string {
    return bin2hex(random_bytes(16));
}

// === Players Functions ===

function get_players(): array {
    return draft_read_json('players.json') ?? [];
}

function save_players(array $players): bool {
    return draft_write_json('players.json', $players);
}

function get_player_by_id(int $id): ?array {
    $players = get_players();
    foreach ($players as $player) {
        if ($player['id'] === $id) return $player;
    }
    return null;
}

function get_available_players(): array {
    $players = get_players();
    return array_filter($players, fn($p) => $p['status'] === 'available');
}

function update_player_status(int $id, string $status): bool {
    $players = get_players();
    foreach ($players as &$player) {
        if ($player['id'] === $id) {
            $player['status'] = $status;
            return save_players($players);
        }
    }
    return false;
}

/**
 * Calculate bucket index from ranking (1-based buckets of 4)
 */
function calculate_bucket(int $ranking): int {
    return (int) ceil($ranking / 4);
}

// === Events Functions ===

function get_events(): array {
    return draft_read_json('events.json') ?? [];
}

function save_events(array $events): bool {
    return draft_write_json('events.json', $events);
}

function add_event(array $event): bool {
    $events = get_events();
    $event['id'] = count($events) + 1;
    $event['made_at'] = date('c');
    $events[] = $event;
    return save_events($events);
}

function get_last_event(): ?array {
    $events = get_events();
    return empty($events) ? null : end($events);
}

function remove_last_event(): ?array {
    $events = get_events();
    if (empty($events)) return null;
    $removed = array_pop($events);
    save_events($events);
    return $removed;
}

// === Audit Functions ===

function get_audit_log(): array {
    return draft_read_json('audit.json') ?? [];
}

function add_audit(string $actor, string $action, array $payload = []): bool {
    return draft_append_json('audit.json', [
        'actor' => $actor,
        'action' => $action,
        'payload' => $payload,
        'created_at' => date('c')
    ]);
}

// === Team Roster Functions ===

function get_team_roster(int $team_id): array {
    $events = get_events();
    $players = get_players();
    $roster = [];
    $addedIds = [];
    
    // First, add players with direct team assignment (Captain/Protected)
    foreach ($players as $player) {
        if (isset($player['team_id']) && $player['team_id'] === $team_id) {
            $roster[] = $player;
            $addedIds[] = $player['id'];
        }
    }
    
    // Then add players from pick events
    foreach ($events as $event) {
        if ($event['team_id'] === $team_id && 
            in_array($event['result'], ['PICK', 'ADMIN_ASSIGN']) && 
            isset($event['player_id']) &&
            !in_array($event['player_id'], $addedIds)) {
            foreach ($players as $player) {
                if ($player['id'] === $event['player_id']) {
                    $roster[] = $player;
                    $addedIds[] = $player['id'];
                    break;
                }
            }
        }
    }
    
    // Sort: captains first, then protected, then others by ranking
    usort($roster, function($a, $b) {
        $roleOrder = ['captain' => 0, 'protected' => 1];
        $aOrder = $roleOrder[$a['role'] ?? ''] ?? 2;
        $bOrder = $roleOrder[$b['role'] ?? ''] ?? 2;
        if ($aOrder !== $bOrder) return $aOrder - $bOrder;
        return ($a['ranking'] ?? 999) - ($b['ranking'] ?? 999);
    });
    
    return $roster;
}

function get_team_buckets_used(int $team_id): array {
    $roster = get_team_roster($team_id);
    return array_map(fn($p) => $p['bucket_index'], $roster);
}

/**
 * Get team roster sorted by draft selection order
 * Returns players with 'pick_number' added (null for captain/protected)
 */
function get_team_roster_by_draft_order(int $team_id): array {
    $events = get_events();
    $players = get_players();
    $roster = [];
    $addedIds = [];
    
    // Build player lookup
    $playerLookup = [];
    foreach ($players as $player) {
        $playerLookup[$player['id']] = $player;
    }
    
    // First, add captain/protected (they come before draft picks)
    foreach ($players as $player) {
        if (isset($player['team_id']) && $player['team_id'] === $team_id) {
            $player['pick_number'] = null; // Pre-assigned, no pick number
            $roster[] = $player;
            $addedIds[] = $player['id'];
        }
    }
    
    // Sort captain/protected: captain first, then protected, then by ranking
    usort($roster, function($a, $b) {
        $roleOrder = ['captain' => 0, 'protected' => 1];
        $aOrder = $roleOrder[$a['role'] ?? ''] ?? 2;
        $bOrder = $roleOrder[$b['role'] ?? ''] ?? 2;
        if ($aOrder !== $bOrder) return $aOrder - $bOrder;
        return ($a['ranking'] ?? 999) - ($b['ranking'] ?? 999);
    });
    
    // Then add players from pick events in order they were picked
    foreach ($events as $event) {
        if ($event['team_id'] === $team_id && 
            $event['result'] === 'PICK' && 
            isset($event['player_id']) &&
            !in_array($event['player_id'], $addedIds)) {
            if (isset($playerLookup[$event['player_id']])) {
                $player = $playerLookup[$event['player_id']];
                $player['pick_number'] = $event['pick_number'];
                $roster[] = $player;
                $addedIds[] = $player['id'];
            }
        }
    }
    
    return $roster;
}

/**
 * Get role marker for a player (C) for captain, (P) for protected
 */
function get_role_marker(array $player): string {
    if (($player['role'] ?? '') === 'captain') return ' <span class="role-marker captain">(C)</span>';
    if (($player['role'] ?? '') === 'protected') return ' <span class="role-marker protected">(P)</span>';
    return '';
}

/**
 * Get a version hash based on modification times of all data files
 * Used for smart polling - only refresh when data changes
 */
function get_data_version(): string {
    $files = ['session.json', 'teams.json', 'players.json', 'events.json'];
    $mtimes = [];
    foreach ($files as $file) {
        $path = DRAFT_DATA_DIR . $file;
        $mtimes[] = file_exists($path) ? filemtime($path) : 0;
    }
    return md5(implode('-', $mtimes));
}
