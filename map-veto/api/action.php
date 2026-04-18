<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/map_veto.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = (string) file_get_contents('php://input');
$body = json_decode($raw ?: 'null', true);
if (!is_array($body)) {
    $body = [];
}

$token = $_GET['t'] ?? ($body['t'] ?? '');
$mapId = (string) ($body['map_id'] ?? '');

if ($token === '' || $mapId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing token or map_id']);
    exit;
}

$hit = map_veto_find_by_token($token);
if ($hit === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Invalid token']);
    exit;
}

if (($hit['role'] ?? '') === 'public') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Public token cannot act']);
    exit;
}

$role = (string) $hit['role'];
$sessionId = (string) ($hit['session']['id'] ?? '');
$side = $role === 'player_b' ? 'b' : 'a';

$live = map_veto_refresh_session($sessionId);
if ($live === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Session not found']);
    exit;
}

$status = (string) ($live['status'] ?? '');
if ($status === 'live_veto') {
    $result = map_veto_submit_veto($sessionId, $side, $mapId);
} elseif ($status === 'live_order') {
    $result = map_veto_submit_pick($sessionId, $side, $mapId);
} else {
    echo json_encode([
        'ok' => false,
        'error' => 'Session is not accepting picks right now.',
        'state' => map_veto_build_payload($live, $role),
    ]);
    exit;
}

if (is_array($result) && array_key_exists('success', $result) && $result['success'] === false) {
    echo json_encode(['ok' => false, 'error' => (string) ($result['message'] ?? 'Failed')]);
    exit;
}

if (!is_array($result) || !isset($result['id'])) {
    echo json_encode(['ok' => false, 'error' => 'Could not apply action']);
    exit;
}

$fresh = map_veto_refresh_session($sessionId) ?? $result;
echo json_encode([
    'ok' => true,
    'state' => map_veto_build_payload($fresh, $role),
], JSON_UNESCAPED_SLASHES);
