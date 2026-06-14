<?php
/**
 * Draft Logic Functions
 * Snake draft, bucket rules, pick validation
 */

require_once __DIR__ . '/data.php';

/**
 * Count completed draft picks for a team (PICK + ADMIN_ASSIGN).
 */
function count_team_draft_picks(int $team_id): int {
    $count = 0;
    foreach (get_events() as $event) {
        if ((int) ($event['team_id'] ?? 0) === $team_id
            && in_array($event['result'] ?? '', ['PICK', 'ADMIN_ASSIGN'], true)) {
            $count++;
        }
    }
    return $count;
}

/**
 * Per-team pick cap from session (null = unlimited).
 */
function get_team_pick_limit(int $team_id): ?int {
    $session = get_session();
    $limits = $session['team_pick_limits'] ?? null;
    if (!is_array($limits)) {
        return null;
    }
    if (isset($limits[$team_id])) {
        return (int) $limits[$team_id];
    }
    if (isset($limits[(string) $team_id])) {
        return (int) $limits[(string) $team_id];
    }
    return null;
}

/**
 * Whether a team may still make a draft pick under pick limits.
 */
function team_can_make_draft_pick(int $team_id): bool {
    $limit = get_team_pick_limit($team_id);
    if ($limit === null) {
        return true;
    }
    return count_team_draft_picks($team_id) < $limit;
}

/**
 * How many S11 draft picks this team is scheduled to make.
 */
function get_team_scheduled_pick_count(int $team_id): ?int {
    $limit = get_team_pick_limit($team_id);
    if ($limit !== null) {
        return $limit;
    }

    $session = get_session();
    $schedule = $session['pick_schedule'] ?? null;
    if (!is_array($schedule) || $schedule === []) {
        return null;
    }

    $count = 0;
    foreach ($schedule as $scheduledTeamId) {
        if ((int) $scheduledTeamId === $team_id) {
            $count++;
        }
    }

    return $count > 0 ? $count : null;
}

/**
 * Total picks in this draft session.
 */
function get_total_scheduled_picks(): int {
    $session = get_session();
    if (!empty($session['total_picks'])) {
        return (int) $session['total_picks'];
    }

    $schedule = $session['pick_schedule'] ?? null;
    if (is_array($schedule) && $schedule !== []) {
        return count($schedule);
    }

    return count(get_teams()) * 3;
}

/**
 * Label for draft picks made vs allotted (e.g. "0/5").
 */
function format_team_draft_pick_progress(int $team_id): string {
    $total = get_team_scheduled_pick_count($team_id);
    $made = count_team_draft_picks($team_id);
    if ($total === null) {
        return $made . ' picks';
    }

    return $made . '/' . $total;
}

/**
 * Pick number currently on the clock (live or paused).
 */
function get_active_pick_number(): ?int {
    $session = get_session();
    if (!in_array($session['status'] ?? '', ['live', 'paused'], true)) {
        return null;
    }
    $pick = (int) ($session['current_pick_number'] ?? 0);
    return $pick > 0 ? $pick : null;
}

/**
 * Team ID on the clock — falls back to pick_schedule when current_team_id is unset.
 */
function get_on_clock_team_id(): ?int {
    $session = get_session();
    if (!in_array($session['status'] ?? '', ['live', 'paused'], true)) {
        return null;
    }

    if (!empty($session['current_team_id'])) {
        return (int) $session['current_team_id'];
    }

    $pick = get_active_pick_number();
    if ($pick === null) {
        return null;
    }

    return get_team_for_pick($pick, $session['draft_order'] ?? []);
}

function get_on_clock_team_name(): string {
    $teamId = get_on_clock_team_id();
    if (!$teamId) {
        return '';
    }
    $team = get_team_by_id($teamId);
    return $team ? $team['name'] : '';
}

/**
 * Calculate which team picks at a given pick number using snake draft
 * @param int $pick_number 1-based pick number
 * @param array $draft_order Array of team IDs in Round 1 order [1,2,3,4]
 * @return int Team ID
 */
function get_team_for_pick(int $pick_number, array $draft_order): int {
    $session = get_session();
    $schedule = $session['pick_schedule'] ?? null;
    if (is_array($schedule) && isset($schedule[$pick_number - 1])) {
        return (int) $schedule[$pick_number - 1];
    }

    $n = count($draft_order);
    $round = (int) floor(($pick_number - 1) / $n) + 1;
    $index = ($pick_number - 1) % $n;
    
    if ($round % 2 === 1) {
        // Odd round: normal order
        return $draft_order[$index];
    } else {
        // Even round: reverse order
        return $draft_order[$n - 1 - $index];
    }
}

/**
 * Check if a team can draft a player (bucket rule)
 */
function can_team_draft_player(int $team_id, array $player): bool {
    $buckets_used = get_team_buckets_used($team_id);
    return !in_array($player['bucket_index'], $buckets_used);
}

/**
 * Get players eligible for a team to pick
 */
function get_eligible_players_for_team(int $team_id): array {
    $available = array_values(get_available_players());
    if ($available === []) {
        return [];
    }

    $strict = array_values(array_filter(
        $available,
        fn($p) => can_team_draft_player($team_id, $p)
    ));
    if ($strict !== []) {
        return $strict;
    }

    // Team still has pick slots but every remaining player shares a draft bucket — allow any pick.
    if (team_can_make_draft_pick($team_id)) {
        return $available;
    }

    return [];
}

/**
 * Check if current team has any eligible picks
 */
function team_has_eligible_picks(int $team_id): bool {
    return count(get_eligible_players_for_team($team_id)) > 0;
}

/**
 * Execute a pick
 */
function make_pick(int $team_id, int $player_id, string $made_by = 'TEAM'): array {
    $session = get_session();
    $player = get_player_by_id($player_id);
    
    if (!$player || $player['status'] !== 'available') {
        return ['success' => false, 'error' => 'Player not available'];
    }
    
    if ($made_by === 'TEAM') {
        $eligibleIds = array_map(fn($p) => $p['id'], get_eligible_players_for_team($team_id));
        if (!in_array($player_id, $eligibleIds, true)) {
            return ['success' => false, 'error' => 'Player not eligible for this team'];
        }
    }

    if (!team_can_make_draft_pick($team_id)) {
        return ['success' => false, 'error' => 'Team has reached its pick limit'];
    }
    
    // Record the pick
    add_event([
        'pick_number' => $session['current_pick_number'],
        'team_id' => $team_id,
        'player_id' => $player_id,
        'result' => ($made_by === 'ADMIN') ? 'ADMIN_ASSIGN' : 'PICK',
        'made_by' => $made_by
    ]);
    
    // Update player status and team
    draft_player_to_team($player_id, $team_id);
    
    // Advance draft (only for regular picks, not admin assigns during pause)
    if ($made_by !== 'ADMIN' || $session['status'] === 'live') {
        advance_draft();
    }
    
    add_audit($made_by, 'PICK', ['team_id' => $team_id, 'player_id' => $player_id]);
    
    return ['success' => true];
}

/**
 * Skip current team
 */
function skip_team(string $reason, string $made_by = 'SYSTEM'): bool {
    $session = get_session();
    
    add_event([
        'pick_number' => $session['current_pick_number'],
        'team_id' => $session['current_team_id'],
        'player_id' => null,
        'result' => 'SKIP',
        'skip_reason' => $reason,
        'made_by' => $made_by
    ]);
    
    add_audit($made_by, 'SKIP', ['team_id' => $session['current_team_id'], 'reason' => $reason]);
    
    advance_draft();
    
    return true;
}

/**
 * Advance to next pick
 */
function complete_draft_session(): void {
    $session = get_session();
    $session['status'] = 'completed';
    $session['current_team_id'] = null;
    $session['pick_deadline_at'] = null;
    save_session($session);
    add_audit('SYSTEM', 'DRAFT_COMPLETED', []);
}

function advance_draft(): void {
    $session = get_session();
    $available = get_available_players();
    $totalPicks = (int) ($session['total_picks'] ?? 0);
    $nextPick = $session['current_pick_number'] + 1;

    if (count($available) === 0) {
        complete_draft_session();
        return;
    }

    if ($totalPicks > 0 && $nextPick > $totalPicks) {
        $session['status'] = 'paused';
        $session['pick_deadline_at'] = null;
        save_session($session);
        add_audit('SYSTEM', 'DRAFT_PAUSED_INCOMPLETE', ['remaining' => count($available)]);
        return;
    }

    $session['current_pick_number'] = $nextPick;
    $session['current_team_id'] = get_team_for_pick(
        $session['current_pick_number'],
        $session['draft_order']
    );
    $session['pick_deadline_at'] = date('c', time() + $session['seconds_per_pick']);
    save_session($session);

    if (!team_can_make_draft_pick($session['current_team_id'])) {
        skip_team('PICK_LIMIT_REACHED', 'SYSTEM');
    }
}

/**
 * Apply Captain/Protected assignments from player notes
 * Matches "Captain - TeamName" or "Protected - TeamName" in notes
 */
function apply_captain_protected_assignments(): int {
    $teams = get_teams();
    $teamLookup = [];
    foreach ($teams as $team) {
        $teamLookup[strtolower($team['name'])] = $team['id'];
    }
    
    $players = get_players();
    $updated = 0;
    
    foreach ($players as &$player) {
        // Skip if already assigned
        if (!empty($player['team_id'])) continue;
        
        $notes = $player['notes'] ?? '';
        
        if (preg_match('/^Captain\s*-\s*(.+)$/i', $notes, $matches)) {
            $teamName = strtolower(trim($matches[1]));
            if (isset($teamLookup[$teamName])) {
                $player['role'] = 'captain';
                $player['team_id'] = $teamLookup[$teamName];
                $player['status'] = 'drafted';
                $updated++;
            }
        } elseif (preg_match('/^Protected\s*-\s*(.+)$/i', $notes, $matches)) {
            $teamName = strtolower(trim($matches[1]));
            if (isset($teamLookup[$teamName])) {
                $player['role'] = 'protected';
                $player['team_id'] = $teamLookup[$teamName];
                $player['status'] = 'drafted';
                $updated++;
            }
        }
    }
    
    if ($updated > 0) {
        save_players($players);
        add_audit('SYSTEM', 'APPLY_ASSIGNMENTS', ['count' => $updated]);
    }
    
    return $updated;
}

/**
 * Start the draft
 */
function start_draft(): bool {
    $session = get_session();
    if (!$session || ($session['status'] ?? '') !== 'setup') {
        return false;
    }
    
    // Apply Captain/Protected assignments before starting
    apply_captain_protected_assignments();
    
    $session['status'] = 'live';
    $session['current_pick_number'] = 1;
    $session['current_team_id'] = get_team_for_pick(1, $session['draft_order']);
    $session['seconds_per_pick'] = (int) ($session['seconds_per_pick'] ?? 120);
    $session['pick_deadline_at'] = date('c', time() + $session['seconds_per_pick']);
    
    if (!save_session($session)) {
        return false;
    }
    add_audit('ADMIN', 'DRAFT_STARTED', []);
    
    // Check for immediate skip needed
    if (!team_has_eligible_picks($session['current_team_id'])) {
        skip_team('NO_ELIGIBLE_PLAYERS', 'SYSTEM');
    }
    
    return true;
}

/**
 * Pause the draft
 */
function pause_draft(): bool {
    $session = get_session();
    if ($session['status'] !== 'live') return false;
    
    $session['status'] = 'paused';
    $session['pick_deadline_at'] = null;
    save_session($session);
    add_audit('ADMIN', 'DRAFT_PAUSED', []);
    
    return true;
}

/**
 * Resume the draft
 */
function resume_draft(): bool {
    $session = get_session();
    if ($session['status'] !== 'paused') return false;
    
    $session['status'] = 'live';
    $session['pick_deadline_at'] = date('c', time() + $session['seconds_per_pick']);
    save_session($session);
    add_audit('ADMIN', 'DRAFT_RESUMED', []);
    
    return true;
}

/**
 * End the draft manually
 */
function end_draft(): bool {
    $session = get_session();
    $session['status'] = 'completed';
    $session['current_team_id'] = null;
    $session['pick_deadline_at'] = null;
    save_session($session);
    add_audit('ADMIN', 'DRAFT_ENDED', []);
    
    return true;
}

/**
 * Undo last event
 */
function undo_last(): array {
    $event = get_last_event();
    if (!$event) {
        return ['success' => false, 'error' => 'No events to undo'];
    }
    
    // Restore player if was a pick
    if (in_array($event['result'], ['PICK', 'ADMIN_ASSIGN']) && $event['player_id']) {
        undraft_player($event['player_id']);
    }
    
    // Remove the event
    remove_last_event();
    
    // Restore session state
    $session = get_session();
    $session['current_pick_number'] = $event['pick_number'];
    $session['current_team_id'] = $event['team_id'];
    if ($session['status'] === 'live') {
        $session['pick_deadline_at'] = date('c', time() + $session['seconds_per_pick']);
    }
    save_session($session);
    
    add_audit('ADMIN', 'UNDO', ['event_id' => $event['id']]);
    
    return ['success' => true, 'undone' => $event];
}

/**
 * Check and handle timer expiry
 */
function check_timer(): void {
    $session = get_session();
    
    if ($session['status'] !== 'live' || !$session['pick_deadline_at']) {
        return;
    }
    
    $deadline = strtotime($session['pick_deadline_at']);
    if (time() >= $deadline) {
        skip_team('TIMEOUT', 'SYSTEM');
    }
}

/**
 * Restart timer for current pick
 */
function restart_timer(): bool {
    $session = get_session();
    if ($session['status'] !== 'live') return false;
    
    $session['pick_deadline_at'] = date('c', time() + $session['seconds_per_pick']);
    save_session($session);
    add_audit('ADMIN', 'TIMER_RESTART', []);
    
    return true;
}
