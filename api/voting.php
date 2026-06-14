<?php
/**
 * FSL Voting API
 * Handles Twitch chat bot voting sessions and vote submission.
 *
 * Endpoints (routed by action= param):
 *   GET  ?action=match&fsl_match_id=X  — read match + players
 *   POST ?action=enable                — open a 5-min voting session
 *   GET  ?action=active                — check if a session is open
 *   POST ?action=votes                 — submit 6 attribute votes + tallies
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

// ─── Auth ────────────────────────────────────────────────────────────────────

function auth_check(array $config): void {
    $expected = $config['service_api']['token'] ?? '';

    // Support: Authorization: Bearer <token>  OR  X-Api-Key: <token>
    $headers  = function_exists('getallheaders') ? getallheaders() : [];
    $bearer   = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $x_key    = $headers['X-Api-Key']     ?? $headers['x-api-key']     ?? '';

    $provided = '';
    if (str_starts_with($bearer, 'Bearer ')) {
        $provided = substr($bearer, 7);
    } elseif ($x_key !== '') {
        $provided = $x_key;
    }

    if ($provided === '' || $provided !== $expected) {
        respond(401, false, null, 'UNAUTHORIZED', 'Valid API key required (Authorization: Bearer <key>)');
    }
}

// ─── Response helpers ────────────────────────────────────────────────────────

function respond(int $http, bool $ok, mixed $data, string $error = '', string $message = ''): never {
    http_response_code($http);
    if ($ok) {
        echo json_encode(['ok' => true, 'data' => $data]);
    } else {
        echo json_encode(['ok' => false, 'error' => $error, 'message' => $message]);
    }
    exit;
}

function json_body(): array {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

// ─── Session helpers ─────────────────────────────────────────────────────────

/**
 * Returns the active session row, or null.
 * A session is active when status='open' AND expires_at > NOW().
 */
function get_active_session(PDO $db): ?array {
    $stmt = $db->prepare("
        SELECT vs.*, fm.season, fm.t_code, fm.notes,
               fm.winner_player_id, fm.loser_player_id,
               p1.Real_Name AS player1_name,
               p2.Real_Name AS player2_name
        FROM voting_sessions vs
        JOIN fsl_matches fm ON vs.fsl_match_id = fm.fsl_match_id
        JOIN Players p1 ON fm.winner_player_id = p1.Player_ID
        JOIN Players p2 ON fm.loser_player_id  = p2.Player_ID
        WHERE vs.status = 'open' AND vs.expires_at > NOW()
        ORDER BY vs.id DESC
        LIMIT 1
    ");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function session_to_payload(array $s): array {
    return [
        'session_id'   => (int) $s['id'],
        'fsl_match_id' => (int) $s['fsl_match_id'],
        'expires_at'   => gmdate('c', strtotime($s['expires_at'])),
        'season'       => (int) $s['season'],
        't_code'       => $s['t_code'],
        'player1'      => ['id' => (int) $s['winner_player_id'], 'real_name' => $s['player1_name']],
        'player2'      => ['id' => (int) $s['loser_player_id'],  'real_name' => $s['player2_name']],
    ];
}

// ─── Match helper ─────────────────────────────────────────────────────────────

function get_match(PDO $db, int $match_id): ?array {
    $stmt = $db->prepare("
        SELECT fm.fsl_match_id, fm.season, fm.t_code, fm.notes, fm.vod,
               fm.winner_player_id, fm.loser_player_id,
               p1.Real_Name AS player1_name,
               p2.Real_Name AS player2_name
        FROM fsl_matches fm
        JOIN Players p1 ON fm.winner_player_id = p1.Player_ID
        JOIN Players p2 ON fm.loser_player_id  = p2.Player_ID
        WHERE fm.fsl_match_id = ?
    ");
    $stmt->execute([$match_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function match_to_payload(array $m): array {
    return [
        'fsl_match_id' => (int) $m['fsl_match_id'],
        'season'       => (int) $m['season'],
        't_code'       => $m['t_code'],
        'division'     => $m['t_code'],
        'notes'        => $m['notes'],
        'vod'          => $m['vod'],
        'player1'      => ['id' => (int) $m['winner_player_id'], 'real_name' => $m['player1_name']],
        'player2'      => ['id' => (int) $m['loser_player_id'],  'real_name' => $m['player2_name']],
    ];
}

// ─── Constants ───────────────────────────────────────────────────────────────

const VALID_ATTRIBUTES = ['micro', 'macro', 'clutch', 'creativity', 'aggression', 'strategy'];
const SESSION_TTL_MINS = 5;

// ─── Route ───────────────────────────────────────────────────────────────────

auth_check($config);

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ─── GET match ───────────────────────────────────────────────────────────────
if ($action === 'match' && $method === 'GET') {
    $match_id = isset($_GET['fsl_match_id']) ? (int) $_GET['fsl_match_id'] : 0;
    if ($match_id <= 0) {
        respond(400, false, null, 'MISSING_PARAM', 'fsl_match_id is required');
    }

    $match = get_match($db, $match_id);
    if (!$match) {
        respond(404, false, null, 'MATCH_NOT_FOUND', "No match found with fsl_match_id=$match_id");
    }

    respond(200, true, match_to_payload($match));
}

// ─── POST enable ─────────────────────────────────────────────────────────────
if ($action === 'enable' && $method === 'POST') {
    $body     = json_body();
    $match_id = isset($body['fsl_match_id']) ? (int) $body['fsl_match_id'] : 0;

    if ($match_id <= 0) {
        respond(400, false, null, 'MISSING_PARAM', 'fsl_match_id is required');
    }

    $match = get_match($db, $match_id);
    if (!$match) {
        respond(404, false, null, 'MATCH_NOT_FOUND', "No match found with fsl_match_id=$match_id");
    }

    // Check for any currently active session
    $active = get_active_session($db);
    if ($active) {
        if ((int) $active['fsl_match_id'] === $match_id) {
            // Idempotent: same match already open — return existing session
            respond(200, true, session_to_payload($active));
        }
        // Different match is open
        respond(409, false, null, 'VOTING_ALREADY_OPEN',
            "A voting session is already open for match #{$active['fsl_match_id']} " .
            "({$active['player1_name']} vs {$active['player2_name']}). " .
            "It expires at " . gmdate('c', strtotime($active['expires_at'])) . ".");
    }

    // Create new session
    $enabled_by = $body['requested_by'] ?? null;
    $channel    = $body['channel']      ?? null;
    $stmt = $db->prepare("
        INSERT INTO voting_sessions (fsl_match_id, enabled_by, channel, expires_at, status)
        VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL " . SESSION_TTL_MINS . " MINUTE), 'open')
    ");
    $stmt->execute([$match_id, $enabled_by, $channel]);
    $session_id = (int) $db->lastInsertId();

    // Fetch full session row
    $stmt = $db->prepare("SELECT * FROM voting_sessions WHERE id = ?");
    $stmt->execute([$session_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    $payload = session_to_payload(array_merge($session, [
        'winner_player_id' => $match['winner_player_id'],
        'loser_player_id'  => $match['loser_player_id'],
        'player1_name'     => $match['player1_name'],
        'player2_name'     => $match['player2_name'],
        'season'           => $match['season'],
        't_code'           => $match['t_code'],
    ]));

    respond(200, true, $payload);
}

// ─── GET active ──────────────────────────────────────────────────────────────
if ($action === 'active' && $method === 'GET') {
    $active = get_active_session($db);

    if (!$active) {
        respond(200, true, ['active' => false]);
    }

    respond(200, true, array_merge(['active' => true], session_to_payload($active)));
}

// ─── POST votes ──────────────────────────────────────────────────────────────
if ($action === 'votes' && $method === 'POST') {
    $body        = json_body();
    $session_id  = isset($body['session_id'])  ? (int) $body['session_id']  : 0;
    $match_id    = isset($body['fsl_match_id']) ? (int) $body['fsl_match_id'] : 0;
    $reviewer_id = isset($body['reviewer_id']) ? (int) $body['reviewer_id'] : 0;
    $votes       = $body['votes'] ?? null;

    if ($session_id <= 0 || $match_id <= 0 || $reviewer_id <= 0 || !is_array($votes)) {
        respond(400, false, null, 'MISSING_PARAM',
            'session_id, fsl_match_id, reviewer_id, and votes are required');
    }

    // Verify session is still open and matches
    $stmt = $db->prepare("
        SELECT * FROM voting_sessions
        WHERE id = ? AND status = 'open' AND expires_at > NOW()
    ");
    $stmt->execute([$session_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        respond(403, false, null, 'VOTING_CLOSED',
            "Session #$session_id is not active or has expired");
    }

    if ((int) $session['fsl_match_id'] !== $match_id) {
        respond(403, false, null, 'MATCH_MISMATCH',
            "fsl_match_id=$match_id does not match session (match #{$session['fsl_match_id']})");
    }

    // Verify reviewer exists and is active
    $stmt = $db->prepare("SELECT id FROM reviewers WHERE id = ? AND status = 'active'");
    $stmt->execute([$reviewer_id]);
    if (!$stmt->fetch()) {
        respond(403, false, null, 'INVALID_REVIEWER',
            "reviewer_id=$reviewer_id not found or inactive");
    }

    // Fetch match to get player IDs
    $match = get_match($db, $match_id);
    if (!$match) {
        respond(404, false, null, 'MATCH_NOT_FOUND', "Match #$match_id not found");
    }

    // Validate all 6 attributes are present and values are correct
    foreach (VALID_ATTRIBUTES as $attr) {
        if (!isset($votes[$attr])) {
            respond(400, false, null, 'MISSING_PARAM', "Missing attribute: $attr");
        }
        $v = $votes[$attr];

        if (!isset($v['vote']) || !in_array((int) $v['vote'], [0, 1, 2], true)) {
            respond(400, false, null, 'INVALID_VOTE_VALUE',
                "vote for '$attr' must be 0, 1, or 2");
        }

        if (isset($v['tally'])) {
            $t = $v['tally'];
            foreach (['player1', 'player2', 'tie'] as $k) {
                if (!isset($t[$k]) || !is_int($t[$k]) || $t[$k] < 0) {
                    respond(400, false, null, 'INVALID_TALLY',
                        "tally.$k for '$attr' must be a non-negative integer");
                }
            }
        }
    }

    // Upsert votes — one row per attribute
    $upsert = $db->prepare("
        INSERT INTO Player_Attribute_Votes
            (fsl_match_id, reviewer_id, attribute, vote,
             tally_player1, tally_player2, tally_tie,
             player1_id, player2_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            vote          = VALUES(vote),
            tally_player1 = VALUES(tally_player1),
            tally_player2 = VALUES(tally_player2),
            tally_tie     = VALUES(tally_tie)
    ");

    $rows = 0;
    foreach (VALID_ATTRIBUTES as $attr) {
        $v       = $votes[$attr];
        $vote    = (int) $v['vote'];
        $tally   = $v['tally'] ?? null;
        $tp1     = $tally ? (int) $tally['player1'] : null;
        $tp2     = $tally ? (int) $tally['player2'] : null;
        $ttie    = $tally ? (int) $tally['tie']     : null;

        $upsert->execute([
            $match_id,
            $reviewer_id,
            $attr,
            $vote,
            $tp1,
            $tp2,
            $ttie,
            $match['winner_player_id'],
            $match['loser_player_id'],
        ]);
        $rows++;
    }

    respond(200, true, ['updated' => true, 'rows_affected' => $rows]);
}

// ─── Fallback ────────────────────────────────────────────────────────────────
respond(400, false, null, 'INVALID_ACTION',
    "Unknown action '$action'. Valid: match, enable, active, votes");
